<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({ companies: Array, salesPlan: Number, salesPlanWon: Number });

// План сделок на месяц (для экрана «Офис») — ставит админ или финансист.
const planVal = ref(props.salesPlan ?? 20);
const planWonVal = ref(props.salesPlanWon ?? 20);
const savePlan = () => router.post(route('workshopScreens.plan'), { plan: planVal.value, plan_won: planWonVal.value }, { preserveScroll: true });

const screenUrl = `${window.location.origin}/screen`;
const genCode = async (company, r, kind = 'workshop') => {
    if (r.screen && !(await confirmDialog({ title: tr('Выдать новый код?'), message: `Экран «${r.label}» со старым кодом сразу отключится — на мониторе нужно будет ввести новый код.`, confirmText: tr('Новый код') }))) return;
    router.post(route('workshopScreens.upsert'), { company_id: company.id, workshop: r.workshop ?? null, kind }, { preserveScroll: true });
};
const toggle = (r) => router.post(route('workshopScreens.toggle', r.screen.id), {}, { preserveScroll: true });
const copy = (code) => navigator.clipboard?.writeText(code);
</script>

<template>
    <Head :title="$e('Настройки · Экраны')" />
    <SettingsLayout :title="$e('Экраны цехов')" wide>

        <!-- Инструкция -->
        <div class="mb-5 flex flex-wrap items-center gap-3 rounded-2xl border border-indigo-100 dark:border-indigo-500/30 bg-indigo-50/60 dark:bg-indigo-500/10 px-5 py-4">
            <span class="text-2xl">📺</span>
            <div class="text-sm text-slate-700 dark:text-slate-300">
                {{ $e('На мониторе цеха откройте') }} <button @click="copy(screenUrl)" class="rounded-lg bg-white dark:bg-slate-900/70 px-2 py-0.5 font-semibold text-indigo-700 dark:text-indigo-300 shadow-sm hover:bg-indigo-50" :title="$e('Скопировать')">{{ screenUrl }}</button>
                {{ $e('и введите код цеха. Экран показывает канбан') }} <b>{{ $e('только своего цеха') }}</b> {{ $e('— без сумм, с автообновлением каждые 30 секунд. Экран «Офис» — отдел продаж против плана месяца и лидер.') }}
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('План заявок/мес:') }}</span>
                <input v-model.number="planVal" @change="savePlan" type="number" min="1" max="1000"
                    class="w-20 rounded-lg border-slate-300 py-1.5 text-center text-sm font-semibold shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                <span class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('План подтверждённых/мес:') }}</span>
                <input v-model.number="planWonVal" @change="savePlan" type="number" min="1" max="1000"
                    class="w-20 rounded-lg border-slate-300 py-1.5 text-center text-sm font-semibold shadow-sm focus:border-emerald-500 focus:ring-emerald-500" />
            </div>
        </div>

        <!-- Компании и их цеха -->
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <div v-for="c in companies" :key="c.id" class="rounded-2xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-sm">
                <div class="border-b border-slate-100 dark:border-slate-800 px-5 py-3.5 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ c.name }}</div>
                <div class="divide-y divide-slate-50 dark:divide-slate-800">
                    <div v-for="r in c.rows" :key="r.label" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ r.label }}</span>
                            <span v-if="r.screen && !r.screen.is_active" class="rounded-full bg-rose-100 dark:bg-rose-500/20 px-2 py-0.5 text-xs font-semibold text-rose-600 dark:text-rose-400">{{ $e('отключён') }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button v-if="r.screen" @click="copy(r.screen.code)" :title="$e('Скопировать код')"
                                class="rounded-lg bg-slate-900 px-3 py-1.5 font-mono text-base font-bold tracking-[0.3em] text-emerald-400 transition hover:opacity-80"
                                :class="!r.screen.is_active ? 'opacity-40' : ''">{{ r.screen.code }}</button>
                            <span v-else class="text-xs text-slate-400">{{ $e('кода нет') }}</span>
                            <button @click="genCode(c, r)"
                                class="rounded-lg border border-slate-200 dark:border-slate-800/80 px-3 py-1.5 text-xs font-medium text-slate-600 dark:text-slate-300 transition hover:bg-slate-50 dark:hover:bg-slate-800/60">{{ r.screen ? $e('Новый код') : $e('Выдать код') }}</button>
                            <button v-if="r.screen" @click="toggle(r)"
                                class="rounded-lg px-2.5 py-1.5 text-xs font-medium transition"
                                :class="r.screen.is_active ? 'text-slate-400 hover:bg-rose-50 hover:text-rose-600' : 'text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50'">{{ r.screen.is_active ? $e('Отключить') : $e('Включить') }}</button>
                        </div>
                    </div>
                    <div v-if="!c.rows.length" class="px-5 py-6 text-center text-sm text-slate-400">{{ $e('У компании нет этапов цеха') }}</div>
                    <!-- Экран офиса: сделки по этапам + лидеры менеджеров -->
                    <div class="flex flex-wrap items-center justify-between gap-3 bg-indigo-50/40 dark:bg-indigo-500/10 px-5 py-3.5">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $e('Офис') }} <span class="text-xs font-normal text-slate-400">{{ $e('— сделки и лидеры менеджеров') }}</span></span>
                            <span v-if="c.office && !c.office.is_active" class="rounded-full bg-rose-100 dark:bg-rose-500/20 px-2 py-0.5 text-xs font-semibold text-rose-600 dark:text-rose-400">{{ $e('отключён') }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button v-if="c.office" @click="copy(c.office.code)" :title="$e('Скопировать код')"
                                class="rounded-lg bg-slate-900 px-3 py-1.5 font-mono text-base font-bold tracking-[0.3em] text-emerald-400 transition hover:opacity-80"
                                :class="!c.office.is_active ? 'opacity-40' : ''">{{ c.office.code }}</button>
                            <span v-else class="text-xs text-slate-400">{{ $e('кода нет') }}</span>
                            <button @click="genCode(c, { workshop: null, label: 'Офис', screen: c.office }, 'office')"
                                class="rounded-lg border border-slate-200 dark:border-slate-800/80 px-3 py-1.5 text-xs font-medium text-slate-600 dark:text-slate-300 transition hover:bg-slate-50 dark:hover:bg-slate-800/60">{{ c.office ? $e('Новый код') : $e('Выдать код') }}</button>
                            <button v-if="c.office" @click="toggle({ screen: c.office })"
                                class="rounded-lg px-2.5 py-1.5 text-xs font-medium transition"
                                :class="c.office.is_active ? 'text-slate-400 hover:bg-rose-50 hover:text-rose-600' : 'text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50'">{{ c.office.is_active ? $e('Отключить') : $e('Включить') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </SettingsLayout>
</template>

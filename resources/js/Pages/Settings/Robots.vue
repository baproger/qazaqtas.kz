<script setup>
/**
 * Автоматизация: роботы этапов «Когда → Если → Что» и журнал запусков.
 * Форма действия строится из schema() обработчика — новый тип действия
 * появляется здесь без правки страницы.
 */
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import MultiSelect from '@/Components/MultiSelect.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Toggle from '@/Components/settings/Toggle.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useE } from '@/composables/useTranslations';

const tr = useE();
const props = defineProps({
    robots: Array, runs: Array, stages: Array, selectedStageId: Number, actions: Array, roles: Array, users: Array,
    fields: Object, ops: Array, triggers: Object, sequences: Object, companyId: Number,
});

const stageFilter = ref(props.selectedStageId ?? null);
const tab = ref('robots');
const byStage = computed(() => {
    const groups = [];
    const list = props.robots.filter((r) => !stageFilter.value || r.stage_id === stageFilter.value || r.stage_id === null);
    for (const s of props.stages) {
        const rs = list.filter((r) => r.stage_id === s.id);
        if (rs.length || stageFilter.value === s.id) groups.push({ stage: s, robots: rs });
    }
    const any = list.filter((r) => r.stage_id === null);
    if (any.length) groups.push({ stage: { id: null, name: tr('Любой этап'), color: '#94A3B8' }, robots: any });
    return groups;
});
const stageName = (id) => props.stages.find((s) => s.id === id)?.name ?? tr('Любой этап');
const actionLabel = (t) => props.actions.find((a) => a.type === t)?.label ?? t;
const schemaOf = (t) => props.actions.find((a) => a.type === t)?.schema ?? [];

const delayLabel = (sec) => {
    if (!sec) return tr('сразу');
    if (sec % 86400 === 0) return `${tr('через')} ${sec / 86400} ${tr('дн.')}`;
    if (sec % 3600 === 0) return `${tr('через')} ${sec / 3600} ${tr('ч')}`;
    return `${tr('через')} ${Math.round(sec / 60)} ${tr('мин')}`;
};
const conditionText = (r) => (r.conditions?.all ?? []).map((c) => `${props.fields[c.field] ?? c.field} ${c.op} ${c.value ?? ''}`).join(' · ');

// ---- форма ----
const editing = ref(null); // null | 'new' | robot
const form = useForm({ name: '', stage_id: null, trigger: 'enter', sequence: 'parallel', delay_value: 0, delay_unit: 60, run_if_left: false, is_active: true, conditions: { all: [] }, action_type: 'send_notification', action_payload: {} });
const open = (r = null, stageId = null) => {
    form.clearErrors();
    if (r) {
        const sec = r.delay_seconds ?? 0;
        const unit = sec && sec % 86400 === 0 ? 86400 : sec && sec % 3600 === 0 ? 3600 : 60;
        Object.assign(form, { name: r.name, stage_id: r.stage_id, trigger: r.trigger, sequence: r.sequence, delay_value: sec ? sec / unit : 0, delay_unit: unit,
            run_if_left: !!r.run_if_left, is_active: !!r.is_active, conditions: { all: [...(r.conditions?.all ?? [])].map((c) => ({ ...c })) }, action_type: r.action_type, action_payload: { ...(r.action_payload ?? {}) } });
        editing.value = r;
    } else {
        Object.assign(form, { name: '', stage_id: stageId ?? stageFilter.value ?? props.stages[0]?.id ?? null, trigger: 'enter', sequence: 'parallel', delay_value: 0, delay_unit: 60, run_if_left: false, is_active: true, conditions: { all: [] }, action_type: 'send_notification', action_payload: {} });
        editing.value = 'new';
    }
};
const addCondition = () => form.conditions.all.push({ field: 'budget', op: '>', value: '' });
const save = () => {
    const opts = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    const t = form.transform((d) => ({ ...d, delay_seconds: Math.max(0, Math.round(Number(d.delay_value) || 0) * d.delay_unit) }));
    editing.value === 'new' ? t.post(route('robots.store'), opts) : t.put(route('robots.update', editing.value.id), opts);
};
const toggle = (r) => router.post(route('robots.toggle', r.id), {}, { preserveScroll: true });
const duplicate = (r) => router.post(route('robots.duplicate', r.id), {}, { preserveScroll: true });
const remove = async (r) => {
    if (await confirmDialog({ title: tr('Удалить робота'), message: `«${r.name}»`, confirmText: tr('Удалить'), danger: true })) {
        router.delete(route('robots.destroy', r.id), { preserveScroll: true });
    }
};
const statusStyle = { queued: 'bg-sky-50 dark:bg-sky-500/10 text-sky-700 dark:text-sky-400', waiting: 'bg-slate-100 dark:bg-slate-800/60 text-slate-600 dark:text-slate-300', running: 'bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400', done: 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400', skipped: 'bg-slate-100 dark:bg-slate-800/60 text-slate-500 dark:text-slate-400', failed: 'bg-rose-50 dark:bg-rose-500/10 text-rose-700 dark:text-rose-400' };
const statusLabel = { queued: tr('в очереди'), waiting: tr('ждёт цепочку'), running: tr('выполняется'), done: tr('выполнено'), skipped: tr('пропущено'), failed: tr('ошибка') };
const fmt = (t) => t ? new Date(t).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—';
const fieldClass = 'w-full rounded-xl border-white/60 bg-white/70 text-sm shadow-soft backdrop-blur focus:border-indigo-400 focus:ring-indigo-400';
</script>

<template>
    <Head :title="$e('Автоматизация')" />
    <SettingsLayout :title="$e('Автоматизация')" wide>
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <div class="flex rounded-lg border border-slate-200 dark:border-slate-800/80 bg-white dark:bg-slate-900/70 p-0.5 text-sm">
                <button type="button" @click="tab = 'robots'" class="rounded-md px-3 py-1.5 font-medium" :class="tab === 'robots' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'text-slate-600 dark:text-slate-300'">{{ $e('Роботы') }} <span class="opacity-60">{{ robots.length }}</span></button>
                <button type="button" @click="tab = 'runs'" class="rounded-md px-3 py-1.5 font-medium" :class="tab === 'runs' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'text-slate-600 dark:text-slate-300'">{{ $e('Журнал') }} <span class="opacity-60">{{ runs.length }}</span></button>
            </div>
            <select v-model="stageFilter" class="rounded-lg border-slate-300 text-sm shadow-sm">
                <option :value="null">{{ $e('Все этапы') }}</option>
                <option v-for="s in stages" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <PrimaryButton class="ml-auto" @click="open()">+ {{ $e('Робот') }}</PrimaryButton>
        </div>

        <!-- ===== Роботы по этапам ===== -->
        <div v-if="tab === 'robots'" class="space-y-4">
            <div v-if="!byStage.length" class="rounded-2xl border border-dashed border-slate-200 dark:border-slate-800/80 bg-white dark:bg-slate-900/70 p-12 text-center">
                <div class="text-3xl">🤖</div>
                <div class="mt-2 text-sm font-medium text-slate-700 dark:text-slate-300">{{ $e('Роботов пока нет') }}</div>
                <div class="mt-1 text-xs text-slate-400">{{ $e('Робот срабатывает, когда сделка приходит на этап: уведомляет, ставит задачу, меняет ответственного, переводит дальше или дёргает вебхук.') }}</div>
            </div>
            <section v-for="g in byStage" :key="g.stage.id ?? 'any'" class="rounded-2xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 px-5 py-3">
                    <div class="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-200"><span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: g.stage.color }" />{{ g.stage.name }}</div>
                    <button type="button" @click="open(null, g.stage.id)" class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">+ {{ $e('добавить') }}</button>
                </div>
                <div class="divide-y divide-slate-50 dark:divide-slate-800">
                    <div v-for="r in g.robots" :key="r.id" class="flex flex-wrap items-center gap-3 px-5 py-3" :class="r.is_active ? '' : 'opacity-50'">
                        <Toggle :model-value="!!r.is_active" @update:model-value="toggle(r)" />
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ r.name }}</div>
                            <div class="mt-0.5 flex flex-wrap gap-x-2 text-xs text-slate-500 dark:text-slate-400">
                                <span class="rounded-full bg-slate-100 dark:bg-slate-800/60 px-2 py-0.5">{{ triggers[r.trigger] }}</span>
                                <span class="rounded-full bg-slate-100 dark:bg-slate-800/60 px-2 py-0.5">{{ delayLabel(r.delay_seconds) }}</span>
                                <span v-if="r.sequence === 'sequential'" class="rounded-full bg-slate-100 dark:bg-slate-800/60 px-2 py-0.5">{{ $e('по цепочке') }}</span>
                                <span v-if="conditionText(r)" class="rounded-full bg-amber-50 dark:bg-amber-500/10 px-2 py-0.5 text-amber-700 dark:text-amber-400">{{ $e('если') }} {{ conditionText(r) }}</span>
                                <span class="rounded-full bg-indigo-50 dark:bg-indigo-500/10 px-2 py-0.5 font-medium text-indigo-700 dark:text-indigo-300">{{ actionLabel(r.action_type) }}</span>
                                <span v-if="r.failed_runs_count" class="rounded-full bg-rose-50 dark:bg-rose-500/10 px-2 py-0.5 text-rose-700 dark:text-rose-400">{{ $e('ошибок:') }} {{ r.failed_runs_count }}</span>
                            </div>
                        </div>
                        <div class="flex gap-1 text-xs">
                            <button class="rounded-lg px-2 py-1 font-medium text-slate-600 dark:text-slate-300 hover:bg-indigo-50 hover:text-indigo-700" @click="open(r)">{{ $e('Изменить') }}</button>
                            <button class="rounded-lg px-2 py-1 font-medium text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800/60" @click="duplicate(r)">{{ $e('Копия') }}</button>
                            <button class="rounded-lg px-2 py-1 font-medium text-slate-400 hover:bg-rose-50 hover:text-rose-600" @click="remove(r)">{{ $e('Удалить') }}</button>
                        </div>
                    </div>
                    <div v-if="!g.robots.length" class="px-5 py-3 text-xs text-slate-300 dark:text-slate-600">{{ $e('Роботов нет') }}</div>
                </div>
            </section>
        </div>

        <!-- ===== Журнал ===== -->
        <div v-else class="overflow-x-auto rounded-2xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-sm">
            <table class="min-w-full divide-y divide-slate-100 dark:divide-slate-800 text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/50 text-left text-xs uppercase text-slate-500 dark:text-slate-400"><tr>
                    <th class="px-4 py-3">{{ $e('Когда') }}</th><th class="px-4 py-3">{{ $e('Робот') }}</th><th class="px-4 py-3">{{ $e('Сделка') }}</th>
                    <th class="px-4 py-3">{{ $e('Статус') }}</th><th class="px-4 py-3">{{ $e('Результат') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-50 dark:divide-slate-800">
                    <tr v-for="r in runs" :key="r.id">
                        <td class="whitespace-nowrap px-4 py-2 text-slate-400">{{ fmt(r.created_at) }}<span v-if="r.scheduled_at" class="block text-xs">→ {{ fmt(r.scheduled_at) }}</span></td>
                        <td class="px-4 py-2"><div class="font-medium text-slate-800 dark:text-slate-200">{{ r.robot }}</div><div class="text-xs text-slate-400">{{ actionLabel(r.action) }}</div></td>
                        <td class="px-4 py-2"><a v-if="r.deal" :href="route('deals.show', r.deal.id)" class="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">{{ r.deal.number }}</a><div class="text-xs text-slate-400">{{ r.deal?.company }}</div></td>
                        <td class="px-4 py-2"><span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusStyle[r.status]">{{ statusLabel[r.status] }}</span></td>
                        <td class="max-w-md px-4 py-2 text-xs text-slate-500 dark:text-slate-400"><span v-if="r.error" class="text-rose-600 dark:text-rose-400">{{ r.error }}</span><span v-else-if="r.output">{{ JSON.stringify(r.output) }}</span><span v-else>—</span></td>
                    </tr>
                    <tr v-if="!runs.length"><td colspan="5" class="px-4 py-8 text-center text-xs text-slate-300 dark:text-slate-600">{{ $e('Запусков ещё не было') }}</td></tr>
                </tbody>
            </table>
        </div>

        <!-- ===== Форма робота ===== -->
        <div v-if="editing" class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-slate-900/30 p-4 backdrop-blur-sm sm:p-8" @click.self="editing = null">
            <div class="w-full max-w-3xl rounded-3xl border border-white/60 dark:border-slate-800/80 bg-gradient-to-br from-white/95 via-indigo-50/70 to-violet-50/60 dark:from-slate-900/85 dark:via-slate-900/70 dark:to-slate-900/60 p-6 shadow-soft-lg backdrop-blur-xl">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ editing === 'new' ? $e('Новый робот') : $e('Робот') }}</h2>
                    <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300"><Toggle v-model="form.is_active" /> {{ $e('Включён') }}</label>
                </div>

                <div class="mt-4">
                    <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Название') }}</label>
                    <input v-model="form.name" type="text" :class="fieldClass" :placeholder="$e('Напомнить директору о крупной сделке')" />
                    <div v-if="form.errors.name" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ form.errors.name }}</div>
                </div>

                <!-- КОГДА -->
                <div class="mt-4 rounded-2xl bg-white/60 dark:bg-slate-900/70 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $e('Когда') }}</div>
                    <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs text-slate-500 dark:text-slate-400">{{ $e('Этап') }}</label>
                            <select v-model="form.stage_id" :class="fieldClass"><option :value="null">{{ $e('Любой этап') }}</option><option v-for="s in stages" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-slate-500 dark:text-slate-400">{{ $e('Событие') }}</label>
                            <select v-model="form.trigger" :class="fieldClass"><option v-for="(l, k) in triggers" :key="k" :value="k">{{ l }}</option></select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-slate-500 dark:text-slate-400">{{ $e('Задержка') }}</label>
                            <div class="flex gap-2">
                                <input v-model="form.delay_value" type="number" min="0" :class="fieldClass" />
                                <select v-model="form.delay_unit" :class="fieldClass"><option :value="60">{{ $e('минут') }}</option><option :value="3600">{{ $e('часов') }}</option><option :value="86400">{{ $e('дней') }}</option></select>
                            </div>
                            <div class="mt-1 text-xs text-slate-400">0 — {{ $e('сразу') }}</div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-slate-500 dark:text-slate-400">{{ $e('Очерёдность') }}</label>
                            <select v-model="form.sequence" :class="fieldClass"><option v-for="(l, k) in sequences" :key="k" :value="k">{{ l }}</option></select>
                            <label class="mt-2 flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300"><input type="checkbox" v-model="form.run_if_left" class="rounded border-slate-300 text-indigo-600" /> {{ $e('Выполнить, даже если сделка уже ушла с этапа') }}</label>
                        </div>
                    </div>
                </div>

                <!-- ЕСЛИ -->
                <div class="mt-3 rounded-2xl bg-white/60 dark:bg-slate-900/70 p-4">
                    <div class="flex items-center justify-between">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $e('Если') }} <span class="normal-case text-slate-400">— {{ $e('все условия; пусто = всегда') }}</span></div>
                        <button type="button" @click="addCondition" class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">+ {{ $e('условие') }}</button>
                    </div>
                    <div v-for="(c, i) in form.conditions.all" :key="i" class="mt-2 grid grid-cols-[1fr_auto_1fr_auto] items-center gap-2">
                        <select v-model="c.field" :class="fieldClass"><option v-for="(l, k) in fields" :key="k" :value="k">{{ l }}</option></select>
                        <select v-model="c.op" :class="fieldClass"><option v-for="o in ops" :key="o" :value="o">{{ o }}</option></select>
                        <input v-model="c.value" type="text" :class="fieldClass" :disabled="['empty', 'not_empty'].includes(c.op)" />
                        <button type="button" @click="form.conditions.all.splice(i, 1)" class="text-slate-400 hover:text-rose-600">×</button>
                    </div>
                </div>

                <!-- ЧТО -->
                <div class="mt-3 rounded-2xl bg-white/60 dark:bg-slate-900/70 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $e('Что сделать') }}</div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button v-for="a in actions" :key="a.type" type="button" @click="form.action_type = a.type; form.action_payload = {}"
                            class="rounded-full border px-3 py-1.5 text-sm font-medium transition"
                            :class="form.action_type === a.type ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-white/70 dark:border-slate-800/80 bg-white/70 dark:bg-slate-900/70 text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-800'">{{ a.label }}</button>
                    </div>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div v-for="f in schemaOf(form.action_type)" :key="f.key" :class="['textarea'].includes(f.type) ? 'sm:col-span-2' : ''">
                            <label class="mb-1 block text-xs text-slate-500 dark:text-slate-400">{{ f.label }}<span v-if="f.required" class="text-rose-500"> *</span></label>
                            <MultiSelect v-if="f.type === 'roles'" :model-value="form.action_payload[f.key] ?? []" @update:model-value="(v) => (form.action_payload[f.key] = v)" :options="roles" :placeholder="$e('Поиск роли')" :empty-label="$e('не выбрано')" />
                            <select v-else-if="f.type === 'select'" v-model="form.action_payload[f.key]" :class="fieldClass"><option v-for="(l, k) in f.options" :key="k" :value="k">{{ l }}</option></select>
                            <select v-else-if="f.type === 'stage'" v-model="form.action_payload[f.key]" :class="fieldClass"><option v-for="s in stages" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                            <select v-else-if="f.type === 'user'" v-model="form.action_payload[f.key]" :class="fieldClass"><option :value="null">—</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
                            <label v-else-if="f.type === 'bool'" class="flex h-10 items-center gap-2 text-sm text-slate-700 dark:text-slate-300"><Toggle :model-value="!!form.action_payload[f.key]" @update:model-value="(v) => (form.action_payload[f.key] = v)" /> {{ $e('да') }}</label>
                            <textarea v-else-if="f.type === 'textarea'" v-model="form.action_payload[f.key]" rows="3" :class="fieldClass" />
                            <input v-else v-model="form.action_payload[f.key]" :type="f.type === 'number' ? 'number' : 'text'" :class="fieldClass" />
                            <div v-if="f.hint" class="mt-1 text-xs text-slate-400">{{ f.hint }}</div>
                        </div>
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton @click="editing = null">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing || !form.name" @click="save">{{ $e('Сохранить') }}</PrimaryButton>
                </div>
            </div>
        </div>
    </SettingsLayout>
</template>

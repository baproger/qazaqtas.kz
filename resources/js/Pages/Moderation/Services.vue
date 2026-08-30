<script setup>
/** Модерация услуг партнёров: одобрить или отклонить с причиной. */
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();
const props = defineProps({ services: Object, counts: Object, filters: Object, statuses: Object, categories: Array });
// Категории услуг: добавить, переименовать, скрыть/показать, удалить.
const catsOpen = ref(false);
const newCat = ref('');
const addCat = () => { if (newCat.value.trim().length < 2) return; router.post(route('moderation.serviceCategories.store'), { name: newCat.value }, { preserveScroll: true, onSuccess: () => (newCat.value = '') }); };
const renameCat = (c, name) => { if (name.trim().length >= 2 && name !== c.name) router.put(route('moderation.serviceCategories.update', c.id), { name }, { preserveScroll: true }); };
const toggleCat = (c) => router.put(route('moderation.serviceCategories.update', c.id), { is_active: !c.is_active }, { preserveScroll: true });
const delCat = (c) => router.delete(route('moderation.serviceCategories.destroy', c.id), { preserveScroll: true });

const status = ref(props.filters.status);
const setStatus = (s) => { status.value = s; router.get(route('moderation.services'), { status: s }, { preserveState: true, replace: true }); };
const approve = (s) => router.patch(route('moderation.services.approve', s.id), {}, { preserveScroll: true });
const rejecting = ref(null);
const reason = ref('');
const reject = () => router.patch(route('moderation.services.reject', rejecting.value.id), { reason: reason.value }, { preserveScroll: true, onSuccess: () => { rejecting.value = null; reason.value = ''; } });
const fmt = (t) => t ? new Date(t).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';
</script>

<template>
    <Head :title="$e('Модерация услуг')" />
    <AppLayout>
        <template #header>{{ $e('Модерация услуг') }}</template>

        <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="flex rounded-2xl bg-white/70 p-1 text-sm shadow-soft backdrop-blur w-fit">
            <button v-for="(l, k) in statuses" :key="k" type="button" @click="setStatus(k)"
                class="flex items-center gap-1.5 rounded-xl px-4 py-1.5 font-medium transition" :class="status === k ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-white'">
                {{ l }} <span class="rounded-full px-1.5 text-xs" :class="status === k ? 'bg-white/20' : 'bg-slate-100'">{{ counts[k] ?? 0 }}</span>
            </button>
        </div>
        <button type="button" @click="catsOpen = true" class="rounded-xl border border-indigo-300/60 bg-indigo-500/15 px-4 py-2 text-sm font-semibold text-indigo-700 backdrop-blur transition hover:bg-indigo-500/25">🗂 {{ $e('Категории') }} <span class="opacity-60">{{ categories.length }}</span></button>
        </div>

        <div class="grid gap-3">
            <div v-for="s in services.data" :key="s.id" class="flex flex-wrap items-start gap-4 rounded-2xl border border-white/60 bg-white/75 p-4 shadow-soft backdrop-blur-md">
                <img v-if="s.thumb" :src="s.thumb" class="h-24 w-36 rounded-xl object-cover" :alt="s.title" />
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold text-slate-900">{{ s.title }}</span>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">{{ s.category?.name }}</span>
                        <span v-if="s.price" class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700">{{ new Intl.NumberFormat('ru-RU').format(s.price) }} ₸</span>
                        <span class="text-xs text-slate-400">{{ fmt(s.created_at) }}</span>
                    </div>
                    <p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ s.description_full }}</p>
                    <div class="mt-1.5 text-xs text-slate-500">👤 {{ s.partner?.name }} · {{ s.contact_name }} · {{ s.contact_phone }}<template v-if="s.city"> · {{ s.city }}</template></div>
                    <div v-if="s.rejection_reason" class="mt-1 rounded-lg bg-rose-50 px-2 py-1 text-xs text-rose-700">{{ $e('Причина:') }} {{ s.rejection_reason }}</div>
                </div>
                <div v-if="s.status === 'pending'" class="flex shrink-0 flex-col gap-2">
                    <button @click="approve(s)" class="rounded-xl border border-emerald-300/60 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-emerald-700 backdrop-blur transition hover:bg-emerald-500/25">✓ {{ $e('Одобрить') }}</button>
                    <button @click="rejecting = s" class="rounded-xl border border-rose-300/60 bg-rose-500/10 px-4 py-2 text-sm font-semibold text-rose-700 backdrop-blur transition hover:bg-rose-500/20">✕ {{ $e('Отклонить') }}</button>
                </div>
            </div>
            <div v-if="!services.data.length" class="rounded-3xl border border-dashed border-slate-200 bg-white/60 p-12 text-center text-sm text-slate-400 backdrop-blur">{{ $e('Заявок нет') }}</div>
        </div>
        <Pagination :links="services.links" class="mt-4" />

        <!-- Категории услуг -->
        <div v-if="catsOpen" class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-slate-900/30 p-4 backdrop-blur-sm sm:p-10" @click.self="catsOpen = false">
            <div class="w-full max-w-lg rounded-3xl border border-white/60 bg-white/95 p-6 shadow-soft-lg backdrop-blur-xl">
                <h2 class="text-lg font-semibold text-slate-900">{{ $e('Категории услуг') }}</h2>
                <p class="mt-0.5 text-xs text-slate-500">{{ $e('Их видят партнёры в форме и посетители в каталоге. Удаление не трогает услуги — они остаются «без категории».') }}</p>
                <div class="mt-3 flex gap-2">
                    <input v-model="newCat" @keydown.enter="addCat" type="text" :placeholder="$e('Новая категория')" class="flex-1 rounded-xl border-slate-200 text-sm" />
                    <PrimaryButton :disabled="newCat.trim().length < 2" @click="addCat">+ {{ $e('Добавить') }}</PrimaryButton>
                </div>
                <div class="mt-3 divide-y divide-slate-100">
                    <div v-for="c in categories" :key="c.id" class="flex items-center gap-2 py-2" :class="c.is_active ? '' : 'opacity-50'">
                        <input :value="c.name" @change="renameCat(c, $event.target.value)" class="flex-1 rounded-lg border-0 bg-transparent p-1 text-sm font-medium text-slate-800 focus:bg-slate-50 focus:ring-1 focus:ring-indigo-300" />
                        <span class="text-xs text-slate-400">{{ c.services_count }} {{ $e('усл.') }}</span>
                        <button type="button" @click="toggleCat(c)" class="rounded-lg px-2 py-1 text-xs font-medium" :class="c.is_active ? 'text-slate-500 hover:bg-slate-100' : 'text-emerald-600 hover:bg-emerald-50'">{{ c.is_active ? $e('Скрыть') : $e('Показать') }}</button>
                        <button type="button" @click="delCat(c)" class="rounded-lg px-2 py-1 text-xs font-medium text-slate-400 hover:bg-rose-50 hover:text-rose-600">×</button>
                    </div>
                </div>
                <div class="mt-4 flex justify-end"><SecondaryButton @click="catsOpen = false">{{ $e('Готово') }}</SecondaryButton></div>
            </div>
        </div>

        <div v-if="rejecting" class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/30 p-4 backdrop-blur-sm" @click.self="rejecting = null">
            <div class="w-full max-w-md rounded-3xl border border-white/60 bg-white/95 p-6 shadow-soft-lg backdrop-blur-xl">
                <h2 class="text-lg font-semibold text-slate-900">{{ $e('Отклонить услугу') }}</h2>
                <p class="mt-0.5 text-xs text-slate-500">«{{ rejecting.title }}» — {{ $e('партнёр увидит причину и сможет исправить.') }}</p>
                <textarea v-model="reason" rows="3" class="mt-3 w-full rounded-xl border-slate-200 text-sm" :placeholder="$e('Что не так и что исправить')"></textarea>
                <div class="mt-4 flex justify-end gap-2">
                    <SecondaryButton @click="rejecting = null">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="reason.trim().length < 5" @click="reject">{{ $e('Отклонить') }}</PrimaryButton>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

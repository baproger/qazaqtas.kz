<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { formatDate, formatDuration } from '@/utils/format';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({ screen: Object, stages: Array, projects: Array });

const byStage = (id) => props.projects.filter((p) => p.stage_id === id);

// ТВ-режим: часы + автообновление раз в 30 секунд.
const clock = ref('');
let clockTimer = null, refreshTimer = null;
const nowTs = ref(Date.now());
const tick = () => { clock.value = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' }); nowTs.value = Date.now(); };
const onStage = (p) => p.stage_entered_at ? formatDuration((nowTs.value - new Date(p.stage_entered_at).getTime()) / 1000) : null;
onMounted(() => {
    tick();
    clockTimer = setInterval(tick, 1000);
    refreshTimer = setInterval(() => router.reload({ preserveScroll: true }), 10000);
});
onUnmounted(() => { clearInterval(clockTimer); clearInterval(refreshTimer); });

const title = computed(() => [props.screen?.company, props.screen?.workshop].filter(Boolean).join(' · ') || tr('Цех'));
const leave = () => router.post(route('screen.leave'));

// «Далее» прямо с экрана: работник цеха двигает заказ на следующий этап.
// На последнем этапе кнопки нет — «Готово» нажимается в системе.
const lastStageId = computed(() => props.stages[props.stages.length - 1]?.id);
const advancing = ref(null);
const advance = (p) => {
    if (advancing.value) return;
    advancing.value = p.id;
    router.post(route('screen.advanceProject', p.id), {}, {
        preserveScroll: true,
        onFinish: () => (advancing.value = null),
    });
};
// «Готово» на последнем этапе («Отправка»): заказ завершается и уходит на
// Логистику — спрашиваем подтверждение, чтобы не завершить случайным тыком.
const complete = (p) => {
    if (advancing.value) return;
    if (!window.confirm(`Заказ «${p.name}» готов? Он завершится и уйдёт из цеха на Логистику.`)) return;
    advancing.value = p.id;
    router.post(route('screen.completeProject', p.id), {}, {
        preserveScroll: true,
        onFinish: () => (advancing.value = null),
    });
};
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen bg-slate-50 p-4 lg:p-6">
        <!-- Шапка: цех, живые часы, счётчик заказов — единый светлый стиль -->
        <div class="mb-5 flex items-center justify-between gap-4 rounded-2xl border border-slate-100 bg-white px-5 py-4 shadow-sm">
            <div class="flex items-center gap-3">
                <div>
                    <h1 class="text-2xl font-bold leading-tight text-slate-900 lg:text-3xl">{{ title }}</h1>
                    <div class="text-sm text-slate-400">{{ $e('заказов в работе:') }} <b class="tabular-nums text-slate-600">{{ projects.length }}</b></div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-4 py-2 text-emerald-700">
                    <span class="relative flex h-2.5 w-2.5"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500"></span></span>
                    <span class="text-xl font-bold tabular-nums lg:text-2xl">{{ clock }}</span>
                </div>
                <button @click="leave" class="rounded-lg px-3 py-2 text-xs text-slate-400 transition hover:bg-slate-100 hover:text-slate-600" :title="$e('Сменить код')">{{ $e('выйти') }}</button>
            </div>
        </div>

        <!-- Канбан цеха: только свои этапы и заказы, без сумм -->
        <div class="flex gap-4 overflow-x-auto pb-4">
            <div v-for="stage in stages" :key="stage.id" class="flex w-80 flex-shrink-0 flex-col rounded-2xl bg-slate-200/60">
                <div class="flex items-center justify-between px-4 py-3">
                    <div class="flex items-center gap-2.5">
                        <span class="h-3 w-3 rounded-full" :style="{ backgroundColor: stage.color }"></span>
                        <span class="text-lg font-bold text-slate-800">{{ stage.name }}</span>
                        <span v-if="stage.is_completed" :title="$e('Завершающий')">🏁</span>
                    </div>
                    <span class="rounded-full bg-white px-2.5 py-0.5 text-sm font-bold tabular-nums text-slate-500 shadow-sm">{{ byStage(stage.id).length }}</span>
                </div>
                <div class="flex-1 space-y-2.5 px-3 pb-3">
                    <div v-for="p in byStage(stage.id)" :key="p.id" class="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
                        <div class="text-lg font-bold leading-snug text-slate-900">{{ p.name }}</div>
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-slate-300">{{ p.number }}</span>
                            <span v-if="onStage(p)" class="rounded-full bg-indigo-50 px-2 py-0.5 text-sm font-bold tabular-nums text-indigo-600" :title="$e('Время на этапе')">⏱ {{ onStage(p) }}</span>
                        </div>
                        <div v-if="p.address" class="mt-1.5 text-sm text-slate-500">📍 {{ p.address }}</div>
                        <div v-if="p.deadline" class="mt-1 text-sm font-semibold" :class="p.overdue ? 'text-rose-600' : 'text-slate-600'">⏰ {{ formatDate(p.deadline) }}<span v-if="p.overdue"> {{ $e('· просрочен!') }}</span></div>
                        <div v-if="p.description" class="mt-1.5 whitespace-pre-line text-sm leading-snug text-slate-500">{{ p.description }}</div>
                        <div v-if="p.note" class="mt-2 rounded-lg bg-amber-50 px-2.5 py-1.5 text-sm leading-snug text-amber-800">📌 {{ p.note }}</div>
                        <!-- Крупные кнопки, чтобы удобно жать с ТВ/планшета цеха:
                             «Далее» — следующий этап; на «Отправке» — «Готово» (в Логистику) -->
                        <button v-if="p.stage_id !== lastStageId" @click="advance(p)" :disabled="advancing === p.id"
                            class="mt-2.5 w-full rounded-xl bg-emerald-600 py-2.5 text-base font-bold text-white shadow-sm transition hover:bg-emerald-700 active:scale-95 disabled:opacity-50">
                            {{ advancing === p.id ? '…' : $e('Далее →') }}
                        </button>
                        <button v-else @click="complete(p)" :disabled="advancing === p.id"
                            class="mt-2.5 w-full rounded-xl bg-indigo-600 py-2.5 text-base font-bold text-white shadow-sm transition hover:bg-indigo-700 active:scale-95 disabled:opacity-50">
                            {{ advancing === p.id ? '…' : $e('Готово ✓ — в Логистику') }}
                        </button>
                    </div>
                    <div v-if="!byStage(stage.id).length" class="py-8 text-center text-sm text-slate-400">{{ $e('Пусто') }}</div>
                </div>
            </div>
        </div>
    </div>
</template>

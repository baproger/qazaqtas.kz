<script setup>
/**
 * Товары заказа, у каждого — свои фото.
 *
 * Снимок принадлежит позиции, а не сделке целиком: в цехе по нему сверяют
 * отливку конкретной плитки, и общая куча снимков на весь заказ этого не
 * даёт. Слева товар и объём, справа его фотографии — открыл карточку и сразу
 * видно, что делать и как оно должно выглядеть.
 *
 * Один компонент на сделку и на цех: разойдись они, фото у позиции показывали
 * бы в одном месте и не показывали в другом.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PhotoPanel from '@/Components/PhotoPanel.vue';
import { money } from '@/utils/format';

const props = defineProps({
    items: { type: Array, default: () => [] },
    /** Показывать суммы позиций. Цеху сервер их и не присылает. */
    showMoney: { type: Boolean, default: false },
    /** Сколько по позиции сделано в цехе: [id позиции => план/факт]. */
    progress: { type: Object, default: () => ({}) },
    /**
     * Цех: можно записать выработку и закрыть товар. В карточке сделки этого
     * нет — объём пишут там, где его делают.
     */
    reportable: { type: Boolean, default: false },
    projectId: { type: Number, default: null },
    /** Позиций нет — что писать вместо них (наименование товара из сделки). */
    fallbackName: { type: String, default: '' },
    fallbackQuantity: { type: [String, Number], default: null },
    fallbackUnit: { type: String, default: '' },
});

const qty = (v) => Number(v ?? 0).toLocaleString('ru-RU');
const hasItems = computed(() => props.items.length > 0);

// Прогресс по позиции считает сервер (ProductionProgressService) — тот же,
// что на странице производства. Здесь только рисуем: два места, считающие
// остаток по-своему, рано или поздно разойдутся.
const done = (item) => props.progress[item.id] ?? null;

// «Сделал N» по позиции. Запись становится обычным сменным нарядом и ждёт
// мастера — без его галочки выработку можно было бы приписать себе.
const reporting = ref(null);
const qtyInput = ref('');
const busy = ref(false);

const openReport = (item) => {
    reporting.value = item.id;
    // Подставляем остаток: чаще всего дописывают именно его, а мимо остатка
    // ввести всё равно можно — перевыполнение система показывает, не прячет.
    qtyInput.value = done(item)?.left || '';
};

const saveReport = (item) => {
    if (!(Number(qtyInput.value) > 0) || busy.value) return;
    busy.value = true;
    router.post(route('projects.items.output', [props.projectId, item.id]), { qty: qtyInput.value }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; reporting.value = null; qtyInput.value = ''; },
    });
};

const toggleFinished = (item) => router.post(route('projects.items.finish', [props.projectId, item.id]), {}, { preserveScroll: true });
</script>

<template>
    <div v-if="hasItems" class="divide-y divide-slate-100">
        <div v-for="it in items" :key="it.id" class="grid gap-3 py-3 first:pt-0 last:pb-0 sm:grid-cols-2 sm:gap-4">
            <!-- Слева: что делать и сколько. -->
            <div class="min-w-0">
                <div class="flex items-baseline gap-2">
                    <span class="min-w-0 text-[15px] font-medium leading-snug" :class="it.finished_at ? 'text-slate-400 line-through' : 'text-slate-800'">🧱 {{ it.name }}</span>
                    <span v-if="it.finished_at" class="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700"
                        :title="it.finisher?.name ? $e('отметил') + ': ' + it.finisher.name : ''">✓ {{ $e('закончен') }}</span>
                </div>
                <div class="mt-1 flex items-baseline gap-3 tabular-nums">
                    <b class="text-[19px] font-bold leading-none text-slate-900">
                        {{ qty(it.quantity) }} <span class="text-sm font-semibold text-slate-500">{{ it.unit }}</span>
                    </b>
                    <span v-if="showMoney && it.amount" class="text-sm text-slate-400">{{ money(it.amount) }}</span>
                </div>

                <!-- Сделано в цехе: сколько из этого объёма уже закрыто нарядами. -->
                <div v-if="done(it)" class="mt-2">
                    <div class="flex items-baseline gap-2 text-xs tabular-nums">
                        <span class="text-slate-400">{{ $e('сделано') }}</span>
                        <b :class="done(it).over ? 'text-amber-600' : done(it).left === 0 ? 'text-emerald-600' : 'text-slate-700'">
                            {{ qty(done(it).done) }} / {{ qty(done(it).plan) }} {{ it.unit }}
                        </b>
                        <span v-if="done(it).pending" class="rounded bg-amber-50 px-1 py-px text-[10px] font-semibold text-amber-700"
                            :title="$e('внесено, но мастер ещё не подтвердил')">+{{ qty(done(it).pending) }} {{ $e('ждёт') }}</span>
                    </div>
                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full transition-all duration-500"
                            :class="done(it).over ? 'bg-amber-400' : done(it).percent >= 100 ? 'bg-emerald-500' : 'bg-indigo-500'"
                            :style="{ width: Math.min(done(it).percent, 100) + '%' }"></div>
                    </div>
                </div>

                <!-- Цех: записать сделанное и закрыть товар. Запись уходит
                     сменным нарядом и ждёт мастера — сразу в «сделано» она не
                     попадает, иначе объём можно было бы приписать себе. -->
                <div v-if="reportable" class="mt-2 flex flex-wrap items-center gap-2">
                    <template v-if="reporting === it.id">
                        <input v-model="qtyInput" type="number" min="0" step="any" autofocus
                            class="w-24 rounded-lg border-slate-200 py-1 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20"
                            :placeholder="it.unit" @keyup.enter="saveReport(it)" />
                        <span class="text-xs text-slate-400">{{ it.unit }}</span>
                        <button :disabled="busy" @click="saveReport(it)"
                            class="rounded-lg bg-indigo-600 px-3 py-1 text-xs font-semibold text-white transition-colors duration-150 hover:bg-indigo-700 disabled:opacity-50">{{ $e('Записать') }}</button>
                        <button class="text-xs text-slate-400 hover:text-slate-600" @click="reporting = null">{{ $e('Отмена') }}</button>
                    </template>
                    <button v-else-if="!it.finished_at" @click="openReport(it)"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 transition-colors duration-150 hover:bg-slate-200">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                        {{ $e('Сделал') }}
                    </button>

                    <button @click="toggleFinished(it)"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors duration-150"
                        :class="it.finished_at ? 'text-slate-400 hover:bg-slate-100' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100'">
                        {{ it.finished_at ? $e('Вернуть в работу') : $e('Товар закончен') }}
                    </button>
                </div>
            </div>

            <!-- Справа: фото именно этого товара. -->
            <PhotoPanel :documents="it.documents ?? []" entity-type="deal_item" :entity-id="it.id" compact />
        </div>
    </div>

    <!-- Позиций нет: сделка заведена одной строкой «наименование + количество». -->
    <div v-else-if="fallbackName">
        <div class="text-[15px] font-medium leading-snug text-slate-800">🧱 {{ fallbackName }}</div>
        <b v-if="fallbackQuantity" class="text-[19px] font-bold tabular-nums text-slate-900">
            {{ qty(fallbackQuantity) }} <span class="text-sm font-semibold text-slate-500">{{ fallbackUnit }}</span>
        </b>
        <p class="mt-2 text-xs text-slate-400">{{ $e('Фото крепятся к позициям — добавьте товары в заказ') }}</p>
    </div>

    <div v-else class="text-sm text-slate-400">{{ $e('Позиции не заданы — смотрите описание') }}</div>
</template>

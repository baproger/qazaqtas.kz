<script setup>
/**
 * Бонусы за год: 12 месяцев в строке сотрудника.
 *
 * Одна месячная цифра отвечала только на вопрос «сколько заработано в этом
 * месяце». Здесь видно другое: где бонус уже забрали, где он копится и
 * сколько человеку должны на сегодня.
 */
import { ref, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import FinanceLayout from '@/Layouts/FinanceLayout.vue';
import FinanceTile from '@/Components/FinanceTile.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Avatar from '@/Components/Avatar.vue';
import { money, formatDate } from '@/utils/format';
import { confirmDialog } from '@/composables/useConfirm';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    year: { type: Number, default: 0 },
    rows: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ accrued: 0, paid: 0, left: 0 }) },
    canPay: { type: Boolean, default: false },
    payouts: { type: Array, default: () => [] },
});

const MONTHS = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

const setYear = (year) => router.get(route('bonuses.index'), { year },
    { preserveState: true, preserveScroll: true, replace: true });

// Выплата: месяцы выбираются галочками — можно закрыть один месяц, можно
// весь накопленный остаток разом.
const paying = ref(null);
const form = useForm({ user_id: '', months: [], payment_method: 'cash', note: '' });
const openPay = (row) => {
    paying.value = row;
    form.reset();
    form.clearErrors();
    form.user_id = row.uid;
    // По умолчанию — все месяцы с остатком: обычно забирают всё накопленное.
    form.months = row.months.filter((m) => m.left > 0).map((m) => m.month);
};
const payTotal = computed(() => {
    if (!paying.value) return 0;

    return paying.value.months
        .filter((m) => form.months.includes(m.month))
        .reduce((sum, m) => sum + m.left, 0);
});
const toggleMonth = (month) => {
    form.months = form.months.includes(month)
        ? form.months.filter((m) => m !== month)
        : [...form.months, month];
};
const submit = () => form.post(route('bonuses.pay'), {
    preserveScroll: true,
    onSuccess: () => (paying.value = null),
});

const cancelPayout = async (payout) => {
    if (!(await confirmDialog({
        title: tr('Отменить выплату бонуса'),
        message: `${money(payout.amount)} за ${payout.month} — деньги вернутся в кассу.`,
        confirmText: tr('Отменить выплату'),
        danger: true,
    }))) return;
    router.delete(route('bonuses.destroy', payout.id), { preserveScroll: true });
};

// Цвет ячейки говорит о состоянии месяца: выплачен, копится или пуст.
const cellClass = (cell) => {
    if (cell.accrued <= 0) return 'text-slate-300';
    if (cell.left <= 0) return 'text-emerald-600';

    return 'font-semibold text-amber-700';
};
</script>

<template>
    <Head :title="$e('Бонусы')" />
    <FinanceLayout :title="$e('Бонусы')" :subtitle="$e('год целиком: начислено по месяцам, выплачено и накоплено')">
        <template #actions>
            <button @click="setYear(year - 1)"
                class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm text-slate-600 transition-colors duration-150 hover:bg-slate-50">←</button>
            <span class="text-sm font-semibold tabular-nums text-slate-700">{{ year }}</span>
            <button @click="setYear(year + 1)"
                class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm text-slate-600 transition-colors duration-150 hover:bg-slate-50">→</button>
        </template>

        <div class="grid gap-3 sm:grid-cols-3">
            <FinanceTile :label="$e('Начислено за год')" :value="money(totals.accrued)" />
            <FinanceTile tone="good" :label="$e('Выплачено')" :value="money(totals.paid)" />
            <FinanceTile tone="warn" :label="$e('К выплате сотрудникам')" :value="money(totals.left)"
                :hint="$e('накопленный бонус, который ещё не забрали')" />
        </div>

        <div class="mt-6 rounded-2xl border border-slate-100 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">{{ $e('Бонусы по месяцам') }}</h3>
                <span class="text-xs text-slate-400">{{ $e('месяц бонуса — когда пришли деньги от клиента') }}</span>
            </div>

            <div v-if="!rows.length" class="px-6 py-10 text-center text-sm text-slate-400">
                {{ $e('За этот год бонусов не начислялось.') }}
            </div>

            <div v-else class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <!-- Сотрудник слева, «К выплате» справа — прилипают
                                 к краям: 12 месяцев в экран не помещаются, и
                                 главная цифра уезжала за правый край. -->
                            <th class="sticky left-0 z-10 bg-slate-50 px-6 py-2.5">{{ $e('Сотрудник') }}</th>
                            <th v-for="(m, i) in MONTHS" :key="m" class="px-2 py-2.5 text-right">{{ m }}</th>
                            <th class="px-4 py-2.5 text-right">{{ $e('Начислено') }}</th>
                            <th class="px-4 py-2.5 text-right">{{ $e('Выплачено') }}</th>
                            <th class="sticky right-0 z-10 border-l border-slate-200 bg-slate-50 px-4 py-2.5 text-right">{{ $e('К выплате') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="r in rows" :key="r.uid" class="transition-colors duration-150 hover:bg-slate-50/60">
                            <td class="sticky left-0 z-10 whitespace-nowrap bg-white px-6 py-2.5">
                                <div class="flex items-center gap-2">
                                    <Avatar :name="r.name" :src="r.avatar" :size="28" />
                                    <span class="font-medium text-slate-900">{{ r.name }}</span>
                                </div>
                            </td>
                            <!-- Ячейка месяца: зелёная — забрал, янтарная — копится -->
                            <!-- В месяце видно и сумму, и судьбу бонуса: забрал
                                 его сотрудник или он копится дальше. -->
                            <td v-for="cell in r.months" :key="cell.month"
                                class="whitespace-nowrap px-2 py-2.5 text-right tabular-nums" :class="cellClass(cell)"
                                :title="cell.accrued > 0
                                    ? `${$e('начислено')} ${money(cell.accrued)} · ${$e('выплачено')} ${money(cell.paid)}`
                                    : $e('нет начислений')">
                                <template v-if="cell.accrued > 0">
                                    {{ money(cell.accrued) }}
                                    <div class="text-xs font-normal">
                                        {{ cell.left <= 0 ? $e('✓ получил') : $e('копит') }}
                                    </div>
                                </template>
                                <span v-else>—</span>
                            </td>
                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-800">{{ money(r.accrued) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-emerald-600">
                                {{ money(r.paid) }}
                                <!-- Переплата: выдали больше, чем начислено -->
                                <div v-if="r.overpaid > 0" class="text-xs font-semibold text-rose-600"
                                    :title="$e('Начисление уменьшилось уже после выплаты — удалили наряд или выросли расходы по сделке.')">
                                    {{ $e('переплата') }} {{ money(r.overpaid) }}
                                </div>
                            </td>
                            <!-- Кнопка выплаты живёт в этой же ячейке: отдельным
                                 столбцом она оказывалась правее прилипшей
                                 колонки и закрывала сумму при прокрутке. -->
                            <td class="sticky right-0 z-10 whitespace-nowrap border-l border-slate-200 bg-white px-4 py-2.5 text-right">
                                <div class="font-bold tabular-nums" :class="r.left > 0 ? 'text-amber-700' : 'text-slate-300'">
                                    {{ r.left > 0 ? money(r.left) : '—' }}
                                </div>
                                <button v-if="canPay && r.left > 0" @click="openPay(r)"
                                    class="mt-1 rounded-lg bg-indigo-600 px-3 py-1 text-xs font-semibold text-white transition-colors duration-150 hover:bg-indigo-700">{{ $e('Выплатить') }}</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="border-t border-slate-100 px-6 py-3 text-xs text-slate-400">
                {{ $e('Зелёным — бонус за месяц получен, янтарным — копится. «К выплате» — сколько сотруднику должны на сегодня; в ведомости ЗП идёт та же сумма.') }}
            </p>
        </div>

        <!-- История выплат: когда бонус реально забрали -->
        <div v-if="payouts.length" class="mt-6 rounded-2xl border border-slate-100 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">{{ $e('Выплаты бонусов') }}</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-6 py-2.5">{{ $e('Дата') }}</th>
                            <th class="px-4 py-2.5">{{ $e('Сотрудник') }}</th>
                            <th class="px-4 py-2.5">{{ $e('За месяц') }}</th>
                            <th class="px-4 py-2.5 text-right">{{ $e('Сумма') }}</th>
                            <th class="px-4 py-2.5">{{ $e('Как выдано') }}</th>
                            <th v-if="canPay" class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="p in payouts" :key="p.id" class="group transition-colors duration-150 hover:bg-slate-50/60">
                            <td class="px-6 py-2.5 text-slate-500">{{ formatDate(p.date) }}</td>
                            <td class="px-4 py-2.5 text-slate-700">{{ p.user }}</td>
                            <td class="px-4 py-2.5 text-slate-500">{{ p.month }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-800">{{ money(p.amount) }}</td>
                            <td class="px-4 py-2.5">
                                <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                                    {{ p.method === 'cash' ? $e('наличные') : $e('банк') }}
                                </span>
                            </td>
                            <td v-if="canPay" class="px-4 py-2.5 text-right">
                                <button @click="cancelPayout(p)"
                                    class="rounded p-1 text-slate-300 opacity-0 transition hover:text-rose-600 group-hover:opacity-100"
                                    :title="$e('Отменить выплату')">✕</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Выплата бонуса: месяцы выбираются галочками -->
        <Modal :show="!!paying" @close="paying = null" max-width="lg">
            <div v-if="paying" class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-slate-900">{{ $e('Выплатить бонус') }} · {{ paying.name }}</h2>
                <p class="mb-4 text-xs text-slate-400">{{ $e('Отметьте месяцы. Деньги уйдут из кассы или банка — это подтверждённый расход компании.') }}</p>

                <div class="max-h-56 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
                    <label v-for="m in paying.months.filter((x) => x.left > 0)" :key="m.month"
                        class="flex cursor-pointer items-center justify-between gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-slate-50">
                        <span class="flex items-center gap-2">
                            <input type="checkbox" :checked="form.months.includes(m.month)" @change="toggleMonth(m.month)"
                                class="rounded border-slate-300 text-indigo-600" />
                            <span class="text-slate-600">{{ m.month }}</span>
                        </span>
                        <span class="tabular-nums text-slate-800">{{ money(m.left) }}</span>
                    </label>
                </div>
                <div v-if="form.errors.months" class="mt-1 text-xs text-red-600">{{ form.errors.months }}</div>

                <div class="mt-4 flex gap-2">
                    <button type="button" @click="form.payment_method = 'cash'"
                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors duration-150"
                        :class="form.payment_method === 'cash' ? 'border-emerald-500 bg-emerald-100 text-emerald-700 ring-1 ring-emerald-500' : 'border-slate-200 bg-white text-slate-500'">{{ $e('Наличные') }}</button>
                    <button type="button" @click="form.payment_method = 'bank'"
                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors duration-150"
                        :class="form.payment_method === 'bank' ? 'border-sky-500 bg-sky-100 text-sky-700 ring-1 ring-sky-500' : 'border-slate-200 bg-white text-slate-500'">{{ $e('Банк') }}</button>
                </div>

                <div class="mt-4">
                    <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Заметка') }}</label>
                    <input v-model="form.note" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                </div>

                <div class="mt-5 flex items-center justify-between gap-3">
                    <span class="text-sm text-slate-500">{{ $e('К выдаче:') }}
                        <b class="text-base tabular-nums text-slate-900">{{ money(payTotal) }}</b>
                    </span>
                    <div class="flex gap-2">
                        <SecondaryButton @click="paying = null">{{ $e('Отмена') }}</SecondaryButton>
                        <PrimaryButton :disabled="form.processing || !form.months.length" @click="submit">{{ $e('Выплатить') }}</PrimaryButton>
                    </div>
                </div>
            </div>
        </Modal>
    </FinanceLayout>
</template>

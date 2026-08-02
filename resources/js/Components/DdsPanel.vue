<script setup>
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { money } from '@/utils/format';
import { confirmDialog } from '@/composables/useConfirm';

// ДДС — ручная Excel-сводка финансиста: счета компаний + долги.
// Цифры НИОТКУДА не считаются — только то, что введено руками.
const props = defineProps({
    dds: { type: Object, default: () => ({ accounts: [], debts: [], date: '' }) },
    canManage: Boolean,
});

const totals = computed(() => ({
    balance: props.dds.accounts.reduce((s, a) => s + Number(a.balance ?? 0), 0),
    receivable: props.dds.accounts.reduce((s, a) => s + Number(a.receivable ?? 0), 0),
    debts: props.dds.debts.reduce((s, d) => s + Number(d.amount ?? 0), 0),
}));

// Дата сводки (шапка, как в Excel).
const editDate = ref(false);
const dateInput = ref('');
const saveDate = () => router.post(route('finance.dds.date'), { dds_date: dateInput.value }, {
    preserveScroll: true, onSuccess: () => (editDate.value = false),
});
const openDateEdit = () => { dateInput.value = props.dds.date; editDate.value = true; };

// Добавление / правка строк (одна форма на обе секции).
const editingId = ref(null);   // id строки в режиме правки
const addingKind = ref(null);  // 'account' | 'debt' — открыта форма добавления
const form = useForm({ kind: 'account', name: '', bank: '', balance: null, receivable: null, amount: null });

const startAdd = (kind) => {
    editingId.value = null;
    form.reset();
    form.kind = kind;
    addingKind.value = kind;
};
const startEdit = (row) => {
    addingKind.value = null;
    editingId.value = row.id;
    Object.assign(form, {
        kind: row.kind, name: row.name, bank: row.bank ?? '',
        balance: row.balance, receivable: row.receivable, amount: row.amount,
    });
};
const cancel = () => { editingId.value = null; addingKind.value = null; form.reset(); };
const save = () => {
    const opts = { preserveScroll: true, onSuccess: cancel };
    editingId.value ? form.put(route('finance.dds.update', editingId.value), opts) : form.post(route('finance.dds.store'), opts);
};
const remove = async (row) => {
    if (await confirmDialog({ title: 'Удалить строку ДДС', message: `«${row.name}» будет удалена из сводки.`, confirmText: 'Удалить', danger: true })) {
        router.delete(route('finance.dds.destroy', row.id), { preserveScroll: true });
    }
};
const fmt = (v) => (v === null || v === undefined || v === '' ? '—' : money(Number(v)));
</script>

<template>
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-slate-800">
                ДДС — сводка по счетам и долгам
                <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-slate-400" title="Цифры вводятся вручную и нигде не пересчитываются">ручной ввод</span>
            </h3>
            <div class="flex items-center gap-2 text-sm">
                <template v-if="editDate">
                    <input v-model="dateInput" placeholder="20.07.2026" class="w-32 rounded-lg border-slate-200 py-1 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400" />
                    <button @click="saveDate" class="rounded-lg bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-indigo-700">ОК</button>
                    <button @click="editDate = false" class="text-slate-400 hover:text-slate-600">✕</button>
                </template>
                <template v-else>
                    <span class="rounded-lg bg-sky-50 px-3 py-1 font-semibold text-sky-700">{{ dds.date || 'дата не указана' }}</span>
                    <button v-if="canManage" @click="openDateEdit" title="Изменить дату" class="text-slate-300 hover:text-indigo-500">✎</button>
                </template>
            </div>
        </div>

        <div class="grid gap-5 xl:grid-cols-3">
            <!-- Счета компаний -->
            <div class="xl:col-span-2 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase text-slate-400">
                        <tr>
                            <th class="py-2 pr-3">Компания</th><th class="py-2 pr-3">Банк</th>
                            <th class="py-2 pr-3 text-right">Фактический остаток</th>
                            <th class="py-2 pr-3 text-right">Дебиторский</th>
                            <th v-if="canManage" class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="a in dds.accounts" :key="a.id" class="group hover:bg-slate-50">
                            <template v-if="editingId === a.id">
                                <td class="py-1.5 pr-2"><input v-model="form.name" class="w-full rounded-lg border-slate-200 py-1 text-sm" /></td>
                                <td class="py-1.5 pr-2"><input v-model="form.bank" class="w-24 rounded-lg border-slate-200 py-1 text-sm" /></td>
                                <td class="py-1.5 pr-2"><input v-model="form.balance" type="number" step="0.01" class="w-32 rounded-lg border-slate-200 py-1 text-right text-sm" /></td>
                                <td class="py-1.5 pr-2"><input v-model="form.receivable" type="number" step="0.01" class="w-32 rounded-lg border-slate-200 py-1 text-right text-sm" /></td>
                                <td class="py-1.5 whitespace-nowrap text-right">
                                    <button @click="save" class="text-xs font-semibold text-indigo-600 hover:underline">Сохранить</button>
                                    <button @click="cancel" class="ml-2 text-xs text-slate-400 hover:underline">Отмена</button>
                                </td>
                            </template>
                            <template v-else>
                                <td class="py-2 pr-3 font-medium text-slate-800">{{ a.name }}</td>
                                <td class="py-2 pr-3 text-slate-500">{{ a.bank || '—' }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums text-slate-800">{{ fmt(a.balance) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums text-slate-600">{{ fmt(a.receivable) }}</td>
                                <td v-if="canManage" class="py-2 whitespace-nowrap text-right text-xs opacity-0 transition group-hover:opacity-100">
                                    <button @click="startEdit(a)" class="text-indigo-600 hover:underline">✎</button>
                                    <button @click="remove(a)" class="ml-2 text-rose-500 hover:underline">✕</button>
                                </td>
                            </template>
                        </tr>
                        <!-- Добавление строки-счёта -->
                        <tr v-if="addingKind === 'account'" class="bg-indigo-50/40">
                            <td class="py-1.5 pr-2"><input v-model="form.name" placeholder="Компания" class="w-full rounded-lg border-slate-200 py-1 text-sm" /></td>
                            <td class="py-1.5 pr-2"><input v-model="form.bank" placeholder="банк" class="w-24 rounded-lg border-slate-200 py-1 text-sm" /></td>
                            <td class="py-1.5 pr-2"><input v-model="form.balance" type="number" step="0.01" placeholder="0" class="w-32 rounded-lg border-slate-200 py-1 text-right text-sm" /></td>
                            <td class="py-1.5 pr-2"><input v-model="form.receivable" type="number" step="0.01" placeholder="0" class="w-32 rounded-lg border-slate-200 py-1 text-right text-sm" /></td>
                            <td class="py-1.5 whitespace-nowrap text-right">
                                <button @click="save" :disabled="!form.name || form.processing" class="text-xs font-semibold text-indigo-600 hover:underline disabled:opacity-40">Добавить</button>
                                <button @click="cancel" class="ml-2 text-xs text-slate-400 hover:underline">✕</button>
                            </td>
                        </tr>
                        <tr v-if="!dds.accounts.length && addingKind !== 'account'">
                            <td colspan="5" class="py-5 text-center text-xs text-slate-400">Строк пока нет — добавьте компании и остатки</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-200 font-semibold">
                            <td class="py-2 pr-3 text-slate-500">Сумма</td><td></td>
                            <td class="py-2 pr-3 text-right tabular-nums text-rose-600">{{ money(totals.balance) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums text-slate-800">{{ money(totals.receivable) }}</td>
                            <td v-if="canManage"></td>
                        </tr>
                    </tfoot>
                </table>
                <button v-if="canManage && addingKind !== 'account'" @click="startAdd('account')"
                    class="mt-2 rounded-lg border border-dashed border-indigo-300 px-3 py-1.5 text-xs font-medium text-indigo-600 hover:bg-indigo-50">+ Компания</button>
            </div>

            <!-- Долги -->
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase text-slate-400">
                        <tr><th class="py-2 pr-3">Долги</th><th class="py-2 pr-3 text-right">Сумма</th><th v-if="canManage" class="py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="d in dds.debts" :key="d.id" class="group hover:bg-slate-50">
                            <template v-if="editingId === d.id">
                                <td class="py-1.5 pr-2"><input v-model="form.name" class="w-full rounded-lg border-slate-200 py-1 text-sm" /></td>
                                <td class="py-1.5 pr-2"><input v-model="form.amount" type="number" step="0.01" class="w-28 rounded-lg border-slate-200 py-1 text-right text-sm" /></td>
                                <td class="py-1.5 whitespace-nowrap text-right">
                                    <button @click="save" class="text-xs font-semibold text-indigo-600 hover:underline">ОК</button>
                                    <button @click="cancel" class="ml-1.5 text-xs text-slate-400 hover:underline">✕</button>
                                </td>
                            </template>
                            <template v-else>
                                <td class="py-2 pr-3 font-medium text-slate-800">{{ d.name }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums text-slate-800">{{ fmt(d.amount) }}</td>
                                <td v-if="canManage" class="py-2 whitespace-nowrap text-right text-xs opacity-0 transition group-hover:opacity-100">
                                    <button @click="startEdit(d)" class="text-indigo-600 hover:underline">✎</button>
                                    <button @click="remove(d)" class="ml-2 text-rose-500 hover:underline">✕</button>
                                </td>
                            </template>
                        </tr>
                        <tr v-if="addingKind === 'debt'" class="bg-indigo-50/40">
                            <td class="py-1.5 pr-2"><input v-model="form.name" placeholder="Кому должны" class="w-full rounded-lg border-slate-200 py-1 text-sm" /></td>
                            <td class="py-1.5 pr-2"><input v-model="form.amount" type="number" step="0.01" placeholder="0" class="w-28 rounded-lg border-slate-200 py-1 text-right text-sm" /></td>
                            <td class="py-1.5 whitespace-nowrap text-right">
                                <button @click="save" :disabled="!form.name || form.processing" class="text-xs font-semibold text-indigo-600 hover:underline disabled:opacity-40">ОК</button>
                                <button @click="cancel" class="ml-1.5 text-xs text-slate-400 hover:underline">✕</button>
                            </td>
                        </tr>
                        <tr v-if="!dds.debts.length && addingKind !== 'debt'">
                            <td colspan="3" class="py-5 text-center text-xs text-slate-400">Долгов нет</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-200 font-semibold">
                            <td class="py-2 pr-3 text-slate-500">Итого</td>
                            <td class="py-2 pr-3 text-right tabular-nums text-slate-800">{{ money(totals.debts) }}</td>
                            <td v-if="canManage"></td>
                        </tr>
                    </tfoot>
                </table>
                <button v-if="canManage && addingKind !== 'debt'" @click="startAdd('debt')"
                    class="mt-2 rounded-lg border border-dashed border-indigo-300 px-3 py-1.5 text-xs font-medium text-indigo-600 hover:bg-indigo-50">+ Долг</button>
            </div>
        </div>
    </div>
</template>

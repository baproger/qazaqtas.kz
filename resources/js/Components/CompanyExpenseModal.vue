<script setup>
/**
 * Расход компании (аренда, комуслуги, интернет, бензин…): вводит бухгалтер
 * или админ, подтверждается сразу и уменьшает кассу либо счёт.
 *
 * Общий компонент для Финансов и рабочего места бухгалтера — форма денег
 * должна быть одна, где бы её ни открыли.
 */
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { money } from '@/utils/format';

const props = defineProps({
    show: { type: Boolean, default: false },
    categories: { type: Array, default: () => [] },
    // Остатки: расход списывается с них, поэтому показываем «доступно».
    cash: { type: Number, default: 0 },
    bank: { type: Number, default: 0 },
});
const emit = defineEmits(['close']);

const form = useForm({
    expenseable_type: '', expenseable_id: '', category_id: '', amount: '',
    date: new Date().toISOString().slice(0, 10), payment_method: 'bank',
    description: '', status: 'confirmed', file: null,
});

watch(() => props.show, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
        form.date = new Date().toISOString().slice(0, 10);
    }
});

const onReceipt = (e) => (form.file = e.target.files[0] ?? null);
// Превышение остатка не блокируем — деньги могли прийти мимо системы, — но
// предупреждаем: молча уводить кассу в минус нельзя.
const overBalance = () => Number(form.amount || 0) > Number(form.payment_method === 'cash' ? props.cash : props.bank);
const submit = () => form.post(route('expenses.store'), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => emit('close'),
});
</script>

<template>
    <Modal :show="show" @close="emit('close')" max-width="lg">
        <div class="p-6">
            <h2 class="mb-1 text-lg font-semibold text-slate-900">{{ $e('Расход компании') }}</h2>
            <p class="mb-4 text-xs text-slate-400">{{ $e('Не по сделке: аренда, комуслуги, интернет, бензин, канцтовары… Подтверждается сразу.') }}</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Категория *') }}</label>
                    <select v-model="form.category_id" class="w-full rounded-md border-slate-300 text-sm shadow-sm">
                        <option value="">{{ $e('— выберите —') }}</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <div v-if="form.errors.category_id" class="mt-1 text-xs text-red-600">{{ form.errors.category_id }}</div>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Сумма, ₸ *') }}</label>
                    <input v-model="form.amount" type="number" min="0.01" step="0.01" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                    <div v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</div>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Дата *') }}</label>
                    <input v-model="form.date" type="date" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                </div>
                <div class="sm:col-span-2">
                    <div class="flex gap-2">
                        <button type="button" @click="form.payment_method = 'cash'"
                            class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-all"
                            :class="form.payment_method === 'cash' ? 'border-emerald-500 bg-emerald-100 text-emerald-700 ring-1 ring-emerald-500' : 'border-slate-200 bg-white text-slate-500'">{{ $e('Наличные') }}</button>
                        <button type="button" @click="form.payment_method = 'bank'"
                            class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-all"
                            :class="form.payment_method === 'bank' ? 'border-sky-500 bg-sky-100 text-sky-700 ring-1 ring-sky-500' : 'border-slate-200 bg-white text-slate-500'">{{ $e('Банк (счёт)') }}</button>
                    </div>
                    <div class="mt-1.5 text-[11px]" :class="overBalance() ? 'font-semibold text-rose-600' : 'text-slate-400'">
                        {{ $e('Доступно: касса') }} {{ money(cash) }} {{ $e('· счёт') }} {{ money(bank) }}
                        <template v-if="overBalance()"> {{ $e('— расход превышает остаток') }} {{ form.payment_method === 'cash' ? $e('кассы') : $e('счёта') }}!</template>
                    </div>
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Описание') }}</label>
                    <input v-model="form.description" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" :placeholder="$e('За что…')" />
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-500">{{ $e('Чек / квитанция (фото или PDF, необязательно)') }}</label>
                    <input type="file" accept="image/*,.pdf" @change="onReceipt"
                        class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100" />
                    <div v-if="form.errors.file" class="mt-1 text-xs text-red-600">{{ form.errors.file }}</div>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <SecondaryButton @click="emit('close')">{{ $e('Отмена') }}</SecondaryButton>
                <PrimaryButton :disabled="form.processing || !form.category_id || !(Number(form.amount) > 0)" @click="submit">{{ $e('Сохранить расход') }}</PrimaryButton>
            </div>
        </div>
    </Modal>
</template>

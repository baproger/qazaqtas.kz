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
import ExpenseFields from '@/Components/ExpenseFields.vue';
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
            <ExpenseFields :form="form" :categories="categories">
                <template #middle>
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
                </template>
            </ExpenseFields>
            <div class="mt-6 flex justify-end gap-2">
                <SecondaryButton @click="emit('close')">{{ $e('Отмена') }}</SecondaryButton>
                <PrimaryButton :disabled="form.processing || !form.category_id || !(Number(form.amount) > 0)" @click="submit">{{ $e('Сохранить расход') }}</PrimaryButton>
            </div>
        </div>
    </Modal>
</template>

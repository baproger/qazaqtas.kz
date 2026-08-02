<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, default: '' },
    color: { type: String, default: '' },
});

const map = {
    draft: 'bg-slate-100 text-slate-700',
    active: 'bg-blue-100 text-blue-700',
    closed: 'bg-green-100 text-green-700',
    completed: 'bg-green-100 text-green-700',
    cancelled: 'bg-red-100 text-red-700',
    new: 'bg-slate-100 text-slate-700',
    in_progress: 'bg-amber-100 text-amber-700',
    review: 'bg-purple-100 text-purple-700',
    done: 'bg-green-100 text-green-700',
    legal: 'bg-indigo-100 text-indigo-700',
    individual: 'bg-teal-100 text-teal-700',
    // Статусы счетов (invoices)
    sent: 'bg-blue-100 text-blue-700',
    partial: 'bg-amber-100 text-amber-700',
    paid: 'bg-green-100 text-green-700',
};

const labels = {
    draft: 'Черновик', active: 'Активна', closed: 'Закрыта', completed: 'Завершён',
    cancelled: 'Отменена', new: 'Новая', in_progress: 'В работе', review: 'Проверка',
    done: 'Готово', legal: 'Юр. лицо', individual: 'Физ. лицо',
    // Статусы счетов: выставлен → частично оплачен → оплачен
    sent: 'Выставлен', partial: 'Частично оплачен', paid: 'Оплачен',
};

const cls = computed(() => map[props.status] ?? 'bg-slate-100 text-slate-700');
const label = computed(() => labels[props.status] ?? props.status);
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
        :class="cls"
        :style="color ? { backgroundColor: color + '22', color } : {}"
    >
        {{ label }}
    </span>
</template>

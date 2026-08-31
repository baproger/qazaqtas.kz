<script setup>
import { computed } from 'vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    status: { type: String, default: '' },
    color: { type: String, default: '' },
});

const map = {
    draft: 'bg-slate-100 text-slate-700 dark:bg-slate-800/60 dark:text-slate-300',
    active: 'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300',
    closed: 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400',
    completed: 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400',
    cancelled: 'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400',
    new: 'bg-slate-100 text-slate-700 dark:bg-slate-800/60 dark:text-slate-300',
    in_progress: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400',
    review: 'bg-purple-100 dark:bg-purple-500/20 text-purple-700 dark:text-purple-300',
    done: 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400',
    legal: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300',
    individual: 'bg-teal-100 dark:bg-teal-500/20 text-teal-700 dark:text-teal-300',
    // Статусы счетов (invoices)
    sent: 'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300',
    partial: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400',
    paid: 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400',
};

const labels = {
    draft: tr('Черновик'), active: tr('Активна'), closed: tr('Закрыта'), completed: tr('Завершён'),
    cancelled: tr('Отменена'), new: tr('Новая'), in_progress: tr('В работе'), review: tr('Проверка'),
    done: tr('Готово'), legal: tr('Юр. лицо'), individual: tr('Физ. лицо'),
    // Статусы счетов: выставлен → частично оплачен → оплачен
    sent: tr('Выставлен'), partial: tr('Частично оплачен'), paid: tr('Оплачен'),
};

const cls = computed(() => map[props.status] ?? 'bg-slate-100 text-slate-700 dark:bg-slate-800/60 dark:text-slate-300');
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

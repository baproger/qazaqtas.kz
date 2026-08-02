<script setup>
defineProps({ history: { type: Array, default: () => [] } });

const actionLabel = { created: 'Создал(а)', updated: 'Изменил(а)', deleted: 'Удалил(а)' };
const actionColor = { created: 'bg-emerald-500', updated: 'bg-amber-500', deleted: 'bg-rose-500' };
const fieldRu = {
    name: 'Название', budget: 'Бюджет', status: 'Статус', deal_stage_id: 'Этап',
    project_stage_id: 'Этап', responsible_user_id: 'Ответственный', deadline: 'Срок',
    description: 'Описание', client_name: 'Клиент', company_name: 'Компания', closed_at: 'Закрытие',
};
const fmt = (t) => new Date(t).toLocaleString('ru-RU');
</script>

<template>
    <div class="space-y-0">
        <div v-for="(log, i) in history" :key="log.id" class="flex gap-3">
            <div class="flex flex-col items-center">
                <span class="mt-1 h-2.5 w-2.5 rounded-full" :class="actionColor[log.action] || 'bg-slate-400'"></span>
                <span v-if="i < history.length - 1" class="w-px flex-1 bg-slate-200"></span>
            </div>
            <div class="pb-4 text-sm">
                <div class="text-slate-800">
                    <span class="font-medium">{{ log.user?.name ?? 'Система' }}</span>
                    <span class="text-slate-500"> {{ (actionLabel[log.action] || log.action).toLowerCase() }}</span>
                    <span v-if="log.field_name" class="text-slate-500"> «{{ fieldRu[log.field_name] || log.field_name }}»</span>
                </div>
                <div v-if="log.field_name" class="mt-0.5 text-xs">
                    <span class="text-rose-500 line-through">{{ log.old_value ?? '∅' }}</span>
                    <span class="mx-1">→</span>
                    <span class="text-emerald-600">{{ log.new_value ?? '∅' }}</span>
                </div>
                <div class="text-[11px] text-slate-400">{{ fmt(log.created_at) }}</div>
            </div>
        </div>
        <div v-if="!history.length" class="flex flex-col items-center gap-2 py-8 text-center">
            <svg class="h-10 w-10 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            <span class="text-sm text-slate-400">История пуста</span>
        </div>
    </div>
</template>

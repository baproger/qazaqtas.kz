<script setup>
/**
 * Строка настройки: название и пояснение слева, управление справа.
 * Одна форма для всех страниц — чтобы поля и тогглы стояли одинаково.
 */
defineProps({ title: { type: String, required: true }, description: { type: String, default: '' } });
</script>

<template>
    <div class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <div class="text-sm font-medium text-slate-900">{{ title }}</div>
            <p v-if="description" class="mt-0.5 text-sm text-slate-500">{{ description }}</p>
        </div>
        <div class="shrink-0 sm:w-64 sm:text-right">
            <slot />
        </div>
    </div>
</template>

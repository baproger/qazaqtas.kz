<script setup>
/** Блок настроек: заголовок с подчёркиванием и список строк. */
defineProps({ title: { type: String, required: true }, description: { type: String, default: '' } });
</script>

<template>
    <section class="rounded-2xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-sm">
        <div class="border-b border-slate-100 dark:border-slate-800 px-6 py-4">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ title }}</h2>
            <p v-if="description" class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ description }}</p>
        </div>
        <div class="divide-y divide-slate-100 dark:divide-slate-800">
            <slot />
        </div>
    </section>
</template>

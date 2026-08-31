<script setup>
import { Link } from '@inertiajs/vue3';
import { usePageLinks } from '@/utils/pagination';

const props = defineProps({
    links: { type: Array, default: () => [] },
});

const pageLinks = usePageLinks(() => props.links);
</script>

<template>
    <nav v-if="pageLinks.length > 3" class="flex flex-wrap items-center gap-1">
        <template v-for="link in pageLinks" :key="link.key">
            <span
                v-if="link.url === null"
                class="inline-flex h-8 min-w-8 items-center justify-center rounded px-3 text-sm text-slate-400"
                :aria-label="link.aria"
            >
                <svg v-if="link.arrow" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="link.arrow === 'prev' ? 'M19 12H5M11 18l-6-6 6-6' : 'M5 12h14M13 6l6 6-6 6'" /></svg>
                <template v-else>{{ link.label }}</template>
            </span>
            <Link
                v-else
                :href="link.url"
                preserve-scroll
                :class="link.active ? 'bg-indigo-600 text-white' : 'bg-white text-slate-700 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'"
                class="inline-flex h-8 min-w-8 items-center justify-center rounded px-3 text-sm ring-1 ring-slate-200 dark:ring-slate-800"
                :aria-label="link.aria"
            >
                <svg v-if="link.arrow" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="link.arrow === 'prev' ? 'M19 12H5M11 18l-6-6 6-6' : 'M5 12h14M13 6l6 6-6 6'" /></svg>
                <template v-else>{{ link.label }}</template>
            </Link>
        </template>
    </nav>
</template>

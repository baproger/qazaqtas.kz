<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center rounded-full bg-slate-100/80 px-5 py-2.5 text-xs font-semibold uppercase tracking-widest text-slate-600 transition-colors hover:bg-slate-200/80 transition duration-150 ease-in-out hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25"
    >
        <slot />
    </button>
</template>

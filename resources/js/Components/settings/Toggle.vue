<script setup>
/** Переключатель вкл/выкл — замена чекбоксу в настройках. */
defineProps({ modelValue: { type: Boolean, default: false }, disabled: { type: Boolean, default: false } });
defineEmits(['update:modelValue']);
</script>

<template>
    <button type="button" role="switch" :aria-checked="modelValue" :disabled="disabled"
        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50"
        :class="modelValue ? 'bg-indigo-600' : 'bg-slate-200'"
        @click="$emit('update:modelValue', !modelValue)">
        <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition" :class="modelValue ? 'translate-x-5' : 'translate-x-0.5'" />
    </button>
</template>

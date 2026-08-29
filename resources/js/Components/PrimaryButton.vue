<script setup>
/**
 * Основная кнопка ERP. Один стиль на всё приложение: скруглённый
 * прямоугольник, индиго, обычный регистр, средний вес — как у всех
 * остальных кнопок (Настройки, Права, Структура). Капслок и «пилюля»
 * из Breeze убраны: они выбивались из остального интерфейса.
 */
defineProps({ type: { type: String, default: 'button' } });
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 active:bg-indigo-800 disabled:cursor-not-allowed disabled:opacity-50"
    >
        <slot />
    </button>
</template>

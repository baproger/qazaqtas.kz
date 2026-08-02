<script setup>
import { ref } from 'vue';

const ripples = ref([]);
let rid = 0;
const onClick = (e) => {
    const rect = e.currentTarget.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height);
    ripples.value.push({ id: rid++, x: e.clientX - rect.left - size / 2, y: e.clientY - rect.top - size / 2, size });
    setTimeout(() => ripples.value.shift(), 600);
};
</script>

<template>
    <button
        @click="onClick"
        class="relative inline-flex items-center overflow-hidden rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white shadow-sm transition-all duration-150 ease-in-out hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 active:scale-95"
    >
        <span v-for="r in ripples" :key="r.id" class="rp" :style="{ left: r.x + 'px', top: r.y + 'px', width: r.size + 'px', height: r.size + 'px' }"></span>
        <span class="relative"><slot /></span>
    </button>
</template>

<style scoped>
.rp {
    position: absolute;
    border-radius: 9999px;
    background: rgba(255, 255, 255, 0.45);
    transform: scale(0);
    animation: rp 0.6s linear;
    pointer-events: none;
}
@keyframes rp {
    to { transform: scale(2.5); opacity: 0; }
}
</style>

<script setup>
import { computed } from 'vue';

// Unified user/chat avatar: shows the photo when available, otherwise a
// deterministic coloured initial. Used app-wide for consistent design.
const props = defineProps({
    name: { type: String, default: '' },
    src: { type: [String, null], default: null },
    size: { type: Number, default: 32 }, // px
    ring: { type: Boolean, default: false },
});

const initial = computed(() => (props.name || '?').trim().charAt(0).toUpperCase());
const colors = ['bg-indigo-500', 'bg-emerald-500', 'bg-rose-500', 'bg-amber-500', 'bg-sky-500', 'bg-violet-500', 'bg-teal-500', 'bg-pink-500', 'bg-cyan-500'];
const color = computed(() => {
    let h = 0;
    for (const c of (props.name || '')) h = (h + c.charCodeAt(0)) % colors.length;
    return colors[h];
});
</script>

<template>
    <span :class="[color, ring ? 'ring-2 ring-white' : '']"
        class="inline-flex flex-shrink-0 items-center justify-center overflow-hidden rounded-full font-semibold text-white"
        :style="{ width: size + 'px', height: size + 'px', fontSize: Math.round(size * 0.42) + 'px' }">
        <img v-if="src" :src="src" class="h-full w-full object-cover" alt="" />
        <template v-else>{{ initial }}</template>
    </span>
</template>

<script setup>
// Generic content skeleton shown during slow page navigations.
</script>

<template>
    <div class="space-y-6">
        <!-- Stat row -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div v-for="i in 4" :key="i" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="sk h-3 w-24 rounded"></div>
                <div class="sk mt-3 h-7 w-32 rounded"></div>
                <div class="sk mt-2 h-2.5 w-20 rounded"></div>
            </div>
        </div>
        <!-- Table block -->
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-3.5">
                <div class="sk h-3.5 w-40 rounded"></div>
            </div>
            <div class="divide-y divide-slate-50">
                <div v-for="i in 6" :key="i" class="flex items-center gap-4 px-5 py-3.5">
                    <div class="sk h-3 w-8 rounded"></div>
                    <div class="flex-1 space-y-1.5">
                        <div class="sk h-3 w-1/3 rounded"></div>
                        <div class="sk h-2.5 w-1/5 rounded"></div>
                    </div>
                    <div class="sk h-3 w-20 rounded"></div>
                    <div class="sk h-3 w-16 rounded"></div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.sk {
    background: linear-gradient(90deg, #eef2f6 25%, #e2e8f0 37%, #eef2f6 63%);
    background-size: 400% 100%;
    animation: shimmer 1.4s ease-in-out infinite;
}
@keyframes shimmer {
    0% { background-position: 100% 0; }
    100% { background-position: -100% 0; }
}
</style>

<script setup>
import Modal from '@/Components/Modal.vue';
import { useConfirmState, answerConfirm } from '@/composables/useConfirm';

const state = useConfirmState();
</script>

<template>
    <Modal :show="state.open" max-width="md" @close="answerConfirm(false)">
        <div class="p-6">
            <div class="flex items-start gap-4">
                <div :class="state.danger ? 'bg-red-100 dark:bg-red-500/20 text-red-600 dark:bg-rose-500/20 dark:text-rose-400' : 'bg-indigo-100 text-indigo-600 dark:bg-indigo-500/20 dark:text-indigo-400'"
                    class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full text-xl">
                    {{ state.danger ? '⚠' : '?' }}
                </div>
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ state.title }}</h2>
                    <p class="mt-1 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ state.message }}</p>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" @click="answerConfirm(false)"
                    class="rounded-lg bg-slate-100 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-200 dark:bg-slate-800/60 dark:text-slate-300 dark:hover:bg-slate-700">
                    {{ state.cancelText }}
                </button>
                <button type="button" @click="answerConfirm(true)"
                    :class="state.danger ? 'bg-red-600 hover:bg-red-700' : 'bg-indigo-600 hover:bg-indigo-700'"
                    class="rounded-lg px-4 py-2 text-sm font-semibold text-white transition-transform hover:scale-[1.02] active:scale-95">
                    {{ state.confirmText }}
                </button>
            </div>
        </div>
    </Modal>
</template>

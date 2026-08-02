<script setup>
import Modal from '@/Components/Modal.vue';
import { useConfirmState, answerConfirm } from '@/composables/useConfirm';

const state = useConfirmState();
</script>

<template>
    <Modal :show="state.open" max-width="md" @close="answerConfirm(false)">
        <div class="p-6">
            <div class="flex items-start gap-4">
                <div :class="state.danger ? 'bg-red-100 text-red-600' : 'bg-indigo-100 text-indigo-600'"
                    class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full text-xl">
                    {{ state.danger ? '⚠' : '?' }}
                </div>
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-slate-900">{{ state.title }}</h2>
                    <p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ state.message }}</p>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" @click="answerConfirm(false)"
                    class="rounded-lg bg-slate-100 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-200">
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

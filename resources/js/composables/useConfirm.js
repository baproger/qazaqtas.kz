import { reactive } from 'vue';

// Shared state for a single app-wide confirmation modal (mounted once in AppLayout).
const state = reactive({
    open: false,
    title: 'Подтверждение',
    message: '',
    confirmText: 'Подтвердить',
    cancelText: 'Отмена',
    danger: false,
    _resolve: null,
});

/**
 * Show a confirmation modal. Returns a Promise that resolves to true/false.
 * Usage: if (await confirmDialog({ message: 'Удалить?', danger: true })) { ... }
 */
export function confirmDialog(opts = {}) {
    state.title = opts.title ?? 'Подтверждение';
    state.message = opts.message ?? '';
    state.confirmText = opts.confirmText ?? 'Подтвердить';
    state.cancelText = opts.cancelText ?? 'Отмена';
    state.danger = opts.danger ?? false;
    state.open = true;

    return new Promise((resolve) => {
        state._resolve = resolve;
    });
}

export function useConfirmState() {
    return state;
}

export function answerConfirm(value) {
    state.open = false;
    if (state._resolve) {
        state._resolve(value);
        state._resolve = null;
    }
}

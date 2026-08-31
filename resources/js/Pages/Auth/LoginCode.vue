<script setup>
/**
 * Второй шаг входа: персональный код ключевого сотрудника.
 *
 * Шесть ячеек, автопереход между ними, вставка кода целиком из буфера,
 * автоотправка после последней цифры. При ошибке ячейки очищаются.
 */
import { computed, onMounted, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthSplitLayout from '@/Layouts/AuthSplitLayout.vue';

const DIGITS = 6;
const cells = ref(Array(DIGITS).fill(''));
const inputs = ref([]);
const form = useForm({ code: '' });

const code = computed(() => cells.value.join(''));

const submit = () => {
    if (code.value.length !== DIGITS || form.processing) return;
    form.code = code.value;
    form.post(route('login.code.store'), {
        onError: () => {
            cells.value = Array(DIGITS).fill('');
            inputs.value[0]?.focus();
        },
    });
};

const onInput = (i, event) => {
    const digits = event.target.value.replace(/\D/g, '');
    if (!digits) {
        cells.value[i] = '';
        return;
    }
    // Вставили код целиком — раскладываем по ячейкам с текущей.
    [...digits].slice(0, DIGITS - i).forEach((d, k) => (cells.value[i + k] = d));
    const next = Math.min(i + digits.length, DIGITS - 1);
    inputs.value[next]?.focus();
    if (code.value.length === DIGITS) submit();
};

const onKeydown = (i, event) => {
    if (event.key === 'Backspace' && !cells.value[i] && i > 0) inputs.value[i - 1]?.focus();
    if (event.key === 'ArrowLeft' && i > 0) inputs.value[i - 1]?.focus();
    if (event.key === 'ArrowRight' && i < DIGITS - 1) inputs.value[i + 1]?.focus();
};

onMounted(() => inputs.value[0]?.focus());
</script>

<template>
    <Head :title="$e('Код входа')" />
    <AuthSplitLayout>
        <div class="auth-reveal mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-50 ring-1 ring-emerald-200" style="animation-delay: 100ms">
            <svg viewBox="0 0 24 24" class="h-7 w-7 text-emerald-600" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 1 1 8 0v3"/><circle cx="12" cy="15.5" r="1.5" fill="currentColor" stroke="none"/></svg>
        </div>

        <h2 class="auth-reveal mt-5 text-center text-3xl font-bold tracking-tight text-slate-900" style="animation-delay: 160ms">{{ $e('Код входа') }}</h2>
        <p class="auth-reveal mt-2 text-center text-sm text-slate-400" style="animation-delay: 220ms">
            {{ $e('Введите персональный код доступа. Его выдаёт администратор.') }}
        </p>

        <form @submit.prevent="submit" class="mt-8">
            <div class="auth-reveal flex justify-center gap-2.5" style="animation-delay: 300ms">
                <input
                    v-for="(c, i) in cells"
                    :key="i"
                    :ref="(el) => (inputs[i] = el)"
                    :value="cells[i]"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    class="h-14 w-12 rounded-xl border text-center text-2xl font-bold text-slate-900 transition-all focus:outline-none"
                    :class="form.errors.code
                        ? 'border-rose-300 bg-rose-50 focus:border-rose-500 focus:ring-2 focus:ring-rose-200'
                        : 'border-slate-200 bg-white focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200'"
                    @input="onInput(i, $event)"
                    @keydown="onKeydown(i, $event)"
                    @focus="$event.target.select()"
                />
            </div>
            <div v-if="form.errors.code" class="mt-3 text-center text-xs font-medium text-rose-600">{{ form.errors.code }}</div>

            <button type="submit" :disabled="form.processing || code.length !== DIGITS"
                class="auth-reveal auth-btn mt-7 w-full rounded-xl bg-gradient-to-r from-emerald-500 to-emerald-600 py-3.5 text-sm font-semibold text-white transition-all duration-200 hover:brightness-105 active:scale-[0.99] disabled:opacity-50"
                style="animation-delay: 380ms">
                <span v-if="!form.processing">{{ $e('Подтвердить') }}</span>
                <span v-else class="inline-flex items-center gap-2">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" class="opacity-90"/></svg>
                    {{ $e('Проверка…') }}
                </span>
            </button>
        </form>

        <p class="auth-reveal mt-7 text-center" style="animation-delay: 450ms">
            <Link :href="route('login.code.cancel')" method="delete" as="button" class="text-xs font-medium text-slate-400 transition hover:text-slate-600">
                ← {{ $e('Вернуться ко входу') }}
            </Link>
        </p>
    </AuthSplitLayout>
</template>

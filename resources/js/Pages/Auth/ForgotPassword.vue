<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthSplitLayout from '@/Layouts/AuthSplitLayout.vue';

defineProps({ status: { type: String } });

const form = useForm({ email: '' });
const submit = () => form.post(route('password.email'));
</script>

<template>
    <Head title="Восстановление пароля" />
    <AuthSplitLayout>
        <h2 class="text-3xl font-bold tracking-tight text-slate-900">Забыли пароль?</h2>
        <p class="mt-2 text-sm text-slate-400">Укажите email — отправим ссылку для сброса пароля, чтобы вы могли задать новый.</p>

        <div v-if="status" class="mt-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">{{ status }}</div>

        <form @submit.prevent="submit" class="mt-8 space-y-5">
            <div class="auth-reveal" style="animation-delay: 250ms">
                <label for="email" class="mb-1.5 block text-sm font-semibold text-slate-700">Email</label>
                <div class="group relative">
                    <span class="auth-ico pointer-events-none absolute inset-y-0 left-0 flex w-11 items-center justify-center text-slate-400">
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="m4 7 8 6 8-6"/></svg>
                    </span>
                    <input id="email" v-model="form.email" type="email" required autofocus autocomplete="username" placeholder="your@email.com" class="auth-input py-3 pl-11 pr-4" />
                </div>
                <div v-if="form.errors.email" class="mt-1.5 text-xs font-medium text-rose-600">{{ form.errors.email }}</div>
            </div>

            <button type="submit" :disabled="form.processing" class="auth-reveal auth-btn w-full rounded-xl bg-gradient-to-r from-emerald-500 to-emerald-600 py-3.5 text-sm font-semibold text-white transition-all duration-200 hover:brightness-105 active:scale-[0.99] disabled:opacity-60" style="animation-delay: 330ms">
                <span v-if="!form.processing" class="relative z-10">Отправить ссылку для сброса</span>
                <span v-else class="relative z-10 inline-flex items-center gap-2">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" class="opacity-90"/></svg>
                    Отправка…
                </span>
            </button>
        </form>

        <p class="auth-reveal mt-7 text-center text-sm text-slate-500" style="animation-delay: 400ms">
            <Link :href="route('login')" class="font-semibold text-emerald-600 hover:text-emerald-700">← Вернуться ко входу</Link>
        </p>
    </AuthSplitLayout>
</template>

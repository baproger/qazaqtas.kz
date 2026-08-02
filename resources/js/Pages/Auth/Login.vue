<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthSplitLayout from '@/Layouts/AuthSplitLayout.vue';

const props = defineProps({
    canResetPassword: { type: Boolean },
    status: { type: String },
    companies: { type: Array, default: () => [] },
});

const form = useForm({ email: '', password: '', remember: false, company_id: props.companies[0]?.id ?? '' });
const showPassword = ref(false);
const submit = () => form.post(route('login'), { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Вход" />
    <AuthSplitLayout>
        <h2 class="text-3xl font-bold tracking-tight text-slate-900">Добро пожаловать</h2>
        <p class="mt-2 text-sm text-slate-400">Войдите в свой аккаунт для продолжения</p>

        <div v-if="status" class="mt-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">{{ status }}</div>

        <form @submit.prevent="submit" class="mt-8 space-y-5">
            <div v-if="companies.length > 1" class="auth-reveal" style="animation-delay: 200ms">
                <label class="mb-1.5 block text-sm font-semibold text-slate-700">Компания</label>
                <div class="grid grid-cols-2 gap-2">
                    <button v-for="c in companies" :key="c.id" type="button" @click="form.company_id = c.id"
                        class="rounded-xl border py-3 text-sm font-semibold transition-all"
                        :class="form.company_id === c.id ? 'border-emerald-500 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-500' : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300'">
                        {{ c.name }}
                    </button>
                </div>
            </div>

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

            <div class="auth-reveal" style="animation-delay: 330ms">
                <label for="password" class="mb-1.5 block text-sm font-semibold text-slate-700">Пароль</label>
                <div class="group relative">
                    <span class="auth-ico pointer-events-none absolute inset-y-0 left-0 flex w-11 items-center justify-center text-slate-400">
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 1 1 8 0v3"/></svg>
                    </span>
                    <input id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'" required autocomplete="current-password" placeholder="••••••••" class="auth-input py-3 pl-11 pr-11" />
                    <button type="button" @click="showPassword = !showPassword" class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-400 transition-colors hover:text-slate-600" tabindex="-1">
                        <svg v-if="!showPassword" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg v-else viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3l18 18"/><path d="M10.6 10.6a3 3 0 0 0 4.2 4.2"/><path d="M9.9 4.2A10.9 10.9 0 0 1 12 4c6.5 0 10 7 10 7a18 18 0 0 1-3.2 4.1M6.6 6.6A18 18 0 0 0 2 11s3.5 7 10 7a10.9 10.9 0 0 0 3.3-.5"/></svg>
                    </button>
                </div>
                <div v-if="form.errors.password" class="mt-1.5 text-xs font-medium text-rose-600">{{ form.errors.password }}</div>
            </div>

            <div class="auth-reveal" style="animation-delay: 410ms">
                <label class="flex cursor-pointer select-none items-center gap-2 text-sm text-slate-600">
                    <input v-model="form.remember" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
                    Запомнить меня
                </label>
            </div>

            <button type="submit" :disabled="form.processing" class="auth-reveal auth-btn w-full rounded-xl bg-gradient-to-r from-emerald-500 to-emerald-600 py-3.5 text-sm font-semibold text-white transition-all duration-200 hover:brightness-105 active:scale-[0.99] disabled:opacity-60" style="animation-delay: 490ms">
                <span v-if="!form.processing" class="relative z-10">Войти в систему</span>
                <span v-else class="relative z-10 inline-flex items-center gap-2">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" class="opacity-90"/></svg>
                    Вход…
                </span>
            </button>
        </form>

        <p class="auth-reveal mt-7 text-center text-xs text-slate-400" style="animation-delay: 560ms">
            Доступ к системе выдаёт администратор
        </p>
    </AuthSplitLayout>
</template>

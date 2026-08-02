<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ code: '' });
const submit = () => form.post(route('screen.enter'), { onError: () => form.reset('code') });
</script>

<template>
    <Head title="Экран цеха" />
    <div class="flex min-h-screen items-center justify-center bg-slate-50 px-4">
        <div class="w-full max-w-sm">
            <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
                <h1 class="text-2xl font-bold text-slate-900">Экран цеха</h1>
                <p class="mt-2 text-sm text-slate-500">Введите код экрана — его выдаёт администратор в Настройки → Экраны</p>
                <form @submit.prevent="submit" class="mt-6">
                    <input v-model="form.code" type="text" inputmode="numeric" autocomplete="one-time-code" placeholder="••••••"
                        class="w-full rounded-2xl border-slate-300 bg-slate-50 py-4 text-center text-3xl font-bold tracking-[0.5em] text-slate-900 placeholder-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" autofocus />
                    <div v-if="form.errors.code" class="mt-3 text-sm text-rose-600">{{ form.errors.code }}</div>
                    <button :disabled="form.processing || !form.code"
                        class="mt-4 w-full rounded-2xl bg-indigo-600 py-3.5 text-base font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-40">Открыть экран</button>
                </form>
            </div>
            <p class="mt-4 text-center text-xs text-slate-400">BAIA ERP · экран обновляется автоматически</p>
        </div>
    </div>
</template>

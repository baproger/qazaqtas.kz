<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({ settings: Object });
const form = useForm({
    company_name: props.settings.company_name,
    currency: props.settings.currency,
    auto_create_project: !!props.settings.auto_create_project,
    default_locale: props.settings.default_locale,
    tax_percent: props.settings.tax_percent,
});
const save = () => form.put(route('settings.update'), { preserveScroll: true });
</script>

<template>
    <Head title="Настройки" />
    <AppLayout>
        <template #header>{{ $t('page.settings', 'Настройки системы') }}</template>

        <div class="mb-4 flex gap-2 border-b">
            <Link :href="route('settings.index')" class="border-b-2 border-indigo-600 px-3 py-2 text-sm font-medium text-indigo-600">Общие</Link>
            <Link :href="route('stages.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">Этапы</Link>
            <Link :href="route('screens.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">Экраны</Link>
            <Link :href="route('custom-fields.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">Доп. поля</Link>
        </div>

        <div class="max-w-xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
            <div>
                <InputLabel value="Название компании" />
                <TextInput v-model="form.company_name" class="mt-1 w-full" />
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <InputLabel value="Валюта" />
                    <TextInput v-model="form.currency" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel value="Язык по умолчанию" />
                    <select v-model="form.default_locale" class="mt-1 w-full rounded-md border-slate-300 shadow-sm">
                        <option value="ru">Русский</option>
                        <option value="kk">Қазақша</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <InputLabel value="Налог, % с суммы сделок" />
                    <TextInput v-model="form.tax_percent" type="number" step="0.1" class="mt-1 w-full" />
                </div>
                <div class="rounded-lg bg-slate-50 p-3 text-xs text-slate-500 ring-1 ring-slate-200">
                    <div class="font-semibold text-slate-600">Бонус сотрудника — по марже сделки (фиксировано):</div>
                    до 10% — нет · 11–15% — 5% · 16–20% — 7% · 21–30% — 10% · 31–40% — 13% · от 41% — 15% от остатка
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" v-model="form.auto_create_project" class="rounded border-slate-300 text-indigo-600" />
                Автоматически создавать проект при выигрыше сделки
            </label>
            <div class="pt-2"><PrimaryButton :disabled="form.processing" @click="save">Сохранить</PrimaryButton></div>
        </div>
    </AppLayout>
</template>

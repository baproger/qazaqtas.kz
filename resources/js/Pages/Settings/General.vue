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
    configurator_enabled: !!props.settings.configurator_enabled,
    default_locale: props.settings.default_locale,
    tax_percent: props.settings.tax_percent,
    material_markup_percent: props.settings.material_markup_percent,
    bonus_sale_percent: props.settings.bonus_sale_percent,
    bonus_resale_percent: props.settings.bonus_resale_percent,
});
const save = () => form.put(route('settings.update'), { preserveScroll: true });
</script>

<template>
    <Head :title="$e('Настройки')" />
    <AppLayout>
        <template #header>{{ $t('page.settings', 'Настройки системы') }}</template>

        <div class="mb-4 flex gap-2 border-b">
            <Link :href="route('settings.index')" class="border-b-2 border-indigo-600 px-3 py-2 text-sm font-medium text-indigo-600">{{ $e('Общие') }}</Link>
            <Link :href="route('stages.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Этапы') }}</Link>
            <Link :href="route('screens.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Экраны') }}</Link>
            <Link :href="route('custom-fields.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Доп. поля') }}</Link>
            <Link :href="route('siteSettings.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Сайт') }}</Link>
        </div>

        <div class="max-w-xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
            <div>
                <InputLabel :value="$e('Название компании')" />
                <TextInput v-model="form.company_name" class="mt-1 w-full" />
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <InputLabel :value="$e('Валюта')" />
                    <TextInput v-model="form.currency" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel :value="$e('Язык по умолчанию')" />
                    <select v-model="form.default_locale" class="mt-1 w-full rounded-md border-slate-300 shadow-sm">
                        <option value="kk">{{ $e('Қазақша (KZ)') }}</option>
                        <option value="ru">{{ $e('Русский (RU)') }}</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <InputLabel :value="$e('Налог, % с суммы сделок')" />
                    <TextInput v-model="form.tax_percent" type="number" step="0.1" class="mt-1 w-full" />
                </div>
                <div class="rounded-lg bg-slate-50 p-3 text-xs text-slate-500 ring-1 ring-slate-200">
                    <div class="font-semibold text-slate-600">{{ $e('Бонус сотрудника — по марже сделки (фиксировано):') }}</div>
                    {{ $e('до 10% — нет · 11–15% — 5% · 16–20% — 7% · 21–30% — 10% · 31–40% — 13% · от 41% — 15% от остатка') }}
                </div>
            </div>
            <!-- Товар со склада: наценка и бонус менеджера от неё -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <InputLabel :value="$e('Наценка на товар со склада, %')" />
                    <TextInput v-model="form.material_markup_percent" type="number" step="0.01" min="0" class="mt-1 w-full" />
                    <p class="mt-1 text-xs text-slate-400">{{ $e('Общая: цена продажи = закуп + наценка. У позиции склада может быть своя.') }}</p>
                </div>
                <div>
                    <InputLabel :value="$e('Бонус менеджера: своё производство, %')" />
                    <TextInput v-model="form.bonus_sale_percent" type="number" step="0.01" min="0" class="mt-1 w-full" />
                    <p class="mt-1 text-xs text-slate-400">{{ $e('Процент от остатка сделки (сумма − налог − расходы − партнёр), пропорционально оплате клиента.') }}</p>
                </div>
                <div>
                    <InputLabel :value="$e('Бонус менеджера: перепродажа, %')" />
                    <TextInput v-model="form.bonus_resale_percent" type="number" step="0.01" min="0" class="mt-1 w-full" />
                    <p class="mt-1 text-xs text-slate-400">{{ $e('Купили → склад → продали: своя ставка, обычно выше производственной.') }}</p>
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" v-model="form.auto_create_project" class="rounded border-slate-300 text-indigo-600" />
                {{ $e('Автоматически создавать проект при переходе сделки в «Оплата успешно»') }}
            </label>

            <label class="flex items-start gap-2 text-sm text-slate-700">
                <input type="checkbox" v-model="form.configurator_enabled" class="mt-0.5 rounded border-slate-300 text-indigo-600" />
                <span>
                    {{ $e('Показывать 3D-конфигуратор двора на сайте') }}
                    <span class="block text-xs text-slate-400">
                        {{ $e('Пока выключен, пункт скрыт в меню, а страница отдаёт 404') }}
                    </span>
                </span>
            </label>
            <div class="pt-2"><PrimaryButton :disabled="form.processing" @click="save">{{ $e('Сохранить') }}</PrimaryButton></div>
        </div>
    </AppLayout>
</template>

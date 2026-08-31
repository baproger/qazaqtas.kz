<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import SettingSection from '@/Components/settings/SettingSection.vue';
import SettingRow from '@/Components/settings/SettingRow.vue';
import Toggle from '@/Components/settings/Toggle.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({ settings: Object });
const form = useForm({
    company_name: props.settings.company_name,
    currency: props.settings.currency,
    auto_create_project: !!props.settings.auto_create_project,
    default_locale: props.settings.default_locale,
    tax_percent: props.settings.tax_percent,
    material_markup_percent: props.settings.material_markup_percent,
    bonus_sale_percent: props.settings.bonus_sale_percent,
    bonus_resale_percent: props.settings.bonus_resale_percent,
    foreman_rate_m2: props.settings.foreman_rate_m2,
    foreman_rate_pcs: props.settings.foreman_rate_pcs,
    ui_font_size: props.settings.ui_font_size || 'normal',
});
const fontSizes = [
    { value: 'compact', label: tr('Компактный') },
    { value: 'normal', label: tr('Обычный') },
    { value: 'large', label: tr('Крупный') },
    { value: 'xlarge', label: tr('Очень крупный') },
];
const save = () => form.put(route('settings.update'), { preserveScroll: true });
</script>

<template>
    <Head :title="$e('Настройки')" />
    <SettingsLayout :title="$e('Общие')">
        <div class="space-y-6">
            <SettingSection :title="$e('Компания')">
                <SettingRow :title="$e('Название компании')">
                    <TextInput v-model="form.company_name" class="w-full" />
                </SettingRow>
                <SettingRow :title="$e('Валюта')">
                    <TextInput v-model="form.currency" class="w-full" />
                </SettingRow>
                <SettingRow :title="$e('Язык по умолчанию')" :description="$e('Для новых сотрудников; каждый может сменить язык у себя.')">
                    <select v-model="form.default_locale" class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
                        <option value="kk">{{ $e('Қазақша (KZ)') }}</option>
                        <option value="ru">{{ $e('Русский (RU)') }}</option>
                    </select>
                </SettingRow>
            </SettingSection>

            <SettingSection :title="$e('Финансы и бонусы')" :description="$e('отдел продаж — процент от остатка сделки, производство — деньги за сделанный объём.')">
                <SettingRow :title="$e('Налог, % с суммы сделок')">
                    <TextInput v-model="form.tax_percent" type="number" step="0.1" class="w-full" />
                </SettingRow>
                <SettingRow :title="$e('Наценка на товар со склада, %')" :description="$e('Общая: цена продажи = закуп + наценка. У позиции склада может быть своя.')">
                    <TextInput v-model="form.material_markup_percent" type="number" step="0.01" min="0" class="w-full" />
                </SettingRow>
                <SettingRow :title="$e('Бонус менеджера: своё производство, %')" :description="$e('Процент от остатка сделки (сумма − налог − расходы − партнёр), пропорционально оплате клиента.')">
                    <TextInput v-model="form.bonus_sale_percent" type="number" step="0.01" min="0" class="w-full" />
                </SettingRow>
                <SettingRow :title="$e('Бонус менеджера: перепродажа, %')" :description="$e('Купили → склад → продали: своя ставка, обычно выше производственной.')">
                    <TextInput v-model="form.bonus_resale_percent" type="number" step="0.01" min="0" class="w-full" />
                </SettingRow>
            </SettingSection>

            <SettingSection :title="$e('Производство')" :description="$e('Бонус смены начисляется бригадиру целиком. Кто из бригады сколько получит, решает он сам — система долей не считает.')">
                <SettingRow :title="$e('Бригадир: ₸ за м²')" :description="$e('Бригадиру платят за весь объём смены его бригады.')">
                    <TextInput v-model="form.foreman_rate_m2" type="number" step="1" min="0" class="w-full" />
                </SettingRow>
                <SettingRow :title="$e('Бригадир: ₸ за штуку')">
                    <TextInput v-model="form.foreman_rate_pcs" type="number" step="1" min="0" class="w-full" />
                </SettingRow>
            </SettingSection>

            <SettingSection :title="$e('Интерфейс')">
                <SettingRow :title="$e('Размер шрифта интерфейса')" :description="$e('Действует для всех сотрудников во всём ERP; сайт не меняется.')">
                    <div class="flex flex-wrap gap-2 sm:justify-end">
                        <button v-for="s in fontSizes" :key="s.value" type="button" @click="form.ui_font_size = s.value"
                            class="rounded-lg border px-3 py-1.5 text-sm font-medium transition"
                            :class="form.ui_font_size === s.value ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-300' : 'border-slate-200 dark:border-slate-800/80 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800/60'">
                            {{ s.label }}
                        </button>
                    </div>
                </SettingRow>
            </SettingSection>

            <SettingSection :title="$e('Автоматизация')">
                <SettingRow :title="$e('Автоматически создавать проект при переходе сделки в «Оплата успешно»')">
                    <Toggle v-model="form.auto_create_project" />
                </SettingRow>
            </SettingSection>

            <div class="flex justify-end">
                <PrimaryButton :disabled="form.processing" @click="save">{{ $e('Сохранить') }}</PrimaryButton>
            </div>
        </div>
    </SettingsLayout>
</template>

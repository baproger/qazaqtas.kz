<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    site: Object,
    locales: { type: Array, default: () => [] },
});

/**
 * Язык, который сейчас правится в списках.
 *
 * Основной язык живёт в самом поле (`city`), остальные — в поле с суффиксом
 * (`city_ru`). Так цифры тарифов, годы и координаты не дублируются: они
 * общие для всех языков и разъезжались бы при первой же правке одной из копий.
 */
const contentLocale = ref(props.locales.find((l) => l.is_default)?.code ?? 'kk');
const defaultLocale = props.locales.find((l) => l.is_default)?.code ?? 'kk';

/** Имя поля строки под текущий язык. */
const at = (field) => (contentLocale.value === defaultLocale ? field : `${field}_${contentLocale.value}`);

/** Что покажет витрина, пока перевод не заведён. */
const fallback = (row, field) => (contentLocale.value === defaultLocale ? '' : row[field] ?? '');

const form = useForm({
    hero: props.site.hero ?? 'scene3d',
    phone: props.site.contacts.phone ?? '',
    whatsapp: props.site.contacts.whatsapp ?? '',
    email: props.site.contacts.email ?? '',
    hours: props.site.hours?.base ?? '',
    hours_kk: props.site.hours?.kk ?? '',
    hours_ru: props.site.hours?.ru ?? '',
    instagram: props.site.contacts.instagram ?? '',
    // Строки приходят с сервера как есть, вместе с языковыми суффиксами:
    // форма правит оба языка, а не то, что уже подставила витрина.
    branches: (props.site.branches ?? []).map((b) => ({ ...b })),
    delivery: (props.site.delivery ?? []).map((d) => ({ ...d })),
    faq: (props.site.faq ?? []).map((f) => ({ ...f })),
});

const heroOptions = [
    {
        value: 'scene3d',
        title: tr('3D-сборка двора'),
        text: tr('Двор собирается по мере прокрутки: плитка, бордюр, малые формы. Показывает технологию.'),
    },
    {
        value: 'showcase',
        title: tr('Витрина изделий'),
        text: tr('Слайдер по товарам: рендер, цена, характеристики и кнопка «В корзину». Продаёт напрямую.'),
    },
];

const addRow = (list, row) => form[list].push(row);
const removeRow = (list, i) => form[list].splice(i, 1);

const submit = () => form.put(route('siteSettings.update'), { preserveScroll: true });
</script>

<template>
    <AppLayout>
        <template #header>{{ $e('Настройки · Сайт') }}</template>

        <div class="mb-4 flex gap-2 border-b">
            <Link :href="route('settings.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Общие') }}</Link>
            <Link :href="route('stages.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Этапы') }}</Link>
            <Link :href="route('screens.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Экраны') }}</Link>
            <Link :href="route('custom-fields.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">{{ $e('Доп. поля') }}</Link>
            <Link :href="route('siteSettings.index')" class="border-b-2 border-indigo-600 px-3 py-2 text-sm font-medium text-indigo-600">{{ $e('Сайт') }}</Link>
        </div>

        <div class="space-y-4">
            <!-- Первый экран -->
            <section class="rounded-xl border border-slate-100 bg-white p-6 shadow-sm">
                <h3 class="text-sm font-semibold text-slate-900">{{ $e('Первый экран сайта') }}</h3>
                <p class="mt-1 text-xs text-slate-400">{{ $e('Что видит посетитель до прокрутки. Переключается мгновенно.') }}</p>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <label
                        v-for="option in heroOptions"
                        :key="option.value"
                        class="flex cursor-pointer gap-3 rounded-xl border p-4 transition"
                        :class="form.hero === option.value ? 'border-indigo-500 bg-indigo-50/60' : 'border-slate-200 hover:border-slate-300'"
                    >
                        <input v-model="form.hero" type="radio" :value="option.value" class="mt-0.5 text-indigo-600 focus:ring-indigo-500" />
                        <span>
                            <span class="block text-sm font-medium text-slate-900">{{ option.title }}</span>
                            <span class="mt-1 block text-xs leading-relaxed text-slate-500">{{ option.text }}</span>
                        </span>
                    </label>
                </div>

                <p v-if="form.hero === 'showcase' && !site.heroSlidesCount" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">
                    {{ $e('Ни у одного товара нет фото — витрине нечего показывать, поэтому сайт останется на 3D-сцене. Загрузите снимки в «Каталог сайта».') }}
                </p>
                <InputError :message="form.errors.hero" class="mt-1" />
            </section>

            <!-- Контакты -->
            <section class="rounded-xl border border-slate-100 bg-white p-6 shadow-sm">
                <h3 class="text-sm font-semibold text-slate-900">{{ $e('Контакты') }}</h3>
                <p class="mt-1 text-xs text-slate-400">{{ $e('Показываются в шапке, подвале и на странице «Контакты».') }}</p>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div>
                        <InputLabel :value="$e('Телефон call-центра *')" />
                        <TextInput v-model="form.phone" class="mt-1 w-full" placeholder="+7 707 372 22 22" />
                        <InputError :message="form.errors.phone" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="WhatsApp *" />
                        <TextInput v-model="form.whatsapp" class="mt-1 w-full" placeholder="+7 771 610 77 70" />
                        <p class="mt-1 text-[11px] text-slate-400">{{ $e('В ссылку wa.me уходят только цифры.') }}</p>
                        <InputError :message="form.errors.whatsapp" class="mt-1" />
                    </div>
                    <div><InputLabel value="Email" /><TextInput v-model="form.email" class="mt-1 w-full" /><InputError :message="form.errors.email" class="mt-1" /></div>
                    <div>
                        <InputLabel :value="$e('Часы работы')" />
                        <TextInput v-model="form.hours" class="mt-1 w-full" :placeholder="$e('Пн–Сб, 09:00–18:00')" />
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <TextInput v-model="form.hours_kk" :placeholder="$e('KZ — Дс–Сб, 09:00–18:00')" />
                            <TextInput v-model="form.hours_ru" :placeholder="$e('RU — как в поле выше')" />
                        </div>
                    </div>
                    <div class="sm:col-span-2"><InputLabel value="Instagram" /><TextInput v-model="form.instagram" class="mt-1 w-full" /></div>
                </div>
            </section>

            <!-- Язык списков ниже: площадки, тарифы, вопросы -->
            <div class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-100 bg-white px-5 py-3 shadow-sm">
                <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $e('Язык списков') }}</span>
                <div class="flex items-center rounded-lg bg-slate-100 p-0.5 text-xs">
                    <button
                        v-for="l in locales"
                        :key="l.code"
                        type="button"
                        class="rounded px-3 py-1 font-medium transition"
                        :class="contentLocale === l.code ? 'bg-white text-indigo-600 shadow' : 'text-slate-500'"
                        @click="contentLocale = l.code"
                    >{{ l.short }} <span class="font-normal text-slate-400">{{ l.name }}</span></button>
                </div>
                <p class="text-xs text-slate-400">
                    <template v-if="contentLocale === defaultLocale">
                        {{ $e('Основной язык. Цифры тарифов, телефоны и координаты общие для всех языков.') }}
                    </template>
                    <template v-else>
                        {{ $e('Пустое поле = как на основном языке. Наведите на поле, чтобы увидеть исходный текст.') }}
                    </template>
                </p>
            </div>

            <!-- Филиалы -->
            <section class="rounded-xl border border-slate-100 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">{{ $e('Производственные площадки') }}</h3>
                        <p class="mt-1 text-xs text-slate-400">{{ $e('Выводятся в подвале и на «Контактах».') }}</p>
                    </div>
                    <button class="text-xs font-semibold text-indigo-600 hover:underline" @click="addRow('branches', { city: '', role: '', address: '', phone: '' })">{{ $e('+ Площадка') }}</button>
                </div>

                <div v-for="(b, i) in form.branches" :key="i" class="mt-3 grid gap-2 sm:grid-cols-[1fr_1fr_2fr_1fr_auto]">
                    <TextInput v-model="b[at('city')]" :placeholder="$e('Город')" :title="fallback(b, 'city')" />
                    <TextInput v-model="b[at('role')]" :placeholder="$e('Роль (производство)')" :title="fallback(b, 'role')" />
                    <TextInput v-model="b[at('address')]" :placeholder="$e('Адрес')" :title="fallback(b, 'address')" />
                    <TextInput v-model="b.phone" :placeholder="$e('Телефон')" />
                    <button class="rounded p-2 text-slate-300 hover:text-rose-600" @click="removeRow('branches', i)">✕</button>
                </div>
            </section>

            <!-- Доставка -->
            <section class="rounded-xl border border-slate-100 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">{{ $e('Тарифы доставки') }}</h3>
                        <p class="mt-1 text-xs text-slate-400">{{ $e('Считают прикидку в корзине; менеджер подтверждает точную сумму.') }}</p>
                    </div>
                    <button class="text-xs font-semibold text-indigo-600 hover:underline" @click="addRow('delivery', { city: '', base: 0, per_km: 0, free_from: 0 })">{{ $e('+ Тариф') }}</button>
                </div>

                <div class="mt-3 hidden gap-2 px-1 text-[11px] uppercase tracking-wide text-slate-400 sm:grid sm:grid-cols-[2fr_1fr_1fr_1fr_auto]">
                    <span>{{ $e('Город') }}</span><span>{{ $e('База, ₸') }}</span><span>{{ $e('За км, ₸') }}</span><span>{{ $e('Бесплатно от, ₸') }}</span><span></span>
                </div>
                <div v-for="(d, i) in form.delivery" :key="i" class="mt-2 grid gap-2 sm:grid-cols-[2fr_1fr_1fr_1fr_auto]">
                    <TextInput v-model="d[at('city')]" :placeholder="$e('Город')" :title="fallback(d, 'city')" />
                    <TextInput v-model="d.base" type="number" min="0" />
                    <TextInput v-model="d.per_km" type="number" min="0" />
                    <TextInput v-model="d.free_from" type="number" min="0" />
                    <button class="rounded p-2 text-slate-300 hover:text-rose-600" @click="removeRow('delivery', i)">✕</button>
                </div>
            </section>

            <!-- FAQ -->
            <section class="rounded-xl border border-slate-100 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">{{ $e('Частые вопросы') }}</h3>
                        <p class="mt-1 text-xs text-slate-400">{{ $e('Блок на странице «Контакты».') }}</p>
                    </div>
                    <button class="text-xs font-semibold text-indigo-600 hover:underline" @click="addRow('faq', { q: '', a: '' })">{{ $e('+ Вопрос') }}</button>
                </div>

                <div v-for="(f, i) in form.faq" :key="i" class="mt-3 rounded-lg border border-slate-100 p-3">
                    <div class="flex gap-2">
                        <TextInput v-model="f[at('q')]" class="flex-1" :placeholder="$e('Вопрос')" :title="fallback(f, 'q')" />
                        <button class="rounded p-2 text-slate-300 hover:text-rose-600" @click="removeRow('faq', i)">✕</button>
                    </div>
                    <textarea v-model="f[at('a')]" rows="2" class="mt-2 w-full rounded-lg border-slate-300 text-sm shadow-sm" :placeholder="$e('Ответ')"></textarea>
                </div>
            </section>

            <div class="flex items-center gap-3">
                <PrimaryButton :disabled="form.processing" @click="submit">{{ $e('Сохранить') }}</PrimaryButton>
                <a :href="route('site.contacts')" target="_blank" class="text-xs font-medium text-indigo-600 hover:underline">{{ $e('Открыть «Контакты» на сайте ↗') }}</a>
            </div>
        </div>
    </AppLayout>
</template>

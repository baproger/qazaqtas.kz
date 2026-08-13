<script setup>
import { computed, ref, watch } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { FIELD_CODECS } from '@/utils/catalog';

/**
 * Вкладки языков для карточек каталога, разделов и объектов сайта.
 *
 * Что здесь важно: пустое поле — это НЕ пустой текст на сайте, а «как в
 * карточке». Поэтому подсказкой в каждом поле стоит базовое значение: видно,
 * что покажет витрина, пока перевод не заведён, и не возникает соблазна
 * скопировать текст сам в себя.
 *
 * Поля, которые в карточке заполняются построчно (характеристики, палитра),
 * разбираются теми же функциями, что и основная форма — см. utils/catalog.
 */
const props = defineProps({
    /** [{ code, name, short, is_default }] — приходит с сервера. */
    locales: { type: Array, default: () => [] },
    /** Значения базовых полей карточки: показываются подсказкой. */
    base: { type: Object, default: () => ({}) },
    /** [{ key, label, type: 'text'|'textarea'|'map'|'colors'|'pairs', hint, rows }] */
    fields: { type: Array, required: true },
});

/** { kk: { name: '…' }, ru: { … } } */
const model = defineModel({ type: Object, default: () => ({}) });

const active = ref(props.locales[0]?.code ?? 'kk');

watch(() => props.locales, (list) => {
    if (!list.some((l) => l.code === active.value)) active.value = list[0]?.code ?? 'kk';
});

/**
 * Построчные поля держим отдельным текстом на каждый язык: разбирать и
 * собирать строку на каждое нажатие клавиши значило бы переставлять курсор
 * пользователю под руками.
 */
const drafts = ref({});

const draftKey = (locale, field) => `${locale}.${field}`;

const syncDrafts = () => {
    for (const locale of props.locales) {
        for (const field of props.fields) {
            const codec = FIELD_CODECS[field.type];
            if (!codec) continue;

            const key = draftKey(locale.code, field.key);
            drafts.value[key] = codec.format(model.value?.[locale.code]?.[field.key]);
        }
    }
};

// Только при первом показе и смене набора языков. Следить за model нельзя:
// каждое нажатие клавиши пересобирало бы текст из разобранного значения и
// съедало недописанную строку. Чтобы вкладки взяли другую карточку, форма
// монтирует компонент заново — :key="editingId".
watch(() => props.locales, syncDrafts, { immediate: true });

const valueOf = (locale, field) => model.value?.[locale]?.[field] ?? '';

const setValue = (locale, field, value) => {
    model.value = {
        ...model.value,
        [locale]: { ...(model.value?.[locale] ?? {}), [field]: value },
    };
};

const setDraft = (locale, field, text) => {
    drafts.value[draftKey(locale, field.key)] = text;
    setValue(locale, field.key, FIELD_CODECS[field.type].parse(text));
};

/** Что покажет витрина, пока перевод не заведён. */
const placeholderFor = (field) => {
    const codec = FIELD_CODECS[field.type];
    const raw = props.base?.[field.key];

    return codec ? codec.format(raw) : (raw ?? '');
};

const filledCount = (locale) => props.fields.filter((f) => {
    const value = valueOf(locale, f.key);

    return Array.isArray(value) ? value.length > 0
        : typeof value === 'object' && value !== null ? Object.keys(value).length > 0
            : String(value ?? '').trim() !== '';
}).length;
</script>

<template>
    <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
        <div class="flex flex-wrap items-center gap-2">
            <button
                v-for="locale in locales"
                :key="locale.code"
                type="button"
                class="rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                :class="active === locale.code
                    ? 'bg-white text-indigo-600 shadow-sm ring-1 ring-slate-200'
                    : 'text-slate-500 hover:text-slate-700'"
                @click="active = locale.code"
            >
                {{ locale.short }}
                <span class="ml-1 font-normal text-slate-400">{{ locale.name }}</span>
                <span
                    v-if="filledCount(locale.code)"
                    class="ml-1.5 rounded bg-emerald-100 px-1.5 text-[10px] font-semibold text-emerald-700"
                >{{ filledCount(locale.code) }}</span>
            </button>

            <p class="ml-auto text-xs text-slate-400">
                {{ $e('Пустое поле = как в карточке') }}
            </p>
        </div>

        <div v-for="locale in locales" v-show="active === locale.code" :key="locale.code" class="mt-4 space-y-4">
            <div v-for="field in fields" :key="field.key">
                <InputLabel :value="field.label" />
                <p v-if="field.hint" class="mt-0.5 text-xs text-slate-400">{{ field.hint }}</p>

                <TextInput
                    v-if="field.type === 'text'"
                    :model-value="valueOf(locale.code, field.key)"
                    :placeholder="placeholderFor(field)"
                    class="mt-1 w-full"
                    @update:model-value="setValue(locale.code, field.key, $event)"
                />

                <textarea
                    v-else-if="field.type === 'textarea'"
                    :value="valueOf(locale.code, field.key)"
                    :placeholder="placeholderFor(field)"
                    :rows="field.rows ?? 3"
                    class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm"
                    @input="setValue(locale.code, field.key, $event.target.value)"
                />

                <textarea
                    v-else
                    :value="drafts[`${locale.code}.${field.key}`] ?? ''"
                    :placeholder="placeholderFor(field)"
                    :rows="field.rows ?? 4"
                    class="mt-1 w-full rounded-lg border-slate-300 font-mono text-xs shadow-sm"
                    @input="setDraft(locale.code, field, $event.target.value)"
                />
            </div>
        </div>
    </div>
</template>

<script setup>
/**
 * Вкладка SEO карточки товара: title / description / keywords на ru и kk.
 *
 * «Сгенерировать» заполняет оба языка разом: Claude при заданном ключе
 * ANTHROPIC_API_KEY, иначе — шаблон из данных карточки. Поля можно править
 * руками; сохранение — отдельным запросом, чтобы не мешать основной форме.
 */
import { onMounted, reactive, ref } from 'vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();
const props = defineProps({ product: { type: Object, required: true } });

const form = reactive({ title: '', description: '', keywords: '', title_kk: '', description_kk: '', keywords_kk: '' });
const busy = ref(false);
const saving = ref(false);
const note = ref('');
const error = ref('');

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('catalog.seo', props.product.id));
        Object.assign(form, Object.fromEntries(Object.entries(data ?? {}).map(([k, v]) => [k, v ?? ''])));
    } catch { /* нет сохранённого SEO — поля просто пустые */ }
});

const generate = async () => {
    busy.value = true; error.value = ''; note.value = '';
    try {
        const { data } = await window.axios.post(route('catalog.seo.generate', props.product.id));
        Object.assign(form, {
            title: data.ru.title, description: data.ru.description, keywords: data.ru.keywords,
            title_kk: data.kk.title, description_kk: data.kk.description, keywords_kk: data.kk.keywords,
        });
        note.value = data.source === 'ai' ? tr('Сгенерировано ИИ — проверьте и сохраните.') : tr('Собрано по шаблону (ключ ИИ не задан) — проверьте и сохраните.');
    } catch {
        error.value = tr('Не удалось сгенерировать. Попробуйте ещё раз.');
    } finally {
        busy.value = false;
    }
};

const save = async () => {
    saving.value = true; error.value = '';
    try {
        await window.axios.post(route('catalog.seo.save', props.product.id), form);
        note.value = tr('SEO сохранено.');
    } catch {
        error.value = tr('Не удалось сохранить.');
    } finally {
        saving.value = false;
    }
};

const field = 'mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400';
const counter = (v, max) => `${(v ?? '').length}/${max}`;
const over = (v, max) => (v ?? '').length > max;
const COLS = [
    ['', tr('Русский')],
    ['_kk', 'Қазақша'],
];
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <button type="button" :disabled="busy" @click="generate"
                class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-indigo-700 active:scale-[0.97] disabled:opacity-50">
                <span v-if="!busy">✨ {{ $e('Сгенерировать (ИИ)') }}</span>
                <span v-else>{{ $e('Генерируем…') }}</span>
            </button>
            <button type="button" :disabled="saving" @click="save"
                class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-slate-700 active:scale-[0.97] disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900">
                {{ $e('Сохранить SEO') }}
            </button>
            <span v-if="note" class="text-xs text-emerald-600 dark:text-emerald-400">{{ note }}</span>
            <span v-if="error" class="text-xs text-rose-600 dark:text-rose-400">{{ error }}</span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div v-for="[sfx, label] in COLS" :key="sfx" class="space-y-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ label }}</p>
                <div>
                    <div class="flex items-center justify-between text-xs text-slate-400">
                        <span>Title</span>
                        <span :class="over(form['title' + sfx], 70) ? 'font-semibold text-rose-500' : ''">{{ counter(form['title' + sfx], 70) }}</span>
                    </div>
                    <input v-model="form['title' + sfx]" type="text" :class="field" />
                </div>
                <div>
                    <div class="flex items-center justify-between text-xs text-slate-400">
                        <span>Description</span>
                        <span :class="over(form['description' + sfx], 160) ? 'font-semibold text-rose-500' : ''">{{ counter(form['description' + sfx], 160) }}</span>
                    </div>
                    <textarea v-model="form['description' + sfx]" rows="3" :class="field" />
                </div>
                <div>
                    <div class="text-xs text-slate-400">Keywords <span class="text-slate-300 dark:text-slate-600">· {{ $e('через запятую') }}</span></div>
                    <input v-model="form['keywords' + sfx]" type="text" :class="field" />
                </div>
            </div>
        </div>

        <p class="mt-4 text-xs leading-relaxed text-slate-400">
            {{ $e('Title до 70 символов, description до 160 — так сниппет не режется в выдаче. Пустые поля добирает автогенерация витрины.') }}
        </p>
    </div>
</template>

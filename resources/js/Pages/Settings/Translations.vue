<script setup>
import { ref, watch } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

/**
 * Настройки → Переводы.
 *
 * Показывает весь словарь, а не только строки из базы: слева текст из
 * поставки, справа — правка владельца. Пустое поле правки означает «как в
 * поставке», поэтому поставочный текст стоит подсказкой прямо в поле: видно,
 * что покажет система, и незачем копировать текст сам в себя.
 */
const props = defineProps({
    groups: { type: Array, default: () => [] },
    locales: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    total: { type: Number, default: 0 },
    limit: { type: Number, default: 150 },
});

const group = ref(props.filters.group ?? 'site');
const search = ref(props.filters.search ?? '');

let timer = null;

const reload = () => router.get(route('translations.index'), {
    group: group.value,
    search: search.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });

watch(group, reload);
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(reload, 350);
});

// Правки держим отдельной копией: строка из props перерисовывается при
// каждой перезагрузке списка и затирала бы недописанное.
const edits = ref({});

watch(() => props.items, (rows) => {
    edits.value = Object.fromEntries(rows.map((r) => [r.key, { ...r.override }]));
}, { immediate: true });

const saveForm = useForm({ items: [] });

const save = () => {
    saveForm.items = props.items.map((row) => ({
        key: row.key,
        group: row.group,
        ...edits.value[row.key],
    }));
    saveForm.put(route('translations.update'), { preserveScroll: true, preserveState: true });
};

/** Правка снята — строка вернётся к тексту из поставки. */
const reset = (row) => {
    for (const locale of props.locales) edits.value[row.key][locale.code] = '';
};

const isOverridden = (row) => props.locales.some((l) => (edits.value[row.key]?.[l.code] ?? '') !== '');
</script>

<template>
    <Head :title="$e('Переводы')" />
    <SettingsLayout :title="$e('Переводы')" wide>

        <div class="mx-auto max-w-6xl space-y-5">
            <p class="text-sm text-slate-500">
                {{ $e('Слева — текст из поставки, справа — ваша правка. Пустое поле правки означает «оставить как есть».') }}
            </p>

            <!-- Разделы словаря -->
            <div class="flex flex-wrap items-center gap-2">
                <button
                    v-for="g in groups"
                    :key="g.code"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                    :class="group === g.code
                        ? 'bg-indigo-600 text-white shadow-sm'
                        : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'"
                    @click="group = g.code"
                >
                    {{ g.label }}
                    <span class="ml-1 text-xs opacity-70">{{ g.count }}</span>
                </button>

                <input
                    v-model="search"
                    type="search"
                    :placeholder="$e('Поиск по ключу или тексту…')"
                    class="ml-auto w-72 rounded-lg border-slate-200 py-2 text-sm shadow-sm"
                />
            </div>

            <p v-if="total > limit" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">
                {{ $e('Показаны первые') }} {{ limit }} {{ $e('из') }} {{ total }} — {{ $e('уточните поиск, чтобы увидеть остальные.') }}
            </p>

            <!-- Строки словаря -->
            <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-4 py-2.5 w-1/3">{{ $e('Строка') }}</th>
                            <th v-for="l in locales" :key="l.code" class="px-4 py-2.5">{{ l.name }}</th>
                            <th class="px-4 py-2.5 w-10"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="row in items" :key="row.key" class="align-top hover:bg-slate-50/60">
                            <td class="px-4 py-2.5">
                                <p class="break-words text-xs text-slate-600">{{ row.shipped[locales[0].code] || row.name }}</p>
                                <p class="mt-0.5 break-all font-mono text-xs text-slate-300">{{ row.name }}</p>
                            </td>

                            <td v-for="l in locales" :key="l.code" class="px-4 py-2.5">
                                <textarea
                                    v-model="edits[row.key][l.code]"
                                    rows="1"
                                    :placeholder="row.shipped[l.code]"
                                    class="w-full rounded-md border-slate-200 py-1.5 text-sm focus:border-indigo-400 focus:ring-indigo-400"
                                />
                            </td>

                            <td class="px-4 py-2.5 text-right">
                                <button
                                    v-if="isOverridden(row)"
                                    class="text-slate-300 transition-colors hover:text-rose-500"
                                    :title="$e('Снять правку — вернуть текст из поставки')"
                                    @click="reset(row)"
                                >✕</button>
                            </td>
                        </tr>

                        <tr v-if="!items.length">
                            <td :colspan="locales.length + 2" class="px-6 py-12 text-center text-slate-400">
                                {{ $e('Ничего не найдено') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="sticky bottom-4 flex items-center justify-end gap-3">
                <span v-if="saveForm.recentlySuccessful" class="text-sm text-emerald-600">{{ $e('✓ Сохранено') }}</span>
                <PrimaryButton :disabled="saveForm.processing" @click="save">{{ $e('Сохранить переводы') }}</PrimaryButton>
            </div>
        </div>
    </SettingsLayout>
</template>

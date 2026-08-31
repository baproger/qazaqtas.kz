<script setup>
/**
 * Узел дерева отделов — рекурсивный: отдел рисует своих подчинённых собой же.
 *
 * Обработчики берём через inject, а не прокидываем пропсами через каждый
 * уровень: на пяти уровнях вложенности это пять одинаковых строк ради одного
 * клика. Данные при этом остаются пропсами — узел должен быть предсказуем.
 */
import { computed, inject } from 'vue';
import Avatar from '@/Components/Avatar.vue';

const props = defineProps({
    node: { type: Object, required: true },
    depth: { type: Number, default: 0 },
    // Последний в ветке: вертикаль обрывается на уголке, а не тянется вниз
    // мимо пустоты — иначе линия обещает продолжение, которого нет.
    isLast: { type: Boolean, default: false },
});

const ctx = inject('structure');

const isCollapsed = computed(() => ctx.collapsed.value.has(props.node.id));
const isSelected = computed(() => ctx.selected.value?.id === props.node.id);

// Всего людей вместе с подчинёнными отделами: карточка отвечает «за скольких
// он отвечает», а не «сколько сидит в этой строке».
const total = computed(() => {
    const count = (n) => n.people.length + n.children.reduce((s, c) => s + count(c), 0);
    return count(props.node);
});
</script>

<template>
    <div>
        <div class="relative flex items-stretch gap-2">
            <!-- Связь с родителем: вертикаль от него и горизонталь к карточке.
                 Глубину показываем линией, а не отступом — на четвёртом уровне
                 отступ уже не читается, а линия ведёт глаз к владельцу узла. -->
            <div v-if="depth" class="relative flex-shrink-0" :style="{ width: depth * 22 + 'px' }" aria-hidden="true">
                <span class="absolute right-0 top-0 w-px bg-slate-200 dark:bg-slate-700" :class="isLast ? 'h-[26px]' : 'h-full'"></span>
                <span class="absolute right-0 top-[26px] h-px w-3 bg-slate-200 dark:bg-slate-700"></span>
            </div>

            <div class="min-w-0 flex-1 rounded-xl border bg-white shadow-sm transition-colors duration-150 dark:bg-slate-900"
                :class="isSelected ? 'border-indigo-400 ring-1 ring-indigo-200' : 'border-slate-200/60 hover:border-indigo-200 dark:border-slate-800/80'">
                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5 px-3 py-2">
                    <button v-if="node.children.length" type="button" @click="ctx.toggle(node.id)"
                        class="rounded p-0.5 text-slate-300 transition-colors duration-150 hover:text-slate-600 dark:text-slate-600 dark:hover:text-slate-300" :title="$e('Свернуть')">
                        <svg class="h-3.5 w-3.5 transition-transform duration-200" :class="isCollapsed ? '' : 'rotate-90'"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                    </button>
                    <span v-else class="w-4" aria-hidden="true"></span>

                    <!-- Руководитель — отдельной строкой под названием, а не
                         в общей куче: отдел без руководителя это дыра, в
                         которую проваливаются уведомления о просрочках. -->
                    <button type="button" @click="ctx.pick(node)" class="min-w-0 flex-1 text-left">
                        <div class="truncate text-xs font-semibold leading-tight text-slate-900 dark:text-slate-100">{{ node.name }}</div>
                        <div v-if="node.head" class="mt-0.5 flex items-center gap-1.5">
                            <Avatar :name="node.head.name" :src="node.head.avatar" :size="16" />
                            <span class="truncate text-xs text-slate-500 dark:text-slate-400">{{ node.head.name }}</span>
                        </div>
                        <div v-else class="mt-0.5 text-xs text-amber-600 dark:text-amber-400">{{ $e('руководитель не назначен') }}</div>
                    </button>

                    <!-- Лица отдела: без них карточка — просто слово. -->
                    <!-- Стопка, а не список имён: отдел на десять человек
                         иначе растягивал карточку в столбец. Клик открывает
                         состав, там же людей добавляют и убирают. -->
                    <button v-if="node.people.length" type="button" @click="ctx.canManage && ctx.addPerson(node)"
                        :title="node.people.map((p) => p.name).join(', ')"
                        class="flex items-center transition-opacity duration-150 hover:opacity-80">
                        <span class="flex -space-x-1.5">
                            <Avatar v-for="p in node.people.slice(0, 4)" :key="p.id" :name="p.name" :src="p.avatar" :size="22"
                                class="ring-2 ring-white dark:ring-slate-700" />
                        </span>
                        <span v-if="node.people.length > 4" class="ml-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400">+{{ node.people.length - 4 }}</span>
                    </button>

                    <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-xs font-semibold tabular-nums text-slate-500 dark:bg-slate-800/60 dark:text-slate-400"
                        :title="$e('в отделе и подчинённых')">{{ total }}</span>

                    <!-- Действия иконками: на десяти отделах подписи «＋
                         сотрудник / ＋ отдел» повторяли одно и то же в каждой
                         карточке. Что делает кнопка, говорит подсказка. -->
                    <template v-if="ctx.canManage">
                        <button type="button" @click="ctx.addPerson(node)" :title="$e('Добавить сотрудника')"
                            class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full border border-indigo-200 text-indigo-600 transition-colors duration-150 hover:border-indigo-400 hover:bg-indigo-50 dark:border-indigo-500/40 dark:text-indigo-400 dark:hover:bg-indigo-500/10">
                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>
                        </button>
                        <button type="button" @click="ctx.openCreate(node.id)" :title="$e('Добавить подотдел')"
                            class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full border border-slate-200 text-slate-400 transition-colors duration-150 hover:border-indigo-400 hover:text-indigo-700 dark:border-slate-800/80 dark:hover:text-indigo-300">
                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        </button>
                    </template>
                </div>
            </div>
        </div>

        <div v-if="node.children.length && !isCollapsed" class="mt-2 space-y-2">
            <StructureNode v-for="(child, i) in node.children" :key="child.id" :node="child"
                :depth="depth + 1" :is-last="i === node.children.length - 1" />
        </div>
    </div>
</template>

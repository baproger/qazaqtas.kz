<script setup>
/**
 * ИИ-помощник руководителя: вопрос → ответ со знанием цифр системы.
 *
 * Ответ модели показываем БЕЗ v-html: текст разбирается на строки и куски
 * **жирного** и рисуется обычным шаблоном. Разметка получается живой, а
 * вставить в ERP чужой скрипт через ответ модели физически нельзя.
 */
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    conversations: { type: Array, default: () => [] },
    conversation: { type: Object, default: null },
    configured: { type: Boolean, default: false },
    usedToday: { type: Number, default: 0 },
    dailyLimit: { type: Number, default: 0 },
});

const message = ref('');
const sending = ref(false);
const error = ref('');
const feed = ref(null);

const messages = computed(() => props.conversation?.messages ?? []);

/** Примеры вопросов для пустого экрана — по ним видно, что помощник умеет. */
const examples = [
    tr('Что по просроченным сделкам?'),
    tr('Покажи остатки по складу'),
    tr('Сколько заказов в цехе и где они стоят?'),
    tr('Сравни поступления денег с прошлым месяцем'),
];

/**
 * Разбор ответа: строки → абзацы и пункты списка, внутри — **жирное**.
 * Никакого HTML из текста модели не строим (см. комментарий вверху).
 */
const parse = (text) => String(text ?? '').split('\n').map((line) => {
    const trimmed = line.trim();
    const bullet = /^[-•*]\s+/.test(trimmed);
    const body = bullet ? trimmed.replace(/^[-•*]\s+/, '') : trimmed;

    return {
        bullet,
        empty: body === '',
        parts: body.split(/\*\*(.+?)\*\*/g).map((chunk, i) => ({ text: chunk, bold: i % 2 === 1 })),
    };
});

const scrollDown = () => nextTick(() => {
    if (feed.value) feed.value.scrollTop = feed.value.scrollHeight;
});
watch(() => props.conversation?.id, scrollDown, { immediate: true });
watch(() => messages.value.length, scrollDown);

const send = (text) => {
    const body = (text ?? message.value).trim();
    if (!body || sending.value) return;

    error.value = '';
    sending.value = true;
    router.post(route('ai.send'), { message: body, conversation_id: props.conversation?.id ?? null }, {
        preserveScroll: true,
        onSuccess: () => { message.value = ''; },
        onError: (errors) => { error.value = errors.message || tr('Не удалось отправить вопрос.'); },
        onFinish: () => { sending.value = false; scrollDown(); },
    });
};

/** Enter отправляет, Shift+Enter переносит строку — как в любом мессенджере. */
const onKeydown = (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        send();
    }
};

const remove = (id) => router.delete(route('ai.destroy', id), { preserveScroll: true });
</script>

<template>
    <Head :title="$e('ИИ-помощник')" />
    <AppLayout>
        <template #header>{{ $e('ИИ-помощник') }}</template>

        <div class="grid gap-4 lg:grid-cols-[260px_1fr]">
            <!-- Список диалогов -->
            <aside class="flex max-h-[75vh] flex-col rounded-xl border border-slate-200 bg-white dark:border-slate-800/80 dark:bg-slate-900/70">
                <div class="border-b border-slate-100 p-3 dark:border-slate-800/80">
                    <Link :href="route('ai.index')"
                        class="flex w-full items-center justify-center gap-1 rounded-lg border border-dashed border-indigo-300 py-2 text-xs font-medium text-indigo-600 transition-colors hover:border-indigo-400 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-500/10">
                        {{ $e('+ Новый диалог') }}
                    </Link>
                </div>
                <div class="flex-1 space-y-1 overflow-y-auto p-2">
                    <div v-for="c in conversations" :key="c.id" class="group relative">
                        <Link :href="route('ai.show', c.id)"
                            class="block truncate rounded-lg px-3 py-2 pr-8 text-sm transition-colors"
                            :class="conversation?.id === c.id
                                ? 'bg-indigo-50 font-medium text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300'
                                : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800/60'">
                            {{ c.title }}
                        </Link>
                        <button class="absolute right-2 top-1/2 hidden -translate-y-1/2 text-slate-300 transition-colors hover:text-rose-500 group-hover:block"
                            :title="$e('Удалить диалог')" @click="remove(c.id)">✕</button>
                    </div>
                    <p v-if="!conversations.length" class="px-3 py-6 text-center text-xs text-slate-400">{{ $e('Диалогов пока нет') }}</p>
                </div>
                <div v-if="dailyLimit > 0" class="border-t border-slate-100 px-3 py-2 text-xs text-slate-400 dark:border-slate-800/80">
                    {{ $e('Вопросов сегодня') }}: <b class="tabular-nums text-slate-600 dark:text-slate-300">{{ usedToday }}</b> / {{ dailyLimit }}
                </div>
            </aside>

            <!-- Диалог -->
            <section class="flex max-h-[75vh] flex-col rounded-xl border border-slate-200 bg-white dark:border-slate-800/80 dark:bg-slate-900/70">
                <!-- Без ключа помощник работает: отвечает готовыми выборками из базы. -->
                <div v-if="!configured" class="border-b border-slate-100 bg-slate-50 px-4 py-2 text-xs text-slate-500 dark:border-slate-800/80 dark:bg-slate-800/40 dark:text-slate-400">
                    {{ $e('Режим без ИИ: отвечаю готовыми данными из системы. Свободные вопросы заработают, когда администратор добавит ключ ANTHROPIC_API_KEY.') }}
                </div>

                <div ref="feed" class="flex-1 space-y-4 overflow-y-auto p-4 sm:p-6">
                    <!-- Пустой экран: что это и о чём спрашивать -->
                    <div v-if="!messages.length" class="mx-auto max-w-xl py-10 text-center">
                        <div class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-2xl bg-gradient-to-br from-indigo-500/15 to-violet-500/10 text-2xl text-indigo-500">✦</div>
                        <p class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ $e('Спросите о делах компании') }}</p>
                        <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-slate-500 dark:text-slate-400">
                            {{ $e('Помощник отвечает, зная цифры вашей системы: сделки, цех, склад, задачи и деньги.') }}
                        </p>
                        <div class="mt-6 grid gap-2 sm:grid-cols-2">
                            <button v-for="q in examples" :key="q" :disabled="sending" @click="send(q)"
                                class="spotlight rounded-lg border border-slate-200 px-3 py-2.5 text-left text-xs text-slate-600 transition-all hover:-translate-y-0.5 hover:border-indigo-300 hover:text-indigo-600 disabled:opacity-50 dark:border-slate-800/80 dark:text-slate-300 dark:hover:border-indigo-500/50">
                                {{ q }}
                            </button>
                        </div>
                    </div>

                    <!-- Реплики -->
                    <div v-for="m in messages" :key="m.id" class="flex" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
                        <div class="max-w-[85%] rounded-2xl px-4 py-3 text-sm leading-relaxed"
                            :class="m.role === 'user'
                                ? 'bg-indigo-600 text-white'
                                : 'spotlight border border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-800/80 dark:bg-slate-800/40 dark:text-slate-200'">
                            <template v-for="(line, i) in parse(m.content)" :key="i">
                                <div v-if="line.empty" class="h-2" />
                                <div v-else :class="line.bullet ? 'flex gap-2' : ''">
                                    <span v-if="line.bullet" class="shrink-0 opacity-50">•</span>
                                    <span><template v-for="(p, j) in line.parts" :key="j"><b v-if="p.bold">{{ p.text }}</b><template v-else>{{ p.text }}</template></template></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Ожидание ответа -->
                    <div v-if="sending" class="flex justify-start">
                        <div class="flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-400 dark:border-slate-800/80 dark:bg-slate-800/40">
                            <span class="flex gap-1">
                                <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-indigo-400" style="animation-delay: 0ms" />
                                <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-indigo-400" style="animation-delay: 150ms" />
                                <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-indigo-400" style="animation-delay: 300ms" />
                            </span>
                            {{ $e('Помощник думает…') }}
                        </div>
                    </div>
                </div>

                <!-- Ввод -->
                <div class="border-t border-slate-100 p-3 dark:border-slate-800/80">
                    <p v-if="error" class="mb-2 text-xs text-rose-500">{{ error }}</p>
                    <div class="flex items-end gap-2">
                        <textarea v-model="message" rows="1" :disabled="sending" :placeholder="$e('Задайте вопрос…')"
                            class="max-h-40 flex-1 resize-none rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                            @keydown="onKeydown" @input="$event.target.style.height = 'auto'; $event.target.style.height = $event.target.scrollHeight + 'px'" />
                        <button :disabled="sending || !message.trim()" @click="send()"
                            class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white transition-all hover:bg-indigo-700 active:scale-[0.97] disabled:opacity-40">
                            {{ $e('Отправить') }}
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>

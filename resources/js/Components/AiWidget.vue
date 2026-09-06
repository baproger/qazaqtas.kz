<script setup>
/**
 * Мини-чат помощника в углу экрана — доступен с любой страницы ERP.
 *
 * Спрашивает через JSON-маршрут ai.ask, поэтому страница под виджетом не
 * перезагружается и работа не теряется. Диалог тот же, что на полной
 * странице: переписка сохраняется и открывается кнопкой «Открыть полностью».
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { parseAnswer } from '@/utils/chatText';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const open = ref(false);
const question = ref('');
const sending = ref(false);
const messages = ref([]);          // { role, content }
const conversationId = ref(null);
const feed = ref(null);

const examples = [
    tr('Что по просроченным сделкам?'),
    tr('Покажи остатки по складу'),
    tr('Сколько заказов в цехе и где они стоят?'),
];

const canSend = computed(() => question.value.trim().length > 1 && !sending.value);

const scrollDown = () => nextTick(() => {
    if (feed.value) feed.value.scrollTop = feed.value.scrollHeight;
});

const ask = async (text) => {
    const body = (text ?? question.value).trim();
    if (!body || sending.value) return;

    messages.value.push({ role: 'user', content: body });
    question.value = '';
    sending.value = true;
    scrollDown();

    try {
        const { data } = await axios.post(route('ai.ask'), {
            message: body,
            conversation_id: conversationId.value,
        });
        conversationId.value = data.conversation_id;
        messages.value.push({ role: 'assistant', content: data.answer });
    } catch (e) {
        // 422 — валидация или дневной лимит: показываем текст сервера.
        const shown = e.response?.data?.errors?.message?.[0]
            ?? e.response?.data?.message
            ?? tr('Не удалось отправить вопрос.');
        messages.value.push({ role: 'assistant', content: shown });
    } finally {
        sending.value = false;
        scrollDown();
    }
};

/** Новый диалог при каждом открытии не начинаем — но и хвост не тянем. */
const toggle = () => {
    open.value = !open.value;
    if (open.value) scrollDown();
};

const onKeydown = (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        ask();
    }
};

/** Esc закрывает панель — привычно и не мешает работе на странице. */
const onEsc = (e) => {
    if (e.key === 'Escape' && open.value) open.value = false;
};
onMounted(() => document.addEventListener('keydown', onEsc));
onBeforeUnmount(() => document.removeEventListener('keydown', onEsc));
</script>

<template>
    <div class="fixed bottom-5 right-5 z-50 print:hidden">
        <!-- Панель -->
        <transition
            enter-active-class="transition duration-200 ease-out" enter-from-class="translate-y-2 scale-95 opacity-0"
            leave-active-class="transition duration-150 ease-out" leave-to-class="translate-y-2 scale-95 opacity-0">
            <section v-if="open"
                class="mb-3 flex h-[32rem] w-[22rem] max-w-[calc(100vw-2.5rem)] origin-bottom-right flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-800 dark:bg-slate-900">
                <header class="flex items-center justify-between border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                    <span class="flex items-center gap-2 text-sm font-bold text-slate-900 dark:text-slate-100">
                        <span class="grid h-6 w-6 place-items-center rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 text-xs text-white">✦</span>
                        {{ $e('ИИ-помощник') }}
                    </span>
                    <span class="flex items-center gap-3">
                        <Link :href="route('ai.index')" class="text-xs text-indigo-600 hover:underline dark:text-indigo-400">{{ $e('Открыть полностью') }}</Link>
                        <button class="text-slate-400 transition-colors hover:text-slate-600" :title="$e('Свернуть')" @click="open = false">✕</button>
                    </span>
                </header>

                <div ref="feed" class="flex-1 space-y-3 overflow-y-auto px-4 py-3">
                    <div v-if="!messages.length" class="py-6 text-center">
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ $e('Спросите о делах компании') }}</p>
                        <div class="mt-4 space-y-1.5">
                            <button v-for="q in examples" :key="q" :disabled="sending" @click="ask(q)"
                                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-left text-xs text-slate-600 transition-colors hover:border-indigo-300 hover:text-indigo-600 disabled:opacity-50 dark:border-slate-800 dark:text-slate-300">
                                {{ q }}
                            </button>
                        </div>
                    </div>

                    <div v-for="(m, i) in messages" :key="i" class="flex" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
                        <div class="max-w-[88%] rounded-xl px-3 py-2 text-xs leading-relaxed"
                            :class="m.role === 'user'
                                ? 'bg-indigo-600 text-white'
                                : 'border border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-200'">
                            <template v-for="(line, j) in parseAnswer(m.content)" :key="j">
                                <div v-if="line.empty" class="h-1.5" />
                                <div v-else :class="line.bullet ? 'flex gap-1.5' : ''">
                                    <span v-if="line.bullet" class="shrink-0 opacity-50">•</span>
                                    <span><template v-for="(p, k) in line.parts" :key="k"><b v-if="p.bold">{{ p.text }}</b><template v-else>{{ p.text }}</template></template></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div v-if="sending" class="flex justify-start">
                        <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-400 dark:border-slate-800 dark:bg-slate-800/50">
                            <span class="flex gap-1">
                                <span class="h-1 w-1 animate-bounce rounded-full bg-indigo-400" style="animation-delay: 0ms" />
                                <span class="h-1 w-1 animate-bounce rounded-full bg-indigo-400" style="animation-delay: 150ms" />
                                <span class="h-1 w-1 animate-bounce rounded-full bg-indigo-400" style="animation-delay: 300ms" />
                            </span>
                            {{ $e('Помощник думает…') }}
                        </div>
                    </div>
                </div>

                <div class="border-t border-slate-100 p-3 dark:border-slate-800">
                    <div class="flex items-end gap-2">
                        <textarea v-model="question" rows="1" :disabled="sending" :placeholder="$e('Задайте вопрос…')"
                            class="max-h-24 flex-1 resize-none rounded-lg border-slate-300 text-xs shadow-sm focus:border-indigo-400 focus:ring-indigo-400 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                            @keydown="onKeydown" />
                        <button :disabled="!canSend" @click="ask()"
                            class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-indigo-600 text-white transition-all hover:bg-indigo-700 active:scale-[0.95] disabled:opacity-40">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4z" /></svg>
                        </button>
                    </div>
                </div>
            </section>
        </transition>

        <!-- Кнопка вызова -->
        <button
            class="ml-auto flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-violet-600 text-xl text-white shadow-lg transition-all hover:shadow-xl active:scale-[0.95]"
            :title="$e('ИИ-помощник')" :aria-label="$e('ИИ-помощник')" @click="toggle">
            <span v-if="!open">✦</span>
            <span v-else class="text-base">✕</span>
        </button>
    </div>
</template>

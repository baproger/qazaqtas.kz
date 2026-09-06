<script setup>
/**
 * ИИ-помощник: плавающая кнопка и панель на любой странице ERP.
 *
 * Спрашивает агента (/api/assistant/ask): тот сам ходит инструментами в базу
 * и отвечает цифрами системы, а не «по памяти». Страница под виджетом не
 * перезагружается, поэтому работа не теряется.
 *
 * Ответ рисуется БЕЗ v-html: текст разобран на куски (жирное, ссылки), а
 * ссылки открываются переходом внутри приложения.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { parseAnswer } from '@/utils/chatText';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const open = ref(false);
const tab = ref('chat');
const question = ref('');
const sending = ref(false);
const messages = ref([]);          // { role, content, tools? }
const feed = ref(null);

const history = ref([]);
const historyLoading = ref(false);
const expanded = ref({});

const examples = [
    tr('Сколько прибыль за месяц?'),
    tr('Что по просроченным сделкам?'),
    tr('Покажи остатки по складу'),
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
        // Контекст диалога — последние 10 реплик, без служебных полей.
        const context = messages.value.slice(-11, -1)
            .map((m) => ({ role: m.role, content: m.content }));

        const { data } = await axios.post('/api/assistant/ask', { question: body, history: context });
        messages.value.push({ role: 'assistant', content: data.answer, tools: data.used_tools ?? [] });
    } catch (e) {
        messages.value.push({
            role: 'assistant',
            content: e.response?.data?.error ?? tr('Не удалось отправить вопрос.'),
        });
    } finally {
        sending.value = false;
        scrollDown();
    }
};

const loadHistory = async () => {
    historyLoading.value = true;
    try {
        const { data } = await axios.get('/api/assistant/history');
        history.value = data.items ?? [];
    } catch {
        history.value = [];
    } finally {
        historyLoading.value = false;
    }
};

watch(tab, (v) => { if (v === 'history') loadHistory(); });

/** Даты — заголовками: журнал читается сверху вниз, как лента. */
const historyByDate = computed(() => {
    const groups = new Map();
    for (const item of history.value) {
        const d = item.created_at ? new Date(item.created_at) : null;
        const key = d ? d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }) : '—';
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(item);
    }

    return [...groups.entries()].map(([date, items]) => ({ date, items }));
});

const time = (iso) => (iso ? new Date(iso).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) : '');

/** Переход по ссылке из ответа — внутри приложения, без перезагрузки. */
const go = (href) => {
    open.value = false;
    router.visit(href);
};

const onKeydown = (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        ask();
    }
};

const onEsc = (e) => {
    if (e.key === 'Escape' && open.value) open.value = false;
};
onMounted(() => document.addEventListener('keydown', onEsc));
onBeforeUnmount(() => document.removeEventListener('keydown', onEsc));
</script>

<template>
    <!-- z-index выше любых оверлеев и карт системы. -->
    <div class="fixed bottom-5 right-5 print:hidden" style="z-index: 1200">
        <transition
            enter-active-class="transition duration-200 ease-out" enter-from-class="translate-y-2 scale-95 opacity-0"
            leave-active-class="transition duration-150 ease-out" leave-to-class="translate-y-2 scale-95 opacity-0">
            <section v-if="open"
                class="mb-3 flex h-[34rem] w-[25rem] max-w-[calc(100vw-2.5rem)] origin-bottom-right flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-800 dark:bg-slate-900">
                <header class="border-b border-slate-100 dark:border-slate-800">
                    <div class="flex items-center justify-between px-4 pt-3">
                        <span class="flex items-center gap-2 text-sm font-bold text-slate-900 dark:text-slate-100">
                            <span class="grid h-6 w-6 place-items-center rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 text-xs text-white">✦</span>
                            {{ $e('ИИ-помощник') }}
                        </span>
                        <button class="text-slate-400 transition-colors hover:text-slate-600" :title="$e('Свернуть')" @click="open = false">✕</button>
                    </div>
                    <div class="flex gap-4 px-4">
                        <button v-for="t in [{ k: 'chat', n: $e('Чат') }, { k: 'history', n: $e('История') }]" :key="t.k"
                            class="border-b-2 pb-2 pt-2 text-xs font-medium transition-colors"
                            :class="tab === t.k ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-slate-400 hover:text-slate-600'"
                            @click="tab = t.k">{{ t.n }}</button>
                    </div>
                </header>

                <!-- ЧАТ -->
                <template v-if="tab === 'chat'">
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
                                        <span><template v-for="(p, k) in line.parts" :key="k"><a v-if="p.link" href="#" class="font-semibold text-indigo-600 underline decoration-dotted underline-offset-2 dark:text-indigo-400" @click.prevent="go(p.link)">{{ p.text }}</a><b v-else-if="p.bold">{{ p.text }}</b><template v-else>{{ p.text }}</template></template></span>
                                    </div>
                                </template>
                                <div v-if="m.tools?.length" class="mt-1.5 flex flex-wrap gap-1">
                                    <span v-for="t in m.tools" :key="t" class="rounded bg-slate-200/70 px-1.5 py-0.5 text-[10px] text-slate-500 dark:bg-slate-700/60 dark:text-slate-400">{{ t }}</span>
                                </div>
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
                </template>

                <!-- ИСТОРИЯ -->
                <div v-else class="flex-1 overflow-y-auto px-4 py-3">
                    <p v-if="historyLoading" class="py-6 text-center text-xs text-slate-400">{{ $e('Загрузка…') }}</p>
                    <p v-else-if="!history.length" class="py-6 text-center text-xs text-slate-400">{{ $e('Вопросов пока не было') }}</p>

                    <div v-for="group in historyByDate" :key="group.date" class="mb-4">
                        <p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-slate-400">{{ group.date }}</p>
                        <div v-for="item in group.items" :key="item.id"
                            class="mb-2 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-800">
                            <p class="text-xs font-bold text-slate-800 dark:text-slate-100">{{ item.question }}</p>
                            <p class="mt-1 whitespace-pre-line text-xs text-slate-500 dark:text-slate-400"
                                :class="expanded[item.id] ? '' : 'line-clamp-2'">{{ item.answer }}</p>
                            <button class="mt-1 text-[10px] text-indigo-500 hover:underline"
                                @click="expanded[item.id] = !expanded[item.id]">
                                {{ expanded[item.id] ? $e('Свернуть') : $e('Показать полностью') }}
                            </button>
                            <div class="mt-1.5 flex flex-wrap items-center gap-1">
                                <span v-for="t in item.used_tools" :key="t"
                                    class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] text-indigo-500 dark:bg-indigo-500/10 dark:text-indigo-400">{{ t }}</span>
                                <span class="ml-auto text-[10px] text-slate-400">{{ time(item.created_at) }} · {{ item.user }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </transition>

        <button
            class="ml-auto flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-violet-600 text-xl text-white shadow-lg transition-all hover:shadow-xl active:scale-[0.95]"
            :title="$e('ИИ-помощник')" :aria-label="$e('ИИ-помощник')" @click="open = !open">
            <span v-if="!open">✦</span>
            <span v-else class="text-base">✕</span>
        </button>
    </div>
</template>

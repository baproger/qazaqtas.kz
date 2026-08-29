<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TaskPanel from '@/Components/TaskPanel.vue';
import FinancePanel from '@/Components/FinancePanel.vue';
import DocumentPanel from '@/Components/DocumentPanel.vue';
import CommentPanel from '@/Components/CommentPanel.vue';
import HistoryPanel from '@/Components/HistoryPanel.vue';
import PhotoPanel from '@/Components/PhotoPanel.vue';
import OrderItems from '@/Components/OrderItems.vue';
import { isImage } from '@/utils/image';
import { formatDuration, formatDate, formatDateTime } from '@/utils/format';

const props = defineProps({ project: Object, stages: Array, users: Array, finance: Object, financeEntityType: String, financeEntityId: Number, financeInvoices: Array, financeExpenses: Array, canSeeMoney: Boolean, canOpenDeal: { type: Boolean, default: false }, itemProgress: { type: Object, default: () => ({}) }, canReport: { type: Boolean, default: false }, unfinishedItems: { type: Number, default: 0 }, history: Array, stageLogs: { type: Array, default: () => [] } });
const money = (v) => new Intl.NumberFormat('ru-RU').format(v ?? 0) + ' ₸';
const tab = ref('info');
const showTiming = ref(false);
const lastStage = computed(() => props.stages[props.stages.length - 1]);
const isLast = computed(() => props.project.project_stage_id === lastStage.value?.id);

// Сделка, из которой пришёл заказ: цех работает по её данным.
const deal = computed(() => props.project.deal ?? null);
const items = computed(() => deal.value?.items ?? []);
const qty = (v) => Number(v ?? 0).toLocaleString('ru-RU');
// Фото и документы — одна таблица, разводим по типу файла. Снимки берём и у
// заказа, и у сделки: объект менеджер снимает в сделке, отливку цех — в
// заказе, а смотреть их нужно вместе.
const attachments = computed(() => [...(props.project.documents ?? []), ...(deal.value?.documents ?? [])]);
const photos = computed(() => attachments.value.filter((d) => isImage(d.mime_type)));
const files = computed(() => attachments.value.filter((d) => !isImage(d.mime_type)));

const moveStage = (id) => router.patch(route('projects.stage', props.project.id), { project_stage_id: id }, { preserveScroll: true });
const advance = () => router.patch(route('projects.advance', props.project.id), {}, { preserveScroll: true });
const sendToAct = () => router.post(route('projects.toAct', props.project.id), {}, { preserveScroll: true });
</script>

<template>
    <Head :title="project.number" />
    <AppLayout>
        <template #header>
            <div class="flex min-w-0 items-center gap-3">
                <Link :href="route('projects.index')" class="flex-shrink-0 text-slate-400 hover:text-slate-600">← {{ $t('page.workshop', 'Цех') }}</Link>
                <span class="min-w-0 truncate" :title="project.deal?.company_name || project.name">{{ project.deal?.company_name || project.name }}</span>
                <span class="flex-shrink-0 whitespace-nowrap rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">{{ project.number }}</span>
            </div>
        </template>

        <!-- Process bar: the main thing workshop staff need -->
        <div class="mb-4 rounded-2xl bg-white p-5 border border-slate-100 shadow-sm">
            <div class="flex items-center gap-2 overflow-x-auto pb-1">
                <template v-for="(stage, i) in stages" :key="stage.id">
                    <button @click="moveStage(stage.id)"
                        :class="stage.id === project.project_stage_id ? 'text-white shadow' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                        :style="stage.id === project.project_stage_id ? { backgroundColor: stage.color } : {}"
                        class="whitespace-nowrap rounded-full px-4 py-2 text-sm font-medium transition-all duration-200">
                        {{ stage.name }}
                    </button>
                    <span v-if="i < stages.length - 1" class="text-slate-300">›</span>
                </template>
            </div>
            <div class="mt-4 border-t pt-4">
                <button v-if="!isLast" @click="advance"
                    class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow transition-transform hover:scale-[1.02] hover:bg-indigo-700 active:scale-95">
                    {{ $e('Далее — следующий этап →') }}
                </button>
                <!-- Незакрытые позиции держат заказ в цехе: сервер его не
                     отпустит, и кнопка не должна делать вид, что отпустит. -->
                <div v-else-if="project.status !== 'completed'" class="flex flex-wrap items-center gap-3">
                    <button @click="sendToAct" :disabled="unfinishedItems > 0"
                        class="rounded-xl bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white shadow transition-transform hover:scale-[1.02] hover:bg-teal-700 active:scale-95 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:shadow-none disabled:hover:scale-100">
                        {{ $e('🚚 Готово — отправить на «Логистику»') }}
                    </button>
                    <span v-if="unfinishedItems > 0" class="text-sm text-amber-700">
                        {{ $e('Не закончено товаров:') }} <b>{{ unfinishedItems }}</b> — {{ $e('отметьте их ниже') }}
                    </span>
                </div>
                <span v-else class="inline-flex items-center gap-2 text-sm font-semibold text-green-600">{{ $e('✓ Отправлено на «Логистику»') }}</span>
            </div>

            <!-- Тайминг этапов: сколько времени заказ провёл на каждом.
                 Свёрнут — в цехе его смотрят редко, а места он занимал больше,
                 чем всё остальное в карточке. -->
            <div v-if="stageLogs.length" class="mt-4 border-t pt-4">
                <button type="button" class="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400 transition-colors hover:text-slate-600" @click="showTiming = !showTiming">
                    {{ $e('⏱ Тайминг этапов') }}
                    <svg class="h-3.5 w-3.5 transition-transform duration-200" :class="showTiming ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                </button>
                <div v-show="showTiming" class="space-y-1.5">
                    <div v-for="(l, i) in stageLogs" :key="i" class="flex flex-wrap items-center justify-between gap-2 rounded-lg px-3 py-1.5 text-sm"
                        :class="l.open ? 'bg-indigo-50' : 'bg-slate-50'">
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-slate-800">{{ l.stage }}</span>
                            <span v-if="l.open" class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-700">{{ $e('сейчас') }}</span>
                        </div>
                        <div class="flex items-center gap-3 tabular-nums">
                            <span class="text-xs text-slate-400">{{ formatDateTime(l.entered_at) }}<template v-if="l.left_at"> → {{ formatDateTime(l.left_at) }}</template></span>
                            <b :class="l.open ? 'text-indigo-700' : 'text-slate-700'">{{ formatDuration(l.seconds) }}</b>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2 space-y-4">
                <!-- Что делать и как это выглядит — первым экраном и рядом:
                     слева товар, справа фото. Ради этих двух вещей цех и
                     открывает карточку. Цен в позициях нет: сервер их цеху
                     не присылает. -->
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="mb-3 flex items-baseline gap-2">
                        <h3 class="text-sm font-semibold text-slate-900">{{ $e('Что делать') }}</h3>
                        <span v-if="items.length" class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-600">{{ items.length }}</span>
                    </div>
                    <OrderItems :items="items" :progress="itemProgress" :show-money="canSeeMoney"
                        :reportable="canReport" :project-id="project.id"
                        :fallback-name="deal?.client_name ?? ''" :fallback-quantity="deal?.lot_number" :fallback-unit="deal?.unit ?? ''" />
                </div>

                <!-- Общие снимки заказа: площадка, упаковка, отгрузка — то,
                     что не относится к одному товару. -->
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="mb-3 flex items-baseline gap-2">
                        <h3 class="text-sm font-semibold text-slate-900">{{ $e('Общие фото заказа') }}</h3>
                        <span v-if="photos.length" class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-600">{{ photos.length }}</span>
                    </div>
                    <PhotoPanel :documents="attachments" entity-type="project" :entity-id="project.id" />
                </div>

                <div class="rounded-2xl bg-white p-5 border border-slate-100 shadow-sm">
                    <div class="mb-4 flex flex-wrap gap-4 border-b text-sm">
                        <button :class="tab==='info' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-slate-500'" class="pb-2 transition-colors" @click="tab='info'">{{ $e('Информация') }}</button>
                        <button :class="tab==='tasks' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-slate-500'" class="pb-2 transition-colors" @click="tab='tasks'">{{ $e('Задачи (') }}{{ project.tasks.length }})</button>
                        <button v-if="canSeeMoney" :class="tab==='finance' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-slate-500'" class="pb-2 transition-colors" @click="tab='finance'">{{ $e('Финансы') }}</button>
                        <button :class="tab==='docs' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-slate-500'" class="pb-2 transition-colors" @click="tab='docs'">{{ $e('Документы (') }}{{ files.length }})</button>
                        <button :class="tab==='comments' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-slate-500'" class="pb-2 transition-colors" @click="tab='comments'">{{ $e('Комментарии (') }}{{ project.comments.length }})</button>
                        <button :class="tab==='history' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-slate-500'" class="pb-2 transition-colors" @click="tab='history'">{{ $e('История') }}</button>
                    </div>

                    <div v-if="tab==='info'" class="text-sm">
                        <!-- Кто и куда — одной плотной сеткой, а не столбиком
                             строк во всю ширину: в цехе это читают мельком. -->
                        <div class="grid grid-cols-2 gap-x-5 gap-y-3 sm:grid-cols-3">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-slate-400">{{ $e('Клиент') }}</div>
                                <div class="mt-0.5 font-semibold text-slate-900">{{ project.client?.name ?? deal?.company_name ?? '—' }}</div>
                            </div>
                            <!-- Кто ведёт заказ: менеджер со стороны продаж, бригадир со стороны цеха. -->
                            <div>
                                <div class="text-xs uppercase tracking-wide text-slate-400">{{ $e('Менеджер') }}</div>
                                <div class="mt-0.5 font-medium text-slate-900">{{ deal?.responsible?.name ?? project.responsible?.name ?? '—' }}</div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-slate-400">{{ $e('Бригадир') }}</div>
                                <div class="mt-0.5 font-medium text-slate-900">{{ deal?.foreman?.name ?? '—' }}</div>
                            </div>
                            <div class="col-span-2">
                                <div class="text-xs uppercase tracking-wide text-slate-400">{{ $e('Адрес') }}</div>
                                <div class="mt-0.5 font-medium text-slate-900">📍 {{ deal?.address || '—' }}</div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-slate-400">{{ $e('Срок') }}</div>
                                <div class="mt-0.5 font-medium text-slate-900">{{ formatDate(project.deadline ?? deal?.deadline) }}</div>
                            </div>
                            <div v-if="project.deal">
                                <div class="text-xs uppercase tracking-wide text-slate-400">{{ $e('Из сделки') }}</div>
                                <Link v-if="canOpenDeal" :href="route('deals.show', project.deal.id)" class="mt-0.5 block font-medium text-indigo-600 hover:underline">{{ project.deal.number }} →</Link>
                                <div v-else class="mt-0.5 font-medium text-slate-900">{{ project.deal.number }}</div>
                            </div>
                        </div>

                        <div v-if="deal?.note" class="mt-4 rounded-xl bg-amber-50 px-4 py-2.5 ring-1 ring-inset ring-amber-100">
                            <div class="mb-0.5 text-xs font-semibold uppercase tracking-wide text-amber-700">{{ $e('Заметка менеджера') }}</div>
                            <p class="whitespace-pre-line text-slate-700">{{ deal.note }}</p>
                        </div>
                        <div v-if="project.description || deal?.description" class="mt-4">
                            <div class="text-xs uppercase tracking-wide text-slate-400">{{ $e('Описание') }}</div>
                            <p class="mt-0.5 whitespace-pre-line text-slate-700">{{ project.description || deal?.description }}</p>
                        </div>
                    </div>

                    <TaskPanel v-else-if="tab==='tasks'" :tasks="project.tasks" taskable-type="project" :taskable-id="project.id" :users="users" />
                    <FinancePanel v-else-if="tab==='finance' && canSeeMoney" :entity-type="financeEntityType" :entity-id="financeEntityId" :client-id="project.client_id" :invoices="financeInvoices" :expenses="financeExpenses" :finance="finance" :balances="$page.props.balances" />
                    <DocumentPanel v-else-if="tab==='docs'" :documents="files" entity-type="project" :entity-id="project.id" />
                    <CommentPanel v-else-if="tab==='comments'" :comments="project.comments" entity-type="project" :entity-id="project.id" />
                    <HistoryPanel v-else :history="history" />
                </div>
            </div>

            <!-- Budget aside — only for privileged roles -->
            <div v-if="canSeeMoney && finance" class="rounded-2xl bg-white p-5 border border-slate-100 shadow-sm self-start">
                <div class="text-xs uppercase text-slate-400">{{ $e('Бюджет (сумма)') }}</div>
                <div class="mt-1 text-2xl font-bold text-indigo-600">{{ money(finance.budget) }}</div>
                <div class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-slate-500">{{ $e('Статус') }}</span><StatusBadge :status="project.status" /></div>
                    <div class="flex justify-between"><span class="text-slate-500">{{ $e('Расходы') }}</span><span class="font-medium text-red-600">{{ money(finance.expense) }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">{{ $e('Прибыль') }}</span><span class="font-medium" :class="finance.plannedProfit >= 0 ? 'text-green-600' : 'text-red-600'">{{ money(finance.plannedProfit) }}</span></div>
                    <div class="flex justify-between border-t pt-2"><span class="text-slate-500">{{ $e('Маржа') }}</span><span class="font-bold">{{ finance.plannedMargin }}% · {{ money(finance.plannedProfit) }}</span></div>
                </div>
            </div>
            <div v-else class="rounded-2xl bg-indigo-50 p-4 text-sm text-indigo-700 self-start">
                {{ $e('Выполните свой этап и нажмите «Далее». Финансовые данные видны только руководству.') }}
            </div>
        </div>
    </AppLayout>
</template>

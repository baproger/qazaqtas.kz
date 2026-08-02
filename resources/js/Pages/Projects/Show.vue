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
import { formatDuration, formatDate, formatDateTime } from '@/utils/format';

const props = defineProps({ project: Object, stages: Array, users: Array, finance: Object, financeEntityType: String, financeEntityId: Number, financeInvoices: Array, financeExpenses: Array, canSeeMoney: Boolean, history: Array, stageLogs: { type: Array, default: () => [] } });
const money = (v) => new Intl.NumberFormat('ru-RU').format(v ?? 0) + ' ₸';
const tab = ref('info');
const lastStage = computed(() => props.stages[props.stages.length - 1]);
const isLast = computed(() => props.project.project_stage_id === lastStage.value?.id);

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
        <div class="mb-6 rounded-2xl bg-white p-5 border border-slate-200 shadow-sm">
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
                    Далее — следующий этап →
                </button>
                <button v-else-if="project.status !== 'completed'" @click="sendToAct"
                    class="rounded-xl bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white shadow transition-transform hover:scale-[1.02] hover:bg-teal-700 active:scale-95">
                    🚚 Готово — отправить на «Логистику»
                </button>
                <span v-else class="inline-flex items-center gap-2 text-sm font-semibold text-green-600">✓ Отправлено на «Логистику»</span>
            </div>

            <!-- Тайминг этапов: сколько времени заказ провёл на каждом -->
            <div v-if="stageLogs.length" class="mt-4 border-t pt-4">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">⏱ Тайминг этапов</div>
                <div class="space-y-1.5">
                    <div v-for="(l, i) in stageLogs" :key="i" class="flex flex-wrap items-center justify-between gap-2 rounded-lg px-3 py-1.5 text-sm"
                        :class="l.open ? 'bg-indigo-50' : 'bg-slate-50'">
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-slate-800">{{ l.stage }}</span>
                            <span v-if="l.open" class="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">сейчас</span>
                        </div>
                        <div class="flex items-center gap-3 tabular-nums">
                            <span class="text-xs text-slate-400">{{ formatDateTime(l.entered_at) }}<template v-if="l.left_at"> → {{ formatDateTime(l.left_at) }}</template></span>
                            <b :class="l.open ? 'text-indigo-700' : 'text-slate-700'">{{ formatDuration(l.seconds) }}</b>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 space-y-6">
                <div class="rounded-2xl bg-white p-6 border border-slate-200 shadow-sm">
                    <div class="mb-4 flex flex-wrap gap-4 border-b text-sm">
                        <button :class="tab==='info' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-slate-500'" class="pb-2 transition-colors" @click="tab='info'">Информация</button>
                        <button :class="tab==='tasks' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-slate-500'" class="pb-2 transition-colors" @click="tab='tasks'">Задачи ({{ project.tasks.length }})</button>
                        <button v-if="canSeeMoney" :class="tab==='finance' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-slate-500'" class="pb-2 transition-colors" @click="tab='finance'">Финансы</button>
                        <button :class="tab==='docs' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-slate-500'" class="pb-2 transition-colors" @click="tab='docs'">Документы ({{ project.documents.length }})</button>
                        <button :class="tab==='comments' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-slate-500'" class="pb-2 transition-colors" @click="tab='comments'">Комментарии ({{ project.comments.length }})</button>
                        <button :class="tab==='history' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-slate-500'" class="pb-2 transition-colors" @click="tab='history'">История</button>
                    </div>

                    <div v-if="tab==='info'" class="space-y-3 text-sm">
                        <div class="flex justify-between border-b py-2"><span class="text-slate-500">Клиент</span><span class="font-medium">{{ project.client?.name ?? '—' }}</span></div>
                        <div class="flex justify-between border-b py-2"><span class="text-slate-500">Ответственный</span><span class="font-medium">{{ project.responsible?.name ?? '—' }}</span></div>
                        <div v-if="project.deal && canSeeMoney" class="flex justify-between border-b py-2">
                            <span class="text-slate-500">Из сделки</span>
                            <Link :href="route('deals.show', project.deal.id)" class="text-indigo-600 hover:underline">{{ project.deal.number }}</Link>
                        </div>
                        <div class="flex justify-between border-b py-2"><span class="text-slate-500">Срок</span><span class="font-medium">{{ formatDate(project.deadline) }}</span></div>
                        <div class="py-2"><div class="mb-1 text-slate-500">Описание</div><p class="whitespace-pre-line text-slate-700">{{ project.description ?? '—' }}</p></div>
                    </div>

                    <TaskPanel v-else-if="tab==='tasks'" :tasks="project.tasks" taskable-type="project" :taskable-id="project.id" :users="users" />
                    <FinancePanel v-else-if="tab==='finance' && canSeeMoney" :entity-type="financeEntityType" :entity-id="financeEntityId" :client-id="project.client_id" :invoices="financeInvoices" :expenses="financeExpenses" :finance="finance" :balances="$page.props.balances" />
                    <DocumentPanel v-else-if="tab==='docs'" :documents="project.documents" entity-type="project" :entity-id="project.id" />
                    <CommentPanel v-else-if="tab==='comments'" :comments="project.comments" entity-type="project" :entity-id="project.id" />
                    <HistoryPanel v-else :history="history" />
                </div>
            </div>

            <!-- Budget aside — only for privileged roles -->
            <div v-if="canSeeMoney && finance" class="rounded-2xl bg-white p-6 border border-slate-200 shadow-sm self-start">
                <div class="text-xs uppercase text-slate-400">Бюджет (сумма)</div>
                <div class="mt-1 text-2xl font-bold text-indigo-600">{{ money(finance.budget) }}</div>
                <div class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-slate-500">Статус</span><StatusBadge :status="project.status" /></div>
                    <div class="flex justify-between"><span class="text-slate-500">Расходы</span><span class="font-medium text-red-600">{{ money(finance.expense) }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Прибыль</span><span class="font-medium" :class="finance.plannedProfit >= 0 ? 'text-green-600' : 'text-red-600'">{{ money(finance.plannedProfit) }}</span></div>
                    <div class="flex justify-between border-t pt-2"><span class="text-slate-500">Маржа</span><span class="font-bold">{{ finance.plannedMargin }}% · {{ money(finance.plannedProfit) }}</span></div>
                </div>
            </div>
            <div v-else class="rounded-2xl bg-indigo-50 p-6 text-sm text-indigo-700 self-start">
                Выполните свой этап и нажмите «Далее». Финансовые данные видны только руководству.
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { usePage, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { confirmDialog } from '@/composables/useConfirm';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    comments: { type: Array, default: () => [] },
    entityType: String,
    entityId: Number,
});

const me = computed(() => usePage().props.auth.user);
const isAdmin = computed(() => me.value?.roles?.includes('admin'));
const form = useForm({ commentable_type: props.entityType, commentable_id: props.entityId, body: '' });
const add = () => form.post(route('comments.store'), { preserveScroll: true, onSuccess: () => form.reset('body') });
const remove = async (c) => { if (await confirmDialog({ title: 'Удалить комментарий', message: 'Комментарий будет удалён.', confirmText: 'Удалить', danger: true })) router.delete(route('comments.destroy', c.id), { preserveScroll: true }); };
const fmt = (t) => new Date(t).toLocaleString('ru-RU');
</script>

<template>
    <div class="space-y-4">
        <div class="flex gap-2">
            <textarea v-model="form.body" rows="2" placeholder="Написать комментарий…"
                class="w-full rounded-lg border-slate-300 text-sm shadow-sm transition duration-150 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20"></textarea>
        </div>
        <div><PrimaryButton :disabled="form.processing || !form.body" @click="add">Отправить</PrimaryButton></div>

        <div class="space-y-3">
            <div v-for="c in comments" :key="c.id" class="rounded-xl bg-slate-50 p-4 text-sm">
                <div class="flex items-center justify-between">
                    <span class="font-medium text-slate-900">{{ c.user?.name }}</span>
                    <div class="flex items-center gap-2 text-xs text-slate-400">
                        <span>{{ fmt(c.created_at) }}<span v-if="c.edited_at"> (изм.)</span></span>
                        <button v-if="c.user_id === me?.id || isAdmin" class="rounded p-0.5 text-slate-400 transition-colors duration-150 hover:text-rose-600" title="Удалить" @click="remove(c)">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>
                <p class="mt-1 whitespace-pre-line text-slate-700">{{ c.body }}</p>
            </div>
            <div v-if="!comments.length" class="flex flex-col items-center gap-2 py-6 text-center">
                <svg class="h-10 w-10 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M9 9h6M9 12h4"/></svg>
                <span class="text-sm text-slate-400">Комментариев нет</span>
            </div>
        </div>
    </div>
</template>

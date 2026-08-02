<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { confirmDialog } from '@/composables/useConfirm';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    documents: { type: Array, default: () => [] },
    entityType: String,
    entityId: Number,
});

const fileInput = ref(null);
const form = useForm({ documentable_type: props.entityType, documentable_id: props.entityId, name: '', file: null });

const pick = () => fileInput.value.click();
const onFile = (e) => {
    form.file = e.target.files[0];
    if (form.file) form.post(route('documents.store'), { preserveScroll: true, forceFormData: true, onSuccess: () => form.reset('file') });
};
const remove = async (d) => { if (await confirmDialog({ title: 'Удалить документ', message: 'Документ будет удалён.', confirmText: 'Удалить', danger: true })) router.delete(route('documents.destroy', d.id), { preserveScroll: true }); };
const kb = (b) => b > 1048576 ? (b / 1048576).toFixed(1) + ' МБ' : Math.max(1, Math.round(b / 1024)) + ' КБ';
</script>

<template>
    <div class="space-y-3">
        <input ref="fileInput" type="file" class="hidden" @change="onFile" />
        <PrimaryButton :disabled="form.processing" @click="pick">{{ form.processing ? 'Загрузка…' : '+ Загрузить документ' }}</PrimaryButton>

        <div class="space-y-2">
            <div v-for="d in documents" :key="d.id" class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-4 py-3 text-sm">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-500">
                        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="height:18px;width:18px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                    </span>
                    <div class="min-w-0">
                        <a :href="route('documents.download', d.id)" class="font-medium text-indigo-600 transition-colors duration-150 hover:text-indigo-800 hover:underline">{{ d.name }}</a>
                        <div class="text-xs text-slate-400">v{{ d.version }} · {{ kb(d.size) }} · {{ d.user?.name }}</div>
                    </div>
                </div>
                <button class="rounded p-1 text-slate-400 transition-colors duration-150 hover:text-rose-600" title="Удалить" @click="remove(d)">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6"/></svg>
                </button>
            </div>
            <div v-if="!documents.length" class="flex flex-col items-center gap-2 py-6 text-center">
                <svg class="h-10 w-10 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                <span class="text-sm text-slate-400">Документов нет</span>
            </div>
        </div>
    </div>
</template>

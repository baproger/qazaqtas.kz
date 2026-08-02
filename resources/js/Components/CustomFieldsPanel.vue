<script setup>
import { reactive } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    fields: { type: Array, default: () => [] },
    entityType: String,
    entityId: Number,
});

const model = reactive({});
props.fields.forEach((f) => {
    model[f.id] = f.type === 'boolean' ? (f.value === '1' || f.value === true) : (f.value ?? '');
});

const form = useForm({ entity_type: props.entityType, entity_id: props.entityId, values: model });
const save = () => { form.values = { ...model }; form.post(route('custom-field-values.sync'), { preserveScroll: true }); };
</script>

<template>
    <div v-if="fields.length" class="space-y-4">
        <div v-for="f in fields" :key="f.id">
            <label class="mb-1 block text-sm text-slate-600">{{ f.name }}<span v-if="f.required" class="text-red-500"> *</span></label>

            <select v-if="f.type === 'select' || f.type === 'radio'" v-model="model[f.id]" class="w-full rounded-lg border-slate-300 shadow-sm transition duration-150 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                <option value="">—</option>
                <option v-for="opt in f.options" :key="opt" :value="opt">{{ opt }}</option>
            </select>
            <label v-else-if="f.type === 'boolean'" class="flex items-center gap-2 text-sm">
                <input type="checkbox" v-model="model[f.id]" class="rounded border-slate-300 text-indigo-600" /> Да
            </label>
            <TextInput v-else v-model="model[f.id]"
                :type="f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : f.type === 'email' ? 'email' : 'text'"
                class="w-full" />
        </div>
        <PrimaryButton :disabled="form.processing" @click="save">Сохранить поля</PrimaryButton>
    </div>
    <div v-else class="flex flex-col items-center gap-2 py-8 text-center">
        <svg class="h-10 w-10 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M12 8v8M8 12h8"/></svg>
        <span class="text-sm text-slate-400">Дополнительных полей не настроено. Добавьте их в «Настройки → Доп. поля».</span>
    </div>
</template>

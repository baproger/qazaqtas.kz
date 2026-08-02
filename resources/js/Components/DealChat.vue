<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { usePage, useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({ chatId: Number });
const me = computed(() => usePage().props.auth.user);

const messages = ref([]);
const lastId = ref(0);
const scroller = ref(null);
let timer = null;
const form = useForm({ message: '' });

const scrollBottom = () => nextTick(() => { if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight; });

const load = async () => {
    if (!props.chatId) return;
    const { data } = await window.axios.get(route('chat.messages', props.chatId), { params: { after: lastId.value } });
    if (data.messages.length) {
        messages.value.push(...data.messages);
        lastId.value = data.messages[data.messages.length - 1].id;
        scrollBottom();
    }
};
const send = () => {
    if (!form.message.trim()) return;
    form.post(route('chat.send', props.chatId), { preserveScroll: true, preserveState: true, onSuccess: () => { form.reset('message'); load(); } });
};
const fmt = (t) => new Date(t).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });

// Фоновая вкладка сервер не дёргает; вернулись — догружаем сразу.
const onVisible = () => { if (!document.hidden) load(); };
onMounted(() => { load(); timer = setInterval(() => { if (!document.hidden) load(); }, 4000); document.addEventListener('visibilitychange', onVisible); });
onUnmounted(() => { clearInterval(timer); document.removeEventListener('visibilitychange', onVisible); });
</script>

<template>
    <div class="flex h-96 flex-col">
        <div ref="scroller" class="flex-1 space-y-2 overflow-y-auto rounded-lg bg-slate-50 p-3">
            <div v-for="m in messages" :key="m.id" class="flex" :class="m.user_id === me?.id ? 'justify-end' : 'justify-start'">
                <div :class="m.user_id === me?.id ? 'bg-indigo-600 text-white' : 'bg-white text-slate-800 ring-1 ring-slate-100'" class="max-w-[75%] rounded-lg px-3 py-2 text-sm shadow-sm">
                    <div v-if="m.user_id !== me?.id" class="text-xs font-semibold text-indigo-500">{{ m.user_name }}</div>
                    <div class="whitespace-pre-line">{{ m.message }}</div>
                    <div class="mt-0.5 text-right text-[10px] opacity-70">{{ fmt(m.created_at) }}</div>
                </div>
            </div>
            <div v-if="!messages.length" class="flex flex-col items-center gap-2 pt-10 text-center">
                <svg class="h-10 w-10 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22z"/><path d="M8 12h.01M12 12h.01M16 12h.01"/></svg>
                <span class="text-sm text-slate-400">Обсуждение по сделке — напишите первым</span>
            </div>
        </div>
        <div class="mt-3 flex gap-2">
            <input v-model="form.message" @keyup.enter="send" placeholder="Сообщение по сделке…"
                class="flex-1 rounded-lg border-slate-300 text-sm shadow-sm transition duration-150 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
            <PrimaryButton :disabled="form.processing || !form.message.trim()" @click="send">Отправить</PrimaryButton>
        </div>
    </div>
</template>

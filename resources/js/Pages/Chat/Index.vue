<script setup>
import { ref, computed, reactive, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { Head, usePage, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Avatar from '@/Components/Avatar.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { syncChatState } from '@/composables/useChatAlerts';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({
    chats: Array, users: Array, canCreateGroup: Boolean,
    companies: { type: Array, default: () => [] },
    trashedChats: { type: Array, default: () => [] },
});
const me = computed(() => usePage().props.auth.user);

// ---- State ----
const activeChat = ref(props.chats[0] ?? null);
const messages = ref([]);
const lastId = ref(0);
const scroller = ref(null);
const textarea = ref(null);
let timer = null;

const search = ref('');
const listOpen = ref(false);   // mobile chat list
const infoOpen = ref(false);   // right info panel
const infoTab = ref('members');
const showEmoji = ref(false);

const form = useForm({ message: '', file: null, reply_to_id: null, mention_ids: [] });
const replyTo = ref(null); // сообщение, на которое отвечаем (цитата)
const editingMsg = ref(null); // сообщение, которое редактируем

// ---- Persisted UI state (unread + pins) ----
const readState = reactive(JSON.parse(localStorage.getItem('chat_seen') || '{}'));
const pins = ref(JSON.parse(localStorage.getItem('chat_pins') || '[]'));
const archived = ref(JSON.parse(localStorage.getItem('chat_archived') || '[]'));
const showArchived = ref(false);
const persistSeen = () => localStorage.setItem('chat_seen', JSON.stringify(readState));
const persistPins = () => localStorage.setItem('chat_pins', JSON.stringify(pins.value));
const persistArchived = () => localStorage.setItem('chat_archived', JSON.stringify(archived.value));
const isArchived = (c) => archived.value.includes(c.id);
const toggleArchive = (c) => {
    archived.value = isArchived(c) ? archived.value.filter((x) => x !== c.id) : [...archived.value, c.id];
    persistArchived();
};

const isPinned = (c) => pins.value.includes(c.id);
const togglePin = (c) => {
    pins.value = isPinned(c) ? pins.value.filter((id) => id !== c.id) : [...pins.value, c.id];
    persistPins();
};
// ---- Звук нового сообщения (WebAudio, без файлов; тумблер 🔔 сохраняется) ----
const soundOn = ref(localStorage.getItem('chat_sound') !== 'off');
const toggleSound = () => {
    soundOn.value = !soundOn.value;
    localStorage.setItem('chat_sound', soundOn.value ? 'on' : 'off');
    if (soundOn.value) askNotifyPermission(); // клик — удобный момент спросить разрешение
};

// ---- Браузерные уведомления: работают из фоновой вкладки и на телефоне ----
const askNotifyPermission = () => {
    if ('Notification' in window && Notification.permission === 'default') {
        try { Notification.requestPermission(); } catch (e) { /* старые браузеры */ }
    }
};
const notifyBrowser = (title, body) => {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    try {
        const n = new Notification(title, { body, tag: 'qazaqtas-chat' });
        n.onclick = () => { window.focus(); n.close(); };
    } catch (e) { /* ignore */ }
};

let audioCtx = null;
const ding = () => {
    if (!soundOn.value) return;
    try {
        audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
        if (audioCtx.state === 'suspended') audioCtx.resume();
        const o = audioCtx.createOscillator(); const g = audioCtx.createGain();
        o.connect(g); g.connect(audioCtx.destination);
        o.type = 'sine';
        o.frequency.setValueAtTime(880, audioCtx.currentTime);
        o.frequency.exponentialRampToValueAtTime(1318, audioCtx.currentTime + 0.08);
        g.gain.setValueAtTime(0.001, audioCtx.currentTime);
        g.gain.exponentialRampToValueAtTime(0.12, audioCtx.currentTime + 0.02);
        g.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.35);
        o.start(); o.stop(audioCtx.currentTime + 0.4);
    } catch (e) { /* браузер мог запретить звук до первого клика */ }
};

// ---- Живые бейджи: лёгкий поллинг непрочитанных и последних сообщений ----
const live = reactive({});   // chat_id -> { unread, last }
let statePrev = null;
const pollState = async () => {
    try {
        const { data } = await window.axios.get(route('chat.state'));
        const st = data.state || {};
        const alerts = [];
        for (const [id, s] of Object.entries(st)) {
            const isActive = Number(id) === activeChat.value?.id;
            // Открытый чат не «дзынькает» на переднем плане (его озвучивает loadMessages),
            // но в фоне уведомляем и о нём.
            if (statePrev && s.unread > (statePrev[id]?.unread ?? 0) && (!isActive || document.hidden)) {
                alerts.push({ id: Number(id), s });
            }
            live[id] = s;
        }
        statePrev = st;
        syncChatState(st); // бейдж «Чат» в меню + защита от повторного «дзыня» после ухода со страницы
        if (alerts.length) {
            ding();
            if (document.hidden) {
                const a = alerts[0];
                const chat = props.chats.find((c) => c.id === a.id);
                notifyBrowser(chat?.name ?? tr('Новое сообщение в чате'), a.s.last
                    ? `${a.s.last.author ?? ''}: ${a.s.last.text}`
                    : tr('Есть непрочитанные сообщения'));
            }
        }
    } catch (e) { /* ignore transient poll errors */ }
};
const lastOf = (c) => live[c.id]?.last ?? c.last;

// Непрочитанные — серверный счётчик (live-поллинг или снапшот props); открытый чат гасим сразу.
const locallyRead = reactive({});
const unreadCount = (c) => {
    if (c.id === activeChat.value?.id) return 0;
    const s = live[c.id];
    if (s) return s.unread;
    return locallyRead[c.id] ? 0 : (c.unread ?? 0);
};
const isUnread = (c) => unreadCount(c) > 0;
const markSeen = (c) => {
    if (!c) return;
    if (c.last) { readState[c.id] = c.last.id; persistSeen(); }
    locallyRead[c.id] = true;
    window.axios.post(route('chat.read', c.id)).catch(() => {});
};

// ---- Chat list sections ----
const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.chats;
    return props.chats.filter((c) => c.name.toLowerCase().includes(q)
        || (c.participants || []).some((p) => p.name.toLowerCase().includes(q)));
});
const sections = computed(() => {
    // Архивные — вне обычных секций (показываются отдельным блоком).
    const list = filtered.value.filter((c) => !isArchived(c));
    const pinned = list.filter(isPinned);
    const rest = list.filter((c) => !isPinned(c));
    return [
        { key: 'pinned', title: tr('Закреплённые'), items: pinned },
        { key: 'global', title: tr('Общий'), items: rest.filter((c) => c.type === 'global') },
        { key: 'personal', title: tr('Личные сообщения'), items: rest.filter((c) => c.type === 'personal') },
        { key: 'group', title: tr('Групповые чаты'), items: rest.filter((c) => c.type === 'group' && !c.deal_id) },
        { key: 'project', title: tr('Проектные каналы'), items: rest.filter((c) => c.deal_id) },
    ].filter((g) => g.items.length);
});
const archivedChats = computed(() => filtered.value.filter(isArchived));

// ---- Helpers ----
const initial = (name) => (name ?? '?').trim().charAt(0).toUpperCase();
const avatarColor = (name) => {
    const colors = ['bg-indigo-500', 'bg-emerald-500', 'bg-rose-500', 'bg-amber-500', 'bg-sky-500', 'bg-violet-500', 'bg-teal-500'];
    let h = 0; for (const ch of (name ?? '')) h = (h + ch.charCodeAt(0)) % colors.length;
    return colors[h];
};
const fmtTime = (t) => new Date(t).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
const fmtDay = (t) => new Date(t).toLocaleDateString('ru-RU', { day: '2-digit', month: 'long' });
const typeLabel = (c) => c?.deal_id ? tr('Проектный канал') : ({ global: tr('Общий чат'), personal: tr('Личный чат'), group: tr('Групповой чат') }[c?.type] ?? '');
const otherParticipant = (c) => (c?.participants || []).find((p) => p.id !== me.value?.id);

// In-chat message search.
const showSearch = ref(false);
const msgSearch = ref('');
const visibleMessages = computed(() => {
    const q = msgSearch.value.trim().toLowerCase();
    if (!q) return messages.value;
    return messages.value.filter((m) => (m.message || '').toLowerCase().includes(q)
        || (m.attachments || []).some((a) => (a.name || '').toLowerCase().includes(q)));
});

// Messages grouped with day separators.
const grouped = computed(() => {
    const out = [];
    let day = null;
    for (const m of visibleMessages.value) {
        const d = fmtDay(m.created_at);
        if (d !== day) { out.push({ sep: true, id: 's' + m.id, day: d }); day = d; }
        out.push(m);
    }
    return out;
});

// Chat attachments (the «Вложения» tab).
const attachments = ref([]);
const loadAttachments = async () => {
    if (!activeChat.value) { attachments.value = []; return; }
    try {
        const { data } = await window.axios.get(route('chat.attachments', activeChat.value.id));
        attachments.value = data.attachments;
    } catch (e) { attachments.value = []; }
};
watch([() => infoTab.value, () => activeChat.value?.id, infoOpen], () => {
    if (infoOpen.value && infoTab.value === 'files' && activeChat.value) loadAttachments();
});

// ---- Messaging ----
const scrollBottom = () => nextTick(() => { if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight; });

// «Кто прочитал»: user_id → id последнего прочитанного им сообщения этого чата.
const reads = ref({});
const readersFor = (m) => (activeChat.value?.participants || [])
    .filter((p) => p.id !== m.user_id && Number(reads.value[p.id] ?? 0) >= m.id);

const loadMessages = async (reset = false) => {
    if (!activeChat.value) return;
    if (reset) { messages.value = []; lastId.value = 0; reads.value = {}; }
    try {
        const { data } = await window.axios.get(route('chat.messages', activeChat.value.id), { params: { after: lastId.value } });
        if (data.reads) reads.value = data.reads;
        if (data.messages.length) {
            // Звук: пришло чужое сообщение в открытый чат (не первичная загрузка).
            if (!reset && data.messages.some((m) => m.user_id !== me.value?.id)) ding();
            messages.value.push(...data.messages);
            lastId.value = data.messages[data.messages.length - 1].id;
            markSeen(activeChat.value);
            scrollBottom();
        }
    } catch (e) { /* ignore transient poll errors */ }
};

const selectChat = async (chat) => {
    activeChat.value = chat;
    listOpen.value = false;
    showEmoji.value = false;
    markSeen(chat);
    await loadMessages(true);
};

const send = () => {
    // Режим редактирования своего сообщения.
    if (editingMsg.value) { saveEditMsg(); return; }
    if ((!form.message.trim() && !form.file) || !activeChat.value) return;
    showEmoji.value = false;
    form.reply_to_id = replyTo.value?.id ?? null;
    // Упоминания: отправляем id тех, чьё @имя реально осталось в тексте.
    form.mention_ids = mentioned.value.filter((m) => form.message.includes('@' + m.name)).map((m) => m.id);
    form.post(route('chat.send', activeChat.value.id), {
        preserveScroll: true, preserveState: true, forceFormData: true,
        onSuccess: () => { form.reset('message', 'reply_to_id', 'mention_ids'); form.file = null; replyTo.value = null; mentioned.value = []; resizeInput(); loadMessages(); },
    });
};

// ---- Упоминания @имя ----
const mentioned = ref([]); // [{id, name}] упомянутые в текущем черновике
const mentionQuery = ref(null);
const mentionList = computed(() => {
    if (mentionQuery.value === null) return [];
    const q = mentionQuery.value.toLowerCase();
    const pool = activeChat.value?.participants?.length ? activeChat.value.participants : props.users;
    return pool.filter((u) => u.id !== me.value?.id && u.name.toLowerCase().includes(q)).slice(0, 6);
});
const onComposerInput = () => {
    resizeInput();
    const el = textarea.value;
    const upto = el ? form.message.slice(0, el.selectionStart) : form.message;
    const m = upto.match(/@([\p{L}\p{N} ]{0,24})$/u);
    mentionQuery.value = m ? m[1].replace(/^\s+/, '') : null;
};
const pickMention = (u) => {
    const el = textarea.value;
    const pos = el ? el.selectionStart : form.message.length;
    const before = form.message.slice(0, pos).replace(/@([\p{L}\p{N} ]*)$/u, '@' + u.name + ' ');
    form.message = before + form.message.slice(pos);
    if (!mentioned.value.some((x) => x.id === u.id)) mentioned.value.push({ id: u.id, name: u.name });
    mentionQuery.value = null;
    nextTick(() => { textarea.value?.focus(); resizeInput(); });
};
const onEnter = (e) => {
    if (e.shiftKey) return;
    e.preventDefault();
    // Если открыт список @упоминаний — Enter выбирает первого.
    if (mentionList.value.length) { pickMention(mentionList.value[0]); return; }
    send();
};

// ---- Ответ-цитата ----
const startReply = (m) => { editingMsg.value = null; replyTo.value = m; textarea.value?.focus(); };
const cancelReply = () => { replyTo.value = null; };

// ---- Редактирование своего сообщения ----
const startEditMsg = (m) => {
    replyTo.value = null;
    editingMsg.value = m;
    form.message = m.message || '';
    resizeInput();
    textarea.value?.focus();
};
const cancelEditMsg = () => { editingMsg.value = null; form.reset('message'); resizeInput(); };
const saveEditMsg = async () => {
    if (!form.message.trim()) return;
    try {
        await window.axios.patch(route('chat.messages.update', editingMsg.value.id), { message: form.message });
        editingMsg.value = null; form.reset('message'); resizeInput(); loadMessages(true);
    } catch (e) { /* ignore */ }
};

// ---- File attachment ----
const fileInput = ref(null);
const pickFile = () => fileInput.value?.click();
const onFilePicked = (e) => { const f = e.target.files?.[0]; if (f) form.file = f; e.target.value = ''; };
const fmtSize = (b) => b >= 1048576 ? (b / 1048576).toFixed(1) + tr(' МБ') : Math.max(1, Math.round(b / 1024)) + tr(' КБ');

// ---- Delete a message (admin/director any, author own) ----
const deleteMessage = async (m) => {
    if (await confirmDialog({ title: tr('Удалить сообщение'), message: tr('Сообщение будет удалено безвозвратно.'), confirmText: tr('Удалить'), danger: true })) {
        try { await window.axios.delete(route('chat.messages.destroy', m.id)); loadMessages(true); } catch (e) { /* ignore */ }
    }
};

// ---- Реакции-эмодзи ----
const REACTIONS = ['👍', '❤️', '😂', '🎉', '🔥', '👏'];
const reactPickerFor = ref(null); // id сообщения, у которого открыт выбор реакции
const react = async (m, emoji) => {
    reactPickerFor.value = null;
    try { await window.axios.post(route('chat.messages.react', m.id), { emoji }); loadMessages(true); } catch (e) { /* ignore */ }
};

// ---- Закрепление сообщения (админ/директор) ----
const pinMessage = async (m) => {
    const chatId = activeChat.value?.id;
    try {
        await window.axios.post(route('chat.messages.pin', m.id));
        router.reload({ only: ['chats'], onSuccess: () => { if (chatId) syncActive(chatId); } });
    } catch (e) { /* ignore */ }
};

// Auto-resize textarea.
const resizeInput = () => nextTick(() => {
    const el = textarea.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 140) + 'px';
});

// Emoji.
const emojis = ['😀', '😁', '😂', '🙂', '😉', '😍', '😎', '🤔', '👍', '🙏', '🔥', '🎉', '✅', '❌', '❤️', '👏', '🤝', '📌', '💰', '⚡'];
const addEmoji = (e) => { form.message += e; resizeInput(); textarea.value?.focus(); };

// ---- New chat / group ----
const showNew = ref(false);
const newForm = useForm({ type: 'personal', name: '', description: '', company_id: null, participants: [] });
const userSearch = ref('');
const filteredUsers = computed(() => {
    const q = userSearch.value.trim().toLowerCase();
    let pool = props.users;
    // Группа фирмы — в списке только сотрудники этой фирмы.
    const cid = showEdit.value ? editForm.company_id : (newForm.type === 'group' ? newForm.company_id : null);
    if (cid) pool = pool.filter((u) => (u.company_ids || []).includes(cid));
    return q ? pool.filter((u) => u.name.toLowerCase().includes(q)) : pool;
});
const toggleParticipant = (id) => {
    newForm.participants = newForm.participants.includes(id)
        ? newForm.participants.filter((x) => x !== id)
        : [...newForm.participants, id];
};
const openNew = () => { newForm.reset(); newForm.type = 'personal'; userSearch.value = ''; showNew.value = true; };
// A single Inertia visit already refreshes `chats` (store returns back()), so no extra reload — this fixes the slow double-load.
const createChat = () => newForm.post(route('chat.store'), {
    preserveScroll: true, preserveState: true,
    onSuccess: () => { showNew.value = false; },
});

// Re-point the active chat to its refreshed prop object (updated name/avatar/participants).
const syncActive = (id) => { const c = props.chats.find((x) => x.id === id); if (c) activeChat.value = c; };

// ---- Edit / delete group (admin/director) ----
const canManage = (c) => props.canCreateGroup && c && c.type === 'group';
// Удалять можно и личные чаты (в корзину); общий и каналы сделок — нельзя.
const canDeleteChat = (c) => props.canCreateGroup && c && c.type !== 'global' && !c.deal_id;
const showEdit = ref(false);
const editPhoto = ref(null);
const editForm = useForm({ id: null, name: '', description: '', company_id: null, participants: [], photo: null });
const openEdit = (c) => {
    editForm.id = c.id;
    editForm.name = c.name;
    editForm.company_id = c.company_id ?? null;
    editForm.description = c.description ?? '';
    editForm.photo = null;
    editPhoto.value = null;
    editForm.participants = (c.participants || []).map((p) => p.id).filter((id) => id !== me.value?.id);
    userSearch.value = '';
    showEdit.value = true;
};
const toggleEditParticipant = (id) => {
    editForm.participants = editForm.participants.includes(id)
        ? editForm.participants.filter((x) => x !== id)
        : [...editForm.participants, id];
};
const editPhotoInput = ref(null);
const onEditPhoto = (e) => { const f = e.target.files?.[0]; if (f) { editForm.photo = f; editPhoto.value = URL.createObjectURL(f); } e.target.value = ''; };
const saveEdit = () => {
    editForm.transform((data) => ({ ...data, _method: 'put' })).post(route('chat.update', editForm.id), {
        preserveScroll: true, preserveState: true, forceFormData: true,
        onSuccess: () => { showEdit.value = false; syncActive(editForm.id); },
    });
};
const removeChat = async (c) => {
    const label = c.type === 'group' ? tr('Группа') : tr('Чат');
    if (await confirmDialog({ title: c.type === 'group' ? tr('Удалить группу') : tr('Удалить чат'), message: `${label} «${c.name}» отправится в корзину — можно будет восстановить.`, confirmText: tr('В корзину'), danger: true })) {
        router.delete(route('chat.destroy', c.id), {
            preserveScroll: true, preserveState: true,
            onSuccess: () => { infoOpen.value = false; activeChat.value = null; messages.value = []; },
        });
    }
};

// ---- Корзина чатов (admin/director): вернуть или стереть навсегда ----
const showTrash = ref(false);
const restoreChat = (c) => router.post(route('chat.restore', c.id), {}, { preserveScroll: true, preserveState: true });
const purgeChat = async (c) => {
    if (await confirmDialog({ title: tr('Удалить навсегда'), message: `Чат «${c.name}» и все его сообщения будут стёрты БЕЗВОЗВРАТНО.`, confirmText: tr('Стереть'), danger: true })) {
        router.delete(route('chat.force', c.id), { preserveScroll: true, preserveState: true });
    }
};

// ---- Участники группы: добавить нового сотрудника / убрать (admin/director) ----
const memberSearch = ref('');
const nonMembers = computed(() => {
    const c = activeChat.value;
    if (!c) return [];
    const ids = new Set((c.participants || []).map((p) => p.id));
    const q = memberSearch.value.trim().toLowerCase();
    return props.users
        .filter((u) => !ids.has(u.id))
        // В группу фирмы предлагаем только сотрудников этой фирмы.
        .filter((u) => !c.company_id || (u.company_ids || []).includes(c.company_id))
        .filter((u) => !q || u.name.toLowerCase().includes(q))
        .slice(0, 30);
});
const addMember = (u) => {
    const id = activeChat.value?.id;
    if (!id) return;
    router.post(route('chat.members.add', id), { user_id: u.id }, {
        preserveScroll: true, preserveState: true,
        onSuccess: () => syncActive(id),
    });
};
const removeMember = async (p) => {
    const id = activeChat.value?.id;
    if (!id) return;
    if (await confirmDialog({ title: tr('Убрать участника'), message: `${p.name} потеряет доступ к этой группе.`, confirmText: tr('Убрать'), danger: true })) {
        router.delete(route('chat.members.remove', [id, p.id]), {
            preserveScroll: true, preserveState: true,
            onSuccess: () => syncActive(id),
        });
    }
};

watch(() => form.message, resizeInput);

// Передний план — полный поллинг (4с); фон — только лёгкий state раз в 30с,
// чтобы звук и уведомления работали, не нагружая сервер сообщениями.
let bgTimer = null;
const onVisible = () => { if (!document.hidden) { loadMessages(); pollState(); } };
onMounted(() => {
    if (activeChat.value) { markSeen(activeChat.value); loadMessages(true); }
    pollState();
    askNotifyPermission();
    timer = setInterval(() => { if (!document.hidden) { loadMessages(); pollState(); } }, 4000);
    bgTimer = setInterval(() => { if (document.hidden) pollState(); }, 30000);
    document.addEventListener('visibilitychange', onVisible);
});
onUnmounted(() => { clearInterval(timer); clearInterval(bgTimer); document.removeEventListener('visibilitychange', onVisible); });
</script>

<template>
    <Head :title="$e('Чат')" />
    <AppLayout>
        <template #header>{{ $t('page.chat', 'Чат') }}</template>

        <div class="relative flex h-[calc(100vh-8.5rem)] overflow-hidden rounded-2xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-sm">
            <!-- ============ LEFT: chat list ============ -->
            <aside :class="listOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
                class="absolute inset-y-0 left-0 z-20 flex w-72 flex-shrink-0 flex-col border-r border-slate-200 dark:border-slate-800/80 bg-slate-50 dark:bg-slate-800/50 transition-transform duration-300 lg:static lg:z-0">
                <div class="flex items-center justify-between gap-2 border-b border-slate-200 dark:border-slate-800/80 px-4 py-3">
                    <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $e('Сообщения') }}</h3>
                    <button @click="toggleSound" :title="soundOn ? $e('Выключить звук') : $e('Включить звук')"
                        class="ml-auto flex h-8 w-8 items-center justify-center rounded-lg text-base transition-colors"
                        :class="soundOn ? 'text-indigo-500 dark:text-indigo-400 hover:bg-indigo-50' : 'text-slate-300 dark:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-800/60'">
                        {{ soundOn ? '🔔' : '🔕' }}
                    </button>
                    <button @click="openNew" :title="$e('Новый чат')"
                        class="new-btn flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-white shadow-sm transition-all hover:bg-indigo-700">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                    </button>
                </div>
                <div class="border-b border-slate-200 dark:border-slate-800/80 p-3">
                    <div class="relative">
                        <svg viewBox="0 0 24 24" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/></svg>
                        <input v-model="search" :placeholder="$e('Поиск чатов и контактов…')"
                            class="w-full rounded-lg border-slate-200 bg-white py-2 pl-9 pr-3 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400" />
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto px-2 py-2">
                    <div v-for="sec in sections" :key="sec.key" class="mb-2">
                        <div class="flex items-center gap-1.5 px-2 py-1 text-xs font-semibold uppercase tracking-wide text-slate-400">
                            <span v-if="sec.key === 'pinned'">📌</span>{{ sec.title }}
                        </div>
                        <button v-for="c in sec.items" :key="c.id" @click="selectChat(c)"
                            :class="activeChat?.id === c.id ? 'bg-white dark:bg-slate-900/70 shadow-sm ring-1 ring-indigo-100' : 'hover:bg-white/70'"
                            class="group relative flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left transition-all">
                            <span class="relative flex h-10 w-10 flex-shrink-0 items-center justify-center overflow-hidden rounded-full text-sm font-bold text-white" :class="avatarColor(c.name)">
                                <img v-if="c.avatar" :src="c.avatar" class="h-full w-full object-cover" alt="" />
                                <span v-else-if="c.type === 'group' || c.type === 'global'">#</span>
                                <template v-else>{{ initial(c.name) }}</template>
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="flex min-w-0 items-center gap-1.5">
                                        <span class="truncate text-sm" :class="isUnread(c) ? 'font-bold text-slate-900 dark:text-slate-100' : 'font-medium text-slate-700 dark:text-slate-300'">{{ c.name }}</span>
                                        <span v-if="c.company_name" class="flex-shrink-0 rounded bg-slate-200/70 dark:bg-slate-700 px-1 py-px text-xs font-bold uppercase text-slate-500 dark:text-slate-400">{{ c.company_name }}</span>
                                    </span>
                                    <span v-if="lastOf(c)" class="flex-shrink-0 text-xs text-slate-400">{{ fmtTime(lastOf(c).time) }}</span>
                                </div>
                                <div class="flex items-center justify-between gap-2">
                                    <span class="truncate text-xs" :class="isUnread(c) ? 'font-semibold text-slate-600 dark:text-slate-300' : 'text-slate-400'">
                                        {{ lastOf(c) ? lastOf(c).text : $e('Нет сообщений') }}
                                    </span>
                                    <span v-if="unreadCount(c) > 0" class="flex h-4 min-w-4 flex-shrink-0 items-center justify-center rounded-full bg-indigo-500 px-1 text-xs font-bold text-white">{{ unreadCount(c) > 99 ? '99+' : unreadCount(c) }}</span>
                                </div>
                            </div>
                            <button @click.stop="togglePin(c)" :title="isPinned(c) ? $e('Открепить') : $e('Закрепить')"
                                class="absolute right-1.5 top-1.5 hidden text-xs text-slate-300 dark:text-slate-600 hover:text-indigo-500 group-hover:block"
                                :class="{ '!block text-indigo-400': isPinned(c) }">📌</button>
                            <button @click.stop="toggleArchive(c)" :title="$e('В архив')"
                                class="absolute right-1.5 bottom-1.5 hidden text-slate-300 dark:text-slate-600 hover:text-indigo-500 group-hover:block">
                                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/></svg>
                            </button>
                        </button>
                    </div>

                    <!-- Архив -->
                    <div v-if="archivedChats.length" class="mb-2">
                        <button @click="showArchived = !showArchived" class="flex w-full items-center gap-1.5 px-2 py-1 text-xs font-semibold uppercase tracking-wide text-slate-400 hover:text-slate-600">
                            <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/></svg>
                            {{ $e('Архив (') }}{{ archivedChats.length }})
                            <span class="ml-auto">{{ showArchived ? '▲' : '▼' }}</span>
                        </button>
                        <template v-if="showArchived">
                            <button v-for="c in archivedChats" :key="c.id" @click="selectChat(c)"
                                :class="activeChat?.id === c.id ? 'bg-white dark:bg-slate-900/70 shadow-sm ring-1 ring-indigo-100' : 'hover:bg-white/70'"
                                class="group relative flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left opacity-70 transition-all">
                                <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center overflow-hidden rounded-full text-sm font-bold text-white" :class="avatarColor(c.name)">
                                    <img v-if="c.avatar" :src="c.avatar" class="h-full w-full object-cover" alt="" />
                                    <span v-else-if="c.type === 'group' || c.type === 'global'">#</span>
                                    <template v-else>{{ initial(c.name) }}</template>
                                </span>
                                <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-600 dark:text-slate-300">{{ c.name }}</span>
                                <button @click.stop="toggleArchive(c)" :title="$e('Вернуть из архива')"
                                    class="flex-shrink-0 text-xs text-slate-400 hover:text-indigo-500">{{ $e('Вернуть') }}</button>
                            </button>
                        </template>
                    </div>
                    <!-- Корзина (admin/director): вернуть чат или стереть навсегда -->
                    <div v-if="canCreateGroup && trashedChats.length" class="mb-2">
                        <button @click="showTrash = !showTrash" class="flex w-full items-center gap-1.5 px-2 py-1 text-xs font-semibold uppercase tracking-wide text-slate-400 hover:text-slate-600">
                            {{ $e('🗑 Корзина (') }}{{ trashedChats.length }})
                            <span class="ml-auto">{{ showTrash ? '▲' : '▼' }}</span>
                        </button>
                        <template v-if="showTrash">
                            <div v-for="c in trashedChats" :key="'t' + c.id" class="flex items-center gap-2 rounded-xl px-2.5 py-2 opacity-80 hover:bg-white/70">
                                <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-slate-300 text-sm font-bold text-white">#</span>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium text-slate-600 dark:text-slate-300">{{ c.name }}</div>
                                    <div class="text-xs text-slate-400">{{ $e('удалён') }} {{ fmtDay(c.deleted_at) }}</div>
                                </div>
                                <button @click="restoreChat(c)" :title="$e('Восстановить')" class="flex-shrink-0 text-xs font-medium text-indigo-500 dark:text-indigo-400 hover:underline">{{ $e('Вернуть') }}</button>
                                <button @click="purgeChat(c)" :title="$e('Стереть навсегда')" class="flex-shrink-0 text-xs text-rose-500 hover:underline">✕</button>
                            </div>
                        </template>
                    </div>
                    <div v-if="!sections.length" class="px-3 py-8 text-center text-sm text-slate-400">{{ $e('Ничего не найдено') }}</div>
                </div>
            </aside>
            <div v-if="listOpen" class="absolute inset-0 z-10 bg-black/20 lg:hidden" @click="listOpen = false"></div>

            <!-- ============ CENTER: conversation ============ -->
            <section class="chat-bg flex min-w-0 flex-1 flex-col">
                <header class="flex items-center justify-between gap-2 border-b border-slate-200 dark:border-slate-800/80 bg-white/80 dark:bg-slate-900/70 px-4 py-2.5 backdrop-blur">
                    <div class="flex min-w-0 items-center gap-3">
                        <button class="rounded-md p-1.5 text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800/60 lg:hidden" @click="listOpen = true">☰</button>
                        <span v-if="activeChat" class="flex h-9 w-9 flex-shrink-0 items-center justify-center overflow-hidden rounded-full text-sm font-bold text-white" :class="avatarColor(activeChat.name)">
                            <img v-if="activeChat.avatar" :src="activeChat.avatar" class="h-full w-full object-cover" alt="" />
                            <span v-else-if="activeChat.type === 'group' || activeChat.type === 'global'">#</span><template v-else>{{ initial(activeChat.name) }}</template>
                        </span>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ activeChat?.name ?? $e('Выберите чат') }}</div>
                            <div class="text-xs text-slate-400">{{ activeChat ? typeLabel(activeChat) + (activeChat.company_name ? ' · ' + activeChat.company_name : '') + ' · ' + (activeChat.participants?.length || 0) + $e(' уч.') : '' }}</div>
                        </div>
                    </div>
                    <div v-if="activeChat" class="flex items-center gap-1.5">
                        <div v-if="showSearch" class="relative">
                            <input v-model="msgSearch" autofocus :placeholder="$e('Поиск в чате…')"
                                class="w-40 rounded-lg border-slate-200 py-1.5 pl-3 pr-7 text-xs shadow-sm focus:border-indigo-400 focus:ring-indigo-400 sm:w-52" />
                            <button @click="showSearch = false; msgSearch = ''" class="absolute right-1.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">✕</button>
                        </div>
                        <button v-else @click="showSearch = true" :title="$e('Поиск в чате')" class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 dark:text-slate-400 transition-colors hover:bg-slate-100 dark:hover:bg-slate-800/60">
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/></svg>
                        </button>
                        <button @click="infoOpen = !infoOpen" :class="infoOpen ? 'bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400' : 'text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800/60'"
                            class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium transition-colors">
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/></svg>
                            {{ $e('Инфо') }}
                        </button>
                    </div>
                </header>

                <!-- Закреплённое сообщение -->
                <div v-if="activeChat?.pinned" class="flex items-center gap-2 border-b border-amber-100 bg-amber-50/70 px-4 py-2 text-xs">
                    <svg viewBox="0 0 24 24" class="h-4 w-4 flex-shrink-0 text-amber-500" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 17v5M9 3h6l-1 7 3 3H7l3-3-1-7Z"/></svg>
                    <div class="min-w-0 flex-1">
                        <span class="font-semibold text-amber-700 dark:text-amber-400">{{ $e('Закреплено ·') }} {{ activeChat.pinned.author }}: </span>
                        <span class="text-slate-600 dark:text-slate-300">{{ activeChat.pinned.message }}</span>
                    </div>
                    <button v-if="canCreateGroup" @click="pinMessage({ id: activeChat.pinned.id })" :title="$e('Открепить')" class="flex-shrink-0 text-slate-400 hover:text-rose-500">✕</button>
                </div>

                <!-- Messages -->
                <div ref="scroller" class="relative flex-1 overflow-y-auto px-4 py-4">
                    <TransitionGroup name="msg" tag="div" class="space-y-1.5">
                        <template v-for="m in grouped" :key="m.id">
                            <div v-if="m.sep" class="my-3 flex justify-center">
                                <span class="rounded-full bg-white/80 dark:bg-slate-900/70 px-3 py-0.5 text-xs font-medium text-slate-400 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">{{ m.day }}</span>
                            </div>
                            <div v-else class="group flex items-end gap-2" :class="m.user_id === me?.id ? 'flex-row-reverse' : ''">
                                <Avatar v-if="m.user_id !== me?.id" :name="m.user_name" :src="m.user_avatar" :size="28" />
                                <div class="max-w-[72%]">
                                    <div :class="m.user_id === me?.id ? 'rounded-br-md bg-indigo-600 text-white' : 'rounded-bl-md bg-white dark:bg-slate-900/70 text-slate-800 dark:text-slate-200 ring-1 ring-slate-100 dark:ring-slate-800'"
                                        class="rounded-2xl px-3.5 py-2 text-sm shadow-sm">
                                        <!-- Цитата: ответ на сообщение -->
                                        <div v-if="m.reply_to" class="mb-1.5 rounded-lg border-l-2 px-2 py-1 text-xs"
                                            :class="m.user_id === me?.id ? 'border-white/50 bg-white/10' : 'border-indigo-300 bg-indigo-50/70'">
                                            <div class="font-semibold" :class="m.user_id === me?.id ? 'text-white' : 'text-indigo-600 dark:text-indigo-400'">{{ m.reply_to.user_name }}</div>
                                            <div class="truncate" :class="m.user_id === me?.id ? 'text-indigo-100' : 'text-slate-500 dark:text-slate-400'">{{ m.reply_to.message || $e('📎 вложение') }}</div>
                                        </div>
                                        <!-- Attachments -->
                                        <div v-for="(a, i) in m.attachments" :key="i" class="mb-1.5">
                                            <a v-if="a.is_image" :href="a.url" target="_blank" class="block overflow-hidden rounded-xl">
                                                <img :src="a.url" class="max-h-56 max-w-full rounded-xl object-cover" alt="" />
                                            </a>
                                            <a v-else :href="a.url" target="_blank" :download="a.name"
                                                :class="m.user_id === me?.id ? 'bg-white/15 hover:bg-white/25' : 'bg-slate-50 dark:bg-slate-800/50 hover:bg-slate-100 dark:hover:bg-slate-800/60'"
                                                class="flex items-center gap-2 rounded-xl px-2.5 py-2 transition-colors">
                                                <span class="text-xl">📄</span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-xs font-semibold" :class="m.user_id === me?.id ? 'text-white' : 'text-slate-700 dark:text-slate-300'">{{ a.name }}</span>
                                                    <span class="block text-xs" :class="m.user_id === me?.id ? 'text-indigo-200' : 'text-slate-400'">{{ fmtSize(a.size) }}</span>
                                                </span>
                                                <svg viewBox="0 0 24 24" class="h-4 w-4 flex-shrink-0" :class="m.user_id === me?.id ? 'text-indigo-200' : 'text-slate-400'" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14"/></svg>
                                            </a>
                                        </div>
                                        <div v-if="m.message" class="whitespace-pre-line break-words">{{ m.message }}</div>
                                        <div class="mt-0.5 flex items-center gap-1.5 text-xs" :class="m.user_id === me?.id ? 'justify-end text-indigo-200' : 'text-slate-400'">
                                            <span v-if="m.user_id !== me?.id && activeChat?.type !== 'personal'" class="font-semibold text-indigo-500 dark:text-indigo-400">{{ m.user_name }}</span>
                                            <span v-if="m.edited">{{ $e('изменено') }}</span>
                                            <span>{{ fmtTime(m.created_at) }}</span>
                                            <!-- Кто прочитал: ✓ отправлено, ✓✓ прочитано (имена — по наведению) -->
                                            <span v-if="m.user_id === me?.id"
                                                :title="readersFor(m).length ? $e('Прочитали: ') + readersFor(m).map((p) => p.name).join(', ') : $e('Ещё не прочитано')"
                                                class="cursor-default font-bold"
                                                :class="readersFor(m).length ? 'text-sky-300' : 'text-indigo-300'">
                                                {{ readersFor(m).length ? '✓✓' : '✓' }}<template v-if="activeChat?.type !== 'personal' && readersFor(m).length"> {{ readersFor(m).length }}</template>
                                            </span>
                                        </div>
                                    </div>
                                    <!-- Реакции-эмодзи под сообщением -->
                                    <div v-if="m.reactions && m.reactions.length" class="mt-1 flex flex-wrap gap-1" :class="m.user_id === me?.id ? 'justify-end' : ''">
                                        <button v-for="r in m.reactions" :key="r.emoji" @click="react(m, r.emoji)"
                                            class="flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-xs ring-1 transition-colors"
                                            :class="r.mine ? 'bg-indigo-50 dark:bg-indigo-500/10 ring-indigo-300' : 'bg-white dark:bg-slate-900/70 ring-slate-200 dark:ring-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/60'">
                                            <span>{{ r.emoji }}</span><span class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ r.count }}</span>
                                        </button>
                                    </div>
                                </div>
                                <!-- Действия наведением: реакция / ответить / изменить / закрепить / удалить -->
                                <div class="relative mb-1 hidden flex-shrink-0 items-center gap-0.5 group-hover:flex">
                                    <button @click="reactPickerFor = reactPickerFor === m.id ? null : m.id" :title="$e('Реакция')" class="flex h-6 w-6 items-center justify-center rounded-full text-slate-300 dark:text-slate-600 transition-colors hover:bg-amber-50 hover:text-amber-500">😊</button>
                                    <button @click="startReply(m)" :title="$e('Ответить')" class="flex h-6 w-6 items-center justify-center rounded-full text-slate-300 dark:text-slate-600 transition-colors hover:bg-indigo-50 hover:text-indigo-500">
                                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 17l-5-5 5-5M4 12h11a5 5 0 0 1 5 5v1"/></svg>
                                    </button>
                                    <button v-if="m.can_edit" @click="startEditMsg(m)" :title="$e('Изменить')" class="flex h-6 w-6 items-center justify-center rounded-full text-slate-300 dark:text-slate-600 transition-colors hover:bg-indigo-50 hover:text-indigo-500">
                                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    </button>
                                    <button v-if="canCreateGroup && activeChat?.type !== 'personal'" @click="pinMessage(m)" :title="$e('Закрепить')" class="flex h-6 w-6 items-center justify-center rounded-full text-slate-300 dark:text-slate-600 transition-colors hover:bg-indigo-50 hover:text-indigo-500">
                                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 17v5M9 3h6l-1 7 3 3H7l3-3-1-7Z"/></svg>
                                    </button>
                                    <button v-if="m.can_delete" @click="deleteMessage(m)" :title="$e('Удалить')" class="flex h-6 w-6 items-center justify-center rounded-full text-slate-300 dark:text-slate-600 transition-colors hover:bg-rose-50 hover:text-rose-500">
                                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>
                                    </button>
                                    <!-- Быстрый выбор реакции -->
                                    <div v-if="reactPickerFor === m.id" class="absolute -top-9 left-0 z-10 flex gap-0.5 rounded-full bg-white dark:bg-slate-900/70 px-1.5 py-1 shadow-lg ring-1 ring-slate-200 dark:ring-slate-800">
                                        <button v-for="e in REACTIONS" :key="e" @click="react(m, e)" class="rounded-full px-1 text-base transition-transform hover:scale-125">{{ e }}</button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </TransitionGroup>
                    <div v-if="activeChat && !messages.length" class="flex h-full flex-col items-center justify-center text-center text-sm text-slate-400">
                        <div class="mb-2 text-3xl">💬</div>{{ $e('Начните переписку — сообщений пока нет') }}
                    </div>
                    <div v-if="!activeChat" class="flex h-full items-center justify-center text-sm text-slate-400">{{ $e('Выберите чат слева') }}</div>
                </div>

                <!-- Composer -->
                <div v-if="activeChat" class="border-t border-slate-200 dark:border-slate-800/80 bg-white/80 dark:bg-slate-900/70 p-3 backdrop-blur">
                    <!-- Баннер ответа/редактирования -->
                    <div v-if="replyTo || editingMsg" class="mb-2 flex items-center gap-2 rounded-lg border-l-2 border-indigo-400 bg-indigo-50/70 px-3 py-1.5 text-xs">
                        <svg viewBox="0 0 24 24" class="h-4 w-4 flex-shrink-0 text-indigo-500 dark:text-indigo-400" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path v-if="editingMsg" d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                            <path v-else d="M9 17l-5-5 5-5M4 12h11a5 5 0 0 1 5 5v1"/>
                        </svg>
                        <div class="min-w-0 flex-1">
                            <div class="font-semibold text-indigo-600 dark:text-indigo-400">{{ editingMsg ? $e('Редактирование') : ($e('Ответ · ') + (replyTo?.user_name ?? '')) }}</div>
                            <div class="truncate text-slate-500 dark:text-slate-400">{{ (editingMsg?.message || replyTo?.message) || $e('📎 вложение') }}</div>
                        </div>
                        <button @click="editingMsg ? cancelEditMsg() : cancelReply()" class="flex-shrink-0 text-slate-400 hover:text-rose-500">✕</button>
                    </div>
                    <!-- Pending attachment chip -->
                    <div v-if="form.file" class="mb-2 flex items-center gap-2 rounded-lg border border-indigo-100 dark:border-indigo-500/30 bg-indigo-50/60 px-3 py-1.5 text-xs">
                        <span class="text-base">📎</span>
                        <span class="min-w-0 flex-1 truncate font-medium text-slate-700 dark:text-slate-300">{{ form.file.name }}</span>
                        <span class="flex-shrink-0 text-slate-400">{{ fmtSize(form.file.size) }}</span>
                        <button @click="form.file = null" class="flex-shrink-0 text-slate-400 hover:text-rose-500">✕</button>
                    </div>
                    <div v-if="form.progress" class="mb-2 h-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800/60">
                        <div class="h-1 bg-indigo-500 transition-all" :style="{ width: form.progress.percentage + '%' }"></div>
                    </div>
                    <div class="relative flex items-end gap-2 rounded-2xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 px-2 py-1.5 shadow-sm focus-within:border-indigo-300 focus-within:ring-2 focus-within:ring-indigo-100">
                        <button @click="showEmoji = !showEmoji" :title="$e('Эмодзи')" class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg text-lg text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800/60">😊</button>
                        <input ref="fileInput" type="file" class="hidden" @change="onFilePicked" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.gif,.webp,.zip,.rar,.txt,.csv" />
                        <button :title="$e('Прикрепить файл')" class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800/60" @click="pickFile">
                            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21.44 11.05 12.25 20.24a5 5 0 0 1-7.07-7.07l9.19-9.19a3 3 0 0 1 4.24 4.24l-9.2 9.19a1 1 0 0 1-1.41-1.41l8.48-8.49"/></svg>
                        </button>
                        <!-- Выпадающий список упоминаний @имя -->
                        <div v-if="mentionList.length" class="absolute bottom-full left-2 mb-2 w-64 overflow-hidden rounded-xl bg-white dark:bg-slate-900/70 shadow-lg ring-1 ring-slate-200 dark:ring-slate-800">
                            <button v-for="u in mentionList" :key="u.id" @click="pickMention(u)" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-indigo-50">
                                <Avatar :name="u.name" :src="u.avatar" :size="26" />
                                <span class="text-slate-700 dark:text-slate-300">{{ u.name }}</span>
                            </button>
                        </div>
                        <textarea ref="textarea" v-model="form.message" @keydown.enter="onEnter" @input="onComposerInput" rows="1" :placeholder="$e('Напишите сообщение…  (@ — упомянуть, Enter — отправить)')"
                            class="max-h-[140px] flex-1 resize-none border-0 bg-transparent py-2 text-sm text-slate-800 placeholder-slate-400 focus:ring-0"></textarea>
                        <button @click="send" :disabled="form.processing || (!form.message.trim() && !form.file)"
                            class="send-btn flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-sm transition-all hover:bg-indigo-700 disabled:opacity-40">
                            <svg v-if="!form.processing" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7Z"/></svg>
                            <svg v-else class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-30"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3"/></svg>
                        </button>

                        <!-- Emoji panel -->
                        <transition enter-active-class="transition duration-150" enter-from-class="opacity-0 translate-y-2" leave-active-class="transition duration-100" leave-to-class="opacity-0">
                            <div v-if="showEmoji" class="absolute bottom-14 left-0 grid grid-cols-8 gap-1 rounded-xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 p-2 shadow-lg">
                                <button v-for="e in emojis" :key="e" @click="addEmoji(e)" class="flex h-8 w-8 items-center justify-center rounded-lg text-lg hover:bg-slate-100 dark:hover:bg-slate-800/60">{{ e }}</button>
                            </div>
                        </transition>
                    </div>
                </div>
            </section>

            <!-- ============ RIGHT: info panel ============ -->
            <transition enter-active-class="transition-transform duration-300" enter-from-class="translate-x-full" leave-active-class="transition-transform duration-300" leave-to-class="translate-x-full">
                <aside v-if="infoOpen && activeChat" class="absolute inset-y-0 right-0 z-20 flex w-72 flex-col border-l border-slate-200 dark:border-slate-800/80 bg-white dark:bg-slate-900/70 shadow-xl lg:static lg:z-0 lg:shadow-none">
                    <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-800/80 px-4 py-3">
                        <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $e('Информация') }}</h3>
                        <button @click="infoOpen = false" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800/60">✕</button>
                    </div>
                    <div class="flex flex-col items-center border-b border-slate-100 dark:border-slate-800 px-4 py-5 text-center">
                        <span class="flex h-16 w-16 items-center justify-center overflow-hidden rounded-full text-xl font-bold text-white shadow-sm" :class="avatarColor(activeChat.name)">
                            <img v-if="activeChat.avatar" :src="activeChat.avatar" class="h-full w-full object-cover" alt="" />
                            <span v-else-if="activeChat.type === 'group' || activeChat.type === 'global'">#</span><template v-else>{{ initial(activeChat.name) }}</template>
                        </span>
                        <div class="mt-2 font-semibold text-slate-900 dark:text-slate-100">{{ activeChat.name }}</div>
                        <div class="text-xs text-slate-400">{{ typeLabel(activeChat) }}</div>
                        <p v-if="activeChat.description" class="mt-2 text-xs leading-relaxed text-slate-500 dark:text-slate-400">{{ activeChat.description }}</p>
                    </div>

                    <div class="flex border-b border-slate-100 dark:border-slate-800 text-xs">
                        <button v-for="tabItem in [{ k: 'members', l: $e('Участники') }, { k: 'files', l: $e('Вложения') }, { k: 'pinned', l: $e('Закреплённые') }]" :key="tabItem.k"
                            @click="infoTab = tabItem.k" :class="infoTab === tabItem.k ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-slate-400'"
                            class="flex-1 border-b-2 py-2 font-medium transition-colors">{{ tabItem.l }}</button>
                    </div>

                    <div class="flex-1 overflow-y-auto p-3">
                        <!-- Members -->
                        <div v-if="infoTab === 'members'">
                            <div v-if="activeChat.type === 'personal'" class="rounded-xl bg-slate-50 dark:bg-slate-800/50 p-3 text-sm">
                                <div class="font-semibold text-slate-800 dark:text-slate-200">{{ otherParticipant(activeChat)?.name ?? activeChat.name }}</div>
                                <div class="mt-1 text-xs text-slate-400">{{ $e('Личный контакт') }}</div>
                            </div>
                            <div v-else class="space-y-1">
                                <div v-for="p in activeChat.participants" :key="p.id" class="group/member flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-50 dark:hover:bg-slate-800/60">
                                    <Avatar :name="p.name" :src="p.avatar" :size="32" />
                                    <span class="text-sm text-slate-700 dark:text-slate-300">{{ p.name }}</span>
                                    <span v-if="p.id === me?.id" class="ml-auto text-xs text-slate-400">{{ $e('вы') }}</span>
                                    <button v-else-if="canManage(activeChat)" @click="removeMember(p)" :title="$e('Убрать из группы')"
                                        class="ml-auto hidden text-xs text-slate-300 dark:text-slate-600 hover:text-rose-500 group-hover/member:block">✕</button>
                                </div>
                                <div v-if="!activeChat.participants?.length" class="py-4 text-center text-xs text-slate-400">{{ $e('Нет участников') }}</div>

                                <!-- Добавить сотрудника в группу (admin/director) -->
                                <div v-if="canManage(activeChat)" class="mt-3 border-t border-slate-100 dark:border-slate-800 pt-3">
                                    <div class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $e('➕ Добавить участника') }}</div>
                                    <input v-model="memberSearch" :placeholder="$e('Поиск сотрудника…')"
                                        class="mb-1.5 w-full rounded-lg border-slate-200 py-1.5 text-xs shadow-sm focus:border-indigo-400 focus:ring-indigo-400" />
                                    <div class="max-h-44 space-y-0.5 overflow-y-auto">
                                        <button v-for="u in nonMembers" :key="u.id" @click="addMember(u)"
                                            class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm hover:bg-indigo-50">
                                            <Avatar :name="u.name" :src="u.avatar" :size="26" />
                                            <span class="min-w-0 flex-1 truncate text-slate-700 dark:text-slate-300">{{ u.name }}</span>
                                            <span class="flex-shrink-0 text-indigo-500 dark:text-indigo-400">+</span>
                                        </button>
                                        <div v-if="!nonMembers.length" class="py-2 text-center text-xs text-slate-400">{{ $e('Все уже в группе') }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Files -->
                        <div v-else-if="infoTab === 'files'">
                            <div v-if="!attachments.length" class="py-8 text-center text-xs text-slate-400"><div class="mb-1 text-2xl">📎</div>{{ $e('Вложений пока нет') }}</div>
                            <div v-else class="space-y-1.5">
                                <a v-for="(a, i) in attachments" :key="i" :href="a.url" target="_blank" :download="a.is_image ? null : a.name"
                                    class="flex items-center gap-2 rounded-lg border border-slate-100 dark:border-slate-800 p-1.5 transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/60">
                                    <span v-if="a.is_image" class="h-9 w-9 flex-shrink-0 overflow-hidden rounded-md">
                                        <img :src="a.url" class="h-full w-full object-cover" alt="" />
                                    </span>
                                    <span v-else class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-md bg-slate-100 dark:bg-slate-800/60 text-base">📄</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-xs font-medium text-slate-700 dark:text-slate-300">{{ a.name }}</span>
                                        <span class="block truncate text-xs text-slate-400">{{ fmtSize(a.size) }} · {{ a.author }}</span>
                                    </span>
                                </a>
                            </div>
                        </div>
                        <!-- Pinned -->
                        <div v-else class="py-8 text-center text-xs text-slate-400">
                            <div class="mb-1 text-2xl">📌</div>{{ $e('Нет закреплённых сообщений') }}
                        </div>
                    </div>

                    <!-- Group management (admin/director) -->
                    <div v-if="canManage(activeChat) || canDeleteChat(activeChat)" class="space-y-2 border-t border-slate-100 dark:border-slate-800 p-3">
                        <button v-if="canManage(activeChat)" @click="openEdit(activeChat)" class="flex w-full items-center justify-center gap-2 rounded-lg border border-slate-200 dark:border-slate-800/80 bg-white dark:bg-slate-900/70 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/60">
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                            {{ $e('Редактировать группу') }}
                        </button>
                        <button v-if="canDeleteChat(activeChat)" @click="removeChat(activeChat)" class="flex w-full items-center justify-center gap-2 rounded-lg border border-rose-200 bg-white dark:bg-slate-900/70 py-2 text-sm font-medium text-rose-600 dark:text-rose-400 transition-colors hover:bg-rose-50">
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>
                            {{ activeChat.type === 'group' ? $e('Удалить группу') : $e('Удалить чат (в корзину)') }}
                        </button>
                    </div>
                </aside>
            </transition>
        </div>

        <!-- ============ New chat / group modal ============ -->
        <Modal :show="showNew" @close="showNew = false" max-width="lg">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $e('Новый чат') }}</h2>

                <div class="mb-4 inline-flex rounded-lg border border-slate-200 dark:border-slate-800/80 bg-slate-50 dark:bg-slate-800/50 p-0.5 text-sm">
                    <button @click="newForm.type = 'personal'" :class="newForm.type === 'personal' ? 'bg-white dark:bg-slate-900/70 text-indigo-600 dark:text-indigo-400 shadow-sm' : 'text-slate-500 dark:text-slate-400'" class="rounded-md px-3 py-1 font-medium">{{ $e('Личный') }}</button>
                    <button v-if="canCreateGroup" @click="newForm.type = 'group'" :class="newForm.type === 'group' ? 'bg-white dark:bg-slate-900/70 text-indigo-600 dark:text-indigo-400 shadow-sm' : 'text-slate-500 dark:text-slate-400'" class="rounded-md px-3 py-1 font-medium">{{ $e('Группа') }}</button>
                </div>

                <div v-if="newForm.type === 'group'" class="mb-3 space-y-2">
                    <input v-model="newForm.name" :placeholder="$e('Название группы')" class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400" />
                    <div v-if="newForm.errors.name" class="text-xs text-red-600 dark:text-rose-400">{{ newForm.errors.name }}</div>
                    <textarea v-model="newForm.description" rows="2" :placeholder="$e('Описание группы (необязательно)')" class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400"></textarea>
                    <!-- Фирма группы: сотрудники видят только группы своей фирмы -->
                    <div v-if="companies.length > 1" class="flex items-center gap-1.5">
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ $e('Фирма:') }}</span>
                        <button type="button" @click="newForm.company_id = null"
                            :class="!newForm.company_id ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-slate-900/70 text-slate-600 dark:text-slate-300 ring-1 ring-slate-200 dark:ring-slate-800'"
                            class="rounded-full px-2.5 py-1 text-xs font-semibold">{{ $e('Обе') }}</button>
                        <button v-for="co in companies" :key="co.id" type="button" @click="newForm.company_id = co.id"
                            :class="newForm.company_id === co.id ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-slate-900/70 text-slate-600 dark:text-slate-300 ring-1 ring-slate-200 dark:ring-slate-800'"
                            class="rounded-full px-2.5 py-1 text-xs font-semibold">{{ co.name }}</button>
                    </div>
                </div>

                <div class="mb-1 text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Участники (') }}{{ newForm.participants.length }})</div>
                <input v-model="userSearch" :placeholder="$e('Поиск по имени…')" class="mb-2 w-full rounded-lg border-slate-200 py-1.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400" />
                <div class="max-h-56 space-y-0.5 overflow-y-auto rounded-lg border border-slate-100 dark:border-slate-800 p-1">
                    <label v-for="u in filteredUsers" :key="u.id" class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-800/60">
                        <input type="checkbox" :checked="newForm.participants.includes(u.id)" @change="toggleParticipant(u.id)" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                        <Avatar :name="u.name" :src="u.avatar" :size="28" />
                        <span class="text-slate-700 dark:text-slate-300">{{ u.name }}</span>
                    </label>
                    <div v-if="!filteredUsers.length" class="py-3 text-center text-xs text-slate-400">{{ $e('Нет сотрудников') }}</div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton @click="showNew = false">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="newForm.processing || (newForm.type === 'personal' && !newForm.participants.length)" @click="createChat">{{ $e('Создать') }}</PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- ============ Edit group modal ============ -->
        <Modal :show="showEdit" @close="showEdit = false" max-width="lg">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $e('Редактировать группу') }}</h2>

                <div class="mb-4 flex items-center gap-3">
                    <span class="flex h-16 w-16 flex-shrink-0 items-center justify-center overflow-hidden rounded-full text-xl font-bold text-white" :class="avatarColor(editForm.name)">
                        <img v-if="editPhoto || activeChat?.avatar" :src="editPhoto || activeChat?.avatar" class="h-full w-full object-cover" alt="" />
                        <template v-else>#</template>
                    </span>
                    <div>
                        <input ref="editPhotoInput" type="file" accept="image/*" class="hidden" @change="onEditPhoto" />
                        <button @click="editPhotoInput?.click()" class="rounded-lg border border-slate-200 dark:border-slate-800/80 px-3 py-1.5 text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800/60">{{ $e('Загрузить фото группы') }}</button>
                        <div v-if="editForm.errors.photo" class="mt-1 text-xs text-red-600 dark:text-rose-400">{{ editForm.errors.photo }}</div>
                    </div>
                </div>

                <div class="mb-3 space-y-2">
                    <input v-model="editForm.name" :placeholder="$e('Название группы')" class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400" />
                    <div v-if="editForm.errors.name" class="text-xs text-red-600 dark:text-rose-400">{{ editForm.errors.name }}</div>
                    <textarea v-model="editForm.description" rows="2" :placeholder="$e('Описание группы')" class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400"></textarea>
                    <div v-if="companies.length > 1" class="flex items-center gap-1.5">
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ $e('Фирма:') }}</span>
                        <button type="button" @click="editForm.company_id = null"
                            :class="!editForm.company_id ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-slate-900/70 text-slate-600 dark:text-slate-300 ring-1 ring-slate-200 dark:ring-slate-800'"
                            class="rounded-full px-2.5 py-1 text-xs font-semibold">{{ $e('Обе') }}</button>
                        <button v-for="co in companies" :key="co.id" type="button" @click="editForm.company_id = co.id"
                            :class="editForm.company_id === co.id ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-slate-900/70 text-slate-600 dark:text-slate-300 ring-1 ring-slate-200 dark:ring-slate-800'"
                            class="rounded-full px-2.5 py-1 text-xs font-semibold">{{ co.name }}</button>
                    </div>
                </div>

                <div class="mb-1 text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Участники (') }}{{ editForm.participants.length + 1 }})</div>
                <input v-model="userSearch" :placeholder="$e('Поиск по имени…')" class="mb-2 w-full rounded-lg border-slate-200 py-1.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400" />
                <div class="max-h-56 space-y-0.5 overflow-y-auto rounded-lg border border-slate-100 dark:border-slate-800 p-1">
                    <label v-for="u in filteredUsers" :key="u.id" class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-800/60">
                        <input type="checkbox" :checked="editForm.participants.includes(u.id)" @change="toggleEditParticipant(u.id)" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                        <Avatar :name="u.name" :src="u.avatar" :size="28" />
                        <span class="text-slate-700 dark:text-slate-300">{{ u.name }}</span>
                    </label>
                    <div v-if="!filteredUsers.length" class="py-3 text-center text-xs text-slate-400">{{ $e('Нет сотрудников') }}</div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton @click="showEdit = false">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="editForm.processing" @click="saveEdit">{{ $e('Сохранить') }}</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<style scoped>
/* Subtle parallax-free geometric background that stays readable */
.chat-bg {
    background-color: #f8fafc;
    background-image: radial-gradient(circle at 1px 1px, rgba(99, 102, 241, 0.06) 1px, transparent 0);
    background-size: 22px 22px;
}
/* Message bubble entrance */
.msg-enter-active { transition: all 0.28s cubic-bezier(0.16, 1, 0.3, 1); }
.msg-enter-from { opacity: 0; transform: translateY(10px) scale(0.96); }
.msg-move { transition: transform 0.28s; }
/* "+" rotate + send pop */
.new-btn:hover svg { transform: rotate(90deg); transition: transform 0.3s; }
.send-btn:not(:disabled):active { transform: scale(0.9); }
</style>

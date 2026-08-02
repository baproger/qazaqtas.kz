import { ref, onMounted, onUnmounted } from 'vue';
import { usePage } from '@inertiajs/vue3';

// ---- Глобальные оповещения чата (AppLayout): каждый сотрудник получает «дзынь» + браузерное
// уведомление о новом сообщении на ЛЮБОЙ странице ERP, а не только внутри чата.
// Состояние держим на уровне модуля: Inertia перемонтирует layout при каждом переходе,
// и без этого базовая линия непрочитанных сбрасывалась бы (ложные/потерянные сигналы).

const unreadTotal = ref(0);
let statePrev = null;
let fgTimer = null;
let bgTimer = null;
let mounts = 0;
let audioCtx = null;

// Тумблер 🔔 общий со страницей чата (localStorage chat_sound).
const soundOn = () => localStorage.getItem('chat_sound') !== 'off';

const ding = () => {
    if (!soundOn()) return;
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

const notifyBrowser = (title, body) => {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    try {
        const n = new Notification(title, { body, tag: 'qazaqtas-chat' });
        n.onclick = () => {
            window.focus();
            try { window.location.href = route('chat.index'); } catch (e) { window.location.href = '/chat'; }
            n.close();
        };
    } catch (e) { /* ignore */ }
};

// Разрешение на уведомления спрашиваем при первом клике (жест пользователя — иначе браузер молча откажет).
const askPermissionOnce = () => {
    window.removeEventListener('pointerdown', askPermissionOnce);
    if ('Notification' in window && Notification.permission === 'default') {
        try { Notification.requestPermission().then(() => ensurePush()); } catch (e) { /* старые браузеры */ }
    }
};

// ---- Web Push (как WhatsApp): работает при свёрнутом браузере и закрытой вкладке ----
// Регистрируем Service Worker и подписываем браузер на пуши; подписка уходит на
// сервер и там используется при каждом новом сообщении чата.
let vapidKey = '';
let pushEnsured = false;
const urlB64ToUint8 = (b64) => {
    const pad = '='.repeat((4 - (b64.length % 4)) % 4);
    const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
};
const ensurePush = async () => {
    if (pushEnsured || !vapidKey) return;
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    pushEnsured = true;
    try {
        const reg = await navigator.serviceWorker.register('/sw.js');
        const sub = (await reg.pushManager.getSubscription())
            ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToUint8(vapidKey) }));
        await window.axios.post(route('push.subscribe'), sub.toJSON());
    } catch (e) {
        pushEnsured = false; // повторим при следующем заходе
    }
};

const poll = async () => {
    // Страница чата поллит /chat/state сама (4с) и сама озвучивает — не дублируем.
    try { if (route().current('chat.*')) return; } catch (e) { /* ziggy ещё не готов */ }
    try {
        const { data } = await window.axios.get(route('chat.state'));
        const st = data.state || {};
        let total = 0;
        const alerts = [];
        for (const [id, s] of Object.entries(st)) {
            total += s.unread;
            if (statePrev && s.unread > (statePrev[id]?.unread ?? 0)) alerts.push(s);
        }
        unreadTotal.value = total;
        const isFirst = statePrev === null;
        statePrev = st;
        if (alerts.length && !isFirst) {
            ding();
            const a = alerts[0];
            notifyBrowser('💬 Новое сообщение в чате', a.last
                ? `${a.last.author ?? ''}: ${a.last.text}`
                : 'Есть непрочитанные сообщения');
        }
    } catch (e) {
        // Сессия истекла/разлогинен — останавливаем поллинг до следующей загрузки страницы.
        if ([401, 419].includes(e?.response?.status)) stop();
    }
};

const start = () => {
    poll();
    // Передний план — раз в 10с; фон — раз в 30с (сервер фоновые вкладки не грузим тяжелее).
    fgTimer = setInterval(() => { if (!document.hidden) poll(); }, 10000);
    bgTimer = setInterval(() => { if (document.hidden) poll(); }, 30000);
    window.addEventListener('pointerdown', askPermissionOnce);
};

const stop = () => {
    clearInterval(fgTimer); clearInterval(bgTimer);
    fgTimer = bgTimer = null;
    window.removeEventListener('pointerdown', askPermissionOnce);
};

export function useChatAlerts() {
    const page = usePage();
    onMounted(() => {
        vapidKey = page.props.vapidPublicKey || vapidKey;
        if (++mounts === 1) start();
        ensurePush(); // разрешение уже дано ранее — подписываем без ожидания клика
    });
    onUnmounted(() => { if (--mounts === 0) stop(); });
    return { chatUnread: unreadTotal };
}

// Страница чата (она поллит сама, 4с) синхронизирует сюда своё состояние:
// бейдж в меню живой, а после ухода со страницы нет повторного «дзыня» об уже озвученном.
export function syncChatState(st) {
    statePrev = st;
    unreadTotal.value = Object.values(st || {}).reduce((sum, s) => sum + (s.unread || 0), 0);
}

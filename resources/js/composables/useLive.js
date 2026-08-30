/**
 * Живые обновления без WebSocket и без нагрузки на сервер.
 *
 * Опрашиваем /live/version — одно чтение из кеша на сервере, 304 без тела,
 * если ничего не менялось. Интервал адаптивный: 30 с; каждая «тихая» проверка
 * удлиняет его в 1,5 раза до 2 мин; любое изменение или действие
 * пользователя (клик, клавиша, возврат на вкладку) возвращает к 30 с.
 * В скрытой вкладке — раз в 5 мин.
 *
 *   useLive({ tasks: ['tasks', 'counts'] })  — ключ штампа → props для reload
 */
import { onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';

const BASE = 30000, MAX = 120000, HIDDEN = 300000;

export function useLive(map) {
    let timer = null;
    let etag = null;
    let last = null;
    let interval = BASE;
    let stopped = false;

    const tick = async () => {
        if (stopped) return;
        try {
            const res = await fetch('/live/version', { headers: { Accept: 'application/json', ...(etag ? { 'If-None-Match': etag } : {}) }, credentials: 'same-origin' });
            if (res.status === 200) {
                etag = res.headers.get('ETag');
                const stamp = await res.json();
                if (last) {
                    const only = Object.entries(map).filter(([k]) => stamp[k] !== last[k]).flatMap(([, props]) => props);
                    if (only.length) { router.reload({ only: [...new Set(only)], preserveScroll: true }); interval = BASE; }
                }
                last = stamp;
            } else if (res.status === 304) {
                interval = Math.min(MAX, Math.round(interval * 1.5));
            }
        } catch { interval = MAX; /* сеть моргнула — не долбим сервер */ }
        schedule();
    };
    const schedule = () => { clearTimeout(timer); timer = setTimeout(tick, document.hidden ? HIDDEN : interval); };
    let lastActivity = 0;
    const onActivity = () => { const now = Date.now(); if (now - lastActivity > 5000) { lastActivity = now; interval = BASE; } };
    const onVisible = () => { if (!document.hidden) { interval = BASE; clearTimeout(timer); tick(); } };

    onMounted(() => {
        tick();
        document.addEventListener('visibilitychange', onVisible);
        ['click', 'keydown'].forEach((e) => document.addEventListener(e, onActivity, { passive: true }));
    });
    onUnmounted(() => {
        stopped = true; clearTimeout(timer);
        document.removeEventListener('visibilitychange', onVisible);
        ['click', 'keydown'].forEach((e) => document.removeEventListener(e, onActivity));
    });
}

/**
 * Живые обновления без WebSocket: опрашиваем /live/version (одна строка
 * JSON, 304 если ничего не менялось) раз в 10 с при открытой вкладке и раз в
 * 60 с при скрытой; при изменении штампа перезагружаем только нужные props.
 *
 *   useLive({ tasks: ['tasks', 'counts'] })  — ключ штампа → props для reload
 */
import { onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';

export function useLive(map, { active = 10000, idle = 60000 } = {}) {
    let timer = null;
    let etag = null;
    let last = null;
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
                    if (only.length) router.reload({ only: [...new Set(only)], preserveScroll: true });
                }
                last = stamp;
            }
        } catch { /* сеть моргнула — попробуем в следующий раз */ }
        schedule();
    };
    const schedule = () => { clearTimeout(timer); timer = setTimeout(tick, document.hidden ? idle : active); };
    const onVisible = () => { if (!document.hidden) { clearTimeout(timer); tick(); } };

    onMounted(() => { tick(); document.addEventListener('visibilitychange', onVisible); });
    onUnmounted(() => { stopped = true; clearTimeout(timer); document.removeEventListener('visibilitychange', onVisible); });
}

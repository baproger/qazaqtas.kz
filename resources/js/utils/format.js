const TZ = 'Asia/Almaty';

// «3д 4ч» / «2ч 15м» / «12м» — длительность этапа (тайминг цеха).
export function formatDuration(seconds) {
    const s = Math.max(0, Math.floor(seconds ?? 0));
    const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
    if (d > 0) return `${d}д ${h}ч`;
    if (h > 0) return `${h}ч ${m}м`;
    return `${m}м`;
}

export function formatDate(v) {
    if (!v) return '—';
    const d = new Date(v);
    if (isNaN(d)) return v;
    return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric', timeZone: TZ });
}

export function formatDateTime(v) {
    if (!v) return '—';
    const d = new Date(v);
    if (isNaN(d)) return v;
    return d.toLocaleString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: TZ });
}

export function money(v) {
    return new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';
}

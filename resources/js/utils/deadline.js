// 3-state deadline color: red if overdue, orange if due within 2 hours.
export function deadlineClass(value, done = false) {
    if (!value || done) return '';
    const d = new Date(value);
    if (isNaN(d)) return '';
    const now = Date.now();
    if (d.getTime() < now) return 'text-red-600 font-semibold';
    if (d.getTime() <= now + 2 * 60 * 60 * 1000) return 'text-orange-500 font-semibold';
    return '';
}

// Strictly past the deadline.
export function isPastDue(value, done = false) {
    if (!value || done) return false;
    const d = new Date(value);
    return !isNaN(d) && d.getTime() < Date.now();
}

// Due within 2 hours (but not yet past).
export function isDueSoon(value, done = false) {
    if (!value || done) return false;
    const d = new Date(value);
    if (isNaN(d)) return false;
    const now = Date.now();
    return d.getTime() >= now && d.getTime() <= now + 2 * 60 * 60 * 1000;
}

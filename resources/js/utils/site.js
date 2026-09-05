/** Утилиты витрины: деньги, числа, локальные списки (избранное, сравнение). */

const nf = new Intl.NumberFormat('ru-RU');

export const money = (value) => `${nf.format(Math.round(Number(value) || 0))} ₸`;

export const number = (value, digits = 2) => {
    const n = Number(value) || 0;
    return n % 1 === 0 ? nf.format(n) : nf.format(Number(n.toFixed(digits)));
};

/**
 * Локальные списки витрины: избранное, сравнение, недавно просмотренные.
 * Живут в localStorage — корзина и заказы остаются на сервере (ERP).
 */
const read = (key) => {
    try {
        const raw = localStorage.getItem(key);
        return Array.isArray(JSON.parse(raw)) ? JSON.parse(raw) : [];
    } catch {
        return [];
    }
};

const write = (key, list) => {
    try {
        localStorage.setItem(key, JSON.stringify(list));
    } catch {
        /* приватный режим — молча игнорируем */
    }
};

export const localList = (key, limit = 50) => ({
    all: () => read(key),
    has: (id) => read(key).includes(id),
    toggle(id) {
        const list = read(key);
        const next = list.includes(id) ? list.filter((x) => x !== id) : [id, ...list].slice(0, limit);
        write(key, next);
        return next;
    },
    push(id) {
        const next = [id, ...read(key).filter((x) => x !== id)].slice(0, limit);
        write(key, next);
        return next;
    },
    remove(id) {
        const next = read(key).filter((x) => x !== id);
        write(key, next);
        return next;
    },
    clear: () => write(key, []),
});

export const favorites = localList('qt.favorites');
export const recent = localList('qt.recent', 8);

/** Плавное появление секций: одна общая IntersectionObserver-обёртка. */
/**
 * Spotlight, следующий за курсором: пишет координаты в CSS-переменные
 * элемента, свечение рисует .spotlight::before (только hover-устройства).
 */
export const trackSpotlight = (e) => {
    const el = e.currentTarget;
    const r = el.getBoundingClientRect();
    el.style.setProperty('--spot-x', `${e.clientX - r.left}px`);
    el.style.setProperty('--spot-y', `${e.clientY - r.top}px`);
};

export const observeReveal = (root = document) => {
    const nodes = root.querySelectorAll('.reveal:not(.is-in)');
    if (!nodes.length) return () => {};

    if (!('IntersectionObserver' in window)) {
        nodes.forEach((n) => n.classList.add('is-in'));
        return () => {};
    }

    const io = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-in');
                    io.unobserve(entry.target);
                }
            });
        },
        { rootMargin: '0px 0px -12% 0px', threshold: 0.12 },
    );

    nodes.forEach((n) => io.observe(n));
    return () => io.disconnect();
};

/**
 * День и ночь витрины. Состояние вынесено из компонента: тему читает не
 * только шапка, но и 3D-сцена на главной — ей нужно перекрасить свет и
 * туман, иначе тёмный двор остаётся тёмным на светлой странице.
 */
import { ref } from 'vue';

export const theme = ref('dark');

export const applyTheme = (value) => {
    theme.value = value;
    document.documentElement.style.colorScheme = value;
    try {
        localStorage.setItem('qt.theme', value);
    } catch {
        /* приватный режим — просто не запоминаем */
    }
};

/**
 * Переключение с развёрткой от самой кнопки: новая тема «наливается» на
 * страницу мягким фронтом, а не подменяется мгновенно (растушёвку края
 * задаёт маска в site.css). Там, где View Transitions нет, тема меняется
 * сразу, а общий тон полотна перетекает переходом CSS.
 */
export const toggleTheme = (origin) => {
    const next = theme.value === 'dark' ? 'light' : 'dark';
    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

    if (!document.startViewTransition || reduced || !origin) {
        applyTheme(next);
        return;
    }

    // Клик с клавиатуры приходит без координат: развёртка тогда идёт от
    // самой кнопки, а не из левого верхнего угла экрана.
    const box = origin.currentTarget?.getBoundingClientRect?.();
    const x = origin.clientX || (box ? box.left + box.width / 2 : window.innerWidth);
    const y = origin.clientY || (box ? box.top + box.height / 2 : 0);
    const radius = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y));

    document.documentElement.style.setProperty('--vt-x', `${x}px`);
    document.documentElement.style.setProperty('--vt-y', `${y}px`);
    document.documentElement.style.setProperty('--vt-r', `${radius}px`);

    document.startViewTransition(() => applyTheme(next));
};

/** Сохранённый выбор, иначе системная настройка. */
export const initTheme = () => {
    let saved = null;
    try {
        saved = localStorage.getItem('qt.theme');
    } catch { /* приватный режим */ }
    applyTheme(saved ?? (window.matchMedia?.('(prefers-color-scheme: light)').matches ? 'light' : 'dark'));
};

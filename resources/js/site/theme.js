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

export const toggleTheme = () => applyTheme(theme.value === 'dark' ? 'light' : 'dark');

/** Сохранённый выбор, иначе системная настройка. */
export const initTheme = () => {
    let saved = null;
    try {
        saved = localStorage.getItem('qt.theme');
    } catch { /* приватный режим */ }
    applyTheme(saved ?? (window.matchMedia?.('(prefers-color-scheme: light)').matches ? 'light' : 'dark'));
};

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

let animTimer = null;

/**
 * Переключение темы — перелив цвета, без остановки страницы.
 *
 * Раньше здесь была View Transitions: браузер снимал кадр всей страницы и
 * проявлял поверх новый. Выглядело это как перезагрузка — на время перехода
 * сайт замирал, 3D-двор и карусель стояли, а потом резко оживали.
 *
 * Теперь тема меняется мгновенно, а плавность даёт CSS: полотно
 * кроссфейдится слоем .theme-canvas, остальные цвета перетекают, пока на
 * корне висит класс theme-anim. Ничего не замирает: сцена крутится,
 * карусель едет, видео играет.
 */
export const toggleTheme = () => {
    const next = theme.value === 'dark' ? 'light' : 'dark';
    const root = document.querySelector('.site');
    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

    if (root && !reduced) {
        root.classList.add('theme-anim');
        clearTimeout(animTimer);
        // Класс снимается сразу после перехода: держать transition на всех
        // элементах постоянно — значит платить за него на каждом ховере.
        animTimer = setTimeout(() => root.classList.remove('theme-anim'), 650);
    }

    applyTheme(next);
};

/** Сохранённый выбор, иначе системная настройка. */
export const initTheme = () => {
    let saved = null;
    try {
        saved = localStorage.getItem('qt.theme');
    } catch { /* приватный режим */ }
    applyTheme(saved ?? (window.matchMedia?.('(prefers-color-scheme: light)').matches ? 'light' : 'dark'));
};

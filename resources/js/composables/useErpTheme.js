import { ref } from 'vue';

/**
 * День и ночь ERP (Modern SaaS, dual-theme).
 *
 * Класс .dark живёт на <html> и включает dark:-утилиты Tailwind по всему
 * приложению. До первой отрисовки его ставит инлайн-скрипт в app.blade.php
 * (иначе тёмная тема мигала бы белым), здесь — переключатель для шапки.
 * Витрина сайта на этот класс не смотрит: у неё своя тема (.site[data-theme]).
 */
const dark = ref(typeof document !== 'undefined' && document.documentElement.classList.contains('dark'));

export function useErpTheme() {
    const toggle = () => {
        dark.value = !dark.value;
        document.documentElement.classList.toggle('dark', dark.value);
        try {
            localStorage.setItem('erp.theme', dark.value ? 'dark' : 'light');
        } catch { /* приватный режим — тема живёт до перезагрузки */ }
    };

    return { dark, toggle };
}

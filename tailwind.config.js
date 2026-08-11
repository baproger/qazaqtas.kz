import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import colors from 'tailwindcss/colors';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            // Design tokens: single accent + semantic aliases (see UI spec).
            colors: {
                primary: colors.indigo,   // accent #4F46E5 family
                // Витрина сайта: тёмная «бетонная» палитра + песочный акцент
                // мраморного композита. ERP остаётся на светлой primary-теме.
                // Значения живут в CSS-переменных (resources/css/app.css):
                // витрина переключает день/ночь одним атрибутом, а вся
                // существующая вёрстка (bg-ink-800, text-sand-50…) следует за
                // ними без правок. ERP эти токены не использует.
                ink: {
                    900: 'rgb(var(--ink-900) / <alpha-value>)',
                    800: 'rgb(var(--ink-800) / <alpha-value>)',
                    700: 'rgb(var(--ink-700) / <alpha-value>)',
                    600: 'rgb(var(--ink-600) / <alpha-value>)',
                    500: 'rgb(var(--ink-500) / <alpha-value>)',
                    400: 'rgb(var(--ink-400) / <alpha-value>)',
                },
                sand: {
                    50: 'rgb(var(--sand-50) / <alpha-value>)',
                    100: 'rgb(var(--sand-100) / <alpha-value>)',
                    200: 'rgb(var(--sand-200) / <alpha-value>)',
                    300: 'rgb(var(--sand-300) / <alpha-value>)',
                    400: 'rgb(var(--sand-400) / <alpha-value>)',
                    500: 'rgb(var(--sand-500) / <alpha-value>)',
                },
                success: colors.emerald,  // incomes / active
                danger: colors.rose,      // overdue / negative
                warning: colors.amber,    // warnings («Просрочено» banners etc.)
            },
            boxShadow: {
                card: '0 1px 3px rgba(0, 0, 0, 0.06)',
                glass: '0 24px 80px -32px rgba(0, 0, 0, 0.9)',
            },
            letterSpacing: {
                display: '-0.035em',
            },
            transitionTimingFunction: {
                premium: 'cubic-bezier(0.16, 1, 0.3, 1)',
            },
        },
    },

    plugins: [forms],
};

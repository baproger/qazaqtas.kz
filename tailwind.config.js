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
                ink: {
                    900: '#08090B',
                    800: '#0D0F12',
                    700: '#14171B',
                    600: '#1C2025',
                    500: '#272C33',
                    400: '#3A4048',
                },
                sand: {
                    50: '#F7F4EF',
                    100: '#EDE7DC',
                    200: '#DCD2C1',
                    300: '#C8B79A',
                    400: '#B49C77',
                    500: '#9A805B',
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

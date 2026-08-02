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
                success: colors.emerald,  // incomes / active
                danger: colors.rose,      // overdue / negative
                warning: colors.amber,    // warnings («Просрочено» banners etc.)
            },
            boxShadow: {
                card: '0 1px 3px rgba(0, 0, 0, 0.06)',
            },
        },
    },

    plugins: [forms],
};

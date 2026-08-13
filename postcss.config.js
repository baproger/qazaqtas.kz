export default {
    plugins: {
        // Раскрывает @import до того, как Tailwind начнёт разбирать файл:
        // иначе @apply и @layer в подключённых слоях остаются необработанными.
        'postcss-import': {},
        tailwindcss: {},
        autoprefixer: {},
    },
};

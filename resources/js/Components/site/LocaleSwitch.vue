<script setup>
import { computed } from 'vue';
import { useLocale } from '@/composables/useTranslations';

/**
 * Переключатель языка витрины.
 *
 * Язык живёт в адресе, поэтому это обычные ссылки на ту же страницу в другом
 * языке — сервер их и присылает в `i18n.alternates`. Переход намеренно
 * полный, а не через Inertia: атрибут `<html lang>` и теги hreflang ставит
 * blade-шаблон, и при частичном обновлении они остались бы от прошлого языка.
 */
const i18n = useLocale();

const options = computed(() =>
    i18n.available
        .filter((locale) => i18n.alternates[locale])
        .map((locale) => ({
            locale,
            href: i18n.alternates[locale],
            short: i18n.short[locale] ?? locale.toUpperCase(),
            name: i18n.names[locale] ?? locale,
            current: locale === i18n.locale,
        })),
);
</script>

<template>
    <div
        v-if="options.length > 1"
        class="flex items-center rounded-full border border-white/10 p-0.5"
        role="group"
        :aria-label="$t('site.a11y.language', 'Язык сайта')"
    >
        <a
            v-for="option in options"
            :key="option.locale"
            :href="option.href"
            :hreflang="option.locale"
            :lang="option.locale"
            :title="option.name"
            :aria-current="option.current ? 'true' : undefined"
            class="rounded-full px-2.5 py-1 text-xs font-semibold uppercase tracking-wide transition"
            :class="option.current
                ? 'bg-sand-300 text-ink-900'
                : 'text-sand-100/60 hover:text-sand-50'"
        >{{ option.short }}</a>
    </div>
</template>

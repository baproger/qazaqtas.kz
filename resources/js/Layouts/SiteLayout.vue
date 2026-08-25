<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { theme, toggleTheme, initTheme } from '@/site/theme';
import FloatingSocial from '@/Components/site/FloatingSocial.vue';
import LocaleSwitch from '@/Components/site/LocaleSwitch.vue';
import { useScrollAmbience } from '@/site/ambience';
import { restoreScroll } from '@/site/localeScroll';
import { useT } from '@/composables/useTranslations';

// Пункты меню собираются в script, поэтому переводчик нужен и здесь,
// не только глобальным $t в шаблоне.
const t = useT();

const props = defineProps({
    seo: { type: Object, default: () => ({}) },
    // Прозрачная шапка нужна только там, где под ней 3D-сцена (главная).
    transparentHeader: { type: Boolean, default: false },
});

const page = usePage();
const site = computed(() => page.props.site ?? {});
const contacts = computed(() => site.value.contacts ?? {});
const branches = computed(() => site.value.branches ?? []);
const cartCount = computed(() => site.value.cartCount ?? 0);

// Конфигуратор включается в ERP → Настройки: пока выключен, пункта нет
// ни в меню, ни в подвале, а сам маршрут отдаёт 404.
const nav = computed(() => [
    { label: t('site.nav.catalog'), route: 'site.catalog' },
    ...(site.value.configurator ? [{ label: t('site.nav.configurator'), route: 'site.configurator' }] : []),
    { label: t('site.nav.about'), route: 'site.about' },
    { label: t('site.nav.projects'), route: 'site.projects' },
    { label: t('site.nav.contacts'), route: 'site.contacts' },
]);


/** Фон меняет настроение по мере прокрутки. */
const siteRoot = ref(null);
useScrollAmbience(siteRoot);

const scrolled = ref(false);
const menuOpen = ref(false);
const onScroll = () => (scrolled.value = window.scrollY > 24);

onMounted(() => {
    initTheme();

    // Пришли со сменой языка — возвращаем посетителя туда, где он читал.
    restoreScroll();

    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
});
onBeforeUnmount(() => window.removeEventListener('scroll', onScroll));

// Мобильное меню не должно оставлять страницу заблокированной при переходе.
watch(menuOpen, (open) => {
    document.documentElement.style.overflow = open ? 'hidden' : '';
});
watch(() => page.url, () => (menuOpen.value = false));

const whatsappHref = computed(() => `https://wa.me/${contacts.value.whatsapp ?? ''}`);
const telHref = computed(() => `tel:${String(contacts.value.phone ?? '').replace(/[^\d+]/g, '')}`);
</script>

<template>
    <Head>
        <title>{{ seo.title ?? 'QAZAQ TAS' }}</title>
        <meta name="description" :content="seo.description ?? ''" />
        <meta property="og:title" :content="seo.title ?? 'QAZAQ TAS'" />
        <meta property="og:description" :content="seo.description ?? ''" />
        <meta property="og:type" content="website" />
        <meta name="theme-color" :content="theme === 'dark' ? '#08090B' : '#FAF8F5'" />
    </Head>

    <div ref="siteRoot" class="site min-h-screen" :data-theme="theme">
        <!-- Свет за содержимым: три слоя перетекают друг в друга при прокрутке -->
        <div class="site-ambience" aria-hidden="true">
            <!-- Дневное полотно поверх ночного: при смене темы оно
                 проявляется, и фон перетекает вместо мгновенной подмены. -->
            <i class="theme-canvas" />
            <i class="amb amb-warm" />
            <i class="amb amb-cool" />
            <i class="amb amb-deep" />
        </div>

        <a href="#content" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[60] focus:rounded-full focus:bg-sand-300 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-ink-900">
            {{ $t('site.a11y.skip') }}
        </a>

        <!-- Шапка: стекло появляется при скролле, над 3D — прозрачная -->
        <header
            class="fixed inset-x-0 top-0 z-50 transition duration-500 ease-premium"
            :class="scrolled || !transparentHeader ? 'site-bar' : ''"
        >
            <div class="mx-auto flex h-16 max-w-7xl items-center gap-6 px-5 sm:h-20 sm:px-8">
                <!-- Логотип уже содержит название — отдельной подписи рядом нет. -->
                <!-- Надпись в логотипе светло-серая: на дневном фоне она
                     теряется, поэтому там он живёт на тёмной плашке. -->
                <Link
                    :href="$r('site.home')"
                    class="flex items-center transition"
                    :class="theme === 'light' ? 'logo-chip rounded-xl px-3 py-2' : ''"
                    :aria-label="$t('site.a11y.home')"
                >
                    <img
                        src="/logo-qazaqtas.png"
                        alt="QAZAQ TAS"
                        width="696"
                        height="141"
                        class="h-8 w-auto transition-opacity duration-300 hover:opacity-80 sm:h-10"
                    />
                </Link>

                <nav class="ml-auto hidden items-center gap-8 lg:flex" :aria-label="$t('site.a11y.main_nav')">
                    <Link
                        v-for="item in nav"
                        :key="item.route"
                        :href="$r(item.route)"
                        class="relative text-sm text-sand-100/75 transition hover:text-sand-50"
                        :class="{ 'text-sand-50': $rIs(item.route) }"
                    >
                        {{ item.label }}
                        <span
                            v-if="$rIs(item.route)"
                            class="absolute -bottom-1.5 left-0 h-px w-full bg-sand-300"
                        />
                    </Link>
                </nav>

                <div class="ml-auto flex items-center gap-2 lg:ml-0">
                    <a :href="telHref" class="hidden text-sm font-medium text-sand-50 transition hover:text-sand-300 xl:block">
                        {{ contacts.phone }}
                    </a>

                    <LocaleSwitch class="hidden sm:flex" />

                    <button
                        class="grid h-10 w-10 place-items-center rounded-full border border-white/10 text-sand-50 transition hover:border-sand-300/60 hover:bg-white/5"
                        :aria-label="theme === 'dark' ? $t('site.a11y.theme_light') : $t('site.a11y.theme_dark')"
                        :title="theme === 'dark' ? $t('site.theme.light') : $t('site.theme.dark')"
                        @click="toggleTheme($event)"
                    >
                        <!-- Солнце и луна не подменяются, а переворачиваются
                             навстречу друг другу — переключение читается
                             как одно движение. -->
                        <span class="theme-icon" :class="theme === 'light' ? 'is-night' : ''">
                            <svg class="theme-icon-sun h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                                <circle cx="12" cy="12" r="4" />
                                <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
                            </svg>
                            <svg class="theme-icon-moon h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" />
                            </svg>
                        </span>
                    </button>

                    <Link
                        :href="$r('site.cart')"
                        class="relative grid h-10 w-10 place-items-center rounded-full border border-white/10 text-sand-50 transition hover:border-sand-300/60 hover:bg-white/5"
                        :aria-label="$t('site.a11y.cart')"
                    >
                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 4h2l2.4 11.2A2 2 0 0 0 9.35 17h8.4a2 2 0 0 0 1.95-1.55L21 8H6" />
                            <circle cx="10" cy="20" r="1.2" /><circle cx="18" cy="20" r="1.2" />
                        </svg>
                        <span
                            v-if="cartCount"
                            class="absolute -right-1 -top-1 grid h-5 min-w-5 place-items-center rounded-full bg-sand-300 px-1 text-[11px] font-bold text-ink-900"
                        >{{ cartCount }}</span>
                    </Link>

                    <button
                        class="grid h-10 w-10 place-items-center rounded-full border border-white/10 text-sand-50 transition hover:border-sand-300/60 lg:hidden"
                        :aria-expanded="menuOpen"
                        :aria-label="$t('site.a11y.menu')"
                        @click="menuOpen = !menuOpen"
                    >
                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                            <path v-if="!menuOpen" d="M4 7h16M4 12h16M4 17h16" />
                            <path v-else d="M6 6l12 12M18 6L6 18" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Мобильное меню -->
            <Transition
                enter-active-class="transition duration-300 ease-premium"
                enter-from-class="opacity-0 -translate-y-2"
                leave-active-class="transition duration-200"
                leave-to-class="opacity-0"
            >
                <div v-if="menuOpen" class="site-bar relative lg:hidden">
                    <nav class="mx-auto max-w-7xl px-5 py-6 sm:px-8">
                        <Link
                            v-for="item in nav"
                            :key="item.route"
                            :href="$r(item.route)"
                            class="block border-b border-white/5 py-4 text-2xl display text-sand-50"
                        >{{ item.label }}</Link>
                        <div class="mt-6 flex flex-col gap-3">
                            <a :href="telHref" class="btn-ghost">{{ contacts.phone }}</a>
                            <a :href="whatsappHref" target="_blank" rel="noopener" class="btn-whatsapp"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.86 9.86 0 0 0 12.04 2Zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.23 8.25-8.23 2.2 0 4.27.86 5.83 2.41a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.78.97-.15.16-.29.18-.53.06-.25-.13-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.71-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.44.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.44-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.42-.14-.01-.3-.01-.47-.01-.16 0-.43.06-.66.31-.22.24-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.68-1.18.2-.58.2-1.08.15-1.18-.06-.11-.22-.17-.47-.29Z" /></svg>{{ $t('site.cta.whatsapp') }}</a>
                            <!-- На узком экране переключатель живёт в меню: в шапке он
                                 отнимал бы место у корзины и кнопки меню. -->
                            <LocaleSwitch class="self-start sm:hidden" />
                        </div>
                    </nav>
                </div>
            </Transition>
        </header>

        <main id="content" :class="transparentHeader ? '' : 'pt-16 sm:pt-20'">
            <slot />
        </main>

        <!-- Подвал -->
        <!-- Связь под рукой на каждой странице витрины -->
        <FloatingSocial />

        <footer class="site-footer">
            <div class="mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-20">
                <div class="grid gap-12 lg:grid-cols-[1.2fr_1fr_1fr]">
                    <div>
                        <p class="display text-3xl text-sand-50 sm:text-4xl">{{ $t('site.footer.tagline_top') }}<br />{{ $t('site.footer.tagline_bottom') }}</p>
                        <p class="mt-5 max-w-md text-sm leading-relaxed text-sand-100/60">
                            {{ $t('site.footer.lead') }}
                        </p>
                        <div class="mt-7 flex flex-wrap gap-3">
                            <a :href="whatsappHref" target="_blank" rel="noopener" class="btn-whatsapp"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.86 9.86 0 0 0 12.04 2Zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.23 8.25-8.23 2.2 0 4.27.86 5.83 2.41a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.78.97-.15.16-.29.18-.53.06-.25-.13-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.71-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.44.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.44-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.42-.14-.01-.3-.01-.47-.01-.16 0-.43.06-.66.31-.22.24-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.68-1.18.2-.58.2-1.08.15-1.18-.06-.11-.22-.17-.47-.29Z" /></svg>WhatsApp</a>
                            <a :href="telHref" class="btn-ghost">{{ contacts.phone }}</a>
                        </div>
                    </div>

                    <div>
                        <p class="eyebrow">{{ $t('site.footer.nav') }}</p>
                        <ul class="mt-5 space-y-3 text-sm">
                            <li v-for="item in nav" :key="item.route">
                                <Link :href="$r(item.route)" class="text-sand-100/70 transition hover:text-sand-50">{{ item.label }}</Link>
                            </li>
                        </ul>
                    </div>

                    <div>
                        <p class="eyebrow">{{ $t('site.footer.production') }}</p>
                        <ul class="mt-5 space-y-4 text-sm">
                            <li v-for="b in branches" :key="b.city">
                                <p class="font-medium text-sand-50">{{ b.city }}</p>
                                <p class="text-sand-100/50">{{ b.address }}</p>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="divider-top mt-14 flex flex-col gap-4 pt-8 text-xs text-sand-100/40 sm:flex-row sm:items-center sm:justify-between">
                    <p>© {{ new Date().getFullYear() }} QAZAQ TAS · {{ $t('site.footer.copyright') }}</p>
                    <p>{{ contacts.hours }} · {{ contacts.email }}</p>
                </div>
            </div>
        </footer>
    </div>
</template>

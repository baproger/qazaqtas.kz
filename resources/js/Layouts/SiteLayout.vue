<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { theme, toggleTheme, initTheme } from '@/site/theme';
import FloatingSocial from '@/Components/site/FloatingSocial.vue';
import { useScrollAmbience } from '@/site/ambience';

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
    { label: 'Каталог', route: 'site.catalog' },
    ...(site.value.configurator ? [{ label: 'Конфигуратор', route: 'site.configurator' }] : []),
    { label: 'Завод', route: 'site.about' },
    { label: 'Проекты', route: 'site.projects' },
    { label: 'Контакты', route: 'site.contacts' },
]);


/** Фон меняет настроение по мере прокрутки. */
const siteRoot = ref(null);
useScrollAmbience(siteRoot);

const scrolled = ref(false);
const menuOpen = ref(false);
const onScroll = () => (scrolled.value = window.scrollY > 24);

onMounted(() => {
    initTheme();

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
            <i class="amb amb-warm" />
            <i class="amb amb-cool" />
            <i class="amb amb-deep" />
        </div>

        <a href="#content" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[60] focus:rounded-full focus:bg-sand-300 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-ink-900">
            К содержимому
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
                    :href="route('site.home')"
                    class="flex items-center transition"
                    :class="theme === 'light' ? 'logo-chip rounded-xl px-3 py-2' : ''"
                    aria-label="QAZAQ TAS — на главную"
                >
                    <img
                        src="/logo-qazaqtas.png"
                        alt="QAZAQ TAS"
                        width="696"
                        height="141"
                        class="h-8 w-auto transition-opacity duration-300 hover:opacity-80 sm:h-10"
                    />
                </Link>

                <nav class="ml-auto hidden items-center gap-8 lg:flex" aria-label="Основная навигация">
                    <Link
                        v-for="item in nav"
                        :key="item.route"
                        :href="route(item.route)"
                        class="relative text-sm text-sand-100/75 transition hover:text-sand-50"
                        :class="{ 'text-sand-50': route().current(item.route) }"
                    >
                        {{ item.label }}
                        <span
                            v-if="route().current(item.route)"
                            class="absolute -bottom-1.5 left-0 h-px w-full bg-sand-300"
                        />
                    </Link>
                </nav>

                <div class="ml-auto flex items-center gap-2 lg:ml-0">
                    <a :href="telHref" class="hidden text-sm font-medium text-sand-50 transition hover:text-sand-300 xl:block">
                        {{ contacts.phone }}
                    </a>

                    <button
                        class="grid h-10 w-10 place-items-center rounded-full border border-white/10 text-sand-50 transition hover:border-sand-300/60 hover:bg-white/5"
                        :aria-label="theme === 'dark' ? 'Включить светлую тему' : 'Включить тёмную тему'"
                        :title="theme === 'dark' ? 'Светлая тема' : 'Тёмная тема'"
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
                        :href="route('site.cart')"
                        class="relative grid h-10 w-10 place-items-center rounded-full border border-white/10 text-sand-50 transition hover:border-sand-300/60 hover:bg-white/5"
                        aria-label="Корзина"
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
                        aria-label="Меню"
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
                            :href="route(item.route)"
                            class="block border-b border-white/5 py-4 text-2xl display text-sand-50"
                        >{{ item.label }}</Link>
                        <div class="mt-6 flex flex-col gap-3">
                            <a :href="telHref" class="btn-ghost">{{ contacts.phone }}</a>
                            <a :href="whatsappHref" target="_blank" rel="noopener" class="btn-sand">Написать в WhatsApp</a>
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
                        <p class="display text-3xl text-sand-50 sm:text-4xl">Мраморный композит<br />для города</p>
                        <p class="mt-5 max-w-md text-sm leading-relaxed text-sand-100/60">
                            Тротуарная плитка, бордюры и малые архитектурные формы собственного
                            производства. Три площадки в Казахстане, отгрузка по всей стране.
                        </p>
                        <div class="mt-7 flex flex-wrap gap-3">
                            <a :href="whatsappHref" target="_blank" rel="noopener" class="btn-sand">WhatsApp</a>
                            <a :href="telHref" class="btn-ghost">{{ contacts.phone }}</a>
                        </div>
                    </div>

                    <div>
                        <p class="eyebrow">Навигация</p>
                        <ul class="mt-5 space-y-3 text-sm">
                            <li v-for="item in nav" :key="item.route">
                                <Link :href="route(item.route)" class="text-sand-100/70 transition hover:text-sand-50">{{ item.label }}</Link>
                            </li>
                        </ul>
                    </div>

                    <div>
                        <p class="eyebrow">Производство</p>
                        <ul class="mt-5 space-y-4 text-sm">
                            <li v-for="b in branches" :key="b.city">
                                <p class="font-medium text-sand-50">{{ b.city }}</p>
                                <p class="text-sand-100/50">{{ b.address }}</p>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="divider-top mt-14 flex flex-col gap-4 pt-8 text-xs text-sand-100/40 sm:flex-row sm:items-center sm:justify-between">
                    <p>© {{ new Date().getFullYear() }} QAZAQ TAS · Производство изделий из мраморного композита</p>
                    <p>{{ contacts.hours }} · {{ contacts.email }}</p>
                </div>
            </div>
        </footer>
    </div>
</template>

<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import ProductCard from '@/Components/site/ProductCard.vue';
import CategoryNav from '@/Components/site/CategoryNav.vue';
import { favorites, compare, recent, money, observeReveal } from '@/utils/site';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    products: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    bounds: { type: Object, default: () => ({ min: 0, max: 0 }) },
    currentCategory: { type: Object, default: null },
    seo: { type: Object, default: () => ({}) },
});

const search = ref(props.filters.search ?? '');
const sort = ref(props.filters.sort ?? 'popular');
const min = ref(props.filters.min ?? '');
const max = ref(props.filters.max ?? '');
const favIds = ref([]);
const compareIds = ref([]);
const recentProducts = ref([]);
const filtersOpen = ref(false);
let stopReveal = () => {};
let searchTimer = null;

const sorts = [
    { key: 'popular', label: 'Популярные' },
    { key: 'price_asc', label: 'Сначала дешевле' },
    { key: 'price_desc', label: 'Сначала дороже' },
    { key: 'name', label: 'По названию' },
];

const apply = (extra = {}) => {
    router.get(route('site.catalog'), {
        category: props.filters.category || undefined,
        search: search.value || undefined,
        sort: sort.value !== 'popular' ? sort.value : undefined,
        min: min.value || undefined,
        max: max.value || undefined,
        ...extra,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(apply, 350);
});
watch([sort, min, max], () => apply());

const reset = () => {
    search.value = '';
    sort.value = 'popular';
    min.value = '';
    max.value = '';
    router.get(route('site.catalog'), {}, { preserveScroll: true });
};

const toggleFavorite = (id) => (favIds.value = favorites.toggle(id));
const toggleCompare = (id) => (compareIds.value = compare.toggle(id));

const compareProducts = computed(() => props.products.data.filter((p) => compareIds.value.includes(p.id)));

/**
 * Пагинатор Laravel отдаёт подписи с HTML-сущностями («&laquo; Previous»).
 * Разбираем их здесь, чтобы шаблон выводил номера обычной интерполяцией, а
 * стрелки рисовал иконками: v-html на витрине не остаётся вовсе.
 */
const pageLinks = computed(() => props.products.links.map((link, i) => {
    const previous = i === 0;
    const next = i === props.products.links.length - 1;
    return {
        key: `${i}-${link.label}`,
        url: link.url,
        active: link.active,
        arrow: previous ? 'prev' : next ? 'next' : null,
        label: link.label,
        aria: previous ? 'Предыдущая страница' : next ? 'Следующая страница' : `Страница ${link.label}`,
    };
}));

onMounted(async () => {
    stopReveal = observeReveal();
    favIds.value = favorites.all();
    compareIds.value = compare.all();

    // Недавно просмотренные подтягиваем из ERP по id из localStorage.
    const ids = recent.all();
    if (ids.length) {
        try {
            const res = await fetch(`${route('site.recent')}?ids=${ids.join(',')}`, { headers: { Accept: 'application/json' } });
            recentProducts.value = res.ok ? await res.json() : [];
        } catch {
            recentProducts.value = [];
        }
    }
});

onBeforeUnmount(() => stopReveal());
</script>

<template>
    <SiteLayout :seo="seo">
        <!-- Шапка раздела -->
        <section class="band band-hero">
            <div class="mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-24">
                <nav class="flex items-center gap-2 text-xs text-sand-100/40" aria-label="Хлебные крошки">
                    <Link :href="route('site.home')" class="transition hover:text-sand-300">Главная</Link>
                    <span>/</span>
                    <Link :href="route('site.catalog')" class="transition hover:text-sand-300">Каталог</Link>
                    <template v-if="currentCategory">
                        <span>/</span><span class="text-sand-100/70">{{ currentCategory.name }}</span>
                    </template>
                </nav>

                <h1 class="display mt-6 max-w-3xl text-[clamp(2.25rem,6vw,4.5rem)] text-sand-50">
                    {{ currentCategory?.name ?? 'Каталог изделий' }}
                </h1>
                <p class="mt-5 max-w-2xl text-sm leading-relaxed text-sand-100/55 sm:text-base">
                    {{ currentCategory?.description ?? 'Тротуарная плитка, бордюры, вазоны, скамьи, урны и облицовка из мраморного композита собственного производства.' }}
                </p>
            </div>
        </section>

        <!-- Категории: плавающая панель, а не строка ссылок -->
        <div class="sticky top-16 z-30 px-5 py-4 sm:top-20 sm:px-8">
            <div class="mx-auto max-w-7xl">
                <CategoryNav :categories="categories" :current="filters.category ?? ''" />
            </div>
        </div>

        <section class="ambient mx-auto max-w-7xl px-5 py-12 sm:px-8 sm:py-16">
            <div class="grid gap-10 lg:grid-cols-[260px_1fr]">
                <!-- Фильтры -->
                <aside class="lg:sticky lg:top-40 lg:self-start">
                    <button
                        class="btn-ghost w-full lg:hidden"
                        :aria-expanded="filtersOpen"
                        @click="filtersOpen = !filtersOpen"
                    >{{ filtersOpen ? 'Скрыть фильтры' : 'Фильтры и поиск' }}</button>

                    <div :class="['card mt-4 space-y-8 p-6 lg:mt-0 lg:block', filtersOpen ? 'block' : 'hidden']">
                        <div>
                            <label for="q" class="eyebrow">Поиск</label>
                            <input
                                id="q"
                                v-model="search"
                                type="search"
                                placeholder="Плитка, бордюр, вазон…"
                                class="mt-3 w-full rounded-xl border-white/12 bg-white/[0.04] px-4 py-3 text-sm text-sand-50 placeholder:text-sand-100/30 focus:border-sand-300 focus:ring-0"
                            />
                        </div>

                        <div>
                            <p class="eyebrow">Цена, ₸</p>
                            <div class="mt-3 flex items-center gap-2">
                                <input v-model="min" type="number" :placeholder="String(Math.round(bounds.min))" class="w-full rounded-xl border-white/12 bg-white/[0.04] px-3 py-2.5 text-sm text-sand-50 focus:border-sand-300 focus:ring-0" />
                                <span class="text-sand-100/30">—</span>
                                <input v-model="max" type="number" :placeholder="String(Math.round(bounds.max))" class="w-full rounded-xl border-white/12 bg-white/[0.04] px-3 py-2.5 text-sm text-sand-50 focus:border-sand-300 focus:ring-0" />
                            </div>
                        </div>

                        <div>
                            <p class="eyebrow">Сортировка</p>
                            <div class="mt-3 space-y-1.5">
                                <button
                                    v-for="s in sorts"
                                    :key="s.key"
                                    class="block w-full rounded-lg px-3 py-2 text-left text-sm transition"
                                    :class="sort === s.key ? 'bg-white/[0.07] text-sand-50' : 'text-sand-100/55 hover:text-sand-50'"
                                    @click="sort = s.key"
                                >{{ s.label }}</button>
                            </div>
                        </div>

                        <button class="text-sm text-sand-300 underline-offset-4 hover:underline" @click="reset">Сбросить всё</button>
                    </div>
                </aside>

                <!-- Товары -->
                <div>
                    <div class="mb-6 flex items-center justify-between text-sm text-sand-100/45">
                        <p>Найдено: {{ products.total }}</p>
                        <button v-if="compareIds.length" class="text-sand-300 hover:underline" @click="compareIds = compare.clear() ?? []">
                            Очистить сравнение ({{ compareIds.length }})
                        </button>
                    </div>

                    <div v-if="products.data.length" class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                        <div v-for="p in products.data" :key="p.id" class="reveal flex flex-col">
                            <ProductCard
                                :product="p"
                                :favorite="favIds.includes(p.id)"
                                @favorite="toggleFavorite"
                            />
                            <button
                                class="mx-auto mt-2 block text-[11px] uppercase tracking-[0.18em] transition"
                                :class="compareIds.includes(p.id) ? 'text-sand-300' : 'text-sand-100/35 hover:text-sand-100/70'"
                                @click="toggleCompare(p.id)"
                            >{{ compareIds.includes(p.id) ? '✓ в сравнении' : 'сравнить' }}</button>
                        </div>
                    </div>

                    <div v-else class="card px-8 py-20 text-center">
                        <p class="display text-2xl text-sand-50">Ничего не нашлось</p>
                        <p class="mt-3 text-sm text-sand-100/50">Попробуйте изменить фильтры или сбросить их.</p>
                        <button class="btn-ghost mt-8" @click="reset">Сбросить фильтры</button>
                    </div>

                    <!-- Пагинация -->
                    <nav v-if="products.last_page > 1" class="mt-12 flex flex-wrap justify-center gap-2">
                        <Link
                            v-for="link in pageLinks"
                            :key="link.key"
                            :href="link.url ?? ''"
                            preserve-scroll
                            class="grid h-10 min-w-10 place-items-center rounded-xl border px-3 text-center text-sm transition"
                            :class="[
                                link.active ? 'border-sand-300 bg-sand-300 text-ink-900' : 'border-white/12 text-sand-100/60 hover:border-sand-300/50',
                                !link.url && 'pointer-events-none opacity-30',
                            ]"
                            :aria-label="link.aria"
                        >
                            <svg v-if="link.arrow" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path :d="link.arrow === 'prev' ? 'M19 12H5M11 18l-6-6 6-6' : 'M5 12h14M13 6l6 6-6 6'" />
                            </svg>
                            <template v-else>{{ link.label }}</template>
                        </Link>
                    </nav>

                    <!-- Сравнение -->
                    <section v-if="compareProducts.length > 1" class="mt-20">
                        <h2 class="display text-2xl text-sand-50">Сравнение</h2>
                        <div class="card mt-6 overflow-x-auto">
                            <table class="w-full min-w-[560px] text-sm">
                                <thead class="bg-white/[0.04] text-left text-xs uppercase tracking-wider text-sand-100/40">
                                    <tr>
                                        <th class="px-5 py-3">Позиция</th>
                                        <th class="px-5 py-3">Цена</th>
                                        <th class="px-5 py-3">Размер</th>
                                        <th class="px-5 py-3">Морозостойкость</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/5">
                                    <tr v-for="p in compareProducts" :key="p.id">
                                        <td class="px-5 py-4 text-sand-50">{{ p.name }}</td>
                                        <td class="px-5 py-4 text-sand-300">{{ money(p.price) }} / {{ p.unit }}</td>
                                        <td class="px-5 py-4 text-sand-100/60">{{ p.specs?.size ?? '—' }}</td>
                                        <td class="px-5 py-4 text-sand-100/60">{{ p.specs?.frost ?? '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- Недавно смотрели -->
                    <section v-if="recentProducts.length" class="mt-20">
                        <h2 class="display text-2xl text-sand-50">Вы недавно смотрели</h2>
                        <div class="mt-6 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                            <ProductCard v-for="p in recentProducts.slice(0, 3)" :key="p.id" :product="p" compact />
                        </div>
                    </section>
                </div>
            </div>
        </section>
    </SiteLayout>
</template>

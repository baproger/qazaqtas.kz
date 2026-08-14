<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import { money, number } from '@/utils/site';
import { useT, useSiteRoute } from '@/composables/useTranslations';

const t = useT();
const { siteRoute } = useSiteRoute();

const props = defineProps({
    collections: { type: Array, default: () => [] },
    patterns: { type: Array, default: () => [] },
    borders: { type: Array, default: () => [] },
    accessories: { type: Array, default: () => [] },
    seo: { type: Object, default: () => ({}) },
});

const canvas = ref(null);
const loading = ref(true);
let scene = null;
let parseTileSize = null;

const collection = ref(props.collections[0] ?? null);
const color = ref(collection.value?.colors?.[0] ?? { name: t('site.configurator.default_color'), hex: '#E8E6E1' });
const pattern = ref(props.patterns[0]?.key ?? 'running');
const width = ref(8);
const length = ref(10);
const withCurb = ref(true);
const border = ref(props.borders[0] ?? null);
const extras = ref({}); // id → количество

const area = computed(() => Math.max(1, Number(width.value) || 0) * Math.max(1, Number(length.value) || 0));
const perimeter = computed(() => 2 * ((Number(width.value) || 0) + (Number(length.value) || 0)));
const activePattern = computed(() => props.patterns.find((p) => p.key === pattern.value) ?? props.patterns[0]);

/** Плитка с запасом на подрезку — коэффициент зависит от раскладки. */
const paving = computed(() => {
    if (!collection.value) return null;
    const waste = 1 + (activePattern.value?.waste ?? 5) / 100;
    const quantity = area.value * waste;
    return {
        quantity,
        pieces: collection.value.pieces_per_m2 ? Math.ceil(quantity * collection.value.pieces_per_m2) : null,
        sum: quantity * collection.value.price,
    };
});

const curb = computed(() => {
    if (!withCurb.value || !border.value) return null;
    const quantity = perimeter.value;
    return { quantity, sum: quantity * Number(border.value.price) };
});

const extrasList = computed(() =>
    props.accessories
        .map((a) => ({ ...a, quantity: Number(extras.value[a.id] || 0) }))
        .filter((a) => a.quantity > 0),
);

const total = computed(() =>
    (paving.value?.sum ?? 0) + (curb.value?.sum ?? 0)
    + extrasList.value.reduce((sum, e) => sum + e.quantity * Number(e.price), 0),
);

/** Позиции, которые уйдут в корзину/WhatsApp — одна структура на оба сценария. */
const items = computed(() => {
    const list = [];
    if (collection.value && paving.value) {
        list.push({
            product_id: collection.value.id,
            name: collection.value.name,
            unit: collection.value.unit,
            color: color.value?.name ?? null,
            quantity: Number(paving.value.quantity.toFixed(2)),
        });
    }
    if (curb.value && border.value) {
        list.push({
            product_id: border.value.id,
            name: border.value.name,
            unit: border.value.unit,
            color: color.value?.name ?? null,
            quantity: Number(curb.value.quantity.toFixed(2)),
        });
    }
    extrasList.value.forEach((e) => list.push({
        product_id: e.id, name: e.name, unit: e.unit, color: null, quantity: e.quantity,
    }));
    return list;
});

const rebuild = () => {
    if (!scene || !collection.value || !parseTileSize) return;
    scene.build({
        tileSize: parseTileSize(collection.value.size),
        pattern: pattern.value,
        area: { width: Number(width.value) || 1, length: Number(length.value) || 1 },
        color: color.value?.hex ?? '#C8B79A',
        withCurb: withCurb.value,
        // Фото поверхности из карточки — сцена показывает реальный материал.
        texture: collection.value.texture ?? null,
        curbTexture: border.value?.texture_path ?? null,
    });
};

watch([collection, color, pattern, width, length, withCurb, border], rebuild);
watch(collection, (c) => {
    if (c?.colors?.length) color.value = c.colors[0];
});

const addToCart = () => {
    if (!items.value.length) return;
    router.post(siteRoute('site.cart.addMany'), { items: items.value }, { preserveScroll: true });
};

const whatsappHref = computed(() => {
    const phone = usePage().props.site?.contacts?.whatsapp ?? '';
    const lines = [
        t('site.configurator.wa_intro'),
        `${t('site.configurator.wa_area')}: ${number(area.value)} м² (${width.value} × ${length.value} м)`,
        `${t('site.configurator.wa_pattern')}: ${activePattern.value?.name ?? '—'}, ${t('site.configurator.wa_color')}: ${color.value?.name ?? '—'}`,
        ...items.value.map((i) => `• ${i.name} — ${number(i.quantity)} ${i.unit}`),
        `${t('site.configurator.wa_total')}: ${money(total.value)}`,
    ];
    return `https://wa.me/${phone}?text=${encodeURIComponent(lines.join('\n'))}`;
});

onMounted(async () => {
    if (!canvas.value) return;
    const module = await import('@/site/configuratorScene');
    parseTileSize = module.parseTileSize;
    scene = module.createConfiguratorScene(canvas.value);
    rebuild();
    loading.value = false;
});

onBeforeUnmount(() => scene?.dispose());
</script>

<template>
    <SiteLayout :seo="seo">
        <section class="mx-auto max-w-7xl px-5 py-12 sm:px-8 sm:py-16">
            <p class="eyebrow">{{ $t('site.nav.configurator') }}</p>
            <h1 class="display mt-5 max-w-3xl text-[clamp(2rem,5.5vw,4rem)] text-sand-50">
                {{ $t('site.configurator.title') }}
            </h1>
            <p class="mt-5 max-w-2xl text-sm leading-relaxed text-sand-100/55 sm:text-base">
                {{ $t('site.configurator.lead') }}
            </p>

            <div class="mt-10 grid gap-6 lg:grid-cols-[1fr_380px]">
                <!-- 3D-сцена -->
                <div class="card relative min-h-[420px] overflow-hidden lg:min-h-[620px]">
                    <canvas ref="canvas" class="h-full w-full cursor-grab active:cursor-grabbing" />

                    <div v-if="loading" class="absolute inset-0 grid place-items-center">
                        <p class="text-sm text-sand-100/40">{{ $t('site.configurator.loading') }}</p>
                    </div>

                    <div class="pointer-events-none absolute left-5 top-5 rounded-full border border-white/10 bg-ink-900/70 px-4 py-2 text-xs text-sand-100/60 backdrop-blur">
                        {{ number(area) }} м² · {{ activePattern?.name }}
                        <span v-if="collection?.texture" class="ml-1 text-sand-300">· {{ $t('site.configurator.photo_texture') }}</span>
                    </div>

                    <button
                        class="absolute bottom-5 right-5 rounded-full border border-white/15 bg-ink-900/70 px-4 py-2 text-xs text-sand-100/70 backdrop-blur transition hover:border-sand-300/60"
                        @click="scene?.resetView()"
                    >{{ $t('site.configurator.reset_view') }}</button>

                    <p class="pointer-events-none absolute bottom-5 left-5 text-[11px] text-sand-100/35">
                        {{ $t('site.configurator.controls_hint') }}
                    </p>
                </div>

                <!-- Панель управления -->
                <aside class="space-y-6">
                    <!-- Коллекция -->
                    <div class="card p-5 sm:p-6">
                        <p class="eyebrow">{{ $t('site.configurator.collection') }}</p>
                        <div class="mt-4 space-y-2">
                            <button
                                v-for="c in collections"
                                :key="c.id"
                                class="flex w-full items-center justify-between gap-3 rounded-xl border px-4 py-3 text-left transition"
                                :class="collection?.id === c.id ? 'border-sand-300 bg-white/[0.06]' : 'border-white/10 hover:border-white/25'"
                                @click="collection = c"
                            >
                                <span>
                                    <span class="block text-sm text-sand-50">{{ c.name }}</span>
                                    <span class="block text-xs text-sand-100/40">{{ c.size }}</span>
                                </span>
                                <span class="whitespace-nowrap text-sm text-sand-300">{{ money(c.price) }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Цвет -->
                    <div v-if="collection?.colors?.length" class="card p-5 sm:p-6">
                        <p class="eyebrow">{{ $t('site.product.color') }} · {{ color?.name }}</p>
                        <div class="mt-4 flex flex-wrap gap-2.5">
                            <button
                                v-for="c in collection.colors"
                                :key="c.hex"
                                class="h-10 w-10 rounded-full border-2 transition"
                                :class="color?.hex === c.hex ? 'border-sand-300 scale-110' : 'border-white/15 hover:border-white/40'"
                                :style="{ background: c.hex }"
                                :aria-label="c.name"
                                @click="color = c"
                            />
                        </div>
                    </div>

                    <!-- Раскладка -->
                    <div class="card p-5 sm:p-6">
                        <p class="eyebrow">{{ $t('site.configurator.pattern') }}</p>
                        <div class="mt-4 grid grid-cols-2 gap-2">
                            <button
                                v-for="p in patterns"
                                :key="p.key"
                                class="rounded-xl border px-3 py-3 text-left transition"
                                :class="pattern === p.key ? 'border-sand-300 bg-white/[0.06]' : 'border-white/10 hover:border-white/25'"
                                :title="p.hint"
                                @click="pattern = p.key"
                            >
                                <span class="block text-sm text-sand-50">{{ p.name }}</span>
                                <span class="block text-[11px] text-sand-100/40">{{ $t('site.configurator.waste', null, { percent: p.waste }) }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Размеры -->
                    <div class="card p-5 sm:p-6">
                        <p class="eyebrow">{{ $t('site.configurator.size') }}</p>
                        <div class="mt-4 flex items-center gap-3">
                            <label class="flex-1">
                                <span class="text-xs text-sand-100/45">{{ $t('site.configurator.width') }}</span>
                                <input v-model="width" type="number" min="1" max="60" class="mt-1.5 w-full rounded-xl border-white/12 bg-white/[0.04] px-3 py-2.5 text-sand-50 focus:border-sand-300 focus:ring-0" />
                            </label>
                            <label class="flex-1">
                                <span class="text-xs text-sand-100/45">{{ $t('site.configurator.length') }}</span>
                                <input v-model="length" type="number" min="1" max="60" class="mt-1.5 w-full rounded-xl border-white/12 bg-white/[0.04] px-3 py-2.5 text-sand-50 focus:border-sand-300 focus:ring-0" />
                            </label>
                        </div>

                        <label class="mt-4 flex cursor-pointer items-center gap-3 text-sm text-sand-100/70">
                            <input v-model="withCurb" type="checkbox" class="rounded border-white/20 bg-transparent text-sand-300 focus:ring-sand-300" />
                            Обрамить бордюром ({{ number(perimeter) }} п.м.)
                        </label>

                        <select
                            v-if="withCurb && borders.length"
                            v-model="border"
                            class="mt-3 w-full rounded-xl border-white/12 bg-white/[0.04] px-3 py-2.5 text-sm text-sand-50 focus:border-sand-300 focus:ring-0"
                        >
                            <option v-for="b in borders" :key="b.id" :value="b" class="bg-ink-800">{{ b.name }} — {{ money(b.price) }}</option>
                        </select>
                    </div>

                    <!-- Малые формы -->
                    <div v-if="accessories.length" class="card p-5 sm:p-6">
                        <p class="eyebrow">{{ $t('site.configurator.extras') }}</p>
                        <div class="mt-4 space-y-3">
                            <div v-for="a in accessories" :key="a.id" class="flex items-center gap-3">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm text-sand-50">{{ a.name }}</span>
                                    <span class="block text-xs text-sand-100/40">{{ money(a.price) }} / {{ a.unit }}</span>
                                </span>
                                <input
                                    v-model.number="extras[a.id]"
                                    type="number"
                                    min="0"
                                    class="w-20 rounded-lg border-white/12 bg-white/[0.04] px-2 py-2 text-center text-sm text-sand-50 focus:border-sand-300 focus:ring-0"
                                    placeholder="0"
                                />
                            </div>
                        </div>
                    </div>
                </aside>
            </div>

            <!-- Смета -->
            <div class="card mt-6 p-6 sm:p-8">
                <div class="grid gap-8 lg:grid-cols-[1fr_320px]">
                    <div>
                        <p class="eyebrow">{{ $t('site.configurator.estimate') }}</p>
                        <table class="mt-5 w-full text-sm">
                            <tbody class="divide-y divide-white/8">
                                <tr v-if="paving">
                                    <td class="py-3 text-sand-100/60">
                                        {{ collection?.name }}
                                        <span class="block text-xs text-sand-100/35">
                                            {{ number(area) }} м² + {{ $t('site.configurator.waste', null, { percent: activePattern?.waste }) }}
                                            <template v-if="paving.pieces"> · ≈ {{ number(paving.pieces, 0) }} {{ $t('site.common.pcs') }}</template>
                                        </span>
                                    </td>
                                    <td class="py-3 text-right text-sand-50">{{ number(paving.quantity) }} м²</td>
                                    <td class="py-3 text-right font-semibold text-sand-50">{{ money(paving.sum) }}</td>
                                </tr>
                                <tr v-if="curb">
                                    <td class="py-3 text-sand-100/60">
                                        {{ border?.name }}
                                        <span class="block text-xs text-sand-100/35">{{ $t('site.configurator.perimeter') }}</span>
                                    </td>
                                    <td class="py-3 text-right text-sand-50">{{ number(curb.quantity) }} п.м.</td>
                                    <td class="py-3 text-right font-semibold text-sand-50">{{ money(curb.sum) }}</td>
                                </tr>
                                <tr v-for="e in extrasList" :key="e.id">
                                    <td class="py-3 text-sand-100/60">{{ e.name }}</td>
                                    <td class="py-3 text-right text-sand-50">{{ number(e.quantity) }} {{ e.unit }}</td>
                                    <td class="py-3 text-right font-semibold text-sand-50">{{ money(e.quantity * e.price) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="mt-4 text-[11px] leading-relaxed text-sand-100/35">
                            Расчёт предварительный: цены и характеристики берутся из каталога,
                            точный объём подтверждает менеджер после замера объекта.
                        </p>
                    </div>

                    <div class="flex flex-col justify-between gap-6">
                        <div>
                            <p class="text-sand-100/60">{{ $t('site.configurator.total') }}</p>
                            <p class="display mt-2 text-4xl text-sand-50">{{ money(total) }}</p>
                        </div>
                        <div class="space-y-3">
                            <button class="btn-cart w-full" :disabled="!items.length" @click="addToCart">
                                {{ $t('site.configurator.add_to_cart') }}
                                <svg class="btn-cart-arrow h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M5 12h14M13 6l6 6-6 6" />
                                </svg>
                            </button>
                            <a :href="whatsappHref" target="_blank" rel="noopener" class="btn-whatsapp w-full"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.86 9.86 0 0 0 12.04 2Zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.23 8.25-8.23 2.2 0 4.27.86 5.83 2.41a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.78.97-.15.16-.29.18-.53.06-.25-.13-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.71-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.44.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.44-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.42-.14-.01-.3-.01-.47-.01-.16 0-.43.06-.66.31-.22.24-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.68-1.18.2-.58.2-1.08.15-1.18-.06-.11-.22-.17-.47-.29Z" /></svg>{{ $t('site.configurator.send_whatsapp') }}</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </SiteLayout>
</template>

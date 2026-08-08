<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import { money, number } from '@/utils/site';

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
const color = ref(collection.value?.colors?.[0] ?? { name: 'Мрамор белый', hex: '#E8E6E1' });
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
    });
};

watch([collection, color, pattern, width, length, withCurb], rebuild);
watch(collection, (c) => {
    if (c?.colors?.length) color.value = c.colors[0];
});

const addToCart = () => {
    if (!items.value.length) return;
    router.post(route('site.cart.addMany'), { items: items.value }, { preserveScroll: true });
};

const whatsappHref = computed(() => {
    const phone = usePage().props.site?.contacts?.whatsapp ?? '';
    const lines = [
        'Здравствуйте! Рассчитал двор в конфигураторе QAZAQ TAS:',
        `Площадь: ${number(area.value)} м² (${width.value} × ${length.value} м)`,
        `Раскладка: ${activePattern.value?.name ?? '—'}, цвет: ${color.value?.name ?? '—'}`,
        ...items.value.map((i) => `• ${i.name} — ${number(i.quantity)} ${i.unit}`),
        `Примерная стоимость: ${money(total.value)}`,
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
            <p class="eyebrow">Конфигуратор</p>
            <h1 class="display mt-5 max-w-3xl text-[clamp(2rem,5.5vw,4rem)] text-sand-50">
                Соберите двор и получите точный расчёт
            </h1>
            <p class="mt-5 max-w-2xl text-sm leading-relaxed text-sand-100/55 sm:text-base">
                Выберите коллекцию, цвет и раскладку, задайте размеры участка — сцена
                пересоберётся, а количество и стоимость посчитаются по данным каталога.
            </p>

            <div class="mt-10 grid gap-6 lg:grid-cols-[1fr_380px]">
                <!-- 3D-сцена -->
                <div class="relative min-h-[420px] overflow-hidden rounded-3xl border border-white/10 bg-ink-800/50 lg:min-h-[620px]">
                    <canvas ref="canvas" class="h-full w-full cursor-grab active:cursor-grabbing" />

                    <div v-if="loading" class="absolute inset-0 grid place-items-center">
                        <p class="text-sm text-sand-100/40">Загружаем сцену…</p>
                    </div>

                    <div class="pointer-events-none absolute left-5 top-5 rounded-full border border-white/10 bg-ink-900/70 px-4 py-2 text-xs text-sand-100/60 backdrop-blur">
                        {{ number(area) }} м² · {{ activePattern?.name }}
                    </div>

                    <button
                        class="absolute bottom-5 right-5 rounded-full border border-white/15 bg-ink-900/70 px-4 py-2 text-xs text-sand-100/70 backdrop-blur transition hover:border-sand-300/60"
                        @click="scene?.resetView()"
                    >Сбросить вид</button>

                    <p class="pointer-events-none absolute bottom-5 left-5 text-[11px] text-sand-100/35">
                        Вращение — перетаскиванием, масштаб — колесом
                    </p>
                </div>

                <!-- Панель управления -->
                <aside class="space-y-6">
                    <!-- Коллекция -->
                    <div class="glass rounded-3xl p-5 sm:p-6">
                        <p class="eyebrow">Коллекция плитки</p>
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
                    <div v-if="collection?.colors?.length" class="glass rounded-3xl p-5 sm:p-6">
                        <p class="eyebrow">Цвет · {{ color?.name }}</p>
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
                    <div class="glass rounded-3xl p-5 sm:p-6">
                        <p class="eyebrow">Раскладка</p>
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
                                <span class="block text-[11px] text-sand-100/40">запас {{ p.waste }} %</span>
                            </button>
                        </div>
                    </div>

                    <!-- Размеры -->
                    <div class="glass rounded-3xl p-5 sm:p-6">
                        <p class="eyebrow">Размеры участка, м</p>
                        <div class="mt-4 flex items-center gap-3">
                            <label class="flex-1">
                                <span class="text-xs text-sand-100/45">Ширина</span>
                                <input v-model="width" type="number" min="1" max="60" class="mt-1.5 w-full rounded-xl border-white/12 bg-white/[0.04] px-3 py-2.5 text-sand-50 focus:border-sand-300 focus:ring-0" />
                            </label>
                            <label class="flex-1">
                                <span class="text-xs text-sand-100/45">Длина</span>
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
                    <div v-if="accessories.length" class="glass rounded-3xl p-5 sm:p-6">
                        <p class="eyebrow">Добавить малые формы</p>
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
            <div class="glass-strong mt-6 rounded-3xl p-6 sm:p-8">
                <div class="grid gap-8 lg:grid-cols-[1fr_320px]">
                    <div>
                        <p class="eyebrow">Расчёт</p>
                        <table class="mt-5 w-full text-sm">
                            <tbody class="divide-y divide-white/8">
                                <tr v-if="paving">
                                    <td class="py-3 text-sand-100/60">
                                        {{ collection?.name }}
                                        <span class="block text-xs text-sand-100/35">
                                            {{ number(area) }} м² + запас {{ activePattern?.waste }} %
                                            <template v-if="paving.pieces"> · ≈ {{ number(paving.pieces, 0) }} шт</template>
                                        </span>
                                    </td>
                                    <td class="py-3 text-right text-sand-50">{{ number(paving.quantity) }} м²</td>
                                    <td class="py-3 text-right font-semibold text-sand-50">{{ money(paving.sum) }}</td>
                                </tr>
                                <tr v-if="curb">
                                    <td class="py-3 text-sand-100/60">
                                        {{ border?.name }}
                                        <span class="block text-xs text-sand-100/35">периметр участка</span>
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
                            <p class="text-sand-100/60">Итого материалов</p>
                            <p class="display mt-2 text-4xl text-sand-50">{{ money(total) }}</p>
                        </div>
                        <div class="space-y-3">
                            <button class="btn-sand w-full" :disabled="!items.length" @click="addToCart">Добавить в корзину</button>
                            <a :href="whatsappHref" target="_blank" rel="noopener" class="btn-ghost w-full">Отправить в WhatsApp</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </SiteLayout>
</template>

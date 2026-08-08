<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import ProductCard from '@/Components/site/ProductCard.vue';
import { money, number } from '@/utils/site';

const props = defineProps({
    cart: { type: Object, required: true },
    whatsapp: { type: String, default: '' },
    recommended: { type: Array, default: () => [] },
    delivery: { type: Array, default: () => [] },
    seo: { type: Object, default: () => ({}) },
});

const city = ref(props.delivery[0]?.city ?? '');
const distance = ref(0);

/** Прикидка доставки: база города + километраж, бесплатно от суммы. */
const deliveryCost = computed(() => {
    const rate = props.delivery.find((d) => d.city === city.value);
    if (!rate) return null;
    if (props.cart.total >= Number(rate.free_from)) return 0;
    return Number(rate.base) + Number(rate.per_km) * Math.max(0, Number(distance.value) || 0);
});

const grandTotal = computed(() => props.cart.total + (deliveryCost.value ?? 0));

const setQuantity = (key, value) => {
    router.patch(route('site.cart.update'), { key, quantity: Number(value) }, { preserveScroll: true });
};

const removeItem = (key) => router.delete(route('site.cart.remove'), { data: { key }, preserveScroll: true });
const clearCart = () => router.delete(route('site.cart.clear'), { preserveScroll: true });
</script>

<template>
    <SiteLayout :seo="seo">
        <section class="mx-auto max-w-7xl px-5 py-14 sm:px-8 sm:py-20">
            <h1 class="display text-[clamp(2rem,5vw,3.5rem)] text-sand-50">Корзина</h1>

            <div v-if="!cart.items.length" class="mt-12 rounded-3xl border border-white/10 bg-ink-800/50 px-8 py-20 text-center">
                <p class="display text-2xl text-sand-50">Пока пусто</p>
                <p class="mt-3 text-sm text-sand-100/50">Загляните в каталог или соберите двор в конфигураторе.</p>
                <div class="mt-8 flex flex-wrap justify-center gap-3">
                    <Link :href="route('site.catalog')" class="btn-sand">В каталог</Link>
                    <Link :href="route('site.configurator')" class="btn-ghost">Конфигуратор</Link>
                </div>
            </div>

            <div v-else class="mt-10 grid gap-8 lg:grid-cols-[1fr_380px]">
                <!-- Позиции -->
                <div class="space-y-3">
                    <article
                        v-for="item in cart.items"
                        :key="item.key"
                        class="flex flex-wrap items-center gap-5 rounded-2xl border border-white/10 bg-ink-800/50 p-5"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-sand-100/40">{{ item.category }}</p>
                            <Link :href="route('site.product', item.slug)" class="mt-1 block truncate text-base font-medium text-sand-50 transition hover:text-sand-300">
                                {{ item.name }}
                            </Link>
                            <p class="mt-1 text-xs text-sand-100/45">
                                {{ money(item.price) }} / {{ item.unit }}
                                <span v-if="item.color"> · {{ item.color }}</span>
                            </p>
                        </div>

                        <div class="flex items-center rounded-full border border-white/12">
                            <button class="h-10 w-10 text-sand-100/70 transition hover:text-sand-50" aria-label="Меньше" @click="setQuantity(item.key, Math.max(0, item.quantity - 1))">−</button>
                            <input
                                :value="item.quantity"
                                type="number"
                                min="0"
                                step="0.5"
                                class="h-10 w-20 border-0 bg-transparent text-center text-sm text-sand-50 focus:ring-0"
                                @change="setQuantity(item.key, $event.target.value)"
                            />
                            <button class="h-10 w-10 text-sand-100/70 transition hover:text-sand-50" aria-label="Больше" @click="setQuantity(item.key, item.quantity + 1)">+</button>
                        </div>

                        <p class="w-32 text-right text-base font-semibold text-sand-50">{{ money(item.sum) }}</p>

                        <button class="text-sand-100/30 transition hover:text-rose-400" aria-label="Удалить" @click="removeItem(item.key)">✕</button>
                    </article>

                    <div class="flex justify-between pt-2">
                        <Link :href="route('site.catalog')" class="text-sm text-sand-300 underline-offset-4 hover:underline">← Продолжить покупки</Link>
                        <button class="text-sm text-sand-100/40 transition hover:text-rose-400" @click="clearCart">Очистить корзину</button>
                    </div>
                </div>

                <!-- Итоги -->
                <aside class="lg:sticky lg:top-28 lg:self-start">
                    <div class="glass-strong rounded-3xl p-6 sm:p-7">
                        <p class="eyebrow">Итого</p>

                        <div class="mt-5 space-y-3 text-sm">
                            <div class="flex justify-between text-sand-100/60">
                                <span>Материалы</span><b class="text-sand-50">{{ money(cart.total) }}</b>
                            </div>

                            <div class="border-t border-white/10 pt-4">
                                <label class="text-xs text-sand-100/45">Доставка в город</label>
                                <select v-model="city" class="mt-1.5 w-full rounded-xl border-white/12 bg-white/[0.04] px-3 py-2.5 text-sm text-sand-50 focus:border-sand-300 focus:ring-0">
                                    <option v-for="d in delivery" :key="d.city" :value="d.city" class="bg-ink-800">{{ d.city }}</option>
                                </select>

                                <label class="mt-3 block text-xs text-sand-100/45">Расстояние за городом, км</label>
                                <input v-model="distance" type="number" min="0" class="mt-1.5 w-full rounded-xl border-white/12 bg-white/[0.04] px-3 py-2.5 text-sm text-sand-50 focus:border-sand-300 focus:ring-0" />

                                <div class="mt-3 flex justify-between text-sand-100/60">
                                    <span>Доставка</span>
                                    <b class="text-sand-50">{{ deliveryCost === 0 ? 'бесплатно' : money(deliveryCost ?? 0) }}</b>
                                </div>
                            </div>

                            <div class="flex items-baseline justify-between border-t border-white/10 pt-4">
                                <span class="text-sand-100/60">К оплате</span>
                                <b class="display text-3xl text-sand-50">{{ money(grandTotal) }}</b>
                            </div>
                        </div>

                        <Link :href="route('site.checkout')" class="btn-sand mt-6 w-full">Оформить заказ</Link>
                        <a :href="whatsapp" target="_blank" rel="noopener" class="btn-ghost mt-3 w-full">Заказать в WhatsApp</a>

                        <p class="mt-4 text-[11px] leading-relaxed text-sand-100/35">
                            Стоимость доставки предварительная — менеджер подтвердит её после расчёта объёма и адреса.
                        </p>
                    </div>
                </aside>
            </div>

            <section v-if="recommended.length" class="mt-24">
                <h2 class="display text-2xl text-sand-50 sm:text-3xl">Добавить к заказу</h2>
                <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <ProductCard v-for="p in recommended" :key="p.id" :product="p" compact />
                </div>
            </section>
        </section>
    </SiteLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import { money } from '@/utils/site';

const props = defineProps({
    cart: { type: Object, required: true },
    cities: { type: Array, default: () => [] },
    delivery: { type: Array, default: () => [] },
    seo: { type: Object, default: () => ({}) },
});

const form = useForm({
    name: '',
    phone: '',
    email: '',
    city: props.cities[0] ?? '',
    address: '',
    delivery: 'delivery',
    comment: '',
});

const submit = () => form.post(route('site.checkout.store'), { preserveScroll: true });
</script>

<template>
    <SiteLayout :seo="seo">
        <section class="mx-auto max-w-7xl px-5 py-12 sm:px-8 sm:py-16">
            <Link :href="route('site.cart')" class="text-sm text-sand-100/45 transition hover:text-sand-300">← Вернуться в корзину</Link>
            <h1 class="display mt-6 text-[clamp(2rem,5vw,3.5rem)] text-sand-50">Оформление заказа</h1>
            <p class="mt-4 max-w-xl text-sm text-sand-100/55">
                Заявка сразу попадает менеджеру. Он свяжется, уточнит объём, сроки и стоимость доставки.
            </p>

            <form class="mt-12 grid gap-8 lg:grid-cols-[1fr_380px]" @submit.prevent="submit">
                <div class="space-y-6">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs text-sand-100/45">Как к вам обращаться *</span>
                            <input v-model="form.name" type="text" required class="mt-1.5 w-full rounded-xl border-white/12 bg-white/[0.04] px-4 py-3 text-sand-50 focus:border-sand-300 focus:ring-0" />
                            <span v-if="form.errors.name" class="mt-1 block text-xs text-rose-400">{{ form.errors.name }}</span>
                        </label>

                        <label class="block">
                            <span class="text-xs text-sand-100/45">Телефон *</span>
                            <input v-model="form.phone" type="tel" required placeholder="+7 ___ ___ __ __" class="mt-1.5 w-full rounded-xl border-white/12 bg-white/[0.04] px-4 py-3 text-sand-50 placeholder:text-sand-100/25 focus:border-sand-300 focus:ring-0" />
                            <span v-if="form.errors.phone" class="mt-1 block text-xs text-rose-400">{{ form.errors.phone }}</span>
                        </label>

                        <label class="block">
                            <span class="text-xs text-sand-100/45">Email</span>
                            <input v-model="form.email" type="email" class="mt-1.5 w-full rounded-xl border-white/12 bg-white/[0.04] px-4 py-3 text-sand-50 focus:border-sand-300 focus:ring-0" />
                            <span v-if="form.errors.email" class="mt-1 block text-xs text-rose-400">{{ form.errors.email }}</span>
                        </label>

                        <label class="block">
                            <span class="text-xs text-sand-100/45">Город</span>
                            <select v-model="form.city" class="mt-1.5 w-full rounded-xl border-white/12 bg-white/[0.04] px-4 py-3 text-sand-50 focus:border-sand-300 focus:ring-0">
                                <option v-for="c in cities" :key="c" :value="c" class="bg-ink-800">{{ c }}</option>
                                <option value="Другой город" class="bg-ink-800">Другой город</option>
                            </select>
                        </label>
                    </div>

                    <fieldset>
                        <legend class="text-xs text-sand-100/45">Получение</legend>
                        <div class="mt-2 grid gap-3 sm:grid-cols-2">
                            <label
                                v-for="opt in [['delivery', 'Доставка на объект', 'Привезём своим транспортом'], ['pickup', 'Самовывоз', 'Забрать с производства']]"
                                :key="opt[0]"
                                class="cursor-pointer rounded-2xl border p-4 transition"
                                :class="form.delivery === opt[0] ? 'border-sand-300 bg-white/[0.05]' : 'border-white/12 hover:border-white/25'"
                            >
                                <input v-model="form.delivery" type="radio" :value="opt[0]" class="sr-only" />
                                <span class="block text-sm font-medium text-sand-50">{{ opt[1] }}</span>
                                <span class="mt-1 block text-xs text-sand-100/45">{{ opt[2] }}</span>
                            </label>
                        </div>
                    </fieldset>

                    <label class="block">
                        <span class="text-xs text-sand-100/45">Адрес объекта</span>
                        <input v-model="form.address" type="text" placeholder="Улица, дом / название объекта" class="mt-1.5 w-full rounded-xl border-white/12 bg-white/[0.04] px-4 py-3 text-sand-50 placeholder:text-sand-100/25 focus:border-sand-300 focus:ring-0" />
                    </label>

                    <label class="block">
                        <span class="text-xs text-sand-100/45">Комментарий</span>
                        <textarea v-model="form.comment" rows="4" placeholder="Сроки, цвет, особенности объекта…" class="mt-1.5 w-full rounded-xl border-white/12 bg-white/[0.04] px-4 py-3 text-sand-50 placeholder:text-sand-100/25 focus:border-sand-300 focus:ring-0" />
                    </label>
                </div>

                <!-- Состав заказа -->
                <aside class="lg:sticky lg:top-28 lg:self-start">
                    <div class="card p-6 sm:p-7">
                        <p class="eyebrow">Ваш заказ</p>
                        <ul class="mt-5 space-y-3 text-sm">
                            <li v-for="item in cart.items" :key="item.key" class="flex justify-between gap-4">
                                <span class="min-w-0 flex-1 text-sand-100/60">
                                    <span class="block truncate text-sand-50">{{ item.name }}</span>
                                    <span class="text-xs">{{ item.quantity }} {{ item.unit }}<template v-if="item.color"> · {{ item.color }}</template></span>
                                </span>
                                <b class="whitespace-nowrap text-sand-50">{{ money(item.sum) }}</b>
                            </li>
                        </ul>

                        <div class="divider-top mt-6 flex items-baseline justify-between pt-5">
                            <span class="text-sand-100/60">Материалы</span>
                            <b class="display text-2xl text-sand-50">{{ money(cart.total) }}</b>
                        </div>

                        <button type="submit" class="btn-sand mt-6 w-full" :disabled="form.processing">
                            {{ form.processing ? 'Отправляем…' : 'Отправить заявку' }}
                        </button>

                        <p class="mt-4 text-[11px] leading-relaxed text-sand-100/35">
                            Нажимая кнопку, вы соглашаетесь на обработку контактных данных для расчёта заказа.
                            Доставка считается отдельно после подтверждения объёма.
                        </p>
                    </div>
                </aside>
            </form>
        </section>
    </SiteLayout>
</template>

<script setup>
/** Кабинет партнёра: свои услуги и заявки. Одно фото, модерация до 24 часов. */
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TranslationTabs from '@/Components/TranslationTabs.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useE } from '@/composables/useTranslations';

const tr = useE();
import { usePage } from '@inertiajs/vue3';
const props = defineProps({ services: Array, categories: Array, locales: Array });
const isModerator = (usePage().props.auth.user?.roles ?? []).some((r) => ['assistant', 'admin'].includes(r));

const editing = ref(null); // null | 'new' | service
const form = useForm({ title: '', category_id: '', description: '', price: '', contact_name: '', contact_phone: '', city: '', photo: null, translations: {} });
const open = (s = null) => {
    form.clearErrors(); form.reset();
    if (s) Object.assign(form, { title: s.title, category_id: s.category_id, description: s.description_raw, price: s.price_raw ?? '', contact_name: s.contact_name, contact_phone: s.contact_phone, city: s.city ?? '', photo: null, translations: JSON.parse(JSON.stringify(s.translations_map ?? {})) });
    editing.value = s ?? 'new';
};
const save = () => {
    const opts = { preserveScroll: true, forceFormData: true, onSuccess: () => (editing.value = null) };
    editing.value === 'new' ? form.post(route('partner.services.store'), opts) : form.post(route('partner.services.update', editing.value.id), { ...opts, headers: { 'X-HTTP-Method-Override': 'PUT' } });
};
const remove = async (s) => { if (await confirmDialog({ title: tr('Удалить услугу'), message: `«${s.title}»`, confirmText: tr('Удалить'), danger: true })) router.delete(route('partner.services.destroy', s.id), { preserveScroll: true }); };
const statusCls = { pending: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400', approved: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400', rejected: 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-400' };
const field = 'w-full rounded-xl border-white/60 bg-white/70 text-sm shadow-soft backdrop-blur focus:border-indigo-400 focus:ring-indigo-400';
</script>

<template>
    <Head :title="$e('Мои услуги')" />
    <AppLayout>
        <template #header>{{ $e('Мои услуги') }}</template>

        <div class="mb-4 flex flex-wrap items-center gap-3 rounded-3xl border border-white/60 bg-gradient-to-br from-white/85 via-indigo-50/60 to-violet-50/50 p-4 shadow-soft-lg backdrop-blur-xl dark:border-slate-800/80 dark:from-slate-900/85 dark:via-slate-900/70 dark:to-slate-900/60">
            <div class="min-w-0 flex-1">
                <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $e('Ваши услуги на витрине QAZAQ TAS') }}</div>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ isModerator ? $e('Ваши услуги публикуются сразу, без модерации. Категории — на странице «Модерация услуг».') : $e('Каждая заявка проходит проверку — ответим в течение 24 часов. Опубликованное видно в каталоге услуг сайта.') }}</p>
            </div>
            <PrimaryButton @click="open()">+ {{ $e('Услуга') }}</PrimaryButton>
        </div>

        <div class="grid gap-3">
            <div v-for="s in services" :key="s.id" class="flex flex-wrap items-center gap-4 rounded-2xl border border-white/60 bg-white/75 p-4 shadow-soft backdrop-blur-md dark:border-slate-800/80 dark:bg-slate-900/70">
                <img v-if="s.thumb" :src="s.thumb" class="h-16 w-24 rounded-xl object-cover" :alt="s.title" />
                <div v-else class="flex h-16 w-24 items-center justify-center rounded-xl bg-slate-100 text-slate-300 dark:bg-slate-800/60 dark:text-slate-600">◆</div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold text-slate-900 dark:text-slate-100">{{ s.title }}</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusCls[s.status]">{{ s.statusLabel }}</span>
                        <a v-if="s.public_url" :href="s.public_url" target="_blank" class="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ $e('на сайте') }} →</a>
                    </div>
                    <div class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ s.category?.name }}<template v-if="s.price"> · {{ new Intl.NumberFormat('ru-RU').format(s.price) }} ₸</template></div>
                    <div v-if="s.status === 'rejected' && s.rejection_reason" class="mt-1 rounded-lg bg-rose-50 px-2 py-1 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-400">{{ $e('Причина:') }} {{ s.rejection_reason }}</div>
                </div>
                <div class="flex gap-1 text-xs">
                    <button class="rounded-lg px-2.5 py-1 font-medium text-slate-600 hover:bg-indigo-50 hover:text-indigo-700 dark:text-slate-300 dark:hover:bg-indigo-500/10 dark:hover:text-indigo-300" @click="open(s)">{{ $e('Изменить') }}</button>
                    <button class="rounded-lg px-2.5 py-1 font-medium text-slate-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10 dark:hover:text-rose-400" @click="remove(s)">{{ $e('Удалить') }}</button>
                </div>
            </div>
            <div v-if="!services.length" class="rounded-3xl border border-dashed border-slate-200 bg-white/60 p-12 text-center text-sm text-slate-400 backdrop-blur dark:border-slate-800/80 dark:bg-slate-900/70">◆ {{ $e('Добавьте первую услугу — после проверки она появится на сайте.') }}</div>
        </div>

        <!-- Форма -->
        <div v-if="editing" class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-slate-900/30 p-4 backdrop-blur-sm sm:p-8" @click.self="editing = null">
            <div class="w-full max-w-2xl rounded-3xl border border-white/60 bg-gradient-to-br from-white/95 via-indigo-50/70 to-violet-50/60 p-6 shadow-soft-lg backdrop-blur-xl dark:border-slate-800/80 dark:from-slate-900/95 dark:via-slate-900/70 dark:to-slate-900/60">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ editing === 'new' ? $e('Новая услуга') : $e('Изменить услугу') }}</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $e('После сохранения услуга уйдёт на проверку (до 24 часов).') }}</p>
                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Название услуги') }} *</label>
                        <input v-model="form.title" type="text" :class="field" maxlength="120" />
                        <div v-if="form.errors.title" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ form.errors.title }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Категория') }} *</label>
                        <select v-model="form.category_id" :class="field"><option value="" disabled>—</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                        <div v-if="form.errors.category_id" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ form.errors.category_id }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Цена, ₸ (пусто — договорная)') }}</label>
                        <input v-model="form.price" type="number" min="0" step="1" :class="field" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Описание') }} *</label>
                        <textarea v-model="form.description" rows="4" :class="field" maxlength="3000" :placeholder="$e('Что входит, сроки, опыт — без ссылок и HTML')"></textarea>
                        <div v-if="form.errors.description" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ form.errors.description }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Контактное лицо') }} *</label>
                        <input v-model="form.contact_name" type="text" :class="field" />
                        <div v-if="form.errors.contact_name" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ form.errors.contact_name }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Телефон') }} *</label>
                        <input v-model="form.contact_phone" type="tel" :class="field" placeholder="+7 (___) ___-__-__" />
                        <div v-if="form.errors.contact_phone" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ form.errors.contact_phone }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Город') }}</label>
                        <input v-model="form.city" type="text" :class="field" />
                    </div>
                    <div class="sm:col-span-2 rounded-2xl bg-white/60 p-3 dark:bg-slate-900/70">
                        <div class="text-xs font-semibold text-slate-600 dark:text-slate-300">🌐 {{ $e('Переводы (kk / ru)') }}</div>
                        <p class="mt-0.5 text-xs text-slate-400">{{ $e('Пустое поле — на сайте покажется основной текст.') }}</p>
                        <TranslationTabs :key="`tr-${editing === 'new' ? 'new' : editing.id}`" v-model="form.translations" class="mt-2"
                            :locales="locales" :base="{ title: form.title, description: form.description }"
                            :fields="[{ key: 'title', label: $e('Название услуги'), type: 'text' }, { key: 'description', label: $e('Описание'), type: 'textarea', rows: 3 }]" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Фото (одно, JPG/PNG/WebP до 8 МБ)') }}<span v-if="editing === 'new'"> *</span></label>
                        <input type="file" accept="image/jpeg,image/png,image/webp" @change="form.photo = $event.target.files[0]"
                            class="w-full text-sm text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-indigo-700" />
                        <div v-if="form.errors.photo" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ form.errors.photo }}</div>
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton @click="editing = null">{{ $e('Отмена') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="save">{{ $e('Отправить на проверку') }}</PrimaryButton>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

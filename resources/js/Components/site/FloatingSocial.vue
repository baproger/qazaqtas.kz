<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Плавающая кнопка связи.
 *
 * Контакты берутся из общих данных Inertia — тех же, что показывают шапка и
 * подвал. Ничего не зашито: меняете телефон в ERP → Настройки → Сайт, и
 * кнопка меняется вместе со всем остальным.
 *
 * Раскрытие и наведение идут только через transform и opacity: раскладка не
 * пересчитывается, и рендер-цикл Three.js на главной не спотыкается.
 */
const page = usePage();

const contacts = computed(() => page.props.site?.contacts ?? {});

/**
 * Адрес из настроек может оказаться чем угодно, включая `javascript:`.
 * Vue подставляет :href как есть и не экранирует схему, поэтому пропускаем
 * только http и https, а всё остальное считаем именем профиля.
 */
const safeUrl = (value, base) => {
    const raw = String(value ?? '').trim();
    if (!raw) return null;

    if (/^https?:\/\//i.test(raw)) {
        try {
            return new URL(raw).toString();
        } catch {
            return null;
        }
    }
    // «qazaqtas» или «@qazaqtas» — достраиваем до полного адреса профиля.
    return base ? base + raw.replace(/^@/, '') : null;
};

/** Только цифры: и wa.me, и tel: не терпят пробелов и скобок. */
const digits = (value) => String(value ?? '').replace(/\D+/g, '');

const channels = computed(() => {
    const list = [];

    const whatsapp = digits(contacts.value.whatsapp);
    if (whatsapp) {
        list.push({
            key: 'whatsapp',
            label: 'Написать в WhatsApp',
            href: `https://wa.me/${whatsapp}`,
            external: true,
            tone: 'whatsapp',
        });
    }

    const instagram = safeUrl(contacts.value.instagram, 'https://instagram.com/');
    if (instagram) {
        list.push({
            key: 'instagram',
            label: 'Мы в Instagram',
            href: instagram,
            external: true,
            tone: 'instagram',
        });
    }

    const phone = digits(contacts.value.phone);
    if (phone) {
        list.push({
            key: 'phone',
            label: contacts.value.phone,
            href: `tel:+${phone}`,
            external: false,
            tone: 'phone',
        });
    }

    return list;
});

const open = ref(false);
const root = ref(null);

/**
 * Волна проходит четыре раза и затихает. Ключ меняется при каждом закрытии
 * меню — Vue пересоздаёт элемент, и анимация начинается заново.
 */
const pulseKey = ref(0);

const toggle = () => {
    open.value = !open.value;
    if (!open.value) pulseKey.value++;
};
const close = () => {
    if (open.value) pulseKey.value++;
    open.value = false;
};

/** Клик мимо и Escape закрывают меню — иначе оно живёт своей жизнью. */
const onPointerDown = (event) => {
    if (open.value && root.value && !root.value.contains(event.target)) close();
};
const onKeydown = (event) => {
    if (event.key === 'Escape') close();
};

onMounted(() => {
    document.addEventListener('pointerdown', onPointerDown, { passive: true });
    document.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onPointerDown);
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div
        v-if="channels.length"
        ref="root"
        class="socialbtn fixed bottom-5 right-5 z-40 flex flex-col items-end gap-3 sm:bottom-8 sm:right-8"
    >
        <!-- Каналы: раскрываются снизу вверх, каждый следующий чуть позже -->
        <a
            v-for="(channel, i) in channels"
            :key="channel.key"
            :href="channel.href"
            :target="channel.external ? '_blank' : undefined"
            :rel="channel.external ? 'noopener noreferrer' : undefined"
            class="socialbtn-item group"
            :class="[`is-${channel.tone}`, open ? 'is-open' : '']"
            :style="{ transitionDelay: `${open ? (channels.length - 1 - i) * 45 : i * 30}ms` }"
            :tabindex="open ? 0 : -1"
            :aria-hidden="open ? undefined : 'true'"
            @click="close"
        >
            <span class="socialbtn-label">{{ channel.label }}</span>

            <span class="socialbtn-icon">
                <svg v-if="channel.tone === 'whatsapp'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.86 9.86 0 0 0 12.04 2Zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.23 8.25-8.23 2.2 0 4.27.86 5.83 2.41a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.78.97-.15.16-.29.18-.53.06-.25-.13-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.71-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.44.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.44-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.42-.14-.01-.3-.01-.47-.01-.16 0-.43.06-.66.31-.22.24-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.68-1.18.2-.58.2-1.08.15-1.18-.06-.11-.22-.17-.47-.29Z" />
                </svg>
                <svg v-else-if="channel.tone === 'instagram'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <rect x="3" y="3" width="18" height="18" rx="5" />
                    <circle cx="12" cy="12" r="3.8" />
                    <circle cx="17.2" cy="6.8" r="1.1" fill="currentColor" stroke="none" />
                </svg>
                <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M6.5 3h3l1.5 4.5-2 1.5a12 12 0 0 0 6 6l1.5-2 4.5 1.5v3a2 2 0 0 1-2.2 2A17 17 0 0 1 4.5 5.2 2 2 0 0 1 6.5 3Z" />
                </svg>
            </span>
        </a>

        <!-- Кнопка-переключатель -->
        <button
            type="button"
            class="socialbtn-trigger"
            :class="open ? 'is-open' : ''"
            :aria-expanded="open"
            aria-controls="socialbtn-channels"
            :aria-label="open ? 'Закрыть способы связи' : 'Связаться с нами'"
            @click="toggle"
        >
            <!-- Волна привлекает внимание, но только пока меню закрыто -->
            <span v-if="!open" :key="pulseKey" class="socialbtn-pulse" aria-hidden="true" />

            <svg v-if="!open" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9.2 9.2 0 0 1-2.6-.4L4 21l1.6-4.2A8.3 8.3 0 0 1 4 11.5a8.4 8.4 0 0 1 9-8.4 8.4 8.4 0 0 1 8 8.4Z" />
                <path d="M9 11.5h.01M12 11.5h.01M15 11.5h.01" />
            </svg>
            <svg v-else class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
                <path d="M6 6l12 12M18 6 6 18" />
            </svg>
        </button>
    </div>
</template>

/**
 * Позиция прокрутки при смене языка витрины.
 *
 * Язык витрины живёт в адресе, и переключатель уводит на другой URL полной
 * перезагрузкой: атрибут `<html lang>` и теги hreflang рисует blade, и при
 * частичном обновлении они остались бы от прошлого языка. Браузер считает
 * это новой страницей и ставит прокрутку в начало — посетитель, читавший
 * характеристики внизу карточки, после переключения оказывался в шапке.
 *
 * Поэтому позицию запоминаем перед уходом и возвращаем после загрузки.
 */

const KEY = 'qt.locale-scroll';

/**
 * Запись живёт секунды: она относится к конкретному переключению языка.
 * Иначе позиция всплыла бы при обычном возврате на страницу через час.
 */
const FRESH_MS = 10_000;

/** Сколько ждём, пока страница дорастёт до нужной высоты. */
const SETTLE_MS = 1500;

/** Вызывается переключателем перед уходом на адрес другого языка. */
export function rememberScroll() {
    try {
        sessionStorage.setItem(KEY, JSON.stringify({ y: window.scrollY, at: Date.now() }));
    } catch {
        // Приватный режим запрещает запись — тогда просто откроемся сверху.
    }
}

/**
 * Возвращает позицию после загрузки страницы на другом языке.
 *
 * Ждём, пока документ дорастёт до нужной высоты: картинки грузятся лениво, и
 * сразу после mount страница короче — `scrollTo` упёрся бы в её текущий конец
 * и остановился выше нужного места.
 */
export function restoreScroll() {
    const target = takeStoredPosition();
    if (target === null) return;

    const deadline = Date.now() + SETTLE_MS;
    let frame = null;

    const step = () => {
        window.scrollTo(0, target);

        // Дошли или страница уже не вырастет — больше дёргать нечего.
        const reached = Math.abs(window.scrollY - target) < 2;
        if (reached || Date.now() > deadline) {
            cancel();

            return;
        }

        frame = requestAnimationFrame(step);
    };

    const cancel = () => {
        if (frame !== null) cancelAnimationFrame(frame);
        frame = null;
        window.removeEventListener('wheel', cancel);
        window.removeEventListener('touchstart', cancel);
        window.removeEventListener('keydown', cancel);
    };

    // Посетитель тронул страницу сам — дальше не спорим с ним.
    window.addEventListener('wheel', cancel, { once: true, passive: true });
    window.addEventListener('touchstart', cancel, { once: true, passive: true });
    window.addEventListener('keydown', cancel, { once: true });

    step();
}

/** Читает и сразу забывает сохранённую позицию: она одноразовая. */
function takeStoredPosition() {
    let raw = null;

    try {
        raw = sessionStorage.getItem(KEY);
        sessionStorage.removeItem(KEY);
    } catch {
        return null;
    }

    if (!raw) return null;

    try {
        const { y, at } = JSON.parse(raw);

        if (!Number.isFinite(y) || y <= 0) return null;
        if (!Number.isFinite(at) || Date.now() - at > FRESH_MS) return null;

        return y;
    } catch {
        return null;
    }
}

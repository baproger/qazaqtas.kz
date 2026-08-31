<script setup>
/**
 * Поля расхода: категория, сумма, дата, описание, чек.
 *
 * Одни и те же поля открываются в трёх местах — расход компании, заявка
 * сотрудника, расход по сделке. Пока они были тремя копиями, формы медленно
 * расходились: где-то появлялось поле, где-то менялась подпись, и бухгалтер
 * видел разный набор в зависимости от того, откуда зашёл.
 *
 * `form` — объект useForm вызывающей страницы: поля пишутся прямо в него,
 * отправляет и разбирает ошибки по-прежнему сама страница.
 */
defineProps({
    form: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    // Подпись файла отличается: у заявки чек обязателен по смыслу, у расхода
    // компании — нет.
    fileHint: { type: String, default: '' },
});
</script>

<template>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Категория *') }}</label>
            <select v-model="form.category_id" class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
                <option value="">{{ $e('— выберите —') }}</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <div v-if="form.errors.category_id" class="mt-1 text-xs text-red-600 dark:text-rose-400">{{ form.errors.category_id }}</div>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Сумма, ₸ *') }}</label>
            <input v-model="form.amount" type="number" min="0.01" step="0.01" class="w-full rounded-lg border-slate-300 text-sm shadow-sm" />
            <div v-if="form.errors.amount" class="mt-1 text-xs text-red-600 dark:text-rose-400">{{ form.errors.amount }}</div>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Дата *') }}</label>
            <input v-model="form.date" type="date" class="w-full rounded-lg border-slate-300 text-sm shadow-sm" />
            <div v-if="form.errors.date" class="mt-1 text-xs text-red-600 dark:text-rose-400">{{ form.errors.date }}</div>
        </div>

        <!-- Между датой и описанием страница вставляет своё: способ оплаты у
             расхода компании, материал у расхода по сделке. -->
        <slot name="middle" />

        <div class="sm:col-span-2">
            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $e('Описание') }}</label>
            <input v-model="form.description" type="text" class="w-full rounded-lg border-slate-300 text-sm shadow-sm" :placeholder="$e('За что…')" />
            <div v-if="form.errors.description" class="mt-1 text-xs text-red-600 dark:text-rose-400">{{ form.errors.description }}</div>
        </div>
        <div class="sm:col-span-2">
            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">
                {{ fileHint || $e('Чек / квитанция (фото или PDF, необязательно)') }}
            </label>
            <input type="file" accept="image/*,.pdf" @change="form.file = $event.target.files[0] ?? null"
                class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100" />
            <div v-if="form.errors.file" class="mt-1 text-xs text-red-600 dark:text-rose-400">{{ form.errors.file }}</div>
        </div>
    </div>
</template>

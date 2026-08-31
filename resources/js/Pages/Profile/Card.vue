<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import { useE } from '@/composables/useTranslations';

const tr = useE();

const props = defineProps({ me: Object, isAdmin: Boolean, employees: Array, departments: Array, mustVerifyEmail: Boolean, status: String });

const selectedId = ref(props.me.id);
const current = computed(() => props.isAdmin ? (props.employees.find((e) => e.id === selectedId.value) || props.me) : props.me);
const isSelf = computed(() => current.value.id === props.me.id);

const form = useForm({
    name: props.me.name, email: props.me.email,
    phone: props.me.phone ?? '', department_id: props.me.department_id ?? '',
});

const loadUser = () => {
    const u = current.value;
    form.name = u.name; form.email = u.email;
    form.phone = u.phone ?? ''; form.department_id = u.department_id ?? '';
    form.clearErrors();
};

// Подписи ролей приходят из БД одним общим списком (HandleInertiaRequests):
// зашитый в шаблоне словарь не знал ни «Бригадира», ни ролей, созданных
// владельцем через Настройки → Права доступа, и показывал голый код.
const roleLabels = computed(() => usePage().props.roleLabels ?? {});
const roleTitle = (code) => roleLabels.value[code] ?? code ?? '';
const deptName = (id) => props.departments.find((d) => d.id === id)?.name ?? '—';

const save = () => form.put(route('profile.card.update', current.value.id), { preserveScroll: true });

// Any user can set their OWN avatar photo.
const avatarInput = ref(null);
const avatarForm = useForm({ avatar: null });
const onAvatar = (e) => {
    const f = e.target.files?.[0];
    e.target.value = '';
    if (!f) return;
    avatarForm.avatar = f;
    avatarForm.post(route('profile.avatar'), { preserveScroll: true, forceFormData: true, onSuccess: () => avatarForm.reset() });
};
</script>

<template>
    <Head :title="$e('Профиль')" />
    <AppLayout>
        <template #header>{{ $t('page.profile', 'Профиль') }}</template>

        <div class="mx-auto max-w-3xl space-y-6">
            <div v-if="isAdmin" class="rounded-2xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 p-4 shadow-sm">
                <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-400">{{ $e('Сотрудник') }}</label>
                <select v-model="selectedId" @change="loadUser" class="w-full rounded-lg border-slate-200 py-2 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400">
                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                </select>
            </div>

            <div class="rounded-2xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 p-6 shadow-sm">
                <div class="flex items-center gap-4">
                    <div class="relative flex-shrink-0">
                        <span class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-full bg-indigo-600 text-2xl font-bold text-white shadow-lg shadow-indigo-600/30">
                            <img v-if="current.avatar" :src="current.avatar" class="h-full w-full object-cover" />
                            <template v-else>{{ current.name?.charAt(0) }}</template>
                        </span>
                        <template v-if="isSelf">
                            <input ref="avatarInput" type="file" accept="image/*" class="hidden" @change="onAvatar" />
                            <button @click="avatarInput?.click()" :disabled="avatarForm.processing" :title="$e('Сменить фото')"
                                class="absolute -bottom-1 -right-1 flex h-7 w-7 items-center justify-center rounded-full border-2 border-white bg-indigo-600 text-white shadow-sm transition-colors hover:bg-indigo-700 disabled:opacity-50">
                                <svg v-if="!avatarForm.processing" viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2Z"/><circle cx="12" cy="13" r="3.5"/></svg>
                                <svg v-else class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-30"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3"/></svg>
                            </button>
                        </template>
                    </div>
                    <div>
                        <div class="text-xl font-semibold text-slate-900 dark:text-slate-100">{{ current.name }}</div>
                        <div class="mt-1.5 flex items-center gap-2">
                            <span class="rounded-md bg-indigo-50 dark:bg-indigo-500/10 px-2 py-0.5 text-xs font-semibold text-indigo-700 dark:text-indigo-300">{{ roleTitle(current.role) || '—' }}</span>
                            <span class="text-xs text-slate-400">{{ deptName(current.department_id) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Read-only notice for regular users -->
                <div v-if="!isAdmin" class="mt-6 rounded-lg bg-slate-50 dark:bg-slate-800/50 px-3 py-2 text-xs text-slate-500 dark:text-slate-400 ring-1 ring-slate-200 dark:ring-slate-800">
                    {{ $e('Данные профиля может изменять только администратор или директор. Для правок обратитесь к руководству.') }}
                </div>

                <!-- Editable form: admins/directors only -->
                <template v-if="isAdmin">
                    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel :value="$e('Полное имя')" />
                            <TextInput v-model="form.name" class="mt-1 w-full" />
                            <div v-if="form.errors.name" class="mt-1 text-xs text-red-600 dark:text-rose-400">{{ form.errors.name }}</div>
                        </div>
                        <div>
                            <InputLabel value="Email" />
                            <TextInput v-model="form.email" type="email" class="mt-1 w-full" />
                            <div v-if="form.errors.email" class="mt-1 text-xs text-red-600 dark:text-rose-400">{{ form.errors.email }}</div>
                        </div>
                        <div>
                            <InputLabel :value="$e('Телефон')" />
                            <TextInput v-model="form.phone" class="mt-1 w-full" />
                        </div>
                        <div>
                            <InputLabel :value="$e('Отдел / должность')" />
                            <select v-model="form.department_id" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400">
                                <option value="">—</option>
                                <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel :value="$e('Роль')" />
                            <div class="mt-1 rounded-md border border-slate-200 dark:border-slate-800/80 bg-slate-50 dark:bg-slate-800/50 px-3 py-2 text-sm text-slate-500 dark:text-slate-400">
                                {{ roleTitle(current.role) || '—' }} <span class="text-xs text-slate-400">{{ $e('(изменяется только в «Сотрудники»)') }}</span>
                            </div>
                        </div>
                    </div>

                    <div v-if="mustVerifyEmail && isSelf" class="mt-4 rounded-lg bg-amber-50 dark:bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-400 ring-1 ring-amber-200 dark:ring-amber-500/30">
                        {{ $e('Электронная почта не подтверждена.') }}
                        <span v-if="status === 'verification-link-sent'" class="font-medium">{{ $e('Ссылка для подтверждения отправлена.') }}</span>
                    </div>

                    <div class="mt-6 flex items-center gap-3">
                        <PrimaryButton :disabled="form.processing" @click="save">{{ $e('Сохранить') }}</PrimaryButton>
                        <transition enter-active-class="transition duration-300 ease-out" enter-from-class="translate-x-2 opacity-0" leave-active-class="transition duration-500" leave-to-class="opacity-0">
                            <span v-if="form.recentlySuccessful" class="flex items-center gap-1 text-sm font-medium text-emerald-600 dark:text-emerald-400">{{ $e('✓ Сохранено') }}</span>
                        </transition>
                    </div>
                </template>

                <!-- Read-only field summary for regular users -->
                <dl v-else class="mt-6 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">Email</dt><dd class="mt-0.5 text-slate-800 dark:text-slate-200">{{ current.email }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">{{ $e('Телефон') }}</dt><dd class="mt-0.5 text-slate-800 dark:text-slate-200">{{ current.phone || '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">{{ $e('Отдел / должность') }}</dt><dd class="mt-0.5 text-slate-800 dark:text-slate-200">{{ deptName(current.department_id) }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-400">{{ $e('Роль') }}</dt><dd class="mt-0.5 text-slate-800 dark:text-slate-200">{{ roleTitle(current.role) || '—' }}</dd></div>
                </dl>
            </div>

            <!-- Security: password & account deletion — admins/directors only, own account -->
            <template v-if="isSelf && isAdmin">
                <div class="rounded-2xl border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900/70 p-6 shadow-sm">
                    <UpdatePasswordForm class="max-w-xl" />
                </div>
                <div class="rounded-2xl border border-rose-200 bg-white dark:bg-slate-900/70 p-6 shadow-sm">
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </template>
        </div>
    </AppLayout>
</template>

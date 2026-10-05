<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const user = computed(() => usePage().props.auth.user);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.put(route('password.wajib.update'), {
        onFinish: () => form.reset(),
    });
};

const logout = () => router.post(route('logout'));
</script>

<template>
    <GuestLayout>
        <Head title="Buat Password Baru" />

        <h1 class="text-lg font-semibold text-gray-900">Buat password baru</h1>
        <p class="mt-1 mb-5 text-sm text-gray-500">
            Halo, {{ user?.name }}. Akun Anda memakai password sementara. Buat password baru milik Anda sendiri untuk melanjutkan.
        </p>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="current_password" value="Password saat ini (sementara)" />
                <TextInput id="current_password" type="password" class="mt-1 block w-full" v-model="form.current_password"
                    required autofocus autocomplete="current-password" />
                <InputError class="mt-2" :message="form.errors.current_password" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="Password baru" />
                <TextInput id="password" type="password" class="mt-1 block w-full" v-model="form.password"
                    required autocomplete="new-password" />
                <p class="mt-1 text-xs text-gray-500">Minimal 10 karakter, mengandung huruf dan angka.</p>
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4">
                <InputLabel for="password_confirmation" value="Ulangi password baru" />
                <TextInput id="password_confirmation" type="password" class="mt-1 block w-full" v-model="form.password_confirmation"
                    required autocomplete="new-password" />
                <InputError class="mt-2" :message="form.errors.password_confirmation" />
            </div>

            <PrimaryButton class="mt-5 w-full justify-center" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                {{ form.processing ? 'Menyimpan…' : 'Simpan password baru' }}
            </PrimaryButton>

            <div class="mt-4 text-center">
                <button type="button" @click="logout" class="text-sm text-gray-500 underline hover:text-gray-800">Keluar</button>
            </div>
        </form>
    </GuestLayout>
</template>

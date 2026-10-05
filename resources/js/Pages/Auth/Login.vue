<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import PengumumanBox from '@/Components/PengumumanBox.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    pengumuman: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const showPassword = ref(false);

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Masuk" />

        <h1 class="text-lg font-semibold text-white">Masuk ke akun Anda</h1>
        <p class="mt-1 mb-5 text-sm text-slate-400">Gunakan email dan password yang diberikan administrator.</p>

        <div v-if="status" class="mb-4 text-sm font-medium text-emerald-400">
            {{ status }}
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Email" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                    placeholder="nama@perusahaan.com"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="Password" />

                <div class="relative mt-1">
                    <TextInput
                        id="password"
                        :type="showPassword ? 'text' : 'password'"
                        class="block w-full pr-24"
                        v-model="form.password"
                        required
                        autocomplete="current-password"
                    />
                    <button type="button" @click="showPassword = !showPassword"
                        class="absolute inset-y-0 right-0 px-3 text-xs font-medium text-slate-400 hover:text-white"
                        :aria-label="showPassword ? 'Sembunyikan password' : 'Tampilkan password'">
                        {{ showPassword ? 'Sembunyikan' : 'Tampilkan' }}
                    </button>
                </div>

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4 block">
                <label class="flex items-center">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="ms-2 text-sm text-slate-400">Ingat saya di perangkat ini</span>
                </label>
            </div>

            <PrimaryButton
                class="mt-5 w-full justify-center"
                :class="{ 'opacity-25': form.processing }"
                :disabled="form.processing"
            >
                {{ form.processing ? 'Memproses…' : 'Masuk' }}
            </PrimaryButton>

            <div class="mt-4 text-center text-sm">
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="text-slate-400 underline hover:text-white"
                >
                    Lupa password?
                </Link>
                <span v-else class="text-slate-400">Lupa password? Hubungi administrator sistem.</span>
            </div>
        </form>

        <template v-if="pengumuman.length" #samping>
            <PengumumanBox :items="pengumuman" />
        </template>
    </GuestLayout>
</template>

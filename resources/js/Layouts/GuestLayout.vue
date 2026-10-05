<script setup>
import BrandMark from '@/Components/BrandMark.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Layout halaman sebelum login (masuk, lupa/reset password): latar gelap seperti aplikasi,
// kartu putih (kontras tinggi, mudah dibaca), identitas dari Profil Developer.
// Slot `samping` (opsional) = kotak pengumuman di sebelah kiri kartu pada layar lebar; di layar
// kecil urutannya: identitas → kartu → samping.
const branding = computed(() => usePage().props.branding ?? {});
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-gradient-to-b from-slate-950 via-slate-900 to-slate-950 px-4 py-10">
        <div :class="$slots.samping
            ? 'w-full max-w-5xl grid grid-cols-1 lg:grid-cols-2 gap-x-14 gap-y-8 items-start'
            : 'w-full flex flex-col items-center'">

            <Link href="/" :class="['flex items-center gap-5 max-w-md', $slots.samping ? 'lg:col-start-1 lg:row-start-1 lg:self-end mx-auto lg:mx-0' : '']">
                <BrandMark size="xl" />
                <div class="min-w-0">
                    <div class="text-white text-3xl font-bold tracking-wide leading-none">SSID</div>
                    <div class="mt-1.5 text-slate-300 text-sm leading-snug">{{ branding.nama_sistem }}</div>
                    <div v-if="branding.nama_developer" class="mt-1 text-violet-300 text-sm font-medium truncate">{{ branding.nama_developer }}</div>
                </div>
            </Link>

            <div :class="['w-full overflow-hidden rounded-2xl bg-white px-6 py-6 shadow-2xl shadow-black/40 sm:max-w-md',
                $slots.samping ? 'lg:col-start-2 lg:row-start-1 lg:row-span-2 lg:self-center mx-auto lg:mx-0' : 'mt-8']">
                <slot />
            </div>

            <div v-if="$slots.samping" class="w-full max-w-md lg:max-w-none lg:col-start-1 lg:row-start-2 mx-auto lg:mx-0">
                <slot name="samping" />
            </div>

            <div :class="['text-xs text-slate-500', $slots.samping ? 'lg:col-span-2 text-center' : 'mt-6']">
                &copy; {{ new Date().getFullYear() }} {{ branding.nama_developer ?? branding.nama_sistem }}
            </div>
        </div>
    </div>
</template>

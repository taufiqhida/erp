<script setup>
import BrandMark from '@/Components/BrandMark.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Layout halaman sebelum login (masuk, lupa/reset password): latar gelap seperti aplikasi,
// kartu putih (kontras tinggi, mudah dibaca), identitas dari Profil Developer.
const branding = computed(() => usePage().props.branding ?? {});
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-gradient-to-b from-slate-950 via-slate-900 to-slate-950 px-4 py-10">
        <Link href="/" class="flex flex-col items-center text-center">
            <BrandMark size="lg" />
            <div class="mt-4 text-white text-xl font-semibold leading-tight">{{ branding.nama_developer ?? 'SSID' }}</div>
            <div class="mt-1 text-slate-400 text-sm">{{ branding.nama_sistem }}</div>
        </Link>

        <div class="mt-8 w-full overflow-hidden rounded-2xl bg-white px-6 py-6 shadow-2xl shadow-black/40 sm:max-w-md">
            <slot />
        </div>

        <div class="mt-6 text-xs text-slate-500">&copy; {{ new Date().getFullYear() }} {{ branding.nama_developer ?? branding.nama_sistem }}</div>
    </div>
</template>

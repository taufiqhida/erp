<script setup>
import BrandMark from '@/Components/BrandMark.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Layout halaman sebelum login (masuk, lupa/reset password, error): satu tema gelap dengan aplikasi,
// latar warna polos. Komponen form Breeze (label/input) masih bergaya terang, jadi di-override
// lewat varian `[&_...]` pada kartu supaya ikut gelap tanpa mengubah komponennya.
// Slot `samping` (opsional) = kotak di sebelah kiri kartu: identitas + pengumuman dalam SATU kotak.
// Di layar kecil kartu form tampil lebih dulu (dengan identitas ringkas), kotak pengumuman di bawahnya.
const branding = computed(() => usePage().props.branding ?? {});

const kartu = 'rounded-2xl border border-slate-800 bg-slate-900 p-6 shadow-2xl shadow-black/30 ' +
    '[&_label]:text-slate-300 [&_.text-red-600]:text-rose-400 ' +
    '[&_input:not([type=checkbox])]:bg-slate-800 [&_input:not([type=checkbox])]:border-slate-700 ' +
    '[&_input:not([type=checkbox])]:text-slate-100 [&_input:not([type=checkbox])]:placeholder-slate-500 ' +
    '[&_input[type=checkbox]]:bg-slate-800 [&_input[type=checkbox]]:border-slate-600';
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-slate-950 px-4 py-10">
        <div :class="$slots.samping
            ? 'w-full max-w-5xl grid grid-cols-1 lg:grid-cols-2 gap-6 items-stretch'
            : 'w-full max-w-md'">

            <!-- Kotak identitas + pengumuman (layar lebar: di kiri; layar kecil: di bawah kartu) -->
            <div v-if="$slots.samping" class="order-last lg:order-first rounded-2xl border border-slate-800 bg-slate-900 p-6 flex flex-col gap-6">
                <Link href="/" class="hidden lg:flex items-center gap-5">
                    <BrandMark size="xl" />
                    <div class="min-w-0">
                        <div class="text-white text-3xl font-bold tracking-wide leading-none">SSID</div>
                        <div class="mt-1.5 text-slate-300 text-sm leading-snug">{{ branding.nama_sistem }}</div>
                        <div v-if="branding.nama_developer" class="mt-1 text-violet-300 text-sm font-medium">{{ branding.nama_developer }}</div>
                    </div>
                </Link>
                <slot name="samping" />
            </div>

            <!-- Kartu form -->
            <div :class="[kartu, $slots.samping ? 'self-center w-full' : '']">
                <Link href="/" :class="['items-center gap-4 mb-6', $slots.samping ? 'flex lg:hidden' : 'flex']">
                    <BrandMark size="xl" />
                    <div class="min-w-0">
                        <div class="text-white text-2xl font-bold tracking-wide leading-none">SSID</div>
                        <div class="mt-1 text-slate-300 text-xs leading-snug">{{ branding.nama_sistem }}</div>
                        <div v-if="branding.nama_developer" class="mt-0.5 text-violet-300 text-xs font-medium">{{ branding.nama_developer }}</div>
                    </div>
                </Link>
                <slot />
            </div>

            <div :class="['text-xs text-slate-500 text-center', $slots.samping ? 'lg:col-span-2 order-last' : 'mt-5']">
                &copy; {{ new Date().getFullYear() }} {{ branding.nama_developer ?? branding.nama_sistem }}
            </div>
        </div>
    </div>
</template>

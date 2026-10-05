<script setup>
import BrandMark from '@/Components/BrandMark.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Layout halaman sebelum login (masuk, lupa/reset password, error): satu tema gelap dengan aplikasi,
// latar warna polos. Komponen form Breeze (label/input) masih bergaya terang, jadi di-override
// lewat varian `[&_...]` pada kartu supaya ikut gelap tanpa mengubah komponennya.
// Identitas (logo, nama sistem, nama developer) ada di bagian atas kartu form.
// Slot `samping` (opsional) = kotak pengumuman di sebelah kiri kartu. Di layar lebar kedua kotak
// berukuran sama dan tetap (kelebihan isi pengumuman digulir); di layar kecil kartu form tampil lebih dulu.
const branding = computed(() => usePage().props.branding ?? {});

const kartu = 'rounded-2xl border border-slate-800 bg-slate-900 p-5 shadow-2xl shadow-black/30 ' +
    '[&_label]:text-slate-300 [&_.text-red-600]:text-rose-400 ' +
    '[&_input:not([type=checkbox])]:bg-slate-800 [&_input:not([type=checkbox])]:border-slate-700 ' +
    '[&_input:not([type=checkbox])]:text-slate-100 [&_input:not([type=checkbox])]:placeholder-slate-500 ' +
    '[&_input[type=checkbox]]:bg-slate-800 [&_input[type=checkbox]]:border-slate-600';
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-slate-950 px-4 py-10">
        <div :class="$slots.samping
            ? 'w-full max-w-6xl grid grid-cols-1 lg:grid-cols-2 gap-6'
            : 'w-full max-w-md'">

            <!-- Kotak pengumuman (layar lebar: di kiri; layar kecil: di bawah kartu) -->
            <div v-if="$slots.samping" class="order-last lg:order-first h-[22rem] lg:h-[27rem] rounded-2xl border border-slate-800 bg-slate-900 p-4">
                <slot name="samping" />
            </div>

            <!-- Kartu form -->
            <div :class="[kartu, $slots.samping ? 'lg:h-[27rem] lg:overflow-y-auto lg:flex lg:flex-col lg:justify-center' : '']">
                <div>
                    <Link href="/" class="flex items-center gap-4 mb-5">
                        <BrandMark size="lg" />
                        <div class="min-w-0 text-left">
                            <div class="text-white text-xl font-bold leading-tight">{{ branding.nama_sistem }}</div>
                            <div v-if="branding.nama_developer" class="mt-0.5 text-violet-300 text-sm font-medium">{{ branding.nama_developer }}</div>
                        </div>
                    </Link>
                    <slot />
                </div>
            </div>

            <div :class="['text-xs text-slate-500 text-center', $slots.samping ? 'lg:col-span-2 order-last' : 'mt-5']">
                &copy; {{ new Date().getFullYear() }} {{ branding.nama_developer ?? branding.nama_sistem }}
            </div>
        </div>
    </div>
</template>

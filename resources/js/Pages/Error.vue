<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    status: { type: Number, default: 500 },
});

// Halaman error berbahasa Indonesia untuk akses langsung (buka alamat / muat ulang halaman).
// Aksi yang ditolak saat sedang memakai aplikasi tetap ditampilkan sebagai notifikasi (toast).
const pesan = computed(() => ({
    403: { judul: 'Akses ditolak', isi: 'Anda tidak memiliki izin untuk membuka halaman ini. Hubungi administrator jika menurut Anda ini keliru.' },
    404: { judul: 'Halaman tidak ditemukan', isi: 'Alamat yang Anda buka tidak ada atau sudah dipindahkan. Coba kembali ke Beranda dan buka lewat menu.' },
    419: { judul: 'Sesi berakhir', isi: 'Sesi Anda habis karena terlalu lama tidak aktif. Muat ulang halaman lalu coba lagi.' },
    500: { judul: 'Terjadi kesalahan', isi: 'Maaf, ada masalah di sistem. Coba lagi beberapa saat lagi. Kalau terus berulang, hubungi administrator.' },
    503: { judul: 'Sedang pemeliharaan', isi: 'Sistem sedang dalam pemeliharaan. Silakan coba lagi sebentar lagi.' },
}[props.status] ?? { judul: 'Terjadi kesalahan', isi: 'Silakan kembali ke Beranda dan coba lagi.' }));

const muatUlang = () => window.location.reload();
const bisaMuatUlang = computed(() => [419, 500, 503].includes(props.status));
</script>

<template>
    <GuestLayout>
        <Head :title="pesan.judul" />

        <div class="text-center">
            <div class="text-5xl font-bold text-violet-400">{{ status }}</div>
            <h1 class="mt-3 text-lg font-semibold text-white">{{ pesan.judul }}</h1>
            <p class="mt-2 text-sm text-slate-400">{{ pesan.isi }}</p>

            <div class="mt-6 flex flex-col sm:flex-row gap-2 justify-center">
                <Link href="/"
                    class="inline-flex items-center justify-center rounded-md bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-500">
                    Kembali ke Beranda
                </Link>
                <button v-if="bisaMuatUlang" type="button" @click="muatUlang"
                    class="inline-flex items-center justify-center rounded-md border border-slate-600 px-4 py-2 text-sm font-medium text-slate-300 hover:bg-slate-800">
                    Muat ulang halaman
                </button>
            </div>
        </div>
    </GuestLayout>
</template>

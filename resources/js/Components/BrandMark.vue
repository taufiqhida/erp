<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

// Logo perusahaan (Profil Developer). Belum diunggah → ikon rumah ungu bawaan.
// Logo tampil APA ADANYA (logo transparan tetap transparan). Hanya kalau logonya terlalu gelap untuk
// latar gelap aplikasi (terdeteksi otomatis dari rata-rata kecerahan piksel yang terlihat), logo diberi
// alas terang tipis supaya tetap terbaca.
const props = defineProps({
    size: { type: String, default: 'md' }, // md = sidebar/header, xl = halaman login
});

const branding = computed(() => usePage().props.branding ?? {});
const xl = computed(() => props.size === 'xl');

const perluAlas = ref(false);
const periksaKontras = (e) => {
    try {
        const ukuran = 40;
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = ukuran;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(e.target, 0, 0, ukuran, ukuran);
        const px = ctx.getImageData(0, 0, ukuran, ukuran).data;

        let total = 0;
        let jumlah = 0;
        for (let i = 0; i < px.length; i += 4) {
            if (px[i + 3] > 128) { // abaikan piksel transparan
                total += 0.2126 * px[i] + 0.7152 * px[i + 1] + 0.0722 * px[i + 2];
                jumlah++;
            }
        }
        perluAlas.value = jumlah > 0 && (total / jumlah) / 255 < 0.45;
    } catch {
        perluAlas.value = false; // gambar tidak bisa dibaca → tampil apa adanya
    }
};

const boxImg = computed(() => xl.value ? 'h-20 max-w-[11rem]' : 'h-8 max-w-[8rem]');
const boxIkon = computed(() => xl.value ? 'w-20 h-20 rounded-2xl' : 'w-8 h-8 rounded-lg');
const icon = computed(() => xl.value ? 'w-10 h-10' : 'w-4 h-4');
</script>

<template>
    <img v-if="branding.logo_url" :src="branding.logo_url" :alt="branding.nama_developer ?? 'Logo'" @load="periksaKontras"
        :class="[boxImg, 'w-auto object-contain flex-shrink-0', perluAlas ? 'bg-white/95 rounded-lg p-1.5' : '']" />
    <div v-else :class="[boxIkon, 'bg-gradient-to-br from-violet-500 to-indigo-600 flex items-center justify-center flex-shrink-0 shadow-lg shadow-violet-500/30']">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" :class="[icon, 'text-white']">
            <path d="M11.47 3.84a.75.75 0 011.06 0l8.69 8.69a.75.75 0 101.06-1.06l-8.689-8.69a2.25 2.25 0 00-3.182 0l-8.69 8.69a.75.75 0 001.061 1.06l8.69-8.69z" />
            <path d="M12 5.432l8.159 8.159c.03.03.06.058.091.086v6.198c0 1.035-.84 1.875-1.875 1.875H15a.75.75 0 01-.75-.75v-4.5a.75.75 0 00-.75-.75h-3a.75.75 0 00-.75.75V21a.75.75 0 01-.75.75H5.625a1.875 1.875 0 01-1.875-1.875v-6.198a2.29 2.29 0 00.091-.086L12 5.43z" />
        </svg>
    </div>
</template>

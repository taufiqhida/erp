<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

// Pilihan ukuran teks (Normal / Besar / Lebih Besar). Diterapkan seketika lewat atribut <html data-ukuran>,
// lalu disimpan ke akun; atribut yang sama dirender server saat halaman dimuat, jadi tidak berkedip.
const user = computed(() => usePage().props.auth.user);
const ukuran = computed(() => user.value?.ukuran_font ?? 'normal');

const pilihan = [
    { nilai: 'normal', label: 'Normal', kelas: 'text-sm' },
    { nilai: 'besar', label: 'Besar', kelas: 'text-base' },
    { nilai: 'lebih-besar', label: 'Lebih Besar', kelas: 'text-xl' },
];

const pilih = (nilai) => {
    if (nilai === ukuran.value) return;
    document.documentElement.dataset.ukuran = nilai;
    router.patch(route('preferensi.update'), { ukuran_font: nilai }, {
        preserveScroll: true,
        preserveState: true,
        only: ['auth'],
    });
};
</script>

<template>
    <div class="flex items-center gap-1" role="group" aria-label="Ukuran teks">
        <button v-for="p in pilihan" :key="p.nilai" type="button" @click="pilih(p.nilai)"
            :aria-pressed="ukuran === p.nilai" :aria-label="`Ukuran teks ${p.label}`" :title="`Ukuran teks: ${p.label}`"
            :class="['w-8 h-8 rounded-lg border font-semibold leading-none transition-colors',
                ukuran === p.nilai
                    ? 'bg-violet-600/25 border-violet-500 text-violet-200'
                    : 'border-slate-700 text-slate-300 hover:bg-slate-800']">
            <span :class="p.kelas">A</span>
        </button>
    </div>
</template>

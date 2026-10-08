<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { terapkanTema } from '@/Composables/useTema';

// Pilihan tema: Gelap / Terang / Sistem (mengikuti pengaturan perangkat). Diterapkan seketika lewat atribut
// <html>, lalu disimpan ke akun; atribut yang sama dirender server saat halaman dimuat (tanpa berkedip).
const user = computed(() => usePage().props.auth.user);
const tema = computed(() => user.value?.tema ?? 'gelap');

const pilihan = [
    { nilai: 'gelap', label: 'Gelap', ikon: 'M21.752 15.002A9.72 9.72 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z' },
    { nilai: 'terang', label: 'Terang', ikon: 'M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z' },
    { nilai: 'sistem', label: 'Ikuti sistem', ikon: 'M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25' },
];

const pilih = (nilai) => {
    if (nilai === tema.value) return;
    terapkanTema(nilai);
    router.patch(route('preferensi.update'), { tema: nilai }, {
        preserveScroll: true,
        preserveState: true,
        only: ['auth'],
    });
};
</script>

<template>
    <div class="flex items-center gap-1" role="group" aria-label="Tema tampilan">
        <button v-for="p in pilihan" :key="p.nilai" type="button" @click="pilih(p.nilai)"
            :aria-pressed="tema === p.nilai" :aria-label="`Tema ${p.label}`" :title="`Tema: ${p.label}`"
            :class="['w-8 h-8 rounded-lg border flex items-center justify-center transition-colors',
                tema === p.nilai
                    ? 'bg-violet-600/25 border-violet-500 text-violet-200'
                    : 'border-slate-700 text-slate-300 hover:bg-slate-800']">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="w-4 h-4" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="p.ikon" />
            </svg>
        </button>
    </div>
</template>

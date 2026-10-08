<script setup>
import { ref } from 'vue';

// PANEL SEMENTARA untuk memilih tema/aksen saat mencoba warna (hanya tampil di lingkungan lokal).
// Pilihan disimpan di localStorage browser ini saja; dihapus setelah warna final diputuskan.
const KUNCI = 'ssid-tema-pratinjau';
const buka = ref(false);
const baca = () => { try { return JSON.parse(localStorage.getItem(KUNCI) || '{}'); } catch { return {}; } };
const pilihan = ref(baca());

const grup = [
    { kunci: 'tema', judul: 'Tema', bawaan: 'gelap', opsi: [['gelap', 'Gelap'], ['terang', 'Terang']] },
    { kunci: 'aksen', judul: 'Aksen', bawaan: 'ungu', opsi: [['ungu', 'Ungu (sekarang)'], ['biru', 'Biru merek'], ['merah', 'Merah merek'], ['teal', 'Teal']] },
    { kunci: 'latar', judul: 'Latar gelap', bawaan: 'slate', opsi: [['slate', 'Slate (sekarang)'], ['navy', 'Navy merek']] },
];

const aktif = (g, nilai) => (pilihan.value[g.kunci] ?? g.bawaan) === nilai;
const pilih = (g, nilai) => {
    pilihan.value = { ...pilihan.value, [g.kunci]: nilai };
    if (nilai === g.bawaan) document.documentElement.removeAttribute(`data-${g.kunci}`);
    else document.documentElement.dataset[g.kunci] = nilai;
    try { localStorage.setItem(KUNCI, JSON.stringify(pilihan.value)); } catch { /* abaikan */ }
};
</script>

<template>
    <div class="fixed bottom-4 right-4 z-[90] text-sm">
        <button v-if="!buka" type="button" @click="buka = true"
            class="px-3 py-2 rounded-full bg-slate-800 border border-slate-600 text-slate-200 shadow-xl hover:bg-slate-700">
            🎨 Pratinjau tema
        </button>
        <div v-else class="w-72 rounded-2xl bg-slate-900 border border-slate-700 shadow-2xl p-4 text-slate-200">
            <div class="flex items-center justify-between mb-3">
                <div class="font-semibold">Pratinjau tema <span class="text-slate-500 font-normal text-xs">(sementara)</span></div>
                <button type="button" @click="buka = false" aria-label="Tutup panel" class="text-slate-500 hover:text-slate-300">✕</button>
            </div>
            <div v-for="g in grup" :key="g.kunci" class="mb-3 last:mb-0">
                <div class="text-slate-400 text-xs mb-1.5">{{ g.judul }}</div>
                <div class="flex flex-wrap gap-1.5">
                    <button v-for="[nilai, label] in g.opsi" :key="nilai" type="button" @click="pilih(g, nilai)"
                        :aria-pressed="aktif(g, nilai)"
                        :class="['px-2.5 py-1.5 rounded-lg border text-xs transition-colors',
                            aktif(g, nilai) ? 'bg-violet-600/25 border-violet-500 text-violet-300' : 'border-slate-600 text-slate-300 hover:bg-slate-800']">
                        {{ label }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

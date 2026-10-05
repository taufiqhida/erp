<script setup>
import { ref } from 'vue';

// Pengumuman di halaman login: mengisi penuh kotak induk (tinggi tetap), isi yang lebih panjang
// digulir (scroll mouse/sentuh) atau dengan tombol naik/turun. Yang disematkan paling atas.
defineProps({
    items: { type: Array, default: () => [] },
});

const daftar = ref(null);
const geser = (arah) => daftar.value?.scrollBy({ top: arah * 130, behavior: 'smooth' });
</script>

<template>
    <section v-if="items.length" class="h-full flex flex-col" aria-label="Pengumuman">
        <header class="flex items-center justify-between px-2 pb-3 border-b border-slate-800">
            <h2 class="text-slate-200 text-sm font-semibold">Pengumuman</h2>
            <div v-if="items.length > 2" class="flex gap-1">
                <button type="button" @click="geser(-1)" aria-label="Naik"
                    class="w-7 h-7 rounded-md text-slate-400 hover:text-white hover:bg-slate-800 transition-colors">▲</button>
                <button type="button" @click="geser(1)" aria-label="Turun"
                    class="w-7 h-7 rounded-md text-slate-400 hover:text-white hover:bg-slate-800 transition-colors">▼</button>
            </div>
        </header>

        <div ref="daftar" class="flex-1 min-h-0 overflow-y-auto divide-y divide-slate-800">
            <article v-for="p in items" :key="p.id" class="px-2 py-3">
                <div class="flex items-center gap-2 text-xs text-slate-500">
                    <span v-if="p.disematkan" class="px-1.5 py-0.5 rounded bg-amber-500/15 text-amber-400 font-medium">📌 Disematkan</span>
                    <span>{{ p.tanggal }}</span>
                </div>
                <h3 class="mt-1 text-slate-100 text-sm font-semibold leading-snug">{{ p.judul }}</h3>
                <p class="mt-1 text-slate-400 text-sm whitespace-pre-line break-words">{{ p.isi }}</p>
            </article>
        </div>
    </section>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

// Tombol filter yang membuka panel kecil di bawahnya. Beda dari Dropdown.vue: panel TIDAK menutup saat isinya diklik (untuk
// checkbox & beberapa kontrol sekaligus), hanya saat klik di luar atau tekan Esc. `badge` = angka kecil (mis. jumlah filter aktif),
// `active` = tombol diberi warna aksen (ada filter yang menyimpang dari bawaan).
defineProps({
    label:  { type: String, required: true },
    badge:  { type: [Number, String], default: null },
    active: { type: Boolean, default: false },
    align:  { type: String, default: 'left' },
    width:  { type: String, default: 'w-72' },
});

const open = ref(false);
const root = ref(null);
const klikLuar = (e) => { if (open.value && root.value && !root.value.contains(e.target)) open.value = false; };
const tekanKey = (e) => { if (open.value && e.key === 'Escape') open.value = false; };

onMounted(() => { document.addEventListener('mousedown', klikLuar); document.addEventListener('keydown', tekanKey); });
onBeforeUnmount(() => { document.removeEventListener('mousedown', klikLuar); document.removeEventListener('keydown', tekanKey); });
</script>

<template>
    <div ref="root" class="relative">
        <button type="button" @click="open = !open" :aria-expanded="open"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-xs transition-colors"
            :class="active || open ? 'bg-violet-600/15 border-violet-500/40 text-violet-200' : 'bg-slate-800 border-slate-700 text-slate-300 hover:text-white'">
            {{ label }}
            <span v-if="badge !== null && badge !== ''" class="px-1.5 rounded bg-violet-600 text-white text-[0.625rem] leading-4">{{ badge }}</span>
            <span class="text-slate-500 text-[0.625rem]">▾</span>
        </button>
        <div v-if="open" class="absolute z-30 mt-2 max-w-[90vw] bg-slate-900 border border-slate-700 rounded-xl shadow-2xl shadow-black/40 p-3"
            :class="[align === 'right' ? 'right-0' : 'left-0', width]">
            <slot :close="() => (open = false)" />
        </div>
    </div>
</template>

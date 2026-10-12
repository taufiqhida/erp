<script setup>
// Lapisan kotak kavling di atas gambar siteplan raster: tiap unit yang sudah ditandai ("bentuk") diwarnai menurut status
// jualnya, bergaris tepi menurut status bangunnya, dan diberi nomor. Dipasang di dalam wadah `relative` yang membungkus gambar.
import { ref, computed } from 'vue';
import { titikLabel } from '@/Composables/isiArea';

const props = defineProps({
    kavlings:             { type: Array, default: () => [] },
    statusConfig:         { type: Object, default: () => ({}) },
    statusBangunColorHex: { type: Object, default: () => ({}) },
    selectedId:           { type: Number, default: null },
    aktif:                { type: Boolean, default: true },   // false: tidak menangkap klik (mis. saat mode atur posisi)
});
const emit = defineEmits(['select']);

const unit = computed(() => props.kavlings
    .filter(k => Array.isArray(k.bentuk) && k.bentuk.length >= 3)
    .map(k => ({ k, titik: k.bentuk.map(p => p.join(',')).join(' '), label: titikLabel(k.bentuk) })));

const hoverId = ref(null);
const hover = computed(() => unit.value.find(u => u.k.id === hoverId.value) ?? null);
const warna = (k) => props.statusConfig[k.status_jual]?.hex ?? '#94a3b8';
const tepi = (k) => props.statusBangunColorHex[k.status_bangun_stage_id] ?? '#64748b';
</script>

<template>
    <div class="absolute inset-0 pointer-events-none" style="container-type: inline-size;">
        <svg class="absolute inset-0 w-full h-full" viewBox="0 0 100 100" preserveAspectRatio="none">
            <polygon v-for="u in unit" :key="u.k.id" :points="u.titik"
                :fill="warna(u.k)" :fill-opacity="selectedId === u.k.id || hoverId === u.k.id ? 0.7 : 0.45"
                :stroke="selectedId === u.k.id ? '#ffffff' : tepi(u.k)" stroke-width="1.5" vector-effect="non-scaling-stroke"
                :class="aktif ? 'pointer-events-auto cursor-pointer' : ''"
                @mouseenter="hoverId = u.k.id" @mouseleave="hoverId = null" @click.stop="aktif && emit('select', u.k)" />
        </svg>
        <span v-for="u in unit" :key="'l' + u.k.id"
            class="absolute -translate-x-1/2 -translate-y-1/2 font-bold leading-none text-slate-900 select-none whitespace-nowrap"
            :style="`left:${u.label[0]}%; top:${u.label[1]}%; font-size:max(0.4rem, 0.9cqw); text-shadow:0 0 2px #fff, 0 0 2px #fff;`">{{ u.k.nomor_kavling }}</span>

        <div v-if="hover" class="absolute z-30 -translate-x-1/2 w-max max-w-[180px]"
            :style="`left:${hover.label[0]}%; top:${hover.label[1]}%; transform:translate(-50%, ${hover.label[1] < 25 ? '1.2rem' : 'calc(-100% - 0.75rem)'});`">
            <div class="bg-slate-950 border border-slate-700 rounded-lg px-2.5 py-1.5 shadow-xl text-left">
                <div class="text-white text-xs font-semibold">{{ hover.k.nomor_lengkap }}</div>
                <div class="flex items-center gap-1 mt-0.5">
                    <span class="w-1.5 h-1.5 rounded-full inline-block" :style="`background:${warna(hover.k)}`"></span>
                    <span class="text-slate-300 text-[0.6875rem]">{{ statusConfig[hover.k.status_jual]?.label }}</span>
                </div>
                <div class="flex items-center gap-1 mt-0.5">
                    <span class="w-1.5 h-1.5 rounded-full inline-block" :style="`background:${tepi(hover.k)}`"></span>
                    <span class="text-slate-400 text-[0.6875rem]">{{ hover.k.status_bangun_label }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

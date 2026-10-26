<script setup>
// Isian potongan promo untuk SATU baris biaya (Kelebihan Tanah / Biaya Tambahan). Promo hanya menentukan baris mana yang boleh dipotong;
// nominal atau persennya diisi di sini, per transaksi. Server menghitung ulang dan membatasi 0..biaya.
import { computed } from 'vue';
import MoneyInput from '@/Components/MoneyInput.vue';

const props = defineProps({
    label:      { type: String, required: true },
    bruto:      { type: Number, default: 0 },
    modelValue: { type: Object, default: () => ({ mode: 'persen', nilai: '' }) },
});
const emit = defineEmits(['update:modelValue']);

const mode = computed(() => props.modelValue?.mode || 'persen');
const nilai = computed(() => props.modelValue?.nilai ?? '');

const potongan = computed(() => {
    const n = Number(nilai.value);
    if (!n || props.bruto <= 0) return 0;
    const p = mode.value === 'persen' ? props.bruto * (n / 100) : n;
    return Math.max(0, Math.min(props.bruto, p));
});

const set = (patch) => emit('update:modelValue', { mode: mode.value, nilai: nilai.value, ...patch });
const penuh = () => emit('update:modelValue', { mode: 'persen', nilai: 100 });
const kosongkan = () => emit('update:modelValue', { mode: 'persen', nilai: '' });
const rp = (v) => 'Rp ' + Math.round(Number(v) || 0).toLocaleString('id-ID');
</script>

<template>
    <div class="bg-emerald-500/5 border border-emerald-500/20 rounded-lg px-3 py-2 space-y-1.5">
        <div class="flex items-center justify-between gap-2">
            <span class="text-emerald-300 text-xs font-medium">Potongan promo · {{ label }}</span>
            <span class="text-slate-500 text-[0.6875rem]">Biaya {{ rp(bruto) }}</span>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <label class="flex items-center gap-1 text-xs text-slate-400 cursor-pointer">
                <input type="radio" :checked="mode === 'persen'" class="accent-emerald-500" @change="set({ mode: 'persen' })" /> %
            </label>
            <label class="flex items-center gap-1 text-xs text-slate-400 cursor-pointer">
                <input type="radio" :checked="mode === 'nominal'" class="accent-emerald-500" @change="set({ mode: 'nominal' })" /> Rp
            </label>
            <MoneyInput :plain="mode === 'persen'" :model-value="nilai" :placeholder="mode === 'persen' ? '%' : 'Rp'"
                class="w-28 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-emerald-500"
                @update:model-value="set({ nilai: $event })" />
            <button type="button" class="px-2 py-1 bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-300 text-xs rounded" @click="penuh">Penuh</button>
            <button v-if="nilai !== '' && nilai !== null" type="button" class="text-slate-500 hover:text-slate-300 text-xs" @click="kosongkan">Hapus</button>
        </div>
        <div v-if="potongan > 0" class="text-xs text-slate-400">
            Potongan <span class="text-emerald-300">{{ rp(potongan) }}</span> → ditagih <span class="text-slate-200 font-medium">{{ rp(bruto - potongan) }}</span>
        </div>
    </div>
</template>

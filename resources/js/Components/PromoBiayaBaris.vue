<script setup>
// Satu baris "promo untuk satu biaya": dropdown promo (hanya promo yang memang menjadikan biaya ini lawannya) dan, bila promo dipilih,
// isian potongannya. Dipakai di form booking dan Ubah Rincian. Server tetap memeriksa ulang lawan promo dan menghitung potongan.
import PotonganPromoInput from '@/Components/PotonganPromoInput.vue';

defineProps({
    label:     { type: String, required: true },
    bruto:     { type: Number, default: 0 },
    options:   { type: Array, default: () => [] },     // promo yang boleh dipilih untuk biaya ini: [{ id, nama }]
    promoId:   { type: [Number, String], default: null },
    potongan:  { type: Object, default: () => ({ mode: 'persen', nilai: '' }) },
});
const emit = defineEmits(['update:promoId', 'update:potongan']);

const pilih = (e) => emit('update:promoId', e.target.value ? Number(e.target.value) : null);
</script>

<template>
    <div class="space-y-1.5">
        <div class="flex items-center gap-2">
            <span class="text-slate-400 text-xs w-36 shrink-0">{{ label }}</span>
            <select :value="promoId ?? ''" @change="pilih"
                class="flex-1 min-w-0 px-2.5 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                <option value="">Tanpa promo</option>
                <option v-for="p in options" :key="p.id" :value="p.id">{{ p.nama }}</option>
            </select>
        </div>
        <PotonganPromoInput v-if="promoId" :label="label" :bruto="bruto" :model-value="potongan"
            @update:model-value="emit('update:potongan', $event)" />
    </div>
</template>

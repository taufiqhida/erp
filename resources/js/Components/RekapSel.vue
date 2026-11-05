<script setup>
import { computed } from 'vue';

// Satu sel pada Rekap Kekurangan. Nominal selalu yang utama; warna dan teks kecil hanya penanda tambahan:
//   belum    = nominal (amber)
//   sebagian = SISA sebagai nominal utama, "dibayar X dari Y" kecil
//   lunas    = nominal (hijau) dengan penanda ✓ (dan tanggal masuk)
// Sel Booking Fee/DP/Pelunasan yang masih ada sisa menampilkan jatuh tempo cicilan terdekat; merah bila sudah lewat.
const props = defineProps({
    sel:  { type: Object, default: null },
    mode: { type: String, default: 'belum_cair' },
});

const rp = (v) => Math.round(Number(v) || 0).toLocaleString('id-ID');
const lunas = computed(() => props.sel?.status === 'lunas');
const nilai = computed(() => {
    if (!props.sel) return 0;
    if (props.mode === 'sudah_cair' || lunas.value) return props.sel.cair;

    return props.sel.sisa;
});
</script>

<template>
    <span v-if="!sel" class="text-slate-600" aria-label="Tidak ada">–</span>
    <div v-else class="leading-tight">
        <div class="font-semibold tabular-nums" :class="lunas ? 'text-emerald-400' : 'text-amber-300'">{{ rp(nilai) }}</div>
        <div v-if="lunas" class="text-[0.625rem] text-emerald-500/80">✓ masuk<span v-if="sel.tanggal" class="text-slate-500"> · {{ sel.tanggal }}</span></div>
        <template v-else>
            <div v-if="sel.status === 'sebagian'" class="text-[0.625rem] text-slate-500">dibayar {{ rp(sel.cair) }} dari {{ rp(sel.nominal) }}</div>
            <div v-if="sel.terlambat_hari" class="text-[0.625rem] text-rose-400">terlambat {{ sel.terlambat_hari }} hari</div>
            <div v-else-if="sel.jatuh_tempo_label" class="text-[0.625rem] text-slate-500">jatuh tempo {{ sel.jatuh_tempo_label }}</div>
        </template>
    </div>
</template>

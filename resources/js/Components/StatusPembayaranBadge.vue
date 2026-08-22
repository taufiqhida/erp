<script setup>
defineProps({
    status: { type: String, default: null }, // 'belum_bayar' | 'sebagian' | 'lunas' | null
});

const config = {
    lunas:       { label: 'Lunas',       cls: 'bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/30' },
    sebagian:    { label: 'Sebagian',    cls: 'bg-amber-500/15 text-amber-400 ring-1 ring-amber-500/30' },
    belum_bayar: { label: 'Belum Bayar', cls: 'bg-rose-500/15 text-rose-400 ring-1 ring-rose-500/30' },
};
</script>

<template>
    <span v-if="status && config[status]"
        :class="['inline-block px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap', config[status].cls]">
        {{ config[status].label }}
    </span>
    <span v-else class="text-slate-700 text-xs">—</span>
</template>

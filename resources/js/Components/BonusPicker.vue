<script setup>
// Pilih banyak bonus (tandon, kipas, AC, ...) sebagai chip yang bisa dinyalakan/dimatikan. Hanya label: tidak ada nominal.
const props = defineProps({
    options:    { type: Array, default: () => [] },      // [{ id, nama, is_active }]
    modelValue: { type: Array, default: () => [] },      // id bonus yang dipilih
});
const emit = defineEmits(['update:modelValue']);

const aktif = (id) => props.modelValue.includes(id);
const toggle = (id) => emit('update:modelValue', aktif(id) ? props.modelValue.filter(x => x !== id) : [...props.modelValue, id]);
</script>

<template>
    <div class="flex flex-wrap gap-2">
        <button v-for="o in options" :key="o.id" type="button" @click="toggle(o.id)"
            :class="aktif(o.id) ? 'bg-violet-600/25 border-violet-500 text-violet-200' : 'bg-slate-800 border-slate-700 text-slate-400 hover:text-slate-200'"
            class="px-3 py-1.5 rounded-full border text-xs transition-colors">
            <span v-if="aktif(o.id)">✓ </span>{{ o.nama }}<span v-if="o.is_active === false" class="text-slate-500"> (nonaktif)</span>
        </button>
        <p v-if="!options.length" class="text-slate-500 text-xs">Belum ada bonus di Pengaturan.</p>
    </div>
</template>

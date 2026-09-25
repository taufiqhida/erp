<script setup>
// Judul kolom yang bisa diklik untuk urut (sort di server). Klik kolom lain
// memakai arah bawaan `firstDir`; klik kolom yang sama membalik arahnya.
const props = defineProps({
    label:    { type: String, required: true },
    sortKey:  { type: String, required: true },
    current:  { type: String, default: '' },
    dir:      { type: String, default: 'desc' },
    firstDir: { type: String, default: 'desc' },
    align:    { type: String, default: 'left' },
});
const emit = defineEmits(['sort']);

const active = () => props.current === props.sortKey;
const click = () => emit('sort', props.sortKey, active() ? (props.dir === 'asc' ? 'desc' : 'asc') : props.firstDir);
</script>

<template>
    <th class="px-4 py-3 font-medium" :class="align === 'right' ? 'text-right' : 'text-left'">
        <button type="button" @click="click"
            class="uppercase tracking-wide hover:text-slate-300 transition-colors"
            :class="active() ? 'text-violet-400' : ''">
            {{ label }} <span v-if="active()">{{ dir === 'asc' ? '▲' : '▼' }}</span>
        </button>
    </th>
</template>

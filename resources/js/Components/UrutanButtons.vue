<script setup>
import { router } from '@inertiajs/vue3';

// Tombol geser naik/turun urutan master data — lihat PengaturanController::moveUrutan().
const props = defineProps({
    type:  { type: String, required: true },
    id:    { type: Number, required: true },
    first: { type: Boolean, default: false },
    last:  { type: Boolean, default: false },
});

const move = (direction) => {
    router.patch(route('pengaturan.urutan.move', { type: props.type, id: props.id, direction }), {}, { preserveScroll: true });
};
</script>

<template>
    <div class="flex flex-col flex-shrink-0">
        <button type="button" @click="move('up')" :disabled="first" title="Naikkan"
            class="text-slate-500 hover:text-violet-400 disabled:opacity-20 disabled:hover:text-slate-500 disabled:cursor-not-allowed leading-none">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path fill-rule="evenodd" d="M14.77 12.79a.75.75 0 01-1.06-.02L10 8.832 6.29 12.77a.75.75 0 11-1.08-1.04l4.25-4.5a.75.75 0 011.08 0l4.25 4.5a.75.75 0 01-.02 1.06z" clip-rule="evenodd"/></svg>
        </button>
        <button type="button" @click="move('down')" :disabled="last" title="Turunkan"
            class="text-slate-500 hover:text-violet-400 disabled:opacity-20 disabled:hover:text-slate-500 disabled:cursor-not-allowed leading-none">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
        </button>
    </div>
</template>

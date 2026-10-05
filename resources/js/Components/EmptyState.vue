<script setup>
import { Link } from '@inertiajs/vue3';

// Keadaan kosong yang menolong: jelaskan kenapa kosong dan tawarkan langkah berikutnya.
// `filtered` = kosong karena filter/pencarian (bukan karena memang belum ada data).
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
    actionLabel: { type: String, default: '' },
    actionHref: { type: String, default: '' },
    filtered: { type: Boolean, default: false },
});
defineEmits(['reset']);
</script>

<template>
    <div class="flex flex-col items-center text-center py-10 px-4">
        <div class="w-12 h-12 rounded-full bg-slate-800 flex items-center justify-center text-slate-500 mb-3">
            <svg v-if="filtered" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
            </svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
            </svg>
        </div>
        <div class="text-slate-300 text-sm font-medium">{{ title }}</div>
        <p v-if="description" class="mt-1 text-slate-500 text-xs max-w-sm">{{ description }}</p>
        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
            <button v-if="filtered" type="button" @click="$emit('reset')"
                class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium transition-colors">
                Reset filter
            </button>
            <Link v-if="actionHref && actionLabel" :href="actionHref"
                class="px-3 py-1.5 rounded-lg bg-violet-600 hover:bg-violet-500 text-white text-xs font-medium transition-colors">
                {{ actionLabel }}
            </Link>
            <slot />
        </div>
    </div>
</template>

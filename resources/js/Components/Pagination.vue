<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

// Paginasi seragam untuk semua tabel server-side: info "Menampilkan x–y dari z", tombol halaman,
// dan pilihan baris per halaman (20/50/100, diingat server per halaman — lihat Controller::perPage()).
const props = defineProps({
    paginator: { type: Object, required: true },
    only: { type: Array, default: () => [] },          // partial reload (mis. ['kavlingsPage'])
    embedded: { type: Boolean, default: false },       // di dalam kartu (garis atas), bukan berdiri sendiri
    options: { type: Array, default: () => [20, 50, 100] },
});

const terkecil = computed(() => Math.min(...props.options));
// Tampil kalau data lebih banyak dari satu halaman terkecil, atau user sudah memilih >terkecil
// (supaya selalu bisa kembali ke pilihan semula).
const tampil = computed(() => props.paginator.total > terkecil.value || props.paginator.per_page > terkecil.value);

const label = (l) => String(l.label)
    .replace('&laquo; Previous', '‹ Sebelumnya')
    .replace('Next &raquo;', 'Berikutnya ›');

const ubahPerHalaman = (e) => {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', e.target.value);
    url.searchParams.delete('page');
    router.get(url.pathname, Object.fromEntries(url.searchParams), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        ...(props.only.length ? { only: props.only } : {}),
    });
};
</script>

<template>
    <div v-if="tampil"
        :class="['flex flex-wrap items-center justify-between gap-3', embedded ? 'border-t border-slate-800 px-5 py-3.5' : 'pt-1']">
        <span class="text-slate-500 text-xs">
            Menampilkan {{ paginator.from ?? 0 }}–{{ paginator.to ?? 0 }} dari {{ paginator.total }}
        </span>

        <div v-if="paginator.last_page > 1" class="flex flex-wrap gap-1">
            <Link v-for="(link, i) in paginator.links" :key="i"
                :href="link.url ?? '#'" v-html="label(link)"
                preserve-scroll preserve-state :only="only.length ? only : undefined"
                :class="['px-3 py-1.5 text-xs rounded-md transition-colors',
                    link.active ? 'bg-violet-600 text-white' : 'text-slate-400 hover:bg-slate-800 bg-slate-900',
                    !link.url ? 'opacity-40 pointer-events-none' : '']" />
        </div>

        <label class="flex items-center gap-2 text-slate-500 text-xs">
            Per halaman
            <select :value="paginator.per_page" @change="ubahPerHalaman"
                class="bg-slate-800 border border-slate-700 rounded-md text-slate-300 text-xs py-1 pl-2 pr-6 focus:outline-none focus:ring-1 focus:ring-violet-500">
                <option v-for="o in options" :key="o" :value="o">{{ o }}</option>
            </select>
        </label>
    </div>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    projects: Array,
});

const formatRupiah = (v) => v
    ? 'Rp ' + Number(v).toLocaleString('id-ID')
    : '-';
</script>

<template>
    <Head title="Penjualan" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <span class="text-slate-200 font-medium">Penjualan</span>
            </div>
        </template>

        <div class="p-6 space-y-6">
            <div>
                <h1 class="text-white font-bold text-xl">Menu Penjualan</h1>
                <p class="text-slate-400 text-sm mt-0.5">Pilih proyek untuk mulai proses penjualan</p>
            </div>

            <div v-if="!projects.length" class="text-center py-20 text-slate-500">
                Tidak ada proyek yang ditugaskan kepada Anda.
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                <Link
                    v-for="project in projects"
                    :key="project.id"
                    :href="route('penjualan.project', project.id)"
                    class="group bg-slate-900 border border-slate-800 rounded-xl overflow-hidden hover:border-violet-500/50 transition-all duration-200 hover:shadow-lg hover:shadow-violet-500/10"
                >
                    <!-- Siteplan preview or placeholder -->
                    <div class="relative h-36 bg-slate-800 overflow-hidden">
                        <img
                            v-if="project.siteplan_image"
                            :src="project.siteplan_image"
                            class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                            alt="Siteplan"
                        />
                        <div v-else class="w-full h-full flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-12 h-12 text-slate-600">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z" />
                            </svg>
                        </div>
                        <!-- Status badge -->
                        <div class="absolute top-2 right-2">
                            <span class="px-2 py-0.5 bg-emerald-500/20 text-emerald-400 text-xs rounded-full ring-1 ring-emerald-500/30 font-medium">
                                Aktif
                            </span>
                        </div>
                    </div>

                    <div class="p-4">
                        <div class="mb-3">
                            <div class="text-slate-200 font-semibold text-base group-hover:text-violet-300 transition-colors">{{ project.nama }}</div>
                            <div class="text-slate-500 text-xs mt-0.5">{{ project.kode }} · {{ project.kota }}</div>
                        </div>

                        <!-- Progress bar -->
                        <div class="mb-3">
                            <div class="flex justify-between text-xs text-slate-400 mb-1">
                                <span>Progress Penjualan</span>
                                <span class="text-violet-400 font-medium">{{ project.progress }}%</span>
                            </div>
                            <div class="h-1.5 bg-slate-700 rounded-full overflow-hidden">
                                <div
                                    class="h-full bg-gradient-to-r from-violet-500 to-indigo-500 rounded-full transition-all duration-500"
                                    :style="`width: ${project.progress}%`"
                                />
                            </div>
                        </div>

                        <!-- Stats -->
                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div class="bg-slate-800/60 rounded-lg py-2">
                                <div class="text-emerald-400 font-bold text-sm">{{ project.kavlings_available }}</div>
                                <div class="text-slate-500 text-xs">Tersedia</div>
                            </div>
                            <div class="bg-slate-800/60 rounded-lg py-2">
                                <div class="text-blue-400 font-bold text-sm">{{ project.kavlings_booked }}</div>
                                <div class="text-slate-500 text-xs">Booking</div>
                            </div>
                            <div class="bg-slate-800/60 rounded-lg py-2">
                                <div class="text-rose-400 font-bold text-sm">{{ project.kavlings_sold }}</div>
                                <div class="text-slate-500 text-xs">Terjual</div>
                            </div>
                        </div>
                    </div>

                    <!-- CTA -->
                    <div class="px-4 pb-4">
                        <div class="flex items-center justify-center gap-2 py-2 bg-violet-600/10 hover:bg-violet-600/20 border border-violet-500/20 rounded-lg text-violet-400 text-sm font-medium transition-colors group-hover:border-violet-500/40">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                            </svg>
                            Buka Siteplan
                        </div>
                    </div>
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

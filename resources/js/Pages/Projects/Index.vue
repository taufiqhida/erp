<script setup>
import BerandaLayout from '@/Layouts/BerandaLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    projects: Object,
    filters: Object,
});

const page = usePage();
const permissions = computed(() => page.props.auth?.user?.permissions ?? []);
const canCreate = computed(() => permissions.value.includes('create projects'));
const canEdit   = computed(() => permissions.value.includes('edit projects'));
const canDelete = computed(() => permissions.value.includes('delete projects'));
const roles = computed(() => page.props.auth?.user?.roles ?? []);
const canManageRoles = computed(() => roles.value.includes('superadmin'));
const canManagePengaturan = computed(() => roles.value.includes('superadmin') || roles.value.includes('manajer'));

const search = ref(props.filters?.search ?? '');
const isActive = ref(props.filters?.is_active ?? '');

let searchTimer;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters(), 400);
});
watch(isActive, () => applyFilters());

const applyFilters = () => {
    router.get(route('beranda'), {
        search: search.value || undefined,
        is_active: isActive.value !== '' ? isActive.value : undefined,
    }, { preserveState: true, replace: true });
};

const deleteProject = (project) => {
    if (!confirm(`Hapus proyek "${project.nama}"? Data akan diarsipkan.`)) return;
    router.delete(route('projects.destroy', project.id));
};
</script>

<template>
    <Head title="Pilih Proyek" />
    <BerandaLayout>
        <div class="p-6 space-y-5">
            <!-- Header Row -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-white font-bold text-xl">Pilih Proyek</h1>
                    <p class="text-slate-400 text-sm mt-0.5">Pilih proyek untuk mulai bekerja, atau lihat semua proyek sekaligus</p>
                </div>
                <div class="flex items-center gap-2">
                    <Link v-if="canManagePengaturan" :href="route('pengaturan.profil-developer')"
                        class="inline-flex items-center gap-1.5 px-3 py-2 text-slate-400 hover:text-slate-200 border border-slate-800 rounded-lg text-sm transition-colors">
                        ⚙️ Pengaturan
                    </Link>
                    <Link v-if="canManageRoles" :href="route('roles.index')"
                        class="inline-flex items-center gap-1.5 px-3 py-2 text-slate-400 hover:text-slate-200 border border-slate-800 rounded-lg text-sm transition-colors">
                        👥 Manajemen Role
                    </Link>
                    <Link
                        v-if="canCreate"
                        :href="route('projects.create')"
                        class="inline-flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20 hover:shadow-violet-500/30"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                            <path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" />
                        </svg>
                        Tambah Proyek
                    </Link>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="flex flex-col sm:flex-row gap-3">
                <div class="relative flex-1 max-w-xs">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Cari nama, kode, kota..."
                        class="w-full pl-9 pr-4 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500 focus:border-violet-500"
                    />
                </div>
                <select
                    v-model="isActive"
                    class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                >
                    <option value="">Semua Status</option>
                    <option value="true">Aktif</option>
                    <option value="false">Non-aktif</option>
                </select>
            </div>

            <!-- Cards -->
            <div v-if="!projects.data.length" class="text-center py-20 text-slate-500">
                Tidak ada proyek ditemukan.
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                <!-- Semua Proyek: keluar dari context 1 proyek, lihat data gabungan
                     lintas-proyek di menu Konsumen/Keuangan/Pembatalan -->
                <Link :href="route('projects.clear-active')"
                    class="group bg-slate-900 border border-dashed border-slate-700 rounded-xl overflow-hidden hover:border-violet-500/50 transition-all duration-200 flex flex-col items-center justify-center text-center p-6 min-h-[220px]">
                    <div class="w-14 h-14 rounded-full bg-violet-600/10 flex items-center justify-center mb-3 group-hover:bg-violet-600/20 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7 text-violet-400">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                        </svg>
                    </div>
                    <div class="text-slate-200 font-semibold text-base group-hover:text-violet-300 transition-colors">🏢 Semua Proyek</div>
                    <p class="text-slate-500 text-xs mt-1">Lihat data gabungan lintas-proyek di Konsumen, Keuangan &amp; Pembatalan</p>
                </Link>

                <div
                    v-for="project in projects.data"
                    :key="project.id"
                    class="group bg-slate-900 border border-slate-800 rounded-xl overflow-hidden hover:border-violet-500/50 transition-all duration-200 hover:shadow-lg hover:shadow-violet-500/10"
                >
                    <Link :href="route('projects.show', project.id)" class="block">
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
                                <span
                                    :class="project.is_active
                                        ? 'bg-emerald-500/20 text-emerald-400 ring-1 ring-emerald-500/30'
                                        : 'bg-slate-800/80 text-slate-400 ring-1 ring-slate-700'"
                                    class="px-2 py-0.5 text-xs rounded-full font-medium"
                                >
                                    {{ project.is_active ? 'Aktif' : 'Non-aktif' }}
                                </span>
                            </div>
                        </div>

                        <div class="p-4 pb-0">
                            <div class="mb-3">
                                <div class="text-slate-200 font-semibold text-base group-hover:text-violet-300 transition-colors">{{ project.nama }}</div>
                                <div class="text-slate-500 text-xs mt-0.5">{{ project.kode }} · {{ project.kota ?? '-' }}</div>
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
                                    <div class="text-slate-500 text-xs">Dipesan</div>
                                </div>
                                <div class="bg-slate-800/60 rounded-lg py-2">
                                    <div class="text-rose-400 font-bold text-sm">{{ project.kavlings_sold }}</div>
                                    <div class="text-slate-500 text-xs">Terjual</div>
                                </div>
                            </div>
                        </div>
                    </Link>

                    <!-- Aksi -->
                    <div class="p-4 flex items-center gap-2">
                        <Link
                            :href="route('projects.show', project.id)"
                            class="flex-1 flex items-center justify-center gap-2 py-2 bg-violet-600/10 hover:bg-violet-600/20 border border-violet-500/20 rounded-lg text-violet-400 text-sm font-medium transition-colors group-hover:border-violet-500/40"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                            </svg>
                            Buka Proyek
                        </Link>
                        <Link
                            v-if="canEdit"
                            :href="route('projects.edit', project.id)"
                            class="p-2 text-slate-500 hover:text-amber-400 hover:bg-amber-400/10 border border-slate-800 rounded-lg transition-colors"
                            title="Edit"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                            </svg>
                        </Link>
                        <button
                            v-if="canDelete"
                            @click="deleteProject(project)"
                            class="p-2 text-slate-500 hover:text-rose-400 hover:bg-rose-400/10 border border-slate-800 rounded-lg transition-colors"
                            title="Hapus"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="projects.last_page > 1" class="flex items-center justify-between">
                <div class="text-slate-500 text-xs">
                    {{ projects.from }}–{{ projects.to }} dari {{ projects.total }} proyek
                </div>
                <div class="flex gap-1">
                    <Link
                        v-for="link in projects.links"
                        :key="link.label"
                        :href="link.url ?? '#'"
                        v-html="link.label"
                        :class="[
                            'px-3 py-1.5 text-xs rounded-md transition-colors',
                            link.active ? 'bg-violet-600 text-white' : 'text-slate-400 hover:bg-slate-800 bg-slate-900',
                            !link.url ? 'opacity-40 pointer-events-none' : ''
                        ]"
                    />
                </div>
            </div>
        </div>
    </BerandaLayout>
</template>

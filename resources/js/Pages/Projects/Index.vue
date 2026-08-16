<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
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

const search = ref(props.filters?.search ?? '');
const isActive = ref(props.filters?.is_active ?? '');

let searchTimer;
watch(search, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters(), 400);
});
watch(isActive, () => applyFilters());

const applyFilters = () => {
    router.get(route('projects.index'), {
        search: search.value || undefined,
        is_active: isActive.value !== '' ? isActive.value : undefined,
    }, { preserveState: true, replace: true });
};

const deleteProject = (project) => {
    if (!confirm(`Hapus proyek "${project.nama}"? Data akan diarsipkan.`)) return;
    router.delete(route('projects.destroy', project.id));
};

const formatHarga = (n) => n ? new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n) : '-';
</script>

<template>
    <Head title="Proyek" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <span class="text-slate-500">Beranda</span>
                <span>/</span>
                <span class="text-slate-200 font-medium">Proyek</span>
            </div>
        </template>

        <div class="p-6 space-y-5">
            <!-- Header Row -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-white font-bold text-xl">Daftar Proyek</h1>
                    <p class="text-slate-400 text-sm mt-0.5">Kelola semua proyek properti</p>
                </div>
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

            <!-- Table -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-800">
                                <th class="text-left px-5 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Proyek</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Lokasi</th>
                                <th class="text-center px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Kavling</th>
                                <th class="text-center px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Progress</th>
                                <th class="text-center px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Status</th>
                                <th class="text-right px-5 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/70">
                            <tr v-if="!projects.data.length">
                                <td colspan="6" class="text-center py-12 text-slate-500">Tidak ada proyek ditemukan.</td>
                            </tr>
                            <tr
                                v-for="project in projects.data"
                                :key="project.id"
                                class="hover:bg-slate-800/30 transition-colors group"
                            >
                                <td class="px-5 py-4">
                                    <Link :href="route('projects.show', project.id)" class="block">
                                        <div class="text-slate-200 font-medium hover:text-violet-300 transition-colors">{{ project.nama }}</div>
                                        <div class="text-slate-500 text-xs mt-0.5">{{ project.kode }}</div>
                                    </Link>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="text-slate-300 text-sm">{{ project.kota ?? '-' }}</div>
                                    <div class="text-slate-500 text-xs truncate max-w-[160px]">{{ project.lokasi ?? '' }}</div>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <div class="text-slate-200 font-medium">{{ project.kavlings_count }}</div>
                                    <div class="text-slate-500 text-xs">{{ project.kavlings_sold }} terjual</div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="w-24 mx-auto">
                                        <div class="h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                            <div
                                                class="h-full bg-gradient-to-r from-violet-500 to-indigo-500 rounded-full"
                                                :style="{ width: project.progress + '%' }"
                                            />
                                        </div>
                                        <div class="text-slate-500 text-xs text-center mt-1">{{ project.progress }}%</div>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span
                                        :class="project.is_active
                                            ? 'bg-emerald-500/10 text-emerald-400 ring-1 ring-emerald-500/20'
                                            : 'bg-slate-800 text-slate-400 ring-1 ring-slate-700'"
                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                    >
                                        {{ project.is_active ? 'Aktif' : 'Non-aktif' }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <Link
                                            :href="route('projects.show', project.id)"
                                            class="p-1.5 text-slate-500 hover:text-violet-400 hover:bg-violet-400/10 rounded-md transition-colors"
                                            title="Lihat"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            </svg>
                                        </Link>
                                        <Link
                                            v-if="canEdit"
                                            :href="route('projects.edit', project.id)"
                                            class="p-1.5 text-slate-500 hover:text-amber-400 hover:bg-amber-400/10 rounded-md transition-colors"
                                            title="Edit"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                            </svg>
                                        </Link>
                                        <button
                                            v-if="canDelete"
                                            @click="deleteProject(project)"
                                            class="p-1.5 text-slate-500 hover:text-rose-400 hover:bg-rose-400/10 rounded-md transition-colors"
                                            title="Hapus"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="projects.last_page > 1" class="flex items-center justify-between px-5 py-3.5 border-t border-slate-800">
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
                                link.active ? 'bg-violet-600 text-white' : 'text-slate-400 hover:bg-slate-800',
                                !link.url ? 'opacity-40 pointer-events-none' : ''
                            ]"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

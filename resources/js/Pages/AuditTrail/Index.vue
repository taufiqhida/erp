<script setup>
import Pagination from '@/Components/Pagination.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    rows:          Object, // paginated
    filters:       Object,
    filterOptions: Object,
});

const search       = ref(props.filters?.search ?? '');
const causerId     = ref(props.filters?.causer_id ?? '');
const subjectType  = ref(props.filters?.subject_type ?? '');
const event        = ref(props.filters?.event ?? '');
const from         = ref(props.filters?.from ?? '');
const to           = ref(props.filters?.to ?? '');

const go = () => {
    router.get(route('audit-trail.index'), {
        search: search.value || undefined,
        causer_id: causerId.value || undefined,
        subject_type: subjectType.value || undefined,
        event: event.value || undefined,
        from: from.value || undefined,
        to: to.value || undefined,
    }, { preserveState: true, replace: true });
};

const resetFilters = () => {
    search.value = ''; causerId.value = ''; subjectType.value = ''; event.value = ''; from.value = ''; to.value = '';
    go();
};
const hasFilter = () => search.value || causerId.value || subjectType.value || event.value || from.value || to.value;

const eventBadge = {
    created: 'bg-emerald-500/15 text-emerald-400',
    updated: 'bg-amber-500/15 text-amber-400',
    deleted: 'bg-rose-500/15 text-rose-400',
};

const expandedId = ref(null);
const toggleExpand = (id) => { expandedId.value = expandedId.value === id ? null : id; };

const formatValue = (v) => {
    if (v === null || v === undefined || v === '') return '-';
    if (typeof v === 'boolean') return v ? 'Ya' : 'Tidak';
    return String(v);
};
</script>

<template>
    <Head title="Audit Trail" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <Link :href="route('beranda')" class="hover:text-slate-200 transition-colors">Beranda</Link>
                <span>/</span>
                <span class="text-slate-200 font-medium">Audit Trail</span>
            </div>
        </template>

        <div class="p-6 space-y-5">
            <div>
                <h1 class="text-white font-bold text-xl">Audit Trail</h1>
                <p class="text-slate-400 text-sm mt-0.5">Riwayat perubahan data — siapa mengubah apa, kapan, dari nilai apa ke nilai apa.</p>
            </div>

            <!-- Filter -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-wrap items-center gap-3">
                <input v-model="search" @keyup.enter="go" type="text" placeholder="Cari deskripsi..."
                    class="flex-1 min-w-[200px] px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500" />
                <select v-model="causerId" @change="go" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                    <option value="">Semua Pengguna</option>
                    <option v-for="u in filterOptions.users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <select v-model="subjectType" @change="go" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                    <option value="">Semua Jenis Data</option>
                    <option v-for="s in filterOptions.subjects" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
                <select v-model="event" @change="go" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                    <option value="">Semua Aksi</option>
                    <option v-for="e in filterOptions.events" :key="e.value" :value="e.value">{{ e.label }}</option>
                </select>
                <input v-model="from" @change="go" type="date" title="Dari tanggal"
                    class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                <input v-model="to" @change="go" type="date" title="Sampai tanggal"
                    class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                <button v-if="hasFilter()" @click="resetFilters" class="px-2.5 py-1.5 text-slate-400 hover:text-slate-200 text-xs rounded-lg transition-colors">
                    Reset
                </button>
            </div>

            <!-- Tabel -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium">Waktu</th>
                                <th class="px-4 py-3 text-left font-medium">Pengguna</th>
                                <th class="px-4 py-3 text-left font-medium">Aksi</th>
                                <th class="px-4 py-3 text-left font-medium">Jenis Data</th>
                                <th class="px-4 py-3 text-left font-medium">Deskripsi</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="row in rows.data" :key="row.id">
                                <tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition-colors">
                                    <td class="px-4 py-3 text-slate-400 text-xs whitespace-nowrap">{{ row.waktu }}</td>
                                    <td class="px-4 py-3 text-slate-200">{{ row.causer_nama }}</td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="eventBadge[row.event] ?? 'bg-slate-700 text-slate-400'">
                                            {{ row.event_label }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-slate-400 text-xs">{{ row.subject_label }} <span class="text-slate-600">#{{ row.subject_id }}</span></td>
                                    <td class="px-4 py-3 text-slate-300">{{ row.description }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <button v-if="row.changes.length" @click="toggleExpand(row.id)"
                                            class="text-xs text-violet-400 hover:text-violet-300 transition-colors whitespace-nowrap">
                                            {{ expandedId === row.id ? 'Tutup' : `${row.changes.length} perubahan` }}
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="expandedId === row.id" class="bg-slate-800/40 border-b border-slate-800/50">
                                    <td colspan="6" class="px-4 py-3">
                                        <table class="w-full text-xs">
                                            <thead class="text-slate-500">
                                                <tr>
                                                    <th class="text-left font-medium pb-1.5 pr-4">Field</th>
                                                    <th class="text-left font-medium pb-1.5 pr-4">Dari</th>
                                                    <th class="text-left font-medium pb-1.5">Ke</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="c in row.changes" :key="c.field">
                                                    <td class="text-slate-400 font-mono pr-4 py-0.5">{{ c.field }}</td>
                                                    <td class="text-rose-400/80 pr-4 py-0.5">{{ formatValue(c.dari) }}</td>
                                                    <td class="text-emerald-400/80 py-0.5">{{ formatValue(c.ke) }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!rows.data.length">
                                <td colspan="6" class="px-4 py-12 text-center text-slate-600">Belum ada aktivitas tercatat.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <Pagination :paginator="rows" embedded />
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';
import SortTh from '@/Components/SortTh.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

// SPK Unit — daftar SPK pembangunan per unit. Kartu status juga berfungsi sebagai filter (Berjalan / Lewat deadline / Selesai).
// Disort dan dipaginasi di server; nilai SPK hanya dikirim ke yang berhak melihatnya.
const props = defineProps({
    project:           Object,
    rows:              Object,   // paginated
    kartu:             Object,   // {total, berjalan, lewat_deadline, selesai}
    filters:           Object,
    kontraktorOptions: Array,
    canManage:         Boolean,
    lihatNilai:        Boolean,
});

const search = ref(props.filters?.search ?? '');
const kontraktor = ref(props.filters?.kontraktor_id ?? '');
const status = computed(() => props.filters?.status ?? 'berjalan');
const urut = computed(() => props.filters?.urut ?? 'deadline');
const arah = computed(() => props.filters?.arah ?? 'asc');
const buka = ref(null);   // id SPK yang barisnya dibuka (unit & catatan lengkap)

const go = (override = {}) => {
    router.get(route('spk.index', props.project.id), {
        status: status.value,
        search: search.value || undefined,
        kontraktor_id: kontraktor.value || undefined,
        urut: urut.value,
        arah: arah.value,
        ...override,
    }, { preserveState: true, replace: true });
};
// Ganti status = kembali ke urutan awal status itu (server yang menentukan).
const setStatus = (s) => go({ status: s, urut: undefined, arah: undefined });
const setSort = (key, direction) => go({ urut: key, arah: direction });

let debounce = null;
watch(search, () => { clearTimeout(debounce); debounce = setTimeout(() => go(), 300); });

const kartuList = computed(() => [
    { key: 'berjalan',       label: 'Berjalan',       nilai: props.kartu.berjalan,       warna: 'text-white',        info: 'Masih ada unit yang belum selesai dibangun' },
    { key: 'lewat_deadline', label: 'Lewat deadline', nilai: props.kartu.lewat_deadline, warna: props.kartu.lewat_deadline > 0 ? 'text-rose-400' : 'text-slate-300', info: 'SPK berjalan yang tanggal deadline-nya sudah lewat' },
    { key: 'selesai',        label: 'Selesai',        nilai: props.kartu.selesai,        warna: 'text-emerald-400',  info: 'Semua unit sudah selesai dibangun' },
]);

const badge = {
    safe:     'bg-emerald-500/15 text-emerald-400',
    warning:  'bg-amber-500/15 text-amber-400',
    critical: 'bg-orange-500/15 text-orange-400',
    expired:  'bg-rose-500/15 text-rose-400',
    selesai:  'bg-slate-700/50 text-slate-400',
};
const sisaHariLabel = (r) => {
    if (r.status === 'selesai') return 'Selesai';
    if (r.sisa_hari < 0) return `lewat ${Math.abs(r.sisa_hari)} hari`;
    if (r.sisa_hari === 0) return 'hari ini';
    return `${r.sisa_hari} hari lagi`;
};

const formatRp = (v) => 'Rp ' + Math.round(Number(v ?? 0)).toLocaleString('id-ID');
const ringkas = (t, n = 48) => (t && t.length > n ? t.slice(0, n).trimEnd() + '…' : t);
const kolomJumlah = computed(() => (props.lihatNilai ? 8 : 7));
const hasFilter = computed(() => !!(search.value || kontraktor.value));
const reset = () => { search.value = ''; kontraktor.value = ''; go({ search: undefined, kontraktor_id: undefined }); };
</script>

<template>
    <Head :title="`SPK Unit – ${project.nama}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <span>Proyek &amp; Teknik</span><span>/</span>
                <span class="text-slate-200 font-medium">SPK Unit — {{ project.nama }}</span>
            </div>
        </template>

        <div class="p-6 space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-white text-xl font-bold">SPK Unit</h1>
                    <p class="text-slate-400 text-sm mt-0.5">Surat Perintah Kerja pembangunan per unit: kontraktor, deadline, dan nilainya.</p>
                </div>
                <Link v-if="canManage" :href="route('spk.create', project.id)"
                    class="inline-flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                        <path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" />
                    </svg>
                    Buat SPK
                </Link>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <button v-for="k in kartuList" :key="k.key" type="button" @click="setStatus(k.key)" :title="k.info"
                    :class="status === k.key ? 'border-violet-500/60 bg-violet-600/10' : 'border-slate-800 bg-slate-900 hover:border-slate-700'"
                    class="text-left border rounded-xl px-4 py-3 transition-colors">
                    <div class="text-slate-500 text-[0.6875rem]">{{ k.label }}</div>
                    <div class="text-2xl font-bold leading-tight mt-0.5" :class="k.warna">{{ k.nilai }}</div>
                    <div v-if="k.key === 'berjalan' && kartu.lewat_deadline > 0" class="text-rose-400 text-[0.6875rem] mt-0.5">{{ kartu.lewat_deadline }} lewat deadline</div>
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div class="relative flex-1 min-w-[14rem]">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                        class="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                    <input v-model="search" type="text" placeholder="Cari nomor SPK..."
                        class="w-full pl-9 pr-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-xs placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500" />
                </div>
                <select v-model="kontraktor" @change="go()"
                    class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                    <option value="">Semua Kontraktor</option>
                    <option v-for="k in kontraktorOptions" :key="k.id" :value="k.id">{{ k.nama }}</option>
                </select>
                <button v-if="status !== 'semua'" type="button" @click="setStatus('semua')" class="px-2 py-1.5 text-slate-500 hover:text-slate-300 text-xs transition-colors">Tampilkan semua status</button>
                <button v-if="hasFilter" type="button" @click="reset" class="px-2 py-1.5 text-slate-500 hover:text-slate-300 text-xs transition-colors">✕ Reset</button>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wide">
                            <tr>
                                <SortTh label="Nomor SPK" sort-key="nomor" :current="urut" :dir="arah" first-dir="asc" @sort="setSort" />
                                <SortTh label="Kontraktor" sort-key="kontraktor" :current="urut" :dir="arah" first-dir="asc" @sort="setSort" />
                                <SortTh label="Unit" sort-key="unit" :current="urut" :dir="arah" @sort="setSort" />
                                <SortTh label="Terbit" sort-key="terbit" :current="urut" :dir="arah" @sort="setSort" />
                                <SortTh label="Deadline" sort-key="deadline" :current="urut" :dir="arah" first-dir="asc" @sort="setSort" />
                                <SortTh v-if="lihatNilai" label="Nilai SPK" sort-key="nilai" :current="urut" :dir="arah" align="right" @sort="setSort" />
                                <th class="px-4 py-3 text-left font-medium">Catatan</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="r in rows.data" :key="r.id">
                                <tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition-colors">
                                    <td class="px-4 py-3 text-slate-200 font-mono font-medium whitespace-nowrap">{{ r.nomor_spk }}</td>
                                    <td class="px-4 py-3 text-slate-300">{{ r.kontraktor_nama }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <button type="button" @click="buka = buka === r.id ? null : r.id" class="text-slate-300 hover:text-violet-300 transition-colors text-xs">
                                            <span class="text-slate-500">{{ buka === r.id ? '▾' : '▸' }}</span>
                                            {{ r.jumlah_unit }} unit
                                            <span class="text-slate-500">· {{ r.jumlah_selesai }} selesai</span>
                                        </button>
                                    </td>
                                    <td class="px-4 py-3 text-slate-400 text-xs whitespace-nowrap">{{ r.tanggal_terbit }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="text-xs text-slate-300">{{ r.tanggal_deadline }}</div>
                                        <span :class="badge[r.deadline_status]" class="inline-block mt-0.5 px-2 py-0.5 rounded-full text-[0.625rem] font-medium">{{ sisaHariLabel(r) }}</span>
                                    </td>
                                    <td v-if="lihatNilai" class="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                                        <span v-if="r.nilai_spk !== null && r.nilai_spk !== undefined" class="text-slate-200 font-medium">{{ formatRp(r.nilai_spk) }}</span>
                                        <span v-else class="text-slate-600">-</span>
                                    </td>
                                    <td class="px-4 py-3 max-w-[16rem]">
                                        <button v-if="r.catatan" type="button" @click="buka = buka === r.id ? null : r.id" class="text-left text-slate-400 hover:text-slate-200 text-xs transition-colors">{{ ringkas(r.catatan) }}</button>
                                        <span v-else class="text-slate-600 text-xs">-</span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <Link v-if="canManage" :href="route('spk.edit', [project.id, r.id])" title="Edit SPK"
                                            class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-slate-500 hover:text-violet-400 hover:bg-slate-800 transition-colors">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                            </svg>
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-if="buka === r.id" class="border-b border-slate-800/50 bg-slate-800/20">
                                    <td :colspan="kolomJumlah" class="px-5 py-3 space-y-2">
                                        <div v-if="r.catatan">
                                            <div class="text-slate-500 text-[0.6875rem] uppercase tracking-wide mb-0.5">Catatan</div>
                                            <p class="text-slate-300 text-xs whitespace-pre-wrap">{{ r.catatan }}</p>
                                        </div>
                                        <div>
                                            <div class="text-slate-500 text-[0.6875rem] uppercase tracking-wide mb-1">Unit ({{ r.jumlah_unit }})</div>
                                            <div class="flex flex-wrap gap-1.5">
                                                <span v-for="u in r.unit" :key="u.id"
                                                    :class="u.selesai ? 'bg-emerald-500/10 text-emerald-300' : 'bg-slate-800 text-slate-300'"
                                                    class="px-2 py-0.5 rounded-md text-xs">
                                                    {{ u.nomor }} <span class="text-slate-500">· {{ u.selesai ? 'selesai' : Math.round(u.progress) + '%' }}</span>
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!rows.data.length">
                                <td :colspan="kolomJumlah">
                                    <EmptyState :filtered="hasFilter || status !== 'semua'"
                                        :title="status === 'berjalan' ? 'Tidak ada SPK yang sedang berjalan' : (status === 'lewat_deadline' ? 'Tidak ada SPK yang lewat deadline' : 'Tidak ada SPK yang cocok')"
                                        description="Ubah status atau filter di atas, atau buat SPK baru."
                                        @reset="reset" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <Pagination :paginator="rows" />
        </div>
    </AuthenticatedLayout>
</template>

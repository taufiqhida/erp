<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    pembayaran:    Array,
    kprMonitoring: Array,
    kprSummary:    Object,
    sbumData:      Array,
    sbumSummary:   Object,
    projects:      Array,
    filters:       Object,
});

const activeTab  = ref('pembayaran');
const filterProject = ref(props.filters?.project_id ?? '');

const applyFilter = () => {
    router.get(route('keuangan.index'), { project_id: filterProject.value }, { preserveState: true, replace: true });
};

const formatRp = (v) => v
    ? 'Rp ' + Number(v).toLocaleString('id-ID')
    : '-';

const tabs = [
    { key: 'pembayaran', label: '💳 Pembayaran Konsumen' },
    { key: 'kpr',        label: '🏦 Pencairan KPR & Dajam' },
    { key: 'sbum',       label: '📋 Klaim SBUM' },
];

// Update KPR form
const kprForm = useForm({ transaksi_id: null, realisasi_cair: '', dajam_ditahan: '' });
const editingKpr = ref(null);
const openKprEdit = (row) => {
    editingKpr.value = row.id;
    kprForm.realisasi_cair = row.realisasi_cair;
    kprForm.dajam_ditahan  = row.dajam_ditahan;
};
const saveKpr = (id) => {
    kprForm.patch(route('keuangan.update-kpr', id), {
        onSuccess: () => { editingKpr.value = null; }
    });
};

// SBUM status map
const sbumStatusConfig = {
    belum:   { label: 'Belum Diajukan', cls: 'text-slate-400 bg-slate-800' },
    proses:  { label: 'Dalam Proses',   cls: 'text-yellow-400 bg-yellow-500/10' },
    cair:    { label: 'Sudah Cair',     cls: 'text-emerald-400 bg-emerald-500/10' },
    ditolak: { label: 'Ditolak',        cls: 'text-rose-400 bg-rose-500/10' },
};

const caraBayarLabel = {
    cash:          'Cash',
    cash_bertahap: 'Cash Bertahap',
    kpr_subsidi:   'KPR Subsidi',
    kpr_komersil:  'KPR Komersil',
};
</script>

<template>
    <Head title="Keuangan" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <span class="text-slate-200 font-medium">Keuangan</span>
            </div>
        </template>

        <div class="p-6 space-y-5">
            <!-- Header + Filter -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-white font-bold text-xl">Monitoring Keuangan</h1>
                    <p class="text-slate-400 text-sm mt-0.5">Pembayaran, KPR, dan klaim SBUM</p>
                </div>
                <div class="flex items-center gap-2">
                    <select v-model="filterProject" @change="applyFilter"
                        class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Semua Proyek</option>
                        <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.nama }}</option>
                    </select>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 bg-slate-900 border border-slate-800 rounded-xl p-1 w-fit">
                <button v-for="tab in tabs" :key="tab.key"
                    @click="activeTab = tab.key"
                    :class="activeTab === tab.key ? 'bg-violet-600 text-white shadow-lg shadow-violet-500/20' : 'text-slate-400 hover:text-slate-200'"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap">
                    {{ tab.label }}
                </button>
            </div>

            <!-- ── Tab 1: Pembayaran ─────────────────────────────── -->
            <div v-if="activeTab === 'pembayaran'">
                <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wide">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium">Konsumen</th>
                                    <th class="px-4 py-3 text-left font-medium">Unit</th>
                                    <th class="px-4 py-3 text-left font-medium">Cara Bayar</th>
                                    <th class="px-4 py-3 text-right font-medium">Harga Deal</th>
                                    <th class="px-4 py-3 text-right font-medium">Terbayar</th>
                                    <th class="px-4 py-3 text-right font-medium">Sisa</th>
                                    <th class="px-4 py-3 text-left font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in pembayaran" :key="row.id"
                                    class="border-b border-slate-800/50 hover:bg-slate-800/30 transition-colors">
                                    <td class="px-4 py-3 text-slate-200 font-medium">{{ row.konsumen_nama }}</td>
                                    <td class="px-4 py-3 text-slate-400">{{ row.kavling }}<br/><span class="text-xs text-slate-600">{{ row.project }}</span></td>
                                    <td class="px-4 py-3 text-slate-400">{{ caraBayarLabel[row.cara_bayar] ?? row.cara_bayar }}</td>
                                    <td class="px-4 py-3 text-slate-200 text-right font-medium">{{ formatRp(row.harga_deal) }}</td>
                                    <td class="px-4 py-3 text-emerald-400 text-right font-medium">{{ formatRp(row.total_terbayar) }}</td>
                                    <td class="px-4 py-3 text-right font-medium" :class="(row.harga_deal - row.total_terbayar) > 0 ? 'text-rose-400' : 'text-emerald-400'">
                                        {{ formatRp(Math.max(0, row.harga_deal - row.total_terbayar)) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 text-xs rounded-full font-medium"
                                            :class="row.status_penjualan === 'akad' ? 'bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/30' : 'bg-blue-500/15 text-blue-400 ring-1 ring-blue-500/30'">
                                            {{ row.status_penjualan_label }}
                                        </span>
                                    </td>
                                </tr>
                                <tr v-if="!pembayaran.length">
                                    <td colspan="7" class="px-4 py-8 text-center text-slate-600">Belum ada data pembayaran</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ── Tab 2: KPR & Dajam ───────────────────────────── -->
            <div v-if="activeTab === 'kpr'" class="space-y-4">
                <!-- Summary Cards -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-center">
                        <div class="text-violet-400 font-bold text-lg">{{ formatRp(kprSummary.total_plafon) }}</div>
                        <div class="text-slate-500 text-xs mt-0.5">Total Plafon</div>
                    </div>
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-center">
                        <div class="text-emerald-400 font-bold text-lg">{{ formatRp(kprSummary.total_realisasi) }}</div>
                        <div class="text-slate-500 text-xs mt-0.5">Total Cair</div>
                    </div>
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-center">
                        <div class="text-amber-400 font-bold text-lg">{{ formatRp(kprSummary.total_dajam) }}</div>
                        <div class="text-slate-500 text-xs mt-0.5">Dajam Ditahan</div>
                    </div>
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-center">
                        <div class="text-slate-200 font-bold text-lg">{{ kprSummary.jumlah_unit }}</div>
                        <div class="text-slate-500 text-xs mt-0.5">Unit KPR</div>
                    </div>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wide">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium">Konsumen</th>
                                    <th class="px-4 py-3 text-left font-medium">Unit</th>
                                    <th class="px-4 py-3 text-left font-medium">Jenis</th>
                                    <th class="px-4 py-3 text-right font-medium">Plafon</th>
                                    <th class="px-4 py-3 text-right font-medium">Realisasi Cair</th>
                                    <th class="px-4 py-3 text-right font-medium">Dajam Ditahan</th>
                                    <th class="px-4 py-3 text-left font-medium">Status</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in kprMonitoring" :key="row.id"
                                    class="border-b border-slate-800/50 hover:bg-slate-800/30 transition-colors">
                                    <td class="px-4 py-3 text-slate-200 font-medium">{{ row.konsumen_nama }}</td>
                                    <td class="px-4 py-3 text-slate-400">{{ row.kavling }}<br/><span class="text-xs text-slate-600">{{ row.project }}</span></td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium"
                                            :class="row.cara_bayar === 'kpr_subsidi' ? 'bg-green-500/15 text-green-400 ring-1 ring-green-500/30' : 'bg-purple-500/15 text-purple-400 ring-1 ring-purple-500/30'">
                                            {{ row.cara_bayar === 'kpr_subsidi' ? 'KPR Subsidi' : 'KPR Komersil' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-slate-200 text-right">{{ formatRp(row.plafon_kpr) }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <span v-if="editingKpr === row.id">
                                            <input v-model="kprForm.realisasi_cair" type="number" class="w-28 px-2 py-1 bg-slate-800 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500 text-right" />
                                        </span>
                                        <span v-else class="text-emerald-400">{{ formatRp(row.realisasi_cair) }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <span v-if="editingKpr === row.id">
                                            <input v-model="kprForm.dajam_ditahan" type="number" class="w-28 px-2 py-1 bg-slate-800 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500 text-right" />
                                        </span>
                                        <span v-else class="text-amber-400">{{ formatRp(row.dajam_ditahan) }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-slate-400 text-xs">{{ row.status_penjualan }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <div v-if="editingKpr === row.id" class="flex gap-1 justify-end">
                                            <button @click="saveKpr(row.id)" class="px-2 py-1 bg-emerald-600 hover:bg-emerald-500 text-white text-xs rounded transition-colors">Simpan</button>
                                            <button @click="editingKpr = null" class="px-2 py-1 bg-slate-700 hover:bg-slate-600 text-slate-300 text-xs rounded transition-colors">Batal</button>
                                        </div>
                                        <button v-else @click="openKprEdit(row)" class="text-xs text-violet-400 hover:text-violet-300 transition-colors">Edit</button>
                                    </td>
                                </tr>
                                <tr v-if="!kprMonitoring.length">
                                    <td colspan="8" class="px-4 py-8 text-center text-slate-600">Belum ada data KPR</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ── Tab 3: SBUM ──────────────────────────────────── -->
            <div v-if="activeTab === 'sbum'" class="space-y-4">
                <!-- Summary Cards -->
                <div class="grid grid-cols-3 gap-4">
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-center">
                        <div class="text-slate-200 font-bold text-xl">{{ sbumSummary.total_diajukan }}</div>
                        <div class="text-slate-500 text-xs mt-0.5">Total Diajukan</div>
                    </div>
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-center">
                        <div class="text-emerald-400 font-bold text-xl">{{ sbumSummary.total_cair }}</div>
                        <div class="text-slate-500 text-xs mt-0.5">Sudah Cair</div>
                    </div>
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-center">
                        <div class="text-violet-400 font-bold text-lg">{{ formatRp(sbumSummary.total_nilai) }}</div>
                        <div class="text-slate-500 text-xs mt-0.5">Total Nilai SBUM</div>
                    </div>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wide">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium">Konsumen</th>
                                    <th class="px-4 py-3 text-left font-medium">Unit</th>
                                    <th class="px-4 py-3 text-right font-medium">Jumlah SBUM</th>
                                    <th class="px-4 py-3 text-left font-medium">Status</th>
                                    <th class="px-4 py-3 text-left font-medium">Tgl Pengajuan</th>
                                    <th class="px-4 py-3 text-left font-medium">Tgl Cair</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in sbumData" :key="row.id"
                                    class="border-b border-slate-800/50 hover:bg-slate-800/30 transition-colors">
                                    <td class="px-4 py-3 text-slate-200 font-medium">{{ row.konsumen_nama }}</td>
                                    <td class="px-4 py-3 text-slate-400">{{ row.kavling }}<br/><span class="text-xs text-slate-600">{{ row.project }}</span></td>
                                    <td class="px-4 py-3 text-slate-200 text-right font-medium">{{ formatRp(row.jumlah_sbum) }}</td>
                                    <td class="px-4 py-3">
                                        <span :class="['px-2 py-0.5 text-xs rounded-full font-medium', sbumStatusConfig[row.status]?.cls]">
                                            {{ sbumStatusConfig[row.status]?.label }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-slate-400 text-xs">{{ row.tanggal_pengajuan ?? '-' }}</td>
                                    <td class="px-4 py-3 text-slate-400 text-xs">{{ row.tanggal_cair ?? '-' }}</td>
                                </tr>
                                <tr v-if="!sbumData.length">
                                    <td colspan="6" class="px-4 py-8 text-center text-slate-600">Belum ada data SBUM</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

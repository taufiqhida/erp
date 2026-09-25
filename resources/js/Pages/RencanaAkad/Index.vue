<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SortTh from '@/Components/SortTh.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Daftar kerja penjadwalan akad — semua transaksi di tahap Rencana Akad dimuat sekaligus,
// jadi cari/filter/sort dilakukan di browser. Mengubah tanggal/notaris tetap di Kelola Dokumen.
const props = defineProps({
    rows:           Array,
    notarisOptions: Array,
});

const search  = ref('');
const notaris = ref(''); // '' = semua, 'none' = tanpa notaris, selain itu id notaris
const sortKey = ref('tanggal');
const sortDir = ref('asc');
const setSort = (key, dir) => { sortKey.value = key; sortDir.value = dir; };

const CARA_BAYAR = { cash: 'Cash', cash_bertahap: 'Cash Bertahap', kpr_subsidi: 'KPR Subsidi', kpr_komersil: 'KPR Komersil' };

const filtered = computed(() => {
    const kw = search.value.trim().toLowerCase();
    return props.rows.filter(r => {
        if (notaris.value === 'none' && r.notaris_id) return false;
        if (notaris.value && notaris.value !== 'none' && String(r.notaris_id) !== String(notaris.value)) return false;
        if (!kw) return true;
        return [r.konsumen_nama, r.konsumen_no_hp, r.kavling_nomor, r.bank, r.notaris_nama]
            .some(v => (v ?? '').toString().toLowerCase().includes(kw));
    });
});

const naturalCompare = (a, b) => a.localeCompare(b, 'id', { numeric: true, sensitivity: 'base' });

const sorted = computed(() => {
    const dir = sortDir.value === 'asc' ? 1 : -1;
    const list = [...filtered.value];
    if (sortKey.value === 'tanggal') {
        // Yang belum punya tanggal selalu paling atas (perlu tindakan), lalu urut tanggal.
        return list.sort((a, b) => {
            if (!a.tanggal_akad && !b.tanggal_akad) return naturalCompare(a.kavling_nomor, b.kavling_nomor);
            if (!a.tanggal_akad) return -1;
            if (!b.tanggal_akad) return 1;
            return dir * a.tanggal_akad.localeCompare(b.tanggal_akad);
        });
    }
    if (sortKey.value === 'unit') return list.sort((a, b) => dir * naturalCompare(a.kavling_nomor, b.kavling_nomor));
    if (sortKey.value === 'nama') return list.sort((a, b) => dir * naturalCompare(a.konsumen_nama, b.konsumen_nama));
    if (sortKey.value === 'notaris') return list.sort((a, b) => dir * naturalCompare(a.notaris_nama ?? '￿', b.notaris_nama ?? '￿'));
    return list;
});

const stats = computed(() => ({
    total: props.rows.length,
    tanpaTanggal: props.rows.filter(r => !r.tanggal_akad).length,
    minggu: props.rows.filter(r => r.sisa_hari !== null && r.sisa_hari >= 0 && r.sisa_hari <= 7).length,
    lewat: props.rows.filter(r => r.sisa_hari !== null && r.sisa_hari < 0).length,
    sp3k: props.rows.filter(r => r.sp3k_warning).length,
}));

const hariLabel = (r) => {
    if (r.sisa_hari === null) return '';
    if (r.sisa_hari === 0) return 'Hari ini';
    return r.sisa_hari > 0 ? `${r.sisa_hari} hari lagi` : `Lewat ${Math.abs(r.sisa_hari)} hari`;
};
const hariCls = (r) => r.sisa_hari < 0 ? 'text-rose-400' : r.sisa_hari <= 7 ? 'text-amber-400' : 'text-slate-500';
const formatRp = (v) => 'Rp ' + Number(v ?? 0).toLocaleString('id-ID');
</script>

<template>
    <Head title="Rencana Akad" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <span>Pemasaran &amp; Penjualan</span><span>/</span>
                <span class="text-slate-200 font-medium">Rencana Akad</span>
            </div>
        </template>

        <div class="p-6 space-y-5">
            <div>
                <h1 class="text-white font-bold text-xl">Rencana Akad</h1>
                <p class="text-slate-400 text-sm mt-0.5">Konsumen yang sedang di tahap Rencana Akad. Untuk mengubah tanggal atau notaris, buka Kelola Dokumen konsumen yang bersangkutan.</p>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
                <div class="bg-slate-900 border border-slate-800 rounded-xl px-4 py-3">
                    <div class="text-slate-500 text-[11px]">Total di tahap ini</div>
                    <div class="text-white text-lg font-bold mt-0.5">{{ stats.total }}</div>
                </div>
                <div class="bg-slate-900 border rounded-xl px-4 py-3" :class="stats.tanpaTanggal ? 'border-amber-500/40' : 'border-slate-800'">
                    <div class="text-slate-500 text-[11px]">Tanggal belum diisi</div>
                    <div class="text-lg font-bold mt-0.5" :class="stats.tanpaTanggal ? 'text-amber-400' : 'text-slate-300'">{{ stats.tanpaTanggal }}</div>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-xl px-4 py-3">
                    <div class="text-slate-500 text-[11px]">Akad ≤ 7 hari ke depan</div>
                    <div class="text-lg font-bold mt-0.5 text-slate-200">{{ stats.minggu }}</div>
                </div>
                <div class="bg-slate-900 border rounded-xl px-4 py-3" :class="stats.lewat ? 'border-rose-500/40' : 'border-slate-800'">
                    <div class="text-slate-500 text-[11px]">Lewat tanggal, belum akad</div>
                    <div class="text-lg font-bold mt-0.5" :class="stats.lewat ? 'text-rose-400' : 'text-slate-300'">{{ stats.lewat }}</div>
                </div>
                <div class="bg-slate-900 border rounded-xl px-4 py-3" :class="stats.sp3k ? 'border-rose-500/40' : 'border-slate-800'">
                    <div class="text-slate-500 text-[11px]">SP3K bermasalah</div>
                    <div class="text-lg font-bold mt-0.5" :class="stats.sp3k ? 'text-rose-400' : 'text-slate-300'">{{ stats.sp3k }}</div>
                </div>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-wrap items-center gap-3">
                <input v-model="search" type="text" placeholder="Cari nama, No. HP, unit, bank, notaris..."
                    class="flex-1 min-w-[220px] px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500" />
                <select v-model="notaris" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                    <option value="">Semua Notaris</option>
                    <option value="none">Belum ada notaris</option>
                    <option v-for="n in notarisOptions" :key="n.id" :value="n.id">{{ n.nama }}</option>
                </select>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wide">
                            <tr>
                                <SortTh label="Konsumen" sort-key="nama" :current="sortKey" :dir="sortDir" first-dir="asc" @sort="setSort" />
                                <SortTh label="Unit" sort-key="unit" :current="sortKey" :dir="sortDir" first-dir="asc" @sort="setSort" />
                                <th class="px-4 py-3 text-left font-medium">Cara Bayar</th>
                                <SortTh label="Notaris" sort-key="notaris" :current="sortKey" :dir="sortDir" first-dir="asc" @sort="setSort" />
                                <SortTh label="Tgl Rencana Akad" sort-key="tanggal" :current="sortKey" :dir="sortDir" first-dir="asc" @sort="setSort" />
                                <th class="px-4 py-3 text-left font-medium">SP3K</th>
                                <th class="px-4 py-3 text-right font-medium">Sisa Piutang</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in sorted" :key="r.id" class="border-b border-slate-800/50 hover:bg-slate-800/30 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="text-slate-200 font-medium">{{ r.konsumen_nama }}</div>
                                    <div class="text-slate-600 text-xs">{{ r.konsumen_no_hp ?? '-' }}</div>
                                </td>
                                <td class="px-4 py-3 text-slate-400">
                                    {{ r.kavling_nomor }}
                                    <div class="text-slate-600 text-xs">{{ r.project_nama }}</div>
                                </td>
                                <td class="px-4 py-3 text-slate-400">
                                    {{ CARA_BAYAR[r.cara_bayar] ?? r.cara_bayar }}
                                    <div v-if="r.bank" class="text-slate-600 text-xs">{{ r.bank }}</div>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <span v-if="r.notaris_nama" class="text-slate-300">{{ r.notaris_nama }}</span>
                                    <span v-else class="text-slate-600">Belum dipilih</span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <template v-if="r.tanggal_akad">
                                        <div class="text-slate-200 text-sm">{{ r.tanggal_akad_label }}</div>
                                        <div class="text-[11px]" :class="hariCls(r)">{{ hariLabel(r) }}</div>
                                    </template>
                                    <span v-else class="px-2 py-0.5 text-xs rounded-full font-medium bg-amber-500/15 text-amber-400">Belum diisi</span>
                                </td>
                                <td class="px-4 py-3 text-xs whitespace-nowrap">
                                    <template v-if="r.sp3k_expired">
                                        <div class="text-slate-400">s.d. {{ r.sp3k_expired }}</div>
                                        <div v-if="r.sp3k_warning === 'sudah_expired'" class="text-rose-400 text-[11px]">Sudah kedaluwarsa</div>
                                        <div v-else-if="r.sp3k_warning === 'sebelum_akad'" class="text-rose-400 text-[11px]">Kedaluwarsa sebelum tgl akad</div>
                                    </template>
                                    <span v-else class="text-slate-600">-</span>
                                </td>
                                <td class="px-4 py-3 text-right text-xs whitespace-nowrap">
                                    <span v-if="r.sisa_piutang > 0.009" class="text-amber-400">{{ formatRp(r.sisa_piutang) }}</span>
                                    <span v-else class="text-emerald-500/80">Lunas</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Link :href="route('dokumen.index', r.id)" title="Buka Kelola Dokumen"
                                        class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs text-violet-400 hover:bg-violet-500/10 transition-colors whitespace-nowrap">Buka →</Link>
                                </td>
                            </tr>
                            <tr v-if="!sorted.length">
                                <td colspan="8" class="px-4 py-12 text-center text-slate-600">Tidak ada konsumen di tahap Rencana Akad.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

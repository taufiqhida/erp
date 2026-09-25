<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CatatPembayaran from '@/Components/CatatPembayaran.vue';
import StatusPembayaranBadge from '@/Components/StatusPembayaranBadge.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, reactive, computed } from 'vue';

const props = defineProps({
    transaksi: Object,
});

const canManagePembayaran = computed(() => usePage().props.auth.user?.permissions?.includes('manage pembayaran'));
const canManageKpr = computed(() => usePage().props.auth.user?.permissions?.includes('manage kpr'));
const isManajerOrAdmin = computed(() => {
    const roles = usePage().props.auth.user?.roles ?? [];
    return roles.includes('manajer') || roles.includes('superadmin');
});
const canPayItem = computed(() => canManagePembayaran.value && (!props.transaksi.is_locked || isManajerOrAdmin.value));
const canPayDajamSbum = computed(() => canManageKpr.value && (!props.transaksi.is_locked || isManajerOrAdmin.value));

// Tandai Selesai — pemicu lock (bukan status Akad), cuma aktif kalau semua
// piutang konsumen & piutang bank sudah lunas (dicek ulang di server).
const markingComplete = ref(false);
const markComplete = () => {
    if (!confirm('Tandai transaksi ini Selesai? Setelah ini data pemberkasan & pembayaran akan terkunci.')) return;
    markingComplete.value = true;
    router.post(route('keuangan.mark-complete', props.transaksi.id), {}, {
        preserveScroll: true,
        onFinish: () => { markingComplete.value = false; },
    });
};

const formatRp = (v) => v ? 'Rp ' + Number(v).toLocaleString('id-ID') : '-';
// Kalau baru bayar sebagian, tampilkan "sudah dibayar / total" biar kelihatan
// progressnya, bukan cuma nominal yang harus dibayar.
const nominalLabel = (nominal, dibayar, status) => status === 'sebagian' && dibayar != null
    ? `${formatRp(dibayar)} / ${formatRp(nominal)}`
    : formatRp(nominal);

const sbumItems = computed(() => (props.transaksi.rincian_biaya_akad || []).filter(i => i.kategori === 'sbum'));
const dajamItems = computed(() => (props.transaksi.rincian_biaya_akad || []).filter(i => i.kategori === 'dajam'));

const totalKartuPiutang = computed(() => (props.transaksi.kartu_piutang_static || []).reduce((sum, i) => sum + Number(i.nominal), 0));

// ── Edit tanggal jatuh tempo cicilan ──────────────────────────────────
const editingTanggal = ref(null);
const tanggalForm = useForm({ tanggal_jatuh_tempo: '' });
const openEditTanggal = (j) => {
    editingTanggal.value = j.id;
    tanggalForm.tanggal_jatuh_tempo = j.tanggal_jatuh_tempo_raw;
};
const submitEditTanggal = (j) => {
    tanggalForm.patch(route('jadwal-tagihan.update-tanggal', j.id), {
        preserveScroll: true,
        onSuccess: () => { editingTanggal.value = null; },
    });
};

// Rencana nominal per cicilan (hasil bagi rata otomatis saat booking) bisa
// dikoreksi manual selama belum ada pembayaran tercatat sama sekali —
// begitu ada pembayaran (sebagian/lunas) rencananya dikunci.
const updateCicilanJumlah = (j, jumlah) => {
    if (Number(jumlah) === Number(j.jumlah)) return;
    router.patch(route('jadwal-tagihan.update-tanggal', j.id), { jumlah }, { preserveScroll: true });
};

// Pelunasan (cash/cash bertahap) boleh ditambah cicilan custom di luar
// tenor otomatis saat booking — mengakomodasi pelunasan dipercepat/
// restrukturisasi. Booking Fee/DP tetap terkunci ke skema, tidak ada
// tombol tambah utk grup itu (lihat kondisi row.type di template).
const showTambahCicilan = ref(false);
const tambahCicilanForm = useForm({ jumlah: '', tanggal_jatuh_tempo: '' });
const submitTambahCicilan = () => {
    tambahCicilanForm.post(route('pelunasan.cicilan.store', props.transaksi.id), {
        preserveScroll: true,
        onSuccess: () => { tambahCicilanForm.reset(); showTambahCicilan.value = false; },
    });
};
const deleteCicilanRow = (j) => {
    if (!confirm(`Hapus baris ${j.jenis_label} #${j.nomor_cicilan}?`)) return;
    router.delete(route('jadwal-tagihan.destroy', j.id), { preserveScroll: true });
};

// Tambahan Uang Muka sekarang baris Kartu Piutang sungguhan (subjek
// Konsumen) — ambil dari kartu_piutang_static biar status/jumlah_dibayar
// konsisten sama baris lain, bukan angka statis di section Pencairan KPR.
const tambahanUmRow = computed(() => (props.transaksi.kartu_piutang_static || []).find(r => r.type === 'tambahan_um_group'));

// Pencairan KPR (uang cair dari bank ke developer) dicatat manual per
// tahap — bank tidak ikut skema/tenor apa pun, beda dari Booking Fee/DP.
const showPencairanTahap = ref(false);
const editingTahap = ref(null);
const tahapForm = useForm({ nominal: '', tanggal_cair: '', keterangan: '' });
const openEditTahap = (t) => {
    editingTahap.value = t.id;
    tahapForm.nominal = t.nominal;
    tahapForm.tanggal_cair = t.tanggal_cair_raw;
    tahapForm.keterangan = t.keterangan;
};
const submitEditTahap = (t) => {
    tahapForm.patch(route('pencairan-kpr.tahap.update', t.id), {
        preserveScroll: true,
        onSuccess: () => { editingTahap.value = null; },
    });
};
const deleteTahap = (t) => {
    if (!confirm('Hapus tahap pencairan ini?')) return;
    router.delete(route('pencairan-kpr.tahap.destroy', t.id), { preserveScroll: true });
};
const showTambahTahap = ref(false);
const tambahTahapForm = useForm({ nominal: '', tanggal_cair: '', keterangan: '' });
const submitTambahTahap = () => {
    tambahTahapForm.post(route('pencairan-kpr.tahap.store', props.transaksi.id), {
        preserveScroll: true,
        onSuccess: () => { tambahTahapForm.reset(); showTambahTahap.value = false; },
    });
};

// Baris Kartu Piutang yang punya cicilan (Booking Fee/DP/Pelunasan bertahap)
// dirender sebagai grup — klik row utk expand/collapse lihat detail per
// cicilan inline, sama seperti di Konsumens/Show.vue.
const rowJenisMap = { booking_fee_group: 'booking_fee', dp_group: 'dp', pelunasan_group: 'pelunasan' };
// Biaya Tanah/Tambahan UM/Titipan Biaya Akad/Biaya Tambahan — cicilan bebas
// (bukan jadwal tagihan), datanya sudah dikirim langsung di row.cicilan dari
// kartuPiutangBreakdown(), bukan difilter dari trx.jadwal_tagihan. Biaya
// Tambahan beda dari 3 lainnya: satu transaksi bisa punya banyak baris
// bertipe sama (multi-preset), jadi expand-state & route-nya harus discope
// per row.id (lihat rowKey), bukan cuma row.type.
const cicilanLainGroupTypes = ['biaya_tanah_group', 'tambahan_um_group', 'titipan_biaya_akad_group', 'biaya_tambahan_group'];
const isGroupRow = (row) => !!rowJenisMap[row.type] || cicilanLainGroupTypes.includes(row.type);
const cicilanForRow = (row) => (props.transaksi.jadwal_tagihan || []).filter(j => j.jenis === rowJenisMap[row.type]);
const rowKey = (row) => `${row.type}:${row.id ?? ''}`;
const expandedRows = reactive({});
const isRowExpanded = (row) => !!expandedRows[rowKey(row)];
const toggleRowExpand = (row) => { const k = rowKey(row); expandedRows[k] = !expandedRows[k]; };

// Cicilan Biaya Tanah/Tambahan UM/Titipan Biaya Akad/Biaya Tambahan — pola
// sama persis Pencairan KPR Tahap: nambah baris kapan saja, tidak
// digenerate otomatis. Biaya Tambahan routing-nya ke item spesifik (row.id
// = id KavlingKonsumenBiayaTambahan), 3 lainnya ke transaksi (row.id null).
const cicilanRouteMap = {
    biaya_tanah_group: 'biaya-tanah.cicilan.store',
    tambahan_um_group: 'tambahan-um.cicilan.store',
    titipan_biaya_akad_group: 'titipan-biaya-akad.cicilan.store',
    biaya_tambahan_group: 'biaya-tambahan.cicilan.store',
};
const cicilanRouteParam = (row) => row.type === 'biaya_tambahan_group' ? row.id : props.transaksi.id;
const showTambahCicilanLain = reactive({});
const tambahCicilanLainForm = useForm({ jumlah: '', tanggal_bayar: '', keterangan: '' });
const submitTambahCicilanLain = (row) => {
    const key = rowKey(row);
    tambahCicilanLainForm.post(route(cicilanRouteMap[row.type], cicilanRouteParam(row)), {
        preserveScroll: true,
        onSuccess: () => { tambahCicilanLainForm.reset(); showTambahCicilanLain[key] = false; },
    });
};

const editingCicilanLain = ref(null);
const cicilanLainForm = useForm({ jumlah: '', tanggal_bayar: '', keterangan: '' });
const openEditCicilanLain = (c) => {
    editingCicilanLain.value = c.id;
    cicilanLainForm.jumlah = c.jumlah;
    cicilanLainForm.tanggal_bayar = c.tanggal_bayar_raw;
    cicilanLainForm.keterangan = c.keterangan;
};
const submitEditCicilanLain = (c) => {
    cicilanLainForm.patch(route('cicilan-pembayaran.update', c.id), {
        preserveScroll: true,
        onSuccess: () => { editingCicilanLain.value = null; },
    });
};
const deleteCicilanLain = (c) => {
    if (!confirm('Hapus cicilan ini?')) return;
    router.delete(route('cicilan-pembayaran.destroy', c.id), { preserveScroll: true });
};
</script>

<template>
    <Head :title="`Keuangan – ${transaksi.konsumen_nama}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <Link :href="route('keuangan.index')" class="hover:text-slate-200 transition-colors">Keuangan</Link>
                <span>/</span>
                <span class="text-slate-200 font-medium">{{ transaksi.konsumen_nama }}</span>
            </div>
        </template>

        <div class="p-6 space-y-6 max-w-4xl">
            <!-- Header info -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 class="text-white text-xl font-bold">{{ transaksi.konsumen_nama }}</h1>
                        <div class="text-slate-400 text-sm mt-1">{{ transaksi.konsumen_no_hp ?? '-' }}</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span v-if="transaksi.status === 'completed'" class="text-xs px-2 py-0.5 rounded-full font-medium bg-emerald-500/15 text-emerald-400" title="Terkunci sejak ditandai Selesai">
                            ✅ Selesai
                        </span>
                        <template v-else-if="canManagePembayaran">
                            <button v-if="transaksi.can_complete" @click="markComplete" :disabled="markingComplete"
                                class="text-xs px-3 py-1.5 rounded-lg font-medium bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white transition-colors">
                                {{ markingComplete ? 'Memproses...' : '✓ Tandai Selesai' }}
                            </button>
                            <span v-else class="text-xs px-2 py-0.5 rounded-full font-medium bg-slate-800 text-slate-500" title="Semua pembayaran konsumen & pencairan bank harus lunas dulu">
                                Belum bisa ditandai selesai
                            </span>
                        </template>
                    </div>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 mt-5 pt-5 border-t border-slate-800">
                    <div>
                        <div class="text-slate-500 text-xs">Unit</div>
                        <div class="text-slate-200 text-sm mt-0.5">{{ transaksi.kavling_nomor }}</div>
                        <div class="text-slate-600 text-xs">{{ transaksi.project_nama }}</div>
                    </div>
                    <div>
                        <div class="text-slate-500 text-xs">Harga Deal</div>
                        <div class="text-slate-200 text-sm mt-0.5">{{ formatRp(transaksi.harga_deal) }}</div>
                    </div>
                    <div>
                        <div class="text-slate-500 text-xs">Cara Bayar</div>
                        <div class="text-slate-200 text-sm mt-0.5">{{ transaksi.cara_bayar_label }}</div>
                    </div>
                    <div>
                        <div class="text-slate-500 text-xs">Bank</div>
                        <div class="text-slate-200 text-sm mt-0.5">{{ transaksi.bank_rekanan_kpr ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="text-slate-500 text-xs">Status</div>
                        <div class="text-slate-200 text-sm mt-0.5">{{ transaksi.status_penjualan_label }}</div>
                    </div>
                </div>
            </div>

            <!-- Rincian Pembayaran: breakdown kalkulasi harga, biar kelihatan
                 Pelunasan/Plafon KPR itu terdiri dari apa saja -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-2">
                <h2 class="text-slate-300 font-medium text-sm">Rincian Pembayaran</h2>
                <div class="bg-slate-800/40 rounded-lg p-3 space-y-1.5 text-sm">
                    <div class="flex justify-between text-slate-400">
                        <span>Harga Dasar</span>
                        <span class="text-slate-300">{{ formatRp(transaksi.harga_dasar) }}</span>
                    </div>
                    <div v-if="transaksi.biaya_kelebihan_tanah_aktif" class="flex justify-between text-slate-400">
                        <span>+ Biaya Kelebihan Tanah</span>
                        <span class="text-slate-300">{{ formatRp(transaksi.biaya_kelebihan_tanah_nominal) }}</span>
                    </div>
                    <div v-for="(bt, i) in transaksi.biaya_tambahan" :key="`bt-${i}`" class="flex justify-between text-slate-400">
                        <span>+ {{ bt.nama }}</span>
                        <span class="text-slate-300">{{ formatRp(bt.nominal) }}</span>
                    </div>
                    <div v-if="transaksi.diskon_nominal > 0" class="flex justify-between text-slate-400">
                        <span>− Diskon{{ transaksi.promo_nama ? ` (${transaksi.promo_nama})` : '' }}{{ transaksi.diskon_mode === 'persen' ? ` ${transaksi.diskon_nilai}%` : '' }}</span>
                        <span class="text-rose-400">{{ formatRp(transaksi.diskon_nominal) }}</span>
                    </div>
                    <div class="flex justify-between pt-1.5 border-t border-slate-700 font-semibold">
                        <span class="text-violet-300">Total Harga</span>
                        <span class="text-violet-300">{{ formatRp(transaksi.harga_deal) }}</span>
                    </div>

                    <div class="pt-2 mt-1 border-t border-slate-700/60">
                        <div class="text-slate-500 text-[11px] uppercase tracking-wide mb-1">
                            Cara Pembayaran: {{ transaksi.cara_bayar_label }}
                            <span v-if="transaksi.skema_dp_preset" class="text-slate-600 normal-case">· Skema: {{ transaksi.skema_dp_preset.nama }}</span>
                            <span v-if="transaksi.program_all_in_nama" class="text-slate-600 normal-case">· Program All In: {{ transaksi.program_all_in_nama }}</span>
                        </div>
                        <div v-if="transaksi.booking_fee > 0" class="flex justify-between text-slate-400">
                            <span>
                                Booking Fee
                                <span v-if="transaksi.skema_dp_preset?.booking_fee_aktif" class="text-slate-600 text-xs ml-1">
                                    ({{ transaksi.skema_dp_preset.booking_fee_tipe === 'persen' ? transaksi.skema_dp_preset.booking_fee_nilai + '%' : 'nominal tetap' }},
                                    {{ transaksi.skema_dp_preset.booking_fee_tenor }}x,
                                    {{ transaksi.skema_dp_preset.booking_fee_masuk_harga_jual ? 'masuk harga jual' : 'di luar harga jual' }})
                                </span>
                            </span>
                            <span class="text-slate-300">{{ formatRp(transaksi.booking_fee) }}</span>
                        </div>
                        <div v-if="transaksi.dp_nominal > 0" class="flex justify-between text-slate-400">
                            <span>
                                DP
                                <span v-if="transaksi.skema_dp_preset?.dp_aktif" class="text-slate-600 text-xs ml-1">
                                    ({{ transaksi.skema_dp_preset.dp_tipe === 'persen' ? transaksi.skema_dp_preset.dp_nilai + '%' : 'nominal tetap' }},
                                    {{ transaksi.skema_dp_preset.dp_tenor }}x,
                                    {{ transaksi.skema_dp_preset.dp_masuk_harga_jual ? 'masuk harga jual' : 'di luar harga jual' }})
                                </span>
                            </span>
                            <span class="text-slate-300">{{ formatRp(transaksi.dp_nominal) }}</span>
                        </div>
                        <div v-if="transaksi.titipan_biaya_akad_nominal > 0" class="flex justify-between text-slate-400">
                            <span>Titipan Biaya Akad<span class="text-slate-600 text-xs ml-1">(dari Program All In)</span></span>
                            <span class="text-slate-300">{{ formatRp(transaksi.titipan_biaya_akad_nominal) }}</span>
                        </div>
                        <div v-if="!(transaksi.booking_fee > 0) && !(transaksi.dp_nominal > 0) && !(transaksi.titipan_biaya_akad_nominal > 0)" class="text-slate-600 text-xs">Tidak ada booking fee maupun DP.</div>
                    </div>
                </div>
            </div>

            <!-- Kartu Piutang -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-2">
                <div class="flex justify-between items-center">
                    <h2 class="text-slate-300 font-medium text-sm">Kartu Piutang</h2>
                    <span class="text-slate-500 text-xs">Total: {{ formatRp(totalKartuPiutang) }}</span>
                </div>

                <div class="rounded-lg border border-slate-800 overflow-hidden">
                    <div class="px-3 py-1.5 bg-slate-800/80 text-slate-500 text-[11px] font-semibold uppercase tracking-wide">
                        Nama Biaya / Subjek / Nominal / Status
                    </div>
                    <template v-for="(row, i) in transaksi.kartu_piutang_static" :key="`static-${i}`">
                        <div
                            class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-t border-slate-800/60 text-sm"
                            :class="isGroupRow(row) ? 'cursor-pointer hover:bg-slate-800/40 transition-colors' : ''"
                            @click="isGroupRow(row) && toggleRowExpand(row)">
                            <div class="min-w-[160px] flex items-center gap-1.5 flex-1">
                                <svg v-if="isGroupRow(row)" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                                    :class="['w-3 h-3 text-slate-500 transition-transform flex-shrink-0', isRowExpanded(row) ? 'rotate-90' : '']">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                </svg>
                                <span class="text-slate-300">{{ row.nama }}</span>
                                <span class="text-slate-600 text-xs ml-2">{{ row.subjek }}</span>
                                <span v-if="row.progress" class="text-slate-600 text-xs ml-2">({{ row.progress }})</span>
                            </div>
                            <div class="flex items-center gap-3 flex-shrink-0">
                                <span class="text-slate-200 font-medium min-w-[110px] text-right">{{ nominalLabel(row.nominal, row.jumlah_dibayar, row.status) }}</span>
                                <span class="w-20 flex justify-center"><StatusPembayaranBadge :status="row.status" /></span>
                            </div>
                        </div>

                        <div v-if="isGroupRow(row) && isRowExpanded(row)" class="bg-slate-950/40 border-t border-slate-800/60">
                            <div v-if="row.legacy_pembayaran"
                                class="flex flex-wrap items-center justify-between gap-2 pl-8 pr-3 py-2 text-sm border-b border-slate-800/40">
                                <div class="flex-1">
                                    <span class="text-slate-400">Pelunasan (tercatat sebelum jadwal cicilan)</span>
                                </div>
                                <div class="flex items-center gap-3 flex-shrink-0">
                                    <span class="font-medium min-w-[110px] text-right text-slate-300">{{ formatRp(row.legacy_pembayaran.jumlah) }}</span>
                                    <span class="w-20 flex justify-center"><StatusPembayaranBadge status="lunas" /></span>
                                    <span class="w-36 flex justify-end">
                                        <CatatPembayaran
                                            :url="route('pembayaran.store', transaksi.id)"
                                            :delete-url="route('pembayaran.destroy', row.legacy_pembayaran.id)"
                                            status="lunas" :tanggal-bayar="row.legacy_pembayaran.tanggal_bayar"
                                            :extra-payload="{ jenis: 'pelunasan' }"
                                            :default-jumlah="row.legacy_pembayaran.jumlah" :paid-jumlah="row.legacy_pembayaran.jumlah" :can-manage="canPayItem" />
                                    </span>
                                </div>
                            </div>
                            <div v-for="j in cicilanForRow(row)" :key="j.id"
                                class="flex flex-wrap items-center justify-between gap-2 pl-8 pr-3 py-2 text-sm border-b border-slate-800/40 last:border-b-0">
                                <div class="flex-1">
                                    <span class="text-slate-400">{{ j.jenis_label }} #{{ j.nomor_cicilan }}</span>
                                    <template v-if="j.status === 'belum_bayar' && editingTanggal === j.id">
                                        <input v-model="tanggalForm.tanggal_jatuh_tempo" type="date"
                                            class="ml-2 px-1.5 py-0.5 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                        <button @click="submitEditTanggal(j)" class="ml-1 text-emerald-400 hover:text-emerald-300 text-xs">✓</button>
                                        <button @click="editingTanggal = null" class="ml-0.5 text-slate-500 hover:text-slate-300 text-xs">✕</button>
                                    </template>
                                    <template v-else>
                                        <span class="text-slate-600 text-xs ml-2">jatuh tempo {{ j.tanggal_jatuh_tempo }}</span>
                                        <button v-if="j.status === 'belum_bayar' && canPayItem" @click="openEditTanggal(j)"
                                            class="text-slate-600 hover:text-violet-400 text-xs ml-1" title="Ubah tanggal jatuh tempo">✎</button>
                                    </template>
                                    <span v-if="j.status !== 'lunas' && j.is_terlambat" class="ml-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-rose-500/15 text-rose-400">Terlambat</span>
                                </div>
                                <div class="flex items-center gap-3 flex-shrink-0">
                                    <MoneyInput v-if="j.jenis === 'pelunasan' && j.status === 'belum_bayar' && canPayItem" :model-value="j.jumlah"
                                        @commit="updateCicilanJumlah(j, $event)"
                                        class="w-28 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-right text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                    <span v-else class="font-medium min-w-[110px] text-right" :class="j.status === 'lunas' ? 'text-emerald-400' : 'text-slate-300'">{{ nominalLabel(j.jumlah, j.jumlah_dibayar, j.status) }}</span>
                                    <span class="w-20 flex justify-center"><StatusPembayaranBadge :status="j.status" /></span>
                                    <span class="w-36 flex justify-end items-center gap-1">
                                        <CatatPembayaran
                                            :url="route('jadwal-tagihan.bayar', j.id)"
                                            :delete-url="route('jadwal-tagihan.bayar.destroy', j.id)"
                                            :status="j.status" :tanggal-bayar="j.tanggal_bayar"
                                            :default-jumlah="j.jumlah" :paid-jumlah="j.jumlah_dibayar" :can-manage="canPayItem" />
                                        <button v-if="j.jenis === 'pelunasan' && j.status === 'belum_bayar' && canPayItem"
                                            @click="deleteCicilanRow(j)" title="Hapus baris cicilan ini"
                                            class="px-1 py-1 text-rose-400 hover:bg-rose-500/10 rounded transition-colors">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </span>
                                </div>
                            </div>

                            <!-- Cicilan Biaya Tanah/Tambahan UM/Titipan Biaya Akad — dicatat
                                 manual per baris (bukan jadwal_tagihan), persis pola Pencairan
                                 KPR Tahap. -->
                            <template v-if="cicilanLainGroupTypes.includes(row.type)">
                                <div v-for="c in row.cicilan" :key="c.id"
                                    class="flex flex-wrap items-center justify-between gap-2 pl-8 pr-3 py-2 text-sm border-b border-slate-800/40 last:border-b-0">
                                    <template v-if="editingCicilanLain === c.id">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <MoneyInput v-model="cicilanLainForm.jumlah" placeholder="Jumlah"
                                                class="w-28 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                            <input v-model="cicilanLainForm.tanggal_bayar" type="date"
                                                class="px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                            <input v-model="cicilanLainForm.keterangan" type="text" placeholder="Keterangan"
                                                class="w-32 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                            <button @click="submitEditCicilanLain(c)" class="text-emerald-400 hover:text-emerald-300 text-xs">✓</button>
                                            <button @click="editingCicilanLain = null" class="text-slate-500 hover:text-slate-300 text-xs">✕</button>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <div class="flex-1">
                                            <span class="text-slate-300 font-medium">{{ formatRp(c.jumlah) }}</span>
                                            <span class="text-slate-600 text-xs ml-2">dibayar {{ c.tanggal_bayar }}</span>
                                            <span v-if="c.keterangan" class="text-slate-600 text-xs ml-2">· {{ c.keterangan }}</span>
                                        </div>
                                        <div v-if="canPayItem" class="flex items-center gap-1 flex-shrink-0">
                                            <button @click="openEditCicilanLain(c)" title="Ubah" class="px-1 py-1 text-slate-500 hover:text-violet-400 rounded transition-colors">✎</button>
                                            <button @click="deleteCicilanLain(c)" title="Hapus" class="px-1 py-1 text-rose-400 hover:bg-rose-500/10 rounded transition-colors">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                    </template>
                                </div>
                                <div v-if="!row.cicilan?.length" class="pl-8 pr-3 py-2 text-slate-600 text-xs">Belum ada cicilan tercatat.</div>

                                <div v-if="canPayItem" class="pl-8 pr-3 py-2">
                                    <button v-if="!showTambahCicilanLain[rowKey(row)]" @click="showTambahCicilanLain[rowKey(row)] = true"
                                        class="text-xs text-violet-400 hover:text-violet-300 transition-colors">
                                        + Catat Cicilan
                                    </button>
                                    <div v-else class="flex flex-wrap items-center gap-1.5">
                                        <MoneyInput v-model="tambahCicilanLainForm.jumlah" placeholder="Jumlah"
                                            class="w-32 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                        <input v-model="tambahCicilanLainForm.tanggal_bayar" type="date"
                                            class="px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                        <input v-model="tambahCicilanLainForm.keterangan" type="text" placeholder="Keterangan (opsional)"
                                            class="w-36 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                        <button @click="submitTambahCicilanLain(row)"
                                            :disabled="!tambahCicilanLainForm.jumlah || !tambahCicilanLainForm.tanggal_bayar || tambahCicilanLainForm.processing"
                                            class="px-2.5 py-1 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-xs font-medium rounded-lg transition-colors">
                                            Simpan
                                        </button>
                                        <button @click="showTambahCicilanLain[rowKey(row)] = false; tambahCicilanLainForm.reset()" class="px-2 py-1 text-slate-400 hover:text-slate-200 text-xs">Batal</button>
                                    </div>
                                </div>
                            </template>

                            <div v-if="row.type === 'pelunasan_group' && canPayItem" class="pl-8 pr-3 py-2">
                                <button v-if="!showTambahCicilan" @click="showTambahCicilan = true"
                                    class="text-xs text-violet-400 hover:text-violet-300 transition-colors">
                                    + Tambah Cicilan Custom
                                </button>
                                <div v-else class="flex flex-wrap items-center gap-1.5">
                                    <MoneyInput v-model="tambahCicilanForm.jumlah" placeholder="Jumlah"
                                        class="w-32 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                    <input v-model="tambahCicilanForm.tanggal_jatuh_tempo" type="date"
                                        class="px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                    <button @click="submitTambahCicilan"
                                        :disabled="!tambahCicilanForm.jumlah || !tambahCicilanForm.tanggal_jatuh_tempo || tambahCicilanForm.processing"
                                        class="px-2.5 py-1 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-xs font-medium rounded-lg transition-colors">
                                        Simpan
                                    </button>
                                    <button @click="showTambahCicilan = false; tambahCicilanForm.reset()" class="px-2 py-1 text-slate-400 hover:text-slate-200 text-xs">Batal</button>
                                </div>
                            </div>
                        </div>
                    </template>
                    <div v-if="!transaksi.kartu_piutang_static?.length" class="px-3 py-4 text-center text-slate-600 text-xs">
                        Belum ada data piutang.
                    </div>
                </div>
            </div>

            <!-- Pencairan KPR -->
            <div v-if="transaksi.is_kpr" class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-2">
                <div class="flex justify-between items-center">
                    <h2 class="text-slate-300 font-medium text-sm">Pencairan KPR</h2>
                    <span class="text-slate-500 text-xs">Total: {{ formatRp(transaksi.total_piutang_bank) }}</span>
                </div>

                <div class="rounded-lg border border-slate-800 overflow-hidden">
                    <template v-if="transaksi.is_kpr_subsidi">
                        <div class="px-3 py-1.5 bg-slate-800/80 text-slate-500 text-[11px] font-semibold uppercase tracking-wide">SBUM</div>
                        <div v-for="item in sbumItems" :key="`sbum-${item.id}`"
                            class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-t border-slate-800/60 text-sm">
                            <div class="flex-1">
                                <span class="text-slate-300">{{ item.nama }}</span>
                                <span class="text-slate-600 text-xs ml-2">Pemerintah/Bank</span>
                            </div>
                            <div class="flex items-center gap-3 flex-shrink-0">
                                <span class="text-slate-200 font-medium min-w-[110px] text-right">{{ nominalLabel(item.nominal, item.jumlah_dibayar, item.status) }}</span>
                                <span class="w-20 flex justify-center"><StatusPembayaranBadge :status="item.status" /></span>
                                <span class="w-36 flex justify-end">
                                    <CatatPembayaran
                                        :url="route('rincian-biaya-akad.bayar', item.id)"
                                        :delete-url="route('rincian-biaya-akad.bayar.destroy', item.id)"
                                        :status="item.status" :tanggal-bayar="item.tanggal_bayar"
                                        :default-jumlah="item.nominal" :paid-jumlah="item.jumlah_dibayar" :can-manage="canPayDajamSbum" />
                                </span>
                            </div>
                        </div>
                        <div v-if="!sbumItems.length" class="px-3 py-3 text-center text-slate-600 text-xs">Belum ada item SBUM (dikelola di tab Konsumen).</div>
                    </template>

                    <div class="flex items-center justify-between px-3 py-2.5 border-t border-slate-800/60 bg-slate-800/30">
                        <span class="text-slate-300 font-medium">Plafon KPR (Pengajuan)</span>
                        <div class="text-right">
                            <span class="text-slate-500 text-xs mr-2">Bank</span>
                            <span class="text-violet-300 font-semibold">{{ formatRp(transaksi.pencairan_kpr?.plafon_hitung) }}</span>
                        </div>
                    </div>

                    <div class="px-3 py-1.5 bg-slate-800/80 text-slate-500 text-[11px] font-semibold uppercase tracking-wide">Dana Jaminan</div>
                    <div v-for="item in dajamItems" :key="`dajam-${item.id}`"
                        class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-t border-slate-800/60 text-sm">
                        <div class="flex-1">
                            <span class="text-slate-300">{{ item.nama }}</span>
                            <span class="text-slate-600 text-xs ml-2">Bank</span>
                        </div>
                        <div class="flex items-center gap-3 flex-shrink-0">
                            <span class="text-slate-200 font-medium min-w-[110px] text-right">{{ nominalLabel(item.nominal, item.jumlah_dibayar, item.status) }}</span>
                            <span class="w-20 flex justify-center"><StatusPembayaranBadge :status="item.status" /></span>
                            <span class="w-36 flex justify-end">
                                <CatatPembayaran
                                    :url="route('rincian-biaya-akad.bayar', item.id)"
                                    :delete-url="route('rincian-biaya-akad.bayar.destroy', item.id)"
                                    :status="item.status" :tanggal-bayar="item.tanggal_bayar"
                                    :default-jumlah="item.nominal" :paid-jumlah="item.jumlah_dibayar" :can-manage="canPayDajamSbum" />
                            </span>
                        </div>
                    </div>
                    <div v-if="!dajamItems.length" class="px-3 py-3 text-center text-slate-600 text-xs">Belum ada item Dana Jaminan (dikelola di tab Konsumen).</div>

                    <div v-if="tambahanUmRow" class="flex items-center justify-between px-3 py-2.5 border-t border-slate-800/60">
                        <span class="text-slate-400">(−) Turun Plafon <span class="text-slate-600 text-xs">— lihat baris Tambahan Uang Muka di Kartu Piutang</span></span>
                        <span class="text-amber-300 font-medium">{{ formatRp(tambahanUmRow.nominal) }}</span>
                    </div>

                    <div class="flex items-center justify-between px-3 py-2.5 border-t border-slate-800/60 bg-emerald-500/10 cursor-pointer hover:bg-emerald-500/15 transition-colors"
                        @click="showPencairanTahap = !showPencairanTahap">
                        <span class="text-emerald-300 font-semibold flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                                :class="['w-3 h-3 transition-transform flex-shrink-0', showPencairanTahap ? 'rotate-90' : '']">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                            </svg>
                            Pencairan KPR Netto
                        </span>
                        <div class="flex items-center gap-3">
                            <span class="text-slate-500 text-xs">Bank</span>
                            <span class="text-emerald-300 font-bold min-w-[110px] text-right">{{ nominalLabel(transaksi.pencairan_kpr?.pencairan_nominal, transaksi.pencairan_kpr?.pencairan_tercatat, transaksi.pencairan_kpr?.pencairan_status) }}</span>
                            <span class="w-20 flex justify-center"><StatusPembayaranBadge :status="transaksi.pencairan_kpr?.pencairan_status" /></span>
                        </div>
                    </div>

                    <div v-if="showPencairanTahap" class="bg-slate-950/40 border-t border-slate-800/60" @click.stop>
                        <div v-for="t in transaksi.pencairan_kpr_tahaps" :key="t.id"
                            class="flex flex-wrap items-center justify-between gap-2 pl-8 pr-3 py-2 text-sm border-b border-slate-800/40 last:border-b-0">
                            <template v-if="editingTahap === t.id">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <MoneyInput v-model="tahapForm.nominal" placeholder="Nominal"
                                        class="w-32 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                    <input v-model="tahapForm.tanggal_cair" type="date"
                                        class="px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                    <input v-model="tahapForm.keterangan" type="text" placeholder="Keterangan"
                                        class="w-32 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                    <button @click="submitEditTahap(t)" class="text-emerald-400 hover:text-emerald-300 text-xs">✓</button>
                                    <button @click="editingTahap = null" class="text-slate-500 hover:text-slate-300 text-xs">✕</button>
                                </div>
                            </template>
                            <template v-else>
                                <div>
                                    <span class="text-slate-300 font-medium">{{ formatRp(t.nominal) }}</span>
                                    <span class="text-slate-600 text-xs ml-2">cair {{ t.tanggal_cair }}</span>
                                    <span v-if="t.keterangan" class="text-slate-600 text-xs ml-2">· {{ t.keterangan }}</span>
                                </div>
                                <div v-if="canPayDajamSbum" class="flex items-center gap-1 flex-shrink-0">
                                    <button @click="openEditTahap(t)" title="Ubah" class="px-1 py-1 text-slate-500 hover:text-violet-400 rounded transition-colors">✎</button>
                                    <button @click="deleteTahap(t)" title="Hapus" class="px-1 py-1 text-rose-400 hover:bg-rose-500/10 rounded transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </template>
                        </div>
                        <div v-if="!transaksi.pencairan_kpr_tahaps?.length" class="pl-8 pr-3 py-2 text-slate-600 text-xs">Belum ada pencairan tercatat.</div>

                        <div v-if="canPayDajamSbum" class="pl-8 pr-3 py-2">
                            <button v-if="!showTambahTahap" @click="showTambahTahap = true"
                                class="text-xs text-violet-400 hover:text-violet-300 transition-colors">
                                + Catat Pencairan
                            </button>
                            <div v-else class="flex flex-wrap items-center gap-1.5">
                                <MoneyInput v-model="tambahTahapForm.nominal" placeholder="Nominal"
                                    class="w-32 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                <input v-model="tambahTahapForm.tanggal_cair" type="date"
                                    class="px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                <input v-model="tambahTahapForm.keterangan" type="text" placeholder="Keterangan (opsional)"
                                    class="w-36 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                <button @click="submitTambahTahap"
                                    :disabled="!tambahTahapForm.nominal || !tambahTahapForm.tanggal_cair || tambahTahapForm.processing"
                                    class="px-2.5 py-1 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-xs font-medium rounded-lg transition-colors">
                                    Simpan
                                </button>
                                <button @click="showTambahTahap = false; tambahTahapForm.reset()" class="px-2 py-1 text-slate-400 hover:text-slate-200 text-xs">Batal</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Riwayat Pembayaran -->
            <div v-if="transaksi.pembayarans?.length" class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-2">
                <h2 class="text-slate-300 font-medium text-sm">Riwayat Pembayaran</h2>
                <div class="space-y-1.5">
                    <div v-for="p in transaksi.pembayarans" :key="p.id"
                        class="flex items-center justify-between px-3 py-2 bg-slate-800/60 rounded-lg text-sm">
                        <div>
                            <span class="text-slate-300">{{ p.jenis_label }}</span>
                            <span v-if="p.keterangan" class="text-slate-500 text-xs ml-2">· {{ p.keterangan }}</span>
                        </div>
                        <div class="text-right">
                            <div class="text-emerald-400 font-medium">{{ formatRp(p.jumlah) }}</div>
                            <div class="text-slate-600 text-xs">{{ p.tanggal_bayar }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

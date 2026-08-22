<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CatatPembayaran from '@/Components/CatatPembayaran.vue';
import StatusPembayaranBadge from '@/Components/StatusPembayaranBadge.vue';
import PengajuanKonsumenModal from '@/Components/PengajuanKonsumenModal.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, reactive, computed, onMounted, nextTick } from 'vue';

const props = defineProps({
    konsumen: Object,
    transaksis: Array,
    openTransaksiId: Number,
    dajamSbumPresets: Array,
});

const canManageKpr = computed(() => usePage().props.auth.user?.permissions?.includes('manage kpr'));
const canManagePembayaran = computed(() => usePage().props.auth.user?.permissions?.includes('manage pembayaran'));
const canViewKeuangan = computed(() => usePage().props.auth.user?.permissions?.includes('view keuangan'));
const canRequestCancellation = computed(() => usePage().props.auth.user?.permissions?.includes('request cancellation'));
const canSwapKavling = computed(() => usePage().props.auth.user?.permissions?.includes('swap kavling'));

const pengajuanModal = reactive({ show: false, type: 'cancellation', trx: null });
const openPengajuan = (trx, type) => {
    pengajuanModal.trx = trx;
    pengajuanModal.type = type;
    pengajuanModal.show = true;
};
const closePengajuan = () => { pengajuanModal.show = false; };
const onPengajuanSuccess = () => { pengajuanModal.show = false; router.reload({ only: ['transaksis'] }); };
const isManajerOrAdmin = computed(() => {
    const roles = usePage().props.auth.user?.roles ?? [];
    return roles.includes('manajer') || roles.includes('superadmin');
});
const canEditBiayaAkad = (trx) => canManageKpr.value && (!trx.is_locked || isManajerOrAdmin.value);
// Pencatatan pembayaran sekarang murni domain Keuangan (lihat
// Keuangan/TransaksiDetail.vue) — di tab Konsumen cuma tampil status
// Lunas/Belum Bayar, tidak ada aksi. Sales/manajer tetap kelola item
// (SBUM/Dajam/Biaya Akad/Biaya Tambahan) di sini lewat canEditBiayaAkad.
const canPayItem = () => false;
const canPayDajamSbum = () => false;

// Rincian Biaya Akad (dajam/sbum/biaya_akad) sekarang tampil di 2 tempat
// berbeda: dajam & sbum jadi baris di Kartu Piutang, biaya_akad jadi baris
// di Kartu Piutang Titipan — masing-masing dengan form tambah sendiri
// (form di-key per section biar gak tabrakan).
const biayaAkadForms = reactive({});
const getBiayaAkadForm = (trxId, section) => {
    const key = `${trxId}:${section}`;
    if (!biayaAkadForms[key]) biayaAkadForms[key] = { dajam_sbum_preset_id: '', nominal: '' };
    return biayaAkadForms[key];
};

const sbumItems = (trx) => (trx.rincian_biaya_akad || []).filter(i => i.kategori === 'sbum');
const dajamItems = (trx) => (trx.rincian_biaya_akad || []).filter(i => i.kategori === 'dajam');
const biayaAkadTitipanItems = (trx) => (trx.rincian_biaya_akad || []).filter(i => i.kategori === 'biaya_akad');

// SBUM cuma relevan untuk KPR Subsidi — di luar itu opsi tambah SBUM
// disembunyikan (tapi item SBUM lama yang sudah ada tetap ditampilkan apa adanya).
const sbumPresetOptions = computed(() => props.dajamSbumPresets.filter(p => p.kategori === 'sbum'));
const dajamPresetOptions = computed(() => props.dajamSbumPresets.filter(p => p.kategori === 'dajam'));
const biayaAkadPresetOptions = computed(() => props.dajamSbumPresets.filter(p => p.kategori === 'biaya_akad'));

const totalKartuPiutang = (trx) => (trx.kartu_piutang_static || []).reduce((sum, i) => sum + Number(i.nominal), 0);
const totalTitipan = (trx) => biayaAkadTitipanItems(trx).reduce((sum, i) => sum + Number(i.nominal), 0);

const addBiayaAkad = (trx, section) => {
    const form = getBiayaAkadForm(trx.id, section);
    if (!form.dajam_sbum_preset_id || !form.nominal) return;
    router.post(route('konsumens.biaya-akad.store', trx.id), {
        dajam_sbum_preset_id: form.dajam_sbum_preset_id,
        nominal: form.nominal,
    }, {
        preserveScroll: true,
        onSuccess: () => { biayaAkadForms[`${trx.id}:${section}`] = { dajam_sbum_preset_id: '', nominal: '' }; },
    });
};

const updateBiayaAkadNominal = (item, nominal) => {
    if (Number(nominal) === Number(item.nominal)) return;
    router.patch(route('konsumens.biaya-akad.update', item.id), { nominal }, { preserveScroll: true });
};

const removeBiayaAkad = (item) => {
    if (confirm(`Hapus item "${item.nama}"?`)) {
        router.delete(route('konsumens.biaya-akad.destroy', item.id), { preserveScroll: true });
    }
};

// Baris Kartu Piutang yang punya cicilan (Booking Fee/DP/Pelunasan bertahap)
// dirender sebagai grup — klik row utk expand/collapse lihat detail per
// cicilan inline, tidak ada lagi section "lihat detail cicilan" terpisah.
const rowJenisMap = { booking_fee_group: 'booking_fee', dp_group: 'dp', pelunasan_group: 'pelunasan' };
const isGroupRow = (row) => !!rowJenisMap[row.type];
const cicilanForRow = (trx, row) => (trx.jadwal_tagihan || []).filter(j => j.jenis === rowJenisMap[row.type]);
const expandedRows = reactive({});
const rowKey = (trxId, type) => `${trxId}:${type}`;
const isRowExpanded = (trxId, type) => !!expandedRows[rowKey(trxId, type)];
const toggleRowExpand = (trxId, type) => { const k = rowKey(trxId, type); expandedRows[k] = !expandedRows[k]; };

// Tambahan Uang Muka sekarang baris Kartu Piutang sungguhan — ambil dari
// kartu_piutang_static biar status/jumlah_dibayar konsisten. Pencairan KPR
// (tahap-tahap dari bank) ditampilkan read-only di sini, aksinya di Keuangan.
const tambahanUmRow = (trx) => (trx.kartu_piutang_static || []).find(r => r.type === 'tambahan_um');
const showPencairanTahap = reactive({});
const togglePencairanTahap = (trxId) => { showPencairanTahap[trxId] = !showPencairanTahap[trxId]; };

const formatRp = (v) => v
    ? 'Rp ' + Number(v).toLocaleString('id-ID')
    : '-';
// Kalau baru bayar sebagian, tampilkan "sudah dibayar / total" biar kelihatan
// progressnya, bukan cuma nominal yang harus dibayar.
const nominalLabel = (nominal, dibayar, status) => status === 'sebagian' && dibayar != null
    ? `${formatRp(dibayar)} / ${formatRp(nominal)}`
    : formatRp(nominal);

// Pipeline (status_penjualan) — dipakai jadi badge di sini & di Konsumens/Index.vue,
// warnanya harus konsisten di kedua tempat.
const statusPenjualanConfig = {
    booking:      { label: 'Booking',            cls: 'bg-blue-500/15 text-blue-400 ring-1 ring-blue-500/30' },
    pemberkasan:  { label: 'Pemberkasan',         cls: 'bg-amber-500/15 text-amber-400 ring-1 ring-amber-500/30' },
    proses_bank:  { label: 'Proses Bank/SLIK',    cls: 'bg-orange-500/15 text-orange-400 ring-1 ring-orange-500/30' },
    sp3k:         { label: 'SP3K',                cls: 'bg-indigo-500/15 text-indigo-400 ring-1 ring-indigo-500/30' },
    rencana_akad: { label: 'Rencana Akad',        cls: 'bg-violet-500/15 text-violet-400 ring-1 ring-violet-500/30' },
    akad:         { label: 'Akad',                cls: 'bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/30' },
    bast:         { label: 'BAST / Selesai',      cls: 'bg-teal-500/15 text-teal-400 ring-1 ring-teal-500/30' },
    batal:        { label: 'Batal',               cls: 'bg-rose-500/15 text-rose-400 ring-1 ring-rose-500/30' },
};

// Status Bangun — warna konsisten dengan Penjualan/Project.vue (statusBangunColorHex)
const statusBangunConfig = {
    not_started:    { label: 'Belum Mulai',       cls: 'bg-slate-700/50 text-slate-400 ring-1 ring-slate-600' },
    foundation:     { label: 'Pondasi',           cls: 'bg-orange-500/15 text-orange-400 ring-1 ring-orange-500/30' },
    structure:      { label: 'Struktur',          cls: 'bg-blue-500/15 text-blue-400 ring-1 ring-blue-500/30' },
    roofing:        { label: 'Atap',              cls: 'bg-indigo-500/15 text-indigo-400 ring-1 ring-indigo-500/30' },
    finishing:      { label: 'Finishing',         cls: 'bg-purple-500/15 text-purple-400 ring-1 ring-purple-500/30' },
    handover_ready: { label: 'Siap Serah Terima', cls: 'bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/30' },
};

const activeTransaksi = ref(null);

onMounted(() => {
    if (!props.openTransaksiId) return;
    const idx = props.transaksis.findIndex(t => t.id === props.openTransaksiId);
    if (idx === -1) return;
    activeTransaksi.value = idx;
    nextTick(() => {
        document.getElementById(`transaksi-${props.openTransaksiId}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});
</script>

<template>
    <Head :title="`Konsumen – ${konsumen.nama}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <Link :href="route('konsumens.index')" class="hover:text-slate-200 transition-colors">Konsumen</Link>
                <span>/</span>
                <span class="text-slate-200 font-medium">{{ konsumen.nama }}</span>
            </div>
        </template>

        <div class="p-6 space-y-6">
            <!-- Profil Konsumen -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <div class="flex items-start justify-between">
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 bg-gradient-to-br from-violet-600 to-indigo-600 rounded-full flex items-center justify-center text-white text-xl font-bold shadow-lg">
                            {{ konsumen.nama.charAt(0).toUpperCase() }}
                        </div>
                        <div>
                            <h1 class="text-white text-xl font-bold">{{ konsumen.nama }}</h1>
                            <div class="flex items-center gap-3 mt-1 text-sm text-slate-400">
                                <span v-if="konsumen.no_hp">📱 {{ konsumen.no_hp }}</span>
                                <span v-if="konsumen.nik">🪪 {{ konsumen.nik }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <a v-if="konsumen.drive_folder_link"
                            :href="konsumen.drive_folder_link" target="_blank" rel="noopener noreferrer"
                            class="px-3 py-1.5 bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 text-sm rounded-lg transition-colors border border-emerald-500/20">
                            📁 Buka Folder Google Drive
                        </a>
                        <Link :href="route('konsumens.edit', konsumen.id)"
                            class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm rounded-lg transition-colors border border-slate-700">
                            ✏️ Edit
                        </Link>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-5 pt-5 border-t border-slate-800">
                    <div v-if="konsumen.email">
                        <div class="text-slate-500 text-xs">Email</div>
                        <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.email }}</div>
                    </div>
                    <div v-if="konsumen.pekerjaan">
                        <div class="text-slate-500 text-xs">Pekerjaan</div>
                        <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.pekerjaan }}</div>
                    </div>
                    <div v-if="konsumen.alamat">
                        <div class="text-slate-500 text-xs">Alamat</div>
                        <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.alamat }}</div>
                    </div>
                    <div>
                        <div class="text-slate-500 text-xs">Total Transaksi</div>
                        <div class="text-violet-400 font-bold text-lg mt-0.5">{{ transaksis.length }}</div>
                    </div>
                </div>
            </div>

            <!-- Riwayat Transaksi -->
            <div v-for="(trx, idx) in transaksis" :key="trx.id" :id="`transaksi-${trx.id}`" class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <!-- Header Transaksi -->
                <div class="flex items-center justify-between px-5 py-4 border-b border-slate-800 cursor-pointer hover:bg-slate-800/30 transition-colors"
                    @click="activeTransaksi = activeTransaksi === idx ? null : idx">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 bg-slate-800 rounded-lg flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-violet-400">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-slate-200 font-semibold">{{ trx.kavling_nomor }} · {{ trx.project_nama }}</div>
                            <div class="text-slate-500 text-xs mt-0.5">{{ trx.cara_bayar_label }} · {{ formatRp(trx.harga_deal) }}</div>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <span :class="['px-2 py-0.5 text-xs rounded-full font-medium', statusPenjualanConfig[trx.status_penjualan]?.cls]">
                            {{ statusPenjualanConfig[trx.status_penjualan]?.label }}
                        </span>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                            :class="['w-4 h-4 text-slate-500 transition-transform duration-200', activeTransaksi === idx ? 'rotate-180' : '']">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                </div>

                <!-- Detail Transaksi (collapsible) -->
                <div v-show="activeTransaksi === idx" class="px-5 py-4 space-y-6">
                    <!-- Detail Unit -->
                    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 bg-slate-800/40 rounded-lg p-3">
                        <div>
                            <div class="text-slate-500 text-[11px]">Unit</div>
                            <div class="text-slate-200 text-sm mt-0.5">{{ trx.kavling_nomor }}</div>
                        </div>
                        <div>
                            <div class="text-slate-500 text-[11px]">Proyek</div>
                            <div class="text-slate-200 text-sm mt-0.5">{{ trx.project_nama }}</div>
                        </div>
                        <div>
                            <div class="text-slate-500 text-[11px]">Tipe</div>
                            <div class="text-slate-200 text-sm mt-0.5">{{ trx.tipe_unit ?? '-' }}</div>
                        </div>
                        <div>
                            <div class="text-slate-500 text-[11px]">Luas Tanah/Bangunan</div>
                            <div class="text-slate-200 text-sm mt-0.5">{{ trx.luas_tanah ?? '-' }}/{{ trx.luas_bangunan ?? '-' }} m²</div>
                        </div>
                        <div>
                            <div class="text-slate-500 text-[11px] mb-1">Status Bangun</div>
                            <span :class="['px-2 py-0.5 text-xs rounded-full font-medium', statusBangunConfig[trx.status_bangun]?.cls]">
                                {{ statusBangunConfig[trx.status_bangun]?.label ?? trx.status_bangun_label }}
                            </span>
                        </div>
                        <div v-if="trx.is_kpr">
                            <div class="text-slate-500 text-[11px]">Bank Rekanan KPR</div>
                            <div class="text-slate-200 text-sm mt-0.5">{{ trx.bank_rekanan_kpr ?? '-' }}</div>
                        </div>
                    </div>

                    <!-- Aksi transaksi: Batal / Tukar Unit — keduanya muara ke menu Pembatalan -->
                    <div v-if="trx.status === 'active'" class="flex items-center gap-2">
                        <span v-if="trx.has_pending_request" class="px-2.5 py-1 bg-amber-500/10 text-amber-400 text-xs rounded-lg border border-amber-500/20">
                            ⏳ Menunggu review {{ trx.pending_request_type === 'unit_swap' ? 'tukar unit' : 'pembatalan' }}
                        </span>
                        <template v-else>
                            <button v-if="canRequestCancellation" @click="openPengajuan(trx, 'cancellation')"
                                class="px-2.5 py-1 bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 text-xs rounded-lg transition-colors border border-rose-500/20">
                                🚫 Ajukan Batal
                            </button>
                            <button v-if="canSwapKavling" @click="openPengajuan(trx, 'unit_swap')"
                                class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition-colors border border-slate-700">
                                🔄 Ajukan Tukar Unit
                            </button>
                        </template>
                    </div>

                    <!-- ═══ Kategori 1: Identitas Konsumen ═══ -->
                    <div class="space-y-2">
                        <h3 class="text-slate-300 text-sm font-semibold flex items-center gap-1.5">🪪 Identitas Konsumen</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 bg-slate-800/40 rounded-lg p-3">
                            <div>
                                <div class="text-slate-500 text-[11px]">Nama Lengkap</div>
                                <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.nama }}</div>
                            </div>
                            <div>
                                <div class="text-slate-500 text-[11px]">No. HP</div>
                                <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.no_hp ?? '-' }}</div>
                            </div>
                            <div>
                                <div class="text-slate-500 text-[11px]">NIK</div>
                                <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.nik ?? '-' }}</div>
                            </div>
                            <div>
                                <div class="text-slate-500 text-[11px]">Email</div>
                                <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.email ?? '-' }}</div>
                            </div>
                            <div>
                                <div class="text-slate-500 text-[11px]">Pekerjaan</div>
                                <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.pekerjaan ?? '-' }}</div>
                            </div>
                            <div>
                                <div class="text-slate-500 text-[11px]">Alamat</div>
                                <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.alamat ?? '-' }}</div>
                            </div>
                            <div v-if="konsumen.catatan" class="col-span-2 sm:col-span-3">
                                <div class="text-slate-500 text-[11px]">Catatan Sales</div>
                                <div class="text-slate-300 text-sm mt-0.5">{{ konsumen.catatan }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ Rincian Pemesanan: kalkulasi harga sama seperti form booking ═══ -->
                    <div class="space-y-2">
                        <h3 class="text-slate-300 text-sm font-semibold flex items-center gap-1.5">🧾 Rincian Pemesanan</h3>
                        <div class="bg-slate-800/40 rounded-lg p-3 space-y-1.5 text-sm">
                            <div class="flex justify-between text-slate-400">
                                <span>Harga Dasar</span>
                                <span class="text-slate-300">{{ formatRp(trx.harga_dasar) }}</span>
                            </div>
                            <div v-if="trx.biaya_kelebihan_tanah_aktif" class="flex justify-between text-slate-400">
                                <span>+ Biaya Kelebihan Tanah</span>
                                <span class="text-slate-300">{{ formatRp(trx.biaya_kelebihan_tanah_nominal) }}</span>
                            </div>
                            <div v-for="(bt, i) in trx.biaya_tambahan" :key="`bt-${i}`" class="flex justify-between text-slate-400">
                                <span>+ {{ bt.nama }}</span>
                                <span class="text-slate-300">{{ formatRp(bt.nominal) }}</span>
                            </div>
                            <div v-if="trx.diskon_nominal > 0" class="flex justify-between text-slate-400">
                                <span>− Diskon{{ trx.promo_nama ? ` (${trx.promo_nama})` : '' }}{{ trx.diskon_mode === 'persen' ? ` ${trx.diskon_nilai}%` : '' }}</span>
                                <span class="text-rose-400">{{ formatRp(trx.diskon_nominal) }}</span>
                            </div>
                            <div class="flex justify-between pt-1.5 border-t border-slate-700 font-semibold">
                                <span class="text-violet-300">Total Harga</span>
                                <span class="text-violet-300">{{ formatRp(trx.harga_deal) }}</span>
                            </div>

                            <div class="pt-2 mt-1 border-t border-slate-700/60">
                                <div class="text-slate-500 text-[11px] uppercase tracking-wide mb-1">
                                    Cara Pembayaran: {{ trx.cara_bayar_label }}
                                    <span v-if="trx.skema_dp_preset" class="text-slate-600 normal-case">· Skema: {{ trx.skema_dp_preset.nama }}</span>
                                </div>
                                <div v-if="trx.booking_fee > 0" class="flex justify-between text-slate-400">
                                    <span>
                                        Booking Fee
                                        <span v-if="trx.skema_dp_preset?.booking_fee_aktif" class="text-slate-600 text-xs ml-1">
                                            ({{ trx.skema_dp_preset.booking_fee_tipe === 'persen' ? trx.skema_dp_preset.booking_fee_nilai + '%' : 'nominal tetap' }},
                                            {{ trx.skema_dp_preset.booking_fee_tenor }}x,
                                            {{ trx.skema_dp_preset.booking_fee_masuk_harga_jual ? 'masuk harga jual' : 'di luar harga jual' }})
                                        </span>
                                    </span>
                                    <span class="text-slate-300">{{ formatRp(trx.booking_fee) }}</span>
                                </div>
                                <div v-if="trx.dp_nominal > 0" class="flex justify-between text-slate-400">
                                    <span>
                                        DP
                                        <span v-if="trx.skema_dp_preset?.dp_aktif" class="text-slate-600 text-xs ml-1">
                                            ({{ trx.skema_dp_preset.dp_tipe === 'persen' ? trx.skema_dp_preset.dp_nilai + '%' : 'nominal tetap' }},
                                            {{ trx.skema_dp_preset.dp_tenor }}x,
                                            {{ trx.skema_dp_preset.dp_masuk_harga_jual ? 'masuk harga jual' : 'di luar harga jual' }})
                                        </span>
                                    </span>
                                    <span class="text-slate-300">{{ formatRp(trx.dp_nominal) }}</span>
                                </div>
                                <div v-if="!(trx.booking_fee > 0) && !(trx.dp_nominal > 0)" class="text-slate-600 text-xs">Tidak ada booking fee maupun DP.</div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ Kategori 2: Pemberkasan ═══ -->
                    <div class="space-y-2">
                        <h3 class="text-slate-300 text-sm font-semibold flex items-center gap-1.5">📋 Pemberkasan</h3>

                        <div v-if="trx.dokumens?.length" class="space-y-2">
                            <div class="flex justify-between items-center">
                                <span class="text-slate-400 text-xs">Kelengkapan Berkas</span>
                                <span class="text-violet-400 font-bold text-sm">
                                    {{ trx.progress_berkas.ada }}/{{ trx.progress_berkas.total }}
                                    ({{ trx.progress_berkas.persen }}%)
                                </span>
                            </div>
                            <div class="h-2 bg-slate-800 rounded-full overflow-hidden">
                                <div
                                    class="h-full bg-gradient-to-r from-violet-500 to-indigo-500 rounded-full transition-all duration-500"
                                    :style="`width: ${trx.progress_berkas.persen}%`"
                                />
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1">
                                <div v-for="dok in trx.dokumens" :key="dok.id"
                                    class="flex items-center justify-between px-3 py-2 bg-slate-800/60 rounded-lg">
                                    <div class="flex items-center gap-2">
                                        <span class="text-base">{{ dok.status_icon }}</span>
                                        <span class="text-slate-300 text-sm">{{ dok.nama_dokumen }}</span>
                                        <span v-if="dok.sifat !== 'wajib'" class="text-slate-600 text-xs">({{ dok.sifat_label }})</span>
                                    </div>
                                    <span class="text-xs" :class="dok.status === 'sudah_ada' ? 'text-emerald-400' : ['perlu_revisi','ditolak'].includes(dok.status) ? 'text-amber-400' : 'text-slate-600'">
                                        {{ dok.status_label }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex justify-end">
                                <Link :href="route('dokumen.index', trx.id)" class="text-xs text-violet-400 hover:text-violet-300 transition-colors flex items-center gap-1">
                                    <span>Kelola Dokumen</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                                </Link>
                            </div>
                        </div>
                        <div v-else class="text-slate-600 text-xs px-1">Belum ada template dokumen untuk cara bayar ini.</div>
                    </div>

                    <!-- ═══ Kategori 3: Pembayaran ═══ -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-slate-300 text-sm font-semibold flex items-center gap-1.5">💳 Pembayaran</h3>
                            <Link v-if="canViewKeuangan" :href="route('keuangan.detail', trx.id)"
                                class="px-2.5 py-1 bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 text-xs rounded-lg transition-colors border border-emerald-500/20 flex items-center gap-1">
                                <span>💰 Kelola di Keuangan</span>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                            </Link>
                        </div>

        <!-- Kartu Piutang: Booking Fee/DP/Biaya Tanah/Biaya Tambahan/Pelunasan
             (KPR-nya sendiri pindah ke section Pencairan KPR di bawah) —
             Nama Biaya / Subjek / Nominal -->
                        <div class="space-y-2">
                            <div class="flex justify-between items-center">
                                <h4 class="text-slate-400 text-xs font-semibold uppercase tracking-wider">Kartu Piutang</h4>
                                <span class="text-slate-500 text-xs">Total: {{ formatRp(totalKartuPiutang(trx)) }}</span>
                            </div>

                            <div class="rounded-lg border border-slate-800 overflow-hidden">
                                <div class="px-3 py-1.5 bg-slate-800/80 text-slate-500 text-[11px] font-semibold uppercase tracking-wide">
                                    Nama Biaya / Subjek / Nominal / Status
                                </div>

                                <template v-for="(row, i) in trx.kartu_piutang_static" :key="`static-${i}`">
                                    <div
                                        class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-t border-slate-800/60 text-sm"
                                        :class="isGroupRow(row) ? 'cursor-pointer hover:bg-slate-800/40 transition-colors' : ''"
                                        @click="isGroupRow(row) && toggleRowExpand(trx.id, row.type)">
                                        <div class="min-w-[160px] flex items-center gap-1.5 flex-1">
                                            <svg v-if="isGroupRow(row)" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                                                :class="['w-3 h-3 text-slate-500 transition-transform flex-shrink-0', isRowExpanded(trx.id, row.type) ? 'rotate-90' : '']">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                            </svg>
                                            <span class="text-slate-300">{{ row.nama }}</span>
                                            <span class="text-slate-600 text-xs ml-2">{{ row.subjek }}</span>
                                            <span v-if="row.progress" class="text-slate-600 text-xs ml-2">({{ row.progress }})</span>
                                        </div>
                                        <div class="flex items-center gap-3 flex-shrink-0">
                                            <span class="text-slate-200 font-medium min-w-[110px] text-right">{{ nominalLabel(row.nominal, row.jumlah_dibayar, row.status) }}</span>
                                            <span class="w-20 flex justify-center"><StatusPembayaranBadge :status="row.status" /></span>
                                            <span class="w-28 flex justify-end" @click.stop>
                                                <CatatPembayaran v-if="row.type === 'biaya_tanah'"
                                                    :url="route('biaya-tanah.bayar', trx.id)"
                                                    :status="row.status" :tanggal-bayar="row.tanggal_bayar"
                                                    :default-jumlah="row.nominal" :can-manage="canPayItem(trx)" />
                                                <CatatPembayaran v-else-if="row.type === 'biaya_tambahan'"
                                                    :url="route('biaya-tambahan.bayar', row.id)"
                                                    :status="row.status" :tanggal-bayar="row.tanggal_bayar"
                                                    :default-jumlah="row.nominal" :can-manage="canPayItem(trx)" />
                                            </span>
                                        </div>
                                    </div>

                                    <div v-if="isGroupRow(row) && isRowExpanded(trx.id, row.type)" class="bg-slate-950/40 border-t border-slate-800/60">
                                        <div v-if="row.legacy_pembayaran"
                                            class="flex flex-wrap items-center justify-between gap-2 pl-8 pr-3 py-2 text-sm border-b border-slate-800/40">
                                            <span class="text-slate-400 flex-1">Pelunasan (tercatat sebelum jadwal cicilan)</span>
                                            <div class="flex items-center gap-3 flex-shrink-0">
                                                <span class="font-medium min-w-[110px] text-right text-slate-300">{{ formatRp(row.legacy_pembayaran.jumlah) }}</span>
                                                <span class="w-20 flex justify-center"><StatusPembayaranBadge status="lunas" /></span>
                                            </div>
                                        </div>
                                        <div v-for="j in cicilanForRow(trx, row)" :key="j.id"
                                            class="flex flex-wrap items-center justify-between gap-2 pl-8 pr-3 py-2 text-sm border-b border-slate-800/40 last:border-b-0">
                                            <div class="flex-1">
                                                <span class="text-slate-400">{{ j.jenis_label }} #{{ j.nomor_cicilan }}</span>
                                                <span class="text-slate-600 text-xs ml-2">jatuh tempo {{ j.tanggal_jatuh_tempo }}</span>
                                                <span v-if="j.status !== 'lunas' && j.is_terlambat" class="ml-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-rose-500/15 text-rose-400">Terlambat</span>
                                            </div>
                                            <div class="flex items-center gap-3 flex-shrink-0">
                                                <span class="font-medium min-w-[110px] text-right" :class="j.status === 'lunas' ? 'text-emerald-400' : 'text-slate-300'">{{ nominalLabel(j.jumlah, j.jumlah_dibayar, j.status) }}</span>
                                                <span class="w-20 flex justify-center"><StatusPembayaranBadge :status="j.status" /></span>
                                                <span class="w-28 flex justify-end">
                                                    <CatatPembayaran
                                                        :url="route('jadwal-tagihan.bayar', j.id)"
                                                        :status="j.status" :tanggal-bayar="j.tanggal_bayar"
                                                        :default-jumlah="j.jumlah" :can-manage="canPayItem(trx)" />
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                <div v-if="!trx.kartu_piutang_static?.length" class="px-3 py-4 text-center text-slate-600 text-xs">
                                    Belum ada data piutang.
                                </div>
                            </div>
                        </div>

                        <!-- Pencairan KPR: khusus cara bayar KPR (Subsidi & Komersil) —
                             urutan: input SBUM (Subsidi saja) → kalkulasi Plafon KPR →
                             input Dana Jaminan → Tambahan Uang Muka (kalau turun
                             plafon) → Pencairan KPR bersih. -->
                        <div v-if="trx.is_kpr" class="space-y-2">
                            <div class="flex justify-between items-center">
                                <h4 class="text-slate-400 text-xs font-semibold uppercase tracking-wider">Pencairan KPR</h4>
                                <div class="flex items-center gap-2">
                                    <span v-if="trx.bank_rekanan_kpr" class="text-slate-500 text-xs">{{ trx.bank_rekanan_kpr }}</span>
                                    <span class="text-slate-500 text-xs">Total: {{ formatRp(trx.total_piutang_bank) }}</span>
                                </div>
                            </div>

                            <div class="rounded-lg border border-slate-800 overflow-hidden">
                                <!-- 1. Sales input SBUM (khusus KPR Subsidi) -->
                                <template v-if="trx.is_kpr_subsidi">
                                    <div class="px-3 py-1.5 bg-slate-800/80 text-slate-500 text-[11px] font-semibold uppercase tracking-wide">SBUM</div>
                                    <div v-for="item in sbumItems(trx)" :key="`sbum-${item.id}`"
                                        class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-t border-slate-800/60 text-sm group">
                                        <div>
                                            <span class="text-slate-300">{{ item.nama }}</span>
                                            <span class="text-slate-600 text-xs ml-2">Pemerintah/Bank</span>
                                        </div>
                                        <div class="flex items-center gap-1.5">
                                            <template v-if="item.status !== 'lunas' && canEditBiayaAkad(trx)">
                                                <input type="number" :value="item.nominal"
                                                    @change="updateBiayaAkadNominal(item, $event.target.value)"
                                                    class="w-24 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-right text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                                <button @click="removeBiayaAkad(item)"
                                                    class="opacity-0 group-hover:opacity-100 text-rose-400 hover:bg-rose-500/10 rounded p-1 transition-all flex-shrink-0">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                    </svg>
                                                </button>
                                            </template>
                                            <span v-else class="text-slate-200 font-medium">{{ nominalLabel(item.nominal, item.jumlah_dibayar, item.status) }}</span>
                                            <CatatPembayaran
                                                :url="route('rincian-biaya-akad.bayar', item.id)"
                                                :status="item.status" :tanggal-bayar="item.tanggal_bayar"
                                                :default-jumlah="item.nominal" :can-manage="canPayDajamSbum(trx)" />
                                        </div>
                                    </div>
                                    <div v-if="canEditBiayaAkad(trx)" class="flex gap-2 px-3 py-2 border-t border-slate-800/60">
                                        <select v-model="getBiayaAkadForm(trx.id, 'sbum').dajam_sbum_preset_id"
                                            class="flex-1 px-2 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                                            <option value="" disabled>Pilih item SBUM...</option>
                                            <option v-for="preset in sbumPresetOptions" :key="preset.id" :value="preset.id">{{ preset.nama }}</option>
                                        </select>
                                        <input v-model="getBiayaAkadForm(trx.id, 'sbum').nominal" type="number" placeholder="Nominal"
                                            @keyup.enter="addBiayaAkad(trx, 'sbum')"
                                            class="w-28 px-2 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                        <button @click="addBiayaAkad(trx, 'sbum')"
                                            :disabled="!getBiayaAkadForm(trx.id, 'sbum').dajam_sbum_preset_id || !getBiayaAkadForm(trx.id, 'sbum').nominal"
                                            class="px-2.5 py-1.5 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-xs font-medium rounded-lg transition-colors whitespace-nowrap">
                                            + Tambah
                                        </button>
                                    </div>
                                </template>

                                <!-- 2. Kalkulasi Pengajuan Plafon KPR -->
                                <div class="flex items-center justify-between px-3 py-2.5 border-t border-slate-800/60 bg-slate-800/30">
                                    <span class="text-slate-300 font-medium">Plafon KPR (Pengajuan)</span>
                                    <div class="text-right">
                                        <span class="text-slate-500 text-xs mr-2">Bank</span>
                                        <span class="text-violet-300 font-semibold">{{ formatRp(trx.pencairan_kpr?.plafon_hitung) }}</span>
                                    </div>
                                </div>

                                <!-- 3. Sales input Dana Jaminan -->
                                <div class="px-3 py-1.5 bg-slate-800/80 text-slate-500 text-[11px] font-semibold uppercase tracking-wide">Dana Jaminan</div>
                                <div v-for="item in dajamItems(trx)" :key="`dajam-${item.id}`"
                                    class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-t border-slate-800/60 text-sm group">
                                    <div>
                                        <span class="text-slate-300">{{ item.nama }}</span>
                                        <span class="text-slate-600 text-xs ml-2">Bank</span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <template v-if="item.status !== 'lunas' && canEditBiayaAkad(trx)">
                                            <input type="number" :value="item.nominal"
                                                @change="updateBiayaAkadNominal(item, $event.target.value)"
                                                class="w-24 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-right text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                            <button @click="removeBiayaAkad(item)"
                                                class="opacity-0 group-hover:opacity-100 text-rose-400 hover:bg-rose-500/10 rounded p-1 transition-all flex-shrink-0">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </template>
                                        <span v-else class="text-slate-200 font-medium">{{ formatRp(item.nominal) }}</span>
                                        <StatusPembayaranBadge :status="item.status" />
                                        <CatatPembayaran
                                            :url="route('rincian-biaya-akad.bayar', item.id)"
                                            :status="item.status" :tanggal-bayar="item.tanggal_bayar"
                                            :default-jumlah="item.nominal" :can-manage="canPayDajamSbum(trx)" />
                                    </div>
                                </div>
                                <div v-if="canEditBiayaAkad(trx)" class="flex gap-2 px-3 py-2 border-t border-slate-800/60">
                                    <select v-model="getBiayaAkadForm(trx.id, 'dajam').dajam_sbum_preset_id"
                                        class="flex-1 px-2 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                                        <option value="" disabled>Pilih item Dana Jaminan...</option>
                                        <option v-for="preset in dajamPresetOptions" :key="preset.id" :value="preset.id">{{ preset.nama }}</option>
                                    </select>
                                    <input v-model="getBiayaAkadForm(trx.id, 'dajam').nominal" type="number" placeholder="Nominal"
                                        @keyup.enter="addBiayaAkad(trx, 'dajam')"
                                        class="w-28 px-2 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                    <button @click="addBiayaAkad(trx, 'dajam')"
                                        :disabled="!getBiayaAkadForm(trx.id, 'dajam').dajam_sbum_preset_id || !getBiayaAkadForm(trx.id, 'dajam').nominal"
                                        class="px-2.5 py-1.5 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-xs font-medium rounded-lg transition-colors whitespace-nowrap">
                                        + Tambah
                                    </button>
                                </div>

                                <!-- 4. (-) Turun Plafon — aksinya di baris Tambahan Uang Muka Kartu Piutang -->
                                <div v-if="tambahanUmRow(trx)" class="flex items-center justify-between px-3 py-2.5 border-t border-slate-800/60">
                                    <span class="text-slate-400">(−) Turun Plafon <span class="text-slate-600 text-xs">— lihat baris Tambahan Uang Muka di Kartu Piutang</span></span>
                                    <span class="text-amber-300 font-medium">{{ formatRp(tambahanUmRow(trx).nominal) }}</span>
                                </div>

                                <!-- 5. Pencairan KPR bersih -->
                                <div class="flex items-center justify-between px-3 py-2.5 border-t border-slate-800/60 bg-emerald-500/10 cursor-pointer hover:bg-emerald-500/15 transition-colors"
                                    @click="togglePencairanTahap(trx.id)">
                                    <span class="text-emerald-300 font-semibold flex items-center gap-1.5">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                                            :class="['w-3 h-3 transition-transform flex-shrink-0', showPencairanTahap[trx.id] ? 'rotate-90' : '']">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                        </svg>
                                        Pencairan KPR Netto
                                    </span>
                                    <div class="flex items-center gap-3">
                                        <span class="text-slate-500 text-xs">Bank</span>
                                        <span class="text-emerald-300 font-bold min-w-[110px] text-right">{{ nominalLabel(trx.pencairan_kpr?.pencairan_nominal, trx.pencairan_kpr?.pencairan_tercatat, trx.pencairan_kpr?.pencairan_status) }}</span>
                                        <StatusPembayaranBadge :status="trx.pencairan_kpr?.pencairan_status" />
                                    </div>
                                </div>
                                <div v-if="showPencairanTahap[trx.id]" class="bg-slate-950/40 border-t border-slate-800/60">
                                    <div v-for="t in trx.pencairan_kpr_tahaps" :key="t.id"
                                        class="pl-8 pr-3 py-2 text-sm border-b border-slate-800/40 last:border-b-0">
                                        <span class="text-slate-300 font-medium">{{ formatRp(t.nominal) }}</span>
                                        <span class="text-slate-600 text-xs ml-2">cair {{ t.tanggal_cair }}</span>
                                        <span v-if="t.keterangan" class="text-slate-600 text-xs ml-2">· {{ t.keterangan }}</span>
                                    </div>
                                    <div v-if="!trx.pencairan_kpr_tahaps?.length" class="pl-8 pr-3 py-2 text-slate-600 text-xs">Belum ada pencairan tercatat.</div>
                                </div>
                            </div>
                        </div>

                        <!-- Kartu Piutang Titipan: Biaya Akad -->
                        <div class="space-y-2">
                            <div class="flex justify-between items-center">
                                <h4 class="text-slate-400 text-xs font-semibold uppercase tracking-wider">Kartu Piutang Titipan</h4>
                                <div class="flex items-center gap-2">
                                    <span v-if="trx.is_locked" class="text-amber-400 text-xs flex items-center gap-1" title="Terkunci sejak ditandai Selesai di Keuangan">
                                        🔒 Terkunci
                                    </span>
                                    <span v-if="biayaAkadTitipanItems(trx).length" class="text-slate-500 text-xs">
                                        Total: {{ formatRp(totalTitipan(trx)) }}
                                    </span>
                                </div>
                            </div>

                            <div v-if="biayaAkadTitipanItems(trx).length" class="space-y-1.5">
                                <div v-for="item in biayaAkadTitipanItems(trx)" :key="item.id"
                                    class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 bg-slate-800/60 rounded-lg text-sm group">
                                    <div>
                                        <span class="text-slate-300">{{ item.nama }}</span>
                                        <span class="text-slate-600 text-xs ml-2">Biaya Akad</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <template v-if="item.status !== 'lunas' && canEditBiayaAkad(trx)">
                                            <input type="number" :value="item.nominal"
                                                @change="updateBiayaAkadNominal(item, $event.target.value)"
                                                class="w-32 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-right text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                            <button @click="removeBiayaAkad(item)"
                                                class="opacity-0 group-hover:opacity-100 text-rose-400 hover:bg-rose-500/10 rounded p-1.5 transition-all">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </template>
                                        <span v-else class="text-slate-300 font-medium">{{ nominalLabel(item.nominal, item.jumlah_dibayar, item.status) }}</span>
                                        <StatusPembayaranBadge :status="item.status" />
                                        <CatatPembayaran
                                            :url="route('rincian-biaya-akad.bayar', item.id)"
                                            :status="item.status" :tanggal-bayar="item.tanggal_bayar"
                                            :default-jumlah="item.nominal" :can-manage="canPayDajamSbum(trx)" />
                                    </div>
                                </div>
                            </div>
                            <div v-else class="text-slate-600 text-xs px-1">Belum ada biaya akad.</div>

                            <div v-if="canEditBiayaAkad(trx)" class="flex gap-2 pt-1">
                                <select v-model="getBiayaAkadForm(trx.id, 'titipan').dajam_sbum_preset_id"
                                    class="flex-1 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                                    <option value="" disabled>Pilih item Biaya Akad...</option>
                                    <option v-for="preset in biayaAkadPresetOptions" :key="preset.id" :value="preset.id">
                                        {{ preset.nama }}
                                    </option>
                                </select>
                                <input v-model="getBiayaAkadForm(trx.id, 'titipan').nominal" type="number" placeholder="Nominal"
                                    @keyup.enter="addBiayaAkad(trx, 'titipan')"
                                    class="w-36 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                <button @click="addBiayaAkad(trx, 'titipan')"
                                    :disabled="!getBiayaAkadForm(trx.id, 'titipan').dajam_sbum_preset_id || !getBiayaAkadForm(trx.id, 'titipan').nominal"
                                    class="px-3 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors whitespace-nowrap">
                                    + Tambah
                                </button>
                            </div>
                        </div>

                        <!-- Riwayat Pembayaran aktual -->
                        <div v-if="trx.pembayarans?.length" class="space-y-2">
                            <h4 class="text-slate-400 text-xs font-semibold uppercase tracking-wider">Riwayat Pembayaran</h4>
                            <div class="space-y-1.5">
                                <div v-for="p in trx.pembayarans" :key="p.id"
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
                </div>
            </div>

            <div v-if="!transaksis.length" class="text-center py-12 text-slate-500 text-sm">
                Konsumen belum memiliki transaksi
            </div>
        </div>

        <PengajuanKonsumenModal
            :show="pengajuanModal.show"
            :type="pengajuanModal.type"
            :kavling-id="pengajuanModal.trx?.kavling_id"
            :kavling-konsumen-id="pengajuanModal.trx?.id"
            :project-id="pengajuanModal.trx?.project_id"
            @close="closePengajuan"
            @success="onPengajuanSuccess"
        />
    </AuthenticatedLayout>
</template>

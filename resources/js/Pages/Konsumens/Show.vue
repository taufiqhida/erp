<script setup>
import CatatanTransaksi from '@/Components/CatatanTransaksi.vue';
import AlihPembeliModal from '@/Components/AlihPembeliModal.vue';
import LengkapiRincianModal from '@/Components/LengkapiRincianModal.vue';
import PotonganPromoInput from '@/Components/PotonganPromoInput.vue';
import { konfirmasi } from '@/Composables/useConfirm';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CatatPembayaran from '@/Components/CatatPembayaran.vue';
import StatusPembayaranBadge from '@/Components/StatusPembayaranBadge.vue';
import PengajuanKonsumenModal from '@/Components/PengajuanKonsumenModal.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, reactive, computed, onMounted, nextTick } from 'vue';

const props = defineProps({
    konsumen: Object,
    transaksis: Array,
    peralihan: { type: Array, default: () => [] },
    openTransaksiId: Number,
    dajamSbumPresets: Array,
    statusBangunStages: Array,
    biayaTambahanPresets: { type: Array, default: () => [] },
    promoPresets: { type: Array, default: () => [] },
    programAllInPresets: { type: Array, default: () => [] },
    suratTemplates: { type: Array, default: () => [] },
});

const canManageRincianBiayaAkad = computed(() => usePage().props.auth.user?.permissions?.includes('manage rincian biaya akad'));
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
const lengkapiTrx = ref(null);  // transaksi batal yang rinciannya sedang dilengkapi dari skema
const canLengkapiRincian = computed(() => usePage().props.auth.user?.permissions?.includes('review cancellation'));
const alihTrx = ref(null);       // transaksi yang sedang diajukan Alih Pembeli
const onPengajuanSuccess = () => { pengajuanModal.show = false; router.reload({ only: ['transaksis'] }); };
const overrideLock = computed(() => usePage().props.auth.user?.permissions?.includes('override transaction lock'));
const canEditBiayaAkad = (trx) => canManageRincianBiayaAkad.value && (!trx.is_locked || overrideLock.value);

// ── Edit Rincian Pesanan — sengaja dibatasi cuma boleh mengubah komponen
// yang BELUM ada pembayaran tercatat (Biaya Kelebihan Tanah, Biaya Tambahan
// per-item, Diskon/Promo). Cara Bayar & Skema DP tidak bisa diganti di sini
// sama sekali (lihat BookingController::updateRincianPesanan).
// Rincian (termasuk Tanggal Booking): SPV/Leader/Superadmin mengubah langsung; Admin Sales mengirim USULAN yang disetujui SPV/Leader.
// Hak dan tahap yang diizinkan ditentukan server (trx.can_ubah_rincian); selama usulan menunggu, edit berikutnya dikunci.
const canEditRincian = (trx) => !!trx.can_ubah_rincian && !trx.has_pending_rincian;
const biayaKelebihanLocked = (trx) => !!trx.biaya_kelebihan_tanah_locked;
const titipanBiayaAkadLocked = (trx) => !!trx.titipan_biaya_akad_locked;

const editingRincian = reactive({});
const rincianForms = reactive({});

const getRincianForm = (trx) => {
    if (!rincianForms[trx.id]) {
        rincianForms[trx.id] = {
            biaya_kelebihan_tanah_aktif: trx.biaya_kelebihan_tanah_aktif ?? false,
            biaya_kelebihan_tanah_luas: trx.biaya_kelebihan_tanah_luas ?? '',
            biaya_kelebihan_tanah_mode: trx.biaya_kelebihan_tanah_mode ?? 'per_m2',
            biaya_kelebihan_tanah_harga_per_m2: trx.biaya_kelebihan_tanah_harga_per_m2 ?? '',
            biaya_kelebihan_tanah_nominal_input: trx.biaya_kelebihan_tanah_mode === 'nominal' ? (trx.biaya_kelebihan_tanah_nominal ?? '') : '',
            potongan_tanah: { mode: trx.biaya_kelebihan_tanah_potongan_mode ?? 'persen', nilai: trx.biaya_kelebihan_tanah_potongan_nilai ?? '' },
            biaya_tambahan: (trx.biaya_tambahan || []).map(bt => ({ ...bt, potongan_input: { mode: bt.potongan_mode ?? 'persen', nilai: bt.potongan_nilai ?? '' } })),
            promo_preset_id: trx.promo_preset_id ?? '',
            diskon_mode: trx.diskon_mode ?? '',
            diskon_nilai: trx.diskon_nilai ?? '',
            program_all_in_preset_id: trx.program_all_in_preset_id ?? '',
            tanggal_booking: trx.tanggal_booking_raw ?? '',
            harga_dasar_ke_unit: false,
            alasan: '',
            pratinjau: null,
            memuat: false,
            processing: false,
            errors: {},
        };
    }
    return rincianForms[trx.id];
};

const openEditRincian = (trx) => {
    delete rincianForms[trx.id];
    getRincianForm(trx);
    editingRincian[trx.id] = true;
};
const cancelEditRincian = (trxId) => { editingRincian[trxId] = false; };

const addRincianBiayaTambahan = (trx) => {
    getRincianForm(trx).biaya_tambahan.push({ id: null, preset_id: '', nama: '', nominal: '', locked: false, potongan_input: { mode: 'persen', nilai: '' } });
};
const removeRincianBiayaTambahan = (trx, idx) => {
    getRincianForm(trx).biaya_tambahan.splice(idx, 1);
};

// Potongan promo: hanya baris yang menjadi "lawan" dari promo terpilih (diatur di Pengaturan > Promo) yang mendapat isian potongan.
const promoForm = (form) => props.promoPresets.find(p => String(p.id) === String(form.promo_preset_id)) ?? null;
const brutoTanahForm = (form) => form.biaya_kelebihan_tanah_mode === 'per_m2'
    ? (Number(form.biaya_kelebihan_tanah_luas) || 0) * (Number(form.biaya_kelebihan_tanah_harga_per_m2) || 0)
    : (Number(form.biaya_kelebihan_tanah_nominal_input) || 0);
const lawanTanahForm = (form, locked = false) => !!promoForm(form)?.lawan_tanah && form.biaya_kelebihan_tanah_aktif && !locked && brutoTanahForm(form) > 0;
// Bruto untuk potongan Harga Jual = harga dasar + biaya bersih (setelah potongan lain), sama seperti dasar hitung diskon di server.
const potonganNominalForm = (bruto, p) => {
    const n = Number(p?.nilai);
    if (!n || bruto <= 0) return 0;
    return Math.max(0, Math.min(bruto, p.mode === 'nominal' ? n : bruto * (n / 100)));
};
const subtotalHargaJualForm = (trx, form) => {
    const tanah = form.biaya_kelebihan_tanah_aktif ? brutoTanahForm(form) - (lawanTanahForm(form, biayaKelebihanLocked(trx)) ? potonganNominalForm(brutoTanahForm(form), form.potongan_tanah) : Number(biayaKelebihanLocked(trx) ? trx.biaya_kelebihan_tanah_potongan : 0)) : 0;
    const lain = form.biaya_tambahan.reduce((s, bt) => s + (Number(bt.nominal) || 0) - (lawanBtForm(form, bt) ? potonganNominalForm(Number(bt.nominal) || 0, bt.potongan_input) : Number(bt.locked ? bt.potongan : 0)), 0);
    return (Number(form.harga_dasar_ke_unit && trx.harga_unit_sekarang != null ? trx.harga_unit_sekarang : trx.harga_dasar) || 0) + tanah + lain;
};
const lawanBtForm = (form, bt) => !bt.locked && !!promoForm(form)?.lawan_biaya_tambahan?.includes(Number(bt.preset_id));

const muatanRincian = (form, tambahan = {}) => ({
    biaya_kelebihan_tanah_aktif: form.biaya_kelebihan_tanah_aktif,
    biaya_kelebihan_tanah_luas: form.biaya_kelebihan_tanah_luas || null,
    biaya_kelebihan_tanah_mode: form.biaya_kelebihan_tanah_mode,
    biaya_kelebihan_tanah_harga_per_m2: form.biaya_kelebihan_tanah_harga_per_m2 || null,
    biaya_kelebihan_tanah_nominal_input: form.biaya_kelebihan_tanah_nominal_input || null,
    potongan_tanah: lawanTanahForm(form) && form.potongan_tanah?.nilai !== '' && form.potongan_tanah?.nilai != null ? form.potongan_tanah : null,
    biaya_tambahan: form.biaya_tambahan.filter(bt => !bt.locked).map(bt => ({
        id: bt.id || null, preset_id: bt.preset_id, nominal: Number(bt.nominal) || 0,
        potongan: lawanBtForm(form, bt) && bt.potongan_input?.nilai !== '' && bt.potongan_input?.nilai != null ? bt.potongan_input : null,
    })),
    promo_preset_id: form.promo_preset_id || null,
    // Nilai kosong/0 berarti diskon dihapus: mode ikut dikosongkan (kalau tidak, server menolak "nilai diskon wajib diisi").
    diskon_mode: Number(form.diskon_nilai) && promoForm(form)?.lawan_harga_jual ? (form.diskon_mode || null) : null,
    diskon_nilai: Number(form.diskon_nilai) && promoForm(form)?.lawan_harga_jual ? Number(form.diskon_nilai) : null,
    program_all_in_preset_id: form.program_all_in_preset_id || null,
    tanggal_booking: form.tanggal_booking || null,
    harga_dasar_ke_unit: form.harga_dasar_ke_unit,
    alasan: form.alasan,
    ...tambahan,
});

// Pratinjau perbedaan sebelum/sesudah (server menerapkan lalu membatalkan, jadi pasti sama dengan hasil penyimpanan).
const periksaRincian = async (trx) => {
    const form = getRincianForm(trx);
    form.memuat = true;
    form.errors = {};
    try {
        form.pratinjau = (await window.axios.patch(route('bookings.rincian-pesanan', trx.id), muatanRincian(form, { simulasi: true }))).data;
    } catch (e) {
        form.pratinjau = null;
        form.errors = e.response?.data?.errors ?? { galat: e.response?.data?.message ?? 'Gagal memeriksa perubahan.' };
    } finally {
        form.memuat = false;
    }
};

const submitRincianPesanan = (trx) => {
    const form = getRincianForm(trx);
    form.processing = true;
    form.errors = {};
    router.patch(route('bookings.rincian-pesanan', trx.id), muatanRincian(form), {
        preserveScroll: true,
        onSuccess: () => { editingRincian[trx.id] = false; delete rincianForms[trx.id]; },
        onError: (errors) => { form.errors = errors; },
        onFinish: () => { form.processing = false; },
    });
};
// Pencatatan pembayaran sekarang murni domain Keuangan (lihat
// Keuangan/TransaksiDetail.vue) — di tab Konsumen cuma tampil status
// Lunas/Belum Bayar, tidak ada aksi. Sales/manajer tetap kelola item
// (SBUM/Dajam/Biaya Akad/Biaya Tambahan) di sini lewat canEditBiayaAkad.
const canPayItem = () => false;
const canPayDajamSbum = () => false;

// Rincian Biaya Akad (dajam/sbum) — dajam & sbum jadi baris di Pencairan
// KPR, masing-masing dengan form tambah sendiri (form di-key per section
// biar gak tabrakan).
const biayaAkadForms = reactive({});
const getBiayaAkadForm = (trxId, section) => {
    const key = `${trxId}:${section}`;
    if (!biayaAkadForms[key]) biayaAkadForms[key] = { dajam_sbum_preset_id: '', nominal: '' };
    return biayaAkadForms[key];
};

const sbumItems = (trx) => (trx.rincian_biaya_akad || []).filter(i => i.kategori === 'sbum');
const dajamItems = (trx) => (trx.rincian_biaya_akad || []).filter(i => i.kategori === 'dajam');

// SBUM cuma relevan untuk KPR Subsidi — di luar itu opsi tambah SBUM
// disembunyikan (tapi item SBUM lama yang sudah ada tetap ditampilkan apa adanya).
const sbumPresetOptions = computed(() => props.dajamSbumPresets.filter(p => p.kategori === 'sbum'));
const dajamPresetOptions = computed(() => props.dajamSbumPresets.filter(p => p.kategori === 'dajam'));

const totalKartuPiutang = (trx) => (trx.kartu_piutang_static || []).reduce((sum, i) => sum + Number(i.nominal), 0);

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

const removeBiayaAkad = async (item) => {
    if (await konfirmasi(`Hapus item "${item.nama}"?`)) {
        router.delete(route('konsumens.biaya-akad.destroy', item.id), { preserveScroll: true });
    }
};

// Baris Kartu Piutang yang punya cicilan (Booking Fee/DP/Pelunasan bertahap)
// dirender sebagai grup — klik row utk expand/collapse lihat detail per
// cicilan inline, tidak ada lagi section "lihat detail cicilan" terpisah.
const rowJenisMap = { booking_fee_group: 'booking_fee', dp_group: 'dp', pelunasan_group: 'pelunasan' };
// Biaya Tanah/Tambahan UM/Titipan Biaya Akad/Biaya Tambahan — cicilan bebas,
// datanya sudah dikirim langsung di row.cicilan (bukan difilter dari
// jadwal_tagihan). Halaman ini murni read-only, jadi cuma perlu expand utk
// lihat, tanpa aksi. Biaya Tambahan beda dari 3 lainnya: satu transaksi bisa
// punya banyak baris bertipe sama (multi-preset), jadi expand-state harus
// discope per row.id juga, bukan cuma row.type.
const cicilanLainGroupTypes = ['biaya_tanah_group', 'tambahan_um_group', 'titipan_biaya_akad_group', 'biaya_tambahan_group'];
const isGroupRow = (row) => !!rowJenisMap[row.type] || cicilanLainGroupTypes.includes(row.type);
const cicilanForRow = (trx, row) => (trx.jadwal_tagihan || []).filter(j => j.jenis === rowJenisMap[row.type]);
const expandedRows = reactive({});
const rowKey = (trxId, row) => `${trxId}:${row.type}:${row.id ?? ''}`;
const isRowExpanded = (trxId, row) => !!expandedRows[rowKey(trxId, row)];
const toggleRowExpand = (trxId, row) => { const k = rowKey(trxId, row); expandedRows[k] = !expandedRows[k]; };

// ── Cetak Dokumen — 1 form per transaksi, template dipilih lewat dropdown
// (bukan expand-list per template) supaya tidak menuh-menuhin layar makin
// banyak Template Surat ditambahkan. Native form (GET) karena generate-nya
// murni export file, tidak ubah data — lihat SuratGenerateController.
const cetakTemplateId = reactive({});
const selectedTemplateId = (trxId) => cetakTemplateId[trxId] ?? props.suratTemplates[0]?.id;
const setCetakTemplateId = (trxId, val) => { cetakTemplateId[trxId] = val; };

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

// Pipeline (status_penjualan) — warnanya dinamis dari master "Warna Status"
// (Pengaturan), sama sumbernya dengan Konsumens/Index.vue.
const STATUS_PENJUALAN_LABELS = {
    booking: 'Booking', pemberkasan: 'Pemberkasan', proses_bank: 'Proses Bank/SLIK',
    sp3k: 'SP3K', rencana_akad: 'Rencana Akad', akad: 'Akad', bast: 'BAST / Selesai', batal: 'Batal',
};
const statusPenjualanConfig = computed(() => {
    const colors = usePage().props.statusColors?.status_penjualan ?? {};
    return Object.fromEntries(Object.entries(STATUS_PENJUALAN_LABELS).map(([k, label]) => {
        const hex = colors[k] ?? '#94a3b8';
        return [k, { label, style: `background:${hex}26; --w:${hex}; color:var(--w)` }];
    }));
});

// Status Bangun — sumbernya master preset live (props.statusBangunStages),
// warna badge dibentuk inline dari hex `warna`, bukan class hardcode.
const statusBangunColorHex = computed(() =>
    Object.fromEntries((props.statusBangunStages ?? []).map(s => [s.id, s.warna]))
);

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
                    <div v-if="konsumen.pekerjaan_label">
                        <div class="text-slate-500 text-xs">Jenis Pekerjaan</div>
                        <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.pekerjaan_label }}</div>
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

            <!-- Riwayat peralihan pembeli (sebagai pembeli lama maupun baru) -->
            <div v-if="peralihan.length" class="bg-sky-500/10 border border-sky-500/20 rounded-xl px-4 py-3 space-y-2">
                <h3 class="text-sky-200 text-sm font-semibold">👥 Riwayat Peralihan Pembeli</h3>
                <div v-for="p in peralihan" :key="p.id" class="text-sm text-slate-300">
                    <template v-if="p.dari_id === konsumen.id">
                        Unit <b>{{ p.unit }}</b> ({{ p.proyek }}) <b class="text-sky-300">dialihkan ke {{ p.ke }}</b> ({{ p.hubungan }}) pada {{ p.tanggal }}.
                    </template>
                    <template v-else>
                        Unit <b>{{ p.unit }}</b> ({{ p.proyek }}) <b class="text-sky-300">peralihan dari {{ p.dari }}</b> ({{ p.hubungan }}) pada {{ p.tanggal }}.
                    </template>
                    <div class="text-slate-500 text-xs">Alasan: {{ p.alasan }} · diajukan {{ p.diajukan_oleh ?? '-' }}, disetujui {{ p.disetujui_oleh ?? '-' }}</div>
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
                        <span class="px-2 py-0.5 text-xs rounded-full font-medium" :style="statusPenjualanConfig[trx.status_penjualan]?.style">
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
                            <div class="text-slate-500 text-[0.6875rem]">Unit</div>
                            <div class="text-slate-200 text-sm mt-0.5">{{ trx.kavling_nomor }}</div>
                        </div>
                        <div>
                            <div class="text-slate-500 text-[0.6875rem]">Proyek</div>
                            <div class="text-slate-200 text-sm mt-0.5">{{ trx.project_nama }}</div>
                        </div>
                        <div>
                            <div class="text-slate-500 text-[0.6875rem]">Tipe</div>
                            <div class="text-slate-200 text-sm mt-0.5">{{ trx.tipe_unit ?? '-' }}</div>
                        </div>
                        <div>
                            <div class="text-slate-500 text-[0.6875rem]">Luas Tanah/Bangunan</div>
                            <div class="text-slate-200 text-sm mt-0.5">{{ trx.luas_tanah ?? '-' }}/{{ trx.luas_bangunan ?? '-' }} m²</div>
                        </div>
                        <div>
                            <div class="text-slate-500 text-[0.6875rem] mb-1">Status Bangun</div>
                            <span :style="`background:${statusBangunColorHex[trx.status_bangun_stage_id]}25; --w:${statusBangunColorHex[trx.status_bangun_stage_id]}; color:var(--w)`"
                                class="px-2 py-0.5 text-xs rounded-full font-medium">
                                {{ trx.status_bangun_label }}
                            </span>
                        </div>
                        <div>
                            <div class="text-slate-500 text-[0.6875rem]">Sales / Agent</div>
                            <div class="text-slate-200 text-sm mt-0.5">{{ trx.sales_nama ?? '-' }}</div>
                        </div>
                        <div v-if="trx.is_kpr">
                            <div class="text-slate-500 text-[0.6875rem]">Bank Rekanan KPR</div>
                            <div class="text-slate-200 text-sm mt-0.5">{{ trx.bank_rekanan_kpr ?? '-' }}</div>
                        </div>
                        <div>
                            <div class="text-slate-500 text-[0.6875rem] mb-1">ID Rumah (Tapera/SIKUMBANG)</div>
                            <div class="text-slate-200 text-sm mt-0.5 font-mono">{{ trx.id_rumah ?? '-' }}</div>
                        </div>
                        <div>
                            <div class="text-slate-500 text-[0.6875rem]">Tanggal Booking</div>
                            <div class="text-slate-200 text-sm mt-0.5">{{ trx.tanggal_booking ?? '-' }}</div>
                        </div>
                        <div>
                            <div class="text-slate-500 text-[0.6875rem]">Tanggal Akad</div>
                            <div class="text-slate-200 text-sm mt-0.5">{{ trx.tanggal_akad ?? '-' }}</div>
                        </div>
                    </div>

                    <!-- Aksi transaksi: Batal / Tukar Unit / Alih Pembeli — semuanya muara ke menu Pengajuan -->
                    <div v-if="trx.status === 'active'" class="flex flex-wrap items-center gap-2">
                        <span v-if="trx.has_pending_request" class="px-2.5 py-1 bg-amber-500/10 text-amber-400 text-xs rounded-lg border border-amber-500/20">
                            ⏳ Menunggu review {{ { unit_swap: 'tukar unit', buyer_transfer: 'alih pembeli' }[trx.pending_request_type] ?? 'pembatalan' }}
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
                            <button v-if="trx.can_alih_pembeli" @click="alihTrx = trx"
                                class="px-2.5 py-1 bg-sky-500/10 hover:bg-sky-500/20 text-sky-300 text-xs rounded-lg transition-colors border border-sky-500/20">
                                👥 Alihkan Pembeli
                            </button>
                        </template>
                    </div>

                    <!-- Rincian pembatalan: tanggal, alasan, tahap terakhir, dan uang -->
                    <div v-if="trx.pembatalan" class="bg-rose-500/10 border border-rose-500/20 rounded-lg px-3 py-2.5 text-xs space-y-1">
                        <div class="text-rose-300 font-semibold text-sm">🚫 Dibatalkan pada {{ trx.pembatalan.tanggal ?? '-' }}</div>
                        <div class="text-slate-300">Alasan: {{ trx.pembatalan.alasan }}</div>
                        <div v-if="trx.pembatalan.tahap" class="text-slate-400">Tahap terakhir sebelum batal: <b class="text-slate-200">{{ trx.pembatalan.tahap }}</b></div>
                        <div class="text-slate-400">
                            Diterima {{ formatRp(trx.pembatalan.diterima) }} · Dikembalikan {{ formatRp(trx.pembatalan.dikembalikan) }} ·
                            <span class="text-rose-300">Hangus {{ formatRp(trx.pembatalan.hangus) }}</span>
                        </div>
                        <div v-if="trx.pembatalan.catatan" class="text-slate-400 italic">↳ {{ trx.pembatalan.catatan }}</div>
                        <button v-if="trx.bisa_lengkapi_rincian && canLengkapiRincian" @click="lengkapiTrx = trx"
                            class="mt-1 px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs rounded-lg border border-slate-700">
                            🧮 Lengkapi rincian dari skema
                        </button>
                        <div class="text-slate-500">
                            Diajukan {{ trx.pembatalan.diajukan ?? '-' }}<span v-if="trx.pembatalan.diajukan_oleh"> oleh {{ trx.pembatalan.diajukan_oleh }}</span><span v-if="trx.pembatalan.disetujui_oleh"> · disetujui {{ trx.pembatalan.disetujui_oleh }}</span>
                        </div>
                    </div>

                    <!-- Riwayat peralihan pembeli pada transaksi ini -->
                    <div v-for="p in trx.peralihan" :key="p.id" class="text-xs bg-sky-500/10 border border-sky-500/20 rounded-lg px-3 py-2 text-sky-200">
                        👥 Pembeli dialihkan dari <b>{{ p.dari }}</b> ke <b>{{ p.ke }}</b> ({{ p.hubungan }}) pada {{ p.tanggal }}.
                        <span class="text-sky-300/80">Pembayaran sebelum tanggal tersebut (termasuk UTJ) dilakukan atas nama {{ p.dari }}. Alasan: {{ p.alasan }}</span>
                    </div>

                    <!-- ═══ Catatan transaksi: booking, pemberkasan (bisa diedit/diselesaikan), revisi dokumen, bank, SP3K, BAST ═══ -->
                    <CatatanTransaksi :data="trx.catatan_transaksi" :kk-id="trx.id" :link-kartu="route('dokumen.index', trx.id)" />

                    <!-- ═══ Kategori 1: Identitas Konsumen ═══ -->
                    <div class="space-y-2">
                        <h3 class="text-slate-300 text-sm font-semibold flex items-center gap-1.5">🪪 Identitas Konsumen</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 bg-slate-800/40 rounded-lg p-3">
                            <div>
                                <div class="text-slate-500 text-[0.6875rem]">Nama Lengkap</div>
                                <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.nama }}</div>
                            </div>
                            <div>
                                <div class="text-slate-500 text-[0.6875rem]">No. HP</div>
                                <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.no_hp ?? '-' }}</div>
                            </div>
                            <div>
                                <div class="text-slate-500 text-[0.6875rem]">NIK</div>
                                <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.nik ?? '-' }}</div>
                            </div>
                            <div>
                                <div class="text-slate-500 text-[0.6875rem]">NPWP</div>
                                <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.npwp ?? '-' }}</div>
                            </div>
                            <div>
                                <div class="text-slate-500 text-[0.6875rem]">Email</div>
                                <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.email ?? '-' }}</div>
                            </div>
                            <div>
                                <div class="text-slate-500 text-[0.6875rem]">Jenis Pekerjaan</div>
                                <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.pekerjaan_label ?? '-' }}</div>
                            </div>
                            <div>
                                <div class="text-slate-500 text-[0.6875rem]">Status Pernikahan</div>
                                <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.status_pernikahan_label ?? '-' }}</div>
                            </div>
                            <div>
                                <div class="text-slate-500 text-[0.6875rem]">Sumber Lead</div>
                                <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.sumber_lead_nama ?? '-' }}<span v-if="konsumen.referral_keterangan" class="text-slate-400"> — {{ konsumen.referral_keterangan }}</span></div>
                            </div>
                            <div>
                                <div class="text-slate-500 text-[0.6875rem]">Alamat</div>
                                <div class="text-slate-200 text-sm mt-0.5">{{ konsumen.alamat ?? '-' }}</div>
                            </div>
                            <div v-if="konsumen.catatan" class="col-span-2 sm:col-span-3">
                                <div class="text-slate-500 text-[0.6875rem]">Catatan Sales</div>
                                <div class="text-slate-300 text-sm mt-0.5">{{ konsumen.catatan }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ Rincian Pemesanan: kalkulasi harga sama seperti form booking ═══ -->
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <h3 class="text-slate-300 text-sm font-semibold flex items-center gap-1.5">🧾 Rincian Pemesanan</h3>
                            <button v-if="canEditRincian(trx) && !editingRincian[trx.id]" @click="openEditRincian(trx)"
                                class="text-xs text-violet-400 hover:text-violet-300 transition-colors">{{ trx.rincian_langsung ? '✏️ Edit' : '✏️ Usulkan Perubahan' }}</button>
                        </div>
                        <p v-if="trx.has_pending_rincian" class="text-amber-400 text-xs bg-amber-500/10 border border-amber-500/20 rounded-lg px-3 py-2">
                            ⏳ Usulan perubahan rincian menunggu persetujuan SPV/Leader di menu Pengajuan. Rincian baru berlaku setelah disetujui.
                        </p>
                        <div v-if="!editingRincian[trx.id]" class="bg-slate-800/40 rounded-lg p-3 space-y-1.5 text-sm">
                            <div class="flex justify-between text-slate-400">
                                <span>Tanggal Booking</span>
                                <span class="text-slate-300">{{ trx.tanggal_booking }}</span>
                            </div>
                            <div class="flex justify-between text-slate-400">
                                <span>Harga Dasar</span>
                                <span class="text-slate-300">{{ formatRp(trx.harga_dasar) }}</span>
                            </div>
                            <div v-if="trx.biaya_kelebihan_tanah_aktif" class="flex justify-between text-slate-400">
                                <span>+ Biaya Kelebihan Tanah</span>
                                <span class="text-slate-300">{{ formatRp(trx.biaya_kelebihan_tanah_nominal) }}</span>
                            </div>
                            <div v-if="trx.biaya_kelebihan_tanah_aktif && Number(trx.biaya_kelebihan_tanah_potongan) > 0" class="flex justify-between text-slate-400 pl-3">
                                <span>− Potongan promo{{ trx.promo_nama ? ` (${trx.promo_nama})` : '' }}</span>
                                <span class="text-emerald-300">{{ formatRp(trx.biaya_kelebihan_tanah_potongan) }}</span>
                            </div>
                            <template v-for="(bt, i) in trx.biaya_tambahan" :key="`bt-${i}`">
                                <div class="flex justify-between text-slate-400">
                                    <span>+ {{ bt.nama }}</span>
                                    <span class="text-slate-300">{{ formatRp(bt.nominal) }}</span>
                                </div>
                                <div v-if="Number(bt.potongan) > 0" class="flex justify-between text-slate-400 pl-3">
                                    <span>− Potongan promo{{ trx.promo_nama ? ` (${trx.promo_nama})` : '' }}</span>
                                    <span class="text-emerald-300">{{ formatRp(bt.potongan) }}</span>
                                </div>
                            </template>
                            <div v-if="trx.diskon_nominal > 0" class="flex justify-between text-slate-400">
                                <span>− Diskon{{ trx.promo_nama ? ` (${trx.promo_nama})` : '' }}{{ trx.diskon_mode === 'persen' ? ` ${trx.diskon_nilai}%` : '' }}</span>
                                <span class="text-rose-400">{{ formatRp(trx.diskon_nominal) }}</span>
                            </div>
                            <div class="flex justify-between pt-1.5 border-t border-slate-700 font-semibold">
                                <span class="text-violet-300">Total Harga</span>
                                <span class="text-violet-300">{{ formatRp(trx.harga_deal) }}</span>
                            </div>

                            <div class="pt-2 mt-1 border-t border-slate-700/60">
                                <div class="text-slate-500 text-[0.6875rem] uppercase tracking-wide mb-1">
                                    Cara Pembayaran: {{ trx.cara_bayar_label }}
                                    <span v-if="trx.skema_dp_preset" class="text-slate-500 normal-case">· Skema: {{ trx.skema_dp_preset.nama }}</span>
                                    <span v-if="trx.program_all_in_nama" class="text-slate-500 normal-case">· Program All In: {{ trx.program_all_in_nama }}</span>
                                </div>
                                <div v-if="trx.booking_fee > 0" class="flex justify-between text-slate-400">
                                    <span>
                                        Booking Fee
                                        <span v-if="trx.skema_dp_preset?.booking_fee_aktif" class="text-slate-500 text-xs ml-1">
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
                                        <span v-if="trx.skema_dp_preset?.dp_aktif" class="text-slate-500 text-xs ml-1">
                                            ({{ trx.skema_dp_preset.dp_tipe === 'persen' ? trx.skema_dp_preset.dp_nilai + '%' : 'nominal tetap' }},
                                            {{ trx.skema_dp_preset.dp_tenor }}x,
                                            {{ trx.skema_dp_preset.dp_masuk_harga_jual ? 'masuk harga jual' : 'di luar harga jual' }})
                                        </span>
                                    </span>
                                    <span class="text-slate-300">{{ formatRp(trx.dp_nominal) }}</span>
                                </div>
                                <div v-if="trx.titipan_biaya_akad_nominal > 0" class="flex justify-between text-slate-400">
                                    <span>Titipan Biaya Akad<span class="text-slate-500 text-xs ml-1">(dari Program All In)</span></span>
                                    <span class="text-slate-300">{{ formatRp(trx.titipan_biaya_akad_nominal) }}</span>
                                </div>
                                <div v-if="!(trx.booking_fee > 0) && !(trx.dp_nominal > 0) && !(trx.titipan_biaya_akad_nominal > 0)" class="text-slate-500 text-xs">Tidak ada booking fee maupun DP.</div>
                            </div>
                        </div>

                        <!-- Edit Rincian Pesanan — cuma komponen yang belum ada
                             pembayaran (Biaya Kelebihan Tanah/Biaya Tambahan/Diskon)
                             yang bisa diubah. Cara Bayar & Skema DP terkunci total. -->
                        <div v-else class="bg-slate-800/40 rounded-lg p-4 space-y-4 text-sm border border-violet-500/30">
                            <p v-if="!trx.rincian_langsung" class="text-sky-300 text-xs bg-sky-500/10 border border-sky-500/20 rounded-lg px-3 py-2">
                                Perubahan dikirim sebagai <b>usulan</b> dan baru berlaku setelah disetujui SPV/Leader.
                            </p>
                            <div>
                                <div class="text-slate-300 font-medium mb-1.5">Tanggal Booking</div>
                                <input v-model="getRincianForm(trx).tanggal_booking" type="date" :max="new Date().toISOString().slice(0, 10)"
                                    class="px-2 py-1.5 bg-slate-900 border border-slate-700 rounded text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                <p class="text-slate-500 text-[0.6875rem] mt-1">Mengubah tanggal juga menggeser UTJ bawaan booking dan jatuh tempo cicilan yang belum dibayar. Cicilan yang sudah dibayar tetap terkunci.</p>
                            </div>
                            <label v-if="trx.harga_unit_sekarang != null && Number(trx.harga_unit_sekarang) !== Number(trx.harga_dasar)" class="flex items-start gap-2 text-xs text-slate-300 cursor-pointer">
                                <input type="checkbox" v-model="getRincianForm(trx).harga_dasar_ke_unit" class="mt-0.5 rounded border-slate-600 bg-slate-800 text-violet-500" />
                                <span>Sesuaikan harga dasar ke harga unit saat ini ({{ formatRp(trx.harga_dasar) }} → <b>{{ formatRp(trx.harga_unit_sekarang) }}</b>)</span>
                            </label>
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-slate-300 font-medium">Biaya Kelebihan Tanah</span>
                                    <button v-if="!biayaKelebihanLocked(trx)" type="button"
                                        @click="getRincianForm(trx).biaya_kelebihan_tanah_aktif = !getRincianForm(trx).biaya_kelebihan_tanah_aktif"
                                        :class="getRincianForm(trx).biaya_kelebihan_tanah_aktif ? 'bg-violet-600' : 'bg-slate-700'"
                                        class="relative w-9 h-5 rounded-full transition-colors flex-shrink-0">
                                        <span :class="getRincianForm(trx).biaya_kelebihan_tanah_aktif ? 'translate-x-4' : 'translate-x-0.5'"
                                            class="absolute top-0.5 w-4 h-4 bg-white rounded-full transition-transform" />
                                    </button>
                                    <span v-else class="text-amber-400 text-xs">🔒 Sudah ada pembayaran</span>
                                </div>
                                <div v-if="biayaKelebihanLocked(trx)" class="text-slate-500 text-xs">
                                    {{ formatRp(trx.biaya_kelebihan_tanah_nominal) }} (terkunci, sudah ada pembayaran)
                                </div>
                                <div v-else-if="getRincianForm(trx).biaya_kelebihan_tanah_aktif" class="space-y-2">
                                    <div class="flex gap-3">
                                        <label class="flex items-center gap-1.5 text-xs text-slate-400 cursor-pointer">
                                            <input type="radio" v-model="getRincianForm(trx).biaya_kelebihan_tanah_mode" value="per_m2" class="accent-violet-500" /> Per m²
                                        </label>
                                        <label class="flex items-center gap-1.5 text-xs text-slate-400 cursor-pointer">
                                            <input type="radio" v-model="getRincianForm(trx).biaya_kelebihan_tanah_mode" value="nominal" class="accent-violet-500" /> Nominal
                                        </label>
                                    </div>
                                    <div v-if="getRincianForm(trx).biaya_kelebihan_tanah_mode === 'per_m2'" class="grid grid-cols-2 gap-2">
                                        <input v-model="getRincianForm(trx).biaya_kelebihan_tanah_luas" type="number" min="0" step="0.01" placeholder="Luas (m²)"
                                            class="px-2 py-1.5 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                        <MoneyInput v-model="getRincianForm(trx).biaya_kelebihan_tanah_harga_per_m2" placeholder="Harga/m²"
                                            class="px-2 py-1.5 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                    </div>
                                    <MoneyInput v-else v-model="getRincianForm(trx).biaya_kelebihan_tanah_nominal_input" placeholder="Nominal"
                                        class="w-full px-2 py-1.5 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                </div>
                            </div>

                            <div>
                                <div class="text-slate-300 font-medium mb-2">Biaya Tambahan</div>
                                <div v-for="(bt, idx) in getRincianForm(trx).biaya_tambahan" :key="idx" class="flex items-center gap-2 mb-1.5">
                                    <template v-if="bt.locked">
                                        <span class="flex-1 text-slate-500 text-xs">🔒 {{ bt.nama }}</span>
                                        <span class="text-slate-500 text-xs">{{ formatRp(bt.nominal) }} (terkunci)</span>
                                    </template>
                                    <template v-else>
                                        <select v-model="bt.preset_id" class="flex-1 px-2 py-1.5 bg-slate-900 border border-slate-700 rounded text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                                            <option value="" disabled>Pilih item...</option>
                                            <option v-for="p in biayaTambahanPresets" :key="p.id" :value="p.id">{{ p.nama }}</option>
                                        </select>
                                        <MoneyInput v-model="bt.nominal" placeholder="Nominal"
                                            class="w-28 px-2 py-1.5 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                        <button type="button" @click="removeRincianBiayaTambahan(trx, idx)"
                                            class="text-rose-400 hover:bg-rose-500/10 rounded p-1.5 flex-shrink-0" aria-label="Hapus">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </template>
                                </div>
                                <button type="button" @click="addRincianBiayaTambahan(trx)" class="text-violet-400 hover:text-violet-300 text-xs">+ Tambah Biaya Tambahan</button>
                            </div>

                            <div>
                                <div class="text-slate-300 font-medium mb-2">Promo</div>
                                <select v-model="getRincianForm(trx).promo_preset_id" class="w-full px-2 py-1.5 bg-slate-900 border border-slate-700 rounded text-slate-300 text-xs mb-2 focus:outline-none focus:ring-1 focus:ring-violet-500">
                                    <option value="">Tanpa promo</option>
                                    <option v-for="p in promoPresets" :key="p.id" :value="p.id">{{ p.nama }}</option>
                                </select>
                                <!-- Potongan promo per lawan: Harga Jual (diskon umum), Kelebihan Tanah, Biaya Tambahan -->
                                <div v-if="promoForm(getRincianForm(trx))" class="space-y-2 mt-2">
                                    <PotonganPromoInput v-if="promoForm(getRincianForm(trx)).lawan_harga_jual" label="Harga Jual (diskon)"
                                        :bruto="subtotalHargaJualForm(trx, getRincianForm(trx))"
                                        :model-value="{ mode: getRincianForm(trx).diskon_mode || 'persen', nilai: getRincianForm(trx).diskon_nilai }"
                                        @update:model-value="v => { getRincianForm(trx).diskon_mode = v.mode; getRincianForm(trx).diskon_nilai = v.nilai; }" />
                                    <p v-if="trx.is_kpr && promoForm(getRincianForm(trx)).lawan_harga_jual" class="text-slate-500 text-[0.6875rem]">Potongan pada Harga Jual hanya mengurangi harga jual; tagihan KPR tidak berubah. Untuk memotong biaya tertentu, pakai lawan Kelebihan Tanah / Biaya Tambahan.</p>
                                    <PotonganPromoInput v-if="lawanTanahForm(getRincianForm(trx), biayaKelebihanLocked(trx))" label="Kelebihan Tanah"
                                        :bruto="brutoTanahForm(getRincianForm(trx))" v-model="getRincianForm(trx).potongan_tanah" />
                                    <template v-for="(bt, idx) in getRincianForm(trx).biaya_tambahan" :key="`pot-${idx}`">
                                        <PotonganPromoInput v-if="lawanBtForm(getRincianForm(trx), bt)" :label="biayaTambahanPresets.find(p => p.id === Number(bt.preset_id))?.nama ?? bt.nama"
                                            :bruto="Number(bt.nominal) || 0" v-model="bt.potongan_input" />
                                    </template>
                                    <p v-if="biayaKelebihanLocked(trx) || getRincianForm(trx).biaya_tambahan.some(b => b.locked)" class="text-slate-500 text-[0.6875rem]">Biaya yang sudah ada pembayarannya terkunci, termasuk potongan promonya.</p>
                                </div>
                            </div>

                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-slate-300 font-medium">Program All In</span>
                                    <span v-if="titipanBiayaAkadLocked(trx)" class="text-amber-400 text-xs">🔒 Titipan Biaya Akad sudah ada pembayaran</span>
                                </div>
                                <div v-if="titipanBiayaAkadLocked(trx)" class="text-slate-500 text-xs">
                                    {{ formatRp(trx.titipan_biaya_akad_nominal) }} (terkunci, sudah ada pembayaran)
                                </div>
                                <select v-else v-model="getRincianForm(trx).program_all_in_preset_id"
                                    class="w-full px-2 py-1.5 bg-slate-900 border border-slate-700 rounded text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                                    <option value="">Tidak ikut Program All In</option>
                                    <option v-for="p in programAllInPresets" :key="p.id" :value="p.id">{{ p.nama }} — {{ formatRp(p.nominal) }}</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-slate-400 text-xs mb-1">Alasan perubahan
                                    <span class="text-slate-600">{{ trx.rincian_langsung ? '(wajib bila mengubah tanggal booking atau harga dasar)' : '(wajib, min. 5 karakter)' }}</span></label>
                                <textarea v-model="getRincianForm(trx).alasan" rows="2" placeholder="Mis. konsumen dijanjikan free kanopi / salah ketik tanggal"
                                    class="w-full px-2 py-1.5 bg-slate-900 border border-slate-700 rounded text-slate-300 text-xs resize-none focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            </div>

                            <div class="space-y-2">
                                <button type="button" @click="periksaRincian(trx)" :disabled="getRincianForm(trx).memuat"
                                    class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 disabled:opacity-50 text-slate-200 text-xs rounded-lg">{{ getRincianForm(trx).memuat ? 'Memeriksa…' : '🔍 Periksa perubahan' }}</button>
                                <template v-if="getRincianForm(trx).pratinjau">
                                    <p v-if="getRincianForm(trx).pratinjau.konflik" class="text-rose-400 text-xs">{{ getRincianForm(trx).pratinjau.konflik }}</p>
                                    <p v-else-if="!getRincianForm(trx).pratinjau.baris.length" class="text-slate-400 text-xs">Tidak ada perubahan dibanding rincian sekarang.</p>
                                    <div v-else class="text-xs bg-slate-900 rounded-lg divide-y divide-slate-800">
                                        <div v-for="b in getRincianForm(trx).pratinjau.baris" :key="b.label" class="flex flex-wrap gap-x-3 px-3 py-1.5">
                                            <span class="w-40 text-slate-500">{{ b.label }}</span>
                                            <span class="text-slate-400 line-through">{{ b.lama }}</span>
                                            <span class="text-emerald-400">→ {{ b.baru }}</span>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <p v-if="Object.keys(getRincianForm(trx).errors).length" class="text-rose-400 text-xs space-y-0.5">
                                <span v-for="(msg, field) in getRincianForm(trx).errors" :key="field" class="block">{{ msg }}</span>
                            </p>
                            <p class="text-slate-500 text-[0.6875rem]">Cara Bayar &amp; Skema DP tidak bisa diubah di sini — kalau memang perlu ganti, transaksi harus dibatalkan &amp; booking ulang. Booking Fee/DP yang sudah digenerate juga tidak ikut berubah.</p>

                            <div class="flex justify-end gap-2 pt-1">
                                <button type="button" @click="cancelEditRincian(trx.id)" class="px-3 py-1.5 text-slate-400 hover:text-slate-200 text-xs">Batal</button>
                                <button type="button" @click="submitRincianPesanan(trx)" :disabled="getRincianForm(trx).processing"
                                    class="px-4 py-1.5 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-xs font-medium rounded-lg transition-colors">
                                    {{ getRincianForm(trx).processing ? 'Menyimpan...' : (trx.rincian_langsung ? 'Simpan' : 'Kirim Usulan') }}
                                </button>
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
                                    :class="dok.tidak_berlaku && !dok.menunggu_data ? 'opacity-50' : ''"
                                    class="flex items-center justify-between px-3 py-2 bg-slate-800/60 rounded-lg">
                                    <div class="flex items-center gap-2">
                                        <span class="text-base">{{ dok.status_icon }}</span>
                                        <span class="text-slate-300 text-sm">{{ dok.nama_dokumen }}</span>
                                        <span v-if="dok.menunggu_data" class="text-amber-300 text-xs" :title="`Isi ${dok.menunggu_data} konsumen (Edit Konsumen) supaya berkas ini bisa dinilai wajib atau tidak`">(Menunggu data {{ dok.menunggu_data }})</span>
                                        <span v-else-if="dok.tidak_berlaku" class="text-slate-500 text-xs">(Tidak berlaku)</span>
                                        <span v-else-if="dok.sifat === 'kondisional'" class="text-slate-500 text-xs">(Wajib{{ dok.syarat_label ? ' · ' + dok.syarat_label : '' }})</span>
                                        <span v-else-if="dok.sifat !== 'wajib'" class="text-slate-500 text-xs">({{ dok.sifat_label }})</span>
                                    </div>
                                    <span class="text-xs" :class="dok.status === 'sudah_ada' ? 'text-emerald-400' : ['perlu_revisi','ditolak'].includes(dok.status) ? 'text-amber-400' : 'text-slate-500'">
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
                        <div v-else class="text-slate-500 text-xs px-1">Belum ada template dokumen untuk cara bayar ini.</div>
                    </div>

                    <!-- ═══ Cetak Dokumen (generate dari Template Surat) ═══ -->
                    <div v-if="suratTemplates.length" class="space-y-2">
                        <h3 class="text-slate-300 text-sm font-semibold flex items-center gap-1.5">📄 Cetak Dokumen</h3>
                        <form :action="route('surat.generate', [trx.id, selectedTemplateId(trx.id)])" method="GET" target="_blank"
                            class="rounded-lg border border-slate-800 p-3 flex flex-wrap items-end gap-2">
                            <div class="min-w-[160px]">
                                <label class="block text-slate-500 text-xs mb-1">Template</label>
                                <select :value="selectedTemplateId(trx.id)" @change="setCetakTemplateId(trx.id, $event.target.value)"
                                    class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                                    <option v-for="tmpl in suratTemplates" :key="tmpl.id" :value="tmpl.id">{{ tmpl.nama }}</option>
                                </select>
                            </div>
                            <div class="flex-1 min-w-[180px]">
                                <label class="block text-slate-500 text-xs mb-1">Nomor Surat</label>
                                <input name="nomor_surat" type="text" placeholder="mis. 124/GP3/SPPR/VIII/2026"
                                    class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            </div>
                            <button type="submit"
                                class="px-3 py-1.5 bg-violet-600 hover:bg-violet-500 text-white text-xs font-medium rounded-lg transition-colors whitespace-nowrap">
                                Unduh .docx
                            </button>
                        </form>
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
                                <div class="px-3 py-1.5 bg-slate-800/80 text-slate-500 text-[0.6875rem] font-semibold uppercase tracking-wide">
                                    Nama Biaya / Subjek / Nominal / Status
                                </div>

                                <template v-for="(row, i) in trx.kartu_piutang_static" :key="`static-${i}`">
                                    <div
                                        class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-t border-slate-800/60 text-sm"
                                        :class="isGroupRow(row) ? 'cursor-pointer hover:bg-slate-800/40 transition-colors' : ''"
                                        @click="isGroupRow(row) && toggleRowExpand(trx.id, row)">
                                        <div class="min-w-[160px] flex items-center gap-1.5 flex-1">
                                            <svg v-if="isGroupRow(row)" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                                                :class="['w-3 h-3 text-slate-500 transition-transform flex-shrink-0', isRowExpanded(trx.id, row) ? 'rotate-90' : '']">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                            </svg>
                                            <span class="text-slate-300">{{ row.nama }}</span>
                                            <span class="text-slate-500 text-xs ml-2">{{ row.subjek }}</span>
                                            <span v-if="row.progress" class="text-slate-500 text-xs ml-2">({{ row.progress }})</span>
                                            <span v-if="Number(row.potongan) > 0" class="text-emerald-300 text-xs ml-2">biaya {{ formatRp(row.bruto) }} − potongan promo {{ formatRp(row.potongan) }}</span>
                                        </div>
                                        <div class="flex items-center gap-3 flex-shrink-0">
                                            <span class="text-slate-200 font-medium min-w-[110px] text-right">{{ nominalLabel(row.nominal, row.jumlah_dibayar, row.status) }}</span>
                                            <span class="w-20 flex justify-center"><StatusPembayaranBadge :status="row.status" /></span>
                                        </div>
                                    </div>

                                    <div v-if="isGroupRow(row) && isRowExpanded(trx.id, row)" class="bg-slate-950/40 border-t border-slate-800/60">
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
                                                <span class="text-slate-500 text-xs ml-2">jatuh tempo {{ j.tanggal_jatuh_tempo }}</span>
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

                                        <!-- Cicilan Biaya Tanah/Tambahan UM/Titipan Biaya Akad/Biaya
                                             Tambahan — halaman ini read-only, cuma tampilkan riwayat,
                                             tidak ada aksi. -->
                                        <div v-if="cicilanLainGroupTypes.includes(row.type)">
                                            <div v-for="c in row.cicilan" :key="c.id"
                                                class="flex flex-wrap items-center justify-between gap-2 pl-8 pr-3 py-2 text-sm border-b border-slate-800/40 last:border-b-0">
                                                <div class="flex-1">
                                                    <span class="text-slate-300 font-medium">{{ formatRp(c.jumlah) }}</span>
                                                    <span class="text-slate-500 text-xs ml-2">dibayar {{ c.tanggal_bayar }}</span>
                                                    <span v-if="c.keterangan" class="text-slate-500 text-xs ml-2">· {{ c.keterangan }}</span>
                                                </div>
                                            </div>
                                            <div v-if="!row.cicilan?.length" class="pl-8 pr-3 py-2 text-slate-500 text-xs">Belum ada cicilan tercatat.</div>
                                        </div>
                                    </div>
                                </template>

                                <div v-if="!trx.kartu_piutang_static?.length" class="px-3 py-4 text-center text-slate-500 text-xs">
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
                                    <div class="px-3 py-1.5 bg-slate-800/80 text-slate-500 text-[0.6875rem] font-semibold uppercase tracking-wide">SBUM</div>
                                    <div v-for="item in sbumItems(trx)" :key="`sbum-${item.id}`"
                                        class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-t border-slate-800/60 text-sm group">
                                        <div>
                                            <span class="text-slate-300">{{ item.nama }}</span>
                                            <span class="text-slate-500 text-xs ml-2">Pemerintah/Bank</span>
                                        </div>
                                        <div class="flex items-center gap-1.5">
                                            <template v-if="item.status !== 'lunas' && canEditBiayaAkad(trx)">
                                                <MoneyInput :model-value="item.nominal"
                                                    @commit="updateBiayaAkadNominal(item, $event)"
                                                    class="w-24 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-right text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                                <button @click="removeBiayaAkad(item)"
                                                    class="opacity-0 group-hover:opacity-100 text-rose-400 hover:bg-rose-500/10 rounded p-1 transition-all flex-shrink-0" aria-label="Hapus">
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
                                        <MoneyInput v-model="getBiayaAkadForm(trx.id, 'sbum').nominal" placeholder="Nominal"
                                            @keyup.enter="addBiayaAkad(trx, 'sbum')"
                                            class="w-28 px-2 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                        <button @click="addBiayaAkad(trx, 'sbum')"
                                            :disabled="!getBiayaAkadForm(trx.id, 'sbum').dajam_sbum_preset_id || !getBiayaAkadForm(trx.id, 'sbum').nominal"
                                            title="Pilih item dan isi nominal dulu"
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
                                <div class="px-3 py-1.5 bg-slate-800/80 text-slate-500 text-[0.6875rem] font-semibold uppercase tracking-wide">Dana Jaminan</div>
                                <div v-for="item in dajamItems(trx)" :key="`dajam-${item.id}`"
                                    class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-t border-slate-800/60 text-sm group">
                                    <div>
                                        <span class="text-slate-300">{{ item.nama }}</span>
                                        <span class="text-slate-500 text-xs ml-2">Bank</span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <template v-if="item.status !== 'lunas' && canEditBiayaAkad(trx)">
                                            <MoneyInput :model-value="item.nominal"
                                                @commit="updateBiayaAkadNominal(item, $event)"
                                                class="w-24 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-right text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                            <button @click="removeBiayaAkad(item)"
                                                class="opacity-0 group-hover:opacity-100 text-rose-400 hover:bg-rose-500/10 rounded p-1 transition-all flex-shrink-0" aria-label="Hapus">
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
                                    <MoneyInput v-model="getBiayaAkadForm(trx.id, 'dajam').nominal" placeholder="Nominal"
                                        @keyup.enter="addBiayaAkad(trx, 'dajam')"
                                        class="w-28 px-2 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                    <button @click="addBiayaAkad(trx, 'dajam')"
                                        :disabled="!getBiayaAkadForm(trx.id, 'dajam').dajam_sbum_preset_id || !getBiayaAkadForm(trx.id, 'dajam').nominal"
                                            title="Pilih item dan isi nominal dulu"
                                        class="px-2.5 py-1.5 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-xs font-medium rounded-lg transition-colors whitespace-nowrap">
                                        + Tambah
                                    </button>
                                </div>

                                <!-- 4. (-) Turun Plafon — aksinya di baris Tambahan Uang Muka Kartu Piutang -->
                                <div v-if="tambahanUmRow(trx)" class="flex items-center justify-between px-3 py-2.5 border-t border-slate-800/60">
                                    <span class="text-slate-400">(−) Turun Plafon <span class="text-slate-500 text-xs">— lihat baris Tambahan Uang Muka di Kartu Piutang</span></span>
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
                                        <span class="text-slate-500 text-xs ml-2">cair {{ t.tanggal_cair }}</span>
                                        <span v-if="t.keterangan" class="text-slate-500 text-xs ml-2">· {{ t.keterangan }}</span>
                                    </div>
                                    <div v-if="!trx.pencairan_kpr_tahaps?.length" class="pl-8 pr-3 py-2 text-slate-500 text-xs">Belum ada pencairan tercatat.</div>
                                </div>
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
                                        <div class="text-slate-500 text-xs">{{ p.tanggal_bayar }}</div>
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

        <LengkapiRincianModal v-if="lengkapiTrx" :url="route('konsumens.lengkapi-rincian-satu', lengkapiTrx.id)"
            @tutup="lengkapiTrx = null" @selesai="lengkapiTrx = null" />

        <AlihPembeliModal v-if="alihTrx" :kk-id="alihTrx.id" :pembeli-lama="konsumen.nama" :unit="alihTrx.kavling_nomor"
            @tutup="alihTrx = null" @selesai="alihTrx = null" />

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

<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    pembayaran: Object,
    transaksi:  Object,
    konsumen:   Object,
    kavling:    Object,
    developer:  Object,
});

const formatRp = (v) => v
    ? 'Rp ' + Number(v).toLocaleString('id-ID')
    : '-';

const terbilang = (num) => {
    // Simplified terbilang implementation
    const satuan = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan'];
    const belasan = ['sepuluh', 'sebelas', 'dua belas', 'tiga belas', 'empat belas', 'lima belas', 'enam belas', 'tujuh belas', 'delapan belas', 'sembilan belas'];

    const convert = (n) => {
        if (n === 0) return '';
        if (n < 10) return satuan[n];
        if (n < 20) return belasan[n - 10];
        if (n < 100) return satuan[Math.floor(n / 10)] + ' puluh' + (n % 10 ? ' ' + satuan[n % 10] : '');
        if (n < 1000) return (n >= 100 && Math.floor(n / 100) === 1 ? 'seratus' : satuan[Math.floor(n / 100)] + ' ratus') + (n % 100 ? ' ' + convert(n % 100) : '');
        if (n < 1000000) return (n >= 1000 && Math.floor(n / 1000) === 1 ? 'seribu' : convert(Math.floor(n / 1000)) + ' ribu') + (n % 1000 ? ' ' + convert(n % 1000) : '');
        if (n < 1000000000) return convert(Math.floor(n / 1000000)) + ' juta' + (n % 1000000 ? ' ' + convert(n % 1000000) : '');
        return convert(Math.floor(n / 1000000000)) + ' miliar' + (n % 1000000000 ? ' ' + convert(n % 1000000000) : '');
    };

    const n = Math.floor(Number(num));
    return (convert(n) || 'nol') + ' rupiah';
};

const printPage = () => window.print();
</script>

<template>
    <Head :title="`Kuitansi – ${konsumen.nama}`" />

    <div class="min-h-screen bg-slate-950 p-6 print:bg-white print:p-0">
        <!-- Action Bar (hidden when printing) -->
        <div class="print:hidden max-w-2xl mx-auto mb-4 flex items-center gap-3">
            <Link :href="route('keuangan.index')" class="text-slate-400 hover:text-slate-200 text-sm transition-colors flex items-center gap-1">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
                Kembali
            </Link>
            <div class="flex-1" />
            <button @click="printPage"
                class="flex items-center gap-2 px-4 py-2 bg-violet-600 hover:bg-violet-500 text-white text-sm font-medium rounded-lg transition-colors shadow-lg shadow-violet-500/20">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5zm-3 0h.008v.008H15V10.5z" />
                </svg>
                Cetak / Simpan PDF
            </button>
        </div>

        <!-- Kuitansi Document -->
        <div class="max-w-2xl mx-auto bg-white text-slate-900 rounded-xl shadow-2xl overflow-hidden print:shadow-none print:rounded-none print:max-w-full">
            <!-- Kop Surat -->
            <div class="border-b-2 border-slate-200">
                <div v-if="developer.kop_surat" class="w-full">
                    <img :src="developer.kop_surat" class="w-full object-contain max-h-24" alt="Kop Surat" />
                </div>
                <div v-else class="px-8 py-5 flex items-center gap-4">
                    <img v-if="developer.logo_path" :src="developer.logo_path" class="h-14 object-contain" alt="Logo" />
                    <div>
                        <div class="font-bold text-xl text-slate-900">{{ developer.nama }}</div>
                        <div class="text-slate-600 text-sm mt-0.5">{{ developer.alamat }}</div>
                        <div class="text-slate-600 text-sm">{{ developer.telepon }}</div>
                    </div>
                </div>
            </div>

            <!-- Title -->
            <div class="text-center py-6 border-b border-slate-100">
                <h1 class="text-2xl font-bold text-slate-900 tracking-wide uppercase">Kuitansi</h1>
                <div class="text-slate-500 text-sm mt-1">No. KWT-{{ String(pembayaran.id).padStart(6, '0') }}</div>
            </div>

            <!-- Content -->
            <div class="px-8 py-6 space-y-4">
                <table class="w-full text-sm">
                    <tbody>
                        <tr class="border-b border-slate-100">
                            <td class="py-2.5 text-slate-500 w-40">Telah diterima dari</td>
                            <td class="py-2.5 text-slate-500 w-4">:</td>
                            <td class="py-2.5 text-slate-900 font-semibold">{{ konsumen.nama }}</td>
                        </tr>
                        <tr class="border-b border-slate-100">
                            <td class="py-2.5 text-slate-500">Unit</td>
                            <td class="py-2.5 text-slate-500">:</td>
                            <td class="py-2.5 text-slate-900">{{ kavling.nomor_lengkap }} – {{ kavling.project_nama }}</td>
                        </tr>
                        <tr class="border-b border-slate-100">
                            <td class="py-2.5 text-slate-500">Jenis Pembayaran</td>
                            <td class="py-2.5 text-slate-500">:</td>
                            <td class="py-2.5 text-slate-900 font-medium">{{ pembayaran.jenis_label }}</td>
                        </tr>
                        <tr class="border-b border-slate-100">
                            <td class="py-2.5 text-slate-500">Tanggal</td>
                            <td class="py-2.5 text-slate-500">:</td>
                            <td class="py-2.5 text-slate-900">{{ pembayaran.tanggal_bayar }}</td>
                        </tr>
                        <tr v-if="pembayaran.keterangan" class="border-b border-slate-100">
                            <td class="py-2.5 text-slate-500">Keterangan</td>
                            <td class="py-2.5 text-slate-500">:</td>
                            <td class="py-2.5 text-slate-700">{{ pembayaran.keterangan }}</td>
                        </tr>
                    </tbody>
                </table>

                <!-- Amount Box -->
                <div class="bg-slate-50 rounded-xl p-5 text-center border border-slate-200 mt-4">
                    <div class="text-slate-500 text-sm mb-1">Jumlah Pembayaran</div>
                    <div class="text-3xl font-bold text-violet-700">{{ formatRp(pembayaran.jumlah) }}</div>
                    <div class="text-slate-500 text-sm mt-1.5 capitalize italic">
                        Terbilang: {{ terbilang(pembayaran.jumlah) }}
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="px-8 pb-8">
                <div class="flex justify-end">
                    <div class="text-center">
                        <div class="text-slate-500 text-sm">{{ developer.nama }}</div>
                        <div class="mt-16 mb-1 border-b border-slate-400 w-40"></div>
                        <div class="text-slate-700 text-sm font-medium">{{ pembayaran.created_by ?? 'Admin' }}</div>
                        <div class="text-slate-500 text-xs">Penerima</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
@media print {
    @page {
        size: A5 landscape;
        margin: 1cm;
    }
    body { background: white !important; }
    .print\:hidden { display: none !important; }
}
</style>

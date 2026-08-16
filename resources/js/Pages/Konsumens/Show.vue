<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    konsumen: Object,
    transaksis: Array,
});

const formatRp = (v) => v
    ? 'Rp ' + Number(v).toLocaleString('id-ID')
    : '-';

const statusPenjualanConfig = {
    booking:      { label: 'Booking',       cls: 'bg-blue-500/15 text-blue-400 ring-1 ring-blue-500/30' },
    pemberkasan:  { label: 'Pemberkasan',   cls: 'bg-yellow-500/15 text-yellow-400 ring-1 ring-yellow-500/30' },
    sp3k:         { label: 'SP3K',          cls: 'bg-indigo-500/15 text-indigo-400 ring-1 ring-indigo-500/30' },
    rencana_akad: { label: 'Rencana Akad',  cls: 'bg-violet-500/15 text-violet-400 ring-1 ring-violet-500/30' },
    akad:         { label: 'Akad',          cls: 'bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/30' },
    batal:        { label: 'Batal',         cls: 'bg-rose-500/15 text-rose-400 ring-1 ring-rose-500/30' },
};

const activeTransaksi = ref(null);
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
            <div v-for="(trx, idx) in transaksis" :key="trx.id" class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
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
                <div v-show="activeTransaksi === idx" class="px-5 py-4 space-y-5">
                    <!-- Progress Berkas KPR -->
                    <div v-if="['kpr_subsidi','kpr_komersil'].includes(trx.cara_bayar) && trx.dokumens?.length" class="space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-slate-300 text-sm font-medium">Kelengkapan Berkas</span>
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
                    </div>

                    <!-- Checklist Dokumen -->
                    <div v-if="trx.dokumens?.length" class="space-y-2">
                        <h4 class="text-slate-400 text-xs font-semibold uppercase tracking-wider">Checklist Pemberkasan</h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
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
                        <div class="flex justify-end mt-2">
                            <Link :href="route('dokumen.index', trx.id)" class="text-xs text-violet-400 hover:text-violet-300 transition-colors flex items-center gap-1">
                                <span>Kelola Dokumen</span>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                            </Link>
                        </div>
                    </div>

                    <!-- Pembayaran -->
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

                    <div v-if="!trx.dokumens?.length && !trx.pembayarans?.length" class="text-center py-4 text-slate-600 text-sm">
                        Belum ada data dokumen atau pembayaran
                    </div>
                </div>
            </div>

            <div v-if="!transaksis.length" class="text-center py-12 text-slate-500 text-sm">
                Konsumen belum memiliki transaksi
            </div>
        </div>
    </AuthenticatedLayout>
</template>

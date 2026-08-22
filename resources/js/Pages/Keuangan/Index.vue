<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    rows:          Array,
    filterOptions: Object,
    filters:       Object,
});

const kluster    = ref(props.filters?.kluster ?? '');
const blok       = ref(props.filters?.blok ?? '');
const statusPenjualan = ref(props.filters?.status_penjualan ?? '');
const caraBayar  = ref(props.filters?.cara_bayar ?? '');
const bank       = ref(props.filters?.bank ?? '');

const applyFilter = () => {
    router.get(route('keuangan.index'), {
        kluster: kluster.value || undefined,
        blok: blok.value || undefined,
        status_penjualan: statusPenjualan.value || undefined,
        cara_bayar: caraBayar.value || undefined,
        bank: bank.value || undefined,
    }, { preserveState: true, replace: true });
};

watch([kluster, blok, statusPenjualan, caraBayar, bank], applyFilter);

const resetFilters = () => {
    kluster.value = '';
    blok.value = '';
    statusPenjualan.value = '';
    caraBayar.value = '';
    bank.value = '';
};

const formatRp = (v) => v
    ? 'Rp ' + Number(v).toLocaleString('id-ID')
    : 'Rp 0';

const pct = (paid, total) => total > 0 ? Math.min(100, Math.round((Number(paid) / Number(total)) * 100)) : 0;

const statusPenjualanConfig = {
    booking:      { cls: 'bg-blue-500/15 text-blue-400 ring-1 ring-blue-500/30' },
    pemberkasan:  { cls: 'bg-amber-500/15 text-amber-400 ring-1 ring-amber-500/30' },
    proses_bank:  { cls: 'bg-orange-500/15 text-orange-400 ring-1 ring-orange-500/30' },
    sp3k:         { cls: 'bg-indigo-500/15 text-indigo-400 ring-1 ring-indigo-500/30' },
    rencana_akad: { cls: 'bg-violet-500/15 text-violet-400 ring-1 ring-violet-500/30' },
    akad:         { cls: 'bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/30' },
    bast:         { cls: 'bg-teal-500/15 text-teal-400 ring-1 ring-teal-500/30' },
    batal:        { cls: 'bg-rose-500/15 text-rose-400 ring-1 ring-rose-500/30' },
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
            <div>
                <h1 class="text-white font-bold text-xl">Monitoring Keuangan</h1>
                <p class="text-slate-400 text-sm mt-0.5">Progress pembayaran konsumen &amp; pencairan KPR/bank per unit</p>
            </div>

            <!-- Filter -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <div class="flex flex-wrap items-center gap-2">
                    <select v-model="kluster" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Semua Kluster</option>
                        <option v-for="k in filterOptions.kluster" :key="k" :value="k">{{ k }}</option>
                    </select>
                    <select v-model="blok" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Semua Blok</option>
                        <option v-for="b in filterOptions.blok" :key="b" :value="b">Blok {{ b }}</option>
                    </select>
                    <select v-model="statusPenjualan" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Semua Status</option>
                        <option v-for="(label, key) in filterOptions.status_penjualan" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <select v-model="caraBayar" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Semua Cara Bayar</option>
                        <option v-for="(label, key) in filterOptions.cara_bayar" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <select v-model="bank" class="px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Semua Bank</option>
                        <option v-for="b in filterOptions.bank" :key="b" :value="b">{{ b }}</option>
                    </select>
                    <button v-if="kluster || blok || statusPenjualan || caraBayar || bank" @click="resetFilters"
                        class="px-3 py-1.5 text-slate-500 hover:text-slate-300 text-xs transition-colors">
                        ✕ Reset filter
                    </button>
                </div>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium">Konsumen</th>
                                <th class="px-4 py-3 text-left font-medium">Unit</th>
                                <th class="px-4 py-3 text-left font-medium">Status</th>
                                <th class="px-4 py-3 text-right font-medium">Harga Deal</th>
                                <th class="px-4 py-3 text-left font-medium">Cara Bayar</th>
                                <th class="px-4 py-3 text-left font-medium">Bank</th>
                                <th class="px-4 py-3 text-left font-medium">Progress Pembayaran Konsumen</th>
                                <th class="px-4 py-3 text-left font-medium">Progress Pencairan Bank</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.id"
                                class="border-b border-slate-800/50 hover:bg-slate-800/30 transition-colors">
                                <td class="px-4 py-3 text-slate-200 font-medium">{{ row.konsumen_nama }}</td>
                                <td class="px-4 py-3 text-slate-400">
                                    {{ row.kavling_nomor }}
                                    <div class="text-slate-600 text-xs">{{ row.project_nama }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="['px-2 py-0.5 text-xs rounded-full font-medium', statusPenjualanConfig[row.status_penjualan]?.cls]">
                                        {{ row.status_penjualan_label }}
                                    </span>
                                    <div v-if="row.status === 'completed'" class="mt-1">
                                        <span class="px-2 py-0.5 text-[10px] rounded-full font-medium bg-emerald-500/15 text-emerald-400">✅ Selesai</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-slate-200 text-right font-medium">{{ formatRp(row.harga_deal) }}</td>
                                <td class="px-4 py-3 text-slate-400">{{ row.cara_bayar_label }}</td>
                                <td class="px-4 py-3 text-slate-400 text-xs">{{ row.bank_rekanan_kpr ?? '-' }}</td>
                                <td class="px-4 py-3">
                                    <div class="text-slate-300 text-xs font-medium whitespace-nowrap">
                                        {{ formatRp(row.total_terbayar_konsumen) }} / {{ formatRp(row.total_piutang_konsumen) }}
                                    </div>
                                    <div class="h-1.5 bg-slate-800 rounded-full overflow-hidden mt-1 w-32">
                                        <div class="h-full bg-gradient-to-r from-violet-500 to-indigo-500 rounded-full"
                                            :style="`width: ${pct(row.total_terbayar_konsumen, row.total_piutang_konsumen)}%`" />
                                    </div>
                                    <div class="text-slate-600 text-[10px] mt-0.5">{{ pct(row.total_terbayar_konsumen, row.total_piutang_konsumen) }}%</div>
                                </td>
                                <td class="px-4 py-3">
                                    <template v-if="row.is_kpr">
                                        <div class="text-slate-300 text-xs font-medium whitespace-nowrap">
                                            {{ formatRp(row.total_terbayar_bank) }} / {{ formatRp(row.total_piutang_bank) }}
                                        </div>
                                        <div class="h-1.5 bg-slate-800 rounded-full overflow-hidden mt-1 w-32">
                                            <div class="h-full bg-gradient-to-r from-emerald-500 to-teal-500 rounded-full"
                                                :style="`width: ${pct(row.total_terbayar_bank, row.total_piutang_bank)}%`" />
                                        </div>
                                        <div class="text-slate-600 text-[10px] mt-0.5">{{ pct(row.total_terbayar_bank, row.total_piutang_bank) }}%</div>
                                    </template>
                                    <span v-else class="text-slate-600 text-xs">-</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Link :href="route('keuangan.detail', row.id)" class="text-xs text-violet-400 hover:text-violet-300 transition-colors whitespace-nowrap">
                                        Lihat Detail →
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!rows.length">
                                <td colspan="9" class="px-4 py-12 text-center text-slate-600">Belum ada data transaksi.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

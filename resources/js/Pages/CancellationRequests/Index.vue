<script setup>
import Pagination from '@/Components/Pagination.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    requests: Object,
    filters: Object,
    statusOptions: Array,
    typeOptions: Array,
});

const statusFilter = ref(props.filters?.status ?? '');
const typeFilter = ref(props.filters?.type ?? '');
watch([statusFilter, typeFilter], ([status, type]) => {
    router.get(route('cancellation-requests.index'), { status: status || undefined, type: type || undefined }, { preserveState: true, replace: true });
});

// Modal state
const showReviewModal = ref(false);
const reviewType = ref(''); // 'approve' | 'reject'
const selectedRequest = ref(null);

const reviewForm = useForm({ catatan_reviewer: '', nominal_dikembalikan: '', ikuti_harga_baru: false });

const openReview = (request, type) => {
    selectedRequest.value = request;
    reviewType.value = type;
    reviewForm.reset();
    showReviewModal.value = true;
};

const formatRupiah = (v) => v ? 'Rp ' + Number(v).toLocaleString('id-ID') : 'Rp 0';

const submitReview = () => {
    const routeName = reviewType.value === 'approve'
        ? 'cancellation-requests.approve'
        : 'cancellation-requests.reject';

    reviewForm.patch(route(routeName, selectedRequest.value.id), {
        onSuccess: () => {
            showReviewModal.value = false;
        }
    });
};

const statusBadgeClass = {
    pending:  'bg-yellow-500/15 text-yellow-400 ring-1 ring-yellow-500/25',
    approved: 'bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/25',
    rejected: 'bg-rose-500/15 text-rose-400 ring-1 ring-rose-500/25',
};
</script>

<template>
    <Head title="Pengajuan" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <span class="text-slate-200 font-medium">Pengajuan</span>
            </div>
        </template>

        <div class="p-6 space-y-5">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-white font-bold text-xl">Pengajuan</h1>
                    <p class="text-slate-400 text-sm mt-0.5">Review dan kelola permintaan pembatalan &amp; tukar unit kavling</p>
                </div>
                <div class="flex items-center gap-2">
                    <select
                        v-model="typeFilter"
                        class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                    >
                        <option value="">Semua Tipe</option>
                        <option v-for="opt in typeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </select>
                    <select
                        v-model="statusFilter"
                        class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                    >
                        <option value="">Semua Status</option>
                        <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </select>
                </div>
            </div>

            <!-- Table -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium">Tipe</th>
                                <th class="px-4 py-3 text-left font-medium">Kavling / Proyek</th>
                                <th class="px-4 py-3 text-left font-medium">Konsumen</th>
                                <th class="px-4 py-3 text-left font-medium">Alasan</th>
                                <th class="px-4 py-3 text-left font-medium">Pengaju</th>
                                <th class="px-4 py-3 text-center font-medium">Status</th>
                                <th class="px-4 py-3 text-right font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/70">
                            <tr v-if="!requests.data.length">
                                <td colspan="7" class="text-center py-12 text-slate-500">Tidak ada pengajuan.</td>
                            </tr>
                            <tr
                                v-for="req in requests.data"
                                :key="req.id"
                                class="hover:bg-slate-800/20 transition-colors"
                            >
                                <td class="px-4 py-4">
                                    <span :class="req.type === 'unit_swap' ? 'bg-purple-500/15 text-purple-400 ring-1 ring-purple-500/25' : req.type === 'buyer_transfer' ? 'bg-sky-500/15 text-sky-300 ring-1 ring-sky-500/25' : req.type === 'rincian_change' ? 'bg-teal-500/15 text-teal-300 ring-1 ring-teal-500/25' : 'bg-slate-700/50 text-slate-300 ring-1 ring-slate-600'"
                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium">
                                        {{ req.type_label }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="text-slate-200 font-medium text-sm">{{ req.kavling }}<span v-if="req.kavling_baru" class="text-slate-500"> → {{ req.kavling_baru }}</span></div>
                                    <div class="text-slate-500 text-xs">{{ req.project }}</div>
                                    <div class="text-slate-500 text-xs mt-0.5">{{ req.created_at }}</div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="text-slate-300 text-sm">{{ req.konsumen }}<span v-if="req.konsumen_baru" class="text-sky-300"> → {{ req.konsumen_baru }}</span></div>
                                    <div v-if="req.hubungan" class="text-slate-500 text-xs">{{ req.hubungan }}</div>
                                </td>
                                <td class="px-4 py-4 max-w-xs">
                                    <p class="text-slate-400 text-xs line-clamp-2">{{ req.alasan }}</p>
                                    <p v-if="req.catatan_reviewer" class="text-slate-500 text-xs mt-1 italic line-clamp-1">↳ {{ req.catatan_reviewer }}</p>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="text-slate-300 text-sm">{{ req.requester }}</div>
                                    <div v-if="req.reviewer" class="text-slate-500 text-xs">Review: {{ req.reviewer }}</div>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span :class="statusBadgeClass[req.status]" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                                        {{ req.status_label }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-2" v-if="req.status === 'pending'">
                                        <button
                                            @click="openReview(req, 'approve')"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-400 text-xs font-medium rounded-lg border border-emerald-500/20 transition-colors"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5">
                                                <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                                            </svg>
                                            Setujui
                                        </button>
                                        <button
                                            @click="openReview(req, 'reject')"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-rose-600/20 hover:bg-rose-600/30 text-rose-400 text-xs font-medium rounded-lg border border-rose-500/20 transition-colors"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5">
                                                <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                                            </svg>
                                            Tolak
                                        </button>
                                    </div>
                                    <div v-else class="text-slate-500 text-xs text-right">{{ req.reviewed_at ?? '-' }}</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <Pagination :paginator="requests" embedded />
            </div>
        </div>

        <!-- Modal: Review -->
        <Teleport to="body">
            <div v-if="showReviewModal && selectedRequest" role="dialog" aria-modal="true" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="showReviewModal = false" />
                <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md shadow-2xl">
                    <div class="flex items-center justify-between p-5 border-b border-slate-800">
                        <h3 class="text-white font-semibold">
                            {{ reviewType === 'approve' ? `✅ Setujui ${selectedRequest.type_label}` : `❌ Tolak ${selectedRequest.type_label}` }}
                        </h3>
                        <button @click="showReviewModal = false" class="text-slate-500 hover:text-slate-300" aria-label="Tutup">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                        </button>
                    </div>
                    <div class="p-5 space-y-4">
                        <!-- Info -->
                        <div class="bg-slate-800/50 rounded-lg p-3 text-xs space-y-1">
                            <div class="text-slate-300"><span class="text-slate-500">Kavling:</span> {{ selectedRequest.kavling }}<span v-if="selectedRequest.kavling_baru"> → {{ selectedRequest.kavling_baru }}</span></div>
                            <div class="text-slate-300"><span class="text-slate-500">Konsumen:</span> {{ selectedRequest.konsumen }}<span v-if="selectedRequest.konsumen_baru" class="text-sky-300"> → {{ selectedRequest.konsumen_baru }} ({{ selectedRequest.hubungan }})</span></div>
                            <div class="text-slate-400"><span class="text-slate-500">Alasan:</span> {{ selectedRequest.alasan }}</div>
                        </div>

                        <template v-if="selectedRequest.type === 'rincian_change'">
                            <div v-if="selectedRequest.perubahan" class="space-y-2 text-xs">
                                <p v-if="selectedRequest.perubahan.konflik" class="text-rose-400 bg-rose-500/10 border border-rose-500/20 rounded-lg p-3">
                                    ⚠️ Usulan tidak bisa diterapkan saat ini: {{ selectedRequest.perubahan.konflik }}
                                </p>
                                <p v-else-if="!selectedRequest.perubahan.baris.length" class="text-slate-400 bg-slate-800/50 rounded-lg p-3">Usulan ini tidak lagi menghasilkan perubahan dibanding kondisi sekarang.</p>
                                <div v-else class="bg-slate-800/50 rounded-lg divide-y divide-slate-800">
                                    <div class="px-3 py-1.5 text-slate-500">Perubahan yang akan diterapkan (dihitung dari kondisi sekarang):</div>
                                    <div v-for="b in selectedRequest.perubahan.baris" :key="b.label" class="flex flex-wrap gap-x-3 px-3 py-1.5">
                                        <span class="w-36 text-slate-500">{{ b.label }}</span>
                                        <span class="text-slate-400 line-through">{{ b.lama }}</span>
                                        <span class="text-emerald-400">→ {{ b.baru }}</span>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <template v-else-if="selectedRequest.type === 'buyer_transfer'">
                            <div v-if="reviewType === 'approve'" class="text-yellow-400 text-xs bg-yellow-500/10 border border-yellow-500/20 rounded-lg p-3 space-y-1">
                                <p>⚠️ Pembeli akan diganti dari <b>{{ selectedRequest.konsumen }}</b> ke <b>{{ selectedRequest.konsumen_baru }}</b>.</p>
                                <p>UTJ dan pembayaran tetap pada transaksi ini. Berkas pembeli lama diarsipkan, hasil Proses Bank/SLIK dan SP3K dikosongkan, dan transaksi kembali ke Pemberkasan. Tindakan ini tidak bisa diurungkan (peralihan baru harus diajukan lagi).</p>
                            </div>
                        </template>
                        <template v-else-if="selectedRequest.type === 'unit_swap'">
                            <div v-if="reviewType === 'approve'" class="text-yellow-400 text-xs bg-yellow-500/10 border border-yellow-500/20 rounded-lg p-3">
                                ⚠️ Unit akan langsung ditukar: {{ selectedRequest.kavling }} → {{ selectedRequest.kavling_baru }}.
                            </div>
                            <!-- Penyesuaian harga: harga transaksi tidak berubah sendiri saat unit ditukar -->
                            <div v-if="reviewType === 'approve' && selectedRequest.harga" class="space-y-2 text-xs">
                                <template v-if="selectedRequest.harga.sama">
                                    <p class="text-slate-400 bg-slate-800/50 rounded-lg p-3">Harga unit tujuan sama dengan harga transaksi ({{ formatRupiah(selectedRequest.harga.dasar_sekarang) }}), tidak ada yang perlu disesuaikan.</p>
                                </template>
                                <template v-else>
                                    <div class="bg-slate-800/50 rounded-lg p-3 space-y-1">
                                        <div class="flex justify-between"><span class="text-slate-500">Harga dasar transaksi sekarang</span><span class="text-slate-200">{{ formatRupiah(selectedRequest.harga.dasar_sekarang) }}</span></div>
                                        <div class="flex justify-between"><span class="text-slate-500">Harga unit tujuan</span><span class="text-slate-200">{{ formatRupiah(selectedRequest.harga.dasar_unit_baru) }}</span></div>
                                        <div class="flex justify-between"><span class="text-slate-500">Selisih</span><span :class="selectedRequest.harga.selisih > 0 ? 'text-rose-400' : 'text-emerald-400'">{{ selectedRequest.harga.selisih > 0 ? '+' : '−' }}{{ formatRupiah(Math.abs(selectedRequest.harga.selisih)) }}</span></div>
                                    </div>
                                    <label class="flex items-start gap-2 cursor-pointer rounded-lg border p-3" :class="!reviewForm.ikuti_harga_baru ? 'border-violet-500 bg-violet-500/10' : 'border-slate-700'">
                                        <input type="radio" :value="false" v-model="reviewForm.ikuti_harga_baru" class="mt-0.5" />
                                        <span class="text-slate-300">Pertahankan harga lama<br><span class="text-slate-500">Harga deal tetap {{ formatRupiah(selectedRequest.harga.deal_sekarang) }}.</span></span>
                                    </label>
                                    <label class="flex items-start gap-2 cursor-pointer rounded-lg border p-3" :class="reviewForm.ikuti_harga_baru ? 'border-violet-500 bg-violet-500/10' : 'border-slate-700'">
                                        <input type="radio" :value="true" v-model="reviewForm.ikuti_harga_baru" class="mt-0.5" />
                                        <span class="text-slate-300">Ikuti harga unit baru<br><span class="text-slate-500">Harga dasar dan harga deal dihitung ulang: harga deal {{ formatRupiah(selectedRequest.harga.deal_sekarang) }} → <b class="text-slate-200">{{ formatRupiah(selectedRequest.harga.deal_baru) }}</b>.</span></span>
                                    </label>
                                    <p v-if="reviewForm.ikuti_harga_baru" class="text-amber-400 bg-amber-500/10 border border-amber-500/20 rounded-lg p-3">
                                        Booking Fee/DP dan jadwal cicilan yang sudah dibuat <b>tidak ikut berubah</b>; periksa Kartu Piutang sesudahnya.
                                        <span v-if="selectedRequest.harga.ada_pembayaran_harga"><br>⚠️ Sudah ada pembayaran DP/pelunasan tercatat pada transaksi ini; pastikan selisih harga sudah dibicarakan dengan konsumen.</span>
                                    </p>
                                </template>
                            </div>
                        </template>
                        <template v-else>
                            <div v-if="reviewType === 'approve'" class="text-yellow-400 text-xs bg-yellow-500/10 border border-yellow-500/20 rounded-lg p-3">
                                ⚠️ Kavling akan kembali ke status <strong>Tersedia</strong> dan transaksi akan dibatalkan.
                            </div>
                            <div v-if="reviewType === 'approve'" class="space-y-3">
                                <div class="bg-slate-800/50 rounded-lg p-3 flex justify-between items-center text-sm">
                                    <span class="text-slate-400">Total Dana Diterima</span>
                                    <span class="text-slate-200 font-semibold">{{ formatRupiah(selectedRequest.total_terbayar) }}</span>
                                </div>
                                <div>
                                    <label class="block text-slate-400 text-xs font-medium mb-1.5">Nominal Dikembalikan ke Konsumen <span class="text-rose-400">*</span></label>
                                    <MoneyInput
                                        v-model="reviewForm.nominal_dikembalikan" placeholder="0"
                                        class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                                        :class="{ 'border-rose-500': reviewForm.errors.nominal_dikembalikan }"
                                    />
                                    <p v-if="reviewForm.errors.nominal_dikembalikan" class="text-rose-400 text-xs mt-1">{{ reviewForm.errors.nominal_dikembalikan }}</p>
                                    <p v-else-if="reviewForm.nominal_dikembalikan !== ''" class="text-slate-500 text-xs mt-1">
                                        Hangus: {{ formatRupiah(Math.max(0, (selectedRequest.total_terbayar || 0) - (reviewForm.nominal_dikembalikan || 0))) }}
                                    </p>
                                </div>
                            </div>
                        </template>
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">
                                Catatan {{ reviewType === 'reject' ? '(Wajib)' : '(Opsional)' }}
                            </label>
                            <textarea
                                v-model="reviewForm.catatan_reviewer"
                                rows="3"
                                :placeholder="reviewType === 'approve' ? 'Catatan untuk pembatalan...' : 'Alasan penolakan (wajib diisi)'"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500 resize-none"
                                :class="{ 'border-rose-500': reviewForm.errors.catatan_reviewer }"
                            />
                            <p v-if="reviewForm.errors.catatan_reviewer" class="text-rose-400 text-xs mt-1">{{ reviewForm.errors.catatan_reviewer }}</p>
                        </div>
                        <div class="flex justify-end gap-3">
                            <button type="button" @click="showReviewModal = false" class="px-4 py-2 text-slate-400 text-sm">Batal</button>
                            <button
                                @click="submitReview"
                                :disabled="reviewForm.processing || (reviewType === 'approve' && !!selectedRequest.perubahan?.konflik)"
                                :class="reviewType === 'approve'
                                    ? 'bg-emerald-600 hover:bg-emerald-500 shadow-emerald-500/20'
                                    : 'bg-rose-600 hover:bg-rose-500 shadow-rose-500/20'"
                                class="px-4 py-2 text-white text-sm font-medium rounded-lg transition-colors shadow-lg"
                            >
                                {{ reviewForm.processing ? 'Memproses...' : (reviewType === 'approve' ? 'Setujui' : 'Tolak') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>

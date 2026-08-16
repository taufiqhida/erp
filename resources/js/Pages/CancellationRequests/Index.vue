<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    requests: Object,
    filters: Object,
    statusOptions: Array,
});

const statusFilter = ref(props.filters?.status ?? '');
watch(statusFilter, (val) => {
    router.get(route('cancellation-requests.index'), { status: val || undefined }, { preserveState: true, replace: true });
});

// Modal state
const showReviewModal = ref(false);
const reviewType = ref(''); // 'approve' | 'reject'
const selectedRequest = ref(null);

const reviewForm = useForm({ catatan_reviewer: '', nominal_dikembalikan: '' });

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
    <Head title="Pengajuan Pembatalan" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <span class="text-slate-200 font-medium">Pembatalan Kavling</span>
            </div>
        </template>

        <div class="p-6 space-y-5">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-white font-bold text-xl">Pengajuan Pembatalan</h1>
                    <p class="text-slate-400 text-sm mt-0.5">Review dan kelola permintaan pembatalan kavling</p>
                </div>
                <select
                    v-model="statusFilter"
                    class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                >
                    <option value="">Semua Status</option>
                    <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
            </div>

            <!-- Table -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-800">
                                <th class="text-left px-5 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Kavling / Proyek</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Konsumen</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Alasan</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Pengaju</th>
                                <th class="text-center px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Status</th>
                                <th class="text-right px-5 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/70">
                            <tr v-if="!requests.data.length">
                                <td colspan="6" class="text-center py-12 text-slate-500">Tidak ada pengajuan pembatalan.</td>
                            </tr>
                            <tr
                                v-for="req in requests.data"
                                :key="req.id"
                                class="hover:bg-slate-800/20 transition-colors"
                            >
                                <td class="px-5 py-4">
                                    <div class="text-slate-200 font-medium text-sm">{{ req.kavling }}</div>
                                    <div class="text-slate-500 text-xs">{{ req.project }}</div>
                                    <div class="text-slate-600 text-xs mt-0.5">{{ req.created_at }}</div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="text-slate-300 text-sm">{{ req.konsumen }}</div>
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
                                    <div v-else class="text-slate-600 text-xs text-right">{{ req.reviewed_at ?? '-' }}</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="requests.last_page > 1" class="flex items-center justify-between px-5 py-3.5 border-t border-slate-800">
                    <span class="text-slate-500 text-xs">{{ requests.from }}–{{ requests.to }} dari {{ requests.total }}</span>
                    <div class="flex gap-1">
                        <Link
                            v-for="link in requests.links"
                            :key="link.label"
                            :href="link.url ?? '#'"
                            v-html="link.label"
                            :class="['px-3 py-1.5 text-xs rounded-md transition-colors', link.active ? 'bg-violet-600 text-white' : 'text-slate-400 hover:bg-slate-800', !link.url ? 'opacity-40 pointer-events-none' : '']"
                        />
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: Review -->
        <Teleport to="body">
            <div v-if="showReviewModal && selectedRequest" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="showReviewModal = false" />
                <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md shadow-2xl">
                    <div class="flex items-center justify-between p-5 border-b border-slate-800">
                        <h3 class="text-white font-semibold">
                            {{ reviewType === 'approve' ? '✅ Setujui Pembatalan' : '❌ Tolak Pembatalan' }}
                        </h3>
                        <button @click="showReviewModal = false" class="text-slate-500 hover:text-slate-300">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                        </button>
                    </div>
                    <div class="p-5 space-y-4">
                        <!-- Info -->
                        <div class="bg-slate-800/50 rounded-lg p-3 text-xs space-y-1">
                            <div class="text-slate-300"><span class="text-slate-500">Kavling:</span> {{ selectedRequest.kavling }}</div>
                            <div class="text-slate-300"><span class="text-slate-500">Konsumen:</span> {{ selectedRequest.konsumen }}</div>
                            <div class="text-slate-400"><span class="text-slate-500">Alasan:</span> {{ selectedRequest.alasan }}</div>
                        </div>
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
                                <input
                                    v-model="reviewForm.nominal_dikembalikan"
                                    type="number" min="0" :max="selectedRequest.total_terbayar" placeholder="0"
                                    class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                                    :class="{ 'border-rose-500': reviewForm.errors.nominal_dikembalikan }"
                                />
                                <p v-if="reviewForm.errors.nominal_dikembalikan" class="text-rose-400 text-xs mt-1">{{ reviewForm.errors.nominal_dikembalikan }}</p>
                                <p v-else-if="reviewForm.nominal_dikembalikan !== ''" class="text-slate-500 text-xs mt-1">
                                    Hangus: {{ formatRupiah(Math.max(0, (selectedRequest.total_terbayar || 0) - (reviewForm.nominal_dikembalikan || 0))) }}
                                </p>
                            </div>
                        </div>
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
                                :disabled="reviewForm.processing"
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

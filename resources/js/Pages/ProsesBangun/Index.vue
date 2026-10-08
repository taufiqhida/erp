<script setup>
import Pagination from '@/Components/Pagination.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    project: Object,
    kavlings: Object, // paginator server-side (data, links, total, ...)
    spkRiwayat: Array,
    filters: Object,
    klusterOptions: Array,
    blokOptions: Array,
    tipeUnitOptions: Array,
    statusBangunStages: Array,
    kontraktorOptions: Array,
    canManage: Boolean,
    canCreateSpk: Boolean,
});

const deadlineBadge = {
    safe:     { label: 'Aman',      cls: 'bg-emerald-500/15 text-emerald-400' },
    warning:  { label: '≤30 hari',  cls: 'bg-amber-500/15 text-amber-400' },
    critical: { label: '≤14 hari',  cls: 'bg-orange-500/15 text-orange-400' },
    expired:  { label: 'Lewat',     cls: 'bg-rose-500/15 text-rose-400' },
};

// Urutan & paginasi dikerjakan SERVER (lihat ProsesBangunController::index) supaya benar lintas
// halaman. Klik judul kolom = muat ulang halaman 1 dengan urutan itu.
const sortKey = ref(props.filters.urut ?? 'unit');
const sortDir = ref(props.filters.arah ?? 'asc');
const sortArrow = (key) => sortKey.value === key ? (sortDir.value === 'asc' ? '▲' : '▼') : '⇅';
const setSort = (key) => {
    if (sortKey.value === key) { sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'; }
    else { sortKey.value = key; sortDir.value = 'asc'; }
    applyFilters();
};
const sortedKavlings = computed(() => props.kavlings.data);

const filters = reactive({
    kluster: props.filters.kluster ?? '',
    blok: props.filters.blok ?? '',
    tipe_unit_preset_id: props.filters.tipe_unit_preset_id ?? '',
    status_bangun_stage_id: props.filters.status_bangun_stage_id ?? '',
    kontraktor_id: props.filters.kontraktor_id ?? '',
});
const applyFilters = () => {
    router.get(route('proses-bangun.index', props.project.id), {
        kluster: filters.kluster || undefined,
        blok: filters.blok || undefined,
        tipe_unit_preset_id: filters.tipe_unit_preset_id || undefined,
        status_bangun_stage_id: filters.status_bangun_stage_id || undefined,
        kontraktor_id: filters.kontraktor_id || undefined,
        urut: sortKey.value === 'unit' ? undefined : sortKey.value,
        arah: sortKey.value === 'unit' && sortDir.value === 'asc' ? undefined : sortDir.value,
    }, { preserveState: true, replace: true });
};
const resetFilters = () => {
    filters.kluster = ''; filters.blok = ''; filters.tipe_unit_preset_id = ''; filters.status_bangun_stage_id = ''; filters.kontraktor_id = '';
    applyFilters();
};

// ── Update status bangun inline dari tabel ──────────────────────────────
const statusForms = reactive({});
// Tahap + persen penyelesaian di dalam tahap itu. Progress total dihitung live di browser
// (sama dengan rumus server: bobot tahap sebelumnya + bobot tahap ini x persen / 100) supaya
// langsung terlihat saat mengetik; nilai resmi dari server menggantikannya setelah tersimpan.
const draft = reactive({});
const rowStage = (k) => Number(draft[k.id]?.stage ?? k.status_bangun_stage_id);
const rowPersen = (k) => Number(draft[k.id]?.persen ?? k.status_bangun_persen);
const progressTotal = (k) => {
    const stage = props.statusBangunStages.find(s => s.id === rowStage(k));
    if (!stage) return 0;
    const sebelum = props.statusBangunStages.filter(s => s.urutan < stage.urutan).reduce((sum, s) => sum + Number(s.bobot), 0);
    return Math.round((sebelum + Number(stage.bobot) * Math.max(0, Math.min(100, rowPersen(k))) / 100) * 100) / 100;
};
const saveBangun = (k, stage, persen) => {
    statusForms[k.id] = useForm({ status_bangun_stage_id: stage, persen });
    statusForms[k.id].patch(route('kavlings.status-bangun', k.id), {
        preserveScroll: true,
        onFinish: () => { delete draft[k.id]; },
    });
};
const changeStage = (k, value) => {
    const stage = Number(value);
    if (stage === k.status_bangun_stage_id) return;
    draft[k.id] = { stage, persen: 0 }; // pindah tahap → mulai dari 0%
    saveBangun(k, stage, 0);
};
const changePersen = (k, value) => {
    let persen = value === '' ? 0 : Number(value);
    if (Number.isNaN(persen)) return;
    persen = Math.max(0, Math.min(100, persen));
    if (persen === k.status_bangun_persen && draft[k.id] === undefined) return;
    draft[k.id] = { stage: rowStage(k), persen };
    saveBangun(k, rowStage(k), persen);
};
const isDefaultStage = (k) => props.statusBangunStages.find(s => s.id === rowStage(k))?.urutan === 1;

// ── Catatan bebas per kavling — tombol tanda ada/tidaknya, buka popover buat baca/isi.
const catatanOpen = ref(null);
const catatanDraft = reactive({});
const catatanForms = reactive({});
const toggleCatatan = (k) => {
    if (catatanOpen.value === k.id) { catatanOpen.value = null; return; }
    catatanDraft[k.id] = k.catatan ?? '';
    catatanOpen.value = k.id;
};
const saveCatatan = (k) => {
    catatanForms[k.id] = useForm({ catatan: catatanDraft[k.id] });
    catatanForms[k.id].patch(route('kavlings.catatan', k.id), {
        preserveScroll: true,
        onSuccess: () => { catatanOpen.value = null; },
    });
};

const updateStatusBangun = (k, value) => {
    changeStage(k, value);
};

const showRiwayat = ref(false);
</script>

<template>
    <Head :title="`Proses Bangun – ${project.nama}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <span>Proyek &amp; Teknik</span>
                <span>/</span>
                <span class="text-slate-200 font-medium">Proses Bangun — {{ project.nama }}</span>
            </div>
        </template>

        <div class="p-6 space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-white text-xl font-bold">Proses Bangun</h1>
                    <p class="text-slate-400 text-sm mt-0.5">Progress pembangunan, kontraktor, dan SPK per unit.</p>
                </div>
                <Link v-if="canCreateSpk" :href="route('spk.create', project.id)"
                    class="inline-flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                        <path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" />
                    </svg>
                    Buat SPK
                </Link>
            </div>

            <!-- Riwayat SPK -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <button type="button" @click="showRiwayat = !showRiwayat"
                    class="w-full flex items-center justify-between px-5 py-3.5 hover:bg-slate-800/30 transition-colors">
                    <span class="text-slate-300 text-sm font-medium">Riwayat SPK ({{ spkRiwayat.length }})</span>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                        :class="['w-4 h-4 text-slate-500 transition-transform', showRiwayat ? 'rotate-180' : '']">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>
                <div v-if="showRiwayat">
                    <div v-if="!spkRiwayat.length" class="px-5 py-6 text-center text-slate-500 text-sm border-t border-slate-800">
                        Belum ada SPK diterbitkan untuk proyek ini.
                    </div>
                    <div v-for="spk in spkRiwayat" :key="spk.id"
                        class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 border-t border-slate-800 text-sm">
                        <div>
                            <span class="text-slate-200 font-medium font-mono">{{ spk.nomor_spk }}</span>
                            <span class="text-slate-500 text-xs ml-2">{{ spk.kontraktor_nama }} · {{ spk.jumlah_unit }} unit</span>
                        </div>
                        <div class="flex items-center gap-3 text-xs">
                            <span class="text-slate-500">Terbit {{ spk.tanggal_terbit }} · Deadline {{ spk.tanggal_deadline }}</span>
                            <span :class="deadlineBadge[spk.deadline_status]?.cls" class="px-2 py-0.5 rounded-full font-medium">
                                {{ deadlineBadge[spk.deadline_status]?.label }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter -->
            <div class="flex flex-wrap items-center gap-2">
                <select v-model="filters.kluster" @change="applyFilters"
                    class="px-3 py-2 bg-slate-900 border border-slate-800 rounded-lg text-slate-300 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                    <option value="">Semua Kluster</option>
                    <option v-for="k in klusterOptions" :key="k" :value="k">{{ k }}</option>
                </select>
                <select v-model="filters.blok" @change="applyFilters"
                    class="px-3 py-2 bg-slate-900 border border-slate-800 rounded-lg text-slate-300 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                    <option value="">Semua Blok</option>
                    <option v-for="b in blokOptions" :key="b" :value="b">{{ b }}</option>
                </select>
                <select v-model="filters.tipe_unit_preset_id" @change="applyFilters"
                    class="px-3 py-2 bg-slate-900 border border-slate-800 rounded-lg text-slate-300 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                    <option value="">Semua Tipe</option>
                    <option v-for="t in tipeUnitOptions" :key="t.id" :value="t.id">{{ t.nama }}</option>
                </select>
                <select v-model="filters.status_bangun_stage_id" @change="applyFilters"
                    class="px-3 py-2 bg-slate-900 border border-slate-800 rounded-lg text-slate-300 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                    <option value="">Semua Status Bangun</option>
                    <option v-for="s in statusBangunStages" :key="s.id" :value="s.id">{{ s.nama }}</option>
                </select>
                <select v-model="filters.kontraktor_id" @change="applyFilters"
                    class="px-3 py-2 bg-slate-900 border border-slate-800 rounded-lg text-slate-300 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                    <option value="">Semua Kontraktor</option>
                    <option v-for="k in kontraktorOptions" :key="k.id" :value="k.id">{{ k.nama }}</option>
                </select>
                <button v-if="filters.kluster || filters.blok || filters.tipe_unit_preset_id || filters.status_bangun_stage_id || filters.kontraktor_id" @click="resetFilters"
                    class="px-2.5 py-1.5 text-slate-400 hover:text-slate-200 text-xs rounded-lg transition-colors">
                    Reset
                </button>
            </div>

            <!-- Tabel Kavling -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-800 text-xs text-slate-500 uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium">
                                    <button type="button" @click="setSort('unit')" class="uppercase tracking-wide hover:text-slate-300 transition-colors inline-flex items-center gap-1" :class="sortKey === 'unit' ? 'text-violet-400' : ''">Kavling <span :class="sortKey === 'unit' ? '' : 'text-slate-700'">{{ sortArrow('unit') }}</span></button>
                                </th>
                                <th class="px-4 py-3 text-left font-medium">Tipe</th>
                                <th class="px-4 py-3 text-left font-medium">Tahap Bangun</th>
                                <th class="px-4 py-3 text-left font-medium">% Tahap Ini</th>
                                <th class="px-4 py-3 text-left font-medium">
                                    <button type="button" @click="setSort('progress')" class="uppercase tracking-wide hover:text-slate-300 transition-colors inline-flex items-center gap-1" :class="sortKey === 'progress' ? 'text-violet-400' : ''">Progress Total <span :class="sortKey === 'progress' ? '' : 'text-slate-700'">{{ sortArrow('progress') }}</span></button>
                                </th>
                                <th class="px-4 py-3 text-left font-medium">Kontraktor</th>
                                <th class="px-4 py-3 text-left font-medium">SPK</th>
                                <th class="px-4 py-3 text-left font-medium">
                                    <button type="button" @click="setSort('deadline')" class="uppercase tracking-wide hover:text-slate-300 transition-colors inline-flex items-center gap-1" :class="sortKey === 'deadline' ? 'text-violet-400' : ''" title="Urutkan berdasarkan deadline terdekat">Deadline <span :class="sortKey === 'deadline' ? '' : 'text-slate-700'">{{ sortArrow('deadline') }}</span></button>
                                </th>
                                <th class="px-4 py-3 text-center font-medium">Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="k in sortedKavlings" :key="k.id" class="border-b border-slate-800/60 last:border-b-0 hover:bg-slate-800/20">
                                <td class="px-5 py-3.5 text-slate-200 font-medium">{{ k.nomor_lengkap }}</td>
                                <td class="px-4 py-3.5 text-slate-400 text-xs">{{ k.tipe_unit_nama ?? '-' }}</td>
                                <td class="px-4 py-3.5">
                                    <select v-if="canManage"
                                        :value="rowStage(k)"
                                        @change="changeStage(k, $event.target.value)"
                                        class="px-2 py-1 bg-slate-800 border border-slate-700 rounded-md text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500 cursor-pointer">
                                        <option v-for="s in statusBangunStages" :key="s.id" :value="s.id">{{ s.nama }}</option>
                                    </select>
                                    <span v-else class="text-slate-400 text-xs">{{ k.status_bangun_label }}</span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <div v-if="isDefaultStage(k)" class="text-slate-500 text-xs">-</div>
                                    <div v-else-if="canManage" class="flex items-center gap-1">
                                        <input type="number" min="0" max="100" step="1"
                                            :value="rowPersen(k)"
                                            @change="changePersen(k, $event.target.value)"
                                            class="w-16 px-2 py-1 bg-slate-800 border border-slate-700 rounded-md text-slate-300 text-xs text-right focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                        <span class="text-slate-500 text-xs">%</span>
                                    </div>
                                    <span v-else class="text-slate-400 text-xs">{{ rowPersen(k) }}%</span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-2">
                                        <div class="w-16 h-1.5 bg-slate-800 rounded-full overflow-hidden flex-shrink-0">
                                            <div class="h-full bg-gradient-to-r from-violet-500 to-indigo-500 rounded-full transition-all" :style="{ width: progressTotal(k) + '%' }"/>
                                        </div>
                                        <span class="text-slate-300 text-xs font-medium tabular-nums">{{ progressTotal(k) }}%</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3.5 text-slate-400 text-xs">{{ k.kontraktor_nama ?? '-' }}</td>
                                <td class="px-4 py-3.5 text-slate-400 text-xs font-mono">{{ k.spk_nomor ?? '-' }}</td>
                                <td class="px-4 py-3.5">
                                    <span v-if="k.spk_deadline" :class="deadlineBadge[k.spk_deadline_status]?.cls" class="px-2 py-0.5 rounded-full text-xs font-medium">
                                        {{ k.spk_deadline }}
                                    </span>
                                    <span v-else class="text-slate-500 text-xs">-</span>
                                </td>
                                <td class="px-4 py-3.5 text-center relative">
                                    <button type="button" @click="toggleCatatan(k)"
                                        :title="k.catatan ? 'Ada catatan — klik untuk baca' : 'Belum ada catatan — klik untuk isi'"
                                        class="inline-flex p-1.5 rounded-lg transition-colors"
                                        :class="k.catatan ? 'text-amber-400 hover:bg-amber-400/10' : 'text-slate-500 hover:text-slate-400 hover:bg-slate-800'">
                                        <svg v-if="k.catatan" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
                                            <path fill-rule="evenodd" d="M4.804 21.644A6.707 6.707 0 006 21.75a6.721 6.721 0 003.583-1.029c.774.182 1.584.279 2.417.279 5.322 0 9.75-3.97 9.75-9 0-5.03-4.428-9-9.75-9s-9.75 3.97-9.75 9c0 2.409 1.025 4.587 2.674 6.192.232.226.277.428.254.543a3.73 3.73 0 01-.814 1.686.75.75 0 00.44 1.223zM8.25 10.875a1.125 1.125 0 100 2.25 1.125 1.125 0 000-2.25zM10.875 12a1.125 1.125 0 112.25 0 1.125 1.125 0 01-2.25 0zm4.875-1.125a1.125 1.125 0 100 2.25 1.125 1.125 0 000-2.25z" clip-rule="evenodd" />
                                        </svg>
                                        <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                                        </svg>
                                    </button>
                                    <div v-if="catatanOpen === k.id" class="absolute right-0 top-full z-20 mt-1 w-72 bg-slate-800 border border-slate-700 rounded-xl shadow-xl p-3 space-y-2 text-left">
                                        <div class="text-slate-300 text-xs font-medium">Catatan — {{ k.nomor_lengkap }}</div>
                                        <textarea v-if="canManage" v-model="catatanDraft[k.id]" rows="3" placeholder="Tulis catatan..."
                                            class="w-full px-2 py-1.5 bg-slate-900 border border-slate-700 rounded-lg text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500"></textarea>
                                        <p v-else class="text-slate-400 text-xs whitespace-pre-wrap">{{ k.catatan || 'Belum ada catatan.' }}</p>
                                        <div v-if="canManage" class="flex justify-end gap-2">
                                            <button type="button" @click="catatanOpen = null" class="px-2 py-1 text-slate-500 hover:text-slate-300 text-xs">Batal</button>
                                            <button type="button" @click="saveCatatan(k)" class="px-2.5 py-1 bg-violet-600 hover:bg-violet-500 text-white text-xs rounded-lg transition-colors">Simpan</button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!kavlings.data.length">
                                <td colspan="9" class="px-5 py-8 text-center text-slate-500 text-sm">Tidak ada kavling yang cocok dengan filter.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :paginator="kavlings" embedded />
            </div>
        </div>
    </AuthenticatedLayout>
</template>

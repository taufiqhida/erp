<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    project: Object,
    kavlings: Array,
    spkRiwayat: Array,
    filters: Object,
    klusterOptions: Array,
    blokOptions: Array,
    tipeUnitOptions: Array,
    statusBangunStages: Array,
    canManage: Boolean,
});

const deadlineBadge = {
    safe:     { label: 'Aman',      cls: 'bg-emerald-500/15 text-emerald-400' },
    warning:  { label: '≤30 hari',  cls: 'bg-amber-500/15 text-amber-400' },
    critical: { label: '≤14 hari',  cls: 'bg-orange-500/15 text-orange-400' },
    expired:  { label: 'Lewat',     cls: 'bg-rose-500/15 text-rose-400' },
};

// Sort di browser — seluruh unit satu proyek sudah dimuat sekaligus (bukan paginasi).
// "Deadline": yang paling mendekati (termasuk yang sudah lewat, karena paling mendesak)
// di atas; unit tanpa SPK dan unit yang sudah selesai dibangun selalu di paling bawah.
const sortKey = ref('unit');
const sortDir = ref('asc');
const setSort = (key) => {
    if (sortKey.value === key) { sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'; return; }
    sortKey.value = key;
    sortDir.value = 'asc';
};
const sortArrow = (key) => sortKey.value === key ? (sortDir.value === 'asc' ? '▲' : '▼') : '';
const sortedKavlings = computed(() => {
    if (sortKey.value !== 'deadline') return sortDir.value === 'asc' ? props.kavlings : [...props.kavlings].reverse();
    const dir = sortDir.value === 'asc' ? 1 : -1;
    const aktif = props.kavlings.filter(k => k.spk_deadline_raw && !k.bangun_selesai)
        .sort((a, b) => dir * a.spk_deadline_raw.localeCompare(b.spk_deadline_raw));
    const sisanya = props.kavlings.filter(k => !(k.spk_deadline_raw && !k.bangun_selesai));
    return [...aktif, ...sisanya];
});

const filters = reactive({
    kluster: props.filters.kluster ?? '',
    blok: props.filters.blok ?? '',
    tipe_unit_preset_id: props.filters.tipe_unit_preset_id ?? '',
    status_bangun_stage_id: props.filters.status_bangun_stage_id ?? '',
});
const applyFilters = () => {
    router.get(route('proses-bangun.index', props.project.id), { ...filters }, { preserveState: true, replace: true });
};
const resetFilters = () => {
    filters.kluster = ''; filters.blok = ''; filters.tipe_unit_preset_id = ''; filters.status_bangun_stage_id = '';
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
                <Link v-if="canManage" :href="route('spk.create', project.id)"
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
                    <div v-if="!spkRiwayat.length" class="px-5 py-6 text-center text-slate-600 text-sm border-t border-slate-800">
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
                <button v-if="filters.kluster || filters.blok || filters.tipe_unit_preset_id || filters.status_bangun_stage_id" @click="resetFilters"
                    class="px-2.5 py-1.5 text-slate-400 hover:text-slate-200 text-xs rounded-lg transition-colors">
                    Reset
                </button>
            </div>

            <!-- Tabel Kavling -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-800">
                                <th class="text-left px-5 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">
                                    <button type="button" @click="setSort('unit')" class="uppercase tracking-wider hover:text-slate-200 transition-colors" :class="sortKey === 'unit' ? 'text-violet-400' : ''">Kavling {{ sortArrow('unit') }}</button>
                                </th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Tipe</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Tahap Bangun</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">% Tahap Ini</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Progress Total</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">Kontraktor</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">SPK</th>
                                <th class="text-left px-4 py-3.5 text-slate-400 font-medium text-xs uppercase tracking-wider">
                                    <button type="button" @click="setSort('deadline')" class="uppercase tracking-wider hover:text-slate-200 transition-colors" :class="sortKey === 'deadline' ? 'text-violet-400' : ''" title="Urutkan berdasarkan deadline terdekat">Deadline {{ sortArrow('deadline') }}</button>
                                </th>
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
                                    <div v-if="isDefaultStage(k)" class="text-slate-600 text-xs">-</div>
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
                                    <span v-else class="text-slate-600 text-xs">-</span>
                                </td>
                            </tr>
                            <tr v-if="!kavlings.length">
                                <td colspan="8" class="px-5 py-8 text-center text-slate-600 text-sm">Tidak ada kavling yang cocok dengan filter.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Papa from 'papaparse';

const props = defineProps({
    show: { type: Boolean, default: false },
    projectId: { type: [Number, String], required: true },
});

const emit = defineEmits(['close', 'imported']);

// ── Step state ────────────────────────────────────────────────────────
const step = ref(1); // 1: upload, 2: mapping, 3: preview

const SYSTEM_FIELDS = [
    { key: 'nomor_kavling', label: 'No Unit / Kode Kavling', required: true, guesses: ['nomor_kavling', 'no_unit', 'nomor_unit', 'kavling', 'unit', 'kode', 'no'] },
    { key: 'blok', label: 'Blok', required: false, guesses: ['blok', 'block'] },
    { key: 'luas_tanah', label: 'Luas Tanah (m²)', required: false, guesses: ['luas_tanah', 'lt', 'luas_m2', 'luas'] },
    { key: 'luas_bangunan', label: 'Luas Bangunan (m²)', required: false, guesses: ['luas_bangunan', 'lb'] },
    { key: 'harga', label: 'Harga', required: false, guesses: ['harga', 'price'] },
    { key: 'status_unit', label: 'Status (available/not_for_sale)', required: false, guesses: ['status', 'status_unit', 'status_jual'] },
    { key: 'keterangan', label: 'Keterangan', required: false, guesses: ['keterangan', 'catatan', 'note'] },
];

const normalize = (s) => (s ?? '').toString().toLowerCase().replace(/[^a-z0-9]/g, '');

// ── Raw parse result ──────────────────────────────────────────────────
const csvHeaders = ref([]);
const csvRows = ref([]);
const parseError = ref('');
const fileName = ref('');

const onFileChange = (e) => {
    const file = e.target.files[0];
    if (!file) return;
    fileName.value = file.name;
    parseError.value = '';

    Papa.parse(file, {
        header: true,
        skipEmptyLines: true,
        complete: (result) => {
            if (result.errors?.length) {
                parseError.value = result.errors[0].message;
                return;
            }
            csvHeaders.value = result.meta.fields ?? [];
            csvRows.value = result.data;
            if (csvRows.value.length === 0) {
                parseError.value = 'File CSV tidak berisi data (hanya header atau kosong).';
                return;
            }
            autoGuessMapping();
            step.value = 2;
        },
        error: (err) => {
            parseError.value = err.message;
        },
    });
};

// ── Column mapping ────────────────────────────────────────────────────
// mapping[systemFieldKey] = csvHeaderName | ''
const mapping = ref({});

const autoGuessMapping = () => {
    const normalizedHeaders = csvHeaders.value.map(h => ({ raw: h, norm: normalize(h) }));
    SYSTEM_FIELDS.forEach((field) => {
        const guessSet = field.guesses.map(normalize);
        const match = normalizedHeaders.find(h => guessSet.includes(h.norm));
        mapping.value[field.key] = match ? match.raw : '';
    });
};

const mappingValid = computed(() =>
    SYSTEM_FIELDS.filter(f => f.required).every(f => mapping.value[f.key])
);

// ── Build preview rows dari mapping ──────────────────────────────────
const previewRows = ref([]);

const buildPreview = () => {
    previewRows.value = csvRows.value.map((row) => {
        const mapped = {};
        SYSTEM_FIELDS.forEach((field) => {
            const csvCol = mapping.value[field.key];
            mapped[field.key] = csvCol ? (row[csvCol] ?? '').toString().trim() : '';
        });
        return mapped;
    });
    step.value = 3;
};

// ── Validasi baris di preview (client-side, sebelum submit) ─────────
const rowError = (row) => {
    if (!row.nomor_kavling) return 'No Unit kosong';
    if (row.status_unit && !['available', 'not_for_sale'].includes(row.status_unit)) {
        return `Status "${row.status_unit}" tidak dikenal`;
    }
    return null;
};

const validRowCount = computed(() => previewRows.value.filter(r => !rowError(r)).length);
const removeRow = (idx) => previewRows.value.splice(idx, 1);

// ── Submit ────────────────────────────────────────────────────────────
const submitForm = useForm({ rows: [] });

const submitImport = () => {
    submitForm.rows = previewRows.value
        .filter(r => !rowError(r))
        .map(r => ({
            ...r,
            luas_tanah: r.luas_tanah ? parseAngka(r.luas_tanah) : null,
            luas_bangunan: r.luas_bangunan ? parseAngka(r.luas_bangunan) : null,
            harga: r.harga ? parseAngka(r.harga) : null,
            status_unit: r.status_unit || 'available',
        }));

    submitForm.post(route('projects.import-kavling-mapped', props.projectId), {
        onSuccess: () => {
            emit('imported');
            resetAndClose();
        },
    });
};

// Bersihkan format angka Indonesia (1.500.000 / 1,500,000 / 1500000)
const parseAngka = (value) => {
    let str = String(value).replace(/[^\d,.]/g, '');
    if (str.includes('.') && str.includes(',')) {
        str = str.replace(/\./g, '').replace(',', '.');
    } else if ((str.match(/\./g) || []).length > 1) {
        str = str.replace(/\./g, '');
    } else if (str.includes(',') && !str.includes('.')) {
        str = str.replace(/,/g, '');
    }
    return parseFloat(str) || null;
};

const resetAndClose = () => {
    step.value = 1;
    csvHeaders.value = [];
    csvRows.value = [];
    previewRows.value = [];
    mapping.value = {};
    fileName.value = '';
    parseError.value = '';
    submitForm.reset();
    emit('close');
};
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="resetAndClose">
            <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" />
            <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-3xl shadow-2xl max-h-[90vh] flex flex-col">
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800 flex-shrink-0">
                    <div>
                        <h3 class="text-white font-semibold">Import Kavling dari CSV</h3>
                        <p class="text-slate-400 text-xs mt-0.5">Parse &amp; preview di browser sebelum disimpan ke server</p>
                    </div>
                    <button @click="resetAndClose" class="text-slate-500 hover:text-slate-300">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <!-- Step indicator -->
                <div class="flex items-center gap-2 px-6 py-3 border-b border-slate-800 flex-shrink-0 text-xs">
                    <span :class="step >= 1 ? 'text-violet-400 font-semibold' : 'text-slate-600'">1. Upload</span>
                    <span class="text-slate-700">→</span>
                    <span :class="step >= 2 ? 'text-violet-400 font-semibold' : 'text-slate-600'">2. Mapping Kolom</span>
                    <span class="text-slate-700">→</span>
                    <span :class="step >= 3 ? 'text-violet-400 font-semibold' : 'text-slate-600'">3. Preview &amp; Import</span>
                </div>

                <div class="p-6 overflow-y-auto flex-1">
                    <!-- ── Step 1: Upload ─────────────────────────── -->
                    <div v-if="step === 1" class="space-y-4">
                        <div class="bg-slate-800 rounded-xl p-4 text-xs text-slate-400">
                            Upload file CSV. Header kolom bebas — Anda akan diminta mencocokkan kolom di langkah berikutnya.
                        </div>
                        <label class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-slate-700 hover:border-violet-500/50 rounded-xl py-10 cursor-pointer transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 text-slate-500"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
                            <span class="text-slate-300 text-sm">Klik untuk pilih file CSV</span>
                            <input type="file" accept=".csv,text/csv" class="hidden" @change="onFileChange" />
                        </label>
                        <p v-if="parseError" class="text-rose-400 text-xs">{{ parseError }}</p>
                    </div>

                    <!-- ── Step 2: Column Mapping ─────────────────── -->
                    <div v-else-if="step === 2" class="space-y-4">
                        <div class="bg-slate-800 rounded-xl p-3 text-xs text-slate-400">
                            📄 {{ fileName }} — {{ csvRows.length }} baris terdeteksi. Cocokkan kolom CSV ke field sistem:
                        </div>
                        <div class="space-y-3">
                            <div v-for="field in SYSTEM_FIELDS" :key="field.key" class="grid grid-cols-2 gap-3 items-center">
                                <label class="text-slate-300 text-sm">
                                    {{ field.label }}
                                    <span v-if="field.required" class="text-rose-400">*</span>
                                </label>
                                <select v-model="mapping[field.key]"
                                    class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                                    <option value="">-- Tidak dipetakan --</option>
                                    <option v-for="h in csvHeaders" :key="h" :value="h">{{ h }}</option>
                                </select>
                            </div>
                        </div>
                        <p v-if="!mappingValid" class="text-amber-400 text-xs">Field wajib ("No Unit / Kode Kavling") harus dipetakan ke salah satu kolom CSV.</p>
                        <div class="flex justify-between pt-2">
                            <button @click="step = 1" class="px-4 py-2 text-slate-400 hover:text-slate-200 text-sm">← Kembali</button>
                            <button @click="buildPreview" :disabled="!mappingValid"
                                class="px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition-colors">
                                Lanjut ke Preview →
                            </button>
                        </div>
                    </div>

                    <!-- ── Step 3: Preview & Edit ─────────────────── -->
                    <div v-else-if="step === 3" class="space-y-4">
                        <div class="bg-slate-800 rounded-xl p-3 text-xs text-slate-400 flex items-center justify-between">
                            <span>{{ previewRows.length }} baris, <span class="text-emerald-400 font-medium">{{ validRowCount }} valid</span> akan diimport.</span>
                        </div>
                        <div class="overflow-x-auto border border-slate-800 rounded-xl">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="bg-slate-800/50 border-b border-slate-800">
                                        <th class="px-3 py-2 text-left text-slate-400 font-medium">No Unit</th>
                                        <th class="px-3 py-2 text-left text-slate-400 font-medium">Blok</th>
                                        <th class="px-3 py-2 text-left text-slate-400 font-medium">LT</th>
                                        <th class="px-3 py-2 text-left text-slate-400 font-medium">LB</th>
                                        <th class="px-3 py-2 text-left text-slate-400 font-medium">Harga</th>
                                        <th class="px-3 py-2 text-left text-slate-400 font-medium">Status</th>
                                        <th class="px-3 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, idx) in previewRows" :key="idx"
                                        :class="rowError(row) ? 'bg-rose-500/5' : ''"
                                        class="border-b border-slate-800/50">
                                        <td class="px-2 py-1.5">
                                            <input v-model="row.nomor_kavling" class="w-24 px-2 py-1 bg-slate-800 border border-slate-700 rounded text-slate-200"
                                                :class="{ 'border-rose-500': !row.nomor_kavling }" />
                                        </td>
                                        <td class="px-2 py-1.5"><input v-model="row.blok" class="w-14 px-2 py-1 bg-slate-800 border border-slate-700 rounded text-slate-200" /></td>
                                        <td class="px-2 py-1.5"><input v-model="row.luas_tanah" class="w-20 px-2 py-1 bg-slate-800 border border-slate-700 rounded text-slate-200" /></td>
                                        <td class="px-2 py-1.5"><input v-model="row.luas_bangunan" class="w-20 px-2 py-1 bg-slate-800 border border-slate-700 rounded text-slate-200" /></td>
                                        <td class="px-2 py-1.5"><input v-model="row.harga" class="w-28 px-2 py-1 bg-slate-800 border border-slate-700 rounded text-slate-200" /></td>
                                        <td class="px-2 py-1.5">
                                            <select v-model="row.status_unit" class="px-2 py-1 bg-slate-800 border border-slate-700 rounded text-slate-200">
                                                <option value="available">available</option>
                                                <option value="not_for_sale">not_for_sale</option>
                                            </select>
                                        </td>
                                        <td class="px-2 py-1.5 text-right">
                                            <button @click="removeRow(idx)" class="text-slate-500 hover:text-rose-400" title="Hapus baris">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5"><path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 006 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 10.23 1.482l.149-.022.841 10.518A2.75 2.75 0 007.596 19h4.807a2.75 2.75 0 002.742-2.53l.841-10.52.149.023a.75.75 0 00.23-1.482A41.03 41.03 0 0014 4.193V3.75A2.75 2.75 0 0011.25 1h-2.5zM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4z" clip-rule="evenodd"/></svg>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="submitForm.errors.rows" class="text-rose-400 text-xs">{{ submitForm.errors.rows }}</p>
                        <div class="flex justify-between pt-2">
                            <button @click="step = 2" class="px-4 py-2 text-slate-400 hover:text-slate-200 text-sm">← Kembali</button>
                            <button @click="submitImport" :disabled="submitForm.processing || validRowCount === 0"
                                class="px-5 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20">
                                {{ submitForm.processing ? 'Mengimpor...' : `✅ Import ${validRowCount} Kavling` }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

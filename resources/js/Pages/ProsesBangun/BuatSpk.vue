<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

const props = defineProps({
    project: Object,
    kavlings: Array,
    kontraktors: Array,
    klusterOptions: Array,
    blokOptions: Array,
    tipeUnitOptions: Array,
    statusBangunStages: Array,
});

const form = useForm({
    nomor_spk: '',
    kontraktor_id: '',
    tanggal_terbit: new Date().toISOString().slice(0, 10),
    tanggal_deadline: '',
    catatan: '',
    kavling_ids: [],
});

// ── Filter unit di dalam picker — sama persis filter Proses Bangun (Kluster/
// Blok/Tipe/Status Bangun), tapi lokal ke halaman ini (client-side, tidak
// reload) supaya isian form di atas tidak hilang. Sengaja bukan search bar
// teks bebas lagi — dropdown lebih presisi buat menjaring banyak unit
// sekaligus, dan pakai keduanya cuma redundan.
const pickerFilters = reactive({
    kluster: '', blok: '', tipe_unit_preset_id: '', status_bangun_stage_id: '',
});
const resetPickerFilters = () => {
    pickerFilters.kluster = ''; pickerFilters.blok = '';
    pickerFilters.tipe_unit_preset_id = ''; pickerFilters.status_bangun_stage_id = '';
};
const filteredKavlings = computed(() => props.kavlings.filter(k =>
    (!pickerFilters.kluster || k.kluster === pickerFilters.kluster) &&
    (!pickerFilters.blok || k.blok === pickerFilters.blok) &&
    (!pickerFilters.tipe_unit_preset_id || k.tipe_unit_preset_id === Number(pickerFilters.tipe_unit_preset_id)) &&
    (!pickerFilters.status_bangun_stage_id || k.status_bangun_stage_id === Number(pickerFilters.status_bangun_stage_id))
));

const allVisibleSelected = computed(() =>
    filteredKavlings.value.length > 0 && filteredKavlings.value.every(k => form.kavling_ids.includes(k.id))
);
const toggleSelectAllVisible = () => {
    if (allVisibleSelected.value) {
        const visibleIds = new Set(filteredKavlings.value.map(k => k.id));
        form.kavling_ids = form.kavling_ids.filter(id => !visibleIds.has(id));
    } else {
        const merged = new Set([...form.kavling_ids, ...filteredKavlings.value.map(k => k.id)]);
        form.kavling_ids = [...merged];
    }
};

const submit = () => {
    form.post(route('spk.store', props.project.id));
};
</script>

<template>
    <Head :title="`Buat SPK – ${project.nama}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <Link :href="route('proses-bangun.index', project.id)" class="hover:text-slate-200 transition-colors">Proses Bangun</Link>
                <span>/</span>
                <span class="text-slate-200 font-medium">Buat SPK — {{ project.nama }}</span>
            </div>
        </template>

        <div class="p-6 max-w-4xl space-y-6">
            <div>
                <h1 class="text-white text-xl font-bold">Buat SPK</h1>
                <p class="text-slate-400 text-sm mt-0.5">Terbitkan Surat Perintah Kerja untuk satu atau beberapa unit sekaligus.</p>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Nomor SPK <span class="text-rose-400">*</span></label>
                        <input v-model="form.nomor_spk" type="text" placeholder="mis. 012/SPK-GS3/IX/2026"
                            class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm font-mono focus:outline-none focus:ring-1 focus:ring-violet-500"
                            :class="{ 'border-rose-500': form.errors.nomor_spk }" />
                        <p v-if="form.errors.nomor_spk" class="text-rose-400 text-xs mt-1">{{ form.errors.nomor_spk }}</p>
                    </div>
                    <div>
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Kontraktor <span class="text-rose-400">*</span></label>
                        <select v-model="form.kontraktor_id"
                            class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                            :class="{ 'border-rose-500': form.errors.kontraktor_id }">
                            <option value="" disabled>Pilih kontraktor...</option>
                            <option v-for="k in kontraktors" :key="k.id" :value="k.id">{{ k.nama }}</option>
                        </select>
                        <p v-if="form.errors.kontraktor_id" class="text-rose-400 text-xs mt-1">{{ form.errors.kontraktor_id }}</p>
                        <p v-if="!kontraktors.length" class="text-amber-400 text-xs mt-1">
                            Belum ada kontraktor — <Link :href="route('pengaturan.kontraktor')" class="underline">tambah dulu di Pengaturan</Link>.
                        </p>
                    </div>
                    <div>
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Tanggal Terbit <span class="text-rose-400">*</span></label>
                        <input v-model="form.tanggal_terbit" type="date"
                            class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                            :class="{ 'border-rose-500': form.errors.tanggal_terbit }" />
                        <p v-if="form.errors.tanggal_terbit" class="text-rose-400 text-xs mt-1">{{ form.errors.tanggal_terbit }}</p>
                    </div>
                    <div>
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Tanggal Deadline <span class="text-rose-400">*</span></label>
                        <input v-model="form.tanggal_deadline" type="date"
                            class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                            :class="{ 'border-rose-500': form.errors.tanggal_deadline }" />
                        <p v-if="form.errors.tanggal_deadline" class="text-rose-400 text-xs mt-1">{{ form.errors.tanggal_deadline }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Catatan (opsional)</label>
                        <textarea v-model="form.catatan" rows="2"
                            class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 resize-none" />
                    </div>
                </div>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-800 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-slate-200 font-medium text-sm">Pilih Unit</h2>
                            <p class="text-slate-500 text-xs mt-0.5">{{ form.kavling_ids.length }} unit terpilih</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <select v-model="pickerFilters.kluster"
                            class="px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                            <option value="">Semua Kluster</option>
                            <option v-for="k in klusterOptions" :key="k" :value="k">{{ k }}</option>
                        </select>
                        <select v-model="pickerFilters.blok"
                            class="px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                            <option value="">Semua Blok</option>
                            <option v-for="b in blokOptions" :key="b" :value="b">{{ b }}</option>
                        </select>
                        <select v-model="pickerFilters.tipe_unit_preset_id"
                            class="px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                            <option value="">Semua Tipe</option>
                            <option v-for="t in tipeUnitOptions" :key="t.id" :value="t.id">{{ t.nama }}</option>
                        </select>
                        <select v-model="pickerFilters.status_bangun_stage_id"
                            class="px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                            <option value="">Semua Status Bangun</option>
                            <option v-for="s in statusBangunStages" :key="s.id" :value="s.id">{{ s.nama }}</option>
                        </select>
                        <button v-if="pickerFilters.kluster || pickerFilters.blok || pickerFilters.tipe_unit_preset_id || pickerFilters.status_bangun_stage_id"
                            @click="resetPickerFilters" type="button"
                            class="px-2.5 py-1.5 text-slate-400 hover:text-slate-200 text-xs rounded-lg transition-colors">
                            Reset
                        </button>
                    </div>
                </div>
                <p v-if="form.errors.kavling_ids" class="text-rose-400 text-xs px-5 pt-3">{{ form.errors.kavling_ids }}</p>

                <div class="px-5 py-2 border-b border-slate-800/60">
                    <label class="flex items-center gap-2 text-xs text-slate-400 cursor-pointer">
                        <input type="checkbox" :checked="allVisibleSelected" @change="toggleSelectAllVisible"
                            class="rounded bg-slate-800 border-slate-700 text-violet-600 focus:ring-violet-500" />
                        Pilih semua yang tampil ({{ filteredKavlings.length }})
                    </label>
                </div>

                <div class="max-h-96 overflow-y-auto">
                    <label v-for="k in filteredKavlings" :key="k.id"
                        class="flex items-center gap-3 px-5 py-2.5 border-b border-slate-800/40 last:border-b-0 hover:bg-slate-800/30 cursor-pointer">
                        <input type="checkbox" :value="k.id" v-model="form.kavling_ids"
                            class="rounded bg-slate-800 border-slate-700 text-violet-600 focus:ring-violet-500" />
                        <span class="text-slate-200 text-sm font-medium">{{ k.nomor_lengkap }}</span>
                        <span class="text-slate-500 text-xs">{{ k.tipe_unit_nama ?? '-' }}</span>
                        <span v-if="k.kluster" class="text-slate-600 text-xs ml-auto">{{ k.kluster }}</span>
                    </label>
                    <div v-if="!filteredKavlings.length" class="px-5 py-8 text-center text-slate-600 text-sm">
                        Tidak ada unit yang cocok.
                    </div>
                </div>
            </div>

            <div class="flex gap-3 justify-end">
                <Link :href="route('proses-bangun.index', project.id)"
                    class="px-4 py-2.5 text-slate-400 hover:text-slate-200 text-sm border border-slate-700 rounded-lg transition-colors">
                    Batal
                </Link>
                <button @click="submit" :disabled="form.processing"
                    class="px-5 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 disabled:opacity-60 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20">
                    {{ form.processing ? 'Menerbitkan...' : 'Terbitkan SPK' }}
                </button>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

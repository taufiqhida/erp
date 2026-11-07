<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

// Form SPK Unit untuk Buat dan Edit. Satu unit hanya boleh di satu SPK: unit yang sudah dipakai SPK LAIN ditampilkan abu-abu dan tidak
// bisa dipilih (server juga menolak). Pada Edit, unit milik SPK ini tetap bisa dicentang/dikeluarkan, dan unit bebas bisa ditambah.
const props = defineProps({
    project: Object,
    spk: { type: Object, default: null },
    kavlings: Array,
    kontraktors: Array,
    klusterOptions: Array,
    blokOptions: Array,
    tipeUnitOptions: Array,
    statusBangunStages: Array,
});

const edit = computed(() => !!props.spk);

const form = useForm({
    nomor_spk: props.spk?.nomor_spk ?? '',
    kontraktor_id: props.spk?.kontraktor_id ?? '',
    tanggal_terbit: props.spk?.tanggal_terbit ?? new Date().toISOString().slice(0, 10),
    tanggal_deadline: props.spk?.tanggal_deadline ?? '',
    nilai_spk: props.spk?.nilai_spk ?? '',
    catatan: props.spk?.catatan ?? '',
    kavling_ids: [...(props.spk?.kavling_ids ?? [])],
});

const pickerFilters = reactive({ kluster: '', blok: '', tipe_unit_preset_id: '', status_bangun_stage_id: '' });
const adaFilter = computed(() => Object.values(pickerFilters).some(Boolean));
const resetPickerFilters = () => { Object.keys(pickerFilters).forEach(k => { pickerFilters[k] = ''; }); };
const filteredKavlings = computed(() => props.kavlings.filter(k =>
    (!pickerFilters.kluster || k.kluster === pickerFilters.kluster) &&
    (!pickerFilters.blok || k.blok === pickerFilters.blok) &&
    (!pickerFilters.tipe_unit_preset_id || k.tipe_unit_preset_id === Number(pickerFilters.tipe_unit_preset_id)) &&
    (!pickerFilters.status_bangun_stage_id || k.status_bangun_stage_id === Number(pickerFilters.status_bangun_stage_id))
));

// "Pilih semua yang tampil" hanya menyentuh unit yang boleh dipilih (belum dipakai SPK lain).
const bisaDipilih = computed(() => filteredKavlings.value.filter(k => !k.spk_lain_nomor));
const allVisibleSelected = computed(() => bisaDipilih.value.length > 0 && bisaDipilih.value.every(k => form.kavling_ids.includes(k.id)));
const toggleSelectAllVisible = () => {
    const ids = new Set(bisaDipilih.value.map(k => k.id));
    if (allVisibleSelected.value) {
        form.kavling_ids = form.kavling_ids.filter(id => !ids.has(id));
    } else {
        form.kavling_ids = [...new Set([...form.kavling_ids, ...ids])];
    }
};

const terpakaiLain = computed(() => props.kavlings.filter(k => k.spk_lain_nomor).length);
// Pada Edit: unit yang dikeluarkan dari SPK ini. Nilai SPK tidak berubah otomatis, jadi diingatkan untuk memeriksanya.
const dikeluarkan = computed(() => (props.spk?.kavling_ids ?? []).filter(id => !form.kavling_ids.includes(id)).length);
const ditambah = computed(() => form.kavling_ids.filter(id => !(props.spk?.kavling_ids ?? []).includes(id)).length);

const submit = () => {
    if (edit.value) form.put(route('spk.update', [props.project.id, props.spk.id]));
    else form.post(route('spk.store', props.project.id));
};
const inputCls = 'w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500';
const filterCls = 'px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500';
</script>

<template>
    <Head :title="`${edit ? 'Edit' : 'Buat'} SPK Unit – ${project.nama}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <Link :href="route('spk.index', project.id)" class="hover:text-slate-200 transition-colors">SPK Unit</Link>
                <span>/</span>
                <span class="text-slate-200 font-medium">{{ edit ? 'Edit SPK' : 'Buat SPK' }} — {{ project.nama }}</span>
            </div>
        </template>

        <div class="p-6 max-w-4xl space-y-6">
            <div>
                <h1 class="text-white text-xl font-bold">{{ edit ? 'Edit SPK' : 'Buat SPK' }}</h1>
                <p class="text-slate-400 text-sm mt-0.5">
                    <template v-if="edit">Revisi data SPK, tambah unit yang belum punya SPK, atau keluarkan unit yang salah dimasukkan.</template>
                    <template v-else>Terbitkan Surat Perintah Kerja untuk satu atau beberapa unit sekaligus. Satu unit hanya boleh berada di satu SPK.</template>
                </p>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Nomor SPK <span class="text-rose-400">*</span></label>
                        <input v-model="form.nomor_spk" type="text" placeholder="mis. 012/SPK-GS3/IX/2026" :class="[inputCls, 'font-mono', { 'border-rose-500': form.errors.nomor_spk }]" />
                        <p v-if="form.errors.nomor_spk" class="text-rose-400 text-xs mt-1">{{ form.errors.nomor_spk }}</p>
                    </div>
                    <div>
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Kontraktor <span class="text-rose-400">*</span></label>
                        <select v-model="form.kontraktor_id" :class="[inputCls, { 'border-rose-500': form.errors.kontraktor_id }]">
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
                        <input v-model="form.tanggal_terbit" type="date" :class="[inputCls, { 'border-rose-500': form.errors.tanggal_terbit }]" />
                        <p v-if="form.errors.tanggal_terbit" class="text-rose-400 text-xs mt-1">{{ form.errors.tanggal_terbit }}</p>
                    </div>
                    <div>
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Tanggal Deadline <span class="text-rose-400">*</span></label>
                        <input v-model="form.tanggal_deadline" type="date" :class="[inputCls, { 'border-rose-500': form.errors.tanggal_deadline }]" />
                        <p v-if="form.errors.tanggal_deadline" class="text-rose-400 text-xs mt-1">{{ form.errors.tanggal_deadline }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Nilai SPK (Rp) <span class="text-rose-400">*</span>
                            <span class="text-slate-500 font-normal"> · satu angka untuk seluruh SPK</span></label>
                        <MoneyInput v-model="form.nilai_spk" placeholder="mis. 450.000.000" :class="[inputCls, 'sm:max-w-xs text-right', { 'border-rose-500': form.errors.nilai_spk }]" />
                        <p v-if="form.errors.nilai_spk" class="text-rose-400 text-xs mt-1">{{ form.errors.nilai_spk }}</p>
                        <p v-if="edit && dikeluarkan > 0" class="text-amber-400 text-xs mt-1">
                            {{ dikeluarkan }} unit dikeluarkan — nilai SPK tidak berubah otomatis, periksa apakah perlu disesuaikan.
                        </p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Catatan (opsional)</label>
                        <textarea v-model="form.catatan" rows="2" :class="[inputCls, 'resize-none']" />
                    </div>
                </div>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-800 space-y-3">
                    <div>
                        <h2 class="text-slate-200 font-medium text-sm">Pilih Unit</h2>
                        <p class="text-slate-500 text-xs mt-0.5">
                            {{ form.kavling_ids.length }} unit terpilih<template v-if="edit && (ditambah || dikeluarkan)"> · {{ ditambah }} ditambah, {{ dikeluarkan }} dikeluarkan</template>
                            <template v-if="terpakaiLain"> · {{ terpakaiLain }} unit sudah di SPK lain (tidak bisa dipilih)</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <select v-model="pickerFilters.kluster" :class="filterCls">
                            <option value="">Semua Kluster</option>
                            <option v-for="k in klusterOptions" :key="k" :value="k">{{ k }}</option>
                        </select>
                        <select v-model="pickerFilters.blok" :class="filterCls">
                            <option value="">Semua Blok</option>
                            <option v-for="b in blokOptions" :key="b" :value="b">{{ b }}</option>
                        </select>
                        <select v-model="pickerFilters.tipe_unit_preset_id" :class="filterCls">
                            <option value="">Semua Tipe</option>
                            <option v-for="t in tipeUnitOptions" :key="t.id" :value="t.id">{{ t.nama }}</option>
                        </select>
                        <select v-model="pickerFilters.status_bangun_stage_id" :class="filterCls">
                            <option value="">Semua Status Bangun</option>
                            <option v-for="s in statusBangunStages" :key="s.id" :value="s.id">{{ s.nama }}</option>
                        </select>
                        <button v-if="adaFilter" @click="resetPickerFilters" type="button" class="px-2.5 py-1.5 text-slate-400 hover:text-slate-200 text-xs rounded-lg transition-colors">Reset</button>
                    </div>
                </div>
                <p v-if="form.errors.kavling_ids" class="text-rose-400 text-xs px-5 pt-3">{{ form.errors.kavling_ids }}</p>

                <div class="px-5 py-2 border-b border-slate-800/60">
                    <label class="flex items-center gap-2 text-xs text-slate-400 cursor-pointer">
                        <input type="checkbox" :checked="allVisibleSelected" @change="toggleSelectAllVisible"
                            class="rounded bg-slate-800 border-slate-700 text-violet-600 focus:ring-violet-500" />
                        Pilih semua yang tampil dan bebas ({{ bisaDipilih.length }})
                    </label>
                </div>

                <div class="max-h-96 overflow-y-auto">
                    <label v-for="k in filteredKavlings" :key="k.id"
                        :class="k.spk_lain_nomor ? 'opacity-50 cursor-not-allowed' : 'hover:bg-slate-800/30 cursor-pointer'"
                        class="flex items-center gap-3 px-5 py-2.5 border-b border-slate-800/40 last:border-b-0">
                        <input type="checkbox" :value="k.id" v-model="form.kavling_ids" :disabled="!!k.spk_lain_nomor"
                            class="rounded bg-slate-800 border-slate-700 text-violet-600 focus:ring-violet-500 disabled:opacity-60" />
                        <span class="text-slate-200 text-sm font-medium">{{ k.nomor_lengkap }}</span>
                        <span class="text-slate-500 text-xs">{{ k.tipe_unit_nama ?? '-' }}</span>
                        <span v-if="k.spk_lain_nomor" class="ml-auto text-[0.6875rem] px-2 py-0.5 rounded-full bg-slate-800 text-slate-400">sudah di SPK {{ k.spk_lain_nomor }}</span>
                        <span v-else-if="k.kluster" class="text-slate-500 text-xs ml-auto">{{ k.kluster }}</span>
                    </label>
                    <div v-if="!filteredKavlings.length" class="px-5 py-8 text-center text-slate-500 text-sm">Tidak ada unit yang cocok.</div>
                </div>
            </div>

            <div class="flex gap-3 justify-end">
                <Link :href="route('spk.index', project.id)" class="px-4 py-2.5 text-slate-400 hover:text-slate-200 text-sm border border-slate-700 rounded-lg transition-colors">Batal</Link>
                <button @click="submit" :disabled="form.processing"
                    class="px-5 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 disabled:opacity-60 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20">
                    {{ form.processing ? 'Menyimpan...' : (edit ? 'Simpan Perubahan' : 'Terbitkan SPK') }}
                </button>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

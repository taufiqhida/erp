<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    projects:           Array,
    pembiayaans:        Object,
    defaultKprSubsidi:  Array,
});

const selectedProjectId = ref(props.projects[0]?.id ?? null);

const currentPembiayaan = computed(() => {
    if (!selectedProjectId.value) return null;
    return props.pembiayaans[selectedProjectId.value] ?? { kpr_subsidi_config: props.defaultKprSubsidi };
});

const form = useForm({
    kpr_subsidi_config: [],
    kpr_komersil_config: [],
});

// Init form when project changes
const initForm = () => {
    const p = currentPembiayaan.value;
    form.kpr_subsidi_config = JSON.parse(JSON.stringify(
        p?.kpr_subsidi_config ?? props.defaultKprSubsidi
    ));
    form.kpr_komersil_config = JSON.parse(JSON.stringify(
        p?.kpr_komersil_config ?? []
    ));
};

// Watch for project selection
initForm();
const selectProject = (id) => {
    selectedProjectId.value = id;
    initForm();
};

const addKomponenKomersil = () => {
    form.kpr_komersil_config.push({ key: `komponen_${Date.now()}`, label: '', jumlah: 0 });
};
const removeKomponenKomersil = (idx) => {
    form.kpr_komersil_config.splice(idx, 1);
};

const save = () => {
    form.patch(route('pengaturan.pembiayaan.update', selectedProjectId.value), {
        onSuccess: () => {},
    });
};

const formatRp = (v) => v ? 'Rp ' + Number(v).toLocaleString('id-ID') : 'Rp 0';

const totalSubsidi = computed(() =>
    form.kpr_subsidi_config.reduce((sum, c) => sum + (Number(c.jumlah) || 0), 0)
);
</script>

<template>
    <Head title="Pengaturan – Pembiayaan Proyek" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <Link :href="route('pengaturan.profil-developer')" class="hover:text-slate-200 transition-colors">Pengaturan</Link>
                <span>/</span>
                <span class="text-slate-200 font-medium">Pembiayaan Proyek</span>
            </div>
        </template>

        <div class="p-6 space-y-5">
            <div>
                <h1 class="text-white font-bold text-xl">Pengaturan Pembiayaan Proyek</h1>
                <p class="text-slate-400 text-sm mt-0.5">Komponen biaya KPR akan otomatis muncul di form booking sales</p>
            </div>

            <div class="grid grid-cols-12 gap-6">
                <!-- Project List -->
                <div class="col-span-12 sm:col-span-3 space-y-1">
                    <div class="text-slate-500 text-xs font-semibold uppercase tracking-wider px-2 mb-2">Pilih Proyek</div>
                    <button v-for="p in projects" :key="p.id"
                        @click="selectProject(p.id)"
                        :class="selectedProjectId === p.id ? 'bg-violet-600/15 text-violet-300 border-violet-500/30' : 'text-slate-400 border-transparent hover:text-slate-200 hover:bg-slate-800'"
                        class="w-full text-left px-3 py-2.5 rounded-lg text-sm border transition-all">
                        <div class="font-medium">{{ p.nama }}</div>
                        <div class="text-xs opacity-60 mt-0.5">{{ p.kode }}</div>
                    </button>
                </div>

                <!-- Form Area -->
                <div class="col-span-12 sm:col-span-9 space-y-5">
                    <div v-if="!selectedProjectId" class="text-center py-12 text-slate-600 text-sm">
                        Pilih proyek untuk mengatur pembiayaan
                    </div>
                    <div v-else>
                        <!-- KPR Subsidi -->
                        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden mb-5">
                            <div class="flex items-center justify-between px-5 py-3 border-b border-slate-800">
                                <h3 class="text-white font-semibold">Komponen KPR Subsidi</h3>
                                <span class="text-violet-400 font-medium text-sm">Total: {{ formatRp(totalSubsidi) }}</span>
                            </div>
                            <div class="divide-y divide-slate-800">
                                <div v-for="(comp, idx) in form.kpr_subsidi_config" :key="idx"
                                    class="flex items-center gap-4 px-5 py-3">
                                    <div class="flex-1 text-slate-300 text-sm font-medium">{{ comp.label }}</div>
                                    <div class="relative">
                                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs">Rp</span>
                                        <input v-model.number="comp.jumlah" type="number" min="0"
                                            class="w-36 pl-7 pr-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm text-right focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- KPR Komersil -->
                        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden mb-5">
                            <div class="flex items-center justify-between px-5 py-3 border-b border-slate-800">
                                <h3 class="text-white font-semibold">Komponen KPR Komersil</h3>
                                <button @click="addKomponenKomersil" class="text-xs text-violet-400 hover:text-violet-300 transition-colors">+ Tambah Komponen</button>
                            </div>
                            <div v-if="!form.kpr_komersil_config.length" class="px-5 py-5 text-center text-slate-600 text-sm">
                                Belum ada komponen. Klik "+ Tambah Komponen" untuk menambah.
                            </div>
                            <div class="divide-y divide-slate-800">
                                <div v-for="(comp, idx) in form.kpr_komersil_config" :key="idx"
                                    class="flex items-center gap-3 px-5 py-3">
                                    <input v-model="comp.label" type="text" placeholder="Nama komponen"
                                        class="flex-1 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                    <div class="relative">
                                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs">Rp</span>
                                        <input v-model.number="comp.jumlah" type="number" min="0"
                                            class="w-36 pl-7 pr-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm text-right focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                    </div>
                                    <button @click="removeKomponenKomersil(idx)" class="text-rose-400 hover:text-rose-300 transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button @click="save" :disabled="form.processing"
                                class="px-5 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 disabled:opacity-60 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20">
                                {{ form.processing ? 'Menyimpan...' : '💾 Simpan Pembiayaan' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

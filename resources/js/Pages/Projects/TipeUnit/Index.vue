<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    project:   Object,
    tipeUnits: Array,
});

const formatRp = (v) => v ? 'Rp ' + Number(v).toLocaleString('id-ID') : '-';

// ── Tambah Tipe ────────────────────────────────────────────────────────
const showAddModal = ref(false);
const addForm = useForm({
    nama: '', luas_tanah: '', luas_bangunan: '', kamar_tidur: '', kamar_mandi: '',
    spek_atap: '', spek_dinding: '', spek_lantai: '', spek_pondasi: '',
});

const openAdd = () => {
    addForm.reset();
    addForm.clearErrors();
    showAddModal.value = true;
};

const submitAdd = () => {
    addForm.post(route('projects.tipe-unit.store', props.project.id), {
        onSuccess: () => { showAddModal.value = false; },
    });
};

// ── Edit Tipe ──────────────────────────────────────────────────────────
const showEditModal = ref(false);
const editingTipe = ref(null);
const editForm = useForm({
    nama: '', luas_tanah: '', luas_bangunan: '', kamar_tidur: '', kamar_mandi: '',
    spek_atap: '', spek_dinding: '', spek_lantai: '', spek_pondasi: '', is_active: true,
});

const openEdit = (t) => {
    editForm.clearErrors();
    editForm.nama = t.nama;
    editForm.luas_tanah = t.luas_tanah ?? '';
    editForm.luas_bangunan = t.luas_bangunan ?? '';
    editForm.kamar_tidur = t.kamar_tidur ?? '';
    editForm.kamar_mandi = t.kamar_mandi ?? '';
    editForm.spek_atap = t.spek_atap ?? '';
    editForm.spek_dinding = t.spek_dinding ?? '';
    editForm.spek_lantai = t.spek_lantai ?? '';
    editForm.spek_pondasi = t.spek_pondasi ?? '';
    editForm.is_active = t.is_active;
    editingTipe.value = t;
    showEditModal.value = true;
};

const submitEdit = () => {
    editForm.transform(data => ({ ...data, is_active: data.is_active ? 1 : 0 }))
        .put(route('tipe-unit.update', editingTipe.value.id), {
            onSuccess: () => { showEditModal.value = false; },
        });
};

const deleteTipe = (t) => {
    const msg = t.kavlings_count > 0
        ? `Tipe "${t.nama}" masih dipakai ${t.kavlings_count} unit — akan dinonaktifkan (bukan dihapus). Lanjutkan?`
        : `Hapus Tipe "${t.nama}"? Belum ada unit yang pakai, jadi ini akan dihapus permanen.`;
    if (!confirm(msg)) return;
    useForm({}).delete(route('tipe-unit.destroy', t.id), { preserveScroll: true });
};

// ── Upload foto/denah ─────────────────────────────────────────────────
const uploadForm = useForm({ tipe: '', gambar: null });
const uploadingFor = ref(null); // { id, tipe }

const handleFileChange = (e, tipeUnit, fieldTipe) => {
    const file = e.target.files[0];
    if (!file) return;
    uploadForm.tipe = fieldTipe;
    uploadForm.gambar = file;
    uploadingFor.value = { id: tipeUnit.id, tipe: fieldTipe };
    uploadForm.post(route('tipe-unit.upload-gambar', tipeUnit.id), {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => { uploadingFor.value = null; uploadForm.reset(); },
    });
};
</script>

<template>
    <Head :title="`Tipe Unit – ${project.nama}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <Link :href="route('beranda')" class="hover:text-slate-200 transition-colors">Proyek</Link>
                <span>/</span>
                <Link :href="route('projects.show', project.id)" class="hover:text-slate-200 transition-colors">{{ project.nama }}</Link>
                <span>/</span>
                <span class="text-slate-200 font-medium">Tipe Unit</span>
            </div>
        </template>

        <div class="p-6 space-y-5">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-white font-bold text-xl">Tipe Unit – {{ project.nama }}</h1>
                    <p class="text-slate-400 text-sm mt-0.5">Master spesifikasi standar (luas, spek, foto fasad &amp; denah) per tipe rumah di proyek ini.</p>
                </div>
                <button @click="openAdd"
                    class="inline-flex items-center gap-2 px-3 py-2 bg-violet-600/20 hover:bg-violet-600/30 text-violet-300 text-xs font-medium rounded-lg transition-colors border border-violet-500/20">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z"/></svg>
                    Tambah Tipe
                </button>
            </div>

            <div v-if="!tipeUnits.length" class="bg-slate-900 border border-dashed border-slate-700 rounded-2xl p-16 text-center text-slate-500">
                Belum ada Tipe Unit. Klik "Tambah Tipe" untuk mulai — setelah ada, kamu tidak perlu isi spek manual lagi tiap tambah unit.
            </div>

            <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div v-for="t in tipeUnits" :key="t.id"
                    class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden"
                    :class="{ 'opacity-50': !t.is_active }">
                    <!-- Foto fasad -->
                    <div class="aspect-video bg-slate-800 relative">
                        <img v-if="t.foto_rumah" :src="t.foto_rumah" class="w-full h-full object-cover" />
                        <div v-else class="w-full h-full flex items-center justify-center text-slate-600 text-xs">Belum ada foto fasad</div>
                        <label class="absolute bottom-2 right-2 px-2 py-1 bg-black/60 hover:bg-black/80 text-white text-[10px] rounded cursor-pointer transition-colors">
                            <span v-if="uploadingFor?.id === t.id && uploadingFor?.tipe === 'foto_rumah'">Mengupload...</span>
                            <span v-else>📷 Upload Foto</span>
                            <input type="file" accept="image/*" class="hidden" @change="handleFileChange($event, t, 'foto_rumah')" />
                        </label>
                        <span v-if="!t.is_active" class="absolute top-2 left-2 px-2 py-0.5 bg-slate-700 text-slate-300 text-[10px] rounded-full font-medium">Nonaktif</span>
                    </div>

                    <div class="p-4 space-y-3">
                        <div class="flex items-center justify-between">
                            <h3 class="text-slate-200 font-semibold">{{ t.nama }}</h3>
                            <span class="text-slate-500 text-xs">{{ t.kavlings_count }} unit</span>
                        </div>

                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div class="text-slate-500">Luas Tanah <span class="text-slate-300">{{ t.luas_tanah ?? '-' }} m²</span></div>
                            <div class="text-slate-500">Luas Bangunan <span class="text-slate-300">{{ t.luas_bangunan ?? '-' }} m²</span></div>
                            <div class="text-slate-500">Kamar Tidur <span class="text-slate-300">{{ t.kamar_tidur ?? '-' }}</span></div>
                            <div class="text-slate-500">Kamar Mandi <span class="text-slate-300">{{ t.kamar_mandi ?? '-' }}</span></div>
                        </div>

                        <div v-if="t.spek_atap || t.spek_dinding || t.spek_lantai || t.spek_pondasi" class="text-xs text-slate-500 space-y-0.5 pt-1 border-t border-slate-800/60">
                            <div v-if="t.spek_atap">Atap: <span class="text-slate-400">{{ t.spek_atap }}</span></div>
                            <div v-if="t.spek_dinding">Dinding: <span class="text-slate-400">{{ t.spek_dinding }}</span></div>
                            <div v-if="t.spek_lantai">Lantai: <span class="text-slate-400">{{ t.spek_lantai }}</span></div>
                            <div v-if="t.spek_pondasi">Pondasi: <span class="text-slate-400">{{ t.spek_pondasi }}</span></div>
                        </div>

                        <div class="flex items-center justify-between pt-1">
                            <label class="text-xs text-violet-400 hover:text-violet-300 cursor-pointer transition-colors">
                                <span v-if="uploadingFor?.id === t.id && uploadingFor?.tipe === 'denah_rumah'">Mengupload...</span>
                                <span v-else>{{ t.denah_rumah ? '📐 Ganti Denah' : '📐 Upload Denah' }}</span>
                                <input type="file" accept="image/*" class="hidden" @change="handleFileChange($event, t, 'denah_rumah')" />
                            </label>
                            <div class="flex items-center gap-1.5">
                                <button @click="openEdit(t)" title="Edit"
                                    class="p-1.5 text-slate-400 hover:text-amber-400 hover:bg-amber-400/10 rounded-lg transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                </button>
                                <button @click="deleteTipe(t)" title="Hapus/Nonaktifkan"
                                    class="p-1.5 text-slate-400 hover:text-rose-400 hover:bg-rose-400/10 rounded-lg transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODAL: TAMBAH TIPE -->
        <Teleport to="body">
            <div v-if="showAddModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="showAddModal = false" />
                <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md shadow-2xl max-h-[90vh] overflow-y-auto">
                    <div class="flex items-center justify-between p-5 border-b border-slate-800">
                        <h3 class="text-white font-semibold">Tambah Tipe Unit</h3>
                        <button @click="showAddModal = false" class="text-slate-500 hover:text-slate-300">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                        </button>
                    </div>
                    <form @submit.prevent="submitAdd" class="p-5 space-y-4">
                        <div>
                            <label class="block text-slate-400 text-xs mb-1.5">Nama Tipe <span class="text-rose-400">*</span></label>
                            <input v-model="addForm.nama" type="text" placeholder="cth. Sakura" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" :class="{ 'border-rose-500': addForm.errors.nama }" />
                            <p v-if="addForm.errors.nama" class="text-rose-400 text-xs mt-1">{{ addForm.errors.nama }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Luas Tanah (m²)</label>
                                <input v-model="addForm.luas_tanah" type="number" step="0.01" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Luas Bangunan (m²)</label>
                                <input v-model="addForm.luas_bangunan" type="number" step="0.01" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Kamar Tidur</label>
                                <input v-model="addForm.kamar_tidur" type="number" min="0" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Kamar Mandi</label>
                                <input v-model="addForm.kamar_mandi" type="number" min="0" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Spek Atap</label>
                                <input v-model="addForm.spek_atap" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Spek Dinding</label>
                                <input v-model="addForm.spek_dinding" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Spek Lantai</label>
                                <input v-model="addForm.spek_lantai" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Spek Pondasi</label>
                                <input v-model="addForm.spek_pondasi" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                        </div>
                        <p class="text-slate-600 text-xs">Foto fasad &amp; denah bisa diupload setelah tipe dibuat.</p>
                        <div class="flex justify-end gap-3">
                            <button type="button" @click="showAddModal = false" class="px-4 py-2.5 text-slate-400 text-sm">Batal</button>
                            <button type="submit" :disabled="addForm.processing" class="px-4 py-2.5 bg-violet-600 hover:bg-violet-500 text-white text-sm font-medium rounded-lg transition-colors">
                                {{ addForm.processing ? 'Menyimpan...' : 'Simpan' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- MODAL: EDIT TIPE -->
        <Teleport to="body">
            <div v-if="showEditModal && editingTipe" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="showEditModal = false" />
                <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md shadow-2xl max-h-[90vh] overflow-y-auto">
                    <div class="flex items-center justify-between p-5 border-b border-slate-800">
                        <h3 class="text-white font-semibold">Edit Tipe Unit</h3>
                        <button @click="showEditModal = false" class="text-slate-500 hover:text-slate-300">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                        </button>
                    </div>
                    <form @submit.prevent="submitEdit" class="p-5 space-y-4">
                        <div>
                            <label class="block text-slate-400 text-xs mb-1.5">Nama Tipe <span class="text-rose-400">*</span></label>
                            <input v-model="editForm.nama" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" :class="{ 'border-rose-500': editForm.errors.nama }" />
                            <p v-if="editForm.errors.nama" class="text-rose-400 text-xs mt-1">{{ editForm.errors.nama }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Luas Tanah (m²)</label>
                                <input v-model="editForm.luas_tanah" type="number" step="0.01" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Luas Bangunan (m²)</label>
                                <input v-model="editForm.luas_bangunan" type="number" step="0.01" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Kamar Tidur</label>
                                <input v-model="editForm.kamar_tidur" type="number" min="0" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Kamar Mandi</label>
                                <input v-model="editForm.kamar_mandi" type="number" min="0" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Spek Atap</label>
                                <input v-model="editForm.spek_atap" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Spek Dinding</label>
                                <input v-model="editForm.spek_dinding" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Spek Lantai</label>
                                <input v-model="editForm.spek_lantai" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5">Spek Pondasi</label>
                                <input v-model="editForm.spek_pondasi" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"/>
                            </div>
                        </div>
                        <label class="flex items-center gap-2.5 px-3 py-2.5 bg-slate-800/60 rounded-lg cursor-pointer hover:bg-slate-800 transition-colors">
                            <input type="checkbox" v-model="editForm.is_active" class="w-4 h-4 accent-emerald-500 rounded" />
                            <span class="text-slate-300 text-sm">Aktif (muncul sebagai pilihan saat tambah unit baru)</span>
                        </label>
                        <div class="flex justify-end gap-3">
                            <button type="button" @click="showEditModal = false" class="px-4 py-2.5 text-slate-400 text-sm">Batal</button>
                            <button type="submit" :disabled="editForm.processing" class="px-4 py-2.5 bg-violet-600 hover:bg-violet-500 text-white text-sm font-medium rounded-lg transition-colors">
                                {{ editForm.processing ? 'Menyimpan...' : 'Simpan Perubahan' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>

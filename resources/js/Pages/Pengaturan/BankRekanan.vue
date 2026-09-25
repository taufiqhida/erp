<script setup>
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import UrutanButtons from '@/Components/UrutanButtons.vue';
import { useForm, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = defineProps({
    presets: Array,
});

const addForm = useForm({
    nama: '',
    nama_pt: '',
    kantor_cabang: '',
    keterangan: '',
    alamat: '',
});

const addPreset = () => {
    addForm.post(route('pengaturan.bank-rekanan.store'), {
        onSuccess: () => addForm.reset(),
    });
};

const toggleActive = (preset) => {
    router.patch(route('pengaturan.bank-rekanan.update', preset.id), {
        nama: preset.nama,
        nama_pt: preset.nama_pt,
        kantor_cabang: preset.kantor_cabang,
        keterangan: preset.keterangan,
        alamat: preset.alamat,
        is_active: !preset.is_active,
    }, { preserveScroll: true });
};

// ── Edit (dipakai buat blok "Kepada Yth" di Surat Penawaran Pembiayaan & placeholder surat) ──
const editingId = ref(null);
const editForm = reactive({ nama: '', nama_pt: '', kantor_cabang: '', keterangan: '', alamat: '' });
const openEdit = (preset) => {
    editingId.value = preset.id;
    editForm.nama = preset.nama;
    editForm.nama_pt = preset.nama_pt ?? '';
    editForm.kantor_cabang = preset.kantor_cabang ?? '';
    editForm.keterangan = preset.keterangan ?? '';
    editForm.alamat = preset.alamat ?? '';
};
const saveEdit = (preset) => {
    router.patch(route('pengaturan.bank-rekanan.update', preset.id), {
        nama: editForm.nama,
        nama_pt: editForm.nama_pt,
        kantor_cabang: editForm.kantor_cabang,
        keterangan: editForm.keterangan,
        alamat: editForm.alamat,
        is_active: preset.is_active,
    }, { preserveScroll: true, onSuccess: () => { editingId.value = null; } });
};

const delForm = useForm({});
const destroy = (preset) => {
    if (confirm(`Hapus bank rekanan "${preset.nama}"?`)) {
        delForm.delete(route('pengaturan.bank-rekanan.destroy', preset.id));
    }
};
</script>

<template>
    <PengaturanLayout title="Bank Rekanan KPR">
            <div>
                <h1 class="text-white font-bold text-xl">Bank Rekanan KPR</h1>
                <p class="text-slate-400 text-sm mt-0.5">Daftar bank rekanan KPR (mis. BTN, BRI) yang bisa dipilih staff di tahap Pemberkasan/Proses Bank. Alamat dipakai otomatis saat cetak Surat Penawaran Pembiayaan.</p>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div v-if="presets.length">
                    <div v-for="(preset, idx) in presets" :key="preset.id"
                        class="px-5 py-3 border-b border-slate-800 hover:bg-slate-800/30 transition-colors group"
                        :class="{ 'opacity-50': !preset.is_active }">
                        <div v-if="editingId !== preset.id" class="flex items-center justify-between">
                            <div>
                                <div class="text-slate-200 text-sm font-medium">{{ preset.nama }}</div>
                                <div class="text-slate-500 text-xs mt-0.5">{{ preset.nama_pt || 'Nama PT belum diisi' }}<span v-if="preset.kantor_cabang"> — {{ preset.kantor_cabang }}</span></div>
                                <div class="text-slate-500 text-xs mt-0.5">{{ preset.keterangan ?? '-' }}</div>
                                <div class="text-slate-600 text-xs mt-0.5 whitespace-pre-line">{{ preset.alamat || 'Alamat belum diisi' }}</div>
                            </div>
                            <div class="flex items-center gap-3">
                                <UrutanButtons type="bank-rekanan" :id="preset.id" :first="idx === 0" :last="idx === presets.length - 1" />
                                <button @click="openEdit(preset)"
                                    class="opacity-0 group-hover:opacity-100 px-2 py-1 text-violet-400 hover:bg-violet-500/10 rounded-lg text-xs transition-all">
                                    Edit
                                </button>
                                <button @click="toggleActive(preset)"
                                    :class="preset.is_active ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-700 text-slate-400'"
                                    class="px-2 py-1 rounded-lg text-xs font-medium transition-colors">
                                    {{ preset.is_active ? 'Aktif' : 'Nonaktif' }}
                                </button>
                                <button @click="destroy(preset)"
                                    class="opacity-0 group-hover:opacity-100 px-2 py-1 text-rose-400 hover:bg-rose-500/10 rounded-lg text-xs transition-all">
                                    Hapus
                                </button>
                            </div>
                        </div>
                        <div v-else class="space-y-2">
                            <div class="grid grid-cols-2 gap-2">
                                <input v-model="editForm.nama" type="text" placeholder="Nama bank (singkat, mis. BRI)"
                                    class="px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                <input v-model="editForm.keterangan" type="text" placeholder="Keterangan (opsional)"
                                    class="px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <input v-model="editForm.nama_pt" type="text" placeholder="Nama PT resmi (buat surat)"
                                    class="px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                <input v-model="editForm.kantor_cabang" type="text" placeholder="Kantor cabang"
                                    class="px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            </div>
                            <textarea v-model="editForm.alamat" rows="2" placeholder="Alamat lengkap cabang (buat blok 'Kepada Yth')"
                                class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 resize-none" />
                            <div class="flex justify-end gap-2">
                                <button @click="editingId = null" class="px-3 py-1.5 text-slate-400 hover:text-slate-200 text-xs">Batal</button>
                                <button @click="saveEdit(preset)" class="px-3 py-1.5 bg-violet-600 hover:bg-violet-500 text-white text-xs font-medium rounded-lg transition-colors">Simpan</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="px-5 py-8 text-center text-slate-600 text-sm">
                    Belum ada bank rekanan.
                </div>

                <!-- Add New -->
                <div class="px-5 py-4 border-t border-slate-800 bg-slate-800/20 space-y-2">
                    <div class="flex gap-3">
                        <input v-model="addForm.nama" type="text" placeholder="Nama bank (mis. Bank BTN)"
                            :class="{ 'border-rose-500': addForm.errors.nama }"
                            class="flex-1 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        <input v-model="addForm.keterangan" type="text" placeholder="Keterangan (opsional)"
                            class="flex-1 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                    </div>
                    <div class="flex gap-3">
                        <input v-model="addForm.nama_pt" type="text" placeholder="Nama PT resmi (buat surat, opsional)"
                            class="flex-1 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        <input v-model="addForm.kantor_cabang" type="text" placeholder="Kantor cabang (opsional)"
                            class="flex-1 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                    </div>
                    <textarea v-model="addForm.alamat" rows="2" placeholder="Alamat lengkap cabang (opsional, bisa dilengkapi belakangan)"
                        class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 resize-none" />
                    <div class="flex justify-end">
                        <button @click="addPreset" :disabled="addForm.processing || !addForm.nama"
                            class="px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors whitespace-nowrap">
                            + Tambah
                        </button>
                    </div>
                </div>
            </div>
    </PengaturanLayout>
</template>

<script setup>
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import { useForm, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = defineProps({
    kontraktors: Array,
});

const addForm = useForm({
    nama: '',
    no_hp: '',
    alamat: '',
});

const addKontraktor = () => {
    addForm.post(route('pengaturan.kontraktor.store'), {
        onSuccess: () => addForm.reset(),
    });
};

const toggleActive = (k) => {
    router.patch(route('pengaturan.kontraktor.update', k.id), {
        nama: k.nama,
        no_hp: k.no_hp,
        alamat: k.alamat,
        is_active: !k.is_active,
    }, { preserveScroll: true });
};

const editingId = ref(null);
const editForm = reactive({ nama: '', no_hp: '', alamat: '' });
const openEdit = (k) => {
    editingId.value = k.id;
    editForm.nama = k.nama;
    editForm.no_hp = k.no_hp ?? '';
    editForm.alamat = k.alamat ?? '';
};
const saveEdit = (k) => {
    router.patch(route('pengaturan.kontraktor.update', k.id), {
        nama: editForm.nama,
        no_hp: editForm.no_hp,
        alamat: editForm.alamat,
        is_active: k.is_active,
    }, { preserveScroll: true, onSuccess: () => { editingId.value = null; } });
};

const delForm = useForm({});
const destroy = (k) => {
    if (confirm(`Hapus kontraktor "${k.nama}"?`)) {
        delForm.delete(route('pengaturan.kontraktor.destroy', k.id));
    }
};
</script>

<template>
    <PengaturanLayout title="Kontraktor">
            <div>
                <h1 class="text-white font-bold text-xl">Kontraktor</h1>
                <p class="text-slate-400 text-sm mt-0.5">Daftar kontraktor/vendor pelaksana — dipilih saat menerbitkan SPK di halaman Proses Bangun.</p>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div v-if="kontraktors.length">
                    <div v-for="k in kontraktors" :key="k.id"
                        class="px-5 py-3 border-b border-slate-800 hover:bg-slate-800/30 transition-colors group"
                        :class="{ 'opacity-50': !k.is_active }">
                        <div v-if="editingId !== k.id" class="flex items-center justify-between">
                            <div>
                                <div class="text-slate-200 text-sm font-medium">{{ k.nama }}</div>
                                <div class="text-slate-500 text-xs mt-0.5">{{ k.no_hp ?? '-' }}</div>
                                <div class="text-slate-600 text-xs mt-0.5 whitespace-pre-line">{{ k.alamat || '-' }}</div>
                            </div>
                            <div class="flex items-center gap-3">
                                <button @click="openEdit(k)"
                                    class="opacity-0 group-hover:opacity-100 px-2 py-1 text-violet-400 hover:bg-violet-500/10 rounded-lg text-xs transition-all">
                                    Edit
                                </button>
                                <button @click="toggleActive(k)"
                                    :class="k.is_active ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-700 text-slate-400'"
                                    class="px-2 py-1 rounded-lg text-xs font-medium transition-colors">
                                    {{ k.is_active ? 'Aktif' : 'Nonaktif' }}
                                </button>
                                <button @click="destroy(k)"
                                    class="opacity-0 group-hover:opacity-100 px-2 py-1 text-rose-400 hover:bg-rose-500/10 rounded-lg text-xs transition-all">
                                    Hapus
                                </button>
                            </div>
                        </div>
                        <div v-else class="space-y-2">
                            <div class="grid grid-cols-2 gap-2">
                                <input v-model="editForm.nama" type="text" placeholder="Nama kontraktor"
                                    class="px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                <input v-model="editForm.no_hp" type="text" placeholder="No. HP (opsional)"
                                    class="px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            </div>
                            <textarea v-model="editForm.alamat" rows="2" placeholder="Alamat (opsional)"
                                class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 resize-none" />
                            <div class="flex justify-end gap-2">
                                <button @click="editingId = null" class="px-3 py-1.5 text-slate-400 hover:text-slate-200 text-xs">Batal</button>
                                <button @click="saveEdit(k)" class="px-3 py-1.5 bg-violet-600 hover:bg-violet-500 text-white text-xs font-medium rounded-lg transition-colors">Simpan</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="px-5 py-8 text-center text-slate-600 text-sm">
                    Belum ada kontraktor.
                </div>

                <!-- Add New -->
                <div class="px-5 py-4 border-t border-slate-800 bg-slate-800/20 space-y-2">
                    <div class="flex gap-3">
                        <input v-model="addForm.nama" type="text" placeholder="Nama kontraktor"
                            :class="{ 'border-rose-500': addForm.errors.nama }"
                            class="flex-1 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        <input v-model="addForm.no_hp" type="text" placeholder="No. HP (opsional)"
                            class="flex-1 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                    </div>
                    <textarea v-model="addForm.alamat" rows="2" placeholder="Alamat (opsional)"
                        class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 resize-none" />
                    <div class="flex justify-end">
                        <button @click="addKontraktor" :disabled="addForm.processing || !addForm.nama"
                            class="px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors whitespace-nowrap">
                            + Tambah
                        </button>
                    </div>
                </div>
            </div>
    </PengaturanLayout>
</template>

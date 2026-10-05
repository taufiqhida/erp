<script setup>
import { konfirmasi } from '@/Composables/useConfirm';
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    pengumuman: Array,
});

const hariIni = () => new Date().toISOString().slice(0, 10);
const kosong = () => ({ judul: '', isi: '', tanggal: hariIni(), disematkan: false, aktif: true });

const form = useForm(kosong());
const editingId = ref(null);

const mulaiUbah = (p) => {
    editingId.value = p.id;
    Object.assign(form, { judul: p.judul, isi: p.isi, tanggal: p.tanggal, disematkan: p.disematkan, aktif: p.aktif });
    form.clearErrors();
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

const batalUbah = () => {
    editingId.value = null;
    form.defaults(kosong()).reset();
    form.clearErrors();
};

const simpan = () => {
    const opsi = { preserveScroll: true, onSuccess: batalUbah };
    editingId.value
        ? form.patch(route('pengaturan.pengumuman.update', editingId.value), opsi)
        : form.post(route('pengaturan.pengumuman.store'), opsi);
};

// Sematkan / aktifkan langsung dari daftar (kirim ulang semua isi supaya validasi server tetap utuh).
const ubahCepat = (p, perubahan) => {
    router.patch(route('pengaturan.pengumuman.update', p.id), { ...p, ...perubahan }, { preserveScroll: true });
};

const hapus = async (p) => {
    if (await konfirmasi(`Hapus pengumuman "${p.judul}"?`)) {
        router.delete(route('pengaturan.pengumuman.destroy', p.id), { preserveScroll: true });
    }
};
</script>

<template>
    <PengaturanLayout title="Pengumuman Login">
        <div>
            <h1 class="text-white font-bold text-xl">Pengumuman Login</h1>
            <p class="text-slate-400 text-sm mt-0.5">
                Tampil di sebelah kotak login (maksimal 3 terlihat sekaligus, sisanya digulir). Cocok untuk pengumuman
                fitur baru atau informasi penting. Yang disematkan selalu paling atas.
            </p>
            <p class="mt-2 text-xs text-amber-300/90 bg-amber-500/10 border border-amber-500/20 rounded-lg px-3 py-2">
                ⚠ Halaman login bisa dibaca siapa saja <b>sebelum masuk</b>. Jangan menulis data sensitif (nama konsumen, nominal, password, dll).
            </p>
        </div>

        <!-- Form tambah / ubah -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-3">
            <h2 class="text-slate-200 text-sm font-semibold">{{ editingId ? 'Ubah pengumuman' : 'Tambah pengumuman' }}</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="sm:col-span-2">
                    <label class="block text-slate-400 text-xs mb-1">Judul</label>
                    <input v-model="form.judul" type="text" maxlength="150" placeholder="mis. Fitur baru: Reset password oleh admin"
                        :class="{ 'border-rose-500': form.errors.judul }"
                        class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                    <p v-if="form.errors.judul" class="text-rose-400 text-xs mt-1">{{ form.errors.judul }}</p>
                </div>
                <div>
                    <label class="block text-slate-400 text-xs mb-1">Tanggal</label>
                    <input v-model="form.tanggal" type="date"
                        :class="{ 'border-rose-500': form.errors.tanggal }"
                        class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                </div>
            </div>
            <div>
                <label class="block text-slate-400 text-xs mb-1">Isi pengumuman</label>
                <textarea v-model="form.isi" rows="4" maxlength="3000" placeholder="Tulis isi pengumuman (teks biasa, baris baru dipertahankan)"
                    :class="{ 'border-rose-500': form.errors.isi }"
                    class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                <p v-if="form.errors.isi" class="text-rose-400 text-xs mt-1">{{ form.errors.isi }}</p>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-5 text-sm text-slate-300">
                    <label class="inline-flex items-center gap-2"><input v-model="form.disematkan" type="checkbox" class="rounded bg-slate-800 border-slate-600" /> Sematkan di atas</label>
                    <label class="inline-flex items-center gap-2"><input v-model="form.aktif" type="checkbox" class="rounded bg-slate-800 border-slate-600" /> Tampilkan di login</label>
                </div>
                <div class="flex gap-2">
                    <button v-if="editingId" type="button" @click="batalUbah" class="px-3 py-2 text-slate-400 hover:text-slate-200 text-sm">Batal</button>
                    <button type="button" @click="simpan" :disabled="form.processing || !form.judul || !form.isi"
                        class="px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors">
                        {{ form.processing ? 'Menyimpan…' : editingId ? 'Simpan perubahan' : '+ Tambah' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Daftar -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <div v-if="pengumuman.length">
                <div v-for="p in pengumuman" :key="p.id"
                    class="px-5 py-4 border-b border-slate-800 last:border-b-0" :class="{ 'opacity-50': !p.aktif }">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 text-xs text-slate-500">
                                <span v-if="p.disematkan" class="px-1.5 py-0.5 rounded bg-amber-500/15 text-amber-400 font-medium">📌 Disematkan</span>
                                <span v-if="!p.aktif" class="px-1.5 py-0.5 rounded bg-slate-700 text-slate-300 font-medium">Tidak tampil</span>
                                <span>{{ p.tanggal }}</span>
                            </div>
                            <div class="mt-1 text-slate-100 text-sm font-semibold">{{ p.judul }}</div>
                            <div class="mt-1 text-slate-400 text-sm whitespace-pre-line break-words">{{ p.isi }}</div>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5 text-xs">
                            <button @click="ubahCepat(p, { disematkan: !p.disematkan })" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300">
                                {{ p.disematkan ? 'Lepas sematan' : 'Sematkan' }}
                            </button>
                            <button @click="ubahCepat(p, { aktif: !p.aktif })" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300">
                                {{ p.aktif ? 'Sembunyikan' : 'Tampilkan' }}
                            </button>
                            <button @click="mulaiUbah(p)" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300">Ubah</button>
                            <button @click="hapus(p)" class="px-2.5 py-1.5 rounded-lg text-rose-400 hover:bg-rose-500/10">Hapus</button>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else class="px-5 py-8 text-center text-slate-600 text-sm">Belum ada pengumuman.</div>
        </div>
    </PengaturanLayout>
</template>

<script setup>
// Pratinjau + penerapan "Sinkronkan berkas dengan template" — dipakai untuk satu konsumen (halaman Dokumen) maupun
// seluruh konsumen aktif (Pengaturan → Template Pemberkasan). Server selalu menghitung ulang saat diterapkan.
import { ref, onMounted, computed } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    url:   { type: String, required: true },
    judul: { type: String, default: 'Sinkronkan Berkas dengan Template' },
});
const emit = defineEmits(['tutup', 'selesai']);

const memuat = ref(true);
const menerapkan = ref(false);
const galat = ref(null);
const hasil = ref(null);

onMounted(async () => {
    try {
        const { data } = await window.axios.post(props.url, { simulasi: true });
        hasil.value = data;
    } catch (e) {
        galat.value = e.response?.data?.message ?? 'Gagal membuat pratinjau.';
    } finally {
        memuat.value = false;
    }
});

// Satukan dua bentuk respons (satu konsumen: butir; massal: contoh per konsumen).
const kelompok = computed(() => {
    if (!hasil.value) return [];
    if (hasil.value.contoh) return hasil.value.contoh.map(c => ({ judul: [c.konsumen, c.unit].filter(Boolean).join(' · '), butir: c.butir }));

    return [{ judul: null, butir: hasil.value.butir ?? [] }];
});
const ringkas = computed(() => hasil.value?.ringkas ?? hasil.value?.total ?? { tambah: 0, ubah: 0, hapus: 0, pertahankan: 0 });
const adaPerubahan = computed(() => (ringkas.value.tambah + ringkas.value.ubah + ringkas.value.hapus) > 0);

const label = { tambah: 'Ditambah', ubah: 'Diubah', hapus: 'Dihapus', pertahankan: 'Dipertahankan' };
const warna = { tambah: 'text-emerald-400', ubah: 'text-sky-400', hapus: 'text-rose-400', pertahankan: 'text-amber-400' };

const terapkan = () => {
    menerapkan.value = true;
    router.post(props.url, {}, {
        preserveScroll: true,
        onSuccess: () => emit('selesai'),
        onError: (e) => { galat.value = Object.values(e)[0] ?? 'Gagal menerapkan.'; },
        onFinish: () => { menerapkan.value = false; },
    });
};
</script>

<template>
    <Teleport to="body">
        <div role="dialog" aria-modal="true" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="emit('tutup')" />
            <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-2xl shadow-2xl max-h-[90vh] flex flex-col">
                <div class="flex items-start justify-between p-5 border-b border-slate-800">
                    <div>
                        <h3 class="text-white font-semibold">{{ judul }}</h3>
                        <p class="text-slate-400 text-xs mt-0.5">Status berkas yang sudah diisi tidak ditimpa, dan berkas yang sudah diisi tidak dihapus.</p>
                    </div>
                    <button type="button" class="text-slate-500 hover:text-slate-300" aria-label="Tutup" @click="emit('tutup')">✕</button>
                </div>

                <div class="flex-1 overflow-y-auto p-5 space-y-4">
                    <p v-if="memuat" class="text-slate-400 text-sm">Menghitung perubahan…</p>
                    <p v-else-if="galat" class="text-rose-400 text-sm">{{ galat }}</p>
                    <template v-else>
                        <div class="flex flex-wrap gap-2 text-xs">
                            <span v-if="hasil.transaksi_terdampak !== undefined" class="px-2.5 py-1 rounded-full bg-violet-500/15 text-violet-300">{{ hasil.transaksi_terdampak }} konsumen terdampak</span>
                            <span v-for="(n, k) in ringkas" :key="k" v-show="n" class="px-2.5 py-1 rounded-full bg-slate-800" :class="warna[k]">{{ n }} {{ label[k].toLowerCase() }}</span>
                        </div>
                        <p v-if="!adaPerubahan && !ringkas.pertahankan" class="text-emerald-400 text-sm">✓ Sudah selaras dengan template. Tidak ada yang perlu diubah.</p>
                        <div v-for="(k, i) in kelompok" :key="i" class="space-y-1">
                            <div v-if="k.judul" class="text-slate-300 text-xs font-semibold pt-1">{{ k.judul }}</div>
                            <div v-for="(b, j) in k.butir" :key="j" class="flex gap-3 text-xs">
                                <span class="w-24 flex-shrink-0 font-medium" :class="warna[b.aksi]">{{ label[b.aksi] }}</span>
                                <span class="text-slate-200">{{ b.nama }}<span class="text-slate-500"> — {{ b.detail }}</span></span>
                            </div>
                        </div>
                        <p v-if="hasil.terpotong" class="text-slate-500 text-xs">Menampilkan sebagian konsumen; semua yang terdampak tetap diproses saat diterapkan.</p>
                    </template>
                </div>

                <div class="flex items-center justify-end gap-2 p-4 border-t border-slate-800">
                    <button type="button" class="px-4 py-2 text-slate-300 border border-slate-700 hover:bg-slate-800 rounded-lg text-sm" @click="emit('tutup')">Batal</button>
                    <button type="button" :disabled="memuat || menerapkan || !adaPerubahan" @click="terapkan"
                        class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white rounded-lg text-sm font-medium">{{ menerapkan ? 'Menerapkan…' : 'Terapkan' }}</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

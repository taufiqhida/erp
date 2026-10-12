<script setup>
// Edit massal unit (Stok Kavling → Tabel): ganti harga atau ganti Tipe Unit untuk banyak unit sekaligus.
// Alur dua langkah: isi perubahan → pratinjau "lama → baru" dari server (tanpa menyimpan) → Terapkan.
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import MoneyInput from '@/Components/MoneyInput.vue';

const props = defineProps({
    aksi:       { type: String, required: true },              // 'harga' | 'tipe'
    projectId:  { type: Number, required: true },
    tipeUnits:  { type: Array, default: () => [] },
    kavlingIds: { type: Array, default: () => [] },
    semuaFilter: { type: Boolean, default: false },            // pilih semua hasil filter (lintas halaman)
    filter:     { type: Object, default: () => ({}) },
    jumlah:     { type: Number, default: 0 },
});
const emit = defineEmits(['tutup', 'selesai']);

const mode = ref('set');            // set | persen | nominal
const arah = ref('naik');           // naik | turun (untuk persen/nominal)
const nilai = ref('');
const tipeId = ref('');
const langkah = ref('form');        // form | pratinjau
const memuat = ref(false);
const galat = ref(null);
const hasil = ref(null);

const rupiah = (n) => (n === null || n === undefined ? '—' : 'Rp ' + new Intl.NumberFormat('id-ID').format(n));
const label = { available: 'Tersedia', hold: 'Tidak Tersedia', booked: 'Dipesan', sold: 'Terjual', cancellation_requested: 'Proses Pembatalan', swap_requested: 'Proses Tukar Unit' };

const valid = computed(() => {
    if (props.aksi === 'tipe') return !!tipeId.value;
    if (nilai.value === '') return false;

    return mode.value === 'set' ? Number(nilai.value) >= 0 : Number(nilai.value) > 0;
});
watch(mode, () => { nilai.value = ''; });

const muatan = (simulasi) => {
    const d = {
        aksi: props.aksi,
        ...(props.semuaFilter ? { semua_hasil_filter: true, filter: Object.fromEntries(Object.entries(props.filter).filter(([, v]) => v)) } : { kavling_ids: props.kavlingIds }),
        ...(simulasi ? { simulasi: true } : {}),
    };
    if (props.aksi === 'tipe') d.tipe_unit_preset_id = tipeId.value;
    else { d.mode = mode.value; d.nilai = mode.value === 'set' ? Number(nilai.value) : (arah.value === 'turun' ? -1 : 1) * Number(nilai.value); }

    return d;
};

const pratinjau = async () => {
    memuat.value = true; galat.value = null;
    try {
        const { data } = await window.axios.post(route('kavlings.massal', props.projectId), muatan(true));
        hasil.value = data;
        langkah.value = 'pratinjau';
    } catch (e) {
        galat.value = e.response?.data?.message ?? 'Gagal membuat pratinjau. Coba lagi.';
    } finally {
        memuat.value = false;
    }
};

const terapkan = () => {
    memuat.value = true; galat.value = null;
    router.post(route('kavlings.massal', props.projectId), muatan(false), {
        preserveScroll: true,
        onSuccess: () => emit('selesai'),
        onError: (e) => { galat.value = Object.values(e)[0] ?? 'Gagal menyimpan.'; },
        onFinish: () => { memuat.value = false; },
    });
};

const judul = computed(() => (props.aksi === 'harga' ? 'Ganti Harga Massal' : 'Ganti Tipe Unit Massal'));
const tampil = (nilaiKolom) => (props.aksi === 'harga' ? rupiah(nilaiKolom) : (nilaiKolom ?? '—'));
</script>

<template>
    <Teleport to="body">
        <div role="dialog" aria-modal="true" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="emit('tutup')" />
            <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-2xl shadow-2xl max-h-[90vh] flex flex-col">
                <div class="flex items-start justify-between p-5 border-b border-slate-800">
                    <div>
                        <h3 class="text-white font-semibold">{{ judul }}</h3>
                        <p class="text-slate-400 text-xs mt-0.5">{{ jumlah }} unit dipilih{{ semuaFilter ? ' (semua hasil filter)' : '' }}. Unit yang sudah Dipesan/Terjual tidak diubah.</p>
                    </div>
                    <button type="button" class="text-slate-500 hover:text-slate-300" aria-label="Tutup" @click="emit('tutup')">✕</button>
                </div>

                <!-- Langkah 1: isi perubahan -->
                <div v-if="langkah === 'form'" class="p-5 space-y-4 overflow-y-auto">
                    <template v-if="aksi === 'harga'">
                        <div class="grid sm:grid-cols-3 gap-2">
                            <label v-for="m in [{ v: 'set', t: 'Harga baru', d: 'Sama untuk semua unit' }, { v: 'persen', t: 'Naik / turun %', d: 'Dari harga sekarang' }, { v: 'nominal', t: 'Naik / turun Rp', d: 'Dari harga sekarang' }]" :key="m.v"
                                :class="mode === m.v ? 'border-violet-500 bg-violet-500/10' : 'border-slate-700 hover:border-slate-600'"
                                class="cursor-pointer rounded-xl border p-3">
                                <input type="radio" v-model="mode" :value="m.v" class="sr-only" />
                                <div class="text-slate-200 text-sm font-medium">{{ m.t }}</div>
                                <div class="text-slate-500 text-xs">{{ m.d }}</div>
                            </label>
                        </div>
                        <div class="flex items-center gap-3">
                            <div v-if="mode !== 'set'" class="inline-flex rounded-lg overflow-hidden border border-slate-700">
                                <button type="button" @click="arah = 'naik'" :class="arah === 'naik' ? 'bg-emerald-600 text-white' : 'bg-slate-800 text-slate-300'" class="px-3 py-2 text-xs">Naik</button>
                                <button type="button" @click="arah = 'turun'" :class="arah === 'turun' ? 'bg-rose-600 text-white' : 'bg-slate-800 text-slate-300'" class="px-3 py-2 text-xs">Turun</button>
                            </div>
                            <div class="flex-1 relative">
                                <span v-if="mode !== 'persen'" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-sm">Rp</span>
                                <MoneyInput v-model="nilai" :plain="mode === 'persen'"
                                    :class="mode !== 'persen' ? 'pl-9' : ''"
                                    class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-100 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                                    :placeholder="mode === 'persen' ? 'mis. 5' : 'mis. 350.000.000'" />
                                <span v-if="mode === 'persen'" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 text-sm">%</span>
                            </div>
                        </div>
                        <p class="text-slate-500 text-xs">Hasil dibulatkan ke rupiah. Unit yang belum punya harga dilewati untuk naik/turun.</p>
                    </template>
                    <template v-else>
                        <label class="block text-xs text-slate-400 mb-1">Ganti menjadi Tipe Unit</label>
                        <select v-model="tipeId" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-100 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                            <option value="">-- pilih tipe --</option>
                            <option v-for="t in tipeUnits" :key="t.id" :value="t.id">{{ t.nama }}</option>
                        </select>
                        <p class="text-slate-500 text-xs">Luas tanah dan bangunan mengikuti Tipe Unit, jadi ikut berganti.</p>
                    </template>
                    <p v-if="galat" class="text-rose-400 text-sm">{{ galat }}</p>
                </div>

                <!-- Langkah 2: pratinjau -->
                <div v-else class="flex-1 min-h-0 flex flex-col">
                    <div class="px-5 pt-4 pb-2 flex flex-wrap gap-2 text-xs">
                        <span class="px-2.5 py-1 rounded-full bg-emerald-500/15 text-emerald-400">{{ hasil.ringkas.ubah }} unit akan diubah</span>
                        <span v-if="hasil.ringkas.lewati" class="px-2.5 py-1 rounded-full bg-amber-500/15 text-amber-400">{{ hasil.ringkas.lewati }} dilewati</span>
                        <span v-if="hasil.ringkas.sama" class="px-2.5 py-1 rounded-full bg-slate-700 text-slate-300">{{ hasil.ringkas.sama }} sudah sama</span>
                    </div>
                    <div class="overflow-y-auto px-5 pb-3">
                        <table class="w-full text-xs">
                            <thead class="text-slate-500 text-left sticky top-0 bg-slate-900"><tr><th class="py-1.5 font-medium">Unit</th><th class="py-1.5 font-medium">Status</th><th class="py-1.5 font-medium">Lama</th><th class="py-1.5 font-medium">Baru</th></tr></thead>
                            <tbody class="divide-y divide-slate-800">
                                <tr v-for="b in hasil.baris" :key="b.id" :class="b.hasil === 'ubah' ? '' : 'opacity-50'">
                                    <td class="py-1.5 text-slate-200 font-medium">{{ b.unit }}</td>
                                    <td class="py-1.5 text-slate-400">{{ label[b.status] ?? b.status }}</td>
                                    <td class="py-1.5 text-slate-400 font-mono">{{ tampil(b.lama) }}</td>
                                    <td class="py-1.5 font-mono" :class="b.hasil === 'ubah' ? 'text-emerald-400' : 'text-slate-500'">
                                        <template v-if="b.hasil === 'ubah'">{{ tampil(b.baru) }}</template>
                                        <template v-else>{{ b.alasan }}</template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-if="hasil.terpotong" class="text-slate-500 text-xs mt-2">Menampilkan sebagian; semua unit yang tercantum di ringkasan tetap diproses.</p>
                    </div>
                    <p v-if="galat" class="px-5 pb-2 text-rose-400 text-sm">{{ galat }}</p>
                </div>

                <div class="flex items-center justify-end gap-2 p-4 border-t border-slate-800">
                    <button v-if="langkah === 'pratinjau'" type="button" class="mr-auto px-3 py-2 text-slate-400 hover:text-slate-200 text-sm" @click="langkah = 'form'">← Ubah</button>
                    <button type="button" class="px-4 py-2 text-slate-300 border border-slate-700 hover:bg-slate-800 rounded-lg text-sm" @click="emit('tutup')">Batal</button>
                    <button v-if="langkah === 'form'" type="button" :disabled="!valid || memuat" @click="pratinjau"
                        class="px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white rounded-lg text-sm font-medium">{{ memuat ? 'Menghitung…' : 'Lihat Pratinjau' }}</button>
                    <button v-else type="button" :disabled="memuat || hasil.ringkas.ubah === 0" @click="terapkan"
                        class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white rounded-lg text-sm font-medium">{{ memuat ? 'Menyimpan…' : `Terapkan ke ${hasil.ringkas.ubah} unit` }}</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import { useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    presets: Array,
    caraBayarOptions: Object,
    basisOptions: Object,
});

const emptyForm = () => ({
    nama: '',
    cara_bayar: '',
    booking_fee_aktif: true,
    booking_fee_tipe: 'nominal',
    booking_fee_nilai: '',
    booking_fee_tenor: 1,
    booking_fee_masuk_harga_jual: true,
    booking_fee_basis: 'harga_dasar',
    dp_aktif: false,
    dp_tipe: 'nominal',
    dp_nilai: '',
    dp_tenor: 1,
    dp_masuk_harga_jual: true,
    dp_basis: 'harga_netto',
    keterangan: '',
});

const addForm = useForm(emptyForm());

const addPreset = () => {
    addForm.post(route('pengaturan.skema-dp.store'), {
        onSuccess: () => addForm.reset(),
    });
};

const editingId = ref(null);
const editForm = useForm(emptyForm());

const startEdit = (preset) => {
    editingId.value = preset.id;
    Object.assign(editForm, {
        nama: preset.nama,
        cara_bayar: preset.cara_bayar ?? '',
        booking_fee_aktif: preset.booking_fee_aktif,
        booking_fee_tipe: preset.booking_fee_tipe ?? 'nominal',
        booking_fee_nilai: preset.booking_fee_nilai ?? '',
        booking_fee_tenor: preset.booking_fee_tenor,
        booking_fee_masuk_harga_jual: preset.booking_fee_masuk_harga_jual,
        booking_fee_basis: preset.booking_fee_basis ?? 'harga_dasar',
        dp_aktif: preset.dp_aktif,
        dp_tipe: preset.dp_tipe ?? 'nominal',
        dp_nilai: preset.dp_nilai ?? '',
        dp_tenor: preset.dp_tenor,
        dp_masuk_harga_jual: preset.dp_masuk_harga_jual,
        dp_basis: preset.dp_basis ?? 'harga_netto',
        keterangan: preset.keterangan ?? '',
    });
};
const cancelEdit = () => { editingId.value = null; };

const saveEdit = (preset) => {
    editForm.is_active = preset.is_active;
    editForm.patch(route('pengaturan.skema-dp.update', preset.id), {
        onSuccess: () => { editingId.value = null; },
    });
};

const toggleActive = (preset) => {
    router.patch(route('pengaturan.skema-dp.update', preset.id), {
        nama: preset.nama,
        cara_bayar: preset.cara_bayar,
        booking_fee_aktif: preset.booking_fee_aktif,
        booking_fee_tipe: preset.booking_fee_tipe,
        booking_fee_nilai: preset.booking_fee_nilai,
        booking_fee_tenor: preset.booking_fee_tenor,
        booking_fee_masuk_harga_jual: preset.booking_fee_masuk_harga_jual,
        booking_fee_basis: preset.booking_fee_basis,
        dp_aktif: preset.dp_aktif,
        dp_tipe: preset.dp_tipe,
        dp_nilai: preset.dp_nilai,
        dp_tenor: preset.dp_tenor,
        dp_masuk_harga_jual: preset.dp_masuk_harga_jual,
        dp_basis: preset.dp_basis,
        keterangan: preset.keterangan,
        is_active: !preset.is_active,
    }, { preserveScroll: true });
};

const delForm = useForm({});
const destroy = (preset) => {
    if (confirm(`Hapus preset "${preset.nama}"?`)) {
        delForm.delete(route('pengaturan.skema-dp.destroy', preset.id));
    }
};

const formatRp = (v) => v ? 'Rp ' + Number(v).toLocaleString('id-ID') : '-';
const nilaiLabel = (tipe, nilai, basis) => tipe === 'persen'
    ? `${nilai}% dari ${props.basisOptions[basis]}`
    : formatRp(nilai);
</script>

<template>
    <PengaturanLayout title="Preset Skema DP">
            <div>
                <h1 class="text-white font-bold text-xl">Preset Skema DP</h1>
                <p class="text-slate-400 text-sm mt-0.5">Template booking fee & DP (aktif/tidak, nominal/persen, tenor, masuk harga jual) yang bisa dipilih sales saat booking</p>
            </div>

            <!-- List -->
            <div class="space-y-3">
                <div v-if="!presets.length" class="bg-slate-900 border border-dashed border-slate-700 rounded-xl px-5 py-8 text-center text-slate-600 text-sm">
                    Belum ada preset skema DP.
                </div>

                <div v-for="preset in presets" :key="preset.id"
                    class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden"
                    :class="{ 'opacity-50': !preset.is_active }">

                    <!-- Summary row -->
                    <div v-if="editingId !== preset.id" class="flex items-center justify-between px-5 py-3.5">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-slate-200 text-sm font-medium">{{ preset.nama }}</span>
                                <span v-if="preset.cara_bayar" class="text-slate-500 text-xs bg-slate-800 px-1.5 py-0.5 rounded">{{ caraBayarOptions[preset.cara_bayar] }}</span>
                            </div>
                            <div class="text-slate-500 text-xs mt-1 flex gap-3 flex-wrap">
                                <span>
                                    Booking Fee:
                                    <span :class="preset.booking_fee_aktif ? 'text-slate-300' : 'text-slate-600'">
                                        {{ preset.booking_fee_aktif
                                            ? `${nilaiLabel(preset.booking_fee_tipe, preset.booking_fee_nilai, preset.booking_fee_basis)} · ${preset.booking_fee_tenor}x cicilan · ${preset.booking_fee_masuk_harga_jual ? 'masuk harga jual' : 'di luar harga jual'}`
                                            : 'Nonaktif' }}
                                    </span>
                                </span>
                                <span>
                                    DP:
                                    <span :class="preset.dp_aktif ? 'text-slate-300' : 'text-slate-600'">
                                        {{ preset.dp_aktif
                                            ? `${nilaiLabel(preset.dp_tipe, preset.dp_nilai, preset.dp_basis)} · ${preset.dp_tenor}x cicilan · ${preset.dp_masuk_harga_jual ? 'masuk harga jual' : 'di luar harga jual'}`
                                            : 'Nonaktif' }}
                                    </span>
                                </span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <button @click="toggleActive(preset)"
                                :class="preset.is_active ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-700 text-slate-400'"
                                class="px-2 py-1 rounded-lg text-xs font-medium transition-colors">
                                {{ preset.is_active ? 'Aktif' : 'Nonaktif' }}
                            </button>
                            <button @click="startEdit(preset)" class="px-2 py-1 text-slate-400 hover:text-amber-400 hover:bg-amber-400/10 rounded-lg text-xs transition-colors">Edit</button>
                            <button @click="destroy(preset)" class="px-2 py-1 text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 rounded-lg text-xs transition-colors">Hapus</button>
                        </div>
                    </div>

                    <!-- Edit form -->
                    <div v-else class="px-5 py-4 space-y-3 border-t border-violet-500/30 bg-violet-500/5">
                        <div class="grid grid-cols-2 gap-3">
                            <input v-model="editForm.nama" type="text" placeholder="Nama skema"
                                class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            <select v-model="editForm.cara_bayar"
                                class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                                <option value="">Semua Cara Bayar</option>
                                <option v-for="(label, key) in caraBayarOptions" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2 bg-slate-800/50 rounded-lg p-3">
                                <label class="flex items-center gap-2 text-slate-300 text-xs font-medium">
                                    <input type="checkbox" v-model="editForm.booking_fee_aktif" class="accent-violet-500" /> Booking Fee — Ada
                                </label>
                                <template v-if="editForm.booking_fee_aktif">
                                    <div class="grid grid-cols-3 gap-2">
                                        <div>
                                            <label class="block text-slate-500 text-[10px] mb-0.5">Tipe</label>
                                            <select v-model="editForm.booking_fee_tipe" class="w-full px-2 py-1.5 bg-slate-800 border border-slate-700 rounded text-slate-300 text-xs">
                                                <option value="nominal">Nominal (Rp)</option>
                                                <option value="persen">% dari harga unit</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-slate-500 text-[10px] mb-0.5">Nilai</label>
                                            <input v-model.number="editForm.booking_fee_nilai" type="number" min="0" :placeholder="editForm.booking_fee_tipe === 'persen' ? '%' : 'Rp'"
                                                class="w-full px-2 py-1.5 bg-slate-800 border border-slate-700 rounded text-slate-200 text-xs" />
                                        </div>
                                        <div>
                                            <label class="block text-slate-500 text-[10px] mb-0.5">Tenor (x cicilan)</label>
                                            <input v-model.number="editForm.booking_fee_tenor" type="number" min="1"
                                                class="w-full px-2 py-1.5 bg-slate-800 border border-slate-700 rounded text-slate-200 text-xs" />
                                        </div>
                                    </div>
                                    <div v-if="editForm.booking_fee_tipe === 'persen'">
                                        <label class="block text-slate-500 text-[10px] mb-0.5">Dasar Hitung %</label>
                                        <select v-model="editForm.booking_fee_basis" class="w-full px-2 py-1.5 bg-slate-800 border border-slate-700 rounded text-slate-300 text-xs">
                                            <option v-for="(label, key) in basisOptions" :key="key" :value="key">{{ label }}</option>
                                        </select>
                                    </div>
                                    <label class="flex items-center gap-2 text-slate-400 text-[11px] pt-1">
                                        <input type="checkbox" v-model="editForm.booking_fee_masuk_harga_jual" class="accent-violet-500" />
                                        Masuk hitungan harga jual (mengurangi sisa yang harus dilunasi)
                                    </label>
                                </template>
                            </div>
                            <div class="space-y-2 bg-slate-800/50 rounded-lg p-3">
                                <label class="flex items-center gap-2 text-slate-300 text-xs font-medium">
                                    <input type="checkbox" v-model="editForm.dp_aktif" class="accent-violet-500" /> DP — Ada
                                </label>
                                <template v-if="editForm.dp_aktif">
                                    <div class="grid grid-cols-3 gap-2">
                                        <div>
                                            <label class="block text-slate-500 text-[10px] mb-0.5">Tipe</label>
                                            <select v-model="editForm.dp_tipe" class="w-full px-2 py-1.5 bg-slate-800 border border-slate-700 rounded text-slate-300 text-xs">
                                                <option value="nominal">Nominal (Rp)</option>
                                                <option value="persen">% dari harga jual</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-slate-500 text-[10px] mb-0.5">Nilai</label>
                                            <input v-model.number="editForm.dp_nilai" type="number" min="0" :placeholder="editForm.dp_tipe === 'persen' ? '%' : 'Rp'"
                                                class="w-full px-2 py-1.5 bg-slate-800 border border-slate-700 rounded text-slate-200 text-xs" />
                                        </div>
                                        <div>
                                            <label class="block text-slate-500 text-[10px] mb-0.5">Tenor (x cicilan)</label>
                                            <input v-model.number="editForm.dp_tenor" type="number" min="1"
                                                class="w-full px-2 py-1.5 bg-slate-800 border border-slate-700 rounded text-slate-200 text-xs" />
                                        </div>
                                    </div>
                                    <div v-if="editForm.dp_tipe === 'persen'">
                                        <label class="block text-slate-500 text-[10px] mb-0.5">Dasar Hitung %</label>
                                        <select v-model="editForm.dp_basis" class="w-full px-2 py-1.5 bg-slate-800 border border-slate-700 rounded text-slate-300 text-xs">
                                            <option v-for="(label, key) in basisOptions" :key="key" :value="key">{{ label }}</option>
                                        </select>
                                    </div>
                                    <label class="flex items-center gap-2 text-slate-400 text-[11px] pt-1">
                                        <input type="checkbox" v-model="editForm.dp_masuk_harga_jual" class="accent-violet-500" />
                                        Masuk hitungan harga jual (mengurangi sisa yang harus dilunasi)
                                    </label>
                                </template>
                            </div>
                        </div>

                        <input v-model="editForm.keterangan" type="text" placeholder="Keterangan (opsional)"
                            class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />

                        <div class="flex justify-end gap-2">
                            <button @click="cancelEdit" class="px-3 py-1.5 text-slate-400 hover:text-slate-200 text-xs">Batal</button>
                            <button @click="saveEdit(preset)" :disabled="editForm.processing"
                                class="px-4 py-1.5 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-xs font-medium rounded-lg transition-colors">
                                Simpan
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Add New -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-3">
                <h3 class="text-white font-semibold text-sm">+ Tambah Preset Skema DP</h3>
                <div class="grid grid-cols-2 gap-3">
                    <input v-model="addForm.nama" type="text" placeholder="Nama skema (mis. DP 10% x3 bulan)"
                        :class="{ 'border-rose-500': addForm.errors.nama }"
                        class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                    <select v-model="addForm.cara_bayar"
                        class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="">Semua Cara Bayar</option>
                        <option v-for="(label, key) in caraBayarOptions" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2 bg-slate-800/50 rounded-lg p-3">
                        <label class="flex items-center gap-2 text-slate-300 text-xs font-medium">
                            <input type="checkbox" v-model="addForm.booking_fee_aktif" class="accent-violet-500" /> Booking Fee — Ada
                        </label>
                        <template v-if="addForm.booking_fee_aktif">
                            <div class="grid grid-cols-3 gap-2">
                                <div>
                                    <label class="block text-slate-500 text-[10px] mb-0.5">Tipe</label>
                                    <select v-model="addForm.booking_fee_tipe" class="w-full px-2 py-1.5 bg-slate-800 border border-slate-700 rounded text-slate-300 text-xs">
                                        <option value="nominal">Nominal (Rp)</option>
                                        <option value="persen">% dari harga unit</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-slate-500 text-[10px] mb-0.5">Nilai</label>
                                    <input v-model.number="addForm.booking_fee_nilai" type="number" min="0" :placeholder="addForm.booking_fee_tipe === 'persen' ? '%' : 'Rp'"
                                        class="w-full px-2 py-1.5 bg-slate-800 border border-slate-700 rounded text-slate-200 text-xs" />
                                </div>
                                <div>
                                    <label class="block text-slate-500 text-[10px] mb-0.5">Tenor (x cicilan)</label>
                                    <input v-model.number="addForm.booking_fee_tenor" type="number" min="1"
                                        class="w-full px-2 py-1.5 bg-slate-800 border border-slate-700 rounded text-slate-200 text-xs" />
                                </div>
                            </div>
                            <div v-if="addForm.booking_fee_tipe === 'persen'">
                                <label class="block text-slate-500 text-[10px] mb-0.5">Dasar Hitung %</label>
                                <select v-model="addForm.booking_fee_basis" class="w-full px-2 py-1.5 bg-slate-800 border border-slate-700 rounded text-slate-300 text-xs">
                                    <option v-for="(label, key) in basisOptions" :key="key" :value="key">{{ label }}</option>
                                </select>
                            </div>
                            <label class="flex items-center gap-2 text-slate-400 text-[11px] pt-1">
                                <input type="checkbox" v-model="addForm.booking_fee_masuk_harga_jual" class="accent-violet-500" />
                                Masuk hitungan harga jual (mengurangi sisa yang harus dilunasi)
                            </label>
                        </template>
                    </div>
                    <div class="space-y-2 bg-slate-800/50 rounded-lg p-3">
                        <label class="flex items-center gap-2 text-slate-300 text-xs font-medium">
                            <input type="checkbox" v-model="addForm.dp_aktif" class="accent-violet-500" /> DP — Ada
                        </label>
                        <template v-if="addForm.dp_aktif">
                            <div class="grid grid-cols-3 gap-2">
                                <div>
                                    <label class="block text-slate-500 text-[10px] mb-0.5">Tipe</label>
                                    <select v-model="addForm.dp_tipe" class="w-full px-2 py-1.5 bg-slate-800 border border-slate-700 rounded text-slate-300 text-xs">
                                        <option value="nominal">Nominal (Rp)</option>
                                        <option value="persen">% dari harga jual</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-slate-500 text-[10px] mb-0.5">Nilai</label>
                                    <input v-model.number="addForm.dp_nilai" type="number" min="0" :placeholder="addForm.dp_tipe === 'persen' ? '%' : 'Rp'"
                                        class="w-full px-2 py-1.5 bg-slate-800 border border-slate-700 rounded text-slate-200 text-xs" />
                                </div>
                                <div>
                                    <label class="block text-slate-500 text-[10px] mb-0.5">Tenor (x cicilan)</label>
                                    <input v-model.number="addForm.dp_tenor" type="number" min="1"
                                        class="w-full px-2 py-1.5 bg-slate-800 border border-slate-700 rounded text-slate-200 text-xs" />
                                </div>
                            </div>
                            <div v-if="addForm.dp_tipe === 'persen'">
                                <label class="block text-slate-500 text-[10px] mb-0.5">Dasar Hitung %</label>
                                <select v-model="addForm.dp_basis" class="w-full px-2 py-1.5 bg-slate-800 border border-slate-700 rounded text-slate-300 text-xs">
                                    <option v-for="(label, key) in basisOptions" :key="key" :value="key">{{ label }}</option>
                                </select>
                            </div>
                            <label class="flex items-center gap-2 text-slate-400 text-[11px] pt-1">
                                <input type="checkbox" v-model="addForm.dp_masuk_harga_jual" class="accent-violet-500" />
                                Masuk hitungan harga jual (mengurangi sisa yang harus dilunasi)
                            </label>
                        </template>
                    </div>
                </div>

                <input v-model="addForm.keterangan" type="text" placeholder="Keterangan (opsional)"
                    class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />

                <div class="flex justify-end">
                    <button @click="addPreset" :disabled="addForm.processing || !addForm.nama"
                        class="px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors">
                        + Tambah Preset
                    </button>
                </div>
            </div>
    </PengaturanLayout>
</template>

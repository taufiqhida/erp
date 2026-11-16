<script setup>
import { ref } from 'vue';
import { konfirmasi } from '@/Composables/useConfirm';
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import UrutanButtons from '@/Components/UrutanButtons.vue';
import { useForm, router } from '@inertiajs/vue3';

const props = defineProps({
    presets: Array,
});

// DP di dalam All In punya tiga pilihan: tidak termasuk / termasuk mengikuti DP skema / termasuk sebesar nominal tetap.
// Disimpan sebagai include_dp + dp_nominal_tetap (kosong = mengikuti skema).
const dpModeDari = (p) => !p.include_dp ? 'tidak' : (p.dp_nominal_tetap !== null && p.dp_nominal_tetap !== undefined && p.dp_nominal_tetap !== '' ? 'tetap' : 'skema');
const payloadDari = (f) => ({
    nama: f.nama,
    nominal: f.nominal,
    include_booking_fee: f.include_booking_fee,
    include_dp: f.dp_mode !== 'tidak',
    dp_nominal_tetap: f.dp_mode === 'tetap' ? f.dp_nominal_tetap : null,
});

const addForm = useForm({
    nama: '',
    nominal: '',
    include_booking_fee: false,
    dp_mode: 'tidak',
    dp_nominal_tetap: '',
});

const addPreset = () => {
    addForm.transform(payloadDari).post(route('pengaturan.program-all-in.store'), {
        onSuccess: () => addForm.reset(),
    });
};

// Ubah preset yang sudah ada. Transaksi lama tidak berubah: Titipan Biaya Akad dikunci saat booking.
const editingId = ref(null);
const editForm = useForm({ nama: '', nominal: '', include_booking_fee: false, dp_mode: 'tidak', dp_nominal_tetap: '' });
const openEdit = (p) => {
    editingId.value = p.id;
    editForm.nama = p.nama;
    editForm.nominal = p.nominal;
    editForm.include_booking_fee = !!p.include_booking_fee;
    editForm.dp_mode = dpModeDari(p);
    editForm.dp_nominal_tetap = p.dp_nominal_tetap ?? '';
    editForm.clearErrors();
};
const submitEdit = (p) => {
    editForm.transform((f) => ({ ...payloadDari(f), is_active: p.is_active })).patch(route('pengaturan.program-all-in.update', p.id), {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null; },
    });
};
const editTidakValid = () => !editForm.nama || !editForm.nominal || (editForm.dp_mode === 'tetap' && !editForm.dp_nominal_tetap);

const toggleActive = (preset) => {
    router.patch(route('pengaturan.program-all-in.update', preset.id), {
        nama: preset.nama,
        nominal: preset.nominal,
        include_booking_fee: preset.include_booking_fee,
        include_dp: preset.include_dp,
        dp_nominal_tetap: preset.dp_nominal_tetap,
        is_active: !preset.is_active,
    }, { preserveScroll: true });
};

const delForm = useForm({});
const destroy = async (preset) => {
    if (await konfirmasi(`Hapus Program All In "${preset.nama}"?`)) {
        delForm.delete(route('pengaturan.program-all-in.destroy', preset.id));
    }
};

const formatRp = (v) => v ? 'Rp ' + Number(v).toLocaleString('id-ID') : 'Rp 0';

const includeLabel = (preset) => {
    const parts = [];
    if (preset.include_booking_fee) parts.push('Booking Fee');
    if (preset.include_dp) parts.push(dpModeDari(preset) === 'tetap' ? `DP tetap ${formatRp(preset.dp_nominal_tetap)}` : 'DP (mengikuti DP skema)');
    return parts.length ? `Termasuk ${parts.join(' + ')}` : 'Tidak termasuk Booking Fee/DP';
};

const dpOpsi = [
    { value: 'tidak', label: 'DP tidak termasuk' },
    { value: 'skema', label: 'DP termasuk, mengikuti DP skema' },
    { value: 'tetap', label: 'DP termasuk sebesar nominal tetap' },
];
</script>

<template>
    <PengaturanLayout title="Program All In">
            <div>
                <h1 class="text-white font-bold text-xl">Program All In</h1>
                <p class="text-slate-400 text-sm mt-0.5">
                    Paket bundel nominal All In yang bisa dipilih konsumen saat booking. Kalau nominalnya sudah termasuk
                    Booking Fee dan/atau DP, sisanya otomatis jadi baris "Titipan Biaya Akad" di Kartu Piutang.
                    DP bisa dikurangkan sebesar DP skema yang dipilih, atau sebesar nominal tetap (mis. 1,66 jt di dalam paket 10,5 jt)
                    supaya satu paket berlaku sama untuk KPR maupun cash.
                </p>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div v-if="presets.length">
                    <div v-for="(preset, idx) in presets" :key="preset.id"
                        class="border-b border-slate-800 hover:bg-slate-800/30 transition-colors group"
                        :class="{ 'opacity-50': !preset.is_active && editingId !== preset.id }">
                        <div v-if="editingId !== preset.id" class="flex items-center justify-between px-5 py-3">
                            <div>
                                <div class="text-slate-200 text-sm font-medium">{{ preset.nama }} · {{ formatRp(preset.nominal) }}</div>
                                <div class="text-slate-500 text-xs mt-0.5">{{ includeLabel(preset) }}</div>
                            </div>
                            <div class="flex items-center gap-3">
                                <UrutanButtons type="program-all-in" :id="preset.id" :first="idx === 0" :last="idx === presets.length - 1" />
                                <button @click="openEdit(preset)"
                                    class="opacity-0 group-hover:opacity-100 px-2 py-1 text-slate-400 hover:text-violet-300 hover:bg-violet-500/10 rounded-lg text-xs transition-all">
                                    Ubah
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

                        <!-- Form ubah -->
                        <div v-else class="px-5 py-4 bg-slate-800/30 space-y-3">
                            <div class="flex gap-3">
                                <input v-model="editForm.nama" type="text" placeholder="Nama program"
                                    :class="{ 'border-rose-500': editForm.errors.nama }"
                                    class="flex-1 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                                <MoneyInput v-model="editForm.nominal" placeholder="Nominal (Rp)"
                                    :class="{ 'border-rose-500': editForm.errors.nominal }"
                                    class="w-40 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            </div>
                            <div class="flex flex-wrap items-center gap-4">
                                <label class="flex items-center gap-2 text-slate-300 text-sm cursor-pointer">
                                    <input v-model="editForm.include_booking_fee" type="checkbox" class="accent-violet-500" />
                                    Termasuk Booking Fee
                                </label>
                                <select v-model="editForm.dp_mode"
                                    class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                                    <option v-for="o in dpOpsi" :key="o.value" :value="o.value">{{ o.label }}</option>
                                </select>
                                <MoneyInput v-if="editForm.dp_mode === 'tetap'" v-model="editForm.dp_nominal_tetap" placeholder="DP di dalam paket (Rp)"
                                    :class="{ 'border-rose-500': editForm.errors.dp_nominal_tetap }"
                                    class="w-44 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            </div>
                            <p class="text-slate-500 text-xs">Transaksi yang sudah dibuat tidak berubah (Titipan Biaya Akad dikunci saat booking). Perubahan berlaku untuk booking baru dan Ubah Rincian.</p>
                            <div class="flex gap-2">
                                <button @click="submitEdit(preset)" :disabled="editForm.processing || editTidakValid()"
                                    class="px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors">Simpan</button>
                                <button @click="editingId = null" class="px-3 py-2 text-slate-400 hover:text-slate-200 text-sm">Batal</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="px-5 py-8 text-center text-slate-500 text-sm">
                    Belum ada Program All In.
                </div>

                <!-- Add New -->
                <div class="px-5 py-4 border-t border-slate-800 bg-slate-800/20 space-y-3">
                    <div class="flex gap-3">
                        <input v-model="addForm.nama" type="text" placeholder="Nama program (mis. All In 12jt)"
                            :class="{ 'border-rose-500': addForm.errors.nama }"
                            class="flex-1 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        <MoneyInput v-model="addForm.nominal" placeholder="Nominal (Rp)"
                            :class="{ 'border-rose-500': addForm.errors.nominal }"
                            class="w-40 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                    </div>
                    <div class="flex flex-wrap items-center gap-4">
                        <label class="flex items-center gap-2 text-slate-300 text-sm cursor-pointer">
                            <input v-model="addForm.include_booking_fee" type="checkbox" class="accent-violet-500" />
                            Termasuk Booking Fee
                        </label>
                        <select v-model="addForm.dp_mode"
                            class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                            <option v-for="o in dpOpsi" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>
                        <MoneyInput v-if="addForm.dp_mode === 'tetap'" v-model="addForm.dp_nominal_tetap" placeholder="DP di dalam paket (Rp)"
                            :class="{ 'border-rose-500': addForm.errors.dp_nominal_tetap }"
                            class="w-44 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        <button @click="addPreset" :disabled="addForm.processing || !addForm.nama || !addForm.nominal || (addForm.dp_mode === 'tetap' && !addForm.dp_nominal_tetap)"
                            class="ml-auto px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors whitespace-nowrap">
                            + Tambah
                        </button>
                    </div>
                </div>
            </div>
    </PengaturanLayout>
</template>

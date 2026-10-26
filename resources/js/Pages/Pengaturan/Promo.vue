<script setup>
import { konfirmasi } from '@/Composables/useConfirm';
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import { useForm, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    presets: Array,
    biayaTambahanPresets: { type: Array, default: () => [] },
});

// "Lawan" promo: biaya yang boleh dipotong promo ini (tanpa nominal — nominal/persen diisi per transaksi saat booking).
const lawanForms = reactive({});
const bukaLawan = (preset) => {
    lawanForms[preset.id] = { tanah: preset.lawan_tanah, harga_jual: preset.lawan_harga_jual, biaya_tambahan_preset_ids: [...preset.lawan_biaya_tambahan] };
};
const toggleLawanBt = (presetId, id) => {
    const arr = lawanForms[presetId].biaya_tambahan_preset_ids;
    const i = arr.indexOf(id);
    if (i === -1) arr.push(id); else arr.splice(i, 1);
};
const simpanLawan = (preset) => {
    router.put(route('pengaturan.promo.lawan', preset.id), lawanForms[preset.id], {
        preserveScroll: true,
        onSuccess: () => { delete lawanForms[preset.id]; },
    });
};
const namaLawan = (preset) => [
    ...(preset.lawan_harga_jual ? ['Harga Jual (diskon)'] : []),
    ...(preset.lawan_tanah ? ['Kelebihan Tanah'] : []),
    ...preset.lawan_biaya_tambahan.map(id => props.biayaTambahanPresets.find(b => b.id === id)?.nama).filter(Boolean),
];

const addForm = useForm({
    nama: '',
    keterangan: '',
});

const addPreset = () => {
    addForm.post(route('pengaturan.promo.store'), {
        onSuccess: () => addForm.reset(),
    });
};

const toggleActive = (preset) => {
    router.patch(route('pengaturan.promo.update', preset.id), {
        nama: preset.nama,
        keterangan: preset.keterangan,
        is_active: !preset.is_active,
    }, { preserveScroll: true });
};

const delForm = useForm({});
const destroy = async (preset) => {
    if (await konfirmasi(`Hapus preset "${preset.nama}"?`)) {
        delForm.delete(route('pengaturan.promo.destroy', preset.id));
    }
};
</script>

<template>
    <PengaturanLayout title="Preset Promo">
            <div>
                <h1 class="text-white font-bold text-xl">Preset Promo</h1>
                <p class="text-slate-400 text-sm mt-0.5">Daftar promo yang bisa dipilih sales saat booking. Di sini hanya diatur biaya yang boleh dipotong (lawan), termasuk Harga Jual untuk diskon umum — nominal atau persennya diisi per transaksi, bukan di sini</p>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div v-if="presets.length">
                    <div v-for="preset in presets" :key="preset.id"
                        class="flex items-center justify-between px-5 py-3 border-b border-slate-800 hover:bg-slate-800/30 transition-colors group"
                        :class="{ 'opacity-50': !preset.is_active }">
                        <div>
                            <div class="text-slate-200 text-sm font-medium">{{ preset.nama }}</div>
                            <div class="text-slate-500 text-xs mt-0.5">{{ preset.keterangan ?? '-' }}</div>
                            <div class="mt-1 text-xs">
                                <span v-if="namaLawan(preset).length" class="text-emerald-300">Memotong: {{ namaLawan(preset).join(', ') }}</span>
                                <span v-else class="text-slate-600">Hanya label (tidak memotong biaya)</span>
                                <button v-if="!lawanForms[preset.id]" @click="bukaLawan(preset)" class="ml-2 text-violet-400 hover:text-violet-300">Atur lawan</button>
                            </div>
                            <div v-if="lawanForms[preset.id]" class="mt-2 p-3 bg-slate-800/60 border border-slate-700 rounded-lg space-y-2">
                                <p class="text-slate-400 text-xs">Pilih biaya yang boleh dipotong promo ini. Nominal atau persen potongannya diisi per transaksi saat booking.</p>
                                <label class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer">
                                    <input type="checkbox" v-model="lawanForms[preset.id].harga_jual" class="rounded border-slate-600 bg-slate-800 text-violet-500" /> Harga Jual (diskon umum)
                                </label>
                                <label class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer">
                                    <input type="checkbox" v-model="lawanForms[preset.id].tanah" class="rounded border-slate-600 bg-slate-800 text-violet-500" /> Kelebihan Tanah
                                </label>
                                <label v-for="b in biayaTambahanPresets" :key="b.id" class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer">
                                    <input type="checkbox" :checked="lawanForms[preset.id].biaya_tambahan_preset_ids.includes(b.id)" @change="toggleLawanBt(preset.id, b.id)"
                                        class="rounded border-slate-600 bg-slate-800 text-violet-500" /> {{ b.nama }}
                                </label>
                                <div class="flex gap-2 pt-1">
                                    <button @click="simpanLawan(preset)" class="px-3 py-1.5 bg-violet-600 hover:bg-violet-500 text-white text-xs rounded-lg">Simpan</button>
                                    <button @click="delete lawanForms[preset.id]" class="px-3 py-1.5 text-slate-400 hover:text-slate-200 text-xs">Batal</button>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
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
                </div>
                <div v-else class="px-5 py-8 text-center text-slate-500 text-sm">
                    Belum ada preset promo.
                </div>

                <!-- Add New -->
                <div class="px-5 py-4 border-t border-slate-800 bg-slate-800/20">
                    <div class="flex gap-3">
                        <input v-model="addForm.nama" type="text" placeholder="Nama promo (mis. Promo Kemerdekaan)"
                            @keyup.enter="addPreset"
                            :class="{ 'border-rose-500': addForm.errors.nama }"
                            class="flex-1 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        <input v-model="addForm.keterangan" type="text" placeholder="Keterangan (opsional)"
                            @keyup.enter="addPreset"
                            class="flex-1 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        <button @click="addPreset" :disabled="addForm.processing || !addForm.nama"
                            class="px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors whitespace-nowrap">
                            + Tambah
                        </button>
                    </div>
                </div>
            </div>
    </PengaturanLayout>
</template>

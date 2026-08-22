<script setup>
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import { useForm, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    presets: Array,
    kategoriOptions: Object,
});

const addForm = useForm({
    nama: '',
    kategori: 'dajam',
    keterangan: '',
});

const addPreset = () => {
    addForm.post(route('pengaturan.dajam-sbum.store'), {
        onSuccess: () => addForm.reset('nama', 'keterangan'),
    });
};

const toggleActive = (preset) => {
    router.patch(route('pengaturan.dajam-sbum.update', preset.id), {
        nama: preset.nama,
        kategori: preset.kategori,
        keterangan: preset.keterangan,
        is_active: !preset.is_active,
    }, { preserveScroll: true });
};

const delForm = useForm({});
const destroy = (preset) => {
    if (confirm(`Hapus preset "${preset.nama}"?`)) {
        delForm.delete(route('pengaturan.dajam-sbum.destroy', preset.id));
    }
};

const presetsByKategori = computed(() => {
    const groups = {};
    Object.keys(props.kategoriOptions).forEach(key => { groups[key] = []; });
    props.presets.forEach(p => { (groups[p.kategori] ??= []).push(p); });
    return groups;
});
</script>

<template>
    <PengaturanLayout title="Dana Jaminan & SBUM">
            <div>
                <h1 class="text-white font-bold text-xl">Dana Jaminan & SBUM</h1>
                <p class="text-slate-400 text-sm mt-0.5">
                    Daftar item Dana Jaminan (Sertifikat/BBN, Air, Listrik/PLN, IMB/PBG, dll), SBUM, & Biaya Akad yang berlaku
                    global — bukan per-proyek atau per-cara-bayar. Tidak ada nominal di sini; nominal ditentukan sales secara
                    fleksibel per konsumen/bank saat booking.
                </p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                <div v-for="(label, kategori) in kategoriOptions" :key="kategori"
                    class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-800">
                        <h3 class="text-white font-semibold text-sm">{{ label }}</h3>
                    </div>
                    <div v-if="presetsByKategori[kategori]?.length">
                        <div v-for="preset in presetsByKategori[kategori]" :key="preset.id"
                            class="flex items-center justify-between px-5 py-3 border-b border-slate-800 hover:bg-slate-800/30 transition-colors group"
                            :class="{ 'opacity-50': !preset.is_active }">
                            <div>
                                <div class="text-slate-200 text-sm font-medium">{{ preset.nama }}</div>
                                <div v-if="preset.keterangan" class="text-slate-500 text-xs mt-0.5">{{ preset.keterangan }}</div>
                            </div>
                            <div class="flex items-center gap-2">
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
                    <div v-else class="px-5 py-6 text-center text-slate-600 text-sm">Belum ada item {{ label }}.</div>
                </div>
            </div>

            <!-- Add New -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <h3 class="text-white font-semibold text-sm mb-3">+ Tambah Item</h3>
                <div class="flex gap-3">
                    <select v-model="addForm.kategori"
                        class="w-40 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option v-for="(label, key) in kategoriOptions" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <input v-model="addForm.nama" type="text" placeholder="Nama item (mis. Biaya Akad Notaris)"
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
    </PengaturanLayout>
</template>

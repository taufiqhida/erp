<script setup>
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import { useForm, router } from '@inertiajs/vue3';

const props = defineProps({
    presets: Array,
});

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
const destroy = (preset) => {
    if (confirm(`Hapus preset "${preset.nama}"?`)) {
        delForm.delete(route('pengaturan.promo.destroy', preset.id));
    }
};
</script>

<template>
    <PengaturanLayout title="Preset Promo">
            <div>
                <h1 class="text-white font-bold text-xl">Preset Promo</h1>
                <p class="text-slate-400 text-sm mt-0.5">Daftar nama promo/diskon yang bisa dipilih sales saat booking — persentase atau nominalnya diisi fleksibel per transaksi, bukan di sini</p>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div v-if="presets.length">
                    <div v-for="preset in presets" :key="preset.id"
                        class="flex items-center justify-between px-5 py-3 border-b border-slate-800 hover:bg-slate-800/30 transition-colors group"
                        :class="{ 'opacity-50': !preset.is_active }">
                        <div>
                            <div class="text-slate-200 text-sm font-medium">{{ preset.nama }}</div>
                            <div class="text-slate-500 text-xs mt-0.5">{{ preset.keterangan ?? '-' }}</div>
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
                <div v-else class="px-5 py-8 text-center text-slate-600 text-sm">
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

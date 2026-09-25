<script setup>
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import UrutanButtons from '@/Components/UrutanButtons.vue';
import { useForm, router } from '@inertiajs/vue3';

const props = defineProps({
    presets: Array,
});

const addForm = useForm({
    nama: '',
    nominal: '',
    include_booking_fee: false,
    include_dp: false,
});

const addPreset = () => {
    addForm.post(route('pengaturan.program-all-in.store'), {
        onSuccess: () => addForm.reset(),
    });
};

const toggleActive = (preset) => {
    router.patch(route('pengaturan.program-all-in.update', preset.id), {
        nama: preset.nama,
        nominal: preset.nominal,
        include_booking_fee: preset.include_booking_fee,
        include_dp: preset.include_dp,
        is_active: !preset.is_active,
    }, { preserveScroll: true });
};

const delForm = useForm({});
const destroy = (preset) => {
    if (confirm(`Hapus Program All In "${preset.nama}"?`)) {
        delForm.delete(route('pengaturan.program-all-in.destroy', preset.id));
    }
};

const formatRp = (v) => v ? 'Rp ' + Number(v).toLocaleString('id-ID') : 'Rp 0';

const includeLabel = (preset) => {
    const parts = [];
    if (preset.include_booking_fee) parts.push('Booking Fee');
    if (preset.include_dp) parts.push('DP');
    return parts.length ? `Termasuk ${parts.join(' + ')}` : 'Tidak termasuk Booking Fee/DP';
};
</script>

<template>
    <PengaturanLayout title="Program All In">
            <div>
                <h1 class="text-white font-bold text-xl">Program All In</h1>
                <p class="text-slate-400 text-sm mt-0.5">
                    Paket bundel nominal All In yang bisa dipilih konsumen saat booking. Kalau nominalnya sudah termasuk
                    Booking Fee dan/atau DP, sisanya otomatis jadi baris "Titipan Biaya Akad" di Kartu Piutang.
                </p>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div v-if="presets.length">
                    <div v-for="(preset, idx) in presets" :key="preset.id"
                        class="flex items-center justify-between px-5 py-3 border-b border-slate-800 hover:bg-slate-800/30 transition-colors group"
                        :class="{ 'opacity-50': !preset.is_active }">
                        <div>
                            <div class="text-slate-200 text-sm font-medium">{{ preset.nama }} · {{ formatRp(preset.nominal) }}</div>
                            <div class="text-slate-500 text-xs mt-0.5">{{ includeLabel(preset) }}</div>
                        </div>
                        <div class="flex items-center gap-3">
                            <UrutanButtons type="program-all-in" :id="preset.id" :first="idx === 0" :last="idx === presets.length - 1" />
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
                    <div class="flex items-center gap-4">
                        <label class="flex items-center gap-2 text-slate-300 text-sm cursor-pointer">
                            <input v-model="addForm.include_booking_fee" type="checkbox" class="accent-violet-500" />
                            Termasuk Booking Fee
                        </label>
                        <label class="flex items-center gap-2 text-slate-300 text-sm cursor-pointer">
                            <input v-model="addForm.include_dp" type="checkbox" class="accent-violet-500" />
                            Termasuk DP
                        </label>
                        <button @click="addPreset" :disabled="addForm.processing || !addForm.nama || !addForm.nominal"
                            class="ml-auto px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors whitespace-nowrap">
                            + Tambah
                        </button>
                    </div>
                </div>
            </div>
    </PengaturanLayout>
</template>

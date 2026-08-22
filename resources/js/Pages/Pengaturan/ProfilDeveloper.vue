<script setup>
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import { useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    profile: Object,
    banks:   Array,
});

const form = useForm({
    nama_developer:     props.profile.nama_developer ?? '',
    alamat:             props.profile.alamat ?? '',
    telepon:            props.profile.telepon ?? '',
    email:              props.profile.email ?? '',
    npwp:               props.profile.npwp ?? '',
    logo:               null,
    kop_surat:          null,
});

// ── Rekening bank (multi-bank) ──────────────────────────────────────────
const bankForm = useForm({
    nama_bank: '',
    nomor_rekening: '',
    atas_nama_rekening: '',
});

const addBank = () => {
    bankForm.post(route('pengaturan.profil-developer.banks.store'), {
        onSuccess: () => bankForm.reset(),
    });
};

const setPrimaryBank = (bank) => {
    router.patch(route('pengaturan.profil-developer.banks.primary', bank.id), {}, { preserveScroll: true });
};

const deleteBankForm = useForm({});
const deleteBank = (bank) => {
    if (confirm(`Hapus rekening ${bank.nama_bank}?`)) {
        deleteBankForm.delete(route('pengaturan.profil-developer.banks.destroy', bank.id), { preserveScroll: true });
    }
};

const logoPreview    = ref(props.profile.logo_url ?? null);
const kopSuratPreview = ref(props.profile.kop_surat_url ?? null);

const onLogoChange = (e) => {
    const file = e.target.files[0];
    if (!file) return;
    form.logo = file;
    logoPreview.value = URL.createObjectURL(file);
};
const onKopSuratChange = (e) => {
    const file = e.target.files[0];
    if (!file) return;
    form.kop_surat = file;
    kopSuratPreview.value = URL.createObjectURL(file);
};

const save = () => {
    form.patch(route('pengaturan.profil-developer.update'), {
        forceFormData: true,
        onSuccess: () => {},
    });
};
</script>

<template>
    <PengaturanLayout title="Profil Developer">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Form -->
                <div class="lg:col-span-2 bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-5">
                    <h2 class="text-white font-semibold text-lg">Informasi Developer</h2>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="sm:col-span-2">
                            <label class="block text-slate-400 text-xs mb-1.5 font-medium">Nama Developer / PT</label>
                            <input v-model="form.nama_developer" type="text" placeholder="PT. Developer Indonesia"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs mb-1.5 font-medium">Telepon</label>
                            <input v-model="form.telepon" type="text" placeholder="021-xxxxxxxx"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs mb-1.5 font-medium">Email</label>
                            <input v-model="form.email" type="email" placeholder="info@developer.com"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs mb-1.5 font-medium">NPWP</label>
                            <input v-model="form.npwp" type="text" placeholder="00.000.000.0-000.000"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-slate-400 text-xs mb-1.5 font-medium">Alamat</label>
                            <textarea v-model="form.alamat" rows="2" placeholder="Jl. ..."
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500 resize-none" />
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button @click="save" :disabled="form.processing"
                            class="px-5 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 disabled:opacity-60 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20">
                            {{ form.processing ? 'Menyimpan...' : 'Simpan Perubahan' }}
                        </button>
                    </div>
                </div>

                <!-- Rekening Bank (multi-bank) -->
                <div class="lg:col-span-2 bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-800">
                        <h2 class="text-white font-semibold text-lg">Rekening Bank</h2>
                        <p class="text-slate-500 text-xs mt-0.5">Bisa lebih dari satu rekening; salah satu ditandai sebagai utama</p>
                    </div>
                    <div v-if="banks.length">
                        <div v-for="bank in banks" :key="bank.id"
                            class="flex items-center justify-between px-6 py-3.5 border-b border-slate-800 hover:bg-slate-800/30 transition-colors group">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-slate-200 text-sm font-medium">{{ bank.nama_bank }}</span>
                                    <span v-if="bank.is_primary" class="px-1.5 py-0.5 bg-violet-500/15 text-violet-300 text-[10px] font-medium rounded">UTAMA</span>
                                </div>
                                <div class="text-slate-500 text-xs mt-0.5">{{ bank.nomor_rekening ?? '-' }} a.n. {{ bank.atas_nama_rekening ?? '-' }}</div>
                            </div>
                            <div class="flex items-center gap-2">
                                <button v-if="!bank.is_primary" @click="setPrimaryBank(bank)"
                                    class="opacity-0 group-hover:opacity-100 px-2 py-1 text-violet-400 hover:bg-violet-500/10 rounded-lg text-xs transition-all whitespace-nowrap">
                                    Jadikan Utama
                                </button>
                                <button @click="deleteBank(bank)"
                                    class="opacity-0 group-hover:opacity-100 px-2 py-1 text-rose-400 hover:bg-rose-500/10 rounded-lg text-xs transition-all">
                                    Hapus
                                </button>
                            </div>
                        </div>
                    </div>
                    <div v-else class="px-6 py-6 text-center text-slate-600 text-sm">
                        Belum ada rekening bank.
                    </div>

                    <!-- Add New Bank -->
                    <div class="px-6 py-4 border-t border-slate-800 bg-slate-800/20">
                        <div class="grid grid-cols-3 gap-3">
                            <input v-model="bankForm.nama_bank" type="text" placeholder="Nama Bank (mis. BRI)"
                                :class="{ 'border-rose-500': bankForm.errors.nama_bank }"
                                class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            <input v-model="bankForm.nomor_rekening" type="text" placeholder="Nomor Rekening"
                                class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            <input v-model="bankForm.atas_nama_rekening" type="text" placeholder="Atas Nama"
                                class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        </div>
                        <div class="flex justify-end mt-3">
                            <button @click="addBank" :disabled="bankForm.processing || !bankForm.nama_bank"
                                class="px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors">
                                + Tambah Rekening
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Logo & Kop Surat -->
                <div class="space-y-4">
                    <!-- Logo -->
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-3">
                        <h3 class="text-slate-300 font-medium text-sm">Logo Perusahaan</h3>
                        <div class="flex items-center justify-center h-24 bg-slate-800 rounded-lg border border-dashed border-slate-700">
                            <img v-if="logoPreview" :src="logoPreview" class="max-h-20 max-w-full object-contain" alt="Logo" />
                            <span v-else class="text-slate-600 text-sm">Belum ada logo</span>
                        </div>
                        <label class="block">
                            <span class="block text-xs text-slate-500 mb-1.5">PNG/SVG, maks 2MB</span>
                            <input type="file" accept="image/png,image/jpeg,image/svg+xml" @change="onLogoChange"
                                class="block w-full text-xs text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:bg-slate-700 file:text-slate-300 hover:file:bg-slate-600 cursor-pointer" />
                        </label>
                    </div>

                    <!-- Kop Surat -->
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-3">
                        <h3 class="text-slate-300 font-medium text-sm">Kop Surat</h3>
                        <div class="flex items-center justify-center h-24 bg-slate-800 rounded-lg border border-dashed border-slate-700">
                            <img v-if="kopSuratPreview" :src="kopSuratPreview" class="max-h-20 max-w-full object-contain" alt="Kop Surat" />
                            <span v-else class="text-slate-600 text-sm">Belum ada kop surat</span>
                        </div>
                        <label class="block">
                            <span class="block text-xs text-slate-500 mb-1.5">PNG/JPG, maks 5MB</span>
                            <input type="file" accept="image/png,image/jpeg" @change="onKopSuratChange"
                                class="block w-full text-xs text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:bg-slate-700 file:text-slate-300 hover:file:bg-slate-600 cursor-pointer" />
                        </label>
                    </div>
                </div>
            </div>
    </PengaturanLayout>
</template>

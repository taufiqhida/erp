<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    profile: Object,
});

const form = useForm({
    nama_developer:     props.profile.nama_developer ?? '',
    alamat:             props.profile.alamat ?? '',
    telepon:            props.profile.telepon ?? '',
    email:              props.profile.email ?? '',
    npwp:               props.profile.npwp ?? '',
    nama_bank:          props.profile.nama_bank ?? '',
    nomor_rekening:     props.profile.nomor_rekening ?? '',
    atas_nama_rekening: props.profile.atas_nama_rekening ?? '',
    logo:               null,
    kop_surat:          null,
});

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

// Pengaturan sub-nav
const subNav = [
    { label: 'Profil Developer',     href: 'pengaturan.profil-developer', route: 'pengaturan.profil-developer' },
    { label: 'Template Pemberkasan', href: 'pengaturan.dokumen-templates', route: 'pengaturan.dokumen-templates' },
    { label: 'Pembiayaan Proyek',    href: 'pengaturan.pembiayaan',        route: 'pengaturan.pembiayaan' },
    { label: 'Template Surat',       href: 'pengaturan.surat-templates',   route: 'pengaturan.surat-templates' },
];
</script>

<template>
    <Head title="Pengaturan – Profil Developer" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <span class="text-slate-200 font-medium">Pengaturan</span>
                <span>/</span>
                <span class="text-slate-400">Profil Developer</span>
            </div>
        </template>

        <div class="p-6 space-y-5">
            <!-- Sub Nav -->
            <div class="flex gap-1 bg-slate-900 border border-slate-800 rounded-xl p-1 w-fit flex-wrap">
                <Link v-for="nav in subNav" :key="nav.route"
                    :href="route(nav.href)"
                    :class="$page.url.startsWith('/pengaturan/' + nav.href.replace('pengaturan.', '').replace(/-/g, '-'))
                        ? 'bg-violet-600 text-white shadow-lg shadow-violet-500/20'
                        : 'text-slate-400 hover:text-slate-200'"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap">
                    {{ nav.label }}
                </Link>
            </div>

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

                    <div class="border-t border-slate-800 pt-5 space-y-4">
                        <h3 class="text-slate-300 font-medium text-sm">Informasi Bank</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5 font-medium">Nama Bank</label>
                                <input v-model="form.nama_bank" type="text" placeholder="BRI"
                                    class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5 font-medium">Nomor Rekening</label>
                                <input v-model="form.nomor_rekening" type="text" placeholder="1234567890"
                                    class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1.5 font-medium">Atas Nama</label>
                                <input v-model="form.atas_nama_rekening" type="text" placeholder="PT. ..."
                                    class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button @click="save" :disabled="form.processing"
                            class="px-5 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 disabled:opacity-60 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20">
                            {{ form.processing ? 'Menyimpan...' : 'Simpan Perubahan' }}
                        </button>
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
        </div>
    </AuthenticatedLayout>
</template>

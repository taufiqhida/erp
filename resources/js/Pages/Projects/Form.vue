<script setup>
import BerandaLayout from '@/Layouts/BerandaLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    project: Object, // null = create, filled = edit
});

const isEdit = !!props.project?.id;

const form = useForm({
    nama:             props.project?.nama ?? '',
    kode:             props.project?.kode ?? '',
    deskripsi:        props.project?.deskripsi ?? '',
    lokasi:           props.project?.lokasi ?? '',
    kota:             props.project?.kota ?? '',
    luas_tanah_total: props.project?.luas_tanah_total ?? '',
    is_active:        props.project?.is_active ?? true,
    siteplan_image:   null,
});

const siteplanPreview = ref(props.project?.siteplan_image ?? null);

const onSiteplanChange = (e) => {
    const file = e.target.files[0];
    if (!file) return;
    form.siteplan_image = file;
    siteplanPreview.value = URL.createObjectURL(file);
};

const submit = () => {
    if (isEdit) {
        form.transform((data) => ({ ...data, _method: 'PUT' }))
            .post(route('projects.update', props.project.id), { forceFormData: true });
    } else {
        form.post(route('projects.store'), { forceFormData: true });
    }
};
</script>

<template>
    <Head :title="isEdit ? 'Edit Proyek' : 'Tambah Proyek'" />
    <BerandaLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <Link :href="route('beranda')" class="hover:text-slate-200 transition-colors">Proyek</Link>
                <span>/</span>
                <span class="text-slate-200 font-medium">{{ isEdit ? 'Edit' : 'Tambah' }}</span>
            </div>
        </template>

        <div class="p-6 max-w-2xl mx-auto">
            <div class="mb-6">
                <h1 class="text-white font-bold text-xl">{{ isEdit ? 'Edit Proyek' : 'Tambah Proyek Baru' }}</h1>
                <p class="text-slate-400 text-sm mt-0.5">{{ isEdit ? 'Perbarui informasi proyek' : 'Isi informasi proyek properti baru' }}</p>
            </div>

            <form @submit.prevent="submit" class="space-y-5">
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4">
                    <h2 class="text-slate-300 font-medium text-sm border-b border-slate-800 pb-3">Informasi Dasar</h2>

                    <!-- Nama + Kode -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">Nama Proyek <span class="text-rose-400">*</span></label>
                            <input
                                v-model="form.nama"
                                type="text"
                                placeholder="Contoh: Villa Hijau Asri"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500 focus:border-violet-500"
                                :class="{ 'border-rose-500': form.errors.nama }"
                            />
                            <p v-if="form.errors.nama" class="text-rose-400 text-xs mt-1">{{ form.errors.nama }}</p>
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">Kode Proyek <span class="text-rose-400">*</span></label>
                            <input
                                v-model="form.kode"
                                type="text"
                                placeholder="Contoh: VILLA-01"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500 focus:border-violet-500"
                                :class="{ 'border-rose-500': form.errors.kode }"
                            />
                            <p v-if="form.errors.kode" class="text-rose-400 text-xs mt-1">{{ form.errors.kode }}</p>
                        </div>
                    </div>

                    <!-- Deskripsi -->
                    <div>
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Deskripsi</label>
                        <textarea
                            v-model="form.deskripsi"
                            rows="3"
                            placeholder="Deskripsi singkat proyek..."
                            class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500 focus:border-violet-500 resize-none"
                        />
                    </div>

                    <!-- Lokasi + Kota -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">Alamat / Lokasi</label>
                            <input
                                v-model="form.lokasi"
                                type="text"
                                placeholder="Jl. Contoh No. 1"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500"
                            />
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">Kota</label>
                            <input
                                v-model="form.kota"
                                type="text"
                                placeholder="Contoh: Bandung"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500"
                            />
                        </div>
                    </div>
                </div>

                <!-- Luas Tanah + Siteplan -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4">
                    <h2 class="text-slate-300 font-medium text-sm border-b border-slate-800 pb-3">Lahan & Siteplan</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">Total Luas Tanah (m²)</label>
                            <input
                                v-model="form.luas_tanah_total"
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="Contoh: 15000"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500"
                            />
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">Upload Siteplan</label>
                            <input
                                type="file"
                                accept="image/png,image/jpeg,image/jpg,image/svg+xml"
                                @change="onSiteplanChange"
                                class="block w-full text-xs text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:bg-slate-700 file:text-slate-300 hover:file:bg-slate-600 cursor-pointer"
                            />
                            <p class="text-slate-600 text-xs mt-1">PNG / JPG / SVG, maks 10MB</p>
                        </div>
                    </div>
                    <!-- Siteplan Preview -->
                    <div v-if="siteplanPreview" class="mt-2">
                        <p class="text-slate-500 text-xs mb-2">Preview:</p>
                        <img :src="siteplanPreview" class="max-h-40 rounded-lg border border-slate-700 object-contain" alt="Preview Siteplan" />
                    </div>
                </div>

                <!-- Pengaturan -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4">
                    <h2 class="text-slate-300 font-medium text-sm border-b border-slate-800 pb-3">Pengaturan</h2>
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            @click="form.is_active = !form.is_active"
                            :class="form.is_active ? 'bg-violet-600' : 'bg-slate-700'"
                            class="relative inline-flex h-5 w-10 items-center rounded-full transition-colors focus:outline-none"
                        >
                            <span
                                :class="form.is_active ? 'translate-x-5' : 'translate-x-1'"
                                class="inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform"
                            />
                        </button>
                        <span class="text-slate-300 text-sm">Proyek Aktif</span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end gap-3">
                    <Link
                        :href="route('beranda')"
                        class="px-4 py-2.5 text-slate-400 hover:text-slate-200 text-sm font-medium transition-colors"
                    >
                        Batal
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-5 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 disabled:opacity-60 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20"
                    >
                        {{ form.processing ? 'Menyimpan...' : (isEdit ? 'Simpan Perubahan' : 'Buat Proyek') }}
                    </button>
                </div>
            </form>
        </div>
    </BerandaLayout>
</template>

<script setup>
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    template: Object,    // null = create
    placeholders: Object,
    jadwalPlaceholders: Object,
});

const isEdit = !!props.template?.id;

const form = useForm({
    nama: props.template?.nama ?? '',
    file: null,
});

const fileName = ref(props.template?.file_original_name ?? null);
const onFileChange = (e) => {
    const file = e.target.files[0];
    if (!file) return;
    form.file = file;
    fileName.value = file.name;
};

const submit = () => {
    if (isEdit) {
        // Upload file butuh multipart POST — route PATCH dicapai lewat
        // _method spoofing (pola standar Inertia untuk file + PATCH/PUT).
        form.transform((data) => ({ ...data, _method: 'patch' }))
            .post(route('pengaturan.surat-templates.update', props.template.id), { forceFormData: true });
    } else {
        form.post(route('pengaturan.surat-templates.store'), { forceFormData: true });
    }
};
</script>

<template>
    <PengaturanLayout :title="isEdit ? 'Template Surat — Edit' : 'Template Surat — Buat Baru'">
            <h1 class="text-white font-bold text-xl">{{ isEdit ? 'Edit Template' : 'Buat Template Baru' }}</h1>

            <div class="grid grid-cols-12 gap-5">
                <!-- Form -->
                <div class="col-span-12 lg:col-span-7 space-y-4">
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4">
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">Nama Template <span class="text-rose-400">*</span></label>
                            <input v-model="form.nama" type="text" placeholder="Contoh: SPR, Surat Penawaran Pembiayaan"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                                :class="{ 'border-rose-500': form.errors.nama }" />
                            <p v-if="form.errors.nama" class="text-rose-400 text-xs mt-1">{{ form.errors.nama }}</p>
                        </div>

                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">
                                File Template (.docx) <span v-if="!isEdit" class="text-rose-400">*</span>
                            </label>
                            <label class="flex items-center justify-center gap-2 px-4 py-6 bg-slate-800 border border-dashed border-slate-700 rounded-lg cursor-pointer hover:border-violet-500 transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-slate-500">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l-3.75 3.75M12 9.75l3.75 3.75M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z" />
                                </svg>
                                <span class="text-slate-400 text-sm">{{ fileName ?? 'Klik untuk pilih file .docx' }}</span>
                                <input type="file" accept=".docx" class="hidden" @change="onFileChange" />
                            </label>
                            <p v-if="isEdit" class="text-slate-600 text-xs mt-1">Kosongkan kalau tidak mau ganti file.</p>
                            <p v-if="form.errors.file" class="text-rose-400 text-xs mt-1">{{ form.errors.file }}</p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex gap-3 justify-end">
                        <Link :href="route('pengaturan.surat-templates')"
                            class="px-4 py-2.5 text-slate-400 hover:text-slate-200 text-sm border border-slate-700 rounded-lg transition-colors">
                            Batal
                        </Link>
                        <button @click="submit" :disabled="form.processing"
                            class="px-5 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 disabled:opacity-60 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20">
                            {{ form.processing ? 'Menyimpan...' : 'Simpan Template' }}
                        </button>
                    </div>
                </div>

                <!-- Placeholder reference panel -->
                <div class="col-span-12 lg:col-span-5 space-y-3">
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 sticky top-4 max-h-[75vh] overflow-y-auto">
                        <h3 class="text-slate-300 font-medium text-sm mb-1">Placeholder yang tersedia</h3>
                        <p class="text-slate-500 text-xs mb-3">Ketik persis seperti ini langsung di file Word kamu (bukan di sini) sebelum diupload.</p>
                        <div class="space-y-1">
                            <div v-for="(label, key) in placeholders" :key="key"
                                class="flex items-center justify-between gap-2 px-2.5 py-1.5 bg-slate-800 rounded-lg">
                                <span class="text-violet-300 text-xs font-mono">{{ key }}</span>
                                <span class="text-slate-500 text-[11px] text-right">{{ label }}</span>
                            </div>
                        </div>

                        <h3 class="text-slate-300 font-medium text-sm mb-1 mt-4 pt-3 border-t border-slate-800">Tabel Jadwal Pembayaran</h3>
                        <p class="text-slate-500 text-xs mb-3">Khusus SPR — taruh di 1 baris tabel, di-clone otomatis sesuai jumlah cicilan.</p>
                        <div class="space-y-1">
                            <div v-for="(label, key) in jadwalPlaceholders" :key="key"
                                class="flex items-center justify-between gap-2 px-2.5 py-1.5 bg-slate-800 rounded-lg">
                                <span class="text-violet-300 text-xs font-mono">{{ key }}</span>
                                <span class="text-slate-500 text-[11px] text-right">{{ label }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
    </PengaturanLayout>
</template>

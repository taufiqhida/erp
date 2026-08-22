<script setup>
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    template: Object,    // null = create
    jenisOptions: Object,
});

const isEdit = !!props.template?.id;

const form = useForm({
    nama:    props.template?.nama ?? '',
    jenis:   props.template?.jenis ?? Object.keys(props.jenisOptions)[0] ?? 'booking',
    konten:  props.template?.konten ?? '',
});

// Live preview
const preview = computed(() => {
    const sample = {
        '{{konsumen_nama}}':  'Budi Santoso',
        '{{konsumen_nik}}':   '3210001122334455',
        '{{konsumen_no_hp}}': '08123456789',
        '{{kavling_nomor}}':  'A-05',
        '{{kavling_blok}}':   'A',
        '{{project_nama}}':   'Perumahan Sejahtera 1',
        '{{harga_deal}}':     'Rp 198.000.000',
        '{{booking_fee}}':    'Rp 2.000.000',
        '{{cara_bayar}}':     'KPR Subsidi',
        '{{tanggal_booking}}': new Date().toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }),
        '{{developer_nama}}':  'PT. Properti Makmur Indonesia',
        '{{developer_alamat}}': 'Jl. Contoh No. 1, Jakarta Selatan',
    };
    let html = form.konten ?? '';
    Object.entries(sample).forEach(([key, val]) => {
        html = html.replaceAll(key, `<span class="bg-violet-500/20 text-violet-300 px-0.5 rounded">${val}</span>`);
    });
    return html;
});

const showPreview = ref(false);

const submit = () => {
    if (isEdit) {
        form.patch(route('pengaturan.surat-templates.update', props.template.id));
    } else {
        form.post(route('pengaturan.surat-templates.store'));
    }
};

// Insert variable at cursor
const textarea = ref(null);
const insertVar = (v) => {
    const el = textarea.value;
    if (!el) { form.konten += v; return; }
    const start = el.selectionStart;
    const end   = el.selectionEnd;
    form.konten = form.konten.substring(0, start) + v + form.konten.substring(end);
    // Restore cursor after inserted text
    setTimeout(() => {
        el.selectionStart = el.selectionEnd = start + v.length;
        el.focus();
    }, 0);
};

const templateVars = [
    '{{konsumen_nama}}', '{{konsumen_nik}}', '{{konsumen_no_hp}}',
    '{{kavling_nomor}}', '{{kavling_blok}}', '{{project_nama}}',
    '{{harga_deal}}', '{{booking_fee}}', '{{cara_bayar}}',
    '{{tanggal_booking}}', '{{developer_nama}}', '{{developer_alamat}}',
];
</script>

<template>
    <PengaturanLayout :title="isEdit ? 'Template Surat — Edit' : 'Template Surat — Buat Baru'">
            <div class="flex items-center justify-between">
                <h1 class="text-white font-bold text-xl">{{ isEdit ? 'Edit Template' : 'Buat Template Baru' }}</h1>
                <button @click="showPreview = !showPreview"
                    class="flex items-center gap-2 px-3 py-2 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg text-slate-300 text-sm transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    {{ showPreview ? 'Edit Mode' : 'Preview' }}
                </button>
            </div>

            <div class="grid grid-cols-12 gap-5">
                <!-- Form / Preview -->
                <div class="col-span-12 lg:col-span-8 space-y-4">
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-slate-400 text-xs font-medium mb-1.5">Nama Template <span class="text-rose-400">*</span></label>
                                <input v-model="form.nama" type="text" placeholder="Contoh: Surat Booking"
                                    class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                                    :class="{ 'border-rose-500': form.errors.nama }" />
                                <p v-if="form.errors.nama" class="text-rose-400 text-xs mt-1">{{ form.errors.nama }}</p>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs font-medium mb-1.5">Jenis Surat</label>
                                <select v-model="form.jenis"
                                    class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                                    <option v-for="(label, key) in jenisOptions" :key="key" :value="key">{{ label }}</option>
                                </select>
                            </div>
                        </div>

                        <!-- Konten Editor -->
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label class="text-slate-400 text-xs font-medium">Konten Surat <span class="text-rose-400">*</span></label>
                                <span class="text-slate-600 text-xs">Mendukung HTML sederhana</span>
                            </div>

                            <!-- Preview Mode -->
                            <div v-if="showPreview"
                                class="min-h-[400px] bg-white text-slate-900 rounded-lg p-6 text-sm leading-relaxed"
                                v-html="preview" />

                            <!-- Edit Mode -->
                            <textarea v-else
                                ref="textarea"
                                v-model="form.konten"
                                rows="20"
                                placeholder="Ketik konten surat di sini. Gunakan variabel dari panel kanan..."
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm font-mono focus:outline-none focus:ring-1 focus:ring-violet-500 resize-y"
                                :class="{ 'border-rose-500': form.errors.konten }" />
                            <p v-if="form.errors.konten" class="text-rose-400 text-xs mt-1">{{ form.errors.konten }}</p>
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
                            {{ form.processing ? 'Menyimpan...' : '💾 Simpan Template' }}
                        </button>
                    </div>
                </div>

                <!-- Variables Panel -->
                <div class="col-span-12 lg:col-span-4 space-y-3">
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 sticky top-4">
                        <h3 class="text-slate-300 font-medium text-sm mb-3">📋 Insert Variabel</h3>
                        <p class="text-slate-500 text-xs mb-3">Klik variabel untuk menyisipkan ke posisi kursor di editor.</p>
                        <div class="space-y-1.5">
                            <button v-for="v in templateVars" :key="v"
                                @click="insertVar(v)"
                                class="w-full text-left px-2.5 py-1.5 bg-slate-800 hover:bg-violet-600/15 hover:text-violet-300 text-slate-400 text-xs font-mono rounded-lg transition-colors border border-transparent hover:border-violet-500/30">
                                {{ v }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
    </PengaturanLayout>
</template>

<script setup>
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    templates: Array,
    placeholders: Object,
    jadwalPlaceholders: Object,
});

const delForm = useForm({});

const del = (id) => {
    if (confirm('Hapus template surat ini?')) {
        delForm.delete(route('pengaturan.surat-templates.destroy', id));
    }
};
</script>

<template>
    <PengaturanLayout title="Template Surat">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-white font-bold text-xl">Template Surat</h1>
                    <p class="text-slate-400 text-sm mt-0.5">Upload file .docx (SPR, Surat Penawaran Pembiayaan, dll) — sistem isi otomatis placeholder-nya saat dicetak dari halaman Konsumen.</p>
                </div>
                <Link :href="route('pengaturan.surat-templates.create')"
                    class="inline-flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                        <path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" />
                    </svg>
                    Buat Template
                </Link>
            </div>

            <!-- Template List -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div v-if="!templates.length" class="px-5 py-12 text-center text-slate-600 text-sm">
                    Belum ada template surat. Buat template pertama Anda.
                </div>
                <div v-for="tmpl in templates" :key="tmpl.id"
                    class="flex items-center justify-between px-5 py-4 border-b border-slate-800 hover:bg-slate-800/30 transition-colors group">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 bg-slate-800 rounded-lg flex items-center justify-center text-violet-400 flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-slate-200 font-medium">{{ tmpl.nama }}</div>
                            <div class="flex items-center gap-2 mt-0.5">
                                <span class="text-slate-500 text-xs font-mono">{{ tmpl.file_original_name }}</span>
                                <span class="text-slate-700 text-xs">·</span>
                                <span class="text-slate-500 text-xs">Diperbarui {{ tmpl.updated_at }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                        <Link :href="route('pengaturan.surat-templates.edit', tmpl.id)"
                            class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-slate-300 text-xs rounded-lg transition-colors">
                            Edit
                        </Link>
                        <button @click="del(tmpl.id)"
                            class="px-3 py-1.5 bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 text-xs rounded-lg transition-colors">
                            Hapus
                        </button>
                    </div>
                </div>
            </div>

            <!-- Info placeholder -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4">
                <div>
                    <h3 class="text-slate-300 font-medium text-sm mb-1">Placeholder yang tersedia</h3>
                    <p class="text-slate-500 text-xs mb-3">Taruh persis seperti ini di file .docx kamu sebelum diupload — sistem akan isi otomatis sesuai data transaksi saat dicetak.</p>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                        <div v-for="(label, key) in placeholders" :key="key"
                            class="bg-slate-800 px-2.5 py-1.5 rounded-lg" :title="label">
                            <div class="text-xs text-violet-300 font-mono">{{ key }}</div>
                            <div class="text-[11px] text-slate-500 truncate">{{ label }}</div>
                        </div>
                    </div>
                </div>

                <div class="pt-3 border-t border-slate-800">
                    <h3 class="text-slate-300 font-medium text-sm mb-1">Tabel Jadwal Pembayaran (khusus SPR)</h3>
                    <p class="text-slate-500 text-xs mb-3">Kalau template punya tabel Jadwal Pembayaran, buat 1 baris tabel berisi placeholder ini — sistem akan clone barisnya otomatis sesuai jumlah cicilan.</p>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        <div v-for="(label, key) in jadwalPlaceholders" :key="key"
                            class="bg-slate-800 px-2.5 py-1.5 rounded-lg" :title="label">
                            <div class="text-xs text-violet-300 font-mono">{{ key }}</div>
                            <div class="text-[11px] text-slate-500 truncate">{{ label }}</div>
                        </div>
                    </div>
                </div>
            </div>
    </PengaturanLayout>
</template>

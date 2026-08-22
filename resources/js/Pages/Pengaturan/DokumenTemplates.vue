<script setup>
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import { useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    templates:        Object,
    caraBayarOptions: Object,
    sifatOptions:     Object,
});

const activeCaraBayar = ref(Object.keys(props.caraBayarOptions)[0] ?? 'kpr_subsidi');

const addForm = useForm({
    cara_bayar:   activeCaraBayar.value,
    nama_dokumen: '',
    sifat:        'wajib',
    urutan:       0,
});

const addDoc = () => {
    addForm.cara_bayar = activeCaraBayar.value;
    addForm.post(route('pengaturan.dokumen-templates.store'), {
        onSuccess: () => { addForm.reset('nama_dokumen'); }
    });
};

const sifatBadgeCls = {
    wajib:       'bg-rose-500/15 text-rose-400',
    kondisional: 'bg-amber-500/15 text-amber-400',
    opsional:    'bg-slate-700 text-slate-400',
};

const changeSifat = (doc, sifat) => {
    if (doc.sifat === sifat) return;
    router.patch(route('pengaturan.dokumen-templates.update', doc.id), {
        nama_dokumen: doc.nama_dokumen,
        sifat,
        urutan: doc.urutan,
    }, { preserveScroll: true });
};

const delForm = useForm({});
const del = (id) => {
    if (confirm('Hapus template dokumen ini?')) {
        delForm.delete(route('pengaturan.dokumen-templates.destroy', id));
    }
};
</script>

<template>
    <PengaturanLayout title="Template Pemberkasan">
            <div>
                <h1 class="text-white font-bold text-xl">Template Dokumen Pemberkasan</h1>
                <p class="text-slate-400 text-sm mt-0.5">Checklist dokumen akan auto-generate saat booking berdasarkan cara pembayaran</p>
            </div>

            <!-- Cara Bayar Tabs -->
            <div class="flex gap-1 bg-slate-900 border border-slate-800 rounded-xl p-1 flex-wrap">
                <button v-for="(label, key) in caraBayarOptions" :key="key"
                    @click="activeCaraBayar = key"
                    :class="activeCaraBayar === key ? 'bg-violet-600 text-white shadow-lg shadow-violet-500/20' : 'text-slate-400 hover:text-slate-200'"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition-all">
                    {{ label }}
                </button>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <!-- Dokumen List -->
                <div v-if="templates[activeCaraBayar]?.length">
                    <div v-for="doc in templates[activeCaraBayar]" :key="doc.id"
                        class="flex items-center justify-between px-5 py-3 border-b border-slate-800 hover:bg-slate-800/30 transition-colors group">
                        <div class="flex items-center gap-3">
                            <div class="w-6 h-6 flex items-center justify-center rounded-md"
                                :class="sifatBadgeCls[doc.sifat]">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                </svg>
                            </div>
                            <span class="text-slate-200 text-sm">{{ doc.nama_dokumen }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <select :value="doc.sifat" @change="changeSifat(doc, $event.target.value)"
                                :class="sifatBadgeCls[doc.sifat]"
                                class="text-xs font-medium rounded-lg px-2 py-1 border-0 focus:outline-none focus:ring-1 focus:ring-violet-500 cursor-pointer">
                                <option v-for="(label, key) in sifatOptions" :key="key" :value="key">{{ label }}</option>
                            </select>
                            <button @click="del(doc.id)"
                                class="opacity-0 group-hover:opacity-100 px-2 py-1 text-rose-400 hover:bg-rose-500/10 rounded-lg text-xs transition-all">
                                Hapus
                            </button>
                        </div>
                    </div>
                </div>
                <div v-else class="px-5 py-8 text-center text-slate-600 text-sm">
                    Belum ada template dokumen untuk {{ caraBayarOptions[activeCaraBayar] }}
                </div>

                <!-- Add New -->
                <div class="px-5 py-4 border-t border-slate-800 bg-slate-800/20">
                    <div class="flex gap-3">
                        <input v-model="addForm.nama_dokumen" type="text"
                            placeholder="Nama dokumen baru..."
                            @keyup.enter="addDoc"
                            :class="{ 'border-rose-500': addForm.errors.nama_dokumen }"
                            class="flex-1 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        <select v-model="addForm.sifat"
                            class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                            <option v-for="(label, key) in sifatOptions" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <button @click="addDoc" :disabled="addForm.processing || !addForm.nama_dokumen"
                            class="px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors whitespace-nowrap">
                            + Tambah
                        </button>
                    </div>
                </div>
            </div>
    </PengaturanLayout>
</template>

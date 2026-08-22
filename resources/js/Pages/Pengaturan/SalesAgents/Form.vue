<script setup>
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    agent: Object,
    users: Array,
});

const isEdit = computed(() => !!props.agent);

const form = useForm({
    nama:                props.agent?.nama ?? '',
    tipe:                props.agent?.tipe ?? 'inhouse',
    user_id:             props.agent?.user_id ?? '',
    nik:                 props.agent?.nik ?? '',
    npwp:                props.agent?.npwp ?? '',
    no_hp:               props.agent?.no_hp ?? '',
    email:               props.agent?.email ?? '',
    nama_bank:           props.agent?.nama_bank ?? '',
    nomor_rekening:      props.agent?.nomor_rekening ?? '',
    atas_nama_rekening:  props.agent?.atas_nama_rekening ?? '',
    agency_nama:         props.agent?.agency_nama ?? '',
    komisi_tipe:         props.agent?.komisi_tipe ?? 'persen',
    komisi_nilai:        props.agent?.komisi_nilai ?? '',
});

const submit = () => {
    if (isEdit.value) {
        form.patch(route('pengaturan.sales-agents.update', props.agent.id));
    } else {
        form.post(route('pengaturan.sales-agents.store'));
    }
};
</script>

<template>
    <PengaturanLayout :title="isEdit ? 'Sales / Agent — Edit' : 'Sales / Agent — Tambah'">
        <div class="max-w-2xl">
            <h1 class="text-white font-bold text-xl mb-5">{{ isEdit ? 'Edit Sales/Agent' : 'Tambah Sales/Agent' }}</h1>

            <form @submit.prevent="submit" class="bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-5">
                <!-- Identitas -->
                <div class="space-y-3">
                    <h3 class="text-slate-300 text-sm font-medium border-b border-slate-800 pb-2">Identitas</h3>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="col-span-2">
                            <label class="block text-slate-400 text-xs mb-1.5">Nama <span class="text-rose-400">*</span></label>
                            <input v-model="form.nama" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" :class="{ 'border-rose-500': form.errors.nama }" />
                            <p v-if="form.errors.nama" class="text-rose-400 text-xs mt-1">{{ form.errors.nama }}</p>
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs mb-1.5">Tipe <span class="text-rose-400">*</span></label>
                            <select v-model="form.tipe" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                                <option value="inhouse">Inhouse</option>
                                <option value="freelance">Freelance</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs mb-1.5">Tautkan Akun (opsional)</label>
                            <select v-model="form.user_id" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                                <option value="">-- tidak ditautkan --</option>
                                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                            </select>
                            <p v-if="form.errors.user_id" class="text-rose-400 text-xs mt-1">{{ form.errors.user_id }}</p>
                        </div>
                        <div v-if="form.tipe === 'freelance'" class="col-span-2">
                            <label class="block text-slate-400 text-xs mb-1.5">Nama Agency</label>
                            <input v-model="form.agency_nama" type="text" placeholder="Nama agensi/perusahaan naungan" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs mb-1.5">NIK</label>
                            <input v-model="form.nik" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs mb-1.5">NPWP</label>
                            <input v-model="form.npwp" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs mb-1.5">No. HP</label>
                            <input v-model="form.no_hp" type="text" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs mb-1.5">Email</label>
                            <input v-model="form.email" type="email" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        </div>
                    </div>
                </div>

                <!-- Rekening -->
                <div class="space-y-3">
                    <h3 class="text-slate-300 text-sm font-medium border-b border-slate-800 pb-2">Rekening (untuk pencairan komisi)</h3>
                    <div class="grid grid-cols-3 gap-3">
                        <input v-model="form.nama_bank" type="text" placeholder="Nama Bank" class="px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        <input v-model="form.nomor_rekening" type="text" placeholder="Nomor Rekening" class="px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        <input v-model="form.atas_nama_rekening" type="text" placeholder="Atas Nama" class="px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                    </div>
                </div>

                <!-- Skema Komisi -->
                <div class="space-y-3">
                    <h3 class="text-slate-300 text-sm font-medium border-b border-slate-800 pb-2">Skema Komisi</h3>
                    <div class="grid grid-cols-2 gap-3">
                        <select v-model="form.komisi_tipe" class="px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                            <option value="persen">Persen dari Harga Deal</option>
                            <option value="nominal">Nominal per Unit</option>
                        </select>
                        <div class="relative">
                            <span v-if="form.komisi_tipe === 'nominal'" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs">Rp</span>
                            <input v-model.number="form.komisi_nilai" type="number" min="0" :placeholder="form.komisi_tipe === 'persen' ? '%' : '0'"
                                :class="form.komisi_tipe === 'nominal' ? 'pl-8' : 'pl-3'"
                                class="w-full pr-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <Link :href="route('pengaturan.sales-agents')" class="px-4 py-2.5 text-slate-400 hover:text-slate-200 text-sm">Batal</Link>
                    <button type="submit" :disabled="form.processing"
                        class="px-5 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 disabled:opacity-60 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20">
                        {{ form.processing ? 'Menyimpan...' : (isEdit ? 'Simpan Perubahan' : 'Tambah Sales/Agent') }}
                    </button>
                </div>
            </form>
        </div>
    </PengaturanLayout>
</template>

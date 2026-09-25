<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    konsumen: Object,
    jenisPekerjaanOptions: { type: Object, default: () => ({}) },
    statusPernikahanOptions: { type: Object, default: () => ({}) },
    sumberLeadOptions: { type: Array, default: () => [] },
});

const form = useForm({
    nama:      props.konsumen?.nama ?? '',
    nik:       props.konsumen?.nik ?? '',
    npwp:      props.konsumen?.npwp ?? '',
    no_hp:     props.konsumen?.no_hp ?? '',
    email:     props.konsumen?.email ?? '',
    alamat:    props.konsumen?.alamat ?? '',
    pekerjaan: props.konsumen?.pekerjaan ?? '',
    status_pernikahan: props.konsumen?.status_pernikahan ?? '',
    sumber_lead_id:    props.konsumen?.sumber_lead_id ?? '',
    referral_keterangan: props.konsumen?.referral_keterangan ?? '',
    catatan:   props.konsumen?.catatan ?? '',
});

const isReferralLead = computed(() =>
    !!props.sumberLeadOptions.find(s => s.id === form.sumber_lead_id)?.is_referral
);

const submit = () => {
    form.put(route('konsumens.update', props.konsumen.id));
};
</script>

<template>
    <Head title="Edit Konsumen" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2 text-slate-400 text-sm">
                <Link :href="route('konsumens.index')" class="hover:text-slate-200 transition-colors">Konsumen</Link>
                <span>/</span>
                <span class="text-slate-200 font-medium">Edit</span>
            </div>
        </template>

        <div class="p-6 max-w-2xl mx-auto">
            <div class="mb-6">
                <h1 class="text-white font-bold text-xl">Edit Konsumen</h1>
                <p class="text-slate-400 text-sm mt-0.5">Data identitas konsumen</p>
            </div>

            <form @submit.prevent="submit" class="space-y-5">
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4">
                    <h2 class="text-slate-300 font-medium text-sm border-b border-slate-800 pb-3">Identitas</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">Nama Lengkap <span class="text-rose-400">*</span></label>
                            <input v-model="form.nama" type="text" placeholder="Budi Santoso" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500" :class="{ 'border-rose-500': form.errors.nama }" />
                            <p v-if="form.errors.nama" class="text-rose-400 text-xs mt-1">{{ form.errors.nama }}</p>
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">NIK (KTP)</label>
                            <input v-model="form.nik" type="text" maxlength="20" placeholder="3271234567890001" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500" :class="{ 'border-rose-500': form.errors.nik }" />
                            <p v-if="form.errors.nik" class="text-rose-400 text-xs mt-1">{{ form.errors.nik }}</p>
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">NPWP</label>
                            <input v-model="form.npwp" type="text" maxlength="30" placeholder="15 atau 16 digit (opsional)" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500" :class="{ 'border-rose-500': form.errors.npwp }" />
                            <p v-if="form.errors.npwp" class="text-rose-400 text-xs mt-1">{{ form.errors.npwp }}</p>
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">No. HP / WhatsApp</label>
                            <input v-model="form.no_hp" type="text" placeholder="08123456789" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">Email</label>
                            <input v-model="form.email" type="email" placeholder="budi@email.com" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500" :class="{ 'border-rose-500': form.errors.email }" />
                            <p v-if="form.errors.email" class="text-rose-400 text-xs mt-1">{{ form.errors.email }}</p>
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">Jenis Pekerjaan</label>
                            <select v-model="form.pekerjaan" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                                <option value="">— Pilih —</option>
                                <option v-for="(label, key) in jenisPekerjaanOptions" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">Status Pernikahan</label>
                            <select v-model="form.status_pernikahan" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                                <option value="">— Pilih —</option>
                                <option v-for="(label, key) in statusPernikahanOptions" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">Sumber Lead</label>
                            <select v-model="form.sumber_lead_id" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                                <option value="">— Pilih —</option>
                                <option v-for="s in sumberLeadOptions" :key="s.id" :value="s.id">{{ s.nama }}</option>
                            </select>
                        </div>
                        <div v-if="isReferralLead">
                            <label class="block text-slate-400 text-xs font-medium mb-1.5">Keterangan Referral <span class="text-rose-400">*</span></label>
                            <input v-model="form.referral_keterangan" type="text" maxlength="150" placeholder="Direferensikan oleh siapa?"
                                class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                                :class="{ 'border-rose-500': form.errors.referral_keterangan }" />
                            <p v-if="form.errors.referral_keterangan" class="text-rose-400 text-xs mt-1">{{ form.errors.referral_keterangan }}</p>
                        </div>
                    </div>
                    <div>
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Alamat</label>
                        <textarea v-model="form.alamat" rows="2" placeholder="Jl. Merdeka No. 1, Jakarta" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500 resize-none" />
                    </div>
                    <div>
                        <label class="block text-slate-400 text-xs font-medium mb-1.5">Catatan</label>
                        <textarea v-model="form.catatan" rows="2" placeholder="Catatan tambahan..." class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-violet-500 resize-none" />
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3">
                    <Link :href="route('konsumens.index')" class="px-4 py-2.5 text-slate-400 hover:text-slate-200 text-sm font-medium transition-colors">Batal</Link>
                    <button type="submit" :disabled="form.processing" class="px-5 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 disabled:opacity-60 text-white text-sm font-medium rounded-lg transition-all shadow-lg shadow-violet-500/20">
                        {{ form.processing ? 'Menyimpan...' : 'Simpan Perubahan' }}
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>

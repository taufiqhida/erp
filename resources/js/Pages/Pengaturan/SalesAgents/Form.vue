<script setup>
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    agent: Object,
    tipeOptions: Object,
});

const isEdit = computed(() => !!props.agent);

const form = useForm({
    nama: props.agent?.nama ?? '',
    tipe: props.agent?.tipe ?? 'inhouse',
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
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-slate-400 text-xs mb-1.5">Nama <span class="text-rose-400">*</span></label>
                        <input v-model="form.nama" type="text" placeholder="Nama sales, atau nama agensi untuk asal Agen"
                            class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500"
                            :class="{ 'border-rose-500': form.errors.nama }" />
                        <p v-if="form.errors.nama" class="text-rose-400 text-xs mt-1">{{ form.errors.nama }}</p>
                    </div>
                    <div>
                        <label class="block text-slate-400 text-xs mb-1.5">Asal <span class="text-rose-400">*</span></label>
                        <select v-model="form.tipe" class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500">
                            <option v-for="(label, key) in tipeOptions" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <p v-if="form.errors.tipe" class="text-rose-400 text-xs mt-1">{{ form.errors.tipe }}</p>
                    </div>
                </div>

                <p class="text-slate-500 text-xs">Cukup nama dan asal untuk tagging. Kontak, rekening, fee, dan agensi dikelola di luar sistem ini — untuk closing dari agen, catat nama agensinya saja.</p>

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

<script setup>
// "Tutup selisih" retensi Dajam: sisa yang tidak akan dirinci lagi (biaya administrasi bank/pembulatan) dicatat sebagai satu item Dajam
// "Selisih / penyesuaian bank". Catatan alasan wajib; server memvalidasi ulang.
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    url:  { type: String, required: true },
    sisa: { type: Number, default: 0 },
});

const buka = ref(false);
const catatan = ref('');
const proses = ref(false);
const galat = ref('');
const rp = (v) => 'Rp ' + Math.round(Number(v) || 0).toLocaleString('id-ID');

const kirim = () => {
    proses.value = true;
    galat.value = '';
    router.post(props.url, { catatan: catatan.value }, {
        preserveScroll: true,
        onSuccess: () => { buka.value = false; catatan.value = ''; },
        onError: (e) => { galat.value = Object.values(e)[0] ?? 'Gagal menutup selisih.'; },
        onFinish: () => { proses.value = false; },
    });
};
</script>

<template>
    <span class="inline-flex flex-col items-end gap-1.5">
        <button v-if="!buka" type="button" class="px-2 py-1 text-xs text-slate-400 hover:text-slate-200 border border-slate-700 rounded-lg" @click="buka = true">Tutup selisih</button>
        <span v-else class="flex flex-col items-end gap-1.5">
            <span class="text-slate-400 text-[0.6875rem] max-w-xs text-right">Sisa {{ rp(sisa) }} dicatat sebagai item "Selisih / penyesuaian bank".</span>
            <input v-model="catatan" type="text" placeholder="Alasan (wajib), mis. biaya administrasi bank"
                class="w-64 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
            <span v-if="galat" class="text-rose-400 text-[0.6875rem]">{{ galat }}</span>
            <span class="flex gap-1.5">
                <button type="button" :disabled="proses || catatan.trim().length < 5" class="px-2.5 py-1 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-xs rounded-lg" @click="kirim">
                    {{ proses ? 'Menyimpan…' : 'Simpan' }}
                </button>
                <button type="button" class="px-2 py-1 text-slate-400 hover:text-slate-200 text-xs" @click="buka = false">Batal</button>
            </span>
        </span>
    </span>
</template>

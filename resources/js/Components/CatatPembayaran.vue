<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    url: { type: String, required: true },
    deleteUrl: { type: String, default: null }, // kalau ada, tampilkan aksi Hapus (reset ke belum_bayar)
    status: { type: String, default: null }, // 'belum_bayar' | 'sebagian' | 'lunas' | null (null = tidak ada aksi)
    tanggalBayar: { type: String, default: null },
    defaultJumlah: { type: [Number, String], default: '' }, // nominal yang harus dibayar — prefill saat belum ada pembayaran
    paidJumlah: { type: [Number, String], default: null }, // nominal yang sudah tercatat — prefill saat edit
    extraPayload: { type: Object, default: () => ({}) },
    canManage: { type: Boolean, default: false },
});

const todayIso = () => new Date().toISOString().slice(0, 10);

const open = ref(false);
const processing = ref(false);
const form = reactive({ jumlah: '', tanggal_bayar: todayIso(), keterangan: '' });

const hasPembayaran = () => props.status === 'sebagian' || props.status === 'lunas';

const toggle = () => {
    if (open.value) { open.value = false; return; }
    form.jumlah = (hasPembayaran() ? props.paidJumlah : props.defaultJumlah) ?? '';
    form.tanggal_bayar = todayIso();
    form.keterangan = '';
    open.value = true;
};

const submit = () => {
    processing.value = true;
    router.post(props.url, { ...form, ...props.extraPayload }, {
        preserveScroll: true,
        onSuccess: () => { open.value = false; },
        onFinish: () => { processing.value = false; },
    });
};

const remove = () => {
    if (!props.deleteUrl) return;
    if (!confirm('Batalkan pencatatan pembayaran ini? Statusnya kembali jadi Belum Bayar.')) return;
    router.delete(props.deleteUrl, { preserveScroll: true });
};
</script>

<template>
    <div v-if="canManage" class="flex-shrink-0">
        <div v-if="!open" class="flex items-center gap-1">
            <button @click="toggle"
                class="px-2 py-1 bg-violet-600/20 hover:bg-violet-600/30 text-violet-400 text-xs font-medium rounded-lg transition-colors whitespace-nowrap">
                {{ hasPembayaran() ? '✎ Edit' : '+ Catat Pembayaran' }}
            </button>
            <button v-if="hasPembayaran() && deleteUrl" @click="remove" title="Batalkan pencatatan"
                class="px-1.5 py-1 text-rose-400 hover:bg-rose-500/10 rounded-lg transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <span v-if="tanggalBayar" class="text-slate-600 text-[10px] whitespace-nowrap">{{ tanggalBayar }}</span>
        </div>
        <div v-else class="w-56 bg-slate-900 border border-slate-700 rounded-lg p-2.5 space-y-1.5">
            <input v-model="form.jumlah" type="number" placeholder="Jumlah"
                class="w-full px-2 py-1 bg-slate-800 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
            <input v-model="form.tanggal_bayar" type="date"
                class="w-full px-2 py-1 bg-slate-800 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
            <input v-model="form.keterangan" type="text" placeholder="Keterangan (opsional)"
                class="w-full px-2 py-1 bg-slate-800 border border-slate-700 rounded text-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500" />
            <div class="flex gap-1.5 justify-end pt-0.5">
                <button @click="open = false" class="px-2 py-1 text-slate-400 hover:text-slate-200 text-xs">Batal</button>
                <button @click="submit" :disabled="!form.jumlah || !form.tanggal_bayar || processing"
                    class="px-2.5 py-1 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-xs font-medium rounded-lg transition-colors">
                    {{ processing ? '...' : 'Simpan' }}
                </button>
            </div>
        </div>
    </div>
    <span v-else-if="tanggalBayar" class="text-slate-600 text-[10px] whitespace-nowrap">{{ tanggalBayar }}</span>
</template>

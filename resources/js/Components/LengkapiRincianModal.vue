<script setup>
// Pratinjau + penerapan "Lengkapi rincian dari skema" untuk transaksi BATAL hasil import — massal (satu proyek) atau satu transaksi.
// Server menghitung ulang saat diterapkan; jadwal hanya rencana (belum bayar), uang yang diterima tidak dibagi ke tagihan.
import { ref, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    url: { type: String, required: true },
});
const emit = defineEmits(['tutup', 'selesai']);

const memuat = ref(true);
const menerapkan = ref(false);
const galat = ref(null);
const hasil = ref(null);

const rp = (v) => 'Rp ' + Number(v ?? 0).toLocaleString('id-ID');

onMounted(async () => {
    try {
        const { data } = await window.axios.post(props.url, { simulasi: true });
        hasil.value = data;
    } catch (e) {
        galat.value = e.response?.data?.message ?? 'Gagal membuat pratinjau.';
    } finally {
        memuat.value = false;
    }
});

const terapkan = () => {
    menerapkan.value = true;
    router.post(props.url, {}, {
        preserveScroll: true,
        onSuccess: () => emit('selesai'),
        onError: (e) => { galat.value = Object.values(e)[0] ?? 'Gagal menerapkan.'; },
        onFinish: () => { menerapkan.value = false; },
    });
};
</script>

<template>
    <Teleport to="body">
        <div role="dialog" aria-modal="true" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="emit('tutup')" />
            <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-2xl shadow-2xl max-h-[90vh] flex flex-col">
                <div class="flex items-start justify-between p-5 border-b border-slate-800">
                    <div>
                        <h3 class="text-white font-semibold">Lengkapi Rincian dari Skema</h3>
                        <p class="text-slate-400 text-xs mt-0.5">Untuk transaksi batal hasil import: membuat harga dasar, booking fee, dan jadwal tagihan dari skema pembayaran yang dipilih. Pembayaran yang sudah tercatat tidak diubah.</p>
                    </div>
                    <button type="button" class="text-slate-500 hover:text-slate-300" aria-label="Tutup" @click="emit('tutup')">✕</button>
                </div>

                <div class="flex-1 overflow-y-auto p-5 space-y-4">
                    <p v-if="memuat" class="text-slate-400 text-sm">Menghitung…</p>
                    <p v-else-if="galat" class="text-rose-400 text-sm">{{ galat }}</p>
                    <template v-else>
                        <div class="flex flex-wrap gap-2 text-xs">
                            <span class="px-2.5 py-1 rounded-full bg-emerald-500/15 text-emerald-300">{{ hasil.bisa }} akan dilengkapi</span>
                            <span v-if="hasil.lewati" class="px-2.5 py-1 rounded-full bg-slate-800 text-amber-400">{{ hasil.lewati }} dilewati</span>
                        </div>
                        <p v-if="!hasil.bisa" class="text-emerald-400 text-sm">✓ Tidak ada transaksi batal yang perlu dilengkapi.</p>

                        <div v-if="hasil.contoh.length" class="space-y-2">
                            <div v-for="c in hasil.contoh" :key="c.id" class="bg-slate-800/50 rounded-lg px-3 py-2 text-xs">
                                <div class="text-slate-200 font-medium">{{ c.konsumen }} · {{ c.unit }}</div>
                                <div class="text-slate-400 mt-0.5">
                                    Harga dasar {{ rp(c.harga_dasar) }} · Booking fee {{ rp(c.booking_fee) }} · DP {{ rp(c.dp) }}
                                    <span v-if="c.pelunasan"> · Pelunasan {{ rp(c.pelunasan) }}</span>
                                    · {{ c.jadwal }} baris jadwal
                                </div>
                            </div>
                            <p v-if="hasil.bisa > hasil.contoh.length" class="text-slate-500 text-xs">… dan {{ hasil.bisa - hasil.contoh.length }} lainnya.</p>
                        </div>

                        <div v-if="hasil.dilewati.length" class="space-y-1">
                            <div class="text-slate-300 text-xs font-semibold pt-1">Dilewati</div>
                            <div v-for="c in hasil.dilewati" :key="c.id" class="text-xs text-slate-400">
                                {{ c.konsumen }} · {{ c.unit }} <span class="text-amber-400">— {{ c.alasan }}</span>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="flex gap-3 p-5 border-t border-slate-800">
                    <button type="button" class="flex-1 py-2.5 text-slate-400 border border-slate-700 rounded-lg text-sm hover:text-slate-200" @click="emit('tutup')">Tutup</button>
                    <button v-if="hasil && hasil.bisa" type="button" :disabled="menerapkan" class="flex-1 py-2.5 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white rounded-lg text-sm font-medium" @click="terapkan">
                        {{ menerapkan ? 'Memproses…' : 'Terapkan' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

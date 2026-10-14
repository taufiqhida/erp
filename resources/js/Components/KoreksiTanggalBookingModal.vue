<script setup>
// Koreksi Tanggal Booking (salah input). Menampilkan dampak lebih dulu: UTJ bawaan booking ikut berubah dan jatuh tempo cicilan yang
// belum dibayar bergeser sebesar selisih hari; cicilan yang sudah dibayar tetap terkunci.
import { ref, watch, computed } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    kkId:             { type: Number, required: true },
    tanggalSekarang:  { type: String, required: true },     // Y-m-d
    unit:             { type: String, default: '' },
});
const emit = defineEmits(['tutup', 'selesai']);

const tanggal = ref(props.tanggalSekarang);
const alasan = ref('');
const dampak = ref(null);
const galat = ref(null);
const memuat = ref(false);
const menyimpan = ref(false);
const hariIni = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

const label = (d) => new Date(d + 'T00:00:00').toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });

let timer = null;
watch(tanggal, (v) => {
    clearTimeout(timer);
    dampak.value = null; galat.value = null;
    if (!v || v === props.tanggalSekarang || v > hariIni) return;
    timer = setTimeout(async () => {
        memuat.value = true;
        try {
            dampak.value = (await window.axios.patch(route('bookings.tanggal-booking', props.kkId), { tanggal_booking: v, simulasi: true })).data;
        } catch (e) {
            galat.value = e.response?.data?.message ?? 'Gagal menghitung dampak.';
        } finally {
            memuat.value = false;
        }
    }, 300);
});

const bisa = computed(() => !!dampak.value && alasan.value.trim().length >= 5 && !menyimpan.value);
const simpan = () => {
    menyimpan.value = true; galat.value = null;
    router.patch(route('bookings.tanggal-booking', props.kkId), { tanggal_booking: tanggal.value, alasan: alasan.value }, {
        preserveScroll: true,
        onSuccess: () => emit('selesai'),
        onError: (e) => { galat.value = Object.values(e)[0]; },
        onFinish: () => { menyimpan.value = false; },
    });
};
const input = 'w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500';
</script>

<template>
    <Teleport to="body">
        <div role="dialog" aria-modal="true" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="emit('tutup')" />
            <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md shadow-2xl max-h-[92vh] flex flex-col">
                <div class="flex items-start justify-between p-5 border-b border-slate-800">
                    <div>
                        <h3 class="text-white font-semibold">📅 Koreksi Tanggal Booking</h3>
                        <p class="text-slate-400 text-xs mt-0.5">Unit {{ unit }} · sekarang: <b class="text-slate-300">{{ label(tanggalSekarang) }}</b></p>
                    </div>
                    <button type="button" class="text-slate-500 hover:text-slate-300" aria-label="Tutup" @click="emit('tutup')">✕</button>
                </div>

                <div class="p-5 space-y-4 overflow-y-auto">
                    <div>
                        <label class="block text-slate-400 text-xs mb-1">Tanggal booking yang benar <span class="text-rose-400">*</span></label>
                        <input v-model="tanggal" type="date" :max="hariIni" :class="input" />
                    </div>

                    <p v-if="memuat" class="text-slate-500 text-xs">Menghitung dampak…</p>
                    <p v-else-if="galat" class="text-rose-400 text-xs">{{ galat }}</p>
                    <div v-else-if="dampak" class="text-xs bg-slate-800/60 rounded-lg px-3 py-2.5 space-y-1 text-slate-300">
                        <p>Digeser <b>{{ dampak.selisih_hari > 0 ? '+' : '' }}{{ dampak.selisih_hari }} hari</b> ({{ label(dampak.tanggal_lama) }} → {{ label(dampak.tanggal_baru) }}).</p>
                        <ul class="list-disc pl-4 space-y-0.5 text-slate-400">
                            <li v-if="dampak.utj_ikut">Tanggal bayar UTJ bawaan booking ikut diubah.</li>
                            <li>{{ dampak.cicilan_digeser }} jadwal cicilan (belum dibayar dan UTJ bawaan) jatuh temponya ikut bergeser.</li>
                            <li v-if="dampak.cicilan_terkunci">{{ dampak.cicilan_terkunci }} cicilan yang sudah dibayar tetap terkunci.</li>
                            <li>Jumlah uang tidak berubah. Perubahan tercatat di Audit Trail dan catatan transaksi.</li>
                        </ul>
                    </div>
                    <p v-else class="text-slate-500 text-xs">Pilih tanggal yang berbeda untuk melihat dampaknya.</p>

                    <div>
                        <label class="block text-slate-400 text-xs mb-1">Alasan koreksi <span class="text-rose-400">*</span> <span class="text-slate-600">(min. 5 karakter)</span></label>
                        <textarea v-model="alasan" rows="2" placeholder="Mis. salah ketik tanggal saat input booking" :class="input + ' resize-none'" />
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 p-4 border-t border-slate-800">
                    <button type="button" class="px-4 py-2 text-slate-300 border border-slate-700 hover:bg-slate-800 rounded-lg text-sm" @click="emit('tutup')">Batal</button>
                    <button type="button" :disabled="!bisa" @click="simpan" class="px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white rounded-lg text-sm font-medium">
                        {{ menyimpan ? 'Menyimpan…' : 'Simpan Koreksi' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

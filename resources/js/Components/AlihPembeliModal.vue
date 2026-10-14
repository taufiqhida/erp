<script setup>
// Ajukan Alih Pembeli: pembeli transaksi diganti (mis. A tidak lolos BI Checking, UTJ dialihkan ke saudaranya B) tanpa membatalkan.
// Pengajuan masuk ke menu Pengajuan dan diputuskan SPV/Leader; uang (UTJ, pembayaran) tetap pada transaksi yang sama.
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    kkId:         { type: Number, required: true },
    pembeliLama:  { type: String, default: '' },
    unit:         { type: String, default: '' },
});
const emit = defineEmits(['tutup', 'selesai']);

const HUBUNGAN = { pasangan: 'Pasangan (suami/istri)', orang_tua: 'Orang tua', anak: 'Anak', saudara: 'Saudara kandung', kerabat: 'Kerabat lain', lainnya: 'Lainnya' };
const PEKERJAAN = { karyawan_swasta: 'Karyawan Swasta', pns_asn_tni_polri_bumn: 'PNS / ASN / TNI / Polri / BUMN', wirausaha: 'Wirausaha', freelance: 'Freelance' };
const PERNIKAHAN = { belum_menikah: 'Belum Menikah', menikah: 'Menikah', cerai_hidup: 'Cerai Hidup', cerai_mati: 'Cerai Mati' };

const mode = ref('baru');                 // 'baru' | 'terdaftar' — hampir selalu orang baru, jadi itu yang terbuka
const form = useForm({
    konsumen_baru_id: null,
    baru: { nama: '', nik: '', no_hp: '', npwp: '', email: '', alamat: '', pekerjaan: '', status_pernikahan: '' },
    hubungan: '',
    alasan: '',
});

// ── Cari konsumen terdaftar ──
const q = ref('');
const hasil = ref([]);
const mencari = ref(false);
const dipilih = ref(null);
let timer = null;
watch(q, (v) => {
    clearTimeout(timer);
    hasil.value = [];
    if (v.trim().length < 2) return;
    timer = setTimeout(async () => {
        mencari.value = true;
        try { hasil.value = (await window.axios.get(route('alih-pembeli.cari', props.kkId), { params: { q: v.trim() } })).data; }
        catch { hasil.value = []; }
        finally { mencari.value = false; }
    }, 300);
});
const pilih = (k) => { dipilih.value = k; form.konsumen_baru_id = k.id; hasil.value = []; q.value = ''; };
const batalPilih = () => { dipilih.value = null; form.konsumen_baru_id = null; };

const kirim = () => {
    form.transform((d) => (mode.value === 'terdaftar'
        ? { konsumen_baru_id: d.konsumen_baru_id, hubungan: d.hubungan, alasan: d.alasan }
        : { baru: d.baru, hubungan: d.hubungan, alasan: d.alasan }))
        .post(route('alih-pembeli.store', props.kkId), { preserveScroll: true, onSuccess: () => emit('selesai') });
};
const baruLengkap = () => ['nama', 'nik', 'no_hp', 'pekerjaan', 'status_pernikahan'].every(k => String(form.baru[k] ?? '').trim() !== '');
const siap = () => form.hubungan && form.alasan.trim().length >= 10 && (mode.value === 'terdaftar' ? !!form.konsumen_baru_id : baruLengkap());
const input = 'w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-violet-500';
</script>

<template>
    <Teleport to="body">
        <div role="dialog" aria-modal="true" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="emit('tutup')" />
            <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-lg shadow-2xl max-h-[92vh] flex flex-col">
                <div class="flex items-start justify-between p-5 border-b border-slate-800">
                    <div>
                        <h3 class="text-white font-semibold">👥 Alihkan ke Pembeli Lain</h3>
                        <p class="text-slate-400 text-xs mt-0.5">Unit {{ unit }} · pembeli saat ini: <b class="text-slate-300">{{ pembeliLama }}</b></p>
                    </div>
                    <button type="button" class="text-slate-500 hover:text-slate-300" aria-label="Tutup" @click="emit('tutup')">✕</button>
                </div>

                <div class="p-5 space-y-4 overflow-y-auto">
                    <div class="text-xs text-slate-400 bg-slate-800/60 rounded-lg px-3 py-2.5 space-y-1">
                        <p>Pengajuan diputuskan <b class="text-slate-300">SPV/Leader</b> di menu Pengajuan. Bila disetujui:</p>
                        <ul class="list-disc pl-4 space-y-0.5">
                            <li>UTJ dan seluruh pembayaran <b>tetap</b> pada transaksi ini (tidak dihitung dua kali).</li>
                            <li>Berkas pembeli lama diarsipkan; pembeli baru mulai dari daftar berkas baru.</li>
                            <li>Hasil Proses Bank/SLIK dan SP3K pembeli lama dikosongkan; transaksi kembali ke <b>Pemberkasan</b>.</li>
                        </ul>
                    </div>

                    <div class="inline-flex rounded-lg overflow-hidden border border-slate-700 text-xs">
                        <button type="button" @click="mode = 'baru'" :class="mode === 'baru' ? 'bg-violet-600 text-white' : 'bg-slate-800 text-slate-300'" class="px-3 py-1.5">Konsumen baru</button>
                        <button type="button" @click="mode = 'terdaftar'" :class="mode === 'terdaftar' ? 'bg-violet-600 text-white' : 'bg-slate-800 text-slate-300'" class="px-3 py-1.5">Konsumen terdaftar</button>
                    </div>

                    <!-- Pilih konsumen yang sudah terdaftar -->
                    <div v-if="mode === 'terdaftar'" class="space-y-2">
                        <div v-if="dipilih" class="flex items-center justify-between bg-violet-500/10 border border-violet-500/30 rounded-lg px-3 py-2">
                            <div class="text-sm text-slate-200">{{ dipilih.nama }}<span class="text-slate-500 text-xs"> · {{ dipilih.no_hp ?? '-' }} · {{ dipilih.nik ?? 'tanpa NIK' }}</span></div>
                            <button type="button" class="text-xs text-slate-400 hover:text-slate-200" @click="batalPilih">Ganti</button>
                        </div>
                        <template v-else>
                            <input v-model="q" type="text" placeholder="Cari nama, NIK, atau No. HP (min. 2 huruf)…" :class="input" />
                            <p v-if="mencari" class="text-slate-500 text-xs">Mencari…</p>
                            <div v-if="hasil.length" class="border border-slate-700 rounded-lg divide-y divide-slate-800 max-h-44 overflow-y-auto">
                                <button v-for="k in hasil" :key="k.id" type="button" @click="pilih(k)" class="w-full text-left px-3 py-2 hover:bg-slate-800 text-sm text-slate-200">
                                    {{ k.nama }}<span class="text-slate-500 text-xs"> · {{ k.no_hp ?? '-' }} · {{ k.nik ?? 'tanpa NIK' }}</span>
                                </button>
                            </div>
                            <p v-else-if="q.trim().length >= 2 && !mencari" class="text-slate-500 text-xs">Tidak ada yang cocok. Pakai tab <b>Konsumen baru</b>.</p>
                        </template>
                        <p v-if="form.errors.konsumen_baru_id" class="text-rose-400 text-xs">{{ form.errors.konsumen_baru_id }}</p>
                    </div>

                    <!-- Konsumen baru -->
                    <div v-else class="grid grid-cols-2 gap-3">
                        <div class="col-span-2"><label class="block text-slate-400 text-xs mb-1">Nama lengkap <span class="text-rose-400">*</span></label><input v-model="form.baru.nama" type="text" :class="input" />
                            <p v-if="form.errors['baru.nama']" class="text-rose-400 text-xs mt-1">{{ form.errors['baru.nama'] }}</p></div>
                        <div><label class="block text-slate-400 text-xs mb-1">NIK <span class="text-rose-400">*</span></label><input v-model="form.baru.nik" type="text" :class="input" />
                            <p v-if="form.errors['baru.nik']" class="text-rose-400 text-xs mt-1">{{ form.errors['baru.nik'] }}</p></div>
                        <div><label class="block text-slate-400 text-xs mb-1">No. HP <span class="text-rose-400">*</span></label><input v-model="form.baru.no_hp" type="text" :class="input" />
                            <p v-if="form.errors['baru.no_hp']" class="text-rose-400 text-xs mt-1">{{ form.errors['baru.no_hp'] }}</p></div>
                        <div><label class="block text-slate-400 text-xs mb-1">Pekerjaan <span class="text-rose-400">*</span></label>
                            <select v-model="form.baru.pekerjaan" :class="input"><option value="">—</option><option v-for="(l, k) in PEKERJAAN" :key="k" :value="k">{{ l }}</option></select>
                            <p v-if="form.errors['baru.pekerjaan']" class="text-rose-400 text-xs mt-1">{{ form.errors['baru.pekerjaan'] }}</p></div>
                        <div><label class="block text-slate-400 text-xs mb-1">Status pernikahan <span class="text-rose-400">*</span></label>
                            <select v-model="form.baru.status_pernikahan" :class="input"><option value="">—</option><option v-for="(l, k) in PERNIKAHAN" :key="k" :value="k">{{ l }}</option></select>
                            <p v-if="form.errors['baru.status_pernikahan']" class="text-rose-400 text-xs mt-1">{{ form.errors['baru.status_pernikahan'] }}</p></div>
                        <div><label class="block text-slate-400 text-xs mb-1">NPWP <span class="text-slate-600">(opsional)</span></label><input v-model="form.baru.npwp" type="text" placeholder="15/16 digit" :class="input" />
                            <p v-if="form.errors['baru.npwp']" class="text-rose-400 text-xs mt-1">{{ form.errors['baru.npwp'] }}</p></div>
                        <div><label class="block text-slate-400 text-xs mb-1">Email <span class="text-slate-600">(opsional)</span></label><input v-model="form.baru.email" type="email" :class="input" />
                            <p v-if="form.errors['baru.email']" class="text-rose-400 text-xs mt-1">{{ form.errors['baru.email'] }}</p></div>
                        <div class="col-span-2"><label class="block text-slate-400 text-xs mb-1">Alamat <span class="text-slate-600">(opsional)</span></label><textarea v-model="form.baru.alamat" rows="2" :class="input + ' resize-none'" /></div>
                        <p class="col-span-2 text-slate-500 text-[0.6875rem]">Sumber lead dan sales mengikuti pembeli lama.</p>
                    </div>

                    <div>
                        <label class="block text-slate-400 text-xs mb-1">Hubungan dengan pembeli lama *</label>
                        <select v-model="form.hubungan" :class="input"><option value="">— pilih —</option><option v-for="(l, k) in HUBUNGAN" :key="k" :value="k">{{ l }}</option></select>
                        <p v-if="form.errors.hubungan" class="text-rose-400 text-xs mt-1">{{ form.errors.hubungan }}</p>
                    </div>
                    <div>
                        <label class="block text-slate-400 text-xs mb-1">Alasan peralihan * <span class="text-slate-600">(min. 10 karakter)</span></label>
                        <textarea v-model="form.alasan" rows="3" placeholder="Mis. BI Checking pembeli awal tidak lolos; UTJ dialihkan ke saudara kandung." :class="input + ' resize-none'" />
                        <p v-if="form.errors.alasan" class="text-rose-400 text-xs mt-1">{{ form.errors.alasan }}</p>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 p-4 border-t border-slate-800">
                    <button type="button" class="px-4 py-2 text-slate-300 border border-slate-700 hover:bg-slate-800 rounded-lg text-sm" @click="emit('tutup')">Batal</button>
                    <button type="button" :disabled="form.processing || !siap()" @click="kirim"
                        class="px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white rounded-lg text-sm font-medium">{{ form.processing ? 'Mengirim…' : 'Ajukan Peralihan' }}</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

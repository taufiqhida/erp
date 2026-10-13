<script setup>
// Catatan satu transaksi (dropdown): catatan booking & pemberkasan sebagai entri yang bisa ditambah/diedit/ditandai selesai
// (hapus khusus pemegang override), plus catatan revisi dokumen, proses bank, SP3K, dan BAST yang dibaca dari kartu masing-masing.
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { konfirmasi } from '@/Composables/useConfirm';

const props = defineProps({
    data:      { type: Object, required: true },   // hasil CatatanTransaksi::payload()
    kkId:      { type: Number, required: true },
    bukaAwal:  { type: Boolean, default: false },
    linkKartu: { type: String, default: null },    // tautan ke halaman Dokumen (tempat catatan bank/SP3K/BAST diedit)
});

const buka = ref(props.bukaAwal);
const izin = computed(() => props.data.izin ?? { tambah: false, hapus: false });
const entri = computed(() => props.data.entri ?? []);
const jenisLabel = { booking: 'Catatan booking (promo, gratis kanopi, biaya hook, dan sebagainya)', pemberkasan: 'Catatan pemberkasan (berkas menyusul, dan sebagainya)' };
const perJenis = (j) => entri.value.filter(e => e.jenis === j);

const bacaSaja = computed(() => [
    { judul: 'Catatan proses bank / SLIK', teks: props.data.proses_bank },
    { judul: 'Catatan SP3K', teks: props.data.sp3k },
    { judul: 'Catatan BAST', teks: props.data.bast },
].filter(x => x.teks));

// ── Tambah ──
const baru = ref({ jenis: 'pemberkasan', isi: '' });
const galat = ref(null);
const menambah = ref(false);
const tambah = () => {
    if (!baru.value.isi.trim()) return;
    menambah.value = true; galat.value = null;
    router.post(route('catatan.store', props.kkId), { ...baru.value }, {
        preserveScroll: true,
        onSuccess: () => { baru.value.isi = ''; },
        onError: (e) => { galat.value = Object.values(e)[0]; },
        onFinish: () => { menambah.value = false; },
    });
};

// ── Edit / selesai / hapus ──
const mengedit = ref(null);        // id entri yang sedang diedit
const teksEdit = ref('');
const mulaiEdit = (e) => { mengedit.value = e.id; teksEdit.value = e.isi; };
const simpanEdit = (e) => {
    if (!teksEdit.value.trim()) return;
    router.patch(route('catatan.update', e.id), { isi: teksEdit.value }, { preserveScroll: true, onSuccess: () => { mengedit.value = null; } });
};
const toggleSelesai = (e) => router.patch(route('catatan.selesai', e.id), { selesai: !e.selesai }, { preserveScroll: true });
const hapus = async (e) => {
    if (await konfirmasi('Hapus catatan ini? Tindakan ini tercatat di Audit Trail.')) router.delete(route('catatan.destroy', e.id), { preserveScroll: true });
};
</script>

<template>
    <div class="border border-slate-800 rounded-lg overflow-hidden">
        <button type="button" @click="buka = !buka" class="w-full flex items-center justify-between px-3 py-2.5 bg-slate-800/40 hover:bg-slate-800/70 transition-colors">
            <span class="text-slate-300 text-sm font-medium flex items-center gap-2">
                📝 Catatan
                <span v-if="data.terbuka" class="min-w-[1.25rem] h-5 px-1.5 inline-flex items-center justify-center rounded-full bg-amber-500 text-slate-900 text-xs font-bold"
                    :title="`${data.terbuka} catatan belum selesai`">{{ data.terbuka }}</span>
                <span v-else-if="data.total" class="px-1.5 py-0.5 rounded-full bg-slate-700 text-slate-400 text-xs" title="Semua catatan sudah selesai">{{ data.total }} ✓</span>
                <span v-else class="text-slate-500 text-xs font-normal">belum ada</span>
            </span>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                :class="['w-4 h-4 text-slate-500 transition-transform duration-200', buka ? 'rotate-180' : '']"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
        </button>

        <div v-show="buka" class="divide-y divide-slate-800">
            <!-- Entri: booking & pemberkasan -->
            <div v-for="j in ['booking', 'pemberkasan']" :key="j" class="px-3 py-2.5">
                <div class="text-slate-400 text-xs font-medium mb-1.5">{{ jenisLabel[j] }}</div>
                <p v-if="!perJenis(j).length" class="text-slate-600 text-xs">Belum ada</p>
                <div v-for="e in perJenis(j)" :key="e.id" class="mb-2 last:mb-0 rounded-lg bg-slate-800/40 px-3 py-2" :class="e.selesai ? 'opacity-60' : ''">
                    <template v-if="mengedit === e.id">
                        <textarea v-model="teksEdit" rows="3" class="w-full px-2.5 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm resize-none focus:outline-none focus:ring-1 focus:ring-violet-500" />
                        <div class="flex gap-2 mt-1.5">
                            <button type="button" @click="simpanEdit(e)" :disabled="!teksEdit.trim()" class="px-2.5 py-1 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-xs rounded-lg">Simpan</button>
                            <button type="button" @click="mengedit = null" class="px-2.5 py-1 text-slate-400 hover:text-slate-200 text-xs">Batal</button>
                        </div>
                    </template>
                    <template v-else>
                        <div class="text-sm text-slate-200 whitespace-pre-line" :class="e.selesai ? 'line-through decoration-slate-500' : ''">{{ e.isi }}</div>
                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[0.6875rem] text-slate-500">
                            <span>{{ e.penulis ?? '—' }} · {{ e.tanggal }}</span>
                            <span v-if="e.tahap">{{ e.tahap }}</span>
                            <span v-if="e.diedit" class="text-slate-600">(diedit)</span>
                            <span v-if="e.selesai" class="text-emerald-400">✓ Selesai{{ e.diselesaikan_oleh ? ` oleh ${e.diselesaikan_oleh}` : '' }}{{ e.diselesaikan_at ? `, ${e.diselesaikan_at}` : '' }}</span>
                            <span v-if="izin.tambah || izin.hapus" class="ml-auto flex items-center gap-2">
                                <button v-if="izin.tambah" type="button" @click="toggleSelesai(e)" class="text-emerald-400 hover:text-emerald-300">{{ e.selesai ? '↺ Buka lagi' : '✓ Tandai selesai' }}</button>
                                <button v-if="izin.tambah" type="button" @click="mulaiEdit(e)" class="text-violet-400 hover:text-violet-300">Edit</button>
                                <button v-if="izin.hapus" type="button" @click="hapus(e)" class="text-rose-400 hover:text-rose-300">Hapus</button>
                            </span>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Revisi dokumen (otomatis mengikuti status berkas) -->
            <div class="px-3 py-2.5">
                <div class="text-slate-400 text-xs font-medium mb-1.5">Catatan revisi dokumen pemberkasan</div>
                <p v-if="!data.revisi_dokumen?.length" class="text-slate-600 text-xs">Belum ada</p>
                <div v-for="(r, i) in data.revisi_dokumen" :key="i" class="text-sm mb-1" :class="r.selesai ? 'opacity-60' : ''">
                    <span class="text-amber-400 text-xs">{{ r.nama }} ({{ r.status_label }}):</span>
                    <span class="text-slate-300" :class="r.selesai ? 'line-through decoration-slate-500' : ''"> {{ r.catatan }}</span>
                    <span v-if="r.selesai" class="text-emerald-400 text-xs"> ✓ berkas sudah ada</span>
                </div>
            </div>

            <!-- Catatan dari kartu lain (baca-saja di sini) -->
            <div v-for="b in bacaSaja" :key="b.judul" class="px-3 py-2.5">
                <div class="text-slate-400 text-xs font-medium mb-1">{{ b.judul }}</div>
                <div class="text-slate-300 text-sm whitespace-pre-line">{{ b.teks }}</div>
            </div>
            <div v-if="linkKartu && bacaSaja.length" class="px-3 py-1.5 text-[0.6875rem] text-slate-500">Catatan bank, SP3K, dan BAST diedit di kartunya masing-masing di halaman Dokumen.</div>

            <!-- Tambah catatan -->
            <div v-if="izin.tambah" class="px-3 py-3 bg-slate-800/20 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-slate-400 text-xs">Tambah catatan:</span>
                    <select v-model="baru.jenis" class="px-2 py-1 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-violet-500">
                        <option value="pemberkasan">Pemberkasan</option>
                        <option value="booking">Booking</option>
                    </select>
                </div>
                <textarea v-model="baru.isi" rows="2" placeholder="Tulis catatan…" class="w-full px-2.5 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-200 text-sm resize-none focus:outline-none focus:ring-1 focus:ring-violet-500" />
                <div class="flex items-center gap-3">
                    <button type="button" @click="tambah" :disabled="menambah || !baru.isi.trim()" class="px-3 py-1.5 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-xs font-medium rounded-lg">+ Tambah</button>
                    <span v-if="galat" class="text-rose-400 text-xs">{{ galat }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

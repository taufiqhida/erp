<script setup>
import { konfirmasi } from '@/Composables/useConfirm';
import PengaturanLayout from '@/Layouts/PengaturanLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    daftar: Array, // [{ key, label, jumlah }]
    preview: Object, // hasil pemeriksaan impor (disimpan di sesi) atau null
});

// ── Ekspor ───────────────────────────────────────────────────────────────
const pilihEkspor = ref(props.daftar.map(d => d.key));
const urlEkspor = computed(() => route('pengaturan.pindah-master.ekspor', { master: pilihEkspor.value }));

// ── Impor ────────────────────────────────────────────────────────────────
const form = useForm({
    file: null,
    master: props.daftar.map(d => d.key),
    perbarui: false,
});
const periksa = () => form.post(route('pengaturan.pindah-master.periksa'), { preserveScroll: true, forceFormData: true });

const terapkan = async () => {
    const baru = props.preview.baris.reduce((a, b) => a + b.baru, 0);
    const ubah = props.preview.perbarui ? props.preview.baris.reduce((a, b) => a + b.beda, 0) : 0;
    const pesan = `Terapkan impor sekarang?\n${baru} data baru akan ditambahkan` + (ubah ? ` dan ${ubah} data diperbarui.` : '.');
    if (await konfirmasi(pesan, { confirmText: 'Terapkan impor' })) {
        router.post(route('pengaturan.pindah-master.terapkan'), {}, { preserveScroll: true });
    }
};
const batal = () => router.post(route('pengaturan.pindah-master.batal'), {}, { preserveScroll: true });

const semuaEkspor = computed({
    get: () => pilihEkspor.value.length === props.daftar.length,
    set: (v) => { pilihEkspor.value = v ? props.daftar.map(d => d.key) : []; },
});
const semuaImpor = computed({
    get: () => form.master.length === props.daftar.length,
    set: (v) => { form.master = v ? props.daftar.map(d => d.key) : []; },
});
const waktu = (iso) => iso ? new Date(iso).toLocaleString('id-ID') : '-';
</script>

<template>
    <PengaturanLayout title="Pindah Master Data">
        <div>
            <h1 class="text-white font-bold text-xl">Pindah Master Data</h1>
            <p class="text-slate-400 text-sm mt-0.5">
                Memindahkan pengaturan global (Bank Rekanan, Skema DP, Promo, dst.) dari satu server ke server lain, mis. dari
                staging ke production, tanpa mengetik ulang. Akun, proyek, kavling, konsumen, dan transaksi <b>tidak pernah ikut</b>.
            </p>
        </div>

        <!-- ── Ekspor ─────────────────────────────────────────────── -->
        <section class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
            <h2 class="text-white font-semibold">1. Ekspor (di server asal)</h2>
            <p class="text-slate-400 text-sm mt-0.5">Pilih master yang mau dipindah, lalu unduh filenya.</p>

            <label class="flex items-center gap-2 mt-4 text-sm text-slate-300">
                <input type="checkbox" v-model="semuaEkspor" class="rounded" /> Pilih semua
            </label>
            <div class="mt-2 grid sm:grid-cols-2 gap-x-6 gap-y-1.5">
                <label v-for="d in daftar" :key="d.key" class="flex items-center gap-2 text-sm text-slate-300">
                    <input type="checkbox" :value="d.key" v-model="pilihEkspor" class="rounded" />
                    <span>{{ d.label }}</span>
                    <span class="text-slate-500 text-xs">({{ d.jumlah }})</span>
                </label>
            </div>

            <p class="mt-4 text-xs text-amber-300/90 bg-amber-500/10 border border-amber-500/20 rounded-lg px-3 py-2">
                ⚠ Kalau <b>Profil Developer</b> ikut, file memuat <b>nomor rekening perusahaan</b>. Simpan file dengan hati-hati
                (jangan dikirim lewat chat/email) dan hapus setelah selesai dipakai. Logo, kop surat, dan template Word tidak ikut;
                unggah ulang lewat halamannya masing-masing.
            </p>

            <a :href="pilihEkspor.length ? urlEkspor : null" :aria-disabled="!pilihEkspor.length"
                :class="['mt-4 inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium text-white transition-colors',
                    pilihEkspor.length ? 'bg-violet-600 hover:bg-violet-500' : 'bg-slate-700 text-slate-400 pointer-events-none']">
                ⬇ Unduh file master
            </a>
        </section>

        <!-- ── Impor ─────────────────────────────────────────────── -->
        <section class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
            <h2 class="text-white font-semibold">2. Impor (di server tujuan)</h2>
            <p class="text-slate-400 text-sm mt-0.5">
                Pilih file, lalu <b>Periksa dulu</b>. Pemeriksaan hanya menampilkan ringkasan dan <b>belum mengubah apa pun</b>.
            </p>

            <div class="mt-4">
                <input type="file" accept=".json,application/json" @change="form.file = $event.target.files[0]"
                    class="block w-full text-sm text-slate-400 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:bg-slate-700 file:text-slate-200 hover:file:bg-slate-600 cursor-pointer" />
                <p v-if="form.errors.file" class="text-rose-400 text-xs mt-1">{{ form.errors.file }}</p>
            </div>

            <label class="flex items-center gap-2 mt-4 text-sm text-slate-300">
                <input type="checkbox" v-model="semuaImpor" class="rounded" /> Pilih semua master
            </label>
            <div class="mt-2 grid sm:grid-cols-2 gap-x-6 gap-y-1.5">
                <label v-for="d in daftar" :key="d.key" class="flex items-center gap-2 text-sm text-slate-300">
                    <input type="checkbox" :value="d.key" v-model="form.master" class="rounded" />
                    <span>{{ d.label }}</span>
                </label>
            </div>
            <p v-if="form.errors.master" class="text-rose-400 text-xs mt-1">{{ form.errors.master }}</p>

            <label class="flex items-start gap-2 mt-4 text-sm text-slate-300">
                <input type="checkbox" v-model="form.perbarui" class="rounded mt-0.5" />
                <span>
                    Perbarui yang sudah ada
                    <span class="block text-slate-500 text-xs">
                        Bawaan mati: data dengan nama yang sama dilewati. Kalau dinyalakan, nilai yang berbeda ditimpa dengan isi file.
                    </span>
                </span>
            </label>

            <button type="button" @click="periksa" :disabled="form.processing || !form.file || !form.master.length"
                class="mt-4 px-4 py-2.5 rounded-lg text-sm font-medium bg-slate-700 hover:bg-slate-600 text-white disabled:opacity-50 transition-colors">
                {{ form.processing ? 'Memeriksa…' : '🔍 Periksa dulu' }}
            </button>
        </section>

        <!-- ── Hasil pemeriksaan ──────────────────────────────────── -->
        <section v-if="preview" class="bg-slate-900 border border-violet-500/40 rounded-2xl p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-white font-semibold">Hasil pemeriksaan</h2>
                    <p class="text-slate-400 text-xs mt-0.5">
                        File: {{ preview.nama_file }} · dibuat {{ waktu(preview.dibuat) }}
                        <span v-if="preview.sumber"> · dari {{ preview.sumber }}</span>
                    </p>
                </div>
                <button type="button" @click="batal" class="text-slate-400 hover:text-white text-sm">Batalkan</button>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-400 text-xs border-b border-slate-800">
                            <th class="py-2 pr-4 font-medium">Master</th>
                            <th class="py-2 px-3 font-medium text-right">Di file</th>
                            <th class="py-2 px-3 font-medium text-right">Baru</th>
                            <th class="py-2 px-3 font-medium text-right">Sama</th>
                            <th class="py-2 px-3 font-medium text-right">Beda</th>
                            <th class="py-2 pl-3 font-medium">Hasil</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="b in preview.baris" :key="b.key" class="border-b border-slate-800/60">
                            <td class="py-2 pr-4 text-slate-200">{{ b.label }}</td>
                            <td class="py-2 px-3 text-right text-slate-300">{{ b.total }}</td>
                            <td class="py-2 px-3 text-right" :class="b.baru ? 'text-emerald-400 font-semibold' : 'text-slate-500'">{{ b.baru }}</td>
                            <td class="py-2 px-3 text-right text-slate-300">{{ b.sama }}</td>
                            <td class="py-2 px-3 text-right" :class="b.beda ? 'text-amber-400 font-semibold' : 'text-slate-500'">{{ b.beda }}</td>
                            <td class="py-2 pl-3 text-slate-400 text-xs">
                                <template v-if="b.beda && preview.perbarui">{{ b.diperbarui }} akan diperbarui</template>
                                <template v-else-if="b.beda">{{ b.dilewati }} dilewati (nilai beda)</template>
                                <template v-else>-</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-slate-500 text-xs">
                <b>Baru</b> = belum ada, akan ditambahkan · <b>Sama</b> = sudah ada dan identik · <b>Beda</b> = ada dengan nama yang sama tetapi nilainya berbeda.
            </p>

            <p v-if="preview.tidak_ada?.length" class="mt-3 text-slate-400 text-xs">
                Dicentang tetapi tidak ada di file (dilewati, tidak jadi masalah): {{ preview.tidak_ada.join(', ') }}.
            </p>

            <div v-if="preview.masalah.length" class="mt-4 bg-rose-500/10 border border-rose-500/30 rounded-lg px-4 py-3">
                <div class="text-rose-300 text-sm font-medium">Ada masalah — impor tidak bisa diterapkan</div>
                <ul class="mt-1.5 list-disc pl-5 text-rose-300/90 text-xs space-y-0.5">
                    <li v-for="(m, i) in preview.masalah.slice(0, 30)" :key="i">{{ m }}</li>
                </ul>
                <p v-if="preview.masalah.length > 30" class="text-rose-300/80 text-xs mt-1">…dan {{ preview.masalah.length - 30 }} masalah lain.</p>
            </div>

            <button v-if="preview.bisa_diterapkan" type="button" @click="terapkan"
                class="mt-4 px-4 py-2.5 rounded-lg text-sm font-medium bg-emerald-600 hover:bg-emerald-500 text-white transition-colors">
                ✔ Terapkan impor
            </button>
        </section>
    </PengaturanLayout>
</template>

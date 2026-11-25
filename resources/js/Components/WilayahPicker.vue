<script setup>
// Pemilih wilayah bertingkat: Provinsi > Kabupaten/Kota > Kecamatan > Kelurahan/Desa. v-model = SATU kode tingkat terdalam yang dipilih
// (mis. "32.73.01.1001"); server menurunkan rantai dan nama lengkapnya dari tabel wilayah. Kosong = belum dipilih.
import { computed, onMounted, reactive, ref, watch } from 'vue';
import SearchSelect from '@/Components/SearchSelect.vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    error: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const TINGKAT = [
    { kunci: 'prov', label: 'Provinsi', kosong: 'Pilih provinsi' },
    { kunci: 'kab', label: 'Kabupaten/Kota', kosong: 'Pilih kabupaten/kota' },
    { kunci: 'kec', label: 'Kecamatan', kosong: 'Pilih kecamatan' },
    { kunci: 'kel', label: 'Kelurahan/Desa', kosong: 'Pilih kelurahan/desa' },
];

const pilihan = reactive(['', '', '', '']);       // kode terpilih per tingkat
const daftar = reactive([[], [], [], []]);        // opsi per tingkat
// Item untuk kotak cari: ketik bagian mana pun dari nama (mis. "semarang" menemukan "Kota Semarang" dan "Kabupaten Semarang").
const opsi = computed(() => daftar.map((l) => l.map((o) => ({ id: o.kode, nama: o.nama }))));
const pilih = (i, v) => { pilihan[i] = v || ''; ubah(i); };
const memuat = ref(false);
const gagal = ref(false);
const cache = new Map();

const ambil = async (induk) => {
    const kunci = induk ?? '';
    if (cache.has(kunci)) return cache.get(kunci);
    const url = route('wilayah.anak', induk ? { induk } : {});
    const r = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
    if (!r.ok) throw new Error('gagal');
    const data = await r.json();
    cache.set(kunci, data);
    return data;
};

const rantai = (kode) => {
    const s = (kode || '').split('.').filter(Boolean);
    return s.map((_, i) => s.slice(0, i + 1).join('.'));
};

const muatDari = async (kode) => {
    memuat.value = true;
    gagal.value = false;
    try {
        const r = rantai(kode);
        daftar[0] = await ambil(null);
        for (let i = 0; i < 4; i++) {
            pilihan[i] = r[i] ?? '';
            if (i + 1 < 4) daftar[i + 1] = r[i] ? await ambil(r[i]) : [];
        }
    } catch {
        gagal.value = true;
    } finally {
        memuat.value = false;
    }
};

const terdalam = () => [...pilihan].reverse().find(Boolean) ?? '';

const ubah = async (i) => {
    for (let j = i + 1; j < 4; j++) { pilihan[j] = ''; daftar[j] = []; }
    if (pilihan[i] && i + 1 < 4) {
        memuat.value = true;
        try { daftar[i + 1] = await ambil(pilihan[i]); gagal.value = false; } catch { gagal.value = true; } finally { memuat.value = false; }
    }
    emit('update:modelValue', terdalam());
};

// Nilai dari luar (mis. form di-reset atau data konsumen dimuat) menyegarkan pilihan, kecuali bila sama dengan yang sedang dipilih.
watch(() => props.modelValue, (v) => { if ((v || '') !== terdalam()) muatDari(v || ''); });
onMounted(() => muatDari(props.modelValue || ''));
</script>

<template>
    <div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div v-for="(t, i) in TINGKAT" :key="t.kunci">
                <label class="block text-slate-400 text-xs font-medium mb-1.5">{{ t.label }}</label>
                <SearchSelect :model-value="pilihan[i] || null" @update:model-value="(v) => pilih(i, v)" :items="opsi[i]" :search-keys="['nama']"
                    :placeholder="t.kosong" :limit="500" :disabled="(i > 0 && !pilihan[i - 1]) || (memuat && !daftar[i].length)" />
            </div>
        </div>
        <p v-if="gagal" class="text-amber-400 text-xs mt-1.5">Daftar wilayah gagal dimuat. Muat ulang halaman, atau lewati dan isi alamat tertulis saja.</p>
        <p v-if="error" class="text-rose-400 text-xs mt-1.5">{{ error }}</p>
    </div>
</template>

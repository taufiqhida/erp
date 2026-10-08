<script setup>
import { konfirmasi } from '@/Composables/useConfirm';
import { router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

// Modal "Update Proses": set tahap + persen yang SAMA untuk banyak unit sekaligus. Pilih unit per SPK, per blok/kluster,
// atau satu per satu (filter bisa digabung). Unit yang progresnya tidak serentak cukup tidak dicentang, lalu diubah
// sendiri lewat tabel. Daftar unit dimuat saat modal dibuka (prop opsional `unitMassal`).
const props = defineProps({
    show: Boolean,
    project: Object,
    spkRiwayat: Array,
    stages: Array, // [{ id, nama, warna, bobot, urutan, is_default }]
    klusterOptions: Array,
    blokOptions: Array,
    unit: Array, // semua unit proyek (undefined/null selama belum dimuat)
});
const emit = defineEmits(['close']);

const BATAS = 200;

const filter = reactive({ spk: '', kluster: '', blok: '', stage: '' });
const terpilih = ref(new Set());
const form = useForm({ kavling_ids: [], status_bangun_stage_id: '', persen: '', lewati_selesai: true });

watch(() => props.show, (buka) => {
    if (!buka) return;
    terpilih.value = new Set();
    Object.assign(filter, { spk: '', kluster: '', blok: '', stage: '' });
    form.reset();
    form.clearErrors();
    router.reload({ only: ['unitMassal'] });
});

const stageMap = computed(() => Object.fromEntries(props.stages.map(s => [s.id, s])));
const stageDipilih = computed(() => stageMap.value[Number(form.status_bangun_stage_id)] ?? null);
const stageAwal = computed(() => !!stageDipilih.value?.is_default);

// Rumus sama dengan server: bobot tahap sebelumnya + bobot tahap ini × persen / 100.
const progressUntuk = (stageId, persen) => {
    const st = stageMap.value[stageId];
    if (!st) return 0;
    const sebelum = props.stages.filter(s => s.urutan < st.urutan).reduce((a, s) => a + Number(s.bobot), 0);
    return Math.round((sebelum + Number(st.bobot) * Math.max(0, Math.min(100, Number(persen) || 0)) / 100) * 100) / 100;
};
const persenBaru = computed(() => stageAwal.value ? 0 : Math.max(0, Math.min(100, Number(form.persen) || 0)));

const memuat = computed(() => !props.unit);
const daftar = computed(() => props.unit ?? []);
const tampil = computed(() => daftar.value.filter(u =>
    (!filter.spk || u.spk_ids.includes(Number(filter.spk)))
    && (!filter.kluster || u.kluster === filter.kluster)
    && (!filter.blok || u.blok === filter.blok)
    && (!filter.stage || u.stage_id === Number(filter.stage))
));

const aktif = (u) => terpilih.value.has(u.id) && !(form.lewati_selesai && u.selesai);
const jumlahTerpilih = computed(() => terpilih.value.size);
const jumlahAkanDiubah = computed(() => daftar.value.filter(aktif).length);
const jumlahSelesaiTerpilih = computed(() => daftar.value.filter(u => terpilih.value.has(u.id) && u.selesai).length);

const toggle = (id) => {
    const s = new Set(terpilih.value);
    s.has(id) ? s.delete(id) : s.add(id);
    terpilih.value = s;
};
const semuaTampilTerpilih = computed(() => tampil.value.length > 0 && tampil.value.every(u => terpilih.value.has(u.id)));
const pilihSemuaTampil = () => {
    const s = new Set(terpilih.value);
    semuaTampilTerpilih.value ? tampil.value.forEach(u => s.delete(u.id)) : tampil.value.forEach(u => s.add(u.id));
    terpilih.value = s;
};
const kosongkan = () => { terpilih.value = new Set(); };

const bisaSimpan = computed(() => jumlahAkanDiubah.value > 0 && jumlahAkanDiubah.value <= BATAS && form.status_bangun_stage_id !== ''
    && (stageAwal.value || form.persen !== ''));

const simpan = async () => {
    const ids = daftar.value.filter(aktif).map(u => u.id);
    const pesan = `Ubah ${ids.length} unit ke ${stageDipilih.value.nama} ${persenBaru.value}%?\nProgress masing-masing unit akan diganti.`;
    if (!await konfirmasi(pesan, { confirmText: 'Update proses' })) return;

    form.kavling_ids = ids;
    form.persen = persenBaru.value;
    form.post(route('proses-bangun.massal', props.project.id), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <Teleport to="body">
        <div v-if="show" role="dialog" aria-modal="true" aria-label="Update Proses" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="emit('close')" />
            <div class="relative bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-3xl max-h-[92vh] flex flex-col shadow-2xl">
                <div class="flex items-start justify-between gap-3 px-6 py-4 border-b border-slate-800">
                    <div>
                        <h3 class="text-white font-semibold">Update Proses</h3>
                        <p class="text-slate-400 text-xs mt-0.5">
                            Set tahap dan persen yang sama untuk banyak unit sekaligus. Unit yang progresnya tidak serentak: jangan dicentang,
                            ubah sendiri lewat tabel.
                        </p>
                    </div>
                    <button type="button" @click="emit('close')" aria-label="Tutup" class="text-slate-500 hover:text-slate-300">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                    </button>
                </div>

                <div class="px-6 py-4 overflow-y-auto space-y-5">
                    <!-- 1. Pilih unit -->
                    <section>
                        <h4 class="text-slate-200 text-sm font-medium">1. Pilih unit</h4>
                        <div class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <select v-model="filter.spk" aria-label="Filter SPK" class="bg-slate-800 border border-slate-700 rounded-lg px-2.5 py-2 text-sm text-slate-200">
                                <option value="">Semua SPK</option>
                                <option v-for="s in spkRiwayat" :key="s.id" :value="s.id">{{ s.nomor_spk }} ({{ s.jumlah_unit }} unit)</option>
                            </select>
                            <select v-model="filter.kluster" aria-label="Filter kluster" class="bg-slate-800 border border-slate-700 rounded-lg px-2.5 py-2 text-sm text-slate-200">
                                <option value="">Semua kluster</option>
                                <option v-for="k in klusterOptions" :key="k" :value="k">{{ k }}</option>
                            </select>
                            <select v-model="filter.blok" aria-label="Filter blok" class="bg-slate-800 border border-slate-700 rounded-lg px-2.5 py-2 text-sm text-slate-200">
                                <option value="">Semua blok</option>
                                <option v-for="b in blokOptions" :key="b" :value="b">{{ b }}</option>
                            </select>
                            <select v-model="filter.stage" aria-label="Filter tahap sekarang" class="bg-slate-800 border border-slate-700 rounded-lg px-2.5 py-2 text-sm text-slate-200">
                                <option value="">Semua tahap</option>
                                <option v-for="s in stages" :key="s.id" :value="s.id">{{ s.nama }}</option>
                            </select>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                            <label class="flex items-center gap-2 text-slate-300">
                                <input type="checkbox" :checked="semuaTampilTerpilih" @change="pilihSemuaTampil" class="rounded" :disabled="memuat" />
                                Pilih semua yang tampil ({{ tampil.length }})
                            </label>
                            <div class="text-slate-400 text-xs">
                                <b class="text-slate-200">{{ jumlahTerpilih }}</b> terpilih
                                <button v-if="jumlahTerpilih" type="button" @click="kosongkan" class="ml-2 underline hover:text-white">Kosongkan</button>
                            </div>
                        </div>

                        <div class="mt-2 border border-slate-800 rounded-lg max-h-64 overflow-y-auto divide-y divide-slate-800">
                            <div v-if="memuat" class="p-4 text-center text-slate-500 text-sm">Memuat daftar unit…</div>
                            <div v-else-if="!tampil.length" class="p-4 text-center text-slate-500 text-sm">Tidak ada unit yang cocok dengan filter.</div>
                            <label v-for="u in tampil" :key="u.id"
                                :class="['flex items-center gap-3 px-3 py-2 text-sm cursor-pointer hover:bg-slate-800/60', terpilih.has(u.id) ? 'bg-violet-600/10' : '']">
                                <input type="checkbox" :checked="terpilih.has(u.id)" @change="toggle(u.id)" class="rounded" />
                                <span class="text-slate-200 font-medium w-28 truncate">{{ u.label }}</span>
                                <span class="flex items-center gap-1.5 text-xs flex-1 min-w-0">
                                    <span class="w-2 h-2 rounded-full flex-shrink-0" :style="`background:${stageMap[u.stage_id]?.warna ?? '#94a3b8'}`"></span>
                                    <span class="text-slate-300 truncate">{{ stageMap[u.stage_id]?.nama }} {{ u.persen }}%</span>
                                    <span class="text-slate-500">· {{ u.progress }}%</span>
                                </span>
                                <span v-if="u.selesai" class="text-[0.6875rem] px-1.5 py-0.5 rounded-full bg-emerald-500/15 text-emerald-400">Selesai</span>
                                <span v-if="terpilih.has(u.id) && stageDipilih && !(form.lewati_selesai && u.selesai)" class="text-xs text-violet-300 whitespace-nowrap">
                                    → {{ progressUntuk(stageDipilih.id, persenBaru) }}%
                                </span>
                            </label>
                        </div>
                    </section>

                    <!-- 2. Atur tahap -->
                    <section>
                        <h4 class="text-slate-200 text-sm font-medium">2. Set tahap dan persen</h4>
                        <div class="mt-2 grid sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-slate-400 text-xs mb-1" for="massal-tahap">Tahap</label>
                                <select id="massal-tahap" v-model="form.status_bangun_stage_id" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-2.5 py-2 text-sm text-slate-200">
                                    <option value="" disabled>Pilih tahap…</option>
                                    <option v-for="s in stages" :key="s.id" :value="s.id">{{ s.nama }}</option>
                                </select>
                                <p v-if="form.errors.status_bangun_stage_id" class="text-rose-400 text-xs mt-1">{{ form.errors.status_bangun_stage_id }}</p>
                            </div>
                            <div>
                                <label class="block text-slate-400 text-xs mb-1" for="massal-persen">Persen penyelesaian tahap ini (0–100)</label>
                                <input id="massal-persen" type="number" min="0" max="100" step="any" v-model="form.persen" :disabled="stageAwal"
                                    :placeholder="stageAwal ? 'Tahap awal selalu 0%' : 'mis. 40'"
                                    class="w-full bg-slate-800 border border-slate-700 rounded-lg px-2.5 py-2 text-sm text-slate-200 disabled:opacity-50" />
                                <p v-if="form.errors.persen" class="text-rose-400 text-xs mt-1">{{ form.errors.persen }}</p>
                            </div>
                        </div>

                        <label class="flex items-start gap-2 mt-3 text-sm text-slate-300">
                            <input type="checkbox" v-model="form.lewati_selesai" class="rounded mt-0.5" />
                            <span>
                                Lewati unit yang sudah selesai (100%)
                                <span v-if="jumlahSelesaiTerpilih" class="block text-slate-500 text-xs">
                                    {{ jumlahSelesaiTerpilih }} unit terpilih sudah selesai{{ form.lewati_selesai ? ' dan tidak akan diubah.' : ' dan AKAN diubah.' }}
                                </span>
                            </span>
                        </label>
                    </section>

                    <p v-if="form.errors.kavling_ids" class="text-rose-400 text-sm">{{ form.errors.kavling_ids }}</p>
                    <p v-if="jumlahAkanDiubah > BATAS" class="text-amber-400 text-sm">
                        Maksimal {{ BATAS }} unit sekali update. Kurangi pilihan, lalu ulangi untuk sisanya.
                    </p>
                </div>

                <div class="px-6 py-4 border-t border-slate-800 flex flex-wrap items-center justify-between gap-3">
                    <div class="text-slate-400 text-sm">
                        <template v-if="stageDipilih && jumlahAkanDiubah">
                            <b class="text-white">{{ jumlahAkanDiubah }}</b> unit akan diubah ke
                            <b class="text-white">{{ stageDipilih.nama }} {{ persenBaru }}%</b>
                        </template>
                        <template v-else>Pilih unit dan tahap untuk melanjutkan.</template>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" @click="emit('close')" class="px-4 py-2 text-slate-300 border border-slate-700 rounded-lg text-sm hover:text-white">Batal</button>
                        <button type="button" @click="simpan" :disabled="!bisaSimpan || form.processing"
                            class="px-4 py-2 bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors">
                            {{ form.processing ? 'Menyimpan…' : 'Update Proses' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

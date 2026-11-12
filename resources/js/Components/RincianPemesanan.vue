<script setup>
// Rincian Pemesanan (bacaan saja) dengan struktur yang sama di form booking, tab Konsumen, dan Detail Transaksi Keuangan:
// Harga Dasar → diskon harga jual → biaya tambahan per item (masing-masing dengan baris promonya) → Harga Jual Netto →
// Booking Fee → DP → Titipan Biaya Akad (dengan baris promonya). Semua nominal di satu kolom kanan; keterangan hanya di sisi kiri.
//
// Bentuk data (props.d):
//   hargaDasar, netto
//   diskon: { nominal, mode, nilai, promoNama }
//   tanah: { bruto, potongan, promoNama } | null
//   biaya: [{ nama, nominal, potongan, promoNama }]
//   skema: preset skema (booking_fee_*, dp_*) | null; skemaBelumDipilih: true di form booking sebelum skema dipilih
//   bookingFee, dp: nominal
//   akad: { nominal, potongan, promoNama, termasuk } | null    (termasuk: 'DP' | 'Booking Fee' | 'Booking Fee & DP' | null)
//   header: { caraBayar, skemaNama, allInNama } | null
//   bonus: ['Tandon', 'Kipas'] (label saja, tanpa nominal)
import { computed } from 'vue';

const props = defineProps({ d: { type: Object, required: true } });

const rp = (v) => 'Rp ' + Math.round(Number(v) || 0).toLocaleString('id-ID');
const ada = (v) => Number(v) > 0;

const ketSkema = (tipe, nilai, tenor, masuk) =>
    `${tipe === 'persen' ? `${nilai}%` : 'nominal tetap'}, ${tenor}x, ${masuk ? 'masuk harga jual' : 'di luar harga jual'}`;
const ketBf = computed(() => props.d.skema?.booking_fee_aktif
    ? ketSkema(props.d.skema.booking_fee_tipe, props.d.skema.booking_fee_nilai, props.d.skema.booking_fee_tenor, props.d.skema.booking_fee_masuk_harga_jual) : null);
const ketDp = computed(() => props.d.skema?.dp_aktif
    ? ketSkema(props.d.skema.dp_tipe, props.d.skema.dp_nilai, props.d.skema.dp_tenor, props.d.skema.dp_masuk_harga_jual) : null);

const adaAkad = computed(() => !!props.d.akad && (ada(props.d.akad.nominal)));
const kosong = computed(() => !props.d.skemaBelumDipilih && !ada(props.d.bookingFee) && !ada(props.d.dp) && !adaAkad.value);
</script>

<template>
    <div class="space-y-1.5 text-sm">
        <div class="flex justify-between text-slate-400">
            <span>Harga Dasar</span>
            <span class="text-slate-300 tabular-nums">{{ rp(d.hargaDasar) }}</span>
        </div>
        <div v-if="ada(d.diskon?.nominal)" class="flex justify-between text-slate-400 pl-3">
            <span>− Diskon harga jual{{ d.diskon.promoNama ? ` (${d.diskon.promoNama})` : '' }}{{ d.diskon.mode === 'persen' ? ` ${Number(d.diskon.nilai)}%` : '' }}</span>
            <span class="text-emerald-300 tabular-nums">{{ rp(d.diskon.nominal) }}</span>
        </div>

        <template v-if="d.tanah">
            <div class="flex justify-between text-slate-400">
                <span>+ Biaya Kelebihan Tanah</span>
                <span class="text-slate-300 tabular-nums">{{ rp(d.tanah.bruto) }}</span>
            </div>
            <div v-if="ada(d.tanah.potongan)" class="flex justify-between text-slate-400 pl-3">
                <span>− Potongan promo{{ d.tanah.promoNama ? ` (${d.tanah.promoNama})` : '' }}</span>
                <span class="text-emerald-300 tabular-nums">{{ rp(d.tanah.potongan) }}</span>
            </div>
        </template>
        <template v-for="(b, i) in d.biaya" :key="`b-${i}`">
            <div class="flex justify-between text-slate-400">
                <span>+ {{ b.nama }}</span>
                <span class="text-slate-300 tabular-nums">{{ rp(b.nominal) }}</span>
            </div>
            <div v-if="ada(b.potongan)" class="flex justify-between text-slate-400 pl-3">
                <span>− Potongan promo{{ b.promoNama ? ` (${b.promoNama})` : '' }}</span>
                <span class="text-emerald-300 tabular-nums">{{ rp(b.potongan) }}</span>
            </div>
        </template>

        <div class="flex justify-between pt-1.5 border-t border-slate-700 font-semibold">
            <span class="text-violet-300">Harga Jual Netto</span>
            <span class="text-violet-300 tabular-nums">{{ rp(d.netto) }}</span>
        </div>

        <div class="pt-2 mt-1 border-t border-slate-700/60 space-y-1.5">
            <div v-if="d.header" class="text-slate-500 text-[0.6875rem] uppercase tracking-wide">
                Cara Pembayaran: {{ d.header.caraBayar }}
                <span v-if="d.header.skemaNama" class="normal-case">· Skema: {{ d.header.skemaNama }}</span>
                <span v-if="d.header.allInNama" class="normal-case">· Program All In: {{ d.header.allInNama }}</span>
            </div>

            <template v-if="d.skemaBelumDipilih">
                <div class="flex justify-between text-slate-500"><span>Booking Fee</span><span class="text-xs">pilih skema pembayaran</span></div>
                <div class="flex justify-between text-slate-500"><span>DP</span><span class="text-xs">pilih skema pembayaran</span></div>
            </template>
            <template v-else>
                <div v-if="ada(d.bookingFee)" class="flex justify-between text-slate-400 gap-3">
                    <span>Booking Fee<span v-if="ketBf" class="text-slate-500 text-xs ml-1">({{ ketBf }})</span></span>
                    <span class="text-slate-300 tabular-nums">{{ rp(d.bookingFee) }}</span>
                </div>
                <div v-if="ada(d.dp)" class="flex justify-between text-slate-400 gap-3">
                    <span>DP<span v-if="ketDp" class="text-slate-500 text-xs ml-1">({{ ketDp }})</span></span>
                    <span class="text-slate-300 tabular-nums">{{ rp(d.dp) }}</span>
                </div>
            </template>

            <template v-if="adaAkad">
                <div class="flex justify-between text-slate-400 gap-3">
                    <span>Titipan Biaya Akad<span class="text-slate-500 text-xs ml-1">(Program All In{{ d.akad.termasuk ? `, sudah termasuk ${d.akad.termasuk}` : '' }})</span></span>
                    <span class="text-slate-300 tabular-nums">{{ rp(d.akad.nominal) }}</span>
                </div>
                <template v-if="ada(d.akad.potongan)">
                    <div class="flex justify-between text-slate-400 pl-3">
                        <span>− Potongan promo{{ d.akad.promoNama ? ` (${d.akad.promoNama})` : '' }}</span>
                        <span class="text-emerald-300 tabular-nums">{{ rp(d.akad.potongan) }}</span>
                    </div>
                    <div class="flex justify-between text-slate-300 font-medium">
                        <span>Titipan Biaya Akad ditagih</span>
                        <span class="tabular-nums">{{ rp(Math.max(0, Number(d.akad.nominal) - Number(d.akad.potongan))) }}</span>
                    </div>
                </template>
            </template>

            <div v-if="kosong" class="text-slate-500 text-xs">Tidak ada booking fee maupun DP.</div>
        </div>

        <div v-if="d.bonus?.length" class="pt-2 mt-1 border-t border-slate-700/60 flex justify-between gap-3 text-slate-400">
            <span>Bonus</span>
            <span class="text-slate-300 text-right">{{ d.bonus.join(', ') }}</span>
        </div>
    </div>
</template>

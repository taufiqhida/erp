// Menyusun data untuk komponen RincianPemesanan dari payload transaksi (dipakai tab Konsumen dan Detail Transaksi Keuangan,
// yang memakai nama kolom yang sama).
export function rincianDariTransaksi(t) {
    return {
        header: { caraBayar: t.cara_bayar_label, skemaNama: t.skema_dp_preset?.nama ?? null, allInNama: t.program_all_in_nama ?? null },
        hargaDasar: t.harga_dasar,
        diskon: { nominal: t.diskon_nominal, mode: t.diskon_mode, nilai: t.diskon_nilai, promoNama: t.diskon_promo_nama },
        tanah: t.biaya_kelebihan_tanah_aktif
            ? { bruto: t.biaya_kelebihan_tanah_nominal, potongan: t.biaya_kelebihan_tanah_potongan, promoNama: t.tanah_promo_nama }
            : null,
        biaya: (t.biaya_tambahan || []).map(b => ({ nama: b.nama, nominal: b.nominal, potongan: b.potongan, promoNama: b.promo_nama })),
        netto: t.harga_deal,
        bonus: t.bonus_nama || [],
        skema: t.skema_dp_preset ?? null,
        bookingFee: t.booking_fee,
        dp: t.dp_nominal,
        akad: Number(t.titipan_biaya_akad_nominal) > 0
            ? { nominal: t.titipan_biaya_akad_nominal, potongan: t.titipan_biaya_akad_potongan, promoNama: t.titipan_promo_nama, termasuk: t.titipan_termasuk }
            : null,
    };
}

import { reactive } from 'vue';

// Dialog konfirmasi seragam (pengganti window.confirm). Dipakai dengan:
//   if (!(await konfirmasi('Hapus bank "BTN"?'))) return;
// Teks tombol & warna menyesuaikan isi pesan: "hapus"/"batalkan" → merah dan "Ya, hapus"/"Ya, batalkan".
// Bisa diatur eksplisit: konfirmasi(pesan, { title, confirmText, danger }).
export const confirmState = reactive({
    open: false,
    title: 'Konfirmasi',
    message: '',
    confirmText: 'Ya, lanjutkan',
    cancelText: 'Batal',
    danger: false,
    resolve: null,
});

export function konfirmasi(message, options = {}) {
    const hapus = /hapus/i.test(message);
    const batal = /batalkan/i.test(message);
    const danger = options.danger ?? (hapus || batal);

    return new Promise((resolve) => {
        // Kalau ada dialog lain yang masih terbuka, anggap dibatalkan.
        confirmState.resolve?.(false);
        Object.assign(confirmState, {
            open: true,
            title: options.title ?? (hapus ? 'Konfirmasi hapus' : batal ? 'Konfirmasi pembatalan' : 'Konfirmasi'),
            message,
            confirmText: options.confirmText ?? (hapus ? 'Ya, hapus' : batal ? 'Ya, batalkan' : 'Ya, lanjutkan'),
            cancelText: options.cancelText ?? 'Batal',
            danger,
            resolve,
        });
    });
}

export function tutupKonfirmasi(hasil) {
    const resolve = confirmState.resolve;
    confirmState.open = false;
    confirmState.resolve = null;
    resolve?.(hasil);
}

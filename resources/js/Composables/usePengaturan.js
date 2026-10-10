// Halaman Pengaturan yang bisa dibuka pemegang izin master data tertentu (lihat RolesAndPermissionsSeeder).
// Urutan = halaman tujuan tombol/menu "Pengaturan" bagi pengguna yang punya lebih dari satu izin.
export const PENGATURAN_ROUTES = [
    ['manage system settings', 'pengaturan.profil-developer'],
    ['manage bank rekanan', 'pengaturan.bank-rekanan'],
    ['manage notaris', 'pengaturan.notaris'],
    ['manage dajam sbum preset', 'pengaturan.dajam-sbum'],
    ['manage status bangun master', 'pengaturan.status-bangun'],
    ['manage kontraktor', 'pengaturan.kontraktor'],
    ['manage sales agent', 'pengaturan.sales-agents'],
    ['manage program all in', 'pengaturan.program-all-in'],
];

export const IZIN_PENGATURAN = PENGATURAN_ROUTES.map(([izin]) => izin);

/** Nama rute halaman Pengaturan pertama yang boleh dibuka dengan daftar izin ini; null kalau tidak ada. */
export const rutePengaturanPertama = (izinPengguna = []) =>
    PENGATURAN_ROUTES.find(([izin]) => izinPengguna.includes(izin))?.[1] ?? null;

<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Struktur RBAC hasil evaluasi tim (2026-09-29) — 9 role menggantikan 6 role
 * lama (manajer/sales/staff_lapangan/finance/staff_kpr dibubarkan & dipecah).
 * Lihat memori project_rbac_restructure_2026_09_29 untuk matrix lengkap &
 * alasan tiap pemisahan permission.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Define permissions
        $permissions = [
            // Projects
            'view projects',
            'create projects',
            'edit projects',
            'delete projects',
            // Cakupan lintas-proyek (bukan dibatasi project_user) — dipakai di
            // banyak controller/policy buat "lihat semua data, bukan cuma
            // proyek yang di-assign". Superadmin & Manager saja.
            'view all projects',

            // Kavlings / SPK / Progress bangun
            'view kavlings',
            'create kavlings',
            'edit kavlings',
            'delete kavlings',
            'update status bangun', // update persen/tahap per-kavling (Proses Bangun)
            'terbitkan spk',        // terbitkan SPK baru (beda dari update progress)
            'book kavling',
            'swap kavling',

            // Konsumens
            'view konsumens',
            'create konsumens',
            'edit konsumens',
            'delete konsumens',

            // Dokumen & pipeline penjualan — dulu 1 permission flat
            // 'update status penjualan', sekarang dipecah per fase supaya
            // Admin Sales & Admin Pemberkasan bisa dipisah teknis (bukan cuma SOP):
            // pemberkasan→proses_bank→SP3K itu ranah 'kelola pemberkasan bank',
            // sisanya (rencana akad/akad/BAST, & pemberkasan→rencana_akad cash
            // yang tidak lewat bank) ranah 'kelola pipeline sales'.
            'manage dokumen',
            'kelola pemberkasan bank',
            'kelola pipeline sales',
            'isi bank rekanan kpr',

            // Keuangan
            'view keuangan',
            'manage pembayaran',         // Kartu Piutang — catat pembayaran konsumen
            'manage rincian biaya akad', // atur nominal SBUM/Dajam/Biaya Akad per transaksi
            'manage kpr',                // catat pencairan KPR/SBUM/Dajam dari bank

            // Pembatalan & tukar unit
            'request cancellation',
            'review cancellation',

            // Override transaksi terkunci (pasca "Tandai Selesai" di Keuangan)
            'override transaction lock',

            // Pengaturan / master data — per domain, menggantikan middleware
            // blanket role:superadmin|manajer. Halaman yang tidak disebut di
            // sini (Profil Developer, Dokumen Template, Warna Status, Surat
            // Template, Biaya Tambahan, Sumber Lead, Promo, Skema DP) sengaja
            // dipukul rata ke 'manage system settings' (superadmin saja).
            'manage bank rekanan',
            'manage notaris',
            'manage dajam sbum preset',
            'manage status bangun master',
            'manage kontraktor',
            'manage sales agent',
            'manage program all in',
            'manage system settings',

            // Role & audit
            'manage roles',
            'view audit trail', // permission disiapkan, halaman menyusul
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }

        // Define roles and assign permissions
        $roles = [
            'superadmin' => $permissions, // all

            // Full read/monitor lintas proyek, laporan eksekutif — TIDAK bisa
            // edit/approve apa pun (murni oversight).
            'manager' => [
                'view all projects', 'view projects', 'view kavlings', 'view konsumens', 'view keuangan',
            ],

            // Supervisi operasional harian + approve transaksi kritis, scoped
            // ke proyek yang di-assign (bukan lintas-proyek).
            'spv' => [
                'view projects', 'view kavlings',
                'view konsumens', 'create konsumens', 'edit konsumens',
                'view keuangan',
                'review cancellation',
                'override transaction lock',
            ],

            // Sama seperti SPV + kelola 2 master data (Sales/Agent, Program
            // All In) — SPV & Leader sengaja dibuat identik dulu (dihold),
            // dibedakan lagi nanti kalau sudah kelihatan kebutuhannya di praktik.
            'leader' => [
                'view projects', 'view kavlings',
                'view konsumens', 'create konsumens', 'edit konsumens',
                'view keuangan',
                'review cancellation',
                'override transaction lock',
                'manage sales agent', 'manage program all in',
            ],

            // Booking, Kartu Piutang, Rencana Akad → BAST. TIDAK pegang
            // pencairan KPR/SBUM/Dajam (itu murni Admin Keuangan).
            'admin_sales' => [
                'view projects', 'view kavlings',
                'view konsumens', 'create konsumens', 'edit konsumens',
                'manage dokumen',
                'kelola pipeline sales', 'isi bank rekanan kpr',
                'view keuangan', 'manage pembayaran', 'manage rincian biaya akad',
                'book kavling', 'swap kavling', 'request cancellation',
            ],

            // Pemberkasan → Proses Bank → SP3K. Verifikasi dokumen KPR.
            'admin_pemberkasan' => [
                'view projects', 'view kavlings',
                'view konsumens', 'create konsumens', 'edit konsumens',
                'manage dokumen',
                'kelola pemberkasan bank', 'isi bank rekanan kpr',
                'view keuangan',
            ],

            // Master data unit (kavling/siteplan/tipe unit), SPK, status bangun.
            'admin_proyek' => [
                'view projects', 'view kavlings', 'create kavlings', 'edit kavlings', 'delete kavlings',
                'terbitkan spk', 'update status bangun',
                'manage status bangun master', 'manage kontraktor',
            ],

            // Cuma update progres fisik per-kavling — paling sempit dari
            // semua role operasional.
            'pelaksana_lapangan' => [
                'view kavlings', 'update status bangun',
            ],

            // Verifikasi pembayaran, rekonsiliasi, pencairan KPR/SBUM/Dajam.
            'admin_keuangan' => [
                'view projects', 'view kavlings', 'view konsumens',
                'view keuangan', 'manage pembayaran', 'manage kpr', 'manage rincian biaya akad',
                'manage bank rekanan', 'manage notaris', 'manage dajam sbum preset',
            ],
        ];

        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $roleName]);
            $role->syncPermissions($rolePermissions);
        }

        // Role lama dibubarkan sepenuhnya (RBAC direstrukturisasi 2026-09-29,
        // data masih dummy jadi aman dihapus langsung tanpa migrasi user).
        Role::whereNotIn('name', array_keys($roles))->delete();
    }
}

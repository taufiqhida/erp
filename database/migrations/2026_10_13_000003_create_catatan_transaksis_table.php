<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan transaksi sebagai entri tersendiri (bisa diedit, dihapus oleh yang berwenang, dan ditandai selesai) menggantikan
 * dua kolom teks: `catatan` (catatan booking) dan `catatan_pemberkasan` (riwayat catatan "Lanjutkan"). Isi lama dikonversi
 * menjadi entri; kolom `catatan_pemberkasan` dibuang. Kolom `catatan` dibiarkan (tidak lagi dipakai).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catatan_transaksis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kavling_konsumen_id')->constrained('kavling_konsumen')->cascadeOnDelete();
            $table->string('jenis', 20);                 // booking | pemberkasan
            $table->string('tahap', 120)->nullable();    // konteks, mis. "Pemberkasan → Proses Bank"
            $table->text('isi');
            $table->boolean('selesai')->default(false);
            $table->foreignId('diselesaikan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diselesaikan_at')->nullable();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->string('penulis_nama', 100)->nullable();   // salinan nama penulis (tetap ada walau akun dihapus / data hasil konversi)
            $table->timestamps();
            $table->softDeletes();
            $table->index(['kavling_konsumen_id', 'jenis']);
        });

        $nama = DB::table('users')->pluck('name', 'id');
        $sekarang = now();

        DB::table('kavling_konsumen')->select('id', 'catatan', 'catatan_pemberkasan', 'created_at', 'created_by')->orderBy('id')->chunk(200, function ($baris) use ($nama, $sekarang) {
            $masuk = [];
            foreach ($baris as $b) {
                if (trim((string) $b->catatan) !== '') {
                    $masuk[] = ['kavling_konsumen_id' => $b->id, 'jenis' => 'booking', 'tahap' => null, 'isi' => trim($b->catatan), 'dibuat_oleh' => $b->created_by,
                        'penulis_nama' => $b->created_by ? ($nama[$b->created_by] ?? null) : null, 'created_at' => $b->created_at ?? $sekarang, 'updated_at' => $b->created_at ?? $sekarang];
                }
                if (trim((string) $b->catatan_pemberkasan) !== '') {
                    foreach (preg_split('/\n\n(?=\[)/', trim($b->catatan_pemberkasan)) as $blok) {
                        $tanggal = $sekarang;
                        $penulis = null;
                        $tahap = null;
                        $isi = trim($blok);
                        if (preg_match('/^\[(.+?) · (.+?) · (.+?)\]\n(.*)$/s', $blok, $m)) {
                            try { $tanggal = Carbon::createFromFormat('d M Y H:i', $m[1]); } catch (\Throwable) { /* tetap sekarang */ }
                            [$penulis, $tahap, $isi] = [$m[2], $m[3], trim($m[4])];
                        }
                        if ($isi !== '') {
                            $masuk[] = ['kavling_konsumen_id' => $b->id, 'jenis' => 'pemberkasan', 'tahap' => $tahap, 'isi' => $isi, 'dibuat_oleh' => null,
                                'penulis_nama' => $penulis, 'created_at' => $tanggal, 'updated_at' => $tanggal];
                        }
                    }
                }
            }
            if ($masuk) DB::table('catatan_transaksis')->insert($masuk);
        });

        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->dropColumn('catatan_pemberkasan');
        });
    }

    public function down(): void
    {
        Schema::table('kavling_konsumen', function (Blueprint $table) {
            $table->text('catatan_pemberkasan')->nullable()->after('catatan');
        });
        Schema::dropIfExists('catatan_transaksis');
    }
};

<?php

namespace App\Console\Commands;

use App\Support\SnapshotJurnal;
use Illuminate\Console\Command;

/**
 * Ambil snapshot jurnal SIRUS satu periode dari baris perintah — untuk cutoff terjadwal (cron) atau periode besar.
 * Mesin sama dengan layar Pembukuan > Tarik Data SIRUS (App\Support\SnapshotJurnal). Versi berstatus Proses dilanjutkan.
 *
 *   php artisan sikeu:ambil-jurnal 202610
 */
class AmbilJurnalSirus extends Command
{
    protected $signature = 'sikeu:ambil-jurnal {periode : Periode YYYYMM}';

    protected $description = 'Ambil jurnal SIRUS satu periode sebagai snapshot versi baru (cutoff)';

    public function handle(): int
    {
        $periode = (string) $this->argument('periode');
        if (!SnapshotJurnal::periodeValid($periode)) {
            $this->error('Periode harus YYYYMM, mis. 202610.');
            return self::FAILURE;
        }

        $snapshotJurnal = new SnapshotJurnal();
        $snapId = $snapshotJurnal->mulai($periode, null);
        $this->info("Snapshot #{$snapId} periode {$periode} dimulai.");

        do {
            $kemajuan = $snapshotJurnal->prosesBerikut($snapId, 30);
            $this->line("  {$kemajuan['cabang_selesai']}/{$kemajuan['jumlah_cabang']} cabang");
        } while ($kemajuan['status'] === SnapshotJurnal::STATUS_PROSES);

        if ($kemajuan['status'] === SnapshotJurnal::STATUS_GAGAL) {
            $this->error('Gagal: ' . ($kemajuan['pesan'] ?? 'galat tak dikenal'));
            return self::FAILURE;
        }
        $this->info('Selesai.');

        return self::SUCCESS;
    }
}

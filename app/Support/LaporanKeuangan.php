<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mesin laporan keuangan ber-TEMPLATE (sikeumst_lap_hdrs → _lap_dtls → _lap_akuns). Susunan baris adalah data,
 * bukan kode — lihat skill keuangan-rs §2 dan DDL 05_template_laporan.sql.
 *
 *   Baris A : Σ nilai akun yang ditautkan (akun itu sendiri, plus semua turunannya bila termasuk_turunan = '1')
 *             sisi M = Σ(debit − kredit) · D = Σ debit · K = Σ kredit · S = saldo akhir; lalu × tanda.
 *   Baris R : rumus "[KODE]+[KODE]-[KODE]" atas nilai yang SUDAH ditampilkan; hanya boleh merujuk baris di atasnya.
 *   Baris J : judul, tanpa nilai.
 *
 * Angka hanya dari jurnal berstatus P. Pemeriksaan bawaan: akun Detail yang tak tertampung (yatim) atau terhitung
 * di lebih dari satu baris (dobel) dilaporkan, supaya tidak ada angka yang hilang / ganda diam-diam.
 */
class LaporanKeuangan
{
    public const JENIS_LABARUGI = 'LABARUGI';
    public const JENIS_NERACA = 'NERACA';

    /** Template aktif pertama untuk satu jenis laporan. */
    public static function template(string $jenis): ?object
    {
        return DB::table('sikeumst_lap_hdrs')->where('jenis', $jenis)->where('aktif', '1')->orderBy('lap_id')->first();
    }

    /**
     * Baris template + akun yang ditautkan.
     * @return array<int, array<string, mixed>>
     */
    public static function barisTemplate(int $lapId): array
    {
        $akunPerBaris = DB::table('sikeumst_lap_akuns as la')->join('sikeumst_lap_dtls as d', 'd.baris_id', '=', 'la.baris_id')
            ->where('d.lap_id', $lapId)->get(['la.baris_id', 'la.akun_id', 'la.termasuk_turunan', 'la.sisi'])->groupBy('baris_id');

        return DB::table('sikeumst_lap_dtls')->where('lap_id', $lapId)->orderBy('urut')->orderBy('kode')->get()
            ->map(fn ($row) => (array) $row + ['tautan' => ($akunPerBaris[$row->baris_id] ?? collect())->map(fn ($tautan) => (array) $tautan)->all()])
            ->all();
    }

    /**
     * Mutasi akun Detail dalam rentang periode (jurnal P).
     * @return array<string, array{debit: float, kredit: float}>
     */
    public static function mutasiPerAkun(string $periodeDari, string $periodeSampai): array
    {
        $hasilList = [];
        $rows = DB::select(
            "SELECT d.akun_id, NVL(SUM(d.debit), 0) AS debit, NVL(SUM(d.kredit), 0) AS kredit
               FROM sikeutxn_jurnal_dtls d
               JOIN sikeutxn_jurnal_hdrs h ON h.jurnal_id = d.jurnal_id
              WHERE h.status = 'P' AND h.periode >= ? AND h.periode <= ? AND h.sumber <> 'SALDO_AWAL'
              GROUP BY d.akun_id",
            [$periodeDari, $periodeSampai]
        );
        foreach ($rows as $row) {
            $hasilList[(string) $row->akun_id] = ['debit' => round((float) $row->debit, 2), 'kredit' => round((float) $row->kredit, 2)];
        }

        return $hasilList;
    }

    /**
     * Akun Detail per baris A (penjabaran turunan lewat hierarki induk, bukan awalan kode — format kode bebas).
     * @return array<string, array<int, string>> kode baris => akun_id[]
     */
    public static function akunPerBaris(array $barisList, array $akunDetailList, array $leluhurPerAkun): array
    {
        $hasilList = [];
        foreach ($barisList as $baris) {
            if ($baris['tipe'] !== 'A') {
                continue;
            }
            $anggota = [];
            foreach ($baris['tautan'] as $tautan) {
                foreach ($akunDetailList as $akunId) {
                    $cocok = $akunId === $tautan['akun_id']
                        || ($tautan['termasuk_turunan'] === '1' && in_array($tautan['akun_id'], $leluhurPerAkun[$akunId] ?? [], true));
                    if ($cocok) {
                        $anggota[$akunId] = $tautan['sisi'];
                    }
                }
            }
            $hasilList[$baris['kode']] = $anggota;
        }

        return $hasilList;
    }

    /** @return array<string, array<int, string>> akun_id => daftar leluhurnya (induk, kakek, ...) */
    public static function leluhurPerAkun(): array
    {
        $indukList = DB::table('sikeumst_akuns')->pluck('induk_id', 'akun_id')->all();
        $hasilList = [];
        foreach ($indukList as $akunId => $indukId) {
            $leluhur = [];
            $langkah = 0;
            while ($indukId !== null && $langkah++ < 20) {
                $leluhur[] = (string) $indukId;
                $indukId = $indukList[$indukId] ?? null;
            }
            $hasilList[(string) $akunId] = $leluhur;
        }

        return $hasilList;
    }

    /** Evaluasi rumus "[A]+[B]-[C]" atas nilai baris yang sudah dihitung. */
    public static function hitungRumus(string $rumus, array $nilaiPerKode): float
    {
        $rumus = str_replace(' ', '', $rumus);
        if (!preg_match('/^[+-]?\[[A-Za-z0-9_]+\]([+-]\[[A-Za-z0-9_]+\])*$/', $rumus)) {
            throw new RuntimeException("Rumus tidak sah: {$rumus} (hanya [KODE] dengan + dan -).");
        }
        preg_match_all('/([+-]?)\[([A-Za-z0-9_]+)\]/', $rumus, $cocokList, PREG_SET_ORDER);
        $total = 0.0;
        foreach ($cocokList as [, $operator, $kode]) {
            if (!array_key_exists($kode, $nilaiPerKode)) {
                throw new RuntimeException("Rumus merujuk baris [{$kode}] yang tidak ada atau berada di bawahnya.");
            }
            $total += $operator === '-' ? -$nilaiPerKode[$kode] : $nilaiPerKode[$kode];
        }

        return round($total, 2);
    }

    /**
     * Laporan Laba Rugi untuk satu periode: kolom bulan berjalan & Januari s/d periode.
     * @return array{template: ?object, baris: array, kolom: array, pemeriksaan: array}
     */
    public static function labaRugi(string $periode): array
    {
        $template = self::template(self::JENIS_LABARUGI);
        if (!$template) {
            return ['template' => null, 'baris' => [], 'kolom' => [], 'pemeriksaan' => ['galat' => ['Template Laba Rugi belum ada — jalankan database/sql/05_template_laporan.sql.']]];
        }
        $barisList = self::barisTemplate((int) $template->lap_id);
        $kolomList = [
            'bulan' => ['judul' => SnapshotJurnal::labelPeriode($periode), 'dari' => $periode, 'sampai' => $periode],
            'ytd' => ['judul' => '01 s/d ' . SnapshotJurnal::labelPeriode($periode), 'dari' => substr($periode, 0, 4) . '01', 'sampai' => $periode],
        ];

        $akunLabaRugi = DB::table('sikeumst_akuns as a')->join('sikeumst_akun_kelompoks as k', 'k.kelompok_id', '=', 'a.kelompok_id')
            ->where('a.tipe', 'D')->where('k.jenis_laporan', 'L')->orderBy('a.akun_id')->pluck('a.nama', 'a.akun_id')->all();
        $akunPerBaris = self::akunPerBaris($barisList, array_keys($akunLabaRugi), self::leluhurPerAkun());

        $mutasiPerKolom = [];
        foreach ($kolomList as $kunci => $kolom) {
            $mutasiPerKolom[$kunci] = self::mutasiPerAkun($kolom['dari'], $kolom['sampai']);
        }

        $galatList = [];
        $nilaiPerKode = array_fill_keys(array_keys($kolomList), []);
        foreach ($barisList as $indeks => $baris) {
            $barisList[$indeks]['nilai'] = [];
            $barisList[$indeks]['rincian'] = [];
            foreach ($kolomList as $kunci => $kolom) {
                $nilai = null;
                if ($baris['tipe'] === 'A') {
                    $nilai = 0.0;
                    foreach ($akunPerBaris[$baris['kode']] ?? [] as $akunId => $sisi) {
                        $mutasi = $mutasiPerKolom[$kunci][$akunId] ?? ['debit' => 0.0, 'kredit' => 0.0];
                        $nilaiAkun = match ($sisi) {
                            'D' => $mutasi['debit'],
                            'K' => $mutasi['kredit'],
                            default => $mutasi['debit'] - $mutasi['kredit'],
                        } * (int) $baris['tanda'];
                        $nilai += $nilaiAkun;
                        if ($nilaiAkun != 0.0 || $kunci === 'bulan') {
                            $barisList[$indeks]['rincian'][$akunId]['nama'] = $akunLabaRugi[$akunId] ?? $akunId;
                            $barisList[$indeks]['rincian'][$akunId][$kunci] = round($nilaiAkun, 2);
                        }
                    }
                    $nilai = round($nilai, 2);
                }
                if ($baris['tipe'] === 'R') {
                    try {
                        $nilai = self::hitungRumus((string) $baris['rumus'], $nilaiPerKode[$kunci]) * (int) $baris['tanda'];
                    } catch (RuntimeException $exception) {
                        $galatList[$exception->getMessage()] = true;
                        $nilai = 0.0;
                    }
                }
                if ($nilai !== null) {
                    $nilaiPerKode[$kunci][$baris['kode']] = $nilai;
                }
                $barisList[$indeks]['nilai'][$kunci] = $nilai;
            }
            // Rincian: buang akun tanpa nilai di kedua kolom
            $barisList[$indeks]['rincian'] = array_filter($barisList[$indeks]['rincian'],
                fn (array $rincian) => ($rincian['bulan'] ?? 0) != 0.0 || ($rincian['ytd'] ?? 0) != 0.0);
        }

        // Pemeriksaan cakupan & kecocokan
        $hitunganAkun = [];
        foreach ($akunPerBaris as $anggota) {
            foreach (array_keys($anggota) as $akunId) {
                $hitunganAkun[$akunId] = ($hitunganAkun[$akunId] ?? 0) + 1;
            }
        }
        $yatimList = array_values(array_diff(array_keys($akunLabaRugi), array_keys($hitunganAkun)));
        $dobelList = array_keys(array_filter($hitunganAkun, fn (int $jumlah) => $jumlah > 1));
        $labaBersihAkun = [];
        foreach ($kolomList as $kunci => $kolom) {
            $labaBersihAkun[$kunci] = round(array_sum(array_map(
                fn (string $akunId) => ($mutasiPerKolom[$kunci][$akunId]['kredit'] ?? 0) - ($mutasiPerKolom[$kunci][$akunId]['debit'] ?? 0),
                array_keys($akunLabaRugi)
            )), 2);
        }
        $barisTerakhirR = collect($barisList)->where('tipe', 'R')->last();

        return [
            'template' => $template,
            'baris' => $barisList,
            'kolom' => $kolomList,
            'pemeriksaan' => [
                'galat' => array_keys($galatList),
                'yatim' => array_map(fn (string $akunId) => ['akun_id' => $akunId, 'nama' => $akunLabaRugi[$akunId],
                    'bernilai' => ($mutasiPerKolom['ytd'][$akunId] ?? null) !== null], $yatimList),
                'dobel' => $dobelList,
                'laba_bersih_akun' => $labaBersihAkun,
                'laba_bersih_laporan' => $barisTerakhirR ? $barisTerakhirR['nilai'] : [],
                'kode_laba_bersih' => $barisTerakhirR['kode'] ?? null,
            ],
        ];
    }
}

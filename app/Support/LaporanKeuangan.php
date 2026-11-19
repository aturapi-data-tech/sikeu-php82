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

    public const NAMA_BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /** 202501 → "Januari 2025" */
    public static function namaPeriode(string $periode): string
    {
        return self::NAMA_BULAN[(int) substr($periode, 4, 2)] . ' ' . substr($periode, 0, 4);
    }

    /** 202501 → "31 Januari 2025" (tanggal terakhir bulan itu) */
    public static function tanggalAkhir(string $periode): string
    {
        $hari = cal_days_in_month(CAL_GREGORIAN, (int) substr($periode, 4, 2), (int) substr($periode, 0, 4));

        return $hari . ' ' . self::namaPeriode($periode);
    }

    /**
     * Template untuk satu jenis laporan: yang dipilih ($lapId, aktif & jenisnya cocok), atau yang ditandai BAWAAN,
     * atau aktif pertama. Satu jenis boleh punya banyak template (mis. Umum / Internal) — DDL 09.
     */
    public static function template(string $jenis, ?int $lapId = null): ?object
    {
        $query = fn () => DB::table('sikeumst_lap_hdrs')->where('jenis', $jenis)->where('aktif', '1');
        if ($lapId) {
            $dipilih = $query()->where('lap_id', $lapId)->first();
            if ($dipilih) {
                return $dipilih;
            }
        }

        return $query()->orderByDesc('bawaan')->orderBy('lap_id')->first();
    }

    /** Template aktif per jenis untuk pilihan di layar laporan: lap_id => nama (bawaan di depan). */
    public static function daftarTemplate(string $jenis): array
    {
        return DB::table('sikeumst_lap_hdrs')->where('jenis', $jenis)->where('aktif', '1')
            ->orderByDesc('bawaan')->orderBy('nama')->get(['lap_id', 'nama', 'bawaan'])
            ->mapWithKeys(fn ($row) => [(int) $row->lap_id => $row->nama . ($row->bawaan === '1' ? ' (bawaan)' : '')])->all();
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
     * Inti hitung template — dipakai semua laporan.
     * @param array $barisList         hasil barisTemplate()
     * @param array<string,string> $akunCakupan  akun Detail yang WAJIB tertampung tepat sekali (id => nama)
     * @param array $nilaiPerKolom     [kolom][akun_id] => ['D' => debit, 'K' => kredit, 'M' => debit-kredit, 'S' => saldo akhir bertanda]
     * @return array{baris: array, galat: array, yatim: array, dobel: array}
     */
    public static function hitungTemplate(array $barisList, array $akunCakupan, array $nilaiPerKolom): array
    {
        $kolomKunci = array_keys($nilaiPerKolom);
        $akunPerBaris = self::akunPerBaris($barisList, array_keys($akunCakupan), self::leluhurPerAkun());

        $galatList = [];
        $nilaiPerKode = array_fill_keys($kolomKunci, []);
        foreach ($barisList as $indeks => $baris) {
            $barisList[$indeks]['nilai'] = [];
            $barisList[$indeks]['rincian'] = [];
            foreach ($kolomKunci as $kunci) {
                $nilai = null;
                if ($baris['tipe'] === 'A') {
                    $nilai = 0.0;
                    foreach ($akunPerBaris[$baris['kode']] ?? [] as $akunId => $sisi) {
                        $nilaiAkun = (float) ($nilaiPerKolom[$kunci][$akunId][$sisi] ?? 0) * (int) $baris['tanda'];
                        $nilai += $nilaiAkun;
                        $barisList[$indeks]['rincian'][$akunId]['nama'] = $akunCakupan[$akunId] ?? $akunId;
                        $barisList[$indeks]['rincian'][$akunId][$kunci] = round($nilaiAkun, 2);
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
            // Rincian: hanya akun yang bernilai di salah satu kolom
            $barisList[$indeks]['rincian'] = array_filter($barisList[$indeks]['rincian'], function (array $rincian) use ($kolomKunci) {
                foreach ($kolomKunci as $kunci) {
                    if (($rincian[$kunci] ?? 0) != 0.0) {
                        return true;
                    }
                }
                return false;
            });
        }

        $hitunganAkun = [];
        foreach ($akunPerBaris as $anggota) {
            foreach (array_keys($anggota) as $akunId) {
                $hitunganAkun[$akunId] = ($hitunganAkun[$akunId] ?? 0) + 1;
            }
        }
        $yatimList = array_values(array_diff(array_keys($akunCakupan), array_keys($hitunganAkun)));
        $bernilai = function (string $akunId) use ($nilaiPerKolom, $kolomKunci): bool {
            foreach ($kolomKunci as $kunci) {
                foreach ($nilaiPerKolom[$kunci][$akunId] ?? [] as $nilai) {
                    if ((float) $nilai != 0.0) {
                        return true;
                    }
                }
            }
            return false;
        };

        return [
            'baris' => $barisList,
            'galat' => array_keys($galatList),
            'yatim' => array_map(fn (string $akunId) => ['akun_id' => $akunId, 'nama' => $akunCakupan[$akunId], 'bernilai' => $bernilai($akunId)], $yatimList),
            'dobel' => array_keys(array_filter($hitunganAkun, fn (int $jumlah) => $jumlah > 1)),
        ];
    }

    /** Akun Detail per jenis laporan (N / L / semua) — id => nama. */
    public static function akunDetail(?string $jenisLaporan = null): array
    {
        return DB::table('sikeumst_akuns as a')->join('sikeumst_akun_kelompoks as k', 'k.kelompok_id', '=', 'a.kelompok_id')
            ->where('a.tipe', 'D')->when($jenisLaporan !== null, fn ($query) => $query->where('k.jenis_laporan', $jenisLaporan))
            ->orderBy('a.akun_id')->pluck('a.nama', 'a.akun_id')->all();
    }

    /**
     * Laporan Laba Rugi untuk satu periode: kolom bulan berjalan & Januari s/d periode.
     * @return array{template: ?object, baris: array, kolom: array, pemeriksaan: array}
     */
    public static function labaRugi(string $periode, ?int $lapId = null): array
    {
        $template = self::template(self::JENIS_LABARUGI, $lapId);
        if (!$template) {
            return ['template' => null, 'baris' => [], 'kolom' => [], 'pemeriksaan' => ['galat' => ['Template Laba Rugi belum ada — jalankan database/sql/05_template_laporan.sql.']]];
        }
        $kolomList = [
            'bulan' => ['judul' => self::namaPeriode($periode), 'sub' => 'bulan ini saja', 'dari' => $periode, 'sampai' => $periode],
            'ytd' => ['judul' => (str_ends_with($periode, '01') ? '' : 'Januari – ') . self::namaPeriode($periode), 'sub' => 'kumulatif sejak 1 Januari',
                'dari' => substr($periode, 0, 4) . '01', 'sampai' => $periode],
        ];
        $akunLabaRugi = self::akunDetail('L');

        $nilaiPerKolom = [];
        foreach ($kolomList as $kunci => $kolom) {
            $nilaiPerKolom[$kunci] = [];
            foreach (self::mutasiPerAkun($kolom['dari'], $kolom['sampai']) as $akunId => $mutasi) {
                $bersih = round($mutasi['debit'] - $mutasi['kredit'], 2);
                $nilaiPerKolom[$kunci][$akunId] = ['D' => $mutasi['debit'], 'K' => $mutasi['kredit'], 'M' => $bersih, 'S' => $bersih];
            }
        }
        $hasil = self::hitungTemplate(self::barisTemplate((int) $template->lap_id), $akunLabaRugi, $nilaiPerKolom);

        $labaBersihAkun = [];
        foreach (array_keys($kolomList) as $kunci) {
            $labaBersihAkun[$kunci] = round(-array_sum(array_map(
                fn (string $akunId) => $nilaiPerKolom[$kunci][$akunId]['M'] ?? 0, array_keys($akunLabaRugi))), 2);
        }
        $barisTerakhirR = collect($hasil['baris'])->where('tipe', 'R')->last();

        return [
            'template' => $template,
            'baris' => $hasil['baris'],
            'kolom' => $kolomList,
            'pemeriksaan' => [
                'galat' => $hasil['galat'],
                'yatim' => $hasil['yatim'],
                'dobel' => $hasil['dobel'],
                'laba_bersih_akun' => $labaBersihAkun,
                'laba_bersih_laporan' => $barisTerakhirR ? $barisTerakhirR['nilai'] : [],
                'kode_laba_bersih' => $barisTerakhirR['kode'] ?? null,
            ],
        ];
    }

    /**
     * Saldo akhir bertanda (+ debit / − kredit) per akun Detail pada akhir periode, dari Neraca Saldo
     * (akun Laba Rugi = sejak Januari). Laba tahun lalu yang belum ditutup buku dimasukkan ke akun laba ditahan.
     * @return array{saldo: array<string, float>, laba_tahun_lalu: float}
     */
    public static function saldoAkhirPerAkun(string $periode, bool $hanyaSaldoAwal = false): array
    {
        $neracaSaldo = (new BukuBesar())->neracaSaldo($periode);
        $saldoList = [];
        foreach ($neracaSaldo['akun'] as $akun) {
            $saldoList[$akun['akun_id']] = $hanyaSaldoAwal ? $akun['awal'] : $akun['akhir'];
        }
        $akunDitahan = Pengaturan::ambil('akun.laba_ditahan');
        if ($neracaSaldo['laba_tahun_lalu'] != 0.0 && $akunDitahan !== '') {
            $saldoList[$akunDitahan] = round(($saldoList[$akunDitahan] ?? 0) - $neracaSaldo['laba_tahun_lalu'], 2);
        }

        return ['saldo' => $saldoList, 'laba_tahun_lalu' => $neracaSaldo['laba_tahun_lalu']];
    }

    /**
     * Neraca (Laporan Posisi Keuangan) per akhir periode, pembanding = 1 Januari tahun itu (saldo awal sah).
     * @return array{template: ?object, baris: array, kolom: array, pemeriksaan: array}
     */
    public static function neraca(string $periode, ?int $lapId = null): array
    {
        $template = self::template(self::JENIS_NERACA, $lapId);
        if (!$template) {
            return ['template' => null, 'baris' => [], 'kolom' => [], 'pemeriksaan' => ['galat' => ['Template Neraca belum ada — jalankan database/sql/07_template_neraca.sql.']]];
        }
        $periodeAwal = substr($periode, 0, 4) . '01';
        $kolomList = [
            // urutan kronologis (permintaan user 2026-11-19): awal tahun di kiri, akhir bulan di kanan
            'awal' => ['judul' => '31 Desember ' . ((int) substr($periode, 0, 4) - 1), 'sub' => 'posisi awal tahun (saldo awal 1 Januari ' . substr($periode, 0, 4) . ')'],
            'akhir' => ['judul' => self::tanggalAkhir($periode), 'sub' => 'posisi akhir bulan ini'],
        ];
        $sumberSaldo = ['akhir' => self::saldoAkhirPerAkun($periode), 'awal' => self::saldoAkhirPerAkun($periodeAwal, true)];

        $nilaiPerKolom = [];
        foreach ($sumberSaldo as $kunci => $hasilSaldo) {
            $nilaiPerKolom[$kunci] = [];
            foreach ($hasilSaldo['saldo'] as $akunId => $saldo) {
                $nilaiPerKolom[$kunci][$akunId] = ['S' => $saldo, 'M' => $saldo, 'D' => max($saldo, 0), 'K' => max(-$saldo, 0)];
            }
        }
        // Neraca menampung SEMUA akun Detail: akun Neraca lewat baris aset/liabilitas/ekuitas, akun Laba Rugi lewat laba berjalan
        $hasil = self::hitungTemplate(self::barisTemplate((int) $template->lap_id), self::akunDetail(), $nilaiPerKolom);

        // Pemeriksaan: Σ saldo semua akun = 0 (D = K) dan baris aset = baris liabilitas + ekuitas.
        // Kode baris total WAJIB 'TA' (total aset) & 'TLE' (total liabilitas + ekuitas) di setiap template Neraca.
        $barisPerKode = collect($hasil['baris'])->keyBy('kode');
        $selisih = [];
        foreach (array_keys($kolomList) as $kunci) {
            $totalAset = $barisPerKode['TA']['nilai'][$kunci] ?? null;
            $totalPasiva = $barisPerKode['TLE']['nilai'][$kunci] ?? null;
            $selisih[$kunci] = [
                'aset' => $totalAset, 'pasiva' => $totalPasiva,
                'selisih_laporan' => ($totalAset !== null && $totalPasiva !== null) ? round($totalAset - $totalPasiva, 2) : null,
                'selisih_buku' => round(array_sum($sumberSaldo[$kunci]['saldo']), 2),
            ];
        }

        return [
            'template' => $template,
            'baris' => $hasil['baris'],
            'kolom' => $kolomList,
            'pemeriksaan' => [
                'galat' => $hasil['galat'],
                'yatim' => $hasil['yatim'],
                'dobel' => $hasil['dobel'],
                'seimbang' => $selisih,
                'laba_tahun_lalu' => $sumberSaldo['akhir']['laba_tahun_lalu'],
            ],
        ];
    }
}

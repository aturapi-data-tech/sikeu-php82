<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * STRUKTUR DATA SIKEU — dibaca LANGSUNG dari kamus data Oracle (user_tables / user_tab_columns / user_constraints),
 * jadi selalu sesuai database yang sedang dipakai (tidak ada daftar tabel yang ditulis tangan selain pengelompokan).
 * Dipakai layar Panduan › Struktur Data: gambar relasi (SVG) + rincian kolom per tabel untuk cross-check.
 */
final class StrukturData
{
    /** Pengelompokan tabel per bagian program (urutan = urutan kolom di gambar). Tabel di luar daftar → "Lainnya". */
    public const KELOMPOK = [
        'Bagan Akun & Peta SIRUS' => [
            'ket' => 'Akun SIKEU, kelompoknya, dan pasangan akun SIRUS → SIKEU',
            'tabel' => ['SIKEUMST_AKUN_KELOMPOKS', 'SIKEUMST_AKUNS', 'SIKEUMST_PETA_AKUN_SIRUS'],
        ],
        'Tarikan SIRUS (versi)' => [
            'ket' => 'Salinan jurnal SIRUS per bulan per versi; dibandingkan lewat Bandingkan Versi',
            'tabel' => ['SIKEUTXN_SNAP_HDRS', 'SIKEUTXN_SNAP_CABANGS', 'SIKEUTXN_SNAP_DTLS'],
        ],
        'Tahun & Periode' => [
            'ket' => 'Tahun buku, 12 periode per tahun (Buka/Kunci), saldo akun saat dikunci',
            'tabel' => ['SIKEUMST_TAHUN_BUKUS', 'SIKEUMST_PERIODES', 'SIKEUTXN_SALDO_AKUNS'],
        ],
        'Jurnal (Buku Besar)' => [
            'ket' => 'Semua jurnal: SIRUS (posting), MANUAL (Jurnal Umum), SALDO_AWAL',
            'tabel' => ['SIKEUTXN_JURNAL_HDRS', 'SIKEUTXN_JURNAL_DTLS'],
        ],
        'Susunan Laporan' => [
            'ket' => 'Template Laba Rugi / Neraca: baris dan akun yang dijumlahkan',
            'tabel' => ['SIKEUMST_LAP_HDRS', 'SIKEUMST_LAP_DTLS', 'SIKEUMST_LAP_AKUNS'],
        ],
        'Disiapkan (modul berikut)' => [
            'ket' => 'Tabel sudah ada, layarnya belum: aset & penyusutan, HPP, kartu utang/piutang, pusat biaya',
            'tabel' => ['SIKEUMST_PUSAT_BIAYAS', 'SIKEUMST_MITRAS', 'SIKEUTXN_HP_KARTUS', 'SIKEUTXN_HP_BAYARS', 'SIKEUMST_ASET_KATEGORIS',
                'SIKEUMST_ASETS', 'SIKEUTXN_ASET_MUTASIS', 'SIKEUTXN_ASET_SUSUTS', 'SIKEUTXN_HPP_HDRS', 'SIKEUTXN_HPP_DTLS'],
        ],
        'Sistem & Hak Akses' => [
            'ket' => 'Pengaturan, identitas instansi, log audit, pengguna, peran & hak akses (Spatie)',
            'tabel' => ['SIKEUMST_PENGATURANS', 'SIKEUMST_IDENTITAS', 'SIKEUTXN_AUDIT_LOGS', 'USERS', 'ROLES', 'PERMISSIONS',
                'MODEL_HAS_ROLES', 'MODEL_HAS_PERMISSIONS', 'ROLE_HAS_PERMISSIONS'],
        ],
    ];

    /** Tabel bawaan Laravel (antrian, cache, sesi) — tidak digambar, hanya disebut. */
    public const TABEL_LARAVEL = ['CACHE', 'CACHE_LOCKS', 'FAILED_JOBS', 'JOBS', 'JOB_BATCHES', 'MIGRATIONS', 'PASSWORD_RESET_TOKENS', 'SESSIONS'];

    /**
     * @return array{kelompok: array<string, array{ket: string, tabel: array<int, string>}>, tabel: array<string, array>, relasi: array<int, array>, laravel: array<int, string>}
     */
    public static function baca(): array
    {
        $semua = DB::table('user_tables')->orderBy('table_name')->pluck('table_name')->map(fn ($nama) => (string) $nama)->all();

        $kolomList = [];
        foreach (DB::select('SELECT table_name, column_name, data_type, data_length, data_precision, data_scale, nullable, column_id
                               FROM user_tab_columns ORDER BY table_name, column_id') as $kolom) {
            $kolomList[$kolom->table_name][] = [
                'nama' => $kolom->column_name,
                'tipe' => self::tipe($kolom),
                'wajib' => $kolom->nullable === 'N',
            ];
        }

        $pk = [];
        foreach (DB::select("SELECT c.table_name, cc.column_name FROM user_constraints c
                               JOIN user_cons_columns cc ON cc.constraint_name = c.constraint_name WHERE c.constraint_type = 'P'") as $baris) {
            $pk[$baris->table_name][$baris->column_name] = true;
        }

        $relasi = [];
        foreach (DB::select("SELECT a.table_name dari, ac.column_name kolom, r.table_name ke, rc.column_name kolom_ke
                               FROM user_constraints a
                               JOIN user_cons_columns ac ON ac.constraint_name = a.constraint_name
                               JOIN user_constraints r ON r.constraint_name = a.r_constraint_name
                               JOIN user_cons_columns rc ON rc.constraint_name = r.constraint_name AND rc.position = ac.position
                              WHERE a.constraint_type = 'R' ORDER BY a.table_name, ac.column_name") as $fk) {
            $relasi[] = ['dari' => $fk->dari, 'kolom' => $fk->kolom, 'ke' => $fk->ke, 'kolom_ke' => $fk->kolom_ke];
        }

        $kelompok = self::KELOMPOK;
        $terkelompok = array_merge(...array_column($kelompok, 'tabel'));
        $lainnya = array_values(array_diff($semua, $terkelompok, self::TABEL_LARAVEL));
        if ($lainnya !== []) {
            $kelompok['Lainnya'] = ['ket' => 'Tabel yang belum masuk pengelompokan', 'tabel' => $lainnya];
        }
        foreach ($kelompok as $nama => $isi) {
            $kelompok[$nama]['tabel'] = array_values(array_intersect($isi['tabel'], $semua));
        }

        $tabel = [];
        foreach ($semua as $nama) {
            if (in_array($nama, self::TABEL_LARAVEL, true)) {
                continue;
            }
            $fkKolom = [];
            foreach ($relasi as $fk) {
                if ($fk['dari'] === $nama) {
                    $fkKolom[$fk['kolom']] = $fk['ke'] . '.' . $fk['kolom_ke'];
                }
            }
            $tabel[$nama] = [
                'kolom' => array_map(fn (array $kolom) => $kolom + ['pk' => isset($pk[$nama][$kolom['nama']]), 'fk' => $fkKolom[$kolom['nama']] ?? null], $kolomList[$nama] ?? []),
                'merujuk' => array_values(array_unique(array_column(array_filter($relasi, fn ($fk) => $fk['dari'] === $nama && $fk['ke'] !== $nama), 'ke'))),
                'dirujuk' => array_values(array_unique(array_column(array_filter($relasi, fn ($fk) => $fk['ke'] === $nama && $fk['dari'] !== $nama), 'dari'))),
            ];
        }

        return ['kelompok' => $kelompok, 'tabel' => $tabel, 'relasi' => $relasi, 'laravel' => array_values(array_intersect(self::TABEL_LARAVEL, $semua))];
    }

    private static function tipe(object $kolom): string
    {
        return match ($kolom->data_type) {
            'NUMBER' => $kolom->data_precision !== null ? "NUMBER({$kolom->data_precision}" . ((int) $kolom->data_scale > 0 ? ",{$kolom->data_scale}" : '') . ')' : 'NUMBER',
            'VARCHAR2', 'CHAR', 'NVARCHAR2' => "{$kolom->data_type}({$kolom->data_length})",
            default => (string) $kolom->data_type,
        };
    }

    /**
     * Tata letak gambar gaya ER Diagram (seperti DBeaver): kelompok = kolom gambar, tabel = kotak berisi daftar kolomnya
     * (PK tebal, FK bertanda). Garis relasi ditarik dari BARIS kolom FK ke baris kolom PK tabel tujuan.
     * $semuaKolom = false → hanya kolom PK/FK (gambar ringkas). Relasi ke tabel sendiri tidak digambar.
     * @return array{lebar: int, tinggi: int, lebarKotak: int, tinggiJudul: int, tinggiBaris: int, kolom: array, kotak: array, garis: array}
     */
    public static function tataLetak(array $data, bool $semuaKolom = true): array
    {
        $lebarKotak = 270;
        $tinggiJudul = 28;
        $tinggiBaris = 20;
        $jarakKolom = 120;
        $jarakTabel = 28;
        $atas = 48;
        $kotak = [];
        $kolomGambar = [];
        $tinggiMaks = 0;
        $indeks = 0;
        foreach ($data['kelompok'] as $namaKelompok => $isi) {
            $x = 16 + $indeks * ($lebarKotak + $jarakKolom);
            $kolomGambar[] = ['nama' => $namaKelompok, 'x' => $x];
            $y = $atas;
            foreach ($isi['tabel'] as $namaTabel) {
                $daftar = array_values(array_filter($data['tabel'][$namaTabel]['kolom'] ?? [], fn (array $kolom) => $semuaKolom || $kolom['pk'] || $kolom['fk']));
                $barisY = [];
                foreach ($daftar as $urutan => $kolom) {
                    $barisY[$kolom['nama']] = $y + $tinggiJudul + $urutan * $tinggiBaris + $tinggiBaris / 2;
                }
                $tinggi = $tinggiJudul + max(1, count($daftar)) * $tinggiBaris + 6;
                $kotak[$namaTabel] = ['x' => $x, 'y' => $y, 'tinggi' => $tinggi, 'kol' => $indeks, 'kolom' => $daftar, 'barisY' => $barisY];
                $y += $tinggi + $jarakTabel;
            }
            $tinggiMaks = max($tinggiMaks, $y);
            $indeks++;
        }

        $garis = [];
        foreach ($data['relasi'] as $fk) {
            if ($fk['dari'] === $fk['ke'] || !isset($kotak[$fk['dari']], $kotak[$fk['ke']])) {
                continue;
            }
            $a = $kotak[$fk['dari']];
            $b = $kotak[$fk['ke']];
            $ya = $a['barisY'][$fk['kolom']] ?? ($a['y'] + $tinggiJudul / 2);
            $yb = $b['barisY'][$fk['kolom_ke']] ?? ($b['y'] + $tinggiJudul / 2);
            if ($a['kol'] === $b['kol']) {
                $xa = $a['x'] + $lebarKotak;
                $xb = $b['x'] + $lebarKotak;
                $lengkung = 40 + abs($ya - $yb) * 0.08;
                $d = "M{$xa},{$ya} C" . ($xa + $lengkung) . ",{$ya} " . ($xb + $lengkung) . ",{$yb} {$xb},{$yb}";
            } else {
                $keKanan = $b['kol'] > $a['kol'];
                $xa = $keKanan ? $a['x'] + $lebarKotak : $a['x'];
                $xb = $keKanan ? $b['x'] : $b['x'] + $lebarKotak;
                $lengkung = ($keKanan ? 1 : -1) * 55;
                $d = "M{$xa},{$ya} C" . ($xa + $lengkung) . ",{$ya} " . ($xb - $lengkung) . ",{$yb} {$xb},{$yb}";
            }
            $garis[] = ['d' => $d, 'dari' => $fk['dari'], 'ke' => $fk['ke'], 'kolom' => $fk['kolom'], 'xa' => $xa, 'ya' => $ya];
        }

        return [
            'lebar' => 16 + $indeks * ($lebarKotak + $jarakKolom), 'tinggi' => (int) $tinggiMaks + 8,
            'lebarKotak' => $lebarKotak, 'tinggiJudul' => $tinggiJudul, 'tinggiBaris' => $tinggiBaris,
            'kolom' => $kolomGambar, 'kotak' => $kotak, 'garis' => $garis,
        ];
    }
}

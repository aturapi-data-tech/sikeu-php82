<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SUSUNAN LAPORAN — kelola template laporan (sikeumst_lap_hdrs → _lap_dtls → _lap_akuns) tanpa SQL.
 * Satu jenis laporan boleh punya banyak template (mis. Umum SAK EP untuk pihak luar, Internal rinci per instalasi);
 * tepat satu aktif ditandai BAWAAN (DDL 09). Kelas instance: yajra insertGetId butuh frame objek.
 *
 *   Baris J judul (tanpa nilai) · A jumlah akun yang ditautkan (sisi M/D/K/S, × tanda) · R rumus "[KODE]+[KODE]-[KODE]"
 *   yang hanya boleh merujuk baris DI ATASNYA. Induk baris = judul J tempat baris dilipat di layar.
 *   Template Neraca wajib punya baris kode TA (total aset) & TLE (total liabilitas + ekuitas) — dipakai pemeriksaan seimbang.
 *
 * Template apa pun harus menghasilkan TOTAL yang sama (laba bersih / neraca seimbang); bedanya hanya rincian.
 * periksa() melaporkan akun yatim (tak tertampung), dobel (terhitung dua kali), dan galat rumus.
 */
class SusunanLaporan
{
    public const JENIS_LIST = [LaporanKeuangan::JENIS_LABARUGI => 'Laba Rugi', LaporanKeuangan::JENIS_NERACA => 'Neraca'];
    public const TIPE_LIST = ['J' => 'Judul', 'A' => 'Jumlah akun', 'R' => 'Rumus'];
    public const SISI_LIST = ['M' => 'Mutasi bersih (D − K)', 'S' => 'Saldo akhir', 'D' => 'Debit saja', 'K' => 'Kredit saja'];

    /** Daftar template + jumlah baris. */
    public function daftar(): array
    {
        $jumlah = DB::table('sikeumst_lap_dtls')->selectRaw('lap_id, COUNT(*) AS jumlah')->groupBy('lap_id')->pluck('jumlah', 'lap_id');

        return DB::table('sikeumst_lap_hdrs')->whereIn('jenis', array_keys(self::JENIS_LIST))
            ->orderBy('jenis')->orderByDesc('bawaan')->orderBy('nama')->get()
            ->map(fn ($row) => (array) $row + ['jumlah_baris' => (int) ($jumlah[$row->lap_id] ?? 0)])->all();
    }

    public function header(int $lapId): object
    {
        $header = DB::table('sikeumst_lap_hdrs')->where('lap_id', $lapId)->first();
        if (!$header || !isset(self::JENIS_LIST[$header->jenis])) {
            throw new RuntimeException('Template tidak ditemukan.');
        }

        return $header;
    }

    private function wajibTeks(string $teks, string $nama, int $maks): string
    {
        $teks = trim($teks);
        if ($teks === '' || mb_strlen($teks) > $maks || !BaganAkun::teksAman($teks)) {
            throw new RuntimeException("{$nama} wajib, maksimal {$maks} karakter, tanpa simbol khusus.");
        }

        return $teks;
    }

    // ─── Template ────────────────────────────────────────────────────────────

    /** Template baru kosong (belum ada baris). */
    public function buat(string $jenis, string $nama, ?string $keterangan): int
    {
        if (!isset(self::JENIS_LIST[$jenis])) {
            throw new RuntimeException('Jenis laporan tidak sah.');
        }
        $nama = $this->wajibTeks($nama, 'Nama template', 100);
        $lapId = (int) DB::table('sikeumst_lap_hdrs')->insertGetId([
            'jenis' => $jenis, 'nama' => $nama, 'aktif' => '1', 'bawaan' => '0',
            'keterangan' => $keterangan !== null && trim($keterangan) !== '' ? mb_substr(trim($keterangan), 0, 500) : null, 'created_at' => now(),
        ], 'lap_id');
        BaganAkun::catatAudit('BUAT_TEMPLATE', 'SIKEUMST_LAP_HDRS', (string) $lapId, null, ['jenis' => $jenis, 'nama' => $nama]);

        return $lapId;
    }

    /** Salin template (baris, induk, tautan akun) jadi template baru yang tidak aktif sebagai bawaan. */
    public function salin(int $lapId, string $nama, ?string $keterangan): int
    {
        $nama = $this->wajibTeks($nama, 'Nama template', 100);
        $asal = $this->header($lapId);

        return DB::transaction(function () use ($asal, $nama, $keterangan) {
            $lapBaru = (int) DB::table('sikeumst_lap_hdrs')->insertGetId([
                'jenis' => $asal->jenis, 'nama' => $nama, 'metode' => $asal->metode, 'aktif' => '1', 'bawaan' => '0',
                'keterangan' => $keterangan !== null && trim($keterangan) !== '' ? mb_substr(trim($keterangan), 0, 500) : null,
                'created_at' => now(),
            ], 'lap_id');
            $petaBaris = [];
            $barisList = DB::table('sikeumst_lap_dtls')->where('lap_id', $asal->lap_id)->orderBy('urut')->get();
            foreach ($barisList as $baris) {
                $petaBaris[$baris->baris_id] = (int) DB::table('sikeumst_lap_dtls')->insertGetId([
                    'lap_id' => $lapBaru, 'kode' => $baris->kode, 'urut' => $baris->urut, 'label' => $baris->label, 'tipe' => $baris->tipe,
                    'rumus' => $baris->rumus, 'tanda' => $baris->tanda, 'tebal' => $baris->tebal,
                ], 'baris_id');
            }
            foreach ($barisList as $baris) {
                if ($baris->induk_baris_id && isset($petaBaris[$baris->induk_baris_id])) {
                    DB::table('sikeumst_lap_dtls')->where('baris_id', $petaBaris[$baris->baris_id])->update(['induk_baris_id' => $petaBaris[$baris->induk_baris_id]]);
                }
            }
            foreach (DB::table('sikeumst_lap_akuns')->whereIn('baris_id', array_keys($petaBaris) ?: [0])->get() as $tautan) {
                DB::table('sikeumst_lap_akuns')->insert(['baris_id' => $petaBaris[$tautan->baris_id], 'akun_id' => $tautan->akun_id,
                    'termasuk_turunan' => $tautan->termasuk_turunan, 'sisi' => $tautan->sisi]);
            }
            BaganAkun::catatAudit('SALIN_TEMPLATE', 'SIKEUMST_LAP_HDRS', (string) $lapBaru, ['dari' => (int) $asal->lap_id], ['nama' => $nama, 'baris' => count($petaBaris)]);

            return $lapBaru;
        });
    }

    public function simpanHeader(int $lapId, string $nama, ?string $keterangan, bool $aktif): void
    {
        $nama = $this->wajibTeks($nama, 'Nama template', 100);
        $lama = $this->header($lapId);
        if ($lama->bawaan === '1' && !$aktif) {
            throw new RuntimeException('Template bawaan tidak bisa dinonaktifkan — jadikan template lain bawaan dulu.');
        }
        $keterangan = $keterangan !== null && trim($keterangan) !== '' ? trim($keterangan) : null;
        if ($keterangan !== null && !BaganAkun::teksAman($keterangan)) {
            throw new RuntimeException('Keterangan tanpa simbol khusus.');
        }
        DB::table('sikeumst_lap_hdrs')->where('lap_id', $lapId)->update([
            'nama' => $nama, 'keterangan' => $keterangan !== null ? mb_substr($keterangan, 0, 500) : null, 'aktif' => $aktif ? '1' : '0', 'updated_at' => now(),
        ]);
        BaganAkun::catatAudit('UBAH_TEMPLATE', 'SIKEUMST_LAP_HDRS', (string) $lapId,
            ['nama' => $lama->nama, 'aktif' => $lama->aktif], ['nama' => $nama, 'aktif' => $aktif ? '1' : '0']);
    }

    /** Jadikan template bawaan jenisnya (template lain sejenis dilepas). Ditolak bila pemeriksaan masih bergalat. */
    public function jadikanBawaan(int $lapId): void
    {
        $header = $this->header($lapId);
        $hasil = $this->periksa($lapId);
        if ($hasil['galat'] !== [] || $hasil['dobel'] !== []) {
            throw new RuntimeException('Template masih bermasalah (' . implode('; ', array_merge($hasil['galat'], $hasil['dobel'] !== [] ? ['akun dobel: ' . implode(', ', $hasil['dobel'])] : []))
                . ') — betulkan dulu sebelum dijadikan bawaan.');
        }
        DB::transaction(function () use ($header) {
            DB::table('sikeumst_lap_hdrs')->where('jenis', $header->jenis)->update(['bawaan' => '0', 'updated_at' => now()]);
            DB::table('sikeumst_lap_hdrs')->where('lap_id', $header->lap_id)->update(['bawaan' => '1', 'aktif' => '1', 'updated_at' => now()]);
            BaganAkun::catatAudit('BAWAAN_TEMPLATE', 'SIKEUMST_LAP_HDRS', (string) $header->lap_id, null, ['jenis' => $header->jenis]);
        });
    }

    public function hapus(int $lapId): void
    {
        $header = $this->header($lapId);
        if ($header->bawaan === '1') {
            throw new RuntimeException('Template bawaan tidak bisa dihapus.');
        }
        DB::transaction(function () use ($header) {
            DB::table('sikeumst_lap_dtls')->where('lap_id', $header->lap_id)->update(['induk_baris_id' => null]);
            DB::table('sikeumst_lap_hdrs')->where('lap_id', $header->lap_id)->delete(); // baris & tautan ikut (ON DELETE CASCADE)
            BaganAkun::catatAudit('HAPUS_TEMPLATE', 'SIKEUMST_LAP_HDRS', (string) $header->lap_id, ['nama' => $header->nama, 'jenis' => $header->jenis], null);
        });
    }

    // ─── Baris ───────────────────────────────────────────────────────────────

    /**
     * Simpan satu baris (baru bila $barisId null). $data: kode, label, tipe, rumus, tanda (1/-1), tebal (bool), induk_baris_id,
     * $tautan: [['akun_id', 'termasuk_turunan' (bool), 'sisi']] untuk tipe A. Baris baru diletakkan di bawah $sesudahBarisId
     * (null = paling bawah).
     */
    public function simpanBaris(int $lapId, ?int $barisId, array $data, array $tautan, ?int $sesudahBarisId = null): int
    {
        $header = $this->header($lapId);
        $kode = strtoupper(trim((string) ($data['kode'] ?? '')));
        if (!preg_match('/^[A-Z0-9_]{1,10}$/', $kode)) {
            throw new RuntimeException('Kode baris 1-10 huruf/angka/garis bawah, mis. PU1, LB.');
        }
        $label = $this->wajibTeks((string) ($data['label'] ?? ''), 'Label', 200);
        $tipe = (string) ($data['tipe'] ?? '');
        if (!isset(self::TIPE_LIST[$tipe])) {
            throw new RuntimeException('Tipe baris tidak sah.');
        }
        $tanda = (int) ($data['tanda'] ?? 1) === -1 ? -1 : 1;
        $rumus = $tipe === 'R' ? str_replace(' ', '', strtoupper(trim((string) ($data['rumus'] ?? '')))) : null;
        if ($tipe === 'R' && !preg_match('/^[+-]?\[[A-Z0-9_]+\]([+-]\[[A-Z0-9_]+\])*$/', (string) $rumus)) {
            throw new RuntimeException('Rumus hanya [KODE] dengan + dan -, mis. [PU]-[BP].');
        }
        $induk = $data['induk_baris_id'] ?? null;
        if ($induk) {
            $barisInduk = DB::table('sikeumst_lap_dtls')->where('baris_id', (int) $induk)->where('lap_id', $lapId)->first();
            if (!$barisInduk || $barisInduk->tipe !== 'J' || (int) $barisInduk->baris_id === (int) $barisId) {
                throw new RuntimeException('Induk baris harus baris Judul di template yang sama.');
            }
        }
        $tautanBersih = [];
        if ($tipe === 'A') {
            $akunAda = DB::table('sikeumst_akuns')->whereIn('akun_id', array_column($tautan, 'akun_id') ?: ['-'])->pluck('akun_id')->all();
            foreach ($tautan as $baris) {
                $akunId = (string) ($baris['akun_id'] ?? '');
                if ($akunId === '') {
                    continue;
                }
                if (!in_array($akunId, $akunAda, true)) {
                    throw new RuntimeException("Akun {$akunId} tidak ada di bagan akun.");
                }
                $sisi = isset(self::SISI_LIST[$baris['sisi'] ?? '']) ? $baris['sisi'] : ($header->jenis === LaporanKeuangan::JENIS_NERACA ? 'S' : 'M');
                $tautanBersih[$akunId] = ['akun_id' => $akunId, 'termasuk_turunan' => !empty($baris['termasuk_turunan']) ? '1' : '0', 'sisi' => $sisi];
            }
            if ($tautanBersih === []) {
                throw new RuntimeException('Baris jumlah akun wajib menautkan minimal satu akun.');
            }
        }

        return DB::transaction(function () use ($lapId, $barisId, $kode, $label, $tipe, $tanda, $rumus, $induk, $data, $tautanBersih, $sesudahBarisId) {
            $kembar = DB::table('sikeumst_lap_dtls')->where('lap_id', $lapId)->where('kode', $kode)
                ->when($barisId, fn ($query) => $query->where('baris_id', '<>', $barisId))->exists();
            if ($kembar) {
                throw new RuntimeException("Kode {$kode} sudah dipakai baris lain di template ini.");
            }
            $isi = ['kode' => $kode, 'label' => $label, 'tipe' => $tipe, 'rumus' => $rumus, 'tanda' => $tanda,
                'tebal' => !empty($data['tebal']) ? '1' : '0', 'induk_baris_id' => $induk ? (int) $induk : null];
            if ($barisId) {
                $lama = DB::table('sikeumst_lap_dtls')->where('baris_id', $barisId)->where('lap_id', $lapId)->first();
                if (!$lama) {
                    throw new RuntimeException('Baris tidak ditemukan.');
                }
                if ($lama->kode !== $kode) {
                    $this->wajibTidakDirujuk($lapId, $lama->kode, $barisId, 'mengganti kodenya');
                }
                DB::table('sikeumst_lap_dtls')->where('baris_id', $barisId)->update($isi);
            } else {
                $barisId = (int) DB::table('sikeumst_lap_dtls')->insertGetId($isi + ['lap_id' => $lapId, 'urut' => 0], 'baris_id');
                $this->sisipkanUrut($lapId, $barisId, $sesudahBarisId);
            }
            DB::table('sikeumst_lap_akuns')->where('baris_id', $barisId)->delete();
            foreach ($tautanBersih as $baris) {
                DB::table('sikeumst_lap_akuns')->insert($baris + ['baris_id' => $barisId]);
            }
            $this->rapikanUrut($lapId);
            // Rumus hanya boleh merujuk baris di atasnya
            if ($tipe === 'R') {
                $this->periksaRujukan($lapId, $barisId, (string) $rumus);
            }
            BaganAkun::catatAudit('SIMPAN_BARIS_TEMPLATE', 'SIKEUMST_LAP_DTLS', (string) $barisId, null, $isi + ['akun' => array_keys($tautanBersih)]);

            return $barisId;
        });
    }

    public function hapusBaris(int $lapId, int $barisId): void
    {
        DB::transaction(function () use ($lapId, $barisId) {
            $baris = DB::table('sikeumst_lap_dtls')->where('baris_id', $barisId)->where('lap_id', $lapId)->first();
            if (!$baris) {
                throw new RuntimeException('Baris tidak ditemukan.');
            }
            if (DB::table('sikeumst_lap_dtls')->where('induk_baris_id', $barisId)->exists()) {
                throw new RuntimeException("Baris {$baris->kode} masih menjadi induk baris lain — pindahkan anaknya dulu.");
            }
            $this->wajibTidakDirujuk($lapId, $baris->kode, $barisId, 'menghapusnya');
            DB::table('sikeumst_lap_dtls')->where('baris_id', $barisId)->delete();
            $this->rapikanUrut($lapId);
            BaganAkun::catatAudit('HAPUS_BARIS_TEMPLATE', 'SIKEUMST_LAP_DTLS', (string) $barisId, ['kode' => $baris->kode, 'label' => $baris->label], null);
        });
    }

    /** Geser baris satu langkah ke atas (-1) / bawah (+1). Ditolak bila membuat rumus merujuk baris di bawahnya. */
    public function geserBaris(int $lapId, int $barisId, int $arah): void
    {
        DB::transaction(function () use ($lapId, $barisId, $arah) {
            $urutList = DB::table('sikeumst_lap_dtls')->where('lap_id', $lapId)->orderBy('urut')->orderBy('kode')->pluck('baris_id')->map(fn ($nilai) => (int) $nilai)->all();
            $posisi = array_search($barisId, $urutList, true);
            $tujuan = $posisi === false ? false : $posisi + ($arah < 0 ? -1 : 1);
            if ($posisi === false || $tujuan < 0 || $tujuan >= count($urutList)) {
                return;
            }
            [$urutList[$posisi], $urutList[$tujuan]] = [$urutList[$tujuan], $urutList[$posisi]];
            foreach ($urutList as $indeks => $id) {
                DB::table('sikeumst_lap_dtls')->where('baris_id', $id)->update(['urut' => ($indeks + 1) * 10]);
            }
            foreach (DB::table('sikeumst_lap_dtls')->where('lap_id', $lapId)->where('tipe', 'R')->get(['baris_id', 'rumus']) as $baris) {
                $this->periksaRujukan($lapId, (int) $baris->baris_id, (string) $baris->rumus);
            }
        });
    }

    private function sisipkanUrut(int $lapId, int $barisId, ?int $sesudahBarisId): void
    {
        $urutList = DB::table('sikeumst_lap_dtls')->where('lap_id', $lapId)->where('baris_id', '<>', $barisId)
            ->orderBy('urut')->orderBy('kode')->pluck('baris_id')->map(fn ($nilai) => (int) $nilai)->all();
        $posisi = $sesudahBarisId !== null ? array_search($sesudahBarisId, $urutList, true) : false;
        array_splice($urutList, $posisi === false ? count($urutList) : $posisi + 1, 0, [$barisId]);
        foreach ($urutList as $indeks => $id) {
            DB::table('sikeumst_lap_dtls')->where('baris_id', $id)->update(['urut' => ($indeks + 1) * 10]);
        }
    }

    private function rapikanUrut(int $lapId): void
    {
        $urutList = DB::table('sikeumst_lap_dtls')->where('lap_id', $lapId)->orderBy('urut')->orderBy('kode')->pluck('baris_id');
        foreach ($urutList as $indeks => $id) {
            DB::table('sikeumst_lap_dtls')->where('baris_id', $id)->update(['urut' => ($indeks + 1) * 10]);
        }
    }

    /** Rumus baris $barisId hanya boleh merujuk kode yang ADA dan berada DI ATASNYA. */
    private function periksaRujukan(int $lapId, int $barisId, string $rumus): void
    {
        $baris = DB::table('sikeumst_lap_dtls')->where('baris_id', $barisId)->first(['urut', 'kode']);
        $atasList = DB::table('sikeumst_lap_dtls')->where('lap_id', $lapId)->where('urut', '<', $baris->urut)->where('tipe', '<>', 'J')->pluck('kode')->all();
        preg_match_all('/\[([A-Z0-9_]+)\]/', strtoupper($rumus), $cocok);
        foreach ($cocok[1] as $kode) {
            if (!in_array($kode, $atasList, true)) {
                throw new RuntimeException("Rumus baris {$baris->kode} merujuk [{$kode}] yang tidak ada / bukan baris bernilai / berada di bawahnya.");
            }
        }
    }

    private function wajibTidakDirujuk(int $lapId, string $kode, int $barisId, string $aksi): void
    {
        $perujuk = DB::table('sikeumst_lap_dtls')->where('lap_id', $lapId)->where('tipe', 'R')->where('baris_id', '<>', $barisId)
            ->where('rumus', 'like', '%[' . $kode . ']%')->pluck('kode')->all();
        if ($perujuk !== []) {
            throw new RuntimeException("Kode {$kode} dipakai rumus baris " . implode(', ', $perujuk) . " — ubah rumusnya dulu sebelum {$aksi}.");
        }
    }

    // ─── Pemeriksaan ─────────────────────────────────────────────────────────

    /**
     * Akun yatim / dobel / galat rumus untuk satu template (tanpa angka). Neraca: wajib ada kode TA & TLE.
     * @return array{galat: array<int, string>, yatim: array, dobel: array<int, string>}
     */
    public function periksa(int $lapId): array
    {
        $header = $this->header($lapId);
        $cakupan = $header->jenis === LaporanKeuangan::JENIS_NERACA ? LaporanKeuangan::akunDetail() : LaporanKeuangan::akunDetail('L');
        $barisList = LaporanKeuangan::barisTemplate($lapId);
        $hasil = LaporanKeuangan::hitungTemplate($barisList, $cakupan, ['x' => []]);
        $galat = $hasil['galat'];
        if ($header->jenis === LaporanKeuangan::JENIS_NERACA) {
            $kodeList = array_column($barisList, 'kode');
            foreach (['TA' => 'total aset', 'TLE' => 'total liabilitas + ekuitas'] as $kode => $arti) {
                if (!in_array($kode, $kodeList, true)) {
                    $galat[] = "Template Neraca wajib punya baris kode {$kode} ({$arti}) untuk pemeriksaan seimbang.";
                }
            }
        }
        if ($header->jenis === LaporanKeuangan::JENIS_LABARUGI && collect($barisList)->where('tipe', 'R')->isEmpty()) {
            $galat[] = 'Template Laba Rugi wajib punya baris rumus terakhir = laba (rugi) bersih.';
        }

        // akun aktif saja yang dianggap yatim (akun nonaktif tanpa saldo tidak perlu ditampung)
        $aktif = DB::table('sikeumst_akuns')->where('aktif', '1')->pluck('akun_id')->flip()->all();

        return ['galat' => $galat, 'yatim' => array_values(array_filter($hasil['yatim'], fn (array $akun) => isset($aktif[$akun['akun_id']]))), 'dobel' => $hasil['dobel']];
    }
}

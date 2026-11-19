<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SALDO AWAL tahun buku — ditetapkan DI SIKEU (bukan ditarik dari SIRUS): SIKEU sumber kebenaran keuangan, SIRUS hanya
 * pemasok jurnal transaksi. Satu jurnal sumber SALDO_AWAL, periode Januari, tanggal 1 Januari.
 * Kelas instance (yajra insertGetId butuh frame objek).
 *
 *   - HANYA tahun pertama SIKEU (pengaturan pembukuan.tahun_mulai) yang diisi. Tahun-tahun sesudahnya diteruskan
 *     otomatis dari saldo akhir Desember (Neraca Saldo menghitung kumulatif) — mengisi lagi = terhitung dua kali,
 *     jadi ditolak di mesin (wajibTahunPertama).
 *   - Sumber angka: neraca audit / neraca penutup 31 Des tahun sebelumnya, rekening koran, opname. Isian berupa
 *     form neraca (simpanIsian) → DRAFT; boleh disimpan bertahap.
 *   - Selisih D/K TIDAK diseimbangkan diam-diam: pemakai memilih sendiri akun penampungnya (seimbangkan()).
 *   - sahkan(): wajib seimbang, periode Januari Buka → status P; saldo awal sah sebelumnya dibatalkan (status B).
 *   - Akun Laba Rugi & akun laba berjalan tidak punya saldo awal (laba tahun lalu masuk akun laba ditahan).
 */
class SaldoAwal
{
    public const SUMBER = 'SALDO_AWAL';

    public static function periodeAwal(int $tahun): string
    {
        return sprintf('%04d01', $tahun);
    }

    public const KUNCI_TAHUN_MULAI = 'pembukuan.tahun_mulai';

    /** Tahun pertama pembukuan di SIKEU (pengaturan); bawaan = tahun buku terlama. */
    public static function tahunMulai(): int
    {
        $tahun = Pengaturan::ambil(self::KUNCI_TAHUN_MULAI);
        if (preg_match('/^\d{4}$/', $tahun)) {
            return (int) $tahun;
        }

        return (int) (DB::table('sikeumst_tahun_bukus')->min('tahun') ?? now()->format('Y'));
    }

    /** Saldo awal hanya boleh diisi untuk tahun pertama SIKEU. */
    public static function wajibTahunPertama(int $tahun): void
    {
        $tahunMulai = self::tahunMulai();
        if ($tahun > $tahunMulai) {
            throw new RuntimeException("Saldo awal {$tahun} tidak diisi — diteruskan otomatis dari saldo akhir Desember " . ($tahun - 1)
                . ". Saldo awal hanya diisi untuk tahun pertama SIKEU ({$tahunMulai}).");
        }
        if ($tahun < $tahunMulai) {
            throw new RuntimeException("Tahun {$tahun} sebelum tahun pertama SIKEU ({$tahunMulai}). Ubah tahun pertama di pengaturan bila pembukuan dimulai lebih awal.");
        }
    }

    /**
     * Dampak set ulang tahun pertama ke $tahunBaru — dipakai jendela konfirmasi dan diperiksa lagi oleh setUlangTahunMulai().
     *   MUNDUR (baru < lama): saldo awal tahun lama dibatalkan (tahun itu nanti diteruskan dari tahun sebelumnya); posting tetap.
     *   MAJU   (baru > lama): posting & saldo awal sebelum tahun baru dibatalkan (hasil tarikan tetap); DITOLAK bila ada
     *                       bulan terkunci sebelum tahun baru (buka kunci dulu, dari bulan terbaru).
     * @return array{arah: string, lama: int, baru: int, saldo_awal: array<int, string>, periode_posting: array<int, string>,
     *   terkunci: array<int, string>, tahun_perlu_dibuka: array<int, int>, boleh: bool, galat: ?string}
     */
    public static function dampakSetUlang(int $tahunBaru): array
    {
        $lama = self::tahunMulai();
        $hasil = ['arah' => $tahunBaru < $lama ? 'mundur' : ($tahunBaru > $lama ? 'maju' : 'sama'), 'lama' => $lama, 'baru' => $tahunBaru,
            'saldo_awal' => [], 'periode_posting' => [], 'terkunci' => [], 'tahun_perlu_dibuka' => [], 'boleh' => false, 'galat' => null];
        if ($tahunBaru < 2000 || $tahunBaru > 2100) {
            $hasil['galat'] = 'Tahun tidak valid.';
            return $hasil;
        }
        if ($hasil['arah'] === 'sama') {
            $hasil['galat'] = "Tahun pertama sudah {$lama}.";
            return $hasil;
        }
        $batas = self::periodeAwal($tahunBaru);
        // saldo awal (draft/sah) di tahun selain tahun baru
        $hasil['saldo_awal'] = DB::table('sikeutxn_jurnal_hdrs')->where('sumber', self::SUMBER)->whereIn('status', ['D', 'P'])
            ->where('periode', '<>', $batas)->orderBy('periode')->pluck('no_jurnal')->map(fn ($nilai) => (string) $nilai)->all();
        if ($hasil['arah'] === 'maju') {
            $hasil['periode_posting'] = DB::table('sikeumst_periodes')->where('periode', '<', $batas)->whereNotNull('snap_id_sah')
                ->orderBy('periode')->pluck('periode')->map(fn ($nilai) => (string) $nilai)->all();
            $hasil['terkunci'] = DB::table('sikeumst_periodes')->where('periode', '<', $batas)
                ->whereIn('status', [BukuBesar::STATUS_KUNCI, BukuBesar::STATUS_TUTUP])
                ->orderBy('periode')->pluck('periode')->map(fn ($nilai) => (string) $nilai)->all();
            if ($hasil['terkunci'] !== []) {
                $hasil['galat'] = 'Masih ada ' . count($hasil['terkunci']) . ' bulan terkunci sebelum ' . $tahunBaru . ' (terakhir '
                    . SnapshotJurnal::labelPeriode((string) end($hasil['terkunci'])) . ') — buka kuncinya dulu dari bulan terbaru.';
                return $hasil;
            }
        }
        $tahunAda = DB::table('sikeumst_tahun_bukus')->pluck('tahun')->map(fn ($nilai) => (int) $nilai)->all();
        $tahunAkhir = max([$tahunBaru, ...$tahunAda]);
        for ($tahun = $tahunBaru; $tahun <= $tahunAkhir; $tahun++) {
            if (!in_array($tahun, $tahunAda, true)) {
                $hasil['tahun_perlu_dibuka'][] = $tahun;
            }
        }
        $hasil['boleh'] = true;

        return $hasil;
    }

    /** Set ulang tahun pertama SIKEU (lihat dampakSetUlang). Alasan wajib, tercatat di audit. */
    public static function setUlangTahunMulai(int $tahunBaru, string $alasan, ?int $userId): array
    {
        $alasan = trim($alasan);
        if (mb_strlen($alasan) < BukuBesar::ALASAN_MIN || !BaganAkun::teksAman($alasan)) {
            throw new RuntimeException('Alasan wajib, minimal ' . BukuBesar::ALASAN_MIN . ' karakter, tanpa simbol khusus.');
        }

        return DB::transaction(function () use ($tahunBaru, $alasan, $userId) {
            $dampak = self::dampakSetUlang($tahunBaru);
            if (!$dampak['boleh']) {
                throw new RuntimeException($dampak['galat'] ?? 'Tidak bisa set ulang.');
            }
            $sekarang = now();
            $batas = self::periodeAwal($tahunBaru);
            // saldo awal tahun lain: draft dihapus, yang sah dibatalkan
            $draftList = DB::table('sikeutxn_jurnal_hdrs')->where('sumber', self::SUMBER)->where('status', 'D')->where('periode', '<>', $batas)->pluck('jurnal_id');
            if ($draftList->isNotEmpty()) {
                DB::table('sikeutxn_jurnal_dtls')->whereIn('jurnal_id', $draftList)->delete();
                DB::table('sikeutxn_jurnal_hdrs')->whereIn('jurnal_id', $draftList)->delete();
            }
            DB::table('sikeutxn_jurnal_hdrs')->where('sumber', self::SUMBER)->where('status', 'P')->where('periode', '<>', $batas)
                ->update(['status' => 'B', 'dibatalkan_oleh' => $userId, 'dibatalkan_at' => $sekarang,
                    'alasan_batal' => mb_substr("Set ulang tahun pertama ke {$tahunBaru}: {$alasan}", 0, 500), 'updated_at' => $sekarang]);
            Pengaturan::simpan(self::KUNCI_TAHUN_MULAI, (string) $tahunBaru);
            $batal = $dampak['arah'] === 'maju' ? (new BukuBesar())->batalkanSebelumTahunMulai($userId) : ['periode' => [], 'jurnal' => 0];
            BaganAkun::catatAudit('SET_ULANG_TAHUN_MULAI', 'SIKEUMST_PENGATURANS', self::KUNCI_TAHUN_MULAI,
                ['tahun' => $dampak['lama']], ['tahun' => $tahunBaru, 'saldo_awal_dibatalkan' => $dampak['saldo_awal'],
                    'periode_dibatalkan' => $batal['periode'], 'jurnal_dibatalkan' => $batal['jurnal']], $alasan);

            return $dampak + ['jurnal_dibatalkan' => $batal['jurnal']];
        });
    }

    /** Akun laba ditahan & laba berjalan dari pengaturan (divalidasi: Detail aktif). */
    public static function akunLaba(): array
    {
        return ['ditahan' => Pengaturan::ambil('akun.laba_ditahan'), 'berjalan' => Pengaturan::ambil('akun.laba_berjalan')];
    }

    /** Jurnal saldo awal tahun itu: draft (D) dan yang sah (P). */
    public function jurnal(int $tahun): array
    {
        $rows = DB::table('sikeutxn_jurnal_hdrs')->where('sumber', self::SUMBER)->where('periode', self::periodeAwal($tahun))
            ->whereIn('status', ['D', 'P'])->orderByDesc('jurnal_id')->get();

        return ['draft' => $rows->firstWhere('status', 'D'), 'sah' => $rows->firstWhere('status', 'P')];
    }

    /** Baris jurnal + nama akun & jenis laporannya. */
    public function baris(int $jurnalId): array
    {
        return DB::table('sikeutxn_jurnal_dtls as d')
            ->join('sikeumst_akuns as a', 'a.akun_id', '=', 'd.akun_id')
            ->join('sikeumst_akun_kelompoks as k', 'k.kelompok_id', '=', 'a.kelompok_id')
            ->where('d.jurnal_id', $jurnalId)->orderBy('d.akun_id')
            ->get(['d.jurnal_dtl_id', 'd.akun_id', 'a.nama', 'a.saldo_normal', 'k.jenis_laporan', 'd.debit', 'd.kredit', 'd.keterangan'])
            ->map(fn ($row) => (array) $row)->all();
    }

    private function wajibPeriodeBuka(int $tahun): void
    {
        self::wajibTahunPertama($tahun);
        $periode = DB::table('sikeumst_periodes')->where('periode', self::periodeAwal($tahun))->lockForUpdate()->first();
        if (!$periode) {
            throw new RuntimeException("Tahun buku {$tahun} belum dibuka — buka di Tarik Data SIRUS.");
        }
        if ($periode->status !== BukuBesar::STATUS_BUKA) {
            throw new RuntimeException('Periode ' . SnapshotJurnal::labelPeriode(self::periodeAwal($tahun)) . ' sudah dikunci — saldo awal tidak bisa diubah.');
        }
    }

    private function wajibDraft(int $tahun): object
    {
        $draft = $this->jurnal($tahun)['draft'];
        if (!$draft) {
            throw new RuntimeException('Belum ada draft saldo awal — isi dan simpan dulu.');
        }

        return $draft;
    }

    /**
     * Simpan isian form neraca sekaligus jadi DRAFT (dibuat bila belum ada). $isian = [akun_id => ['nilai' => bertanda
     * (+ debit, − kredit), 'keterangan' => ?string]]; akun yang tidak disebut tidak disentuh, nilai 0 = baris dihapus.
     * @return int jumlah akun yang berubah
     */
    public function simpanIsian(int $tahun, array $isian, ?int $userId): int
    {
        $akunList = DB::table('sikeumst_akuns as a')->join('sikeumst_akun_kelompoks as k', 'k.kelompok_id', '=', 'a.kelompok_id')
            ->whereIn('a.akun_id', array_map('strval', array_keys($isian)))->get(['a.akun_id', 'a.tipe', 'a.aktif', 'k.jenis_laporan'])->keyBy('akun_id');
        $akunBerjalan = self::akunLaba()['berjalan'];
        foreach ($isian as $akunId => $baris) {
            $akunId = (string) $akunId;
            $akun = $akunList[$akunId] ?? null;
            $nilai = round((float) $baris['nilai'], 2);
            if ($nilai != 0.0 && (!$akun || $akun->tipe !== 'D' || $akun->aktif !== '1' || $akun->jenis_laporan !== 'N' || $akunId === $akunBerjalan)) {
                throw new RuntimeException("Akun {$akunId} tidak boleh punya saldo awal (harus akun Detail Neraca aktif, bukan laba berjalan).");
            }
            if (($baris['keterangan'] ?? null) !== null && !BaganAkun::teksAman((string) $baris['keterangan'])) {
                throw new RuntimeException("Keterangan akun {$akunId}: hanya huruf, angka & tanda baca biasa.");
            }
        }

        return DB::transaction(function () use ($tahun, $isian, $userId) {
            $this->wajibPeriodeBuka($tahun);
            $draft = $this->jurnal($tahun)['draft'];
            $jurnalId = $draft ? (int) $draft->jurnal_id : $this->buatDraftKosong($tahun, $userId, "Saldo awal {$tahun}");
            $lamaList = DB::table('sikeutxn_jurnal_dtls')->where('jurnal_id', $jurnalId)->get()->keyBy('akun_id');
            $urut = (int) $lamaList->max('urut');
            $berubah = [];
            foreach ($isian as $akunId => $baris) {
                $akunId = (string) $akunId;
                $nilai = round((float) $baris['nilai'], 2);
                $keterangan = trim((string) ($baris['keterangan'] ?? '')) !== '' ? mb_substr(trim((string) $baris['keterangan']), 0, 500) : null;
                $lama = $lamaList[$akunId] ?? null;
                $nilaiLama = $lama ? round((float) $lama->debit - (float) $lama->kredit, 2) : 0.0;
                if ($nilai == $nilaiLama && ($nilai == 0.0 || $keterangan === ($lama->keterangan ?? null))) {
                    continue;
                }
                $berubah[$akunId] = ['lama' => $nilaiLama, 'baru' => $nilai];
                if ($nilai == 0.0) {
                    DB::table('sikeutxn_jurnal_dtls')->where('jurnal_dtl_id', $lama->jurnal_dtl_id)->delete();
                } elseif ($lama) {
                    DB::table('sikeutxn_jurnal_dtls')->where('jurnal_dtl_id', $lama->jurnal_dtl_id)
                        ->update(['debit' => max($nilai, 0), 'kredit' => max(-$nilai, 0), 'keterangan' => $keterangan]);
                } else {
                    DB::table('sikeutxn_jurnal_dtls')->insert(['jurnal_id' => $jurnalId, 'urut' => ++$urut, 'akun_id' => $akunId,
                        'debit' => max($nilai, 0), 'kredit' => max(-$nilai, 0), 'keterangan' => $keterangan]);
                }
            }
            $this->hitungTotal($jurnalId);
            if ($berubah !== []) {
                BaganAkun::catatAudit('UBAH_SALDO_AWAL', 'SIKEUTXN_JURNAL_HDRS', (string) $jurnalId, null, ['tahun' => $tahun, 'akun' => $berubah]);
            }

            return count($berubah);
        });
    }

    /** Saldo awal yang diteruskan otomatis (tahun setelah tahun pertama): saldo Neraca per 1 Januari dari buku besar. */
    public function diteruskan(int $tahun): array
    {
        $hasil = LaporanKeuangan::saldoAkhirPerAkun(self::periodeAwal($tahun), true);

        return ['saldo' => array_filter($hasil['saldo'], fn ($nilai) => round((float) $nilai, 2) != 0.0), 'laba_tahun_lalu' => $hasil['laba_tahun_lalu']];
    }

    /** Buat draft kosong (mengganti draft lama). */
    public function buatDraftKosong(int $tahun, ?int $userId, string $keterangan = ''): int
    {
        return DB::transaction(function () use ($tahun, $userId, $keterangan) {
            $this->wajibPeriodeBuka($tahun);
            $draftLama = DB::table('sikeutxn_jurnal_hdrs')->where('sumber', self::SUMBER)
                ->where('periode', self::periodeAwal($tahun))->where('status', 'D')->pluck('jurnal_id');
            if ($draftLama->isNotEmpty()) {
                DB::table('sikeutxn_jurnal_dtls')->whereIn('jurnal_id', $draftLama)->delete();
                DB::table('sikeutxn_jurnal_hdrs')->whereIn('jurnal_id', $draftLama)->delete();
            }
            $urutNomor = 1 + DB::table('sikeutxn_jurnal_hdrs')->where('sumber', self::SUMBER)->where('periode', self::periodeAwal($tahun))->count();

            return (int) DB::table('sikeutxn_jurnal_hdrs')->insertGetId([
                'no_jurnal' => sprintf('SA-%04d-%02d', $tahun, $urutNomor),
                'periode' => self::periodeAwal($tahun),
                'tanggal' => sprintf('%04d-01-01', $tahun),
                'sumber' => self::SUMBER,
                'keterangan' => $keterangan !== '' ? $keterangan : "Saldo awal {$tahun}",
                'status' => 'D',
                'dibuat_oleh' => $userId,
                'created_at' => now(),
            ], 'jurnal_id');
        });
    }

    /** Ubah / tambah baris draft: nilai bertanda (+ debit, − kredit); 0 = hapus baris. Satu akun = satu baris. */
    public function aturBaris(int $tahun, string $akunId, float $nilai, ?string $keterangan = null): void
    {
        $akun = DB::table('sikeumst_akuns as a')->join('sikeumst_akun_kelompoks as k', 'k.kelompok_id', '=', 'a.kelompok_id')
            ->where('a.akun_id', $akunId)->first(['a.akun_id', 'a.tipe', 'a.aktif', 'k.jenis_laporan']);
        if (!$akun || $akun->tipe !== 'D' || $akun->aktif !== '1') {
            throw new RuntimeException('Akun harus akun Detail yang aktif.');
        }
        if ($akun->jenis_laporan === 'L' && round($nilai, 2) != 0.0) {
            throw new RuntimeException('Akun Laba Rugi tidak punya saldo awal — masukkan ke akun laba ditahan.');
        }
        if ($keterangan !== null && !BaganAkun::teksAman($keterangan)) {
            throw new RuntimeException('Keterangan hanya huruf, angka & tanda baca biasa.');
        }
        DB::transaction(function () use ($tahun, $akunId, $nilai, $keterangan) {
            $this->wajibPeriodeBuka($tahun);
            $draft = $this->wajibDraft($tahun);
            $lama = DB::table('sikeutxn_jurnal_dtls')->where('jurnal_id', $draft->jurnal_id)->where('akun_id', $akunId)->first();
            $nilai = round($nilai, 2);
            if ($nilai == 0.0) {
                DB::table('sikeutxn_jurnal_dtls')->where('jurnal_id', $draft->jurnal_id)->where('akun_id', $akunId)->delete();
            }
            if ($nilai != 0.0 && $lama) {
                DB::table('sikeutxn_jurnal_dtls')->where('jurnal_dtl_id', $lama->jurnal_dtl_id)->update([
                    'debit' => max($nilai, 0), 'kredit' => max(-$nilai, 0),
                    'keterangan' => $keterangan !== null ? mb_substr($keterangan, 0, 500) : $lama->keterangan,
                ]);
            }
            if ($nilai != 0.0 && !$lama) {
                DB::table('sikeutxn_jurnal_dtls')->insert([
                    'jurnal_id' => $draft->jurnal_id,
                    'urut' => 1 + (int) DB::table('sikeutxn_jurnal_dtls')->where('jurnal_id', $draft->jurnal_id)->max('urut'),
                    'akun_id' => $akunId, 'debit' => max($nilai, 0), 'kredit' => max(-$nilai, 0),
                    'keterangan' => $keterangan !== null ? mb_substr($keterangan, 0, 500) : null,
                ]);
            }
            $this->hitungTotal((int) $draft->jurnal_id);
            BaganAkun::catatAudit('UBAH_SALDO_AWAL', 'SIKEUTXN_JURNAL_DTLS', "{$draft->jurnal_id}:{$akunId}",
                $lama ? ['debit' => (float) $lama->debit, 'kredit' => (float) $lama->kredit] : null,
                ['debit' => max($nilai, 0), 'kredit' => max(-$nilai, 0)]);
        });
    }

    /** Masukkan seluruh selisih D/K draft ke satu akun pilihan pemakai (menambah / mengurangi baris akun itu). */
    public function seimbangkan(int $tahun, string $akunId): float
    {
        $draft = $this->wajibDraft($tahun);
        $selisih = round((float) $draft->total_debit - (float) $draft->total_kredit, 2);
        if ($selisih == 0.0) {
            return 0.0;
        }
        $nilaiAkun = (float) (DB::table('sikeutxn_jurnal_dtls')->where('jurnal_id', $draft->jurnal_id)->where('akun_id', $akunId)
            ->selectRaw('NVL(SUM(debit - kredit), 0) AS nilai')->value('nilai') ?? 0);
        $this->aturBaris($tahun, $akunId, $nilaiAkun - $selisih, 'Penyeimbang selisih saldo awal ' . Format::uang(abs($selisih)) . ($selisih > 0 ? ' K' : ' D'));

        return $selisih;
    }

    /** Sahkan draft → jurnal saldo awal berstatus P (saldo awal sah sebelumnya dibatalkan). */
    public function sahkan(int $tahun, ?int $userId): void
    {
        DB::transaction(function () use ($tahun, $userId) {
            $this->wajibPeriodeBuka($tahun);
            $draft = $this->wajibDraft($tahun);
            $this->hitungTotal((int) $draft->jurnal_id);
            $total = DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $draft->jurnal_id)->first(['total_debit', 'total_kredit']);
            if (round((float) $total->total_debit - (float) $total->total_kredit, 2) != 0.0) {
                throw new RuntimeException('Saldo awal belum seimbang (selisih ' . Format::uang(abs((float) $total->total_debit - (float) $total->total_kredit)) . ') — seimbangkan dulu ke akun yang Anda pilih.');
            }
            if ((float) $total->total_debit == 0.0) {
                throw new RuntimeException('Draft saldo awal kosong.');
            }
            $sekarang = now();
            $sahLama = DB::table('sikeutxn_jurnal_hdrs')->where('sumber', self::SUMBER)->where('periode', self::periodeAwal($tahun))
                ->where('status', 'P')->pluck('jurnal_id');
            if ($sahLama->isNotEmpty()) {
                DB::table('sikeutxn_jurnal_hdrs')->whereIn('jurnal_id', $sahLama)->update([
                    'status' => 'B', 'dibatalkan_oleh' => $userId, 'dibatalkan_at' => $sekarang,
                    'alasan_batal' => 'Diganti saldo awal ' . $draft->no_jurnal, 'updated_at' => $sekarang,
                ]);
            }
            DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $draft->jurnal_id)->update([
                'status' => 'P', 'diposting_oleh' => $userId, 'diposting_at' => $sekarang, 'updated_at' => $sekarang,
            ]);
            BaganAkun::catatAudit('SAHKAN_SALDO_AWAL', 'SIKEUTXN_JURNAL_HDRS', (string) $draft->jurnal_id,
                ['diganti' => $sahLama->all()], ['tahun' => $tahun, 'total' => (float) $total->total_debit]);
        });
    }

    /** Buang draft (saldo awal sah, bila ada, tetap berlaku). */
    public function hapusDraft(int $tahun): void
    {
        DB::transaction(function () use ($tahun) {
            $this->wajibPeriodeBuka($tahun);
            $draft = $this->wajibDraft($tahun);
            DB::table('sikeutxn_jurnal_dtls')->where('jurnal_id', $draft->jurnal_id)->delete();
            DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $draft->jurnal_id)->delete();
            BaganAkun::catatAudit('HAPUS_DRAFT_SALDO_AWAL', 'SIKEUTXN_JURNAL_HDRS', (string) $draft->jurnal_id, ['tahun' => $tahun], null);
        });
    }

    /** Salin draft dari saldo awal yang sah supaya bisa dikoreksi. */
    public function draftDariSah(int $tahun, ?int $userId): int
    {
        return DB::transaction(function () use ($tahun, $userId) {
            $sah = $this->jurnal($tahun)['sah'];
            if (!$sah) {
                throw new RuntimeException('Belum ada saldo awal sah untuk disalin.');
            }
            $jurnalId = $this->buatDraftKosong($tahun, $userId, 'Koreksi ' . $sah->no_jurnal);
            DB::insert(
                'INSERT INTO sikeutxn_jurnal_dtls (jurnal_id, urut, akun_id, debit, kredit, keterangan)
                 SELECT ?, urut, akun_id, debit, kredit, keterangan FROM sikeutxn_jurnal_dtls WHERE jurnal_id = ?',
                [$jurnalId, $sah->jurnal_id]
            );
            $this->hitungTotal($jurnalId);

            return $jurnalId;
        });
    }

    private function hitungTotal(int $jurnalId): void
    {
        $total = DB::table('sikeutxn_jurnal_dtls')->where('jurnal_id', $jurnalId)
            ->selectRaw('NVL(SUM(debit), 0) AS debit, NVL(SUM(kredit), 0) AS kredit')->first();
        DB::table('sikeutxn_jurnal_hdrs')->where('jurnal_id', $jurnalId)
            ->update(['total_debit' => round((float) $total->debit, 2), 'total_kredit' => round((float) $total->kredit, 2), 'updated_at' => now()]);
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SALDO AWAL tahun buku — satu jurnal sumber SALDO_AWAL per tahun, periode Januari, tanggal 1 Januari.
 * Kelas instance (yajra insertGetId butuh frame objek).
 *
 *   1. tarikDariSirus(): saldo awal SIRUS (API /saldo-awal/{tahun}) → lewat peta akun → DRAFT (status D).
 *      Nilai bertanda = sa_acc_d − sa_acc_k (+ debit, − kredit). Akun Laba Rugi & akun laba berjalan tidak boleh
 *      punya saldo awal → dipindah ke akun laba ditahan (pengaturan akun.laba_ditahan / akun.laba_berjalan).
 *   2. Draft boleh dikoreksi (ubah/tambah/hapus baris). Selisih D/K TIDAK diseimbangkan diam-diam: pemakai memilih
 *      sendiri akun penyeimbangnya (seimbangkan()).
 *   3. sahkan(): wajib seimbang, periode Januari Buka → status P; saldo awal sah sebelumnya dibatalkan (status B).
 *
 * Saldo awal SIRUS 2026 sendiri tidak seimbang (≈ Rp 9,26 M, akun L01 = penyeimbang tutup buku SIRUS) — lihat
 * skill keuangan-rs. Di buku besar, jurnal SALDO_AWAL dihitung sebagai saldo awal (bukan mutasi) periode Januari.
 */
class SaldoAwal
{
    public const SUMBER = 'SALDO_AWAL';

    public static function periodeAwal(int $tahun): string
    {
        return sprintf('%04d01', $tahun);
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
            throw new RuntimeException('Belum ada draft saldo awal — tarik dari SIRUS atau buat draft kosong dulu.');
        }

        return $draft;
    }

    /**
     * Saldo awal SIRUS sebagai baris SIKEU bertanda, sebelum disimpan (juga dipakai pratinjau).
     * @return array{baris: array<string, array{nilai: float, sumber: array<int, string>}>, dipindah: array<int, string>,
     *   belum_dipeta: array<int, string>, total_sirus: array{debit: float, kredit: float}}
     */
    public function hitungDariSirus(int $tahun): array
    {
        $saldoList = SirusApi::get("saldo-awal/{$tahun}")['saldo'] ?? [];
        $petaList = DB::table('sikeumst_peta_akun_sirus')->pluck('akun_id', 'acc_id_sirus')->all();
        $akunList = DB::table('sikeumst_akuns as a')->join('sikeumst_akun_kelompoks as k', 'k.kelompok_id', '=', 'a.kelompok_id')
            ->get(['a.akun_id', 'a.tipe', 'a.aktif', 'k.jenis_laporan'])->keyBy('akun_id');
        $akunLaba = self::akunLaba();
        $ditahan = $akunList[$akunLaba['ditahan']] ?? null;
        if (!$ditahan || $ditahan->tipe !== 'D' || $ditahan->aktif !== '1' || $ditahan->jenis_laporan !== 'N') {
            throw new RuntimeException('Akun laba ditahan di pengaturan belum diisi / bukan akun Detail Neraca aktif.');
        }

        $barisList = [];
        $dipindah = [];
        $belumDipeta = [];
        $totalSirus = ['debit' => 0.0, 'kredit' => 0.0];
        foreach ($saldoList as $saldo) {
            $nilai = round((float) $saldo['sa_acc_d'] - (float) $saldo['sa_acc_k'], 2);
            $totalSirus['debit'] += max($nilai, 0);
            $totalSirus['kredit'] += max(-$nilai, 0);
            if ($nilai == 0.0) {
                continue;
            }
            $accId = (string) $saldo['acc_id'];
            $akunId = $petaList[$accId] ?? null;
            $akun = $akunId !== null ? ($akunList[$akunId] ?? null) : null;
            if (!$akun || $akun->tipe !== 'D' || $akun->aktif !== '1') {
                $belumDipeta[] = $accId;
                continue;
            }
            if ($akun->jenis_laporan === 'L' || $akunId === $akunLaba['berjalan']) {
                $dipindah[] = "{$accId} → {$akunLaba['ditahan']} (dari {$akunId})";
                $akunId = $akunLaba['ditahan'];
            }
            $barisList[$akunId]['nilai'] = round(($barisList[$akunId]['nilai'] ?? 0) + $nilai, 2);
            $barisList[$akunId]['sumber'][] = $accId;
        }
        ksort($barisList);

        return ['baris' => $barisList, 'dipindah' => $dipindah, 'belum_dipeta' => $belumDipeta,
            'total_sirus' => array_map(fn ($nilai) => round($nilai, 2), $totalSirus)];
    }

    /** Tarik saldo awal SIRUS jadi DRAFT baru (draft lama diganti). */
    public function tarikDariSirus(int $tahun, ?int $userId): array
    {
        $hasil = $this->hitungDariSirus($tahun);
        if ($hasil['belum_dipeta'] !== []) {
            throw new RuntimeException('Akun SIRUS belum dipetakan ke akun Detail aktif: ' . implode(', ', $hasil['belum_dipeta']) . '. Lengkapi di Peta Akun SIRUS.');
        }

        return DB::transaction(function () use ($tahun, $userId, $hasil) {
            $this->wajibPeriodeBuka($tahun);
            $jurnalId = $this->buatDraftKosong($tahun, $userId, 'Saldo awal ' . $tahun . ' ditarik dari SIRUS (tktxn_saldoawalakuns)');
            $urut = 0;
            foreach ($hasil['baris'] as $akunId => $baris) {
                if ($baris['nilai'] == 0.0) {
                    continue;
                }
                DB::table('sikeutxn_jurnal_dtls')->insert([
                    'jurnal_id' => $jurnalId, 'urut' => ++$urut, 'akun_id' => $akunId,
                    'debit' => max($baris['nilai'], 0), 'kredit' => max(-$baris['nilai'], 0),
                    'keterangan' => mb_substr('SIRUS: ' . implode(', ', $baris['sumber']), 0, 500),
                ]);
            }
            $this->hitungTotal($jurnalId);
            BaganAkun::catatAudit('TARIK_SALDO_AWAL', 'SIKEUTXN_JURNAL_HDRS', (string) $jurnalId, null,
                ['tahun' => $tahun, 'baris' => $urut, 'dipindah' => $hasil['dipindah']]);

            return $hasil + ['jurnal_id' => $jurnalId];
        });
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

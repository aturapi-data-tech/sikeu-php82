-- =============================================================================
-- SIKEU - 05. Template laporan: LABA RUGI (susunan SAK EP untuk rumah sakit)
-- Jalankan sebagai user SIKEU, SESUDAH 02_bagan_akun.sql (baris menunjuk akun header bagan standar).
--
-- Susunan disimpan sebagai DATA (sikeumst_lap_dtls + sikeumst_lap_akuns), bukan di kode — instansi lain cukup
-- mengubah barisnya. Tipe baris: J judul / A jumlah akun / R rumus antar-baris ([KODE] dengan + dan -).
-- Nilai baris A = SUM(debit - kredit) akun (sisi M = mutasi) x tanda; tanda -1 untuk pos bersaldo kredit
-- (pendapatan) supaya tampil positif. Rumus memakai nilai yang sudah ditampilkan.
-- Baris A menunjuk HEADER dengan termasuk_turunan = '1' -> akun baru di bawahnya otomatis ikut.
--
--   PENDAPATAN USAHA (4.1.01..4.1.05, 4.2, 4.3)        -> PENDAPATAN USAHA NETO [PU]
--   BEBAN POKOK PELAYANAN (5.1..5.4)                   -> [BP]
--   LABA KOTOR PELAYANAN = [PU]-[BP]
--   BEBAN USAHA (6.1..6.4)                             -> [BU]
--   LABA USAHA = [LK]-[BU]
--   + PENDAPATAN LAIN (7) - BEBAN LAIN (8)             -> LABA SEBELUM PAJAK
--   - BEBAN PAJAK (9)                                  -> LABA (RUGI) BERSIH
--
-- Bagian: A periksa - B baris - C akun per baris - D verifikasi - E rollback
-- =============================================================================
SET DEFINE OFF

-- A. PERIKSA - template LABARUGI ada, barisnya masih 0
SELECT h.lap_id, h.nama, (SELECT COUNT(*) FROM sikeumst_lap_dtls d WHERE d.lap_id = h.lap_id) AS jumlah_baris
  FROM sikeumst_lap_hdrs h WHERE h.jenis = 'LABARUGI';

-- B. BARIS (induk_baris_id diisi sesudahnya)
INSERT INTO sikeumst_lap_dtls (lap_id, kode, urut, label, tipe, rumus, tanda, tebal)
  SELECT lap_id, x.kode, x.urut, x.label, x.tipe, x.rumus, x.tanda, x.tebal
    FROM sikeumst_lap_hdrs h,
         (SELECT 'PU0' kode,  10 urut, 'PENDAPATAN USAHA' label,                          'J' tipe, NULL rumus, 1 tanda, '1' tebal FROM dual UNION ALL
          SELECT 'PU1',  11, 'Pendapatan Rawat Jalan',                                    'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'PU2',  12, 'Pendapatan Klinik',                                         'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'PU3',  13, 'Pendapatan Gawat Darurat',                                  'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'PU4',  14, 'Pendapatan Rawat Inap',                                     'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'PU5',  15, 'Pendapatan Kamar Operasi',                                  'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'PU6',  16, 'Pendapatan Farmasi',                                        'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'PU7',  17, 'Pengurang Pendapatan (retur, potongan, selisih tarif BPJS)', 'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'PU',   19, 'PENDAPATAN USAHA NETO',                                     'R', '[PU1]+[PU2]+[PU3]+[PU4]+[PU5]+[PU6]+[PU7]', 1, '1' FROM dual UNION ALL
          SELECT 'BP0',  20, 'BEBAN POKOK PELAYANAN',                                     'J', NULL, 1, '1' FROM dual UNION ALL
          SELECT 'BP1',  21, 'Harga Pokok Penjualan Obat dan BMHP',                       'A', NULL, 1, '0' FROM dual UNION ALL
          SELECT 'BP2',  22, 'Pemakaian Bahan Medis Habis Pakai per Unit',                'A', NULL, 1, '0' FROM dual UNION ALL
          SELECT 'BP3',  23, 'Jasa dan Gaji Tenaga Medis',                                'A', NULL, 1, '0' FROM dual UNION ALL
          SELECT 'BP4',  24, 'Operasional Pelayanan',                                     'A', NULL, 1, '0' FROM dual UNION ALL
          SELECT 'BP',   29, 'JUMLAH BEBAN POKOK PELAYANAN',                              'R', '[BP1]+[BP2]+[BP3]+[BP4]', 1, '1' FROM dual UNION ALL
          SELECT 'LK',   30, 'LABA KOTOR PELAYANAN',                                      'R', '[PU]-[BP]', 1, '1' FROM dual UNION ALL
          SELECT 'BU0',  40, 'BEBAN USAHA',                                               'J', NULL, 1, '1' FROM dual UNION ALL
          SELECT 'BU1',  41, 'Beban Pegawai',                                             'A', NULL, 1, '0' FROM dual UNION ALL
          SELECT 'BU2',  42, 'Beban Umum dan Administrasi',                               'A', NULL, 1, '0' FROM dual UNION ALL
          SELECT 'BU3',  43, 'Beban Penyusutan dan Amortisasi',                           'A', NULL, 1, '0' FROM dual UNION ALL
          SELECT 'BU4',  44, 'Beban Penurunan Nilai Piutang',                             'A', NULL, 1, '0' FROM dual UNION ALL
          SELECT 'BU',   49, 'JUMLAH BEBAN USAHA',                                        'R', '[BU1]+[BU2]+[BU3]+[BU4]', 1, '1' FROM dual UNION ALL
          SELECT 'LU',   50, 'LABA USAHA',                                                'R', '[LK]-[BU]', 1, '1' FROM dual UNION ALL
          SELECT 'PL',   60, 'Pendapatan Lain-lain',                                      'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'BL',   61, 'Beban Lain-lain',                                           'A', NULL, 1, '0' FROM dual UNION ALL
          SELECT 'LSP',  69, 'LABA SEBELUM PAJAK',                                        'R', '[LU]+[PL]-[BL]', 1, '1' FROM dual UNION ALL
          SELECT 'PJ',   70, 'Beban Pajak Penghasilan',                                   'A', NULL, 1, '0' FROM dual UNION ALL
          SELECT 'LB',   99, 'LABA (RUGI) BERSIH',                                        'R', '[LSP]-[PJ]', 1, '1' FROM dual) x
   WHERE h.jenis = 'LABARUGI' AND ROWNUM <= 1000;

-- Induk: baris di bawah judul bagiannya
UPDATE sikeumst_lap_dtls d SET induk_baris_id = (
         SELECT j.baris_id FROM sikeumst_lap_dtls j WHERE j.lap_id = d.lap_id
            AND j.kode = SUBSTR(d.kode, 1, 2) || '0')
 WHERE d.lap_id = (SELECT lap_id FROM sikeumst_lap_hdrs WHERE jenis = 'LABARUGI')
   AND d.tipe = 'A' AND REGEXP_LIKE(d.kode, '^(PU|BP|BU)[1-9]$');

-- C. AKUN PER BARIS (header + turunannya, sisi M = mutasi bersih)
INSERT INTO sikeumst_lap_akuns (baris_id, akun_id, termasuk_turunan, sisi)
  SELECT d.baris_id, x.akun_id, '1', 'M'
    FROM sikeumst_lap_dtls d
    JOIN sikeumst_lap_hdrs h ON h.lap_id = d.lap_id AND h.jenis = 'LABARUGI'
    JOIN (SELECT 'PU1' kode, '4.1.01' akun_id FROM dual UNION ALL
          SELECT 'PU2', '4.1.02' FROM dual UNION ALL
          SELECT 'PU3', '4.1.03' FROM dual UNION ALL
          SELECT 'PU4', '4.1.04' FROM dual UNION ALL
          SELECT 'PU5', '4.1.05' FROM dual UNION ALL
          SELECT 'PU6', '4.2'    FROM dual UNION ALL
          SELECT 'PU7', '4.3'    FROM dual UNION ALL
          SELECT 'BP1', '5.1'    FROM dual UNION ALL
          SELECT 'BP2', '5.2'    FROM dual UNION ALL
          SELECT 'BP3', '5.3'    FROM dual UNION ALL
          SELECT 'BP4', '5.4'    FROM dual UNION ALL
          SELECT 'BU1', '6.1'    FROM dual UNION ALL
          SELECT 'BU2', '6.2'    FROM dual UNION ALL
          SELECT 'BU3', '6.3'    FROM dual UNION ALL
          SELECT 'BU4', '6.4'    FROM dual UNION ALL
          SELECT 'PL',  '7'      FROM dual UNION ALL
          SELECT 'BL',  '8'      FROM dual UNION ALL
          SELECT 'PJ',  '9'      FROM dual) x ON x.kode = d.kode;

COMMIT;

-- D. VERIFIKASI - 28 baris, 18 tautan akun; akun Detail Laba Rugi yang tak tertampung = 0 baris
SELECT COUNT(*) AS baris FROM sikeumst_lap_dtls WHERE lap_id = (SELECT lap_id FROM sikeumst_lap_hdrs WHERE jenis = 'LABARUGI');
SELECT COUNT(*) AS tautan FROM sikeumst_lap_akuns la JOIN sikeumst_lap_dtls d ON d.baris_id = la.baris_id
 WHERE d.lap_id = (SELECT lap_id FROM sikeumst_lap_hdrs WHERE jenis = 'LABARUGI');

-- E. ROLLBACK
-- DELETE FROM sikeumst_lap_dtls WHERE lap_id = (SELECT lap_id FROM sikeumst_lap_hdrs WHERE jenis = 'LABARUGI'); COMMIT;

-- =============================================================================
-- SIKEU - 07. Template laporan: NERACA (Laporan Posisi Keuangan, SAK EP, badan hukum PT)
-- Jalankan sebagai user SIKEU, SESUDAH 05_template_laporan.sql. Pola sama dengan template Laba Rugi:
-- baris J judul / A jumlah akun / R rumus; baris A menunjuk HEADER + turunannya, sisi S = saldo akhir.
-- Nilai = SUM(debit - kredit) saldo akhir x tanda (tanda -1 untuk liabilitas & ekuitas supaya tampil positif;
-- akun kontra seperti akumulasi penyusutan ikut baris induknya dan otomatis mengurangi).
--
--   Laba (rugi) tahun berjalan = saldo akun kelompok Laba Rugi (4..9) sejak Januari  -> baris EK3
--   Laba tahun-tahun lalu yang belum ditutup buku dihitung mesin ke akun laba ditahan (pengaturan) -> EK2
--   Pemeriksaan di layar: [TA] JUMLAH ASET = [TLE] JUMLAH LIABILITAS DAN EKUITAS.
--
-- Bila badan hukum berubah menjadi yayasan: ganti label EK0..EK3 (Aset Neto tanpa / dengan pembatasan).
-- =============================================================================
SET DEFINE OFF

-- A. PERIKSA - template NERACA ada, barisnya masih 0
SELECT h.lap_id, h.nama, (SELECT COUNT(*) FROM sikeumst_lap_dtls d WHERE d.lap_id = h.lap_id) AS jumlah_baris
  FROM sikeumst_lap_hdrs h WHERE h.jenis = 'NERACA';

-- B. BARIS
INSERT INTO sikeumst_lap_dtls (lap_id, kode, urut, label, tipe, rumus, tanda, tebal)
  SELECT lap_id, x.kode, x.urut, x.label, x.tipe, x.rumus, x.tanda, x.tebal
    FROM sikeumst_lap_hdrs h,
         (SELECT 'AL0' kode, 10 urut, 'ASET LANCAR' label,                                'J' tipe, NULL rumus, 1 tanda, '1' tebal FROM dual UNION ALL
          SELECT 'AL1',  11, 'Kas dan setara kas',                                         'A', NULL,  1, '0' FROM dual UNION ALL
          SELECT 'AL2',  12, 'Piutang usaha - neto',                                       'A', NULL,  1, '0' FROM dual UNION ALL
          SELECT 'AL3',  13, 'Piutang lain-lain',                                          'A', NULL,  1, '0' FROM dual UNION ALL
          SELECT 'AL4',  14, 'Persediaan',                                                 'A', NULL,  1, '0' FROM dual UNION ALL
          SELECT 'AL5',  15, 'Uang muka dan biaya dibayar dimuka',                         'A', NULL,  1, '0' FROM dual UNION ALL
          SELECT 'AL6',  16, 'Pajak dibayar dimuka',                                       'A', NULL,  1, '0' FROM dual UNION ALL
          SELECT 'AL7',  17, 'Akun sementara (wajib nol)',                                 'A', NULL,  1, '0' FROM dual UNION ALL
          SELECT 'AL',   19, 'JUMLAH ASET LANCAR',                                         'R', '[AL1]+[AL2]+[AL3]+[AL4]+[AL5]+[AL6]+[AL7]', 1, '1' FROM dual UNION ALL
          SELECT 'AT0',  20, 'ASET TIDAK LANCAR',                                          'J', NULL,  1, '1' FROM dual UNION ALL
          SELECT 'AT1',  21, 'Aset tetap - neto',                                          'A', NULL,  1, '0' FROM dual UNION ALL
          SELECT 'AT2',  22, 'Aset takberwujud - neto',                                    'A', NULL,  1, '0' FROM dual UNION ALL
          SELECT 'AT3',  23, 'Aset tidak lancar lainnya',                                  'A', NULL,  1, '0' FROM dual UNION ALL
          SELECT 'AT',   29, 'JUMLAH ASET TIDAK LANCAR',                                   'R', '[AT1]+[AT2]+[AT3]', 1, '1' FROM dual UNION ALL
          SELECT 'TA',   30, 'JUMLAH ASET',                                                'R', '[AL]+[AT]', 1, '1' FROM dual UNION ALL
          SELECT 'LP0',  40, 'LIABILITAS JANGKA PENDEK',                                   'J', NULL,  1, '1' FROM dual UNION ALL
          SELECT 'LP1',  41, 'Utang usaha',                                                'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'LP2',  42, 'Utang jasa pelayanan',                                       'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'LP3',  43, 'Utang pajak',                                                'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'LP4',  44, 'Beban masih harus dibayar',                                  'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'LP5',  45, 'Uang muka pasien dan pendapatan diterima dimuka',            'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'LP6',  46, 'Utang bank jangka pendek',                                   'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'LP7',  47, 'Utang lain-lain',                                            'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'LP',   49, 'JUMLAH LIABILITAS JANGKA PENDEK',                            'R', '[LP1]+[LP2]+[LP3]+[LP4]+[LP5]+[LP6]+[LP7]', 1, '1' FROM dual UNION ALL
          SELECT 'LG0',  50, 'LIABILITAS JANGKA PANJANG',                                  'J', NULL,  1, '1' FROM dual UNION ALL
          SELECT 'LG1',  51, 'Utang jangka panjang',                                       'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'LG2',  52, 'Liabilitas imbalan kerja',                                   'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'LG',   59, 'JUMLAH LIABILITAS JANGKA PANJANG',                           'R', '[LG1]+[LG2]', 1, '1' FROM dual UNION ALL
          SELECT 'TL',   60, 'JUMLAH LIABILITAS',                                          'R', '[LP]+[LG]', 1, '1' FROM dual UNION ALL
          SELECT 'EK0',  70, 'EKUITAS',                                                    'J', NULL,  1, '1' FROM dual UNION ALL
          SELECT 'EK1',  71, 'Modal',                                                      'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'EK2',  72, 'Saldo laba',                                                 'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'EK3',  73, 'Laba (rugi) tahun berjalan',                                 'A', NULL, -1, '0' FROM dual UNION ALL
          SELECT 'EK',   79, 'JUMLAH EKUITAS',                                             'R', '[EK1]+[EK2]+[EK3]', 1, '1' FROM dual UNION ALL
          SELECT 'TLE',  99, 'JUMLAH LIABILITAS DAN EKUITAS',                              'R', '[TL]+[EK]', 1, '1' FROM dual) x
   WHERE h.jenis = 'NERACA';

UPDATE sikeumst_lap_dtls d SET induk_baris_id = (
         SELECT j.baris_id FROM sikeumst_lap_dtls j WHERE j.lap_id = d.lap_id AND j.kode = SUBSTR(d.kode, 1, 2) || '0')
 WHERE d.lap_id = (SELECT lap_id FROM sikeumst_lap_hdrs WHERE jenis = 'NERACA')
   AND d.tipe = 'A';

-- C. AKUN PER BARIS (sisi S = saldo akhir, termasuk turunan)
INSERT INTO sikeumst_lap_akuns (baris_id, akun_id, termasuk_turunan, sisi)
  SELECT d.baris_id, x.akun_id, '1', 'S'
    FROM sikeumst_lap_dtls d
    JOIN sikeumst_lap_hdrs h ON h.lap_id = d.lap_id AND h.jenis = 'NERACA'
    JOIN (SELECT 'AL1' kode, '1.1.01' akun_id FROM dual UNION ALL
          SELECT 'AL2', '1.1.02' FROM dual UNION ALL
          SELECT 'AL3', '1.1.03' FROM dual UNION ALL
          SELECT 'AL4', '1.1.04' FROM dual UNION ALL
          SELECT 'AL5', '1.1.05' FROM dual UNION ALL
          SELECT 'AL6', '1.1.06' FROM dual UNION ALL
          SELECT 'AL7', '1.1.09' FROM dual UNION ALL
          SELECT 'AT1', '1.2.01' FROM dual UNION ALL
          SELECT 'AT1', '1.2.02' FROM dual UNION ALL
          SELECT 'AT2', '1.2.03' FROM dual UNION ALL
          SELECT 'AT3', '1.2.04' FROM dual UNION ALL
          SELECT 'LP1', '2.1.01' FROM dual UNION ALL
          SELECT 'LP2', '2.1.02' FROM dual UNION ALL
          SELECT 'LP3', '2.1.03' FROM dual UNION ALL
          SELECT 'LP4', '2.1.04' FROM dual UNION ALL
          SELECT 'LP5', '2.1.05' FROM dual UNION ALL
          SELECT 'LP6', '2.1.06' FROM dual UNION ALL
          SELECT 'LP7', '2.1.07' FROM dual UNION ALL
          SELECT 'LG1', '2.2.01' FROM dual UNION ALL
          SELECT 'LG2', '2.2.02' FROM dual UNION ALL
          SELECT 'EK1', '3.1.01' FROM dual UNION ALL
          SELECT 'EK2', '3.1.02' FROM dual UNION ALL
          SELECT 'EK3', '4' FROM dual UNION ALL
          SELECT 'EK3', '5' FROM dual UNION ALL
          SELECT 'EK3', '6' FROM dual UNION ALL
          SELECT 'EK3', '7' FROM dual UNION ALL
          SELECT 'EK3', '8' FROM dual UNION ALL
          SELECT 'EK3', '9' FROM dual) x ON x.kode = d.kode;

COMMIT;

-- D. VERIFIKASI - 35 baris, 28 tautan
SELECT COUNT(*) AS baris FROM sikeumst_lap_dtls WHERE lap_id = (SELECT lap_id FROM sikeumst_lap_hdrs WHERE jenis = 'NERACA');
SELECT COUNT(*) AS tautan FROM sikeumst_lap_akuns la JOIN sikeumst_lap_dtls d ON d.baris_id = la.baris_id
 WHERE d.lap_id = (SELECT lap_id FROM sikeumst_lap_hdrs WHERE jenis = 'NERACA');

-- E. ROLLBACK
-- DELETE FROM sikeumst_lap_dtls WHERE lap_id = (SELECT lap_id FROM sikeumst_lap_hdrs WHERE jenis = 'NERACA'); COMMIT;

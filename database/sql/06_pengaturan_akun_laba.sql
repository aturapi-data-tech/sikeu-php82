-- =============================================================================
-- SIKEU - 06. Pengaturan akun saldo laba (dipakai saldo awal & tutup buku)
-- Jalankan sebagai user SIKEU, SESUDAH 04_pengaturan.sql. Nilai bawaan = bagan standar 02_bagan_akun.sql;
-- instansi lain mengubahnya di layar Buku Besar > Saldo Awal (hak akun.kelola).
--
--   akun.laba_ditahan   akun Detail ekuitas penampung laba/rugi tahun-tahun lalu (tujuan jurnal penutup;
--                       saldo awal akun Laba Rugi & laba berjalan dari sistem lama dipindah ke sini)
--   akun.laba_berjalan  akun Detail ekuitas "laba (rugi) tahun berjalan" (per 1 Januari harus nol)
-- =============================================================================
SET DEFINE OFF

SELECT kunci, nilai FROM sikeumst_pengaturans WHERE kunci LIKE 'akun.laba%';

INSERT INTO sikeumst_pengaturans (kunci, nilai, keterangan, created_at) VALUES
  ('akun.laba_ditahan', '3.1.02.001', 'Akun saldo laba ditahan (tujuan jurnal penutup & saldo awal laba tahun lalu).', SYSTIMESTAMP);
INSERT INTO sikeumst_pengaturans (kunci, nilai, keterangan, created_at) VALUES
  ('akun.laba_berjalan', '3.1.02.002', 'Akun laba (rugi) tahun berjalan; nol per 1 Januari.', SYSTIMESTAMP);
COMMIT;

SELECT kunci, nilai FROM sikeumst_pengaturans WHERE kunci LIKE 'akun.laba%';

-- ROLLBACK
-- DELETE FROM sikeumst_pengaturans WHERE kunci IN ('akun.laba_ditahan', 'akun.laba_berjalan'); COMMIT;

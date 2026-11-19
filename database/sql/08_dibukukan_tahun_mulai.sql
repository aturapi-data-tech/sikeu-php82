-- =============================================================================
-- SIKEU - 08. Akun SIRUS "masuk pembukuan" + tahun mulai pembukuan SIKEU
-- Jalankan sebagai user SIKEU, SESUDAH 07_template_neraca.sql.
--
-- 1. sikeumst_peta_akun_sirus.dibukukan ('1' ya / '0' diabaikan). Akun SIRUS yang diabaikan TIDAK diposting:
--    karena jurnal SIRUS berpasangan, SELURUH transaksi yang menyentuh akun itu (kedua sisinya) dilewati supaya
--    buku tetap seimbang. Dipakai bila hal itu dikerjakan sendiri di SIKEU (HPP taksiran, aset & penyusutan, kartu
--    utang). Diatur di layar Data Induk > Peta Akun SIRUS; hanya berlaku untuk posting berikutnya.
-- 2. Pengaturan pembukuan.tahun_mulai: tahun pertama SIKEU. Saldo awal HANYA diisi untuk tahun ini (di SIKEU,
--    bukan dari SIRUS); tahun-tahun sesudahnya diteruskan otomatis dari saldo akhir SIKEU.
-- =============================================================================
SET DEFINE OFF

-- A. PERIKSA - kolom belum ada (0 baris)
SELECT column_name FROM user_tab_columns WHERE table_name = 'SIKEUMST_PETA_AKUN_SIRUS' AND column_name = 'DIBUKUKAN';

-- B. KOLOM
ALTER TABLE sikeumst_peta_akun_sirus ADD (dibukukan VARCHAR2(1) DEFAULT '1' NOT NULL);
ALTER TABLE sikeumst_peta_akun_sirus ADD CONSTRAINT ck_sikeu_peta_dibukukan CHECK (dibukukan IN ('0','1'));

-- C. PENGATURAN
INSERT INTO sikeumst_pengaturans (kunci, nilai, keterangan, created_at) VALUES
  ('pembukuan.tahun_mulai', '2026', 'Tahun pertama pembukuan di SIKEU. Saldo awal diisi di SIKEU hanya untuk tahun ini; tahun berikutnya diteruskan otomatis.', SYSTIMESTAMP);
COMMIT;

-- D. VERIFIKASI
SELECT dibukukan, COUNT(*) FROM sikeumst_peta_akun_sirus GROUP BY dibukukan;
SELECT kunci, nilai FROM sikeumst_pengaturans WHERE kunci = 'pembukuan.tahun_mulai';

-- E. ROLLBACK
-- ALTER TABLE sikeumst_peta_akun_sirus DROP CONSTRAINT ck_sikeu_peta_dibukukan;
-- ALTER TABLE sikeumst_peta_akun_sirus DROP COLUMN dibukukan;
-- DELETE FROM sikeumst_pengaturans WHERE kunci = 'pembukuan.tahun_mulai'; COMMIT;

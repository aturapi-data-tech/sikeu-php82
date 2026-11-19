-- =============================================================================
-- SIKEU - 04. Pengaturan aplikasi (kunci - nilai)
-- Jalankan sebagai user SIKEU, SESUDAH 03_peta_akun_sirus.sql.
--
-- Pengaturan yang berbeda antar-organisasi disimpan di sini (diubah lewat layar), bukan di kode, supaya SIKEU bisa
-- dipakai instansi lain. Dibaca lewat App\Support\Pengaturan; satu kunci = satu baris.
--
--   akun.digit_per_tingkat  digit tiap tingkat kode akun dipisah koma, mis. "1,1,2,3" -> 1.1.01.001 (4 tingkat).
--                           Tingkat terakhir selalu akun Detail. Digit tingkat 1 = kode kelompok (maks 2).
--   akun.pemisah            pemisah antar-tingkat: "." / "-" / kosong (tanpa pemisah, mis. 1101001)
--   akun.awal_kontra        nomor rincian tingkat terakhir yang dicadangkan untuk akun kontra (saran kode otomatis
--                           tidak memakainya), mis. 900 -> x.x.xx.9xx. 0 = tidak ada cadangan.
--
-- Bagian: A periksa - B tabel - C data awal - D verifikasi - E rollback
-- =============================================================================
SET DEFINE OFF

-- A. PERIKSA - hasil harus 0 baris
SELECT table_name FROM user_tables WHERE table_name = 'SIKEUMST_PENGATURANS';

-- B. TABEL
CREATE TABLE sikeumst_pengaturans (
  kunci            VARCHAR2(50)   NOT NULL,
  nilai            VARCHAR2(500),
  keterangan       VARCHAR2(500),
  diubah_oleh      NUMBER(19),
  created_at       TIMESTAMP,
  updated_at       TIMESTAMP,
  CONSTRAINT pk_sikeumst_pengaturans PRIMARY KEY (kunci)
);

-- C. DATA AWAL - format bagan standar 02_bagan_akun.sql (K.S.GG.DDD)
INSERT INTO sikeumst_pengaturans (kunci, nilai, keterangan, created_at) VALUES
  ('akun.digit_per_tingkat', '1,1,2,3', 'Digit tiap tingkat kode akun, dipisah koma. Tingkat terakhir = akun Detail.', SYSTIMESTAMP);
INSERT INTO sikeumst_pengaturans (kunci, nilai, keterangan, created_at) VALUES
  ('akun.pemisah', '.', 'Pemisah antar-tingkat kode akun: titik, strip, atau kosong.', SYSTIMESTAMP);
INSERT INTO sikeumst_pengaturans (kunci, nilai, keterangan, created_at) VALUES
  ('akun.awal_kontra', '900', 'Nomor rincian tingkat terakhir mulai dari sini dicadangkan untuk akun kontra; 0 = tidak ada.', SYSTIMESTAMP);
COMMIT;

-- D. VERIFIKASI - 3 baris
SELECT kunci, nilai FROM sikeumst_pengaturans ORDER BY kunci;

-- E. ROLLBACK
-- DROP TABLE sikeumst_pengaturans;

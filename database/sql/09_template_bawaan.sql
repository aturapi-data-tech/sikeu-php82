-- =============================================================================
-- SIKEU - 09. Banyak template per laporan: penanda BAWAAN + keterangan pemakaian
-- Jalankan sebagai user SIKEU, SESUDAH 08_dibukukan_tahun_mulai.sql.
--
-- Satu jenis laporan (LABARUGI / NERACA / ARUSKAS) boleh punya banyak template (mis. "Umum (SAK EP)" untuk pihak luar
-- dan "Internal (Manajemen)" rinci per instalasi). Tepat satu template aktif per jenis ditandai bawaan = '1'; layar
-- laporan memakai bawaan bila pemakai tidak memilih. Dikelola di layar Data Induk > Susunan Laporan.
-- =============================================================================
SET DEFINE OFF

-- A. PERIKSA - kolom belum ada (0 baris)
SELECT column_name FROM user_tab_columns WHERE table_name = 'SIKEUMST_LAP_HDRS' AND column_name IN ('BAWAAN', 'KETERANGAN');

-- B. KOLOM
ALTER TABLE sikeumst_lap_hdrs ADD (bawaan VARCHAR2(1) DEFAULT '0' NOT NULL, keterangan VARCHAR2(500));
ALTER TABLE sikeumst_lap_hdrs ADD CONSTRAINT ck_sikeu_lap_bawaan CHECK (bawaan IN ('0','1'));

-- C. Template aktif pertama tiap jenis = bawaan; nama template lama diperjelas
UPDATE sikeumst_lap_hdrs h SET bawaan = '1'
 WHERE aktif = '1' AND lap_id = (SELECT MIN(x.lap_id) FROM sikeumst_lap_hdrs x WHERE x.jenis = h.jenis AND x.aktif = '1')
   AND jenis IN ('LABARUGI', 'NERACA');
UPDATE sikeumst_lap_hdrs SET nama = 'Laba Rugi - Umum (SAK EP)', keterangan = 'Ringkas per kelompok - untuk pihak luar (bank, KAP, pemegang saham).'
 WHERE jenis = 'LABARUGI' AND bawaan = '1';
UPDATE sikeumst_lap_hdrs SET nama = 'Neraca - Umum (SAK EP)', keterangan = 'Laporan posisi keuangan standar SAK EP.'
 WHERE jenis = 'NERACA' AND bawaan = '1';
COMMIT;

-- D. VERIFIKASI
SELECT lap_id, jenis, nama, aktif, bawaan FROM sikeumst_lap_hdrs ORDER BY jenis, lap_id;

-- E. ROLLBACK
-- ALTER TABLE sikeumst_lap_hdrs DROP CONSTRAINT ck_sikeu_lap_bawaan;
-- ALTER TABLE sikeumst_lap_hdrs DROP (bawaan, keterangan);

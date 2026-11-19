-- =============================================================================
-- SIKEU - 00. Membuat user / skema SIKEU (Sistem Informasi Keuangan)
-- Jalankan sebagai SYSTEM / DBA di server Oracle (service: orcl).
--
-- Ganti <sandi-sikeu> dengan sandi sungguhan SEBELUM dijalankan; JANGAN commit sandinya.
-- SIKEU TIDAK diberi hak apa pun di skema SIRUS: data SIRUS diambil lewat API /api/sikeu (baca saja).
--
-- Urutan pemasangan:
--   1. 00_create_user_sikeu.sql   (file ini, sebagai SYSTEM)
--   2. php artisan migrate        (tabel kerangka Laravel: users, roles, cache, jobs, identitas)
--   3. 01_struktur_pembukuan.sql  (sebagai user SIKEU: tabel pembukuan)
--   4. 02_bagan_akun.sql          (sebagai user SIKEU: bagan akun standar SAK EP)
--   5. 03_peta_akun_sirus.sql     (sebagai user SIKEU: peta akun SIRUS -> SIKEU)
-- =============================================================================
SET DEFINE OFF

-- A. PERIKSA - user sudah ada? (hasil 1 baris = sudah ada, LEWATI bagian B)
SELECT username, account_status, default_tablespace, created
  FROM dba_users
 WHERE username = 'SIKEU';

-- B. BUAT USER
CREATE USER sikeu IDENTIFIED BY "<sandi-sikeu>"
  DEFAULT TABLESPACE users
  TEMPORARY TABLESPACE temp
  QUOTA UNLIMITED ON users;

GRANT CREATE SESSION, CREATE TABLE, CREATE VIEW, CREATE MATERIALIZED VIEW, CREATE SEQUENCE,
      CREATE PROCEDURE, CREATE TRIGGER, CREATE SYNONYM, CREATE TYPE TO sikeu;

-- C. VERIFIKASI
SELECT privilege FROM dba_sys_privs WHERE grantee = 'SIKEU' ORDER BY privilege;

-- D. ROLLBACK (hapus user BESERTA seluruh isinya - hanya bila memang salah pasang)
-- DROP USER sikeu CASCADE;

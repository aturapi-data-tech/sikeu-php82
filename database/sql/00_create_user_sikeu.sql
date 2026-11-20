-- =============================================================================
-- SIKEU - 00. Membuat user / skema SIKEU (Sistem Informasi Keuangan)
-- Jalankan sebagai SYSTEM / DBA di server Oracle (service: orcl).
--
-- Ganti <sandi-sikeu> dengan sandi sungguhan SEBELUM dijalankan; JANGAN commit sandinya.
-- SIKEU TIDAK diberi hak apa pun di skema SIRUS: data SIRUS diambil lewat API /api/sikeu (baca saja).
--
-- Urutan pemasangan:
--   1. 00_create_user_sikeu.sql   (file ini, sebagai SYSTEM)
--   2. .env: DB_* (user sikeu) + ADMIN_AWAL_EMAIL / ADMIN_AWAL_PASSWORD
--   3. php artisan migrate --seed --force
--      = tabel kerangka Laravel (users, roles, hak akses, identitas) + file 01..11 di folder ini berurutan
--        (migrasi 2026_11_20_100000_pasang_struktur_pembukuan, file yang sudah terpasang dilewati) + akun Admin.
--      File 01..11 tetap bisa dijalankan manual lewat SQL*Plus (sebagai user SIKEU) bila perlu.
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

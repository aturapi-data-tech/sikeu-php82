-- Jalankan sebagai SYSTEM / DBA di server Oracle (service: orcl).
-- Membuat skema SIKEU (Sistem Informasi Keuangan) — terpisah dari skema SIRUS.
-- Ganti <sandi-sikeu> dengan sandi sungguhan SEBELUM dijalankan; JANGAN commit sandinya.

CREATE USER sikeu IDENTIFIED BY "<sandi-sikeu>"
  DEFAULT TABLESPACE users
  TEMPORARY TABLESPACE temp
  QUOTA UNLIMITED ON users;

GRANT CREATE SESSION, CREATE TABLE, CREATE VIEW, CREATE SEQUENCE,
      CREATE PROCEDURE, CREATE TRIGGER, CREATE SYNONYM, CREATE TYPE TO sikeu;

-- Lanjutkan dengan 01_grant_baca_sirus.sql (hak BACA ke skema SIRUS).

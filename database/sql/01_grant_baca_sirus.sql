-- ═══════════════════════════════════════════════════════════════════════════
-- Hak BACA SAJA user SIKEU ke skema SIRUS.
-- Jalankan sebagai pemilik skema (user SIRUS) atau DBA. Aman diulang (idempoten):
-- jalankan lagi setiap kali SIRUS menambah tabel / view baru yang perlu dibaca.
--
-- Hanya SELECT — SIKEU tidak bisa INSERT/UPDATE/DELETE ke tabel SIRUS walau kodenya keliru.
-- Mesin jurnal SIRUS (App\Support\Keuangan\Jurnal + JurnalCabang) membaca LANGSUNG
-- ~200 cabang tabel transaksi RJ/UGD/RI/apotek/gudang/kas, jadi hak baca diberikan
-- ke semua tabel & view, KECUALI tabel login / sesi / token milik aplikasi SIRUS.
-- ═══════════════════════════════════════════════════════════════════════════

-- A. PERIKSA — siapa saja objek yang akan diberi hak (tidak mengubah apa pun)
SELECT object_type, COUNT(*) AS jumlah
  FROM all_objects
 WHERE owner = 'SIRUS'
   AND object_type IN ('TABLE', 'VIEW')
   AND object_name NOT IN ('USERS', 'PASSWORD_RESET_TOKENS', 'SESSIONS', 'PERSONAL_ACCESS_TOKENS',
                           'MIGRATIONS', 'JOBS', 'FAILED_JOBS', 'JOB_BATCHES', 'CACHE', 'CACHE_LOCKS')
   AND object_name NOT LIKE 'BIN$%'
 GROUP BY object_type;

-- B. BERI HAK BACA
BEGIN
  FOR o IN (
    SELECT object_name
      FROM all_objects
     WHERE owner = 'SIRUS'
       AND object_type IN ('TABLE', 'VIEW')
       AND object_name NOT IN ('USERS', 'PASSWORD_RESET_TOKENS', 'SESSIONS', 'PERSONAL_ACCESS_TOKENS',
                               'MIGRATIONS', 'JOBS', 'FAILED_JOBS', 'JOB_BATCHES', 'CACHE', 'CACHE_LOCKS')
       AND object_name NOT LIKE 'BIN$%'
  ) LOOP
    BEGIN
      EXECUTE IMMEDIATE 'GRANT SELECT ON SIRUS."' || o.object_name || '" TO sikeu';
    EXCEPTION
      WHEN OTHERS THEN
        -- view yang rusak / tak valid dilewati, tidak menghentikan sisanya
        DBMS_OUTPUT.PUT_LINE('LEWAT ' || o.object_name || ': ' || SQLERRM);
    END;
  END LOOP;
END;
/

-- C. VERIFIKASI — hanya boleh muncul SELECT
SELECT privilege, COUNT(*) AS jumlah
  FROM all_tab_privs
 WHERE grantee = 'SIKEU' AND table_schema = 'SIRUS'
 GROUP BY privilege;

-- D. ROLLBACK (bila perlu mencabut semuanya)
-- BEGIN
--   FOR p IN (SELECT table_name FROM all_tab_privs WHERE grantee = 'SIKEU' AND table_schema = 'SIRUS') LOOP
--     EXECUTE IMMEDIATE 'REVOKE SELECT ON SIRUS."' || p.table_name || '" FROM sikeu';
--   END LOOP;
-- END;
-- /

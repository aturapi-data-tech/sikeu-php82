-- =============================================================================
-- SIKEU - 01. Struktur pembukuan (Oracle 10g)
-- Jalankan sebagai user SIKEU, SESUDAH `php artisan migrate` (lihat 00_create_user_sikeu.sql).
--
-- KONSEP: SIKEU memegang SATU buku besar double-entry (sikeutxn_jurnal_*). Semua angka laporan
-- berasal dari situ:
--
--   sumber jurnal                        tabel asal                      ke buku besar
--   -----------------------------------  ------------------------------  ---------------------------
--   SIRUS   (transaksi pelayanan)        sikeutxn_snap_* versi disahkan  diposting per periode
--   MANUAL  (memorial / penyesuaian)     layar Jurnal Umum               langsung
--   SUSUT   (penyusutan aset tetap)      sikeutxn_aset_susuts            otomatis per periode
--   ASET    (perolehan/jual/hapus aset)  sikeutxn_aset_mutasis          otomatis per kejadian
--   HPP     (harga pokok penjualan)      sikeutxn_hpp_hdrs               otomatis per periode/lokasi
--   HUTANG / PIUTANG (subledger)         sikeutxn_hp_kartus / _bayars   otomatis per dokumen
--   SALDO_AWAL / PENUTUP                 saldo pembukaan / tutup buku    per tahun
--
--   buku besar -> sikeutxn_saldo_akuns (saldo per periode, dihitung ulang saat periode dikunci)
--              -> Neraca, Laba Rugi, Arus Kas (template sikeumst_lap_*)
--
-- ATURAN:
--   * Periode berstatus K (kunci) / T (tutup) tidak boleh menerima jurnal baru atau perubahan;
--     koreksi = jurnal pembalik di periode yang masih buka.
--   * Jurnal berstatus P (posting) tidak di-UPDATE/DELETE; batal = status B + jurnal pembalik.
--   * Snapshot SIRUS berstatus S tidak pernah diubah; ambil ulang = versi baru.
--   * Nominal NUMBER(18,2), kuantitas NUMBER(18,4). Penanda '1'/'0'. Nama objek maks 30 karakter.
--   * PK numerik diisi trigger dari sequence (yajra insertGetId memakai RETURNING ... INTO).
--   * Tidak ada FK ke tabel users (dibuat migrasi Laravel) supaya urutan pasang bebas.
--
-- Bagian: A periksa - B sequence - C tabel master - D tabel transaksi - E trigger - F data awal
--         G verifikasi - H rollback
-- =============================================================================
SET DEFINE OFF

-- -----------------------------------------------------------------------------
-- A. PERIKSA - tabel senama sudah ada? Hanya SIKEUMST_IDENTITAS (dari migrasi) yang boleh muncul.
-- -----------------------------------------------------------------------------
SELECT table_name FROM user_tables
 WHERE table_name LIKE 'SIKEUMST\_%' ESCAPE '\' OR table_name LIKE 'SIKEUTXN\_%' ESCAPE '\'
 ORDER BY table_name;

-- -----------------------------------------------------------------------------
-- B. SEQUENCE
-- -----------------------------------------------------------------------------
CREATE SEQUENCE sq_sikeu_pusat_biaya  START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE sq_sikeu_lap_hdr      START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE sq_sikeu_lap_dtl      START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE sq_sikeu_aset_kat     START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE sq_sikeu_aset         START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE sq_sikeu_aset_mutasi  START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE sq_sikeu_mitra        START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE sq_sikeu_snap         START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE sq_sikeu_jurnal_hdr   START WITH 1 INCREMENT BY 1 CACHE 20;
CREATE SEQUENCE sq_sikeu_jurnal_dtl   START WITH 1 INCREMENT BY 1 CACHE 100;
CREATE SEQUENCE sq_sikeu_hpp_hdr      START WITH 1 INCREMENT BY 1 NOCACHE;
CREATE SEQUENCE sq_sikeu_hp_kartu     START WITH 1 INCREMENT BY 1 CACHE 20;
CREATE SEQUENCE sq_sikeu_hp_bayar     START WITH 1 INCREMENT BY 1 CACHE 20;
CREATE SEQUENCE sq_sikeu_audit        START WITH 1 INCREMENT BY 1 CACHE 100;

-- =============================================================================
-- C. TABEL MASTER
-- =============================================================================

-- C1. Kelompok akun (1 AKTIVA, 2 KEWAJIBAN, 3 EKUITAS, 4 PENDAPATAN, 5 BEBAN) - sama dengan TKACC_GR_ACCOUNTSES SIRUS
CREATE TABLE sikeumst_akun_kelompoks (
  kelompok_id      VARCHAR2(2)    NOT NULL,
  nama             VARCHAR2(50)   NOT NULL,
  saldo_normal     VARCHAR2(1)    NOT NULL,        -- D / K
  jenis_laporan    VARCHAR2(1)    NOT NULL,        -- N neraca / L laba rugi
  urut             NUMBER(3)      DEFAULT 0 NOT NULL,
  CONSTRAINT pk_sikeumst_akun_kelompoks PRIMARY KEY (kelompok_id),
  CONSTRAINT ck_sikeu_akkel_normal CHECK (saldo_normal IN ('D','K')),
  CONSTRAINT ck_sikeu_akkel_lap    CHECK (jenis_laporan IN ('N','L'))
);

-- C2. Bagan akun SIKEU (COA). Hierarki lewat induk_id; hanya akun tipe 'D' (detail) yang boleh dijurnal.
CREATE TABLE sikeumst_akuns (
  akun_id            VARCHAR2(30)   NOT NULL,
  nama               VARCHAR2(150)  NOT NULL,
  kelompok_id        VARCHAR2(2)    NOT NULL,
  induk_id           VARCHAR2(30),
  tingkat            NUMBER(2)      DEFAULT 1 NOT NULL,
  tipe               VARCHAR2(1)    DEFAULT 'D' NOT NULL,     -- H header / D detail
  saldo_normal       VARCHAR2(1)    NOT NULL,                 -- D / K (bawaan dari kelompok, boleh beda: akun kontra)
  kas_status         VARCHAR2(1)    DEFAULT '0' NOT NULL,     -- '1' = akun kas/bank (dasar arus kas)
  kategori_arus_kas  VARCHAR2(1),                             -- O operasi / I investasi / F pendanaan (dipakai saat jadi akun lawan kas)
  kontrol_hp         VARCHAR2(1),                             -- H = akun kontrol hutang, P = piutang (wajib ber-subledger)
  pusat_biaya_wajib  VARCHAR2(1)    DEFAULT '0' NOT NULL,     -- '1' = baris jurnal wajib isi pusat biaya (beban per unit)
  aktif              VARCHAR2(1)    DEFAULT '1' NOT NULL,
  keterangan         VARCHAR2(500),
  dibuat_oleh        NUMBER(19),
  created_at         TIMESTAMP,
  updated_at         TIMESTAMP,
  CONSTRAINT pk_sikeumst_akuns PRIMARY KEY (akun_id),
  CONSTRAINT fk_sikeu_akun_kelompok FOREIGN KEY (kelompok_id) REFERENCES sikeumst_akun_kelompoks (kelompok_id),
  CONSTRAINT fk_sikeu_akun_induk    FOREIGN KEY (induk_id)    REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT ck_sikeu_akun_tipe     CHECK (tipe IN ('H','D')),
  CONSTRAINT ck_sikeu_akun_normal   CHECK (saldo_normal IN ('D','K')),
  CONSTRAINT ck_sikeu_akun_kas      CHECK (kas_status IN ('0','1')),
  CONSTRAINT ck_sikeu_akun_arus     CHECK (kategori_arus_kas IS NULL OR kategori_arus_kas IN ('O','I','F')),
  CONSTRAINT ck_sikeu_akun_hp       CHECK (kontrol_hp IS NULL OR kontrol_hp IN ('H','P')),
  CONSTRAINT ck_sikeu_akun_pb       CHECK (pusat_biaya_wajib IN ('0','1')),
  CONSTRAINT ck_sikeu_akun_aktif    CHECK (aktif IN ('0','1'))
);
CREATE INDEX idx_sikeu_akun_induk ON sikeumst_akuns (induk_id);

-- C3. Peta akun SIRUS (acmst_accounts.acc_id) -> akun SIKEU. Posting snapshot DITOLAK bila ada acc_id belum dipetakan.
CREATE TABLE sikeumst_peta_akun_sirus (
  acc_id_sirus     VARCHAR2(30)   NOT NULL,
  nama_sirus       VARCHAR2(150),
  akun_id          VARCHAR2(30)   NOT NULL,
  dibuat_oleh      NUMBER(19),
  created_at       TIMESTAMP,
  updated_at       TIMESTAMP,
  CONSTRAINT pk_sikeumst_peta_akun_sirus PRIMARY KEY (acc_id_sirus),
  CONSTRAINT fk_sikeu_peta_akun FOREIGN KEY (akun_id) REFERENCES sikeumst_akuns (akun_id)
);
CREATE INDEX idx_sikeu_peta_akun ON sikeumst_peta_akun_sirus (akun_id);

-- C4. Pusat biaya (unit/poli/ruang) - laba rugi per unit, lokasi aset
CREATE TABLE sikeumst_pusat_biayas (
  pusat_biaya_id   NUMBER(10)     NOT NULL,
  kode             VARCHAR2(20)   NOT NULL,
  nama             VARCHAR2(100)  NOT NULL,
  jenis            VARCHAR2(1)    DEFAULT 'P' NOT NULL,   -- P pendapatan (produksi) / B penunjang (biaya)
  kode_sirus       VARCHAR2(20),                          -- poli_id / room_id / dll di SIRUS (opsional)
  aktif            VARCHAR2(1)    DEFAULT '1' NOT NULL,
  created_at       TIMESTAMP,
  updated_at       TIMESTAMP,
  CONSTRAINT pk_sikeumst_pusat_biayas PRIMARY KEY (pusat_biaya_id),
  CONSTRAINT uk_sikeu_pusat_biaya_kode UNIQUE (kode),
  CONSTRAINT ck_sikeu_pb_jenis CHECK (jenis IN ('P','B')),
  CONSTRAINT ck_sikeu_pb_aktif CHECK (aktif IN ('0','1'))
);

-- C5. Tahun buku - tutup buku tahunan memindahkan laba/rugi ke akun laba ditahan
CREATE TABLE sikeumst_tahun_bukus (
  tahun                NUMBER(4)      NOT NULL,
  status               VARCHAR2(1)    DEFAULT 'B' NOT NULL,   -- B buka / T tutup
  akun_laba_ditahan_id VARCHAR2(30),
  akun_laba_berjalan_id VARCHAR2(30),
  jurnal_penutup_id    NUMBER(19),
  ditutup_oleh         NUMBER(19),
  ditutup_at           TIMESTAMP,
  created_at           TIMESTAMP,
  updated_at           TIMESTAMP,
  CONSTRAINT pk_sikeumst_tahun_bukus PRIMARY KEY (tahun),
  CONSTRAINT fk_sikeu_thn_ditahan  FOREIGN KEY (akun_laba_ditahan_id)  REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT fk_sikeu_thn_berjalan FOREIGN KEY (akun_laba_berjalan_id) REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT ck_sikeu_thn_status CHECK (status IN ('B','T'))
);

-- C6. Periode bulanan - B buka / K kunci (disahkan) / T tutup (tahun sudah tutup buku)
CREATE TABLE sikeumst_periodes (
  periode          VARCHAR2(6)    NOT NULL,        -- YYYYMM
  tahun            NUMBER(4)      NOT NULL,
  bulan            NUMBER(2)      NOT NULL,
  status           VARCHAR2(1)    DEFAULT 'B' NOT NULL,
  snap_id_sah      NUMBER(19),                     -- versi snapshot SIRUS yang disahkan untuk periode ini
  dikunci_oleh     NUMBER(19),
  dikunci_at       TIMESTAMP,
  dibuka_oleh      NUMBER(19),                     -- buka kunci terakhir (wajib alasan)
  dibuka_at        TIMESTAMP,
  alasan_buka      VARCHAR2(500),
  created_at       TIMESTAMP,
  updated_at       TIMESTAMP,
  CONSTRAINT pk_sikeumst_periodes PRIMARY KEY (periode),
  CONSTRAINT fk_sikeu_periode_tahun FOREIGN KEY (tahun) REFERENCES sikeumst_tahun_bukus (tahun),
  CONSTRAINT ck_sikeu_periode_status CHECK (status IN ('B','K','T')),
  CONSTRAINT ck_sikeu_periode_bulan  CHECK (bulan BETWEEN 1 AND 12)
);

-- C7. Template laporan (Neraca, Laba Rugi, Arus Kas). Satu header = satu format laporan.
CREATE TABLE sikeumst_lap_hdrs (
  lap_id           NUMBER(10)     NOT NULL,
  jenis            VARCHAR2(10)   NOT NULL,        -- NERACA / LABARUGI / ARUSKAS
  nama             VARCHAR2(100)  NOT NULL,
  metode           VARCHAR2(1),                    -- arus kas: L langsung / T tidak langsung
  aktif            VARCHAR2(1)    DEFAULT '1' NOT NULL,
  created_at       TIMESTAMP,
  updated_at       TIMESTAMP,
  CONSTRAINT pk_sikeumst_lap_hdrs PRIMARY KEY (lap_id),
  CONSTRAINT ck_sikeu_lap_jenis  CHECK (jenis IN ('NERACA','LABARUGI','ARUSKAS')),
  CONSTRAINT ck_sikeu_lap_metode CHECK (metode IS NULL OR metode IN ('L','T')),
  CONSTRAINT ck_sikeu_lap_aktif  CHECK (aktif IN ('0','1'))
);

-- C8. Baris template: J judul / A jumlah akun (lihat lap_akuns) / R rumus antar-baris (mis. "[10]-[20]")
CREATE TABLE sikeumst_lap_dtls (
  baris_id         NUMBER(10)     NOT NULL,
  lap_id           NUMBER(10)     NOT NULL,
  kode             VARCHAR2(10)   NOT NULL,        -- dirujuk rumus: [kode]
  urut             NUMBER(5)      NOT NULL,
  label            VARCHAR2(200)  NOT NULL,
  tipe             VARCHAR2(1)    NOT NULL,
  rumus            VARCHAR2(500),
  tanda            NUMBER(1)      DEFAULT 1 NOT NULL,   -- 1 / -1 (dibalik saat tampil)
  tebal            VARCHAR2(1)    DEFAULT '0' NOT NULL,
  induk_baris_id   NUMBER(10),
  CONSTRAINT pk_sikeumst_lap_dtls PRIMARY KEY (baris_id),
  CONSTRAINT fk_sikeu_lapdtl_hdr   FOREIGN KEY (lap_id) REFERENCES sikeumst_lap_hdrs (lap_id) ON DELETE CASCADE,
  CONSTRAINT fk_sikeu_lapdtl_induk FOREIGN KEY (induk_baris_id) REFERENCES sikeumst_lap_dtls (baris_id),
  CONSTRAINT uk_sikeu_lapdtl_kode  UNIQUE (lap_id, kode),
  CONSTRAINT ck_sikeu_lapdtl_tipe  CHECK (tipe IN ('J','A','R')),
  CONSTRAINT ck_sikeu_lapdtl_tanda CHECK (tanda IN (1,-1)),
  CONSTRAINT ck_sikeu_lapdtl_tebal CHECK (tebal IN ('0','1'))
);

-- C9. Akun yang dijumlah di satu baris template (tipe A). termasuk_turunan '1' = ikut semua akun di bawahnya.
--     sisi: S saldo akhir (neraca) / M mutasi bersih (laba rugi, arus kas) / D mutasi debit / K mutasi kredit
CREATE TABLE sikeumst_lap_akuns (
  baris_id          NUMBER(10)    NOT NULL,
  akun_id           VARCHAR2(30)  NOT NULL,
  termasuk_turunan  VARCHAR2(1)   DEFAULT '1' NOT NULL,
  sisi              VARCHAR2(1)   DEFAULT 'S' NOT NULL,
  CONSTRAINT pk_sikeumst_lap_akuns PRIMARY KEY (baris_id, akun_id),
  CONSTRAINT fk_sikeu_lapakun_baris FOREIGN KEY (baris_id) REFERENCES sikeumst_lap_dtls (baris_id) ON DELETE CASCADE,
  CONSTRAINT fk_sikeu_lapakun_akun  FOREIGN KEY (akun_id)  REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT ck_sikeu_lapakun_turun CHECK (termasuk_turunan IN ('0','1')),
  CONSTRAINT ck_sikeu_lapakun_sisi  CHECK (sisi IN ('S','M','D','K'))
);

-- C10. Kategori aset tetap + metode & umur penyusutan + akun jurnalnya
CREATE TABLE sikeumst_aset_kategoris (
  kategori_id          NUMBER(10)     NOT NULL,
  kode                 VARCHAR2(20)   NOT NULL,
  nama                 VARCHAR2(100)  NOT NULL,
  metode               VARCHAR2(2)    DEFAULT 'GL' NOT NULL,   -- GL garis lurus / SM saldo menurun / TS tidak disusutkan (tanah)
  umur_bulan           NUMBER(4)      DEFAULT 0 NOT NULL,
  tarif_persen         NUMBER(7,4)    DEFAULT 0 NOT NULL,      -- per tahun (dipakai SM)
  residu_persen        NUMBER(7,4)    DEFAULT 0 NOT NULL,
  akun_aset_id         VARCHAR2(30),
  akun_akumulasi_id    VARCHAR2(30),
  akun_beban_id        VARCHAR2(30),
  akun_laba_jual_id    VARCHAR2(30),
  akun_rugi_jual_id    VARCHAR2(30),
  aktif                VARCHAR2(1)    DEFAULT '1' NOT NULL,
  created_at           TIMESTAMP,
  updated_at           TIMESTAMP,
  CONSTRAINT pk_sikeumst_aset_kategoris PRIMARY KEY (kategori_id),
  CONSTRAINT uk_sikeu_asetkat_kode UNIQUE (kode),
  CONSTRAINT fk_sikeu_asetkat_aset  FOREIGN KEY (akun_aset_id)      REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT fk_sikeu_asetkat_akum  FOREIGN KEY (akun_akumulasi_id) REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT fk_sikeu_asetkat_beban FOREIGN KEY (akun_beban_id)     REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT fk_sikeu_asetkat_laba  FOREIGN KEY (akun_laba_jual_id) REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT fk_sikeu_asetkat_rugi  FOREIGN KEY (akun_rugi_jual_id) REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT ck_sikeu_asetkat_metode CHECK (metode IN ('GL','SM','TS')),
  CONSTRAINT ck_sikeu_asetkat_aktif  CHECK (aktif IN ('0','1'))
);

-- C11. Register aset tetap. Metode/umur boleh beda dari kategori (diisi saat perolehan, lalu beku).
CREATE TABLE sikeumst_asets (
  aset_id              NUMBER(10)     NOT NULL,
  kode_aset            VARCHAR2(30)   NOT NULL,
  nama                 VARCHAR2(200)  NOT NULL,
  kategori_id          NUMBER(10)     NOT NULL,
  pusat_biaya_id       NUMBER(10),                          -- lokasi / unit pemakai (beban susut ikut unit ini)
  merek_tipe           VARCHAR2(150),
  nomor_seri           VARCHAR2(100),
  tanggal_perolehan    DATE           NOT NULL,
  tanggal_mulai_susut  DATE           NOT NULL,
  harga_perolehan      NUMBER(18,2)   NOT NULL,
  nilai_residu         NUMBER(18,2)   DEFAULT 0 NOT NULL,
  metode               VARCHAR2(2)    NOT NULL,
  umur_bulan           NUMBER(4)      NOT NULL,
  tarif_persen         NUMBER(7,4)    DEFAULT 0 NOT NULL,
  akumulasi_awal       NUMBER(18,2)   DEFAULT 0 NOT NULL,   -- aset lama saat go-live: akumulasi s/d saldo awal
  status               VARCHAR2(1)    DEFAULT 'A' NOT NULL, -- A aktif / J dijual / H dihapus (afkir) / S selesai disusutkan
  sumber               VARCHAR2(10)   DEFAULT 'MANUAL' NOT NULL,  -- MANUAL / SIRUS (penerimaan non-medis) / SALDO_AWAL
  referensi_sirus      VARCHAR2(50),                        -- mis. rcv_no penerimaan barang non-medis
  kode_siap            VARCHAR2(30),                        -- tautan opsional ke inventaris alat SIAP
  jurnal_perolehan_id  NUMBER(19),
  keterangan           VARCHAR2(500),
  dibuat_oleh          NUMBER(19),
  created_at           TIMESTAMP,
  updated_at           TIMESTAMP,
  CONSTRAINT pk_sikeumst_asets PRIMARY KEY (aset_id),
  CONSTRAINT uk_sikeu_aset_kode UNIQUE (kode_aset),
  CONSTRAINT fk_sikeu_aset_kategori FOREIGN KEY (kategori_id)    REFERENCES sikeumst_aset_kategoris (kategori_id),
  CONSTRAINT fk_sikeu_aset_pb       FOREIGN KEY (pusat_biaya_id) REFERENCES sikeumst_pusat_biayas (pusat_biaya_id),
  CONSTRAINT ck_sikeu_aset_metode CHECK (metode IN ('GL','SM','TS')),
  CONSTRAINT ck_sikeu_aset_status CHECK (status IN ('A','J','H','S')),
  CONSTRAINT ck_sikeu_aset_nilai  CHECK (harga_perolehan >= 0 AND nilai_residu >= 0 AND nilai_residu <= harga_perolehan),
  CONSTRAINT ck_sikeu_aset_sumber CHECK (sumber IN ('MANUAL','SIRUS','SALDO_AWAL'))
);
CREATE INDEX idx_sikeu_aset_kategori ON sikeumst_asets (kategori_id, status);

-- C12. Mitra hutang/piutang: supplier, penjamin (BPJS/asuransi), pasien, karyawan, lain
CREATE TABLE sikeumst_mitras (
  mitra_id         NUMBER(10)     NOT NULL,
  jenis            VARCHAR2(10)   NOT NULL,        -- SUPPLIER / PENJAMIN / PASIEN / KARYAWAN / LAIN
  kode_sirus       VARCHAR2(30),                   -- supp_id / kode penjamin / reg_no / emp_id di SIRUS
  nama             VARCHAR2(150)  NOT NULL,
  akun_hutang_id   VARCHAR2(30),
  akun_piutang_id  VARCHAR2(30),
  termin_hari      NUMBER(4)      DEFAULT 0 NOT NULL,   -- jatuh tempo bawaan
  npwp             VARCHAR2(30),
  aktif            VARCHAR2(1)    DEFAULT '1' NOT NULL,
  created_at       TIMESTAMP,
  updated_at       TIMESTAMP,
  CONSTRAINT pk_sikeumst_mitras PRIMARY KEY (mitra_id),
  CONSTRAINT uk_sikeu_mitra_sirus UNIQUE (jenis, kode_sirus),
  CONSTRAINT fk_sikeu_mitra_hutang  FOREIGN KEY (akun_hutang_id)  REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT fk_sikeu_mitra_piutang FOREIGN KEY (akun_piutang_id) REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT ck_sikeu_mitra_jenis CHECK (jenis IN ('SUPPLIER','PENJAMIN','PASIEN','KARYAWAN','LAIN')),
  CONSTRAINT ck_sikeu_mitra_aktif CHECK (aktif IN ('0','1'))
);

-- =============================================================================
-- D. TABEL TRANSAKSI
-- =============================================================================

-- D1. Snapshot jurnal SIRUS (cutoff berversi) - App\Support\SnapshotJurnal. Versi S tidak pernah diubah.
CREATE TABLE sikeutxn_snap_hdrs (
  snap_id          NUMBER(19)     NOT NULL,
  periode          VARCHAR2(6)    NOT NULL,
  versi            NUMBER(10)     NOT NULL,
  status           VARCHAR2(1)    DEFAULT 'P' NOT NULL,   -- P proses / S selesai / G gagal
  jumlah_cabang    NUMBER(10)     DEFAULT 0 NOT NULL,
  cabang_selesai   NUMBER(10)     DEFAULT 0 NOT NULL,
  jumlah_baris     NUMBER(10)     DEFAULT 0 NOT NULL,
  total_debit      NUMBER(18,2)   DEFAULT 0 NOT NULL,
  total_kredit     NUMBER(18,2)   DEFAULT 0 NOT NULL,
  checksum         VARCHAR2(40),
  keterangan       VARCHAR2(1000),
  diambil_oleh     NUMBER(19),
  mulai_at         TIMESTAMP,
  selesai_at       TIMESTAMP,
  created_at       TIMESTAMP,
  updated_at       TIMESTAMP,
  CONSTRAINT pk_sikeutxn_snap_hdrs PRIMARY KEY (snap_id),
  CONSTRAINT uk_sikeutxn_snap_hdrs UNIQUE (periode, versi),
  CONSTRAINT ck_sikeu_snap_status CHECK (status IN ('P','S','G'))
);

CREATE TABLE sikeutxn_snap_cabangs (
  snap_id          NUMBER(19)     NOT NULL,
  cabang_kunci     VARCHAR2(12)   NOT NULL,
  cabang_no        NUMBER(5)      NOT NULL,
  sumber           VARCHAR2(10),
  label_cabang     VARCHAR2(1000),
  status           VARCHAR2(1)    DEFAULT 'B' NOT NULL,   -- B belum / S selesai
  jumlah_baris     NUMBER(10)     DEFAULT 0 NOT NULL,
  total_debit      NUMBER(18,2)   DEFAULT 0 NOT NULL,
  total_kredit     NUMBER(18,2)   DEFAULT 0 NOT NULL,
  checksum         VARCHAR2(40),
  CONSTRAINT pk_sikeutxn_snap_cabangs PRIMARY KEY (snap_id, cabang_kunci),
  CONSTRAINT fk_sikeu_snapcab_hdr FOREIGN KEY (snap_id) REFERENCES sikeutxn_snap_hdrs (snap_id) ON DELETE CASCADE,
  CONSTRAINT ck_sikeu_snapcab_status CHECK (status IN ('B','S'))
);

CREATE TABLE sikeutxn_snap_dtls (
  snap_id          NUMBER(19)     NOT NULL,
  cabang_kunci     VARCHAR2(12)   NOT NULL,
  kunci_baris      VARCHAR2(40)   NOT NULL,        -- sha1(cabang|label|akun|lawan|tanggal|shift), TANPA nominal
  txn_name         VARCHAR2(500),
  txn_acc          VARCHAR2(30),
  txn_acc_k        VARCHAR2(30),
  shift            VARCHAR2(5),
  txn_date         DATE,
  txn_d            NUMBER(18,2)   DEFAULT 0 NOT NULL,
  txn_k            NUMBER(18,2)   DEFAULT 0 NOT NULL,
  CONSTRAINT fk_sikeu_snapdtl_hdr FOREIGN KEY (snap_id) REFERENCES sikeutxn_snap_hdrs (snap_id) ON DELETE CASCADE
);
CREATE INDEX idx_sikeutxn_snap_dtls_cab   ON sikeutxn_snap_dtls (snap_id, cabang_kunci);
CREATE INDEX idx_sikeutxn_snap_dtls_kunci ON sikeutxn_snap_dtls (snap_id, kunci_baris);
CREATE INDEX idx_sikeutxn_snap_dtls_acc   ON sikeutxn_snap_dtls (snap_id, txn_acc, txn_date);

-- D2. JURNAL - header. no_jurnal: <SUMBER>-<YYYYMM>-<urut>, mis. JU-202610-0001
CREATE TABLE sikeutxn_jurnal_hdrs (
  jurnal_id        NUMBER(19)     NOT NULL,
  no_jurnal        VARCHAR2(30)   NOT NULL,
  periode          VARCHAR2(6)    NOT NULL,
  tanggal          DATE           NOT NULL,
  sumber           VARCHAR2(10)   NOT NULL,
  referensi_tabel  VARCHAR2(30),                   -- tabel asal (sikeutxn_snap_hdrs, sikeumst_asets, ...)
  referensi_id     VARCHAR2(50),                   -- id baris asal (snap_id, aset_id, kartu_id, ...)
  keterangan       VARCHAR2(500)  NOT NULL,
  total_debit      NUMBER(18,2)   DEFAULT 0 NOT NULL,
  total_kredit     NUMBER(18,2)   DEFAULT 0 NOT NULL,
  status           VARCHAR2(1)    DEFAULT 'D' NOT NULL,   -- D draft / P posting / B batal
  jurnal_pembalik_id NUMBER(19),                   -- jurnal yang membatalkan / dibatalkan oleh jurnal ini
  dibuat_oleh      NUMBER(19),
  diposting_oleh   NUMBER(19),
  diposting_at     TIMESTAMP,
  dibatalkan_oleh  NUMBER(19),
  dibatalkan_at    TIMESTAMP,
  alasan_batal     VARCHAR2(500),
  created_at       TIMESTAMP,
  updated_at       TIMESTAMP,
  CONSTRAINT pk_sikeutxn_jurnal_hdrs PRIMARY KEY (jurnal_id),
  CONSTRAINT uk_sikeu_jurnal_no UNIQUE (no_jurnal),
  CONSTRAINT fk_sikeu_jurnal_periode  FOREIGN KEY (periode) REFERENCES sikeumst_periodes (periode),
  CONSTRAINT fk_sikeu_jurnal_pembalik FOREIGN KEY (jurnal_pembalik_id) REFERENCES sikeutxn_jurnal_hdrs (jurnal_id),
  CONSTRAINT ck_sikeu_jurnal_status CHECK (status IN ('D','P','B')),
  CONSTRAINT ck_sikeu_jurnal_sumber CHECK (sumber IN ('SIRUS','MANUAL','SUSUT','ASET','HPP','HUTANG','PIUTANG','SALDO_AWAL','PENUTUP')),
  -- jurnal terposting wajib seimbang
  CONSTRAINT ck_sikeu_jurnal_seimbang CHECK (status <> 'P' OR total_debit = total_kredit)
);
CREATE INDEX idx_sikeu_jurnal_periode ON sikeutxn_jurnal_hdrs (periode, status);
CREATE INDEX idx_sikeu_jurnal_ref     ON sikeutxn_jurnal_hdrs (referensi_tabel, referensi_id);
CREATE INDEX idx_sikeu_jurnal_tanggal ON sikeutxn_jurnal_hdrs (tanggal);

-- D3. JURNAL - detail (baris debit/kredit). Satu baris = satu sisi; tidak boleh debit & kredit sekaligus.
CREATE TABLE sikeutxn_jurnal_dtls (
  jurnal_dtl_id    NUMBER(19)     NOT NULL,
  jurnal_id        NUMBER(19)     NOT NULL,
  urut             NUMBER(6)      NOT NULL,
  akun_id          VARCHAR2(30)   NOT NULL,
  akun_lawan_id    VARCHAR2(30),                   -- dari SIRUS (txn_acc_k) - dasar arus kas metode langsung
  debit            NUMBER(18,2)   DEFAULT 0 NOT NULL,
  kredit           NUMBER(18,2)   DEFAULT 0 NOT NULL,
  pusat_biaya_id   NUMBER(10),
  mitra_id         NUMBER(10),                     -- subledger hutang/piutang
  kartu_id         NUMBER(19),                     -- dokumen hutang/piutang yang disentuh baris ini
  cabang_kunci     VARCHAR2(12),                   -- asal cabang jurnal SIRUS (telusur ke sikeutxn_snap_dtls)
  keterangan       VARCHAR2(500),
  CONSTRAINT pk_sikeutxn_jurnal_dtls PRIMARY KEY (jurnal_dtl_id),
  CONSTRAINT fk_sikeu_jdtl_hdr   FOREIGN KEY (jurnal_id)      REFERENCES sikeutxn_jurnal_hdrs (jurnal_id),
  CONSTRAINT fk_sikeu_jdtl_akun  FOREIGN KEY (akun_id)        REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT fk_sikeu_jdtl_lawan FOREIGN KEY (akun_lawan_id)  REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT fk_sikeu_jdtl_pb    FOREIGN KEY (pusat_biaya_id) REFERENCES sikeumst_pusat_biayas (pusat_biaya_id),
  CONSTRAINT fk_sikeu_jdtl_mitra FOREIGN KEY (mitra_id)       REFERENCES sikeumst_mitras (mitra_id),
  CONSTRAINT ck_sikeu_jdtl_nilai CHECK (debit >= 0 AND kredit >= 0 AND NOT (debit > 0 AND kredit > 0))
);
CREATE INDEX idx_sikeu_jdtl_jurnal ON sikeutxn_jurnal_dtls (jurnal_id);
CREATE INDEX idx_sikeu_jdtl_akun   ON sikeutxn_jurnal_dtls (akun_id, jurnal_id);
CREATE INDEX idx_sikeu_jdtl_mitra  ON sikeutxn_jurnal_dtls (mitra_id);
CREATE INDEX idx_sikeu_jdtl_pb     ON sikeutxn_jurnal_dtls (pusat_biaya_id);

-- D4. Saldo akun per periode (hasil hitung dari jurnal posting; dihitung ulang saat periode dikunci).
--     Neraca/Laba Rugi/Arus Kas membaca tabel ini, bukan menjumlah jurnal tiap kali.
CREATE TABLE sikeutxn_saldo_akuns (
  periode          VARCHAR2(6)    NOT NULL,
  akun_id          VARCHAR2(30)   NOT NULL,
  awal_debit       NUMBER(18,2)   DEFAULT 0 NOT NULL,
  awal_kredit      NUMBER(18,2)   DEFAULT 0 NOT NULL,
  mutasi_debit     NUMBER(18,2)   DEFAULT 0 NOT NULL,
  mutasi_kredit    NUMBER(18,2)   DEFAULT 0 NOT NULL,
  akhir_debit      NUMBER(18,2)   DEFAULT 0 NOT NULL,
  akhir_kredit     NUMBER(18,2)   DEFAULT 0 NOT NULL,
  dihitung_at      TIMESTAMP,
  CONSTRAINT pk_sikeutxn_saldo_akuns PRIMARY KEY (periode, akun_id),
  CONSTRAINT fk_sikeu_saldo_periode FOREIGN KEY (periode) REFERENCES sikeumst_periodes (periode),
  CONSTRAINT fk_sikeu_saldo_akun    FOREIGN KEY (akun_id) REFERENCES sikeumst_akuns (akun_id)
);

-- D5. Penyusutan aset per periode (satu baris per aset per bulan) -> dijurnal SUSUT per periode
CREATE TABLE sikeutxn_aset_susuts (
  aset_id          NUMBER(10)     NOT NULL,
  periode          VARCHAR2(6)    NOT NULL,
  nilai_susut      NUMBER(18,2)   NOT NULL,
  akumulasi        NUMBER(18,2)   NOT NULL,        -- akumulasi s/d akhir periode ini
  nilai_buku       NUMBER(18,2)   NOT NULL,
  jurnal_id        NUMBER(19),
  dihitung_oleh    NUMBER(19),
  created_at       TIMESTAMP,
  CONSTRAINT pk_sikeutxn_aset_susuts PRIMARY KEY (aset_id, periode),
  CONSTRAINT fk_sikeu_susut_aset    FOREIGN KEY (aset_id)   REFERENCES sikeumst_asets (aset_id),
  CONSTRAINT fk_sikeu_susut_periode FOREIGN KEY (periode)   REFERENCES sikeumst_periodes (periode),
  CONSTRAINT fk_sikeu_susut_jurnal  FOREIGN KEY (jurnal_id) REFERENCES sikeutxn_jurnal_hdrs (jurnal_id),
  CONSTRAINT ck_sikeu_susut_nilai CHECK (nilai_susut >= 0 AND nilai_buku >= 0)
);
CREATE INDEX idx_sikeu_susut_periode ON sikeutxn_aset_susuts (periode);

-- D6. Mutasi aset: PEROLEHAN / TAMBAH (kapitalisasi) / PINDAH (lokasi) / JUAL / HAPUS (afkir) / REVALUASI
CREATE TABLE sikeutxn_aset_mutasis (
  mutasi_id            NUMBER(19)     NOT NULL,
  aset_id              NUMBER(10)     NOT NULL,
  tanggal              DATE           NOT NULL,
  jenis                VARCHAR2(10)   NOT NULL,
  nilai                NUMBER(18,2)   DEFAULT 0 NOT NULL,   -- perolehan / tambahan / nilai revaluasi
  nilai_jual           NUMBER(18,2)   DEFAULT 0 NOT NULL,
  nilai_buku_saat_ini  NUMBER(18,2)   DEFAULT 0 NOT NULL,
  laba_rugi            NUMBER(18,2)   DEFAULT 0 NOT NULL,   -- nilai_jual - nilai buku
  pusat_biaya_lama_id  NUMBER(10),
  pusat_biaya_baru_id  NUMBER(10),
  akun_kas_id          VARCHAR2(30),                        -- kas/bank penerima hasil jual
  keterangan           VARCHAR2(500),
  jurnal_id            NUMBER(19),
  dibuat_oleh          NUMBER(19),
  created_at           TIMESTAMP,
  CONSTRAINT pk_sikeutxn_aset_mutasis PRIMARY KEY (mutasi_id),
  CONSTRAINT fk_sikeu_asetmut_aset   FOREIGN KEY (aset_id)     REFERENCES sikeumst_asets (aset_id),
  CONSTRAINT fk_sikeu_asetmut_kas    FOREIGN KEY (akun_kas_id) REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT fk_sikeu_asetmut_jurnal FOREIGN KEY (jurnal_id)   REFERENCES sikeutxn_jurnal_hdrs (jurnal_id),
  CONSTRAINT ck_sikeu_asetmut_jenis CHECK (jenis IN ('PEROLEHAN','TAMBAH','PINDAH','JUAL','HAPUS','REVALUASI'))
);
CREATE INDEX idx_sikeu_asetmut_aset ON sikeutxn_aset_mutasis (aset_id, tanggal);

-- D7. HPP per periode per lokasi persediaan (APT apotek, WHS gudang medis, WHSNON gudang non-medis).
--     HPP = persediaan awal + pembelian - retur beli +/- penyesuaian - persediaan akhir (dari stok SIRUS)
CREATE TABLE sikeutxn_hpp_hdrs (
  hpp_id               NUMBER(19)     NOT NULL,
  periode              VARCHAR2(6)    NOT NULL,
  lokasi               VARCHAR2(10)   NOT NULL,
  metode               VARCHAR2(1)    DEFAULT 'R' NOT NULL,   -- R rata-rata tertimbang / P periodik (opname)
  persediaan_awal      NUMBER(18,2)   DEFAULT 0 NOT NULL,
  pembelian            NUMBER(18,2)   DEFAULT 0 NOT NULL,
  retur_beli           NUMBER(18,2)   DEFAULT 0 NOT NULL,
  transfer_masuk       NUMBER(18,2)   DEFAULT 0 NOT NULL,
  transfer_keluar      NUMBER(18,2)   DEFAULT 0 NOT NULL,
  penyesuaian          NUMBER(18,2)   DEFAULT 0 NOT NULL,     -- selisih opname, rusak, kedaluwarsa
  persediaan_akhir     NUMBER(18,2)   DEFAULT 0 NOT NULL,
  hpp                  NUMBER(18,2)   DEFAULT 0 NOT NULL,
  akun_persediaan_id   VARCHAR2(30),
  akun_hpp_id          VARCHAR2(30),
  status               VARCHAR2(1)    DEFAULT 'D' NOT NULL,   -- D draft / P posting
  jurnal_id            NUMBER(19),
  dihitung_oleh        NUMBER(19),
  created_at           TIMESTAMP,
  updated_at           TIMESTAMP,
  CONSTRAINT pk_sikeutxn_hpp_hdrs PRIMARY KEY (hpp_id),
  CONSTRAINT uk_sikeu_hpp_periode UNIQUE (periode, lokasi),
  CONSTRAINT fk_sikeu_hpp_periode FOREIGN KEY (periode)            REFERENCES sikeumst_periodes (periode),
  CONSTRAINT fk_sikeu_hpp_persed  FOREIGN KEY (akun_persediaan_id) REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT fk_sikeu_hpp_akun    FOREIGN KEY (akun_hpp_id)        REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT fk_sikeu_hpp_jurnal  FOREIGN KEY (jurnal_id)          REFERENCES sikeutxn_jurnal_hdrs (jurnal_id),
  CONSTRAINT ck_sikeu_hpp_lokasi CHECK (lokasi IN ('APT','WHS','WHSNON')),
  CONSTRAINT ck_sikeu_hpp_metode CHECK (metode IN ('R','P')),
  CONSTRAINT ck_sikeu_hpp_status CHECK (status IN ('D','P'))
);

-- D8. HPP rinci per barang (product_id SIRUS) - kartu persediaan nilai satu periode
CREATE TABLE sikeutxn_hpp_dtls (
  hpp_id           NUMBER(19)     NOT NULL,
  product_id       VARCHAR2(30)   NOT NULL,
  nama_barang      VARCHAR2(200),
  qty_awal         NUMBER(18,4)   DEFAULT 0 NOT NULL,
  nilai_awal       NUMBER(18,2)   DEFAULT 0 NOT NULL,
  qty_masuk        NUMBER(18,4)   DEFAULT 0 NOT NULL,
  nilai_masuk      NUMBER(18,2)   DEFAULT 0 NOT NULL,
  qty_keluar       NUMBER(18,4)   DEFAULT 0 NOT NULL,
  nilai_keluar     NUMBER(18,2)   DEFAULT 0 NOT NULL,   -- = HPP barang ini
  qty_akhir        NUMBER(18,4)   DEFAULT 0 NOT NULL,
  nilai_akhir      NUMBER(18,2)   DEFAULT 0 NOT NULL,
  harga_rata       NUMBER(18,4)   DEFAULT 0 NOT NULL,
  CONSTRAINT pk_sikeutxn_hpp_dtls PRIMARY KEY (hpp_id, product_id),
  CONSTRAINT fk_sikeu_hppdtl_hdr FOREIGN KEY (hpp_id) REFERENCES sikeutxn_hpp_hdrs (hpp_id) ON DELETE CASCADE
);

-- D9. Kartu hutang/piutang (subledger per dokumen). Sisa = nilai - sum(bayar). Umur dari jatuh_tempo.
CREATE TABLE sikeutxn_hp_kartus (
  kartu_id         NUMBER(19)     NOT NULL,
  jenis            VARCHAR2(1)    NOT NULL,        -- H hutang / P piutang
  mitra_id         NUMBER(10)     NOT NULL,
  nomor_dokumen    VARCHAR2(50)   NOT NULL,        -- no faktur / rcv_no / no klaim / no tagihan
  tanggal          DATE           NOT NULL,
  jatuh_tempo      DATE,
  akun_id          VARCHAR2(30)   NOT NULL,        -- akun kontrol hutang/piutang
  nilai            NUMBER(18,2)   NOT NULL,
  terbayar         NUMBER(18,2)   DEFAULT 0 NOT NULL,   -- cache sum bayar (dihitung ulang saat bayar)
  status           VARCHAR2(1)    DEFAULT 'O' NOT NULL, -- O terbuka / L lunas / B batal / H dihapusbukukan
  sumber           VARCHAR2(10)   DEFAULT 'MANUAL' NOT NULL,   -- MANUAL / SIRUS / SALDO_AWAL
  referensi_sirus  VARCHAR2(50),
  keterangan       VARCHAR2(500),
  jurnal_id        NUMBER(19),
  dibuat_oleh      NUMBER(19),
  created_at       TIMESTAMP,
  updated_at       TIMESTAMP,
  CONSTRAINT pk_sikeutxn_hp_kartus PRIMARY KEY (kartu_id),
  CONSTRAINT uk_sikeu_hpkartu_dok UNIQUE (jenis, mitra_id, nomor_dokumen),
  CONSTRAINT fk_sikeu_hpkartu_mitra  FOREIGN KEY (mitra_id)  REFERENCES sikeumst_mitras (mitra_id),
  CONSTRAINT fk_sikeu_hpkartu_akun   FOREIGN KEY (akun_id)   REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT fk_sikeu_hpkartu_jurnal FOREIGN KEY (jurnal_id) REFERENCES sikeutxn_jurnal_hdrs (jurnal_id),
  CONSTRAINT ck_sikeu_hpkartu_jenis  CHECK (jenis IN ('H','P')),
  CONSTRAINT ck_sikeu_hpkartu_status CHECK (status IN ('O','L','B','H')),
  CONSTRAINT ck_sikeu_hpkartu_sumber CHECK (sumber IN ('MANUAL','SIRUS','SALDO_AWAL')),
  CONSTRAINT ck_sikeu_hpkartu_nilai  CHECK (nilai >= 0 AND terbayar >= 0 AND terbayar <= nilai)
);
CREATE INDEX idx_sikeu_hpkartu_mitra ON sikeutxn_hp_kartus (mitra_id, status);
CREATE INDEX idx_sikeu_hpkartu_tempo ON sikeutxn_hp_kartus (jenis, status, jatuh_tempo);

ALTER TABLE sikeutxn_jurnal_dtls ADD CONSTRAINT fk_sikeu_jdtl_kartu FOREIGN KEY (kartu_id) REFERENCES sikeutxn_hp_kartus (kartu_id);

-- D10. Pembayaran / pelunasan hutang-piutang (bisa sebagian, bisa potongan)
CREATE TABLE sikeutxn_hp_bayars (
  bayar_id         NUMBER(19)     NOT NULL,
  kartu_id         NUMBER(19)     NOT NULL,
  tanggal          DATE           NOT NULL,
  nilai            NUMBER(18,2)   NOT NULL,
  potongan         NUMBER(18,2)   DEFAULT 0 NOT NULL,   -- diskon / selisih klaim (mis. BPJS) yang dihapus
  akun_kas_id      VARCHAR2(30),
  akun_potongan_id VARCHAR2(30),
  referensi        VARCHAR2(50),                        -- no bukti kas / transfer
  sumber           VARCHAR2(10)   DEFAULT 'MANUAL' NOT NULL,
  referensi_sirus  VARCHAR2(50),
  status           VARCHAR2(1)    DEFAULT 'A' NOT NULL, -- A aktif / B batal
  jurnal_id        NUMBER(19),
  dibuat_oleh      NUMBER(19),
  created_at       TIMESTAMP,
  CONSTRAINT pk_sikeutxn_hp_bayars PRIMARY KEY (bayar_id),
  CONSTRAINT fk_sikeu_hpbayar_kartu  FOREIGN KEY (kartu_id)         REFERENCES sikeutxn_hp_kartus (kartu_id),
  CONSTRAINT fk_sikeu_hpbayar_kas    FOREIGN KEY (akun_kas_id)      REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT fk_sikeu_hpbayar_pot    FOREIGN KEY (akun_potongan_id) REFERENCES sikeumst_akuns (akun_id),
  CONSTRAINT fk_sikeu_hpbayar_jurnal FOREIGN KEY (jurnal_id)        REFERENCES sikeutxn_jurnal_hdrs (jurnal_id),
  CONSTRAINT ck_sikeu_hpbayar_nilai  CHECK (nilai >= 0 AND potongan >= 0),
  CONSTRAINT ck_sikeu_hpbayar_status CHECK (status IN ('A','B')),
  CONSTRAINT ck_sikeu_hpbayar_sumber CHECK (sumber IN ('MANUAL','SIRUS'))
);
CREATE INDEX idx_sikeu_hpbayar_kartu ON sikeutxn_hp_bayars (kartu_id);

-- D11. Jejak audit aksi penting (posting, batal, kunci/buka periode, ubah peta akun, tutup buku)
CREATE TABLE sikeutxn_audit_logs (
  log_id           NUMBER(19)     NOT NULL,
  waktu            TIMESTAMP      DEFAULT SYSTIMESTAMP NOT NULL,
  user_id          NUMBER(19),
  aksi             VARCHAR2(30)   NOT NULL,
  tabel            VARCHAR2(30)   NOT NULL,
  kunci            VARCHAR2(100)  NOT NULL,
  data_lama        CLOB,
  data_baru        CLOB,
  keterangan       VARCHAR2(500),
  CONSTRAINT pk_sikeutxn_audit_logs PRIMARY KEY (log_id)
);
CREATE INDEX idx_sikeu_audit_tabel ON sikeutxn_audit_logs (tabel, kunci);
CREATE INDEX idx_sikeu_audit_waktu ON sikeutxn_audit_logs (waktu);

-- Tautan yang baru bisa dipasang setelah semua tabel ada
ALTER TABLE sikeumst_periodes    ADD CONSTRAINT fk_sikeu_periode_snap   FOREIGN KEY (snap_id_sah)         REFERENCES sikeutxn_snap_hdrs (snap_id);
ALTER TABLE sikeumst_tahun_bukus ADD CONSTRAINT fk_sikeu_thn_penutup    FOREIGN KEY (jurnal_penutup_id)   REFERENCES sikeutxn_jurnal_hdrs (jurnal_id);
ALTER TABLE sikeumst_asets       ADD CONSTRAINT fk_sikeu_aset_jurnal    FOREIGN KEY (jurnal_perolehan_id) REFERENCES sikeutxn_jurnal_hdrs (jurnal_id);

-- =============================================================================
-- E. TRIGGER PENGISI PK (hanya bila PK tidak dikirim)
-- =============================================================================
CREATE OR REPLACE TRIGGER tr_sikeu_pusat_biaya BEFORE INSERT ON sikeumst_pusat_biayas FOR EACH ROW
WHEN (new.pusat_biaya_id IS NULL)
BEGIN SELECT sq_sikeu_pusat_biaya.NEXTVAL INTO :new.pusat_biaya_id FROM dual; END;
/
CREATE OR REPLACE TRIGGER tr_sikeu_lap_hdr BEFORE INSERT ON sikeumst_lap_hdrs FOR EACH ROW
WHEN (new.lap_id IS NULL)
BEGIN SELECT sq_sikeu_lap_hdr.NEXTVAL INTO :new.lap_id FROM dual; END;
/
CREATE OR REPLACE TRIGGER tr_sikeu_lap_dtl BEFORE INSERT ON sikeumst_lap_dtls FOR EACH ROW
WHEN (new.baris_id IS NULL)
BEGIN SELECT sq_sikeu_lap_dtl.NEXTVAL INTO :new.baris_id FROM dual; END;
/
CREATE OR REPLACE TRIGGER tr_sikeu_aset_kat BEFORE INSERT ON sikeumst_aset_kategoris FOR EACH ROW
WHEN (new.kategori_id IS NULL)
BEGIN SELECT sq_sikeu_aset_kat.NEXTVAL INTO :new.kategori_id FROM dual; END;
/
CREATE OR REPLACE TRIGGER tr_sikeu_aset BEFORE INSERT ON sikeumst_asets FOR EACH ROW
WHEN (new.aset_id IS NULL)
BEGIN SELECT sq_sikeu_aset.NEXTVAL INTO :new.aset_id FROM dual; END;
/
CREATE OR REPLACE TRIGGER tr_sikeu_aset_mutasi BEFORE INSERT ON sikeutxn_aset_mutasis FOR EACH ROW
WHEN (new.mutasi_id IS NULL)
BEGIN SELECT sq_sikeu_aset_mutasi.NEXTVAL INTO :new.mutasi_id FROM dual; END;
/
CREATE OR REPLACE TRIGGER tr_sikeu_mitra BEFORE INSERT ON sikeumst_mitras FOR EACH ROW
WHEN (new.mitra_id IS NULL)
BEGIN SELECT sq_sikeu_mitra.NEXTVAL INTO :new.mitra_id FROM dual; END;
/
CREATE OR REPLACE TRIGGER tr_sikeu_snap BEFORE INSERT ON sikeutxn_snap_hdrs FOR EACH ROW
WHEN (new.snap_id IS NULL)
BEGIN SELECT sq_sikeu_snap.NEXTVAL INTO :new.snap_id FROM dual; END;
/
CREATE OR REPLACE TRIGGER tr_sikeu_jurnal_hdr BEFORE INSERT ON sikeutxn_jurnal_hdrs FOR EACH ROW
WHEN (new.jurnal_id IS NULL)
BEGIN SELECT sq_sikeu_jurnal_hdr.NEXTVAL INTO :new.jurnal_id FROM dual; END;
/
CREATE OR REPLACE TRIGGER tr_sikeu_jurnal_dtl BEFORE INSERT ON sikeutxn_jurnal_dtls FOR EACH ROW
WHEN (new.jurnal_dtl_id IS NULL)
BEGIN SELECT sq_sikeu_jurnal_dtl.NEXTVAL INTO :new.jurnal_dtl_id FROM dual; END;
/
CREATE OR REPLACE TRIGGER tr_sikeu_hpp_hdr BEFORE INSERT ON sikeutxn_hpp_hdrs FOR EACH ROW
WHEN (new.hpp_id IS NULL)
BEGIN SELECT sq_sikeu_hpp_hdr.NEXTVAL INTO :new.hpp_id FROM dual; END;
/
CREATE OR REPLACE TRIGGER tr_sikeu_hp_kartu BEFORE INSERT ON sikeutxn_hp_kartus FOR EACH ROW
WHEN (new.kartu_id IS NULL)
BEGIN SELECT sq_sikeu_hp_kartu.NEXTVAL INTO :new.kartu_id FROM dual; END;
/
CREATE OR REPLACE TRIGGER tr_sikeu_hp_bayar BEFORE INSERT ON sikeutxn_hp_bayars FOR EACH ROW
WHEN (new.bayar_id IS NULL)
BEGIN SELECT sq_sikeu_hp_bayar.NEXTVAL INTO :new.bayar_id FROM dual; END;
/
CREATE OR REPLACE TRIGGER tr_sikeu_audit BEFORE INSERT ON sikeutxn_audit_logs FOR EACH ROW
WHEN (new.log_id IS NULL)
BEGIN SELECT sq_sikeu_audit.NEXTVAL INTO :new.log_id FROM dual; END;
/

-- =============================================================================
-- F. DATA AWAL
-- =============================================================================

-- F1. Kelompok akun (selaras TKACC_GR_ACCOUNTSES SIRUS)
INSERT INTO sikeumst_akun_kelompoks (kelompok_id, nama, saldo_normal, jenis_laporan, urut) VALUES ('1', 'AKTIVA',     'D', 'N', 1);
INSERT INTO sikeumst_akun_kelompoks (kelompok_id, nama, saldo_normal, jenis_laporan, urut) VALUES ('2', 'KEWAJIBAN',  'K', 'N', 2);
INSERT INTO sikeumst_akun_kelompoks (kelompok_id, nama, saldo_normal, jenis_laporan, urut) VALUES ('3', 'EKUITAS',    'K', 'N', 3);
INSERT INTO sikeumst_akun_kelompoks (kelompok_id, nama, saldo_normal, jenis_laporan, urut) VALUES ('4', 'PENDAPATAN', 'K', 'L', 4);
INSERT INTO sikeumst_akun_kelompoks (kelompok_id, nama, saldo_normal, jenis_laporan, urut) VALUES ('5', 'BEBAN',      'D', 'L', 5);

-- F2. Kategori aset - BAWAAN umur fiskal PMK 96/PMK.03/2009 (garis lurus). Akun jurnal diisi kemudian dari layar.
INSERT INTO sikeumst_aset_kategoris (kode, nama, metode, umur_bulan, tarif_persen, created_at) VALUES ('KEL1',   'Bukan bangunan kelompok 1 (4 tahun)',  'GL',  48, 25,    SYSTIMESTAMP);
INSERT INTO sikeumst_aset_kategoris (kode, nama, metode, umur_bulan, tarif_persen, created_at) VALUES ('KEL2',   'Bukan bangunan kelompok 2 (8 tahun)',  'GL',  96, 12.5,  SYSTIMESTAMP);
INSERT INTO sikeumst_aset_kategoris (kode, nama, metode, umur_bulan, tarif_persen, created_at) VALUES ('KEL3',   'Bukan bangunan kelompok 3 (16 tahun)', 'GL', 192, 6.25,  SYSTIMESTAMP);
INSERT INTO sikeumst_aset_kategoris (kode, nama, metode, umur_bulan, tarif_persen, created_at) VALUES ('KEL4',   'Bukan bangunan kelompok 4 (20 tahun)', 'GL', 240, 5,     SYSTIMESTAMP);
INSERT INTO sikeumst_aset_kategoris (kode, nama, metode, umur_bulan, tarif_persen, created_at) VALUES ('BGN-P',  'Bangunan permanen (20 tahun)',         'GL', 240, 5,     SYSTIMESTAMP);
INSERT INTO sikeumst_aset_kategoris (kode, nama, metode, umur_bulan, tarif_persen, created_at) VALUES ('BGN-NP', 'Bangunan tidak permanen (10 tahun)',   'GL', 120, 10,    SYSTIMESTAMP);
INSERT INTO sikeumst_aset_kategoris (kode, nama, metode, umur_bulan, tarif_persen, created_at) VALUES ('TANAH',  'Tanah (tidak disusutkan)',             'TS',   0, 0,     SYSTIMESTAMP);

-- F3. Template laporan (baris & akun diisi dari layar Template Laporan)
INSERT INTO sikeumst_lap_hdrs (jenis, nama, metode, created_at) VALUES ('NERACA',   'Neraca',                         NULL, SYSTIMESTAMP);
INSERT INTO sikeumst_lap_hdrs (jenis, nama, metode, created_at) VALUES ('LABARUGI', 'Laba Rugi',                      NULL, SYSTIMESTAMP);
INSERT INTO sikeumst_lap_hdrs (jenis, nama, metode, created_at) VALUES ('ARUSKAS',  'Arus Kas (metode langsung)',     'L',  SYSTIMESTAMP);
INSERT INTO sikeumst_lap_hdrs (jenis, nama, metode, created_at) VALUES ('ARUSKAS',  'Arus Kas (metode tidak langsung)', 'T', SYSTIMESTAMP);

COMMIT;

-- =============================================================================
-- G. VERIFIKASI - harus 26 tabel (25 di sini + sikeumst_identitas dari migrasi), trigger VALID, 5 kelompok akun
-- =============================================================================
SELECT COUNT(*) AS jumlah_tabel FROM user_tables
 WHERE table_name LIKE 'SIKEUMST\_%' ESCAPE '\' OR table_name LIKE 'SIKEUTXN\_%' ESCAPE '\';
SELECT object_name, object_type, status FROM user_objects
 WHERE object_name LIKE 'TR\_SIKEU\_%' ESCAPE '\' OR status <> 'VALID'
 ORDER BY object_type, object_name;
SELECT COUNT(*) AS kelompok_akun FROM sikeumst_akun_kelompoks;

-- =============================================================================
-- H. ROLLBACK (MENGHAPUS SEMUA DATA PEMBUKUAN - jalankan hanya pada pemasangan yang salah, sebelum ada data)
-- =============================================================================
-- ALTER TABLE sikeumst_periodes    DROP CONSTRAINT fk_sikeu_periode_snap;
-- ALTER TABLE sikeumst_tahun_bukus DROP CONSTRAINT fk_sikeu_thn_penutup;
-- ALTER TABLE sikeumst_asets       DROP CONSTRAINT fk_sikeu_aset_jurnal;
-- ALTER TABLE sikeutxn_jurnal_dtls DROP CONSTRAINT fk_sikeu_jdtl_kartu;
-- DROP TABLE sikeutxn_audit_logs;    DROP TABLE sikeutxn_hp_bayars;   DROP TABLE sikeutxn_hp_kartus;
-- DROP TABLE sikeutxn_hpp_dtls;      DROP TABLE sikeutxn_hpp_hdrs;    DROP TABLE sikeutxn_aset_mutasis;
-- DROP TABLE sikeutxn_aset_susuts;   DROP TABLE sikeutxn_saldo_akuns; DROP TABLE sikeutxn_jurnal_dtls;
-- DROP TABLE sikeutxn_jurnal_hdrs;   DROP TABLE sikeutxn_snap_dtls;   DROP TABLE sikeutxn_snap_cabangs;
-- DROP TABLE sikeutxn_snap_hdrs;     DROP TABLE sikeumst_mitras;      DROP TABLE sikeumst_asets;
-- DROP TABLE sikeumst_aset_kategoris; DROP TABLE sikeumst_lap_akuns;  DROP TABLE sikeumst_lap_dtls;
-- DROP TABLE sikeumst_lap_hdrs;      DROP TABLE sikeumst_periodes;    DROP TABLE sikeumst_tahun_bukus;
-- DROP TABLE sikeumst_pusat_biayas;  DROP TABLE sikeumst_peta_akun_sirus; DROP TABLE sikeumst_akuns;
-- DROP TABLE sikeumst_akun_kelompoks;
-- DROP SEQUENCE sq_sikeu_pusat_biaya; DROP SEQUENCE sq_sikeu_lap_hdr; DROP SEQUENCE sq_sikeu_lap_dtl;
-- DROP SEQUENCE sq_sikeu_aset_kat; DROP SEQUENCE sq_sikeu_aset; DROP SEQUENCE sq_sikeu_aset_mutasi;
-- DROP SEQUENCE sq_sikeu_mitra; DROP SEQUENCE sq_sikeu_snap; DROP SEQUENCE sq_sikeu_jurnal_hdr;
-- DROP SEQUENCE sq_sikeu_jurnal_dtl; DROP SEQUENCE sq_sikeu_hpp_hdr; DROP SEQUENCE sq_sikeu_hp_kartu;
-- DROP SEQUENCE sq_sikeu_hp_bayar; DROP SEQUENCE sq_sikeu_audit;

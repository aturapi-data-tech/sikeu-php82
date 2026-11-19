-- =============================================================================
-- SIKEU - 03. Peta akun SIRUS -> bagan akun SIKEU
-- Jalankan sebagai user SIKEU, SESUDAH 02_bagan_akun.sql. DIBUAT OTOMATIS oleh
-- database/sql/tools/bagan_akun.php.
--
-- Semua akun acmst_accounts SIRUS (termasuk nonaktif & akun induk) dipetakan, karena posting
-- snapshot DITOLAK bila ada acc_id yang belum punya peta. Peta boleh banyak-ke-satu.
--
-- KOREKSI KLASIFIKASI DARI SIRUS (disengaja):
--   * 4.1AA/4.1AKA/4.1BB/4.1CA/4.1GA "Pendapatan ... Terutang" -> PIUTANG pasien per jalur (aset),
--     bukan pendapatan.
--   * 4.1CA1 & 2.1A04 Angsuran Awal Pasien -> Uang Muka Pasien (liabilitas).
--   * 1.1A05/07/08 Peralatan (di SIRUS aset lancar) -> Aset Tetap; 1.1A06 Perlengkapan -> Persediaan.
--   * 5.1F03B1/5.1F041/5.1G21 "Persediaan ..." (di SIRUS kode beban) -> Persediaan.
--   * Subsidi (5.1A-C, 5.1I*) & Return (5.1J*, 4.1AA1, 4.1AK1) -> PENGURANG PENDAPATAN.
--   * Keuntungan (4.1I1) & Kerugian (5.1K6) Layanan BPJS -> satu akun Selisih Tarif INA-CBG/iDRG.
--   * 4.1J Diskon PBF -> Potongan Pembelian (pengurang HPP), bukan pendapatan.
--   * 4.1H Bunga Tabungan -> Pendapatan Lain-lain; 5.1D Bunga Bank -> Beban Lain-lain.
--   * Pendapatan Transfer RJ/UGD (4.1D*, 4.1E*) digabung ke jalur asalnya.
--   * 1.1B05 Akumulasi Penyusutan & 5.1K5 Penyusutan (satu akun di SIRUS) -> akun "Belum Dirinci";
--     rinci per jenis aset lewat jurnal reklasifikasi setelah register aset terisi.
-- =============================================================================
SET DEFINE OFF

INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A01', 'KAS', '1.1.01.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A01A', 'KAS TU', '1.1.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A01B', 'KAS APOTEK', '1.1.01.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A01C', 'KAS KLINIK', '1.1.01.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A01D', 'KAS BAMBANG', '1.1.01.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A01E', 'KAS OPERASIONAL', '1.1.01.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A01F', 'KAS BPJS', '1.1.01.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A01G', 'KAS RS', '1.1.01.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A02', 'TABUNGAN DI BANK MANDIRI SYARIAH', '1.1.01.101', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A03', 'BON KARYAWAN', '1.1.03.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A04', 'PUTANG PASIEN RAWAT INAP', '1.1.02.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1AA', 'PENDAPATAN RAWAT JALAN TERUTANG', '1.1.02.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1AKA', 'PENDAPATAN KLINIK TERUTANG', '1.1.02.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1BB', 'PENDAPATAN UGD TERUTANG', '1.1.02.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1CA', 'PENDAPATAN RAWAT INAP TERUTANG', '1.1.02.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1GA', 'PENDAPATAN RESEP TERUTANG', '1.1.02.005', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A05', 'PERALATAN', '1.2.01.005', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A06', 'PERLENGKAPAN', '1.1.04.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A07', 'PERALATAN NON MEDIS', '1.2.01.005', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A08', 'PERALATAN MEDIS', '1.2.01.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A09', 'OBAT RETURN RAWAT INAP', '1.1.04.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A10', 'PERSEDIAAN SETOCK APOTIK', '1.1.04.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A11', 'PERSEDIAAN SETOCK GUDANG', '1.1.04.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A11.1', 'PERSEDIAAN SETOCK GUDANG NON MEDIS', '1.1.04.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A12', 'PERSEDIAAN SETOCK KLINIK', '1.1.04.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F03B1', '* PERSEDIAAN BEBAN DAPUR KERING', '1.1.04.005', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F041', 'PERSEDIAAN PERALATAN ATK', '1.1.04.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1G21', '* PERSEDIAAN PERAWATAN UMUM', '1.1.04.007', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1A13', 'PEMBAYARAN DIMUKA PBF', '1.1.05.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1B01', 'GEDUNG', '1.2.01.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1B02', 'TANAH', '1.2.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1B03', 'GEDUNG DALAM PEMBANGUNAN', '1.2.01.009', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('201', 'GEDUNG DALAM PEMBANGUNAN', '1.2.01.009', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1B04', 'KENDARAAN', '1.2.01.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('1.1B05', 'AKUMULASI PENYUSUTAN(AKTIVA TETAP)', '1.2.02.009', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('2.1A01', 'HUTANG USAHA', '2.1.01.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('2.1A02', 'HUTANG BANK', '2.1.06.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('2.1A03', 'HUTANG SUPPLIER', '2.1.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('2.1A04', 'ANGSURAN AWAL PASIEN', '2.1.05.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1CA1', 'ANGSURAN AWAL PASIEN RAWAT INAP', '2.1.05.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('3.1A01', 'MODAL', '3.1.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('L00', 'MODAL', '3.1.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('3.1A02', 'LABA USAHA BULAN INI', '3.1.02.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('L01', 'LABA (RUGI)', '3.1.02.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('3.1A03', 'AKUMULASI LABA', '3.1.02.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('L02', 'AKUMULASI LABA USAHA', '3.1.02.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A', 'PENDAPATAN RAWAT JALAN', '4.1.01.008', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A01', 'PENDAPATAN ADMIN (RJ)', '4.1.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A01.1', 'PENDAPATAN ADMIN RS (RJ)', '4.1.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A02', 'PENDAPATAN UANG PERIKSA (RJ)', '4.1.01.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A03', 'PENDAPATAN JASA DOKTER (RJ)', '4.1.01.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A04', 'PENDAPATAN JASA MEDIS (RJ)', '4.1.01.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A05', 'PENDAPATAN JASA KARYAWAN (RJ)', '4.1.01.005', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A06', 'PENDAPATAN OBAT (RJ)', '4.2.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A07', 'PENDAPATAN LABORAT (RJ)', '4.1.01.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A08', 'PENDAPATAN RADIOLOGI (RJ)', '4.1.01.007', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A09', 'PENDAPATAN LAIN-LAIN (RJ)', '4.1.01.008', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1D', 'PENDAPATAN TRANSFER RAWAT JALAN', '4.1.01.008', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1D01', 'PENDAPATAN ADMIN (TRF RJ)', '4.1.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1D01.1', 'PENDAPATAN ADMIN RS (TRF RJ)', '4.1.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1D02', 'PENDAPATAN UANG PERIKSA (TRF RJ)', '4.1.01.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1D03', 'PENDAPATAN JASA DOKTER (TRF RJ)', '4.1.01.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1D04', 'PENDAPATAN JASA MEDIS (TRF RJ)', '4.1.01.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1D05', 'PENDAPATAN JASA KARYAWAN (TRF RJ)', '4.1.01.005', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1D06', 'PENDAPATAN OBAT (TRF RJ)', '4.2.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1D07', 'PENDAPATAN LABORAT (TRF RJ)', '4.1.01.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1D08', 'PENDAPATAN RADIOLOGI (TRF RJ)', '4.1.01.007', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1D09', 'PENDAPATAN LAIN-LAIN (TRF RJ)', '4.1.01.008', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1AK', 'PENDAPATAN RAWAT KLINIK', '4.1.02.008', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A01K', 'PENDAPATAN ADMIN (KLINIK)', '4.1.02.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A01.1K', 'PENDAPATAN ADMIN RS (KLINIK)', '4.1.02.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A02K', 'PENDAPATAN UANG PERIKSA (KLINIK)', '4.1.02.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A03K', 'PENDAPATAN JASA DOKTER (KLINIK)', '4.1.02.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A04K', 'PENDAPATAN JASA MEDIS (KLINIK)', '4.1.02.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A05K', 'PENDAPATAN JASA KARYAWAN (KLINIK)', '4.1.02.005', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A06K', 'PENDAPATAN OBAT (KLINIK)', '4.2.01.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A07K', 'PENDAPATAN LABORAT (KLINIK)', '4.1.02.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A08K', 'PENDAPATAN RADIOLOGI (KLINIK)', '4.1.02.007', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1A09K', 'PENDAPATAN LAIN-LAIN (KLINIK)', '4.1.02.008', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1B', 'PENDAPATAN UGD', '4.1.03.008', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1B01', 'PENDAPATAN ADMIN (UGD)', '4.1.03.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1B01.1', 'PENDAPATAN ADMIN RS (UGD)', '4.1.03.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1B02', 'PENDAPATAN UANG PERIKSA (UGD)', '4.1.03.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1B03', 'PENDAPATAN JASA DOKTER (UGD)', '4.1.03.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1B04', 'PENDAPATAN JASA MEDIS (UGD)', '4.1.03.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1B05', 'PENDAPATAN JASA KARYAWAN (UGD)', '4.1.03.005', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1B06', 'PENDAPATAN OBAT (UGD)', '4.2.01.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1B07', 'PENDAPATAN LABORAT (UGD)', '4.1.03.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1B08', 'PENDAPATAN RADIOLOGI (UGD)', '4.1.03.007', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1B09', 'PENDAPATAN LAIN-LAIN (UGD)', '4.1.03.008', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E', 'PENDAPATAN TRANSFER UGD', '4.1.03.008', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1EA', 'PENDAPATAN TRANSFER RJ DI UGD', '4.1.01.008', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E01', 'PENDAPATAN ADMIN (TRF UGD)', '4.1.03.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E01.1', 'PENDAPATAN ADMIN RS (TRF UGD)', '4.1.03.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E02', 'PENDAPATAN UANG PERIKSA (TRF UGD)', '4.1.03.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E03', 'PENDAPATAN JASA DOKTER (TRF UGD)', '4.1.03.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E04', 'PENDAPATAN JASA MEDIS (TRF UGD)', '4.1.03.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E05', 'PENDAPATAN JASA KARYAWAN (TRF UGD)', '4.1.03.005', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E06', 'PENDAPATAN OBAT (TRF UGD)', '4.2.01.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E07', 'PENDAPATAN LABORAT (TRF UGD)', '4.1.03.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E08', 'PENDAPATAN RADIOLOGI (TRF UGD)', '4.1.03.007', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E09', 'PENDAPATAN LAIN-LAIN (TRF UGD)', '4.1.03.008', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E01A', 'PENDAPATAN ADMIN RS (TRF RJ) DI UGD', '4.1.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E02A', 'PENDAPATAN UANG PERIKSA (TRF RJ) DI UGD', '4.1.01.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E03A', 'PENDAPATAN JASA DOKTER (TRF RJ) DI UGD', '4.1.01.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E04A', 'PENDAPATAN JASA MEDIS (TRF RJ) DI UGD', '4.1.01.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E05A', 'PENDAPATAN JASA KARYAWAN (TRF RJ) DI UGD', '4.1.01.005', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E06A', 'PENDAPATAN OBAT (TRF RJ) DI UGD', '4.2.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E07A', 'PENDAPATAN LABORAT (TRF RJ) DI UGD', '4.1.01.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E08A', 'PENDAPATAN RADIOLOGI (TRF RJ) DI UGD', '4.1.01.007', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1E09A', 'PENDAPATAN LAIN-LAIN (TRF RJ) DI UGD', '4.1.01.008', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1C', 'PENDAPATAN RAWAT INAP', '4.1.04.011', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1C01', 'PENDAPATAN ADMIN AGE (RI)', '4.1.04.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1C02', 'PENDAPATAN ADMIN STATUS (RI)', '4.1.04.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1C03', 'PENDAPATAN JASA DOKTER (RI)', '4.1.04.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1C04', 'PENDAPATAN JASA MEDIS (RI)', '4.1.04.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1C05', 'VISIT (RI)', '4.1.04.005', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1C06', 'KONSUL (RI)', '4.1.04.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1C07', 'PENDAPATAN LABORAT (RI)', '4.1.04.008', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1C08', 'PENDAPATAN RADIOLOGI (RI)', '4.1.04.009', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1C09', 'OBAT PINJAM (RI)', '4.2.01.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1C10', 'PERAWATAN (RI)', '4.1.04.007', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1C11', 'PENDAPATAN SEWA KAMAR (RI)', '4.1.04.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1C12', 'PELAYANAN UMUM (RI)', '4.1.04.010', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1C13', 'PENDAPATAN LAIN-LAIN (RI)', '4.1.04.011', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1F', 'PENDAPATAN OK', '4.1.05.008', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1F01', 'OPERATOR', '4.1.05.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1F02', 'ASISTEN OPERATOR', '4.1.05.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1F03', 'ANASTESI', '4.1.05.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1F04', 'PENGGANTI ANASTESI', '4.1.05.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1F05', 'ASISTEN ANASTESI', '4.1.05.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1F06', 'INSTRUMENT', '4.1.05.005', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1F07', 'OMLOP', '4.1.05.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1F08', 'RECOVERY ROOM', '4.1.05.007', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1F09', 'OK FEE', '4.1.05.008', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1F10', 'BAHAN', '4.1.05.010', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1F11', 'SEWA ALAT', '4.1.05.009', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1G', 'PENDAPATAN RESEP', '4.2.01.005', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1G1', 'PENDAPATAN OBAT (RESEP)', '4.2.01.005', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1G2', 'PENDAPATAN JASA KARYAWAN (RESEP)', '4.2.01.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1H', 'BUNGA TABUNGAN DI BANK', '7.1.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1I', 'PENDAPATAN DILUAR USAHA', '7.1.01.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1I1', 'KEUNTUNGAN LAYANAN BPJS', '4.3.03.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1K6', 'KERUGIAN PELAYANAN BPJS', '4.3.03.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1J', 'DISKON PBF', '5.1.01.901', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1AA1', 'RETURN OBAT (RJ)', '4.3.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('4.1AK1', 'RETURN OBAT (KLINIK)', '4.3.01.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1J', 'RETURN', '4.3.01.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1J1', 'RETURN RI', '4.3.01.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1J2', 'RETURN RJ', '4.3.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1J3', 'RETURN KLINIK', '4.3.01.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1A', 'SUBSIDI RAWAT JALAN', '4.3.02.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1B', 'SUBSIDI UGD', '4.3.02.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1C', 'SUBSIDI RAWAT INAP', '4.3.02.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1I', 'BEBAN SUBSIDI', '4.3.02.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1I1', 'SUBSIDI RJ', '4.3.02.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1I2', 'SUBSIDI UGD', '4.3.02.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1I3', 'SUBSIDI RI', '4.3.02.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1I4', 'SUBSIDI KLINIK', '4.3.02.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1K', 'HARGA POKOK PENJUALAN', '5.1.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1K1', 'HPP RJ /UGD / RESEP', '5.1.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1K2', 'HPP RI', '5.1.01.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1K3', 'HPP  KLINIK', '5.1.01.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1K4', 'HPP RUANGAN', '5.2.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1L2', 'BEBAN PPN RCV', '5.1.01.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1E11', 'BEBAN OBAT RUSAK', '5.1.01.005', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1E12', 'BEBAN OBAT HILANG', '5.1.01.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1E', 'BEBAN PENUNJANG MEDIS', '5.4.03.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1E02', 'BEBAN OBAT RUANGAN', '5.2.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1E07', 'BEBAN OBAT TIMUR', '5.2.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1E08', 'BEBAN OBAT UTARA', '5.2.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1E09', 'BEBAN OBAT SELATAN', '5.2.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1E03', 'BEBAN OBAT LAB', '5.2.01.005', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1E04', 'BEBAN OBAT UGD', '5.2.01.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1E05', 'BEBAN OBAT VK', '5.2.01.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1E06', 'BEBAN OBAT OK', '5.2.01.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1E13', 'BEBAN OBAT KLINIK KALANGAN', '5.2.01.008', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F08', 'PENUNJANG MEDIS', '5.4.03.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F08A', '* PENUNJANG LABORAT', '5.2.01.005', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F08B', '* PENUNJANG RADIOLOGI', '5.2.01.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F08C', '* BEBAN OKSIGEN', '5.2.01.007', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F02', 'BEBAN GAJI DOKTER', '5.3.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F02.A', 'BEBAN JASA/GAJI DOKTER KALANGAN', '5.3.01.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F02A', 'BEBAN JASA MEDIS', '5.3.01.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F02A1', 'BEBAN JASA MEDIS KARYAWAN', '5.3.01.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F02A2', 'BEBAN JASA MEDIS DOKTER', '5.3.01.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F03', 'BEBAN DAPUR', '5.4.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F03A', '* BEBAN DAPUR BASAH', '5.4.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F03B', '* BEBAN DAPUR KERING', '5.4.01.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1E01', 'BIAYA PERAWATAN ALAT-ALAT MEDIS', '5.4.02.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F07', 'PERALATAN MEDIS', '5.4.02.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1G', 'BEBAN PERAWATAN', '5.4.02.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1G1', '* BEBAN PERAWATAN MEDIS', '5.4.02.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1G1.1', '* BEBAN PERAWATAN MEDIS KLINIK KALANGAN', '5.4.02.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1H1A', '* BEBAN OPERASIONAL MEDIS', '5.4.03.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1H1C', '* BEBAN OPERASIONAL RUANGAN', '5.4.03.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F01', 'BEBAN GAJI KARYAWAN', '6.1.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F01.A', 'BEBAN GAJI KARYAWAN KALANGAN', '6.1.01.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1E10', 'BEBAN OBAT KARYAWAN', '6.1.01.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F', 'BIAYA UMUM DAN ADMINISTRASI', '6.2.01.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F04', 'BEBAN ATK', '6.2.01.002', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F05', 'BIAYA LISTRIK/TLP/AIR', '6.2.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F06', 'PERALATAN NON MEDIS', '6.2.01.005', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F10', 'PAJAK', '6.2.01.011', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F11', 'BEBAN UMUM', '6.2.01.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F12', 'BEBAN PERCETAKAN', '6.2.01.003', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F13', 'BEBAN OPRASIONAL UMUM', '6.2.01.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F14', 'BEBAN ADMINISTRASI', '6.2.01.007', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F15', 'BEBAN ADMINISTRASI KLINIK KALANGAN', '6.2.01.009', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F16', 'BEBAN OPRASIONAL UMUM KLINIK KALANGAN', '6.2.01.008', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1G1.2', '* BEBAN PERAWATAN NON MEDIS KLINIK KALANGAN', '6.2.01.008', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1G2', '* BEBAN PERAWATAN UMUM', '6.2.01.004', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1H', 'BEBAN ADMINISTRASI', '6.2.01.007', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1H1', 'BEBAN OPERASIONAL', '6.2.01.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1H1B', '* BEBAN OPERASIONAL UMUM', '6.2.01.006', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1L1', 'BEBAN MATERAI RCV', '6.2.01.010', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1K5', 'BEBAN PENYUSUTAN AKTIVA TETAP', '6.3.01.009', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1F09', 'PENGHAPUS PIUTANG', '6.4.01.001', SYSTIMESTAMP);
INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ('5.1D', 'BEBAN BUNGA BANK', '8.1.01.001', SYSTIMESTAMP);

COMMIT;

-- VERIFIKASI - harus 219 baris; tujuan selalu akun Detail
SELECT COUNT(*) jumlah_peta FROM sikeumst_peta_akun_sirus;
SELECT p.acc_id_sirus, p.akun_id FROM sikeumst_peta_akun_sirus p JOIN sikeumst_akuns a ON a.akun_id = p.akun_id
 WHERE a.tipe <> 'D';

-- ROLLBACK
-- DELETE FROM sikeumst_peta_akun_sirus; COMMIT;

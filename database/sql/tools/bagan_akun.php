<?php

/**
 * Generator 02_bagan_akun.sql + 03_peta_akun_sirus.sql.
 *
 * Bagan akun ditulis SEKALI di sini (kode => nama) supaya tingkat, induk, tipe H/D, saldo normal dan
 * kategori arus kas diturunkan otomatis, bukan diketik tangan 200 kali. Hasil SQL-nya yang dijalankan
 * di server & di-commit; file ini hanya alat bantu bila bagan diubah:
 *
 *   php database/sql/tools/bagan_akun.php
 *
 * Teks wajib ASCII (DB WE8ISO8859P1).
 */

// -----------------------------------------------------------------------------
// Kelompok akun (digit pertama kode) - susunan laporan SAK EP
// -----------------------------------------------------------------------------
$kelompok = [
    '1' => ['ASET',                     'D', 'N'],
    '2' => ['LIABILITAS',               'K', 'N'],
    '3' => ['EKUITAS',                  'K', 'N'],
    '4' => ['PENDAPATAN USAHA',         'K', 'L'],
    '5' => ['BEBAN POKOK PELAYANAN',    'D', 'L'],
    '6' => ['BEBAN USAHA',              'D', 'L'],
    '7' => ['PENDAPATAN LAIN-LAIN',     'K', 'L'],
    '8' => ['BEBAN LAIN-LAIN',          'D', 'L'],
    '9' => ['BEBAN PAJAK PENGHASILAN',  'D', 'L'],
];

// -----------------------------------------------------------------------------
// Bagan akun. Kode: K.S.GG.DDD  (kelompok . subkelompok . golongan . rincian)
// Akun yang punya anak otomatis jadi Header (H); hanya Detail (D) yang boleh dijurnal.
// -----------------------------------------------------------------------------
$akun = [
    '1' => 'ASET',
    '1.1' => 'ASET LANCAR',
    '1.1.01' => 'KAS DAN SETARA KAS',
    '1.1.01.001' => 'Kas Kasir Tata Usaha (Rawat Jalan/UGD/Rawat Inap)',
    '1.1.01.002' => 'Kas Kasir Apotek',
    '1.1.01.003' => 'Kas Kasir Klinik',
    '1.1.01.004' => 'Kas Rumah Sakit (Penerimaan Non Tunai/QRIS)',
    '1.1.01.005' => 'Kas Kecil',
    '1.1.01.006' => 'Kas Operasional (Pemegang Kas)',
    '1.1.01.101' => 'Bank Syariah Indonesia - Tabungan',
    '1.1.01.102' => 'Bank - Giro',
    '1.1.01.201' => 'Deposito Berjangka (jatuh tempo maks. 3 bulan)',
    '1.1.02' => 'PIUTANG USAHA',
    '1.1.02.001' => 'Piutang Pasien Rawat Jalan',
    '1.1.02.002' => 'Piutang Pasien Klinik',
    '1.1.02.003' => 'Piutang Pasien UGD',
    '1.1.02.004' => 'Piutang Pasien Rawat Inap',
    '1.1.02.005' => 'Piutang Resep Apotek',
    '1.1.02.006' => 'Piutang Klaim BPJS Kesehatan',
    '1.1.02.007' => 'Piutang Asuransi dan Perusahaan Penjamin',
    '1.1.02.901' => 'Cadangan Kerugian Penurunan Nilai Piutang',
    '1.1.03' => 'PIUTANG LAIN-LAIN',
    '1.1.03.001' => 'Piutang Karyawan',
    '1.1.03.002' => 'Piutang Lain-lain',
    '1.1.04' => 'PERSEDIAAN',
    '1.1.04.001' => 'Persediaan Obat dan BMHP - Gudang Farmasi',
    '1.1.04.002' => 'Persediaan Obat dan BMHP - Apotek',
    '1.1.04.003' => 'Persediaan Obat dan BMHP - Klinik',
    '1.1.04.004' => 'Persediaan Barang Non Medis - Gudang',
    '1.1.04.005' => 'Persediaan Bahan Makanan (Gizi)',
    '1.1.04.006' => 'Persediaan ATK dan Barang Cetakan',
    '1.1.04.007' => 'Persediaan Bahan Pemeliharaan',
    '1.1.05' => 'UANG MUKA DAN BIAYA DIBAYAR DIMUKA',
    '1.1.05.001' => 'Uang Muka Pembelian - PBF',
    '1.1.05.002' => 'Uang Muka Pembelian - Non Medis',
    '1.1.05.003' => 'Sewa Dibayar Dimuka',
    '1.1.05.004' => 'Asuransi Dibayar Dimuka',
    '1.1.05.005' => 'Uang Muka Kegiatan Operasional',
    '1.1.06' => 'PAJAK DIBAYAR DIMUKA',
    '1.1.06.001' => 'PPh Pasal 22 Dibayar Dimuka',
    '1.1.06.002' => 'PPh Pasal 23 Dibayar Dimuka',
    '1.1.06.003' => 'PPh Pasal 25 Dibayar Dimuka',
    '1.1.06.004' => 'PPN Masukan',
    '1.1.09' => 'AKUN SEMENTARA',
    '1.1.09.001' => 'Akun Penampung (Suspense) - wajib nol saat tutup periode',

    '1.2' => 'ASET TIDAK LANCAR',
    '1.2.01' => 'ASET TETAP',
    '1.2.01.001' => 'Tanah',
    '1.2.01.002' => 'Bangunan dan Gedung',
    '1.2.01.003' => 'Instalasi dan Jaringan',
    '1.2.01.004' => 'Peralatan Medis',
    '1.2.01.005' => 'Peralatan Non Medis dan Inventaris Kantor',
    '1.2.01.006' => 'Kendaraan',
    '1.2.01.007' => 'Komputer dan Perangkat TI',
    '1.2.01.009' => 'Aset Dalam Penyelesaian',
    '1.2.02' => 'AKUMULASI PENYUSUTAN ASET TETAP',
    '1.2.02.002' => 'Akumulasi Penyusutan Bangunan dan Gedung',
    '1.2.02.003' => 'Akumulasi Penyusutan Instalasi dan Jaringan',
    '1.2.02.004' => 'Akumulasi Penyusutan Peralatan Medis',
    '1.2.02.005' => 'Akumulasi Penyusutan Peralatan Non Medis',
    '1.2.02.006' => 'Akumulasi Penyusutan Kendaraan',
    '1.2.02.007' => 'Akumulasi Penyusutan Komputer dan Perangkat TI',
    '1.2.02.009' => 'Akumulasi Penyusutan - Saldo SIRUS Belum Dirinci',
    '1.2.03' => 'ASET TAKBERWUJUD',
    '1.2.03.001' => 'Perangkat Lunak dan Lisensi',
    '1.2.03.901' => 'Akumulasi Amortisasi Aset Takberwujud',
    '1.2.04' => 'ASET TIDAK LANCAR LAINNYA',
    '1.2.04.001' => 'Uang Jaminan',
    '1.2.04.002' => 'Aset Lain-lain',

    '2' => 'LIABILITAS',
    '2.1' => 'LIABILITAS JANGKA PENDEK',
    '2.1.01' => 'UTANG USAHA',
    '2.1.01.001' => 'Utang Usaha - PBF (Obat dan BMHP)',
    '2.1.01.002' => 'Utang Usaha - Supplier Non Medis',
    '2.1.02' => 'UTANG JASA PELAYANAN',
    '2.1.02.001' => 'Utang Jasa Medis Dokter',
    '2.1.02.002' => 'Utang Jasa Pelayanan Karyawan',
    '2.1.03' => 'UTANG PAJAK',
    '2.1.03.001' => 'Utang PPh Pasal 21',
    '2.1.03.002' => 'Utang PPh Pasal 23',
    '2.1.03.003' => 'Utang PPh Pasal 4 ayat 2',
    '2.1.03.004' => 'Utang PPh Pasal 25',
    '2.1.03.005' => 'Utang PPh Pasal 29',
    '2.1.04' => 'BEBAN MASIH HARUS DIBAYAR',
    '2.1.04.001' => 'Gaji dan Tunjangan Masih Harus Dibayar',
    '2.1.04.002' => 'Iuran BPJS Kesehatan dan Ketenagakerjaan',
    '2.1.04.003' => 'Listrik, Air dan Telepon Masih Harus Dibayar',
    '2.1.04.004' => 'Beban Lain Masih Harus Dibayar',
    '2.1.05' => 'UANG MUKA DAN PENDAPATAN DITERIMA DIMUKA',
    '2.1.05.001' => 'Uang Muka Pasien (Deposit/Angsuran Awal)',
    '2.1.05.002' => 'Pendapatan Diterima Dimuka',
    '2.1.06' => 'UTANG BANK JANGKA PENDEK',
    '2.1.06.001' => 'Utang Bank Jangka Pendek',
    '2.1.06.002' => 'Bagian Lancar Utang Bank Jangka Panjang',
    '2.1.07' => 'UTANG LAIN-LAIN',
    '2.1.07.001' => 'Utang kepada Pihak Berelasi',
    '2.1.07.002' => 'Utang Lain-lain',
    '2.2' => 'LIABILITAS JANGKA PANJANG',
    '2.2.01' => 'UTANG JANGKA PANJANG',
    '2.2.01.001' => 'Utang Bank Jangka Panjang',
    '2.2.01.002' => 'Liabilitas Sewa Pembiayaan',
    '2.2.02' => 'LIABILITAS IMBALAN KERJA',
    '2.2.02.001' => 'Liabilitas Imbalan Pascakerja',

    '3' => 'EKUITAS',
    '3.1' => 'EKUITAS',
    '3.1.01' => 'MODAL',
    '3.1.01.001' => 'Modal Disetor',
    '3.1.01.002' => 'Tambahan Modal Disetor',
    '3.1.02' => 'SALDO LABA',
    '3.1.02.001' => 'Saldo Laba Ditahan',
    '3.1.02.002' => 'Laba (Rugi) Tahun Berjalan',
    '3.1.02.003' => 'Dividen',

    '4' => 'PENDAPATAN USAHA',
    '4.1' => 'PENDAPATAN JASA PELAYANAN',
    '4.1.01' => 'PENDAPATAN RAWAT JALAN',
    '4.1.01.001' => 'Administrasi Rawat Jalan',
    '4.1.01.002' => 'Jasa Pemeriksaan Rawat Jalan',
    '4.1.01.003' => 'Jasa Dokter Rawat Jalan',
    '4.1.01.004' => 'Jasa Tindakan Medis Rawat Jalan',
    '4.1.01.005' => 'Jasa Keperawatan Rawat Jalan',
    '4.1.01.006' => 'Laboratorium Rawat Jalan',
    '4.1.01.007' => 'Radiologi Rawat Jalan',
    '4.1.01.008' => 'Pelayanan Lain-lain Rawat Jalan',
    '4.1.02' => 'PENDAPATAN KLINIK',
    '4.1.02.001' => 'Administrasi Klinik',
    '4.1.02.002' => 'Jasa Pemeriksaan Klinik',
    '4.1.02.003' => 'Jasa Dokter Klinik',
    '4.1.02.004' => 'Jasa Tindakan Medis Klinik',
    '4.1.02.005' => 'Jasa Keperawatan Klinik',
    '4.1.02.006' => 'Laboratorium Klinik',
    '4.1.02.007' => 'Radiologi Klinik',
    '4.1.02.008' => 'Pelayanan Lain-lain Klinik',
    '4.1.03' => 'PENDAPATAN GAWAT DARURAT',
    '4.1.03.001' => 'Administrasi UGD',
    '4.1.03.002' => 'Jasa Pemeriksaan UGD',
    '4.1.03.003' => 'Jasa Dokter UGD',
    '4.1.03.004' => 'Jasa Tindakan Medis UGD',
    '4.1.03.005' => 'Jasa Keperawatan UGD',
    '4.1.03.006' => 'Laboratorium UGD',
    '4.1.03.007' => 'Radiologi UGD',
    '4.1.03.008' => 'Pelayanan Lain-lain UGD',
    '4.1.04' => 'PENDAPATAN RAWAT INAP',
    '4.1.04.001' => 'Administrasi Rawat Inap',
    '4.1.04.002' => 'Akomodasi Kamar Rawat Inap',
    '4.1.04.003' => 'Jasa Dokter Rawat Inap',
    '4.1.04.004' => 'Jasa Tindakan Medis Rawat Inap',
    '4.1.04.005' => 'Visite Dokter',
    '4.1.04.006' => 'Konsultasi Dokter',
    '4.1.04.007' => 'Jasa Keperawatan Rawat Inap',
    '4.1.04.008' => 'Laboratorium Rawat Inap',
    '4.1.04.009' => 'Radiologi Rawat Inap',
    '4.1.04.010' => 'Pelayanan Umum Rawat Inap',
    '4.1.04.011' => 'Pelayanan Lain-lain Rawat Inap',
    '4.1.05' => 'PENDAPATAN KAMAR OPERASI',
    '4.1.05.001' => 'Jasa Operator',
    '4.1.05.002' => 'Jasa Asisten Operator',
    '4.1.05.003' => 'Jasa Anestesi',
    '4.1.05.004' => 'Jasa Asisten Anestesi',
    '4.1.05.005' => 'Jasa Instrumen',
    '4.1.05.006' => 'Jasa Omloop',
    '4.1.05.007' => 'Recovery Room',
    '4.1.05.008' => 'Sewa Kamar Operasi',
    '4.1.05.009' => 'Sewa Alat Operasi',
    '4.1.05.010' => 'Bahan dan BMHP Operasi',
    '4.2' => 'PENDAPATAN FARMASI',
    '4.2.01' => 'PENJUALAN OBAT DAN BMHP',
    '4.2.01.001' => 'Penjualan Obat Rawat Jalan',
    '4.2.01.002' => 'Penjualan Obat Klinik',
    '4.2.01.003' => 'Penjualan Obat UGD',
    '4.2.01.004' => 'Penjualan Obat Rawat Inap',
    '4.2.01.005' => 'Penjualan Obat Resep Apotek',
    '4.2.01.006' => 'Jasa Resep dan Embalase',
    '4.3' => 'PENGURANG PENDAPATAN',
    '4.3.01' => 'RETUR PENJUALAN OBAT',
    '4.3.01.001' => 'Retur Obat Rawat Jalan/UGD',
    '4.3.01.002' => 'Retur Obat Rawat Inap',
    '4.3.01.003' => 'Retur Obat Klinik',
    '4.3.02' => 'POTONGAN PELAYANAN',
    '4.3.02.001' => 'Potongan (Subsidi) Pelayanan Rawat Jalan',
    '4.3.02.002' => 'Potongan (Subsidi) Pelayanan UGD',
    '4.3.02.003' => 'Potongan (Subsidi) Pelayanan Rawat Inap',
    '4.3.02.004' => 'Potongan (Subsidi) Pelayanan Klinik',
    '4.3.03' => 'PENYESUAIAN PENDAPATAN PENJAMIN',
    '4.3.03.001' => 'Selisih Tarif RS dengan Tarif INA-CBG/iDRG BPJS',

    '5' => 'BEBAN POKOK PELAYANAN',
    '5.1' => 'BEBAN POKOK PENJUALAN OBAT',
    '5.1.01' => 'HARGA POKOK PENJUALAN OBAT DAN BMHP',
    '5.1.01.001' => 'HPP Obat Rawat Jalan/UGD/Resep',
    '5.1.01.002' => 'HPP Obat Rawat Inap',
    '5.1.01.003' => 'HPP Obat Klinik',
    '5.1.01.004' => 'PPN Pembelian Tidak Dapat Dikreditkan',
    '5.1.01.005' => 'Kerugian Persediaan Rusak/Kedaluwarsa',
    '5.1.01.006' => 'Selisih Kurang/Kehilangan Persediaan',
    '5.1.01.901' => 'Potongan Pembelian (Diskon PBF)',
    '5.2' => 'BEBAN BAHAN MEDIS HABIS PAKAI',
    '5.2.01' => 'PEMAKAIAN OBAT DAN BMHP PER UNIT',
    '5.2.01.001' => 'Pemakaian BMHP Ruang Rawat Inap',
    '5.2.01.002' => 'Pemakaian BMHP UGD',
    '5.2.01.003' => 'Pemakaian BMHP Kamar Bersalin (VK)',
    '5.2.01.004' => 'Pemakaian BMHP Kamar Operasi',
    '5.2.01.005' => 'Pemakaian Bahan Laboratorium',
    '5.2.01.006' => 'Pemakaian Bahan Radiologi',
    '5.2.01.007' => 'Pemakaian Gas Medis (Oksigen)',
    '5.2.01.008' => 'Pemakaian BMHP Klinik',
    '5.3' => 'BEBAN JASA PELAYANAN MEDIS',
    '5.3.01' => 'JASA DAN GAJI TENAGA MEDIS',
    '5.3.01.001' => 'Gaji Dokter',
    '5.3.01.002' => 'Jasa Medis Dokter',
    '5.3.01.003' => 'Jasa Pelayanan Karyawan Medis',
    '5.3.01.004' => 'Jasa/Gaji Dokter Klinik',
    '5.4' => 'BEBAN OPERASIONAL PELAYANAN',
    '5.4.01' => 'GIZI PASIEN',
    '5.4.01.001' => 'Bahan Makanan Basah',
    '5.4.01.002' => 'Bahan Makanan Kering',
    '5.4.02' => 'PEMELIHARAAN DAN PERALATAN MEDIS',
    '5.4.02.001' => 'Pemeliharaan dan Kalibrasi Alat Medis',
    '5.4.02.002' => 'Peralatan Medis Bernilai Rendah',
    '5.4.02.003' => 'Pemeliharaan Alat Medis Klinik',
    '5.4.03' => 'OPERASIONAL UNIT PELAYANAN',
    '5.4.03.001' => 'Operasional Pelayanan Medis',
    '5.4.03.002' => 'Operasional Ruangan',
    '5.4.03.003' => 'Penunjang Medis Lainnya',

    '6' => 'BEBAN USAHA',
    '6.1' => 'BEBAN PEGAWAI',
    '6.1.01' => 'GAJI DAN TUNJANGAN',
    '6.1.01.001' => 'Gaji dan Upah Karyawan',
    '6.1.01.002' => 'Gaji Karyawan Klinik',
    '6.1.01.003' => 'Tunjangan dan Insentif',
    '6.1.01.004' => 'Tunjangan Hari Raya dan Bonus',
    '6.1.01.005' => 'Iuran BPJS Kesehatan dan Ketenagakerjaan',
    '6.1.01.006' => 'Kesejahteraan Karyawan (termasuk obat karyawan)',
    '6.1.01.007' => 'Pendidikan dan Pelatihan',
    '6.1.01.008' => 'Imbalan Pascakerja',
    '6.2' => 'BEBAN UMUM DAN ADMINISTRASI',
    '6.2.01' => 'BEBAN UMUM DAN ADMINISTRASI',
    '6.2.01.001' => 'Listrik, Air, Telepon dan Internet',
    '6.2.01.002' => 'Alat Tulis Kantor',
    '6.2.01.003' => 'Cetakan dan Formulir',
    '6.2.01.004' => 'Pemeliharaan Gedung dan Peralatan Umum',
    '6.2.01.005' => 'Peralatan Non Medis Bernilai Rendah',
    '6.2.01.006' => 'Operasional Umum',
    '6.2.01.007' => 'Administrasi Umum dan Bank',
    '6.2.01.008' => 'Operasional Umum Klinik',
    '6.2.01.009' => 'Administrasi Klinik',
    '6.2.01.010' => 'Bea Meterai',
    '6.2.01.011' => 'Pajak, Retribusi dan Perizinan',
    '6.2.01.012' => 'Kebersihan dan Keamanan',
    '6.2.01.013' => 'Perjalanan Dinas',
    '6.2.01.014' => 'Rapat dan Konsumsi',
    '6.2.01.015' => 'Asuransi',
    '6.2.01.016' => 'Sewa',
    '6.2.01.017' => 'Pemasaran dan Promosi',
    '6.2.01.018' => 'Jasa Profesional',
    '6.3' => 'BEBAN PENYUSUTAN DAN AMORTISASI',
    '6.3.01' => 'PENYUSUTAN DAN AMORTISASI',
    '6.3.01.002' => 'Penyusutan Bangunan dan Gedung',
    '6.3.01.003' => 'Penyusutan Instalasi dan Jaringan',
    '6.3.01.004' => 'Penyusutan Peralatan Medis',
    '6.3.01.005' => 'Penyusutan Peralatan Non Medis',
    '6.3.01.006' => 'Penyusutan Kendaraan',
    '6.3.01.007' => 'Penyusutan Komputer dan Perangkat TI',
    '6.3.01.009' => 'Penyusutan - Saldo SIRUS Belum Dirinci',
    '6.3.01.101' => 'Amortisasi Aset Takberwujud',
    '6.4' => 'BEBAN PENURUNAN NILAI',
    '6.4.01' => 'PENURUNAN NILAI PIUTANG',
    '6.4.01.001' => 'Beban Kerugian Penurunan Nilai Piutang',

    '7' => 'PENDAPATAN LAIN-LAIN',
    '7.1' => 'PENDAPATAN LAIN-LAIN',
    '7.1.01' => 'PENDAPATAN DI LUAR USAHA',
    '7.1.01.001' => 'Pendapatan Bunga/Bagi Hasil Bank',
    '7.1.01.002' => 'Pendapatan Sewa (Kantin, Parkir, Ruang)',
    '7.1.01.003' => 'Laba Pelepasan Aset Tetap',
    '7.1.01.004' => 'Pendapatan Lain-lain',

    '8' => 'BEBAN LAIN-LAIN',
    '8.1' => 'BEBAN LAIN-LAIN',
    '8.1.01' => 'BEBAN DI LUAR USAHA',
    '8.1.01.001' => 'Beban Bunga Pinjaman',
    '8.1.01.002' => 'Rugi Pelepasan Aset Tetap',
    '8.1.01.003' => 'Sumbangan dan Donasi',
    '8.1.01.004' => 'Beban Lain-lain',

    '9' => 'BEBAN PAJAK PENGHASILAN',
    '9.1' => 'BEBAN PAJAK PENGHASILAN',
    '9.1.01' => 'PAJAK PENGHASILAN BADAN',
    '9.1.01.001' => 'Beban Pajak Kini',
    '9.1.01.002' => 'Beban (Manfaat) Pajak Tangguhan',
];

// Akun kontra / beda saldo normal dari kelompoknya (berlaku juga ke anaknya bila header)
$saldoNormalKhusus = [
    '1.1.02.901' => 'K', '1.2.02' => 'K', '1.2.03.901' => 'K',
    '4.3' => 'D', '5.1.01.901' => 'K',
];
$akunKas = ['1.1.01'];                                   // awalan akun kas/bank (dasar arus kas)
$arusKas = [                                             // awalan -> kategori saat jadi lawan kas (sisanya O)
    '1.2' => 'I',
    '2.1.06' => 'F', '2.2.01' => 'F', '2.1.07.001' => 'F', '3.1.01' => 'F', '3.1.02.003' => 'F',
];
$kontrolHp = [
    '1.1.02.001' => 'P', '1.1.02.002' => 'P', '1.1.02.003' => 'P', '1.1.02.004' => 'P',
    '1.1.02.005' => 'P', '1.1.02.006' => 'P', '1.1.02.007' => 'P', '1.1.03.001' => 'P',
    '2.1.01.001' => 'H', '2.1.01.002' => 'H', '2.1.02.001' => 'H',
];

// -----------------------------------------------------------------------------
// Peta akun SIRUS (acmst_accounts.acc_id) -> akun SIKEU. Semua 219 akun, termasuk yang nonaktif.
// -----------------------------------------------------------------------------
$peta = [
    // Kas & bank
    '1.1A01' => '1.1.01.004', '1.1A01A' => '1.1.01.001', '1.1A01B' => '1.1.01.002', '1.1A01C' => '1.1.01.003',
    '1.1A01D' => '1.1.01.006', '1.1A01E' => '1.1.01.006', '1.1A01F' => '1.1.01.006', '1.1A01G' => '1.1.01.004',
    '1.1A02' => '1.1.01.101',
    // Piutang
    '1.1A03' => '1.1.03.001', '1.1A04' => '1.1.02.004',
    '4.1AA' => '1.1.02.001', '4.1AKA' => '1.1.02.002', '4.1BB' => '1.1.02.003', '4.1CA' => '1.1.02.004',
    '4.1GA' => '1.1.02.005',
    // Peralatan (di SIRUS salah masuk aset lancar) -> aset tetap
    '1.1A05' => '1.2.01.005', '1.1A06' => '1.1.04.004', '1.1A07' => '1.2.01.005', '1.1A08' => '1.2.01.004',
    // Persediaan
    '1.1A09' => '1.1.04.002', '1.1A10' => '1.1.04.002', '1.1A11' => '1.1.04.001', '1.1A11.1' => '1.1.04.004',
    '1.1A12' => '1.1.04.003', '5.1F03B1' => '1.1.04.005', '5.1F041' => '1.1.04.006', '5.1G21' => '1.1.04.007',
    '1.1A13' => '1.1.05.001',
    // Aset tetap
    '1.1B01' => '1.2.01.002', '1.1B02' => '1.2.01.001', '1.1B03' => '1.2.01.009', '201' => '1.2.01.009',
    '1.1B04' => '1.2.01.006', '1.1B05' => '1.2.02.009',
    // Liabilitas
    '2.1A01' => '2.1.01.002', '2.1A02' => '2.1.06.001', '2.1A03' => '2.1.01.001', '2.1A04' => '2.1.05.001',
    '4.1CA1' => '2.1.05.001',
    // Ekuitas
    '3.1A01' => '3.1.01.001', 'L00' => '3.1.01.001',
    '3.1A02' => '3.1.02.002', 'L01' => '3.1.02.002',
    '3.1A03' => '3.1.02.001', 'L02' => '3.1.02.001',
    // Pendapatan Rawat Jalan (termasuk induk 4.1A dan transfer RJ ke RI)
    '4.1A' => '4.1.01.008',
    '4.1A01' => '4.1.01.001', '4.1A01.1' => '4.1.01.001', '4.1A02' => '4.1.01.002', '4.1A03' => '4.1.01.003',
    '4.1A04' => '4.1.01.004', '4.1A05' => '4.1.01.005', '4.1A06' => '4.2.01.001', '4.1A07' => '4.1.01.006',
    '4.1A08' => '4.1.01.007', '4.1A09' => '4.1.01.008',
    '4.1D' => '4.1.01.008',
    '4.1D01' => '4.1.01.001', '4.1D01.1' => '4.1.01.001', '4.1D02' => '4.1.01.002', '4.1D03' => '4.1.01.003',
    '4.1D04' => '4.1.01.004', '4.1D05' => '4.1.01.005', '4.1D06' => '4.2.01.001', '4.1D07' => '4.1.01.006',
    '4.1D08' => '4.1.01.007', '4.1D09' => '4.1.01.008',
    // Pendapatan Klinik
    '4.1AK' => '4.1.02.008',
    '4.1A01K' => '4.1.02.001', '4.1A01.1K' => '4.1.02.001', '4.1A02K' => '4.1.02.002', '4.1A03K' => '4.1.02.003',
    '4.1A04K' => '4.1.02.004', '4.1A05K' => '4.1.02.005', '4.1A06K' => '4.2.01.002', '4.1A07K' => '4.1.02.006',
    '4.1A08K' => '4.1.02.007', '4.1A09K' => '4.1.02.008',
    // Pendapatan UGD (termasuk transfer UGD dan transfer RJ di UGD)
    '4.1B' => '4.1.03.008',
    '4.1B01' => '4.1.03.001', '4.1B01.1' => '4.1.03.001', '4.1B02' => '4.1.03.002', '4.1B03' => '4.1.03.003',
    '4.1B04' => '4.1.03.004', '4.1B05' => '4.1.03.005', '4.1B06' => '4.2.01.003', '4.1B07' => '4.1.03.006',
    '4.1B08' => '4.1.03.007', '4.1B09' => '4.1.03.008',
    '4.1E' => '4.1.03.008', '4.1EA' => '4.1.01.008',
    '4.1E01' => '4.1.03.001', '4.1E01.1' => '4.1.03.001', '4.1E02' => '4.1.03.002', '4.1E03' => '4.1.03.003',
    '4.1E04' => '4.1.03.004', '4.1E05' => '4.1.03.005', '4.1E06' => '4.2.01.003', '4.1E07' => '4.1.03.006',
    '4.1E08' => '4.1.03.007', '4.1E09' => '4.1.03.008',
    '4.1E01A' => '4.1.01.001', '4.1E02A' => '4.1.01.002', '4.1E03A' => '4.1.01.003', '4.1E04A' => '4.1.01.004',
    '4.1E05A' => '4.1.01.005', '4.1E06A' => '4.2.01.001', '4.1E07A' => '4.1.01.006', '4.1E08A' => '4.1.01.007',
    '4.1E09A' => '4.1.01.008',
    // Pendapatan Rawat Inap
    '4.1C' => '4.1.04.011',
    '4.1C01' => '4.1.04.001', '4.1C02' => '4.1.04.001', '4.1C03' => '4.1.04.003', '4.1C04' => '4.1.04.004',
    '4.1C05' => '4.1.04.005', '4.1C06' => '4.1.04.006', '4.1C07' => '4.1.04.008', '4.1C08' => '4.1.04.009',
    '4.1C09' => '4.2.01.004', '4.1C10' => '4.1.04.007', '4.1C11' => '4.1.04.002', '4.1C12' => '4.1.04.010',
    '4.1C13' => '4.1.04.011',
    // Kamar Operasi
    '4.1F' => '4.1.05.008',
    '4.1F01' => '4.1.05.001', '4.1F02' => '4.1.05.002', '4.1F03' => '4.1.05.003', '4.1F04' => '4.1.05.003',
    '4.1F05' => '4.1.05.004', '4.1F06' => '4.1.05.005', '4.1F07' => '4.1.05.006', '4.1F08' => '4.1.05.007',
    '4.1F09' => '4.1.05.008', '4.1F10' => '4.1.05.010', '4.1F11' => '4.1.05.009',
    // Farmasi (resep)
    '4.1G' => '4.2.01.005', '4.1G1' => '4.2.01.005', '4.1G2' => '4.2.01.006',
    // Pendapatan lain & penyesuaian
    '4.1H' => '7.1.01.001', '4.1I' => '7.1.01.004', '4.1I1' => '4.3.03.001', '5.1K6' => '4.3.03.001',
    '4.1J' => '5.1.01.901',
    // Retur & potongan (subsidi)
    '4.1AA1' => '4.3.01.001', '4.1AK1' => '4.3.01.003',
    '5.1J' => '4.3.01.002', '5.1J1' => '4.3.01.002', '5.1J2' => '4.3.01.001', '5.1J3' => '4.3.01.003',
    '5.1A' => '4.3.02.001', '5.1B' => '4.3.02.002', '5.1C' => '4.3.02.003',
    '5.1I' => '4.3.02.001', '5.1I1' => '4.3.02.001', '5.1I2' => '4.3.02.002', '5.1I3' => '4.3.02.003',
    '5.1I4' => '4.3.02.004',
    // HPP & persediaan
    '5.1K' => '5.1.01.001', '5.1K1' => '5.1.01.001', '5.1K2' => '5.1.01.002', '5.1K3' => '5.1.01.003',
    '5.1K4' => '5.2.01.001', '5.1L2' => '5.1.01.004',
    '5.1E11' => '5.1.01.005', '5.1E12' => '5.1.01.006',
    // Pemakaian BMHP per unit
    '5.1E' => '5.4.03.003',
    '5.1E02' => '5.2.01.001', '5.1E07' => '5.2.01.001', '5.1E08' => '5.2.01.001', '5.1E09' => '5.2.01.001',
    '5.1E03' => '5.2.01.005', '5.1E04' => '5.2.01.002', '5.1E05' => '5.2.01.003', '5.1E06' => '5.2.01.004',
    '5.1E13' => '5.2.01.008',
    '5.1F08' => '5.4.03.003', '5.1F08A' => '5.2.01.005', '5.1F08B' => '5.2.01.006', '5.1F08C' => '5.2.01.007',
    // Jasa & gaji tenaga medis
    '5.1F02' => '5.3.01.001', '5.1F02.A' => '5.3.01.004', '5.1F02A' => '5.3.01.002',
    '5.1F02A1' => '5.3.01.003', '5.1F02A2' => '5.3.01.002',
    // Operasional pelayanan
    '5.1F03' => '5.4.01.001', '5.1F03A' => '5.4.01.001', '5.1F03B' => '5.4.01.002',
    '5.1E01' => '5.4.02.001', '5.1F07' => '5.4.02.002', '5.1G' => '5.4.02.001', '5.1G1' => '5.4.02.001',
    '5.1G1.1' => '5.4.02.003', '5.1H1A' => '5.4.03.001', '5.1H1C' => '5.4.03.002',
    // Beban pegawai
    '5.1F01' => '6.1.01.001', '5.1F01.A' => '6.1.01.002', '5.1E10' => '6.1.01.006',
    // Beban umum & administrasi
    '5.1F' => '6.2.01.006', '5.1F04' => '6.2.01.002', '5.1F05' => '6.2.01.001', '5.1F06' => '6.2.01.005',
    '5.1F10' => '6.2.01.011', '5.1F11' => '6.2.01.006', '5.1F12' => '6.2.01.003', '5.1F13' => '6.2.01.006',
    '5.1F14' => '6.2.01.007', '5.1F15' => '6.2.01.009', '5.1F16' => '6.2.01.008',
    '5.1G1.2' => '6.2.01.008', '5.1G2' => '6.2.01.004',
    '5.1H' => '6.2.01.007', '5.1H1' => '6.2.01.006', '5.1H1B' => '6.2.01.006',
    '5.1L1' => '6.2.01.010',
    // Penyusutan, piutang tak tertagih, bunga
    '5.1K5' => '6.3.01.009', '5.1F09' => '6.4.01.001', '5.1D' => '8.1.01.001',
];

// -----------------------------------------------------------------------------
// Turunkan atribut & tulis SQL
// -----------------------------------------------------------------------------
function awalan(string $kode, array $daftar): ?string
{
    $cocok = null;
    foreach ($daftar as $awal => $nilai) {
        if ($kode === $awal || str_starts_with($kode, $awal . '.')) {
            if ($cocok === null || strlen($awal) > strlen($cocok)) {
                $cocok = $awal;
            }
        }
    }
    return $cocok;
}
function q(?string $nilai): string
{
    return $nilai === null ? 'NULL' : "'" . str_replace("'", "''", $nilai) . "'";
}

$galat = [];
foreach ($akun as $kode => $nama) {
    if (!mb_check_encoding($nama, 'ASCII')) {
        $galat[] = "nama bukan ASCII: $kode";
    }
    if (strlen($nama) > 150) {
        $galat[] = "nama > 150: $kode";
    }
    $bagian = explode('.', $kode);
    if (count($bagian) > 1 && !isset($akun[implode('.', array_slice($bagian, 0, -1))])) {
        $galat[] = "induk tak ada: $kode";
    }
}
$adaAnak = [];
foreach (array_keys($akun) as $kode) {
    $bagian = explode('.', $kode);
    if (count($bagian) > 1) {
        $adaAnak[implode('.', array_slice($bagian, 0, -1))] = true;
    }
}
foreach ($peta as $sirus => $tujuan) {
    if (!isset($akun[$tujuan]) || isset($adaAnak[$tujuan])) {
        $galat[] = "peta $sirus -> $tujuan bukan akun detail";
    }
}

// Nama akun SIRUS diambil dari dump (php ... > daftar) supaya kolom nama_sirus terisi; argumen 1 = file TSV acc_id<TAB>nama
$namaSirus = [];
if (isset($argv[1]) && is_file($argv[1])) {
    foreach (file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $baris) {
        [$id, $nm] = array_pad(explode("\t", $baris, 2), 2, '');
        $namaSirus[$id] = $nm;
    }
    foreach (array_diff(array_keys($namaSirus), array_keys($peta)) as $belum) {
        $galat[] = "akun SIRUS belum dipetakan: $belum";
    }
    foreach (array_diff(array_keys($peta), array_keys($namaSirus)) as $asing) {
        $galat[] = "peta berisi akun yang tak ada di SIRUS: $asing";
    }
}
if ($galat) {
    fwrite(STDERR, implode("\n", $galat) . "\n");
    exit(1);
}

$dir = dirname(__DIR__);
$jumlahDetail = count($akun) - count($adaAnak);

// ---------- 02_bagan_akun.sql
$sql = [];
$sql[] = <<<'SQL'
-- =============================================================================
-- SIKEU - 02. Bagan akun standar (Chart of Accounts) rumah sakit
-- Jalankan sebagai user SIKEU, SESUDAH 01_struktur_pembukuan.sql. DIBUAT OTOMATIS oleh
-- database/sql/tools/bagan_akun.php - ubah bagan di sana lalu jalankan ulang generatornya.
--
-- ACUAN
--   * SAK EP (Standar Akuntansi Keuangan Entitas Privat, berlaku 1 Jan 2025, pengganti SAK ETAP):
--     penyajian aset/liabilitas lancar vs tidak lancar, istilah "liabilitas", akun kontra
--     (akumulasi penyusutan, cadangan kerugian piutang), pendapatan diukur sebesar imbalan yang
--     berhak diterima (selisih tarif INA-CBG/iDRG = penyesuaian pendapatan, bukan laba/rugi lain),
--     diskon pembelian mengurangi biaya perolehan persediaan, imbalan kerja, pajak kini & tangguhan.
--   * Pola pedoman akuntansi rumah sakit: pendapatan per instalasi pelayanan (RJ, Klinik, UGD, RI,
--     Kamar Operasi, Farmasi), beban pokok pelayanan (HPP obat, BMHP, jasa medis) dipisah dari
--     beban umum & administrasi -> laporan laba rugi bisa menampilkan LABA KOTOR PELAYANAN.
--   * Bila badan hukumnya YAYASAN (nirlaba), penyajian memakai ISAK 35: cukup ganti nama kelompok 3
--     menjadi ASET NETO dan rincian 3.1.xx menjadi "tanpa/dengan pembatasan"; akun lain tetap.
--
-- KODE  K.S.GG.DDD   K kelompok, S subkelompok, GG golongan, DDD rincian. Contoh 4.1.04.002.
--   Tingkat 1-3 = Header (H, tidak boleh dijurnal), tingkat 4 = Detail (D).
--   Rincian 9xx = akun kontra / pengurang di golongannya.
--   1.1.09.001 Akun Penampung: tempat sementara pos yang belum jelas; WAJIB nol saat periode dikunci.
--
-- Bagian: A periksa - B kelompok akun - C bagan akun - D verifikasi - E rollback
-- =============================================================================
SET DEFINE OFF

-- A. PERIKSA - hasil harus 0 baris akun. Bila sudah terisi, lihat bagian E dulu.
SELECT COUNT(*) AS jumlah_akun FROM sikeumst_akuns;

-- B. KELOMPOK AKUN (9 kelompok, susunan laporan SAK EP)
SQL;
foreach ($kelompok as $id => [$nama, $normal, $lap]) {
    $sql[] = "MERGE INTO sikeumst_akun_kelompoks t USING (SELECT " . q($id) . " id FROM dual) s ON (t.kelompok_id = s.id)\n"
        . "  WHEN MATCHED THEN UPDATE SET t.nama = " . q($nama) . ", t.saldo_normal = " . q($normal) . ", t.jenis_laporan = " . q($lap) . ", t.urut = $id\n"
        . "  WHEN NOT MATCHED THEN INSERT (kelompok_id, nama, saldo_normal, jenis_laporan, urut) VALUES (" . q($id) . ", " . q($nama) . ", " . q($normal) . ", " . q($lap) . ", $id);";
}
$sql[] = "\n-- C. BAGAN AKUN (" . count($akun) . " akun: " . count($adaAnak) . " header, $jumlahDetail detail). Induk selalu sebelum anak.";
$kolom = 'akun_id, nama, kelompok_id, induk_id, tingkat, tipe, saldo_normal, kas_status, kategori_arus_kas, kontrol_hp, created_at';
foreach ($akun as $kode => $nama) {
    $bagian = explode('.', $kode);
    $induk = count($bagian) > 1 ? implode('.', array_slice($bagian, 0, -1)) : null;
    $tipe = isset($adaAnak[$kode]) ? 'H' : 'D';
    $normalKhusus = awalan($kode, $saldoNormalKhusus);
    $normal = $normalKhusus !== null ? $saldoNormalKhusus[$normalKhusus] : $kelompok[$bagian[0]][1];
    $kas = awalan($kode, array_flip($akunKas)) !== null && $tipe === 'D' ? '1' : '0';
    $arus = null;
    if ($tipe === 'D' && $kas === '0') {
        $cocok = awalan($kode, $arusKas);
        $arus = $cocok !== null ? $arusKas[$cocok] : 'O';
    }
    $hp = $kontrolHp[$kode] ?? null;
    $sql[] = "INSERT INTO sikeumst_akuns ($kolom) VALUES ("
        . implode(', ', [q($kode), q($nama), q($bagian[0]), q($induk), count($bagian), q($tipe), q($normal), q($kas), q($arus), q($hp), 'SYSTIMESTAMP']) . ');';
}
$sql[] = <<<SQL

COMMIT;

-- D. VERIFIKASI
--   D1 jumlah per kelompok & tipe - total harus {$jumlahDetail} detail
SELECT kelompok_id, tipe, COUNT(*) jumlah FROM sikeumst_akuns GROUP BY kelompok_id, tipe ORDER BY 1, 2;
--   D2 header tanpa anak / detail yang punya anak - harus 0 baris
SELECT a.akun_id, a.tipe FROM sikeumst_akuns a
 WHERE (a.tipe = 'H' AND NOT EXISTS (SELECT 1 FROM sikeumst_akuns c WHERE c.induk_id = a.akun_id))
    OR (a.tipe = 'D' AND EXISTS (SELECT 1 FROM sikeumst_akuns c WHERE c.induk_id = a.akun_id));
--   D3 akun kas/bank
SELECT akun_id, nama FROM sikeumst_akuns WHERE kas_status = '1' ORDER BY akun_id;

-- E. ROLLBACK (hanya bila BELUM ada jurnal; FK menolak bila akun sudah dipakai)
-- DELETE FROM sikeumst_peta_akun_sirus;
-- DELETE FROM sikeumst_akuns WHERE tingkat = 4;
-- DELETE FROM sikeumst_akuns WHERE tingkat = 3;
-- DELETE FROM sikeumst_akuns WHERE tingkat = 2;
-- DELETE FROM sikeumst_akuns WHERE tingkat = 1;
-- COMMIT;
SQL;
file_put_contents("$dir/02_bagan_akun.sql", implode("\n", $sql) . "\n");

// ---------- 03_peta_akun_sirus.sql
$sql = [];
$sql[] = <<<'SQL'
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

SQL;
foreach ($peta as $sirus => $tujuan) {
    $sql[] = "INSERT INTO sikeumst_peta_akun_sirus (acc_id_sirus, nama_sirus, akun_id, created_at) VALUES ("
        . q((string) $sirus) . ', ' . q($namaSirus[$sirus] ?? null) . ', ' . q($tujuan) . ', SYSTIMESTAMP);';
}
$jumlahPeta = count($peta);
$sql[] = <<<SQL

COMMIT;

-- VERIFIKASI - harus {$jumlahPeta} baris; tujuan selalu akun Detail
SELECT COUNT(*) jumlah_peta FROM sikeumst_peta_akun_sirus;
SELECT p.acc_id_sirus, p.akun_id FROM sikeumst_peta_akun_sirus p JOIN sikeumst_akuns a ON a.akun_id = p.akun_id
 WHERE a.tipe <> 'D';

-- ROLLBACK
-- DELETE FROM sikeumst_peta_akun_sirus; COMMIT;
SQL;
file_put_contents("$dir/03_peta_akun_sirus.sql", implode("\n", $sql) . "\n");

echo count($akun) . " akun (" . count($adaAnak) . " H, $jumlahDetail D), " . count($peta) . " peta" . ($namaSirus ? ', nama SIRUS ' . count($namaSirus) : ', TANPA nama SIRUS') . "\n";

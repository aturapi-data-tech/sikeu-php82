---
name: keuangan-rs
description: Panduan akuntansi & laporan keuangan rumah sakit di SIKEU — standar (SAK EP / ISAK 35), set laporan lengkap, kebijakan akuntansi khas RS (pendapatan rawat inap, BPJS/INA-CBG, jasa medis, persediaan farmasi, aset & penyusutan, imbalan kerja, pajak), alur tutup bulan, pemeriksaan data SIRUS, dan daftar celah yang belum dibangun. WAJIB dibaca sebelum membuat / mengubah laporan keuangan (Neraca, Laba Rugi, Arus Kas, Perubahan Ekuitas, CALK), jurnal penyesuaian, saldo awal, tutup buku, bagan akun, peta akun SIRUS, atau saat angka laporan terlihat janggal.
---

# Keuangan RS di SIKEU

Skill ini = "akuntan pendamping" untuk SIKEU. Isinya standar yang dipakai, cara laporan RS disusun, hal yang
sering salah di data SIRUS, dan celah yang masih harus dibangun. Bukan pengganti akuntan publik: kebijakan
akuntansi akhir (umur aset, cadangan piutang, imbalan kerja, pajak tangguhan) **diputuskan manajemen & dikonfirmasi
ke KAP** — SIKEU menyediakan tempat dan angkanya.

Urutan prioritas: permintaan user → skill ini → `CLAUDE.md` SIKEU → skill teknis (`oracle-quirks`, `sikeu-ui-ux`).

Glosarium untuk pemakai (istilah + contoh RS) ada di layar **Panduan › Istilah Keuangan**, isinya di
`App\Support\GlosariumKeuangan` — istilah baru di laporan/layar ikut ditambahkan di sana.

---

## 1. Standar

| Hal | Acuan | Catatan untuk SIKEU |
|---|---|---|
| Kerangka utama | **SAK EP** (Entitas Privat, berlaku 1 Jan 2025, pengganti SAK ETAP; diturunkan dari IFRS for SMEs) | Bagan akun `02_bagan_akun.sql` disusun untuk ini |
| Badan hukum **yayasan / nirlaba** | Penyajian nirlaba (ISAK 35 untuk SAK umum; pastikan padanannya di SAK EP dengan KAP) | Cukup ganti kelompok 3 jadi **ASET NETO** (tanpa / dengan pembatasan) di Data Induk › Kelompok Akun; akun lain tetap |
| Badan hukum **PT** | SAK EP penuh | Ekuitas = modal + saldo laba |
| Pajak | UU PPh (tarif badan 22%), UU HPP: jasa pelayanan kesehatan medis dibebaskan PPN → PPN masukan umumnya **tidak dapat dikreditkan** (masuk biaya perolehan) | Akun `5.1.01.004 PPN Pembelian Tidak Dapat Dikreditkan` |
| Penyusutan fiskal | PMK 96/PMK.03/2009 (kelompok 1–4, bangunan) | **Fiskal ≠ komersial** — lihat §4.6 |
| Pesangon | PP 35/2021 | Liabilitas imbalan pascakerja `2.2.02.001` |

**Badan hukum RS ini = PT (dikonfirmasi user 2026-11-19)** → SAK EP penuh, ekuitas = modal + saldo laba, laporan
pendamping = Laporan Perubahan Ekuitas (bukan Aset Neto).

---

## 2. Set laporan keuangan lengkap (SAK EP)

| # | Laporan | Sumber angka di SIKEU | Status |
|---|---|---|---|
| 1 | Laporan Posisi Keuangan (Neraca) | saldo akhir akun kelompok N (`jenis_laporan='N'`) + laba berjalan | **ADA** — `LaporanKeuangan::neraca`, layar Laporan Keuangan › Neraca, template DDL 07 (kode baris `TA` & `TLE` dipakai cek Aset = Liabilitas + Ekuitas) |
| 2 | Laporan Laba Rugi (dan Penghasilan Komprehensif Lain) | mutasi akun kelompok L periode & YTD | **ADA** — `LaporanKeuangan::labaRugi`, layar Laporan Keuangan › Laba Rugi, template DDL 05 |
| 3 | Laporan Perubahan Ekuitas (yayasan: Perubahan Aset Neto) | saldo awal ekuitas + laba bersih + setoran/dividen | BELUM — **template belum ada di `sikeumst_lap_hdrs`** |
| 4 | Laporan Arus Kas | metode langsung: baris jurnal akun kas × `akun_lawan_id` × `kategori_arus_kas` lawan | BELUM (struktur siap) |
| 5 | Catatan atas Laporan Keuangan (CALK) | kebijakan akuntansi + rincian pos (aging piutang, aset tetap, persediaan) | BELUM — teks di pengaturan + tabel rincian |

Laporan **manajemen** (bukan wajib SAK, tapi paling dipakai direksi RS): Laba Rugi per instalasi (RJ, UGD, RI, OK,
Farmasi, Penunjang), rasio keuangan (§7), anggaran vs realisasi.

### Aturan penyusunan (berlaku untuk semua laporan)
1. **Susunan baris disimpan di template** (`sikeumst_lap_hdrs → _lap_dtls → _lap_akuns`), diubah lewat form — **jangan
   hardcode** urutan / kode akun di kode (lihat CLAUDE.md §7). Baris menunjuk **header** (`termasuk_turunan='1'`) supaya
   akun baru otomatis ikut.
2. **Akun yatim = galat**: setiap akun Detail L harus masuk tepat satu baris Laba Rugi, setiap akun Detail N tepat satu
   baris Neraca. Tampilkan peringatan + daftar akunnya bila ada yang tak tertampung atau dobel.
3. Angka hanya dari **jurnal status P** (`App\Support\BukuBesar`). Jangan membaca snapshot langsung untuk laporan.
4. Tanda: simpan bertanda (debit +, kredit −); balik saat tampil sesuai `saldo_normal` baris (`tanda` di `lap_dtls`).
5. Pemeriksaan wajib di layar laporan: Neraca **Aset = Liabilitas + Ekuitas**; Laba Rugi bersih **= laba berjalan di
   Neraca**; Arus Kas **kenaikan kas = saldo akhir − saldo awal akun kas**. Selisih ≠ 0 → badge merah, jangan sembunyikan.
6. Kolom standar: periode berjalan + YTD (Laba Rugi), periode ini vs akhir tahun lalu (Neraca). Pembanding tahun lalu
   baru bermakna setelah ada data tahun lalu di SIKEU.

---

## 3. Alur data (yang sudah ada)

```
SIRUS ─(API /api/sikeu, per cabang)─► Snapshot versi (beku) ─(Sahkan & Posting)─► Jurnal SIKEU (sumber SIRUS)
                                                                                     │ + jurnal MANUAL / SUSUT / HPP /
                                                                                     │   SALDO_AWAL / PENUTUP (belum)
                                                                                     ▼
                                               Neraca Saldo · Buku Besar · (Laporan) ─► Kunci periode
```
Kode: `SnapshotJurnal`, `BukuBesar`, `BaganAkun`, `FormatKodeAkun`. Detail teknis di `CLAUDE.md` SIKEU §4–8.

---

## 4. Kebijakan akuntansi khas RS — dan kondisi data SIRUS

### 4.1 Pendapatan rawat inap (CELAH BESAR)
- SAK EP: pendapatan jasa diakui **saat jasa diberikan** (sesuai tingkat penyelesaian), bukan saat pasien pulang/bayar.
- **SIRUS menjurnal pendapatan RI pada `exit_date` pasien berstatus pulang (`ri_status='P'`)** (lihat cabang `RI ...` di
  `JurnalCabang.php` SIRUS). Pasien yang **masih dirawat di akhir bulan** belum menghasilkan pendapatan bulan itu, dan
  pasien pulang 2 Agustus yang dirawat sejak 25 Juli seluruh pendapatannya jatuh di Agustus.
- Perbaikan yang disarankan: **jurnal penyesuaian akhir bulan "Pendapatan RI belum ditagih"** (D `1.1.02.004`-unbilled /
  K pendapatan RI) dari biaya berjalan pasien yang masih dirawat per tanggal cutoff, dibalik awal bulan berikutnya.
  Datanya ada di SIRUS (rincian biaya RI berjalan) → butuh endpoint API baru `ri-berjalan/{tanggal}`.
- Materialitas: cek dulu — Σ biaya pasien dirawat per akhir bulan dibanding pendapatan RI sebulan.

### 4.2 BPJS / INA-CBG / iDRG (CELAH BESAR)
- Pendapatan pasien JKN = **tarif klaim yang berhak diterima** (INA-CBG/iDRG), bukan tarif RS. Selisih tarif RS vs klaim
  = penyesuaian pendapatan (`4.3.03.001`), bukan laba/rugi lain-lain.
- **Akun SIRUS 4.1I1 Keuntungan / 5.1K6 Kerugian Layanan BPJS tidak dipakai cabang jurnal mana pun** (tidak muncul di
  snapshot Juli 2026). Akibatnya tagihan pasien BPJS tercatat sebesar tarif RS di "Pendapatan ... Terutang" (→ piutang
  pasien di SIKEU) dan selisihnya **menggantung di piutang**. Gejala: `4.1AA` RJ Terutang Juli D 474 jt vs K 91 jt.
- Piutang BPJS sebaiknya dipisah per **status klaim**: belum diajukan · diajukan · pending/dispute · disetujui · dibayar.
  Klaim pending/dispute lama → dasar cadangan kerugian.
- Perbaikan: (a) telusuri di SIRUS bagaimana pembayaran klaim BPJS dijurnal (akun apa melunasi piutang terutang);
  (b) endpoint API klaim (nilai INA-CBG/iDRG + status per SEP) → jurnal penyesuaian selisih tarif & reklasifikasi
  piutang pasien → `1.1.02.006 Piutang Klaim BPJS`. Data klaim ada di modul iDRG/E-Klaim SIRUS.

### 4.3 Jasa medis dokter
- Pendapatan jasa dokter **gross** (RS mengendalikan layanan) + **beban jasa medis** + **utang jasa medis** saat jasa
  diberikan — bukan saat honor dibayar.
- Honor dokter kini dihitung di **SIAP** (penggajian). Perlu jurnal bulanan: D `5.3.01.002` Jasa Medis / K `2.1.02.001`
  Utang Jasa Medis dari rekap SIAP; pembayaran SIAP melunasi utangnya. Plus PPh 21 dokter → `2.1.03.001`.
- Tanpa ini, Laba Kotor Pelayanan **terlalu tinggi** (pendapatan jasa dokter masuk, bebannya hanya sebagian lewat kas).

### 4.4 Persediaan farmasi & HPP
- Metode SIRUS: perpetual, HPP per keluar (cabang `LABARUGI`/HPP di katalog jurnal). Nilai = harga pokok master.
- Periksa tiap bulan: saldo akun persediaan SIKEU = nilai stok SIRUS (kartu stok × HPP) per lokasi. Selisih = opname
  (`5.1.01.006`) atau salah HPP.
- Obat rusak/kedaluwarsa → `5.1.01.005` (penurunan ke nilai realisasi neto).
- Diskon PBF mengurangi biaya perolehan → `5.1.01.901` (kontra HPP), bukan pendapatan.
- Penerimaan **non-medis belum dijurnal SIRUS** (pembayarannya sudah) → utang non-medis terdebit tanpa kredit.
  Jangan jurnal historis RCV NON begitu saja (2.605 nota lama tak pernah ditutup) — putuskan saldo awal dulu.

### 4.5 Piutang
- Aging per mitra (umum, BPJS, asuransi, perusahaan) → dasar **cadangan kerugian penurunan nilai** (`1.1.02.901` /
  beban `6.4.01.001`). Kebijakan persentase per umur = keputusan manajemen.
- Uang muka/deposit pasien (`2.1.05.001`) adalah **liabilitas** sampai tagihan final — jangan dinetokan ke pendapatan.

### 4.6 Aset tetap & penyusutan
- **Komersial (SAK) ≠ fiskal (PMK 96/2009)**. Laporan keuangan memakai umur **ekonomis** (kebijakan RS, mis. alat
  medis 5–10 th, gedung 20–40 th); umur fiskal hanya untuk SPT → selisihnya = beda temporer (pajak tangguhan).
- `sikeumst_aset_kategoris` saat ini berisi kelompok **fiskal** PMK → perlu kolom/umur **komersial** terpisah, atau
  kategori berbasis jenis aset (alat medis, kendaraan, gedung…) yang masing-masing menyimpan umur komersial & kelompok fiskal.
- Batas kapitalisasi (mis. ≥ Rp 1 jt & umur > 1 th) → di bawahnya beban `5.4.02.002` / `6.2.01.005`.
- SIRUS: akumulasi & beban penyusutan satu akun → di SIKEU masuk "Belum Dirinci" (`1.2.02.009`, `6.3.01.009`); rinci
  lewat jurnal reklasifikasi setelah register aset terisi.

### 4.7 Imbalan kerja
- Liabilitas pesangon/pensiun (PP 35/2021) diakui bertahap → `2.2.02.001` / `6.1.01.008`. SAK EP membolehkan
  penyederhanaan; bila material, pakai aktuaria.

### 4.8 Pajak
- Utang PPh 21 (karyawan & dokter), PPh 23 (jasa vendor), PPh 4(2) (sewa), PPh 25/29 → akun `2.1.03.x`.
- Beban pajak kini & tangguhan (`9.1.01.x`) dihitung saat tutup tahun dari rekonsiliasi fiskal.

---

## 5. Alur tutup bulan (checklist)

Layar: **Pembukuan › Tarik Data SIRUS** (satu-satunya tempat menarik data SIRUS). Panduan untuk pemakai yang sama isinya
ada di **Panduan › Cara Pakai SIKEU** — ubah keduanya bersamaan.

1. **Tarik dari SIRUS** (per bulan, atau *Tarik & Sahkan Setahun*) → bila sudah pernah ditarik, kolom *Versi Disahkan*
   menandai tarikan terbaru **sama / berbeda**; berbeda → **Bandingkan Versi** (transaksi berubah sesudah cutoff = temuan).
2. **Peta Akun SIRUS** status "Perlu perhatian" = 0 (pengesahan ditolak bila ada akun SIRUS belum dipetakan).
3. **Sahkan & Posting** versi final.
4. **Neraca Saldo**: D = K; tidak ada saldo merah bertanda peringatan yang tak bisa dijelaskan (piutang/persediaan kredit, kas
   kredit, utang debit) — badge "saldo berlawanan" untuk menyaringnya.
5. **Rekonsiliasi**: kas fisik & bank (rekening koran) · persediaan vs kartu stok SIRUS · piutang per mitra vs akun
   kontrol · utang supplier per PBF vs akun kontrol (temuan lama: saldo awal utang PBF 2026 akun 947,4 jt vs per
   supplier 753,8 jt) · uang muka pasien vs deposit pasien yang masih dirawat.
6. **Jurnal penyesuaian** di Pembukuan › Jurnal Umum (staf membuat draft, manajer memposting): pendapatan RI belum ditagih ·
   selisih tarif BPJS · jasa medis terutang (SIAP) · gaji/listrik/air terutang · penyusutan · amortisasi biaya dibayar dimuka
   · cadangan piutang · selisih opname persediaan. Kunci periode DITOLAK selama masih ada draft.
7. Laporan bulan berjalan → review manajer keuangan → **Kunci periode**.
8. Tutup tahun: penyesuaian akhir tahun, pajak, kunci 01–12, lalu **Tutup Buku** di Pengaturan Pembukuan › Tahun buku
   (`BukuBesar::tutupBuku`, hak `tutupBuku.jalankan`) → **jurnal PENUTUP** 31 Des (`PENUTUP-YYYY12-NN`, akun L dinolkan →
   akun `akun.laba_ditahan`), tahun & 12 periode status **T**. Saldo awal tahun baru tetap diteruskan otomatis.

### Tutup buku tahunan — ATURAN (dibangun 2026-11-20)

- Syarat (`dampakTutupBuku`): tahun buku status B, 12 bulan berstatus K, tahun sebelumnya T (kecuali tahun pertama SIKEU),
  akun laba ditahan = Detail Neraca aktif. Tahun tanpa saldo L ditutup tanpa jurnal (`jurnal_penutup_id` null).
- **Penyajian: jurnal PENUTUP tahun X tidak ikut laporan tahun X**, baru terbaca mulai X+1 (sama seperti SALDO_AWAL):
  `neracaSaldo` & `mutasiAkun` membuang PENUTUP dengan `periode >= awal tahun laporan`, `LaporanKeuangan::mutasiPerAkun`
  membuang PENUTUP. Jadi laporan final tahun X tidak bergeser; di X+1 `laba_tahun_lalu` = 0 dan angkanya pindah ke akun
  laba ditahan (Neraca per akun identik — teruji lokal 11-20 dalam transaksi rollback).
- Saldo tersimpan (`sikeutxn_saldo_akuns`) bulan terkunci sesudah Desember X dihitung ulang saat tutup & batal.
- Status T: buka kunci, posting, jurnal umum, Reset Tahun ditolak. **Batal Tutup Buku** (`batalTutupBuku`, alasan ≥ 10,
  hanya tahun T terakhir): jurnal penutup → B, tahun → B, 12 periode → K. Tutup ulang = nomor jurnal berikutnya.
- Tercatat juga di Panduan › Cara Pakai §4a — ubah keduanya bersamaan.

### Kunci & buka kunci — ATURAN URUTAN

- **Kunci dari atas ke bawah** (bulan terlama → terbaru, lintas tahun): hanya bulan Buka terlama yang boleh dikunci, dan harus
  sudah diposting. **Buka kunci dari bawah ke atas**: hanya bulan terkunci terakhir, alasan wajib (log audit).
- Alasannya: saldo awal bulan N = saldo akhir bulan N−1. Mengunci N saat N−1 masih Buka, atau membuka N−1 saat N terkunci,
  membuat laporan "final" bisa bergeser.
- Layar menampilkan tombol yang belum gilirannya **nonaktif + alasan tertulis** ("kunci 01/2026 dulu", "02/2026 belum diposting",
  "buka 02/2026 dulu") — jangan disembunyikan diam-diam.
- **Bulan terkunci tetap boleh Tarik ulang** (menarik hanya menyimpan versi baru, buku tidak berubah) untuk mengecek apakah SIRUS
  berubah sesudah tutup. Bila **berbeda**, dua jalan:
  1. **Disarankan:** periode tetap terkunci; selisihnya dibukukan lewat **Jurnal Umum** di bulan yang masih Buka (koreksi
     periode berjalan) — laporan yang sudah final tidak berubah.
  2. Bila memang harus diganti: Buka Kunci (dari bulan terbaru, beralasan) → Ganti Versi → Kunci lagi berurutan.
- Mesin (`BukuBesar::sahkanDanPosting`, `JurnalUmum`) menolak posting / jurnal baru di periode Kunci — aturan layar hanya
  kemudahan, bukan satu-satunya penjaga.

### Warna tombol di layar alur (satu aksi utama per baris)

Hijau solid = **langkah berikutnya** saja (Tarik & Sahkan Setahun, Sahkan & Posting bila belum diposting, Kunci di bulan
gilirannya) · abu = aksi rutin / alternatif (Tarik, Tarik ulang, Ganti Versi) · garis = lihat (Neraca Saldo) · kuning = peringatan
(Buka Kunci). Ikon dari `App\Support\IkonTombol`.

---

## 6. Celah SIKEU — urutan saran pengerjaan

| Prioritas | Butuh | Alasan |
|---|---|---|
| 1 | ~~Laporan Laba Rugi~~ SELESAI; ~~form Susunan Laporan~~ SELESAI (Data Induk › Susunan Laporan, `App\Support\SusunanLaporan`, DDL 09 bawaan/keterangan, DDL 10 contoh LR Internal per instalasi, DDL 11 contoh Neraca Internal rinci per akun (134 baris; total = Umum); banyak template per jenis, pilihan Template di layar laporan, template non-bawaan namanya tercetak di judul; jadikan bawaan ditolak bila galat/dobel; Neraca wajib kode TA & TLE) | universal |
| 2 | ~~Saldo awal~~ SELESAI — **diisi di SIKEU**, hanya tahun pertama (`pembukuan.tahun_mulai`, kini 2026); tidak lagi dari API SIRUS `/saldo-awal` (§8) | syarat Neraca |
| 3 | ~~Neraca~~ SELESAI; sisa **Laporan Perubahan Ekuitas** (PT; jenis baru di `sikeumst_lap_hdrs`) | set laporan SAK EP |
| 4 | ~~Jurnal Umum / Penyesuaian~~ SELESAI (`App\Support\JurnalUmum`, layar Pembukuan › Jurnal Umum; draft `jurnal.entri`, posting/batal `jurnal.posting`; kunci periode ditolak bila ada draft) | tempat semua koreksi §5.6 |
| 5 | **Arus Kas** metode langsung (kas × lawan × kategori) + tidak langsung | set laporan |
| 6 | API SIRUS **klaim BPJS** + **RI berjalan** → penyesuaian otomatis §4.1–4.2 | celah terbesar di angka pendapatan & piutang |
| 7 | Jurnal **jasa medis dari SIAP** §4.3 | beban pokok yang hilang |
| 8 | **Register aset & penyusutan** (umur komersial vs fiskal) §4.6 | menggantikan akun "Belum Dirinci" |
| 9 | **Rekonsiliasi bank & kartu hutang/piutang** (subledger `sikeutxn_hp_*` vs akun kontrol) | kontrol internal |
| 10 | **Laporan manajemen**: per instalasi, rasio, anggaran | dipakai direksi |
| 11 | **CALK** (kebijakan akuntansi sebagai teks di pengaturan + tabel rincian otomatis) | set laporan |
| 12 | ~~Tutup buku tahunan (PENUTUP)~~ SELESAI (§5 Tutup buku tahunan); sisa **pajak tangguhan** | akhir tahun |

---

## 7. Rasio & indikator untuk direksi RS

| Indikator | Rumus | Catatan |
|---|---|---|
| Rasio lancar | aset lancar ÷ liabilitas jangka pendek | likuiditas |
| Rata-rata umur piutang (hari) | piutang usaha ÷ pendapatan usaha × hari periode | pisahkan BPJS vs non-BPJS |
| Rata-rata umur utang PBF (hari) | utang usaha PBF ÷ HPP obat × hari | |
| Margin laba kotor pelayanan | laba kotor ÷ pendapatan neto | per instalasi bila pusat biaya ada |
| Margin laba bersih | laba bersih ÷ pendapatan neto | |
| Rasio beban pegawai | beban pegawai + jasa medis ÷ pendapatan neto | |
| Rasio selisih BPJS | selisih tarif ÷ pendapatan JKN tarif RS | butuh §4.2 |
| Perputaran persediaan obat | HPP obat ÷ rata-rata persediaan | pembanding laporan perputaran obat SIRUS |

---

## 8. Saldo awal — DITETAPKAN DI SIKEU (keputusan user 2026-11-19)

- **SIKEU = sumber kebenaran keuangan; SIRUS hanya pemasok jurnal transaksi.** Saldo awal diisi di layar Saldo Awal
  (form neraca, semua akun Detail Neraca) dari neraca penutup / audit, rekening koran, opname — BUKAN ditarik dari SIRUS.
- **Hanya tahun pertama** (`sikeumst_pengaturans.pembukuan.tahun_mulai` = 2026). Tahun sesudahnya diteruskan otomatis
  (Neraca Saldo kumulatif); mengisi lagi = dobel → ditolak di mesin `SaldoAwal::wajibTahunPertama()`. Tahun pertama
  tidak bisa diubah selama ada saldo awal (draft/sah) di tahun lain, dan tidak bisa dimajukan selama ada posting sebelumnya.
- **Bulan sebelum tahun pertama = hanya dicek** (keputusan user 11-19, opsi 1 untuk 2025): tarik & bandingkan boleh;
  sahkan/posting, jurnal umum, dan kunci DITOLAK (`BukuBesar::wajibMulaiTahunPertama`); urutan kunci dihitung mulai tahun
  pertama. Posting yang terlanjur dibatalkan lewat `BukuBesar::batalkanSebelumTahunMulai()` (snapshot tetap disimpan).
- Layar **Pembukuan › Pengaturan Pembukuan** (menu urutan 1, `buku-besar.pengaturan`): tahun pertama + Set Ulang,
  tahun buku (status & Buka Tahun Buku), akun saldo laba. Menu Pembukuan urut alur: Pengaturan → Saldo Awal → Tarik
  Data SIRUS → Bandingkan Versi → Jurnal Umum → Neraca Saldo → Buku Besar.
- **Set Ulang tahun pertama** (`SaldoAwal::dampakSetUlang/setUlangTahunMulai`, hak `tutupBuku.jalankan`, alasan wajib):
  MUNDUR → saldo awal tahun lain dibatalkan, posting tetap; MAJU → posting & saldo awal sebelum tahun baru dibatalkan,
  DITOLAK bila ada bulan terkunci sebelumnya. Isian bebas tahun pertama sudah DIHAPUS (penyebab 2025 sempat terposting).
- **Reset Tahun / Hapus Tahun Buku** (`BukuBesar::dampakResetTahun/resetTahun/hapusTahunBuku`, hak `tutupBuku.jalankan`):
  reset = posting SIRUS dibatalkan + snap_id_sah dilepas, jurnal umum P → DRAFT, saldo awal tidak disentuh; DITOLAK bila ada
  bulan terkunci di tahun itu atau sesudahnya. Hapus hanya tahun tanpa jurnal aktif/jurnal umum/saldo awal (jurnal SIRUS
  berstatus batal ikut dihapus karena FK ke periode; hasil tarikan tetap).
- **Giliran kunci lintas tahun** (`BukuBesar::giliranKunci()`): bulan pertama sejak 01/tahun pertama yang belum Kunci,
  TERMASUK tahun yang belum dibuka (tidak dilewati) → 2024 terkunci penuh dulu, baru 2025, baru 2026.
- **Akun SIRUS "Masuk pembukuan"** (`sikeumst_peta_akun_sirus.dibukukan`, DDL 08): '0' = seluruh baris snapshot yang
  menyentuh akun itu (kedua sisi — jurnal SIRUS berpasangan) dilewati saat posting; pemeriksaan total memakai total
  snapshot setelah pengecualian. Untuk pekerjaan yang dikerjakan sendiri di SIKEU (HPP taksiran, aset & penyusutan,
  kartu utang di luar SIRUS). Aturan: **diabaikan di SIRUS = wajib dicatat di SIKEU**, terutama bila lawannya Kas/Persediaan.
  Berlaku untuk posting berikutnya (bulan terposting baru berubah setelah tarik ulang → sahkan versi baru).

### Kasus khusus — akun SIRUS yang dimatikan / dipertahankan (analisis tarikan 01/2025–11/2026, 11-19)
- Aturan: matikan HANYA bila SIKEU mencatat kejadian yang sama dengan cara lain; yang tidak dicatat SIRUS → Jurnal Umum.
- Dimatikan: HPP 5.1K/5.1K1/5.1K2/5.1K3 (HPP taksiran + koreksi opname). 5.1K4 HPP Ruangan = pemakaian BMHP → tetap.
- JANGAN dimatikan (lawan kas/piutang nyata): 1.1A07/1.1A08 peralatan (lawan Kas TU), 5.1F01/5.1F02* gaji & jasa medis
  (lawan Kas TU), 4.1I1/5.1K6 untung/rugi BPJS (lawan Kas + piutang terutang), semua pendapatan/persediaan/utang.
- Tidak ada di SIRUS: penyusutan (nol jurnal), akrual RI masih dirawat, akrual beban/jasa medis, bunga/admin bank, PPh.
- Diputuskan nanti: BPJS (matikan 4.1I1/5.1K6 bila klaim dibukukan per bulan pelayanan dari E-Klaim); gaji (SIAP vs SIRUS, jangan dobel).
- Tercatat juga di Panduan › Cara Pakai §4c — ubah keduanya bersamaan.

### Catatan lama: saldo awal SIRUS 2026 (alasan tidak dipakai)

- `tktxn_saldoawalakuns` 2026 **tidak seimbang ±Rp 9,26 M** (K lebih). Penyebab: akun **L01 "Laba (Rugi)"** dipakai tutup
  buku SIRUS sebagai PENYEIMBANG (Σ D − Σ K dibawa terus), bukan laba sebenarnya; aset tetap/ekuitas lama tak lengkap.
- Di SIKEU selisih **tidak pernah diseimbangkan otomatis**: pemakai memilih akun penampungnya (saran: Akun Penampung
  1.1.09.001 — wajib nol saat tutup periode, jadi memaksa penelusuran). Keputusan akhir = manajemen + KAP.
- Saldo bersisi terbalik di sumber: Persediaan Apotek **kredit 1,43 M**, Kas Apotek kredit 2,5 jt, Piutang Klinik kredit 46,65 jt.
- Saldo awal akun Laba Rugi & laba berjalan dipindah ke akun laba ditahan (pengaturan `akun.laba_ditahan` / `akun.laba_berjalan`).
- Saldo awal per **1 Januari** → semua bulan Jan s/d bulan laporan harus diposting supaya Neraca benar.

## 9. Jebakan yang sudah diketahui

- Oracle 10.2.0.1: UNION ALL besar → **wrong result**. Agregasi laporan per cabang/per kelompok kecil; jangan satu UNION raksasa.
- Teks laporan & keterangan jurnal: ASCII saja (DB WE8ISO8859P1; "—", "≤" jadi "¿").
- `''` = NULL di Oracle (pengaturan pemisah kode akun kosong tersimpan NULL).
- Akun SIRUS "Pendapatan ... Terutang" (4.1AA/4.1BB/4.1CA/4.1GA/4.1AKA) adalah **piutang**, sudah dipetakan ke 1.1.02.x.
- Saldo laba tahun lalu yang belum ditutup buku muncul sebagai baris tersendiri di Neraca Saldo — jangan dihilangkan;
  hilang sendiri setelah tahun itu di-Tutup Buku (jurnal PENUTUP memindahkannya ke akun laba ditahan).
- Query saldo/laporan baru WAJIB memperlakukan sumber PENUTUP seperti di `neracaSaldo` (buang bila periode >= awal tahun
  laporan) — kalau tidak, laporan Desember tahun yang ditutup menampilkan akun Laba Rugi nol.
- Jangan UPDATE/DELETE jurnal berstatus P. Koreksi = jurnal pembalik / status B.
- Hasil query besar jangan disimpan di properti publik Livewire (pakai `#[Computed]`).

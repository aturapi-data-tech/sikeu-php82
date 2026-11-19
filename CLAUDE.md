# SIKEU — Sistem Informasi Keuangan

Aplikasi akuntansi & pelaporan keuangan RS, terpisah dari SIRUS (`~/Desktop/LARAVEL/sirus-php82`).
Fondasi disalin dari SIAP (`~/Desktop/LARAVEL/siap-php82`): Laravel 12, Livewire 4 (Volt SFC `⚡*.blade.php`),
Oracle 10g (yajra/laravel-oci8), Tailwind + Alpine, Spatie Permission.

## Aturan keras

1. **SIRUS = baca saja, LEWAT API.** Data SIRUS hanya lewat `App\Support\SirusApi` (`/api/sikeu` di SIRUS,
   JWT, akun ber-role Api Sikeu). SIKEU TIDAK punya koneksi database ke skema SIRUS — jangan menambahkannya.
   Data baru yang dibutuhkan = endpoint baru di SIRUS (`routes/api-sikeu.php`, `Api\Sikeu\JurnalApiController`),
   dikerjakan di branch `develop` SIRUS. Bila suatu fitur butuh MENULIS ke SIRUS, **berhenti dan tanya user**.
2. **Transaksi pelayanan tetap di SIRUS**: kasir RJ/UGD/RI/apotek, piutang, penerimaan PBF, hutang/DP
   supplier. SIKEU tidak membuat ulang layar-layar itu.
3. **Tabel milik SIKEU** berawalan `sikeumst_` (master) / `sikeutxn_` (transaksi), lewat koneksi default.
   Nama objek Oracle maks 30 karakter (`ORA_MAX_NAME_LEN=30`). **Sumber struktur tabel pembukuan =
   `database/sql/01_struktur_pembukuan.sql`** (bukan migrasi Laravel; migrasi hanya untuk tabel kerangka:
   users, roles, cache, jobs, identitas). Tabel/kolom baru = file DDL bernomor berikutnya (02_…), lengkap
   dengan bagian periksa / verifikasi / rollback. Konsep buku besar ada di kepala file 01.
   **Bagan akun & peta akun SIRUS** (02_, 03_) DIBANGKITKAN dari `database/sql/tools/bagan_akun.php` —
   jangan edit SQL-nya langsung. Generator menolak akun SIRUS yang belum dipetakan / peta ke akun header.
   Itu hanya untuk PASANG AWAL: sesudahnya sumber data = tabel, diubah lewat layar Data Induk › Bagan Akun &
   Peta Akun SIRUS (gate `akun.kelola`, aturan di `App\Support\BaganAkun`, setiap perubahan ke sikeutxn_audit_logs).
4. **Mesin jurnal tetap satu, di SIRUS** (`App\Support\Keuangan\Jurnal` + `JurnalCabang`, lihat
   `docs/jurnal-keuangan.md` di repo SIRUS). Jangan menyalin katalog cabangnya ke SIKEU. API mengirim per cabang
   (satu SELECT tanpa UNION — UNION besar memberi wrong result di Oracle 10.2.0.1).
5. **Snapshot = tak pernah diubah.** Versi berstatus Selesai di `sikeutxn_snap_*` tidak di-update/di-hapus;
   ambil ulang = versi baru. Pembanding (`App\Support\SnapshotJurnal::bandingkan`) bergantung pada itu (hasilnya di-cache).
   `kunci_baris` = sha1(cabang|label|akun|lawan|tanggal|shift) — nominal sengaja tidak masuk kunci.
6. Halaman baru: daftarkan route di `routes/web.php` **dan** entri di `App\Services\AppMenu::definitions()`
   dengan `'izin'` = permission (pembatas akses halaman + aksi Livewire, middleware `menu` persistent).
   **Jangan menulis nama role di kode** — hak akses = permission Spatie: tambah di `App\Support\HakAkses::DAFTAR`,
   buat lewat migrasi, pakai `@can` / `->can()` + guard server di method-nya; pembagian ke role diatur user di Role Control.
   Pengecualian tunggal: `HakAkses::PERAN_SUPER` ('Admin') selalu boleh semua.
7. **Hal yang beda antar-instansi = pengaturan, bukan konstanta** (tabel `sikeumst_pengaturans`, DDL 04, kelas
   `App\Support\Pengaturan`). Format kode akun dibaca lewat `App\Support\FormatKodeAkun` — jangan menulis
   jumlah tingkat/digit/pemisah/nomor kontra di kode.
8. **Buku besar** (`App\Support\BukuBesar`, layar Pembukuan › Tarik Data SIRUS / Neraca Saldo / Buku Besar):
   versi snapshot yang DISAHKAN per periode diposting jadi satu jurnal per hari (sumber SIRUS), baris = Σ per
   (tanggal, cabang, akun, lawan, sisi) lewat peta akun; posting ulang selama periode Buka → jurnal lama status B.
   Saldo = Σ jurnal status P (akun L hanya sejak Januari). Kunci berurutan dari bulan terlama; buka kunci hanya
   periode terkunci terakhir + alasan. Jangan UPDATE/DELETE jurnal P — batalkan (status B).
10. **Ikon tombol baku** = `App\Support\IkonTombol::PETA` (salinan dari SIRUS + `kurangi`, `tukar`) lewat
   `<x-ikon nama="simpan" />` di dalam tombol berteks; satu aksi = satu ikon + satu komponen tombol (lihat kolom `tombol`).
   Jangan menulis `<svg>` inline / emoji / tanda "+" di label ("Tambah …" + ikon `tambah`). Baris tabel: `x-action-edit`,
   `x-lihat-button`, `x-cetak-button`, `x-action-delete`. Aksi baru → tambah entri di peta dulu.
9. **Akuntansi & laporan keuangan**: baca skill `keuangan-rs` dulu (standar, kebijakan RS, celah data SIRUS, urutan kerja).

## Uji lokal

- `.env`: `DB_HOST=localhost` = Oracle lokal dev (boleh tulis ke skema SIKEU). Bila `DB_HOST` mengarah
  ke server produksi, perlakukan hanya-baca.
- Cek API SIRUS: menu Sistem › Sambungan SIRUS (Admin).
- `SnapshotJurnal` kelas INSTANCE: yajra `insertGetId` gagal dari method statis.
- Kelas Tailwind baru wajib `npm run build`.
- Tanya user sebelum commit.

Skill repo: `.claude/skills/` (indeks `docs/skills-index.md`).

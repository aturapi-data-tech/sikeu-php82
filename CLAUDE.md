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
4. **Mesin jurnal tetap satu, di SIRUS** (`App\Support\Keuangan\Jurnal` + `JurnalCabang`, lihat
   `docs/jurnal-keuangan.md` di repo SIRUS). Jangan menyalin katalog cabangnya ke SIKEU. API mengirim per cabang
   (satu SELECT tanpa UNION — UNION besar memberi wrong result di Oracle 10.2.0.1).
5. **Snapshot = tak pernah diubah.** Versi berstatus Selesai di `sikeutxn_snap_*` tidak di-update/di-hapus;
   ambil ulang = versi baru. Pembanding (`App\Support\SnapshotJurnal::bandingkan`) bergantung pada itu (hasilnya di-cache).
   `kunci_baris` = sha1(cabang|label|akun|lawan|tanggal|shift) — nominal sengaja tidak masuk kunci.
6. Halaman baru: daftarkan route di `routes/web.php` **dan** entri di `App\Services\AppMenu::definitions()`
   (daftar role di sana = pembatas akses halaman, middleware `menu`). Aksi berisiko: konstanta di
   `App\Support\AksiRole` + Gate di `AppServiceProvider`, dan guard server `->can()` di method-nya.

## Uji lokal

- `.env`: `DB_HOST=localhost` = Oracle lokal dev (boleh tulis ke skema SIKEU). Bila `DB_HOST` mengarah
  ke server produksi, perlakukan hanya-baca.
- Cek API SIRUS: menu Sistem › Sambungan SIRUS (Admin).
- `SnapshotJurnal` kelas INSTANCE: yajra `insertGetId` gagal dari method statis.
- Kelas Tailwind baru wajib `npm run build`.
- Tanya user sebelum commit.

Skill repo: `.claude/skills/` (indeks `docs/skills-index.md`).

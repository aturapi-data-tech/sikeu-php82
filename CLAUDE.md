# SIKEU — Sistem Informasi Keuangan

Aplikasi akuntansi & pelaporan keuangan RS, terpisah dari SIRUS (`~/Desktop/LARAVEL/sirus-php82`).
Fondasi disalin dari SIAP (`~/Desktop/LARAVEL/siap-php82`): Laravel 12, Livewire 4 (Volt SFC `⚡*.blade.php`),
Oracle 10g (yajra/laravel-oci8), Tailwind + Alpine, Spatie Permission.

## Aturan keras

1. **SIRUS = baca saja.** Data SIRUS hanya lewat `DB::connection('sirus')`. Jangan pernah menulis ke
   SIRUS dari SIKEU — tidak lewat koneksi itu, tidak lewat API. Hak Oracle user `sikeu` di skema SIRUS
   memang hanya SELECT (`database/sql/01_grant_baca_sirus.sql`); jangan minta / tambah GRANT tulis.
   Bila suatu fitur butuh menulis ke SIRUS (mis. jurnal yang harus ikut terbaca form Oracle 6i),
   **berhenti dan tanya user** — itu keputusan arsitektur.
2. **Transaksi pelayanan tetap di SIRUS**: kasir RJ/UGD/RI/apotek, piutang, penerimaan PBF, hutang/DP
   supplier. SIKEU tidak membuat ulang layar-layar itu.
3. **Tabel milik SIKEU** berawalan `sikeumst_` (master) / `sikeutxn_` (transaksi), lewat koneksi default.
   Nama objek Oracle maks 30 karakter (`ORA_MAX_NAME_LEN=30`).
4. **Laporan keuangan** mengikuti keputusan SIRUS: SELECT langsung ke tabel transaksi (mesin
   `App\Support\Keuangan\Jurnal` + katalog `JurnalCabang` di sirus-php82, lihat `docs/jurnal-keuangan.md`
   di repo SIRUS), BUKAN view `TKVIEW_ACCOUNTS*` (lambat & ada bug wrong-result Oracle 10g).
5. Halaman baru: daftarkan route di `routes/web.php` **dan** entri di `App\Services\AppMenu::definitions()`
   (daftar role di sana = pembatas akses halaman, middleware `menu`). Aksi berisiko: konstanta di
   `App\Support\AksiRole` + Gate di `AppServiceProvider`, dan guard server `->can()` di method-nya.

## Uji lokal

- `.env`: `DB_HOST=localhost` = Oracle lokal dev (boleh tulis ke skema SIKEU). Bila `DB_HOST` mengarah
  ke server produksi, perlakukan hanya-baca.
- Cek koneksi SIRUS: menu Sistem › Sambungan SIRUS (Admin).
- Kelas Tailwind baru wajib `npm run build`.
- Tanya user sebelum commit.

Skill repo: `.claude/skills/` (indeks `docs/skills-index.md`).

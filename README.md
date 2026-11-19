<p align="center">
  <img src="public/images/logo-sikeu.svg" width="320" alt="Logo SIKEU">
</p>

<p align="center">
  <strong>SIKEU – Sistem Informasi Keuangan</strong><br>
  Akuntansi & pelaporan keuangan rumah sakit di atas data transaksi SIRUS
</p>

---

## Tentang SIKEU

**SIKEU** adalah aplikasi terpisah untuk sisi **akuntansi & pelaporan** keuangan: buku besar,
neraca, laba rugi, arus kas, jurnal umum, kunci periode, dan tutup buku tahunan.

Transaksi pelayanan (kasir RJ/UGD/RI/apotek, piutang pasien, penerimaan PBF, hutang & DP supplier)
**tetap di SIRUS**, karena di sana jurnal dan stok tercatat saat kejadian. SIKEU **membaca**
tabel-tabel itu langsung dan tidak menyalinnya.

Dibangun dengan **Laravel 12** + **Livewire v4**, **Oracle Database**, **Tailwind CSS + Flowbite (UI saja)**,
dan **Alpine.js** — fondasi yang sama dengan SIAP & SIRUS.

---

## Arsitektur data

| Koneksi | Skema | Hak | Isi |
|---|---|---|---|
| `oracle` (default) | `SIKEU` | penuh | tabel milik SIKEU: `SIKEUMST_*` (master), `SIKEUTXN_*` (transaksi), users/roles |
| `sirus` | `SIRUS` | **SELECT saja** | akun, kas, jurnal & seluruh tabel transaksi SIRUS |

Koneksi `sirus` login memakai user `sikeu` lalu mengalihkan `CURRENT_SCHEMA` ke `SIRUS`, jadi nama
tabel ditulis tanpa awalan (`DB::connection('sirus')->table('acmst_accounts')`). Karena hak user
`sikeu` di skema SIRUS hanya `SELECT`, tulis ke SIRUS **ditolak Oracle** (ORA-01031). Menu
**Sistem › Sambungan SIRUS** memeriksa keempat syarat ini.

---

## Role

Menu tampil sesuai role (definisi tunggal di `app/Services/AppMenu.php`); aksi dibatasi lewat
`app/Support/AksiRole.php`.

| Role | Akses |
|---|---|
| **Admin** | Semua menu, termasuk Sistem |
| **Manager Keuangan** | Semua menu keuangan + pengesahan (kunci periode, tutup buku) |
| **Staf Akuntansi** | Jurnal umum & laporan, tanpa pengesahan |
| **Direksi** | Baca laporan |

---

## Instalasi

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Database (sekali, oleh DBA):

1. `database/sql/00_create_user_sikeu.sql` — sebagai SYSTEM: buat user `sikeu` (ganti sandinya dulu).
2. `database/sql/01_grant_baca_sirus.sql` — sebagai pemilik skema SIRUS atau DBA: beri hak **SELECT** ke `sikeu`.
   Ulangi bila SIRUS menambah tabel baru.

Isi `.env` (`DB_HOST`, `DB_SERVICE_NAME`, `DB_USERNAME=sikeu`, `DB_PASSWORD`, `SIRUS_DB_SCHEMA`, `IDENTITAS_*`), lalu:

```bash
php artisan migrate
php artisan storage:link
npm run build
php artisan serve --port=8003
```

---

## Dokumentasi

Pola komponen & arsitektur di [`docs/`](docs/). Skill AI (Claude Code) di `.claude/skills/` —
indeksnya di [`docs/skills-index.md`](docs/skills-index.md). Aturan kerja untuk AI di [`CLAUDE.md`](CLAUDE.md).

---

## Lisensi

[MIT](LICENSE)

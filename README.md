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

SIKEU **tidak membaca database SIRUS langsung**. Data diambil lewat **API baca-saja** SIRUS
(`/api/sikeu`, token JWT, akun SIRUS ber-role *Api Sikeu*) lalu disimpan di skema Oracle SIKEU sendiri.
Mesin jurnal tetap satu, di SIRUS (`App\Support\Keuangan\Jurnal` + `JurnalCabang`), jadi angka SIKEU
= angka Buku Besar / Laba Rugi SIRUS.

| Endpoint SIRUS | Isi |
|---|---|
| `POST /api/sikeu/token` | login akun API → JWT |
| `GET /api/sikeu/cabang` | katalog cabang jurnal (±200) + kunci tetap per cabang |
| `GET /api/sikeu/jurnal/{tahun}/{bulan}?cabang=N` | semua baris jurnal satu cabang satu bulan |
| `GET /api/sikeu/akun` | bagan akun |

### Cutoff & pembanding versi

- **Snapshot Jurnal** — tiap "Ambil dari SIRUS" menyimpan jurnal satu bulan sebagai **versi baru**
  (`sikeutxn_snap_hdrs` / `_cabangs` / `_dtls`); versi lama tidak pernah ditimpa. Checksum per cabang & per versi.
- **Bandingkan Versi** — dua versi dibandingkan sampai tingkat transaksi: **baru**, **hilang**, **berubah**
  (nominal / tanggal / akun / shift). Hanya cabang yang checksum-nya beda yang dibandingkan per baris.
- Dari baris perintah / cron: `php artisan sikeu:ambil-jurnal 202610`.

Tabel milik SIKEU berawalan `SIKEUMST_` (master) dan `SIKEUTXN_` (transaksi).

---

## Role

Menu tampil sesuai role (definisi tunggal di `app/Services/AppMenu.php`); aksi dibatasi lewat
`app/Support/AksiRole.php`.

| Role | Akses |
|---|---|
| **Admin** | Semua menu, termasuk Sistem |
| **Manager Keuangan** | Semua menu keuangan + pengesahan (kunci periode, tutup buku) |
| **Staf Akuntansi** | Snapshot jurnal, jurnal umum & laporan, tanpa pengesahan |
| **Direksi** | Baca laporan & Bandingkan Versi |

---

## Instalasi

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Database (sekali):

1. `database/sql/00_create_user_sikeu.sql` — sebagai SYSTEM: buat user `sikeu` (ganti sandinya dulu).
2. `php artisan migrate` — tabel kerangka Laravel (users, roles, cache, jobs, identitas).
3. `database/sql/01_struktur_pembukuan.sql` — sebagai user `sikeu`: 25 tabel pembukuan (akun, periode, jurnal,
   saldo, template laporan, aset & penyusutan, HPP, hutang-piutang, snapshot SIRUS, audit).

Di **SIRUS**: isi `SIKEU_API_JWT_SECRET` (≥ 32 karakter acak) di `.env`, lalu `php artisan sikeu-api:akun`
→ salin username & password yang dicetak ke `.env` SIKEU.

Isi `.env` SIKEU (`DB_*` dengan `DB_USERNAME=sikeu`, `SIKEU_API_URL`, `SIKEU_API_USER`, `SIKEU_API_PASSWORD`,
`IDENTITAS_*`), lalu:

```bash
php artisan migrate          # lalu jalankan 01_struktur_pembukuan.sql sebagai user sikeu
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

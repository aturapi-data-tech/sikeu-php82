<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien API SIRUS (/api/sikeu, JWT) — BACA SAJA, pola sama dengan klien gaji dokter SIAP.
 * Kredensial di .env: SIKEU_API_URL / SIKEU_API_USER / SIKEU_API_PASSWORD (akun SIRUS ber-role Api Sikeu).
 * SIKEU tidak pernah menulis ke SIRUS: klien ini hanya punya GET (dan POST /token untuk login).
 */
class SirusApi
{
    private const KUNCI_CACHE_TOKEN = 'sikeu.sirus-api-token';

    public static function aktif(): bool
    {
        return (string) config('services.sirus.url', '') !== '' && (string) config('services.sirus.user', '') !== '';
    }

    public static function alamat(): string
    {
        return rtrim((string) config('services.sirus.url', ''), '/');
    }

    private static function token(bool $tokenSegar = false): string
    {
        if (!self::aktif()) {
            throw new RuntimeException('API SIRUS belum diatur (SIKEU_API_URL / SIKEU_API_USER / SIKEU_API_PASSWORD di .env).');
        }
        if ($tokenSegar) {
            Cache::forget(self::KUNCI_CACHE_TOKEN);
        }

        return Cache::remember(self::KUNCI_CACHE_TOKEN, now()->addMinutes(45), function () {
            $respon = Http::acceptJson()->timeout(20)->post(self::alamat() . '/api/sikeu/token', [
                'username' => config('services.sirus.user'), 'password' => config('services.sirus.password'),
            ]);
            if (!$respon->ok() || !$respon->json('token')) {
                throw new RuntimeException('Gagal login API SIRUS: ' . ($respon->json('error') ?? "HTTP {$respon->status()}"));
            }

            return (string) $respon->json('token');
        });
    }

    /** GET ber-Bearer; token kedaluwarsa (401) dicoba ulang sekali dengan token baru. */
    public static function get(string $path, array $query = []): array
    {
        $url = self::alamat() . '/api/sikeu/' . ltrim($path, '/');
        foreach ([false, true] as $tokenSegar) {
            $respon = Http::acceptJson()->timeout(120)->withToken(self::token($tokenSegar))->get($url, $query);
            if ($respon->status() === 401 && !$tokenSegar) {
                continue;
            }
            if (!$respon->ok()) {
                throw new RuntimeException("API SIRUS {$path}: " . ($respon->json('error') ?? "HTTP {$respon->status()}"));
            }

            return (array) $respon->json();
        }
        throw new RuntimeException("API SIRUS {$path}: token ditolak.");
    }

    /**
     * Uji sambungan: login, katalog cabang jurnal, bagan akun.
     * @return array<int, array{judul: string, lulus: bool, keterangan: string}>
     */
    public static function ujiSambungan(): array
    {
        $hasilList = [];
        if (!self::aktif()) {
            return [['judul' => 'Pengaturan .env', 'lulus' => false, 'keterangan' => 'SIKEU_API_URL / SIKEU_API_USER belum diisi.']];
        }
        $hasilList[] = ['judul' => 'Pengaturan .env', 'lulus' => true, 'keterangan' => 'Alamat ' . self::alamat() . ', akun ' . config('services.sirus.user') . '.'];

        try {
            self::token(true);
            $hasilList[] = ['judul' => 'Login API (token JWT)', 'lulus' => true, 'keterangan' => 'Token diterima.'];
        } catch (\Throwable $exception) {
            $hasilList[] = ['judul' => 'Login API (token JWT)', 'lulus' => false, 'keterangan' => $exception->getMessage()];
            return $hasilList;
        }

        foreach (['cabang' => 'Katalog cabang jurnal', 'akun' => 'Bagan akun'] as $path => $judul) {
            try {
                $data = self::get($path);
                $jumlah = count($data[$path] ?? []);
                $hasilList[] = ['judul' => $judul, 'lulus' => $jumlah > 0, 'keterangan' => number_format($jumlah, 0, ',', '.') . ' baris terbaca.'];
            } catch (\Throwable $exception) {
                $hasilList[] = ['judul' => $judul, 'lulus' => false, 'keterangan' => $exception->getMessage()];
            }
        }

        return $hasilList;
    }
}

<?php

use Livewire\Component;
use App\Support\SirusApi;

/**
 * SAMBUNGAN SIRUS — uji API SIRUS (/api/sikeu, JWT, baca saja): pengaturan .env, login token,
 * katalog cabang jurnal, bagan akun. Logika di App\Support\SirusApi::ujiSambungan().
 */
new class extends Component {
    /** @var array<int, array{judul: string, lulus: bool, keterangan: string}> */
    public array $hasilPeriksa = [];

    public ?string $waktuPeriksa = null;

    public function periksa(): void
    {
        $this->hasilPeriksa = SirusApi::ujiSambungan();
        $this->waktuPeriksa = now()->format('d/m/Y H:i:s');
    }
};
?>

<div>
    <x-page-title title="Sambungan SIRUS"
        subtitle="Uji API SIRUS — login token, katalog cabang jurnal, bagan akun" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="max-w-3xl px-6 pt-4 pb-6 mx-auto space-y-4">

            <div class="p-4 text-sm border rounded-xl border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-800/40 dark:bg-blue-900/20 dark:text-blue-100">
                SIKEU membaca SIRUS lewat <b>API baca-saja</b> (<code>/api/sikeu</code>, token JWT).
                Atur <code>SIKEU_API_URL</code>, <code>SIKEU_API_USER</code>, <code>SIKEU_API_PASSWORD</code> di <code>.env</code> SIKEU;
                di SIRUS isi <code>SIKEU_API_JWT_SECRET</code> dan buat akunnya dengan <code>php artisan sikeu-api:akun</code>.
            </div>

            <div class="flex items-center gap-3">
                <x-primary-button type="button" wire:click="periksa" wire:loading.attr="disabled" wire:target="periksa">
                    <span wire:loading.remove wire:target="periksa" class="inline-flex items-center gap-2"><x-ikon nama="cari" /> Periksa Sambungan</span>
                    <span wire:loading wire:target="periksa">Memeriksa…</span>
                </x-primary-button>
                @if ($waktuPeriksa)
                    <span class="text-xs text-muted dark:text-gray-400">Diperiksa {{ $waktuPeriksa }}</span>
                @endif
            </div>

            @if ($hasilPeriksa)
                <ul class="overflow-hidden border divide-y rounded-xl border-hairline divide-hairline bg-surface-card dark:bg-gray-800 dark:border-gray-700 dark:divide-gray-700">
                    @foreach ($hasilPeriksa as $hasil)
                        <li class="flex items-start gap-3 px-4 py-3">
                            <span @class([
                                'mt-0.5 inline-flex shrink-0 items-center justify-center w-6 h-6 rounded-full text-xs font-bold',
                                'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $hasil['lulus'],
                                'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' => !$hasil['lulus'],
                            ]) aria-hidden="true">
                                @if ($hasil['lulus'])
                                    <x-ikon nama="setujui" ukuran="w-3.5 h-3.5" />
                                @else
                                    <x-ikon-peringatan />
                                @endif
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-ink dark:text-white">
                                    {{ $hasil['judul'] }}
                                    <span class="sr-only">{{ $hasil['lulus'] ? '(lulus)' : '(gagal)' }}</span>
                                </p>
                                <p class="text-sm break-words text-body dark:text-gray-300">{{ $hasil['keterangan'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>

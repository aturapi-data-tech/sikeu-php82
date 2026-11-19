<?php

use Livewire\Component;
use Illuminate\Support\Facades\DB;
use App\Support\BaganAkun;
use App\Support\FormatKodeAkun;
use App\Support\Pengaturan;

/**
 * FORMAT KODE AKUN — pengaturan bentuk kode bagan akun (jumlah tingkat, digit per tingkat, pemisah, nomor kontra).
 * Disimpan di sikeumst_pengaturans; dipakai Bagan Akun & Kelompok Akun lewat App\Support\FormatKodeAkun.
 * Format hanya bisa diganti bila SEMUA akun yang sudah ada tetap cocok dengan format baru — kode akun tidak pernah
 * diubah otomatis (dipakai jurnal, saldo, peta).
 */
new class extends Component {
    /** @var array<int, string> digit per tingkat, indeks mulai 1 (string supaya isian kosong tetap terbaca) */
    public array $digitList = [];
    public string $pemisah = '.';
    public string $awalKontra = '0';

    public function mount(): void
    {
        $this->muat();
    }

    private function muat(): void
    {
        Pengaturan::lupakan();
        $this->digitList = array_map('strval', FormatKodeAkun::digitPerTingkat());
        $this->pemisah = FormatKodeAkun::pemisah();
        $this->awalKontra = (string) FormatKodeAkun::awalKontra();
        $this->resetValidation();
    }

    public function tambahTingkat(): void
    {
        if (count($this->digitList) < FormatKodeAkun::TINGKAT_MAKS) {
            $this->digitList[count($this->digitList) + 1] = '2';
        }
    }

    public function kurangiTingkat(): void
    {
        if (count($this->digitList) > FormatKodeAkun::TINGKAT_MIN) {
            unset($this->digitList[count($this->digitList)]);
        }
    }

    public function batal(): void
    {
        $this->muat();
    }

    /** @return array<int, int> */
    private function digitAngka(): array
    {
        return array_map(fn ($digit) => (int) $digit, $this->digitList);
    }

    protected function rules(): array
    {
        $rules = [
            'pemisah' => ['present', function (string $atribut, $nilai, $gagal) {
                if (!in_array((string) $nilai, FormatKodeAkun::PEMISAH_SAH, true)) {
                    $gagal('Pemisah harus titik, strip, atau tanpa pemisah.');
                }
            }],
            'awalKontra' => ['required', 'integer', 'min:0', function (string $atribut, $nilai, $gagal) {
                $digitTerakhir = (int) end($this->digitList);
                if ((int) $nilai >= 10 ** $digitTerakhir) {
                    $gagal("Nomor kontra harus muat di {$digitTerakhir} digit tingkat terakhir (maks " . (10 ** $digitTerakhir - 1) . '), atau 0.');
                }
            }],
            'digitList' => ['array', 'min:' . FormatKodeAkun::TINGKAT_MIN, 'max:' . FormatKodeAkun::TINGKAT_MAKS, function (string $atribut, $nilai, $gagal) {
                $panjang = array_sum($this->digitAngka()) + (count($this->digitList) - 1) * strlen($this->pemisah);
                if ($panjang > FormatKodeAkun::PANJANG_KODE_MAKS) {
                    $gagal('Kode terpanjang ' . $panjang . ' karakter, maksimal ' . FormatKodeAkun::PANJANG_KODE_MAKS . '.');
                }
            }],
            'digitList.1' => 'required|integer|min:1|max:' . FormatKodeAkun::DIGIT_KELOMPOK_MAKS,
        ];
        foreach (array_keys($this->digitList) as $tingkat) {
            if ($tingkat > 1) {
                $rules["digitList.{$tingkat}"] = 'required|integer|min:1|max:' . FormatKodeAkun::DIGIT_MAKS;
            }
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'integer' => ':attribute harus angka bulat.',
            'min' => ':attribute minimal :min.',
            'max' => ':attribute maksimal :max.',
            'digitList.min' => 'Minimal :min tingkat.',
            'digitList.max' => 'Maksimal :max tingkat.',
        ];
    }

    protected function validationAttributes(): array
    {
        $atributList = ['pemisah' => 'Pemisah', 'awalKontra' => 'Nomor awal akun kontra', 'digitList.1' => 'Digit tingkat 1 (kelompok)'];
        foreach (array_keys($this->digitList) as $tingkat) {
            if ($tingkat > 1) {
                $atributList["digitList.{$tingkat}"] = "Digit tingkat {$tingkat}";
            }
        }

        return $atributList;
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->can('akun.kelola'), 403);
        $this->digitList = array_combine(range(1, count($this->digitList)), array_map(fn ($digit) => trim((string) $digit), array_values($this->digitList)));
        $this->validate();

        $takCocok = FormatKodeAkun::akunTakCocok($this->digitAngka(), $this->pemisah);
        if ($takCocok !== []) {
            $this->addError('digitList', 'Akun yang sudah ada tidak cocok dengan format ini: ' . implode(', ', $takCocok)
                . '. Kode akun tidak diubah otomatis — format hanya bisa diganti selama semua akun tetap cocok.');
            return;
        }

        $lama = ['digit' => implode(',', FormatKodeAkun::digitPerTingkat()), 'pemisah' => FormatKodeAkun::pemisah(), 'kontra' => FormatKodeAkun::awalKontra()];
        $baru = ['digit' => implode(',', $this->digitAngka()), 'pemisah' => $this->pemisah, 'kontra' => (int) $this->awalKontra];
        DB::transaction(function () use ($lama, $baru) {
            Pengaturan::simpan('akun.digit_per_tingkat', $baru['digit']);
            Pengaturan::simpan('akun.pemisah', $baru['pemisah']);
            Pengaturan::simpan('akun.awal_kontra', (string) $baru['kontra']);
            BaganAkun::catatAudit('UBAH', 'SIKEUMST_PENGATURANS', 'akun.format', $lama, $baru);
        });
        $this->muat();
        $this->dispatch('toast', type: 'success', message: 'Format kode akun disimpan.');
    }

    /** Contoh kode dari isian yang sedang diketik (belum disimpan). */
    public function contohIsian(): string
    {
        $digitList = $this->digitAngka();
        if (in_array(0, $digitList, true)) {
            return '-';
        }

        return FormatKodeAkun::contoh($digitList, $this->pemisah);
    }

    public function jumlahPerTingkat(): array
    {
        return DB::table('sikeumst_akuns')->groupBy('tingkat')->selectRaw('tingkat, COUNT(*) AS jumlah')->pluck('jumlah', 'tingkat')->all();
    }
};
?>

<div>

    <x-page-title
        title="Format Kode Akun"
        subtitle="Bentuk kode bagan akun — jumlah tingkat, digit per tingkat, pemisah, dan nomor akun kontra" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="px-6 pt-2 pb-6 space-y-4">

            <div class="px-4 py-3 text-sm rounded-xl bg-info-tint text-info-deep dark:bg-blue-900/30 dark:text-blue-200">
                Tingkat 1 = kode kelompok (Master › Kelompok Akun). Tingkat terakhir selalu akun <b>Detail</b> yang dijurnal;
                tingkat di antaranya boleh Header atau Detail. Format hanya bisa diganti bila semua akun yang sudah ada tetap cocok —
                kode akun tidak pernah diubah otomatis. Contoh: menambah tingkat di bawah selalu boleh, mengubah digit tingkat yang sudah terisi tidak.
            </div>

            @php $kelola = auth()->user()?->can('akun.kelola'); $jumlahAkun = $this->jumlahPerTingkat(); @endphp

            <div class="grid items-start grid-cols-1 gap-4 lg:grid-cols-2">
                <x-border-form title="Tingkat & Digit">
                    <div class="space-y-3">
                        @foreach ($digitList as $tingkat => $digit)
                            <div wire:key="tingkat-{{ $tingkat }}" class="grid items-center grid-cols-12 gap-3">
                                <label for="digit-{{ $tingkat }}" class="col-span-5 text-sm text-ink dark:text-gray-200">
                                    Tingkat {{ $tingkat }}
                                    <span class="block text-[11px] text-muted dark:text-gray-400">
                                        {{ $tingkat === 1 ? 'kelompok' : ($tingkat === count($digitList) ? 'akun detail' : 'header / detail') }}
                                        · {{ $jumlahAkun[$tingkat] ?? 0 }} akun
                                    </span>
                                </label>
                                <div class="col-span-4">
                                    <x-text-input id="digit-{{ $tingkat }}" type="number" min="1" max="{{ $tingkat === 1 ? FormatKodeAkun::DIGIT_KELOMPOK_MAKS : FormatKodeAkun::DIGIT_MAKS }}"
                                        wire:model.live.debounce.300ms="digitList.{{ $tingkat }}" :disabled="!$kelola"
                                        :error="$errors->has('digitList.' . $tingkat)" class="w-full" />
                                </div>
                                <span class="col-span-3 text-xs text-muted dark:text-gray-400">digit</span>
                                <x-input-error :messages="$errors->get('digitList.' . $tingkat)" class="col-span-12" />
                            </div>
                        @endforeach
                        <x-input-error :messages="$errors->get('digitList')" />
                        @if ($kelola)
                            <div class="flex gap-2 pt-2">
                                <x-secondary-button type="button" wire:click="tambahTingkat" :disabled="count($digitList) >= FormatKodeAkun::TINGKAT_MAKS">+ Tingkat</x-secondary-button>
                                <x-secondary-button type="button" wire:click="kurangiTingkat" :disabled="count($digitList) <= FormatKodeAkun::TINGKAT_MIN">- Tingkat</x-secondary-button>
                            </div>
                        @endif
                    </div>
                </x-border-form>

                <x-border-form title="Pemisah & Akun Kontra">
                    <div class="space-y-4">
                        <div>
                            <x-input-label for="pemisah" value="Pemisah antar-tingkat" />
                            <x-select-input id="pemisah" wire:model.live="pemisah" :disabled="!$kelola" :error="$errors->has('pemisah')" class="w-full mt-1">
                                <option value=".">Titik ( . )</option>
                                <option value="-">Strip ( - )</option>
                                <option value="">Tanpa pemisah</option>
                            </x-select-input>
                            <x-input-error :messages="$errors->get('pemisah')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="awalKontra" value="Nomor awal akun kontra (tingkat terakhir)" />
                            <x-text-input id="awalKontra" type="number" min="0" wire:model.live.debounce.300ms="awalKontra" :disabled="!$kelola"
                                :error="$errors->has('awalKontra')" class="w-full mt-1" />
                            <x-input-error :messages="$errors->get('awalKontra')" class="mt-1" />
                            <p class="mt-1 text-[11px] text-muted dark:text-gray-400">
                                Saran kode otomatis tidak memakai nomor ini ke atas (dicadangkan untuk akumulasi penyusutan, cadangan piutang, dsb.). 0 = tidak ada cadangan.
                            </p>
                        </div>
                        <div class="p-3 border rounded-lg border-hairline bg-canvas dark:bg-gray-800 dark:border-gray-700">
                            <p class="text-xs text-muted dark:text-gray-400">Contoh kode akun detail</p>
                            <p class="mt-1 font-mono text-lg text-ink dark:text-gray-100" role="status" aria-live="polite">{{ $this->contohIsian() }}</p>
                            <p class="mt-1 text-[11px] text-muted dark:text-gray-400">Tersimpan sekarang: <span class="font-mono">{{ FormatKodeAkun::contoh() }}</span></p>
                        </div>
                    </div>
                </x-border-form>
            </div>

            @if ($kelola)
                <div class="flex justify-end gap-2">
                    <x-secondary-button type="button" wire:click="batal" wire:loading.attr="disabled" wire:target="batal">Batal</x-secondary-button>
                    <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Simpan Format</span>
                        <span wire:loading wire:target="save">Menyimpan...</span>
                    </x-primary-button>
                </div>
            @endif
        </div>
    </div>
</div>

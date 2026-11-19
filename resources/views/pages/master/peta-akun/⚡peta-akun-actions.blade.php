<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use App\Support\BaganAkun;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/**
 * Modal petakan / ubah peta satu akun SIRUS ke akun detail SIKEU, plus hapus peta yang akunnya sudah hilang dari SIRUS.
 * Peta dibaca saat POSTING snapshot, jadi perubahan berlaku untuk posting berikutnya; periode terkunci tidak ikut berubah.
 */
new class extends Component {
    use WithRenderVersioningTrait;

    public array $renderVersions = [];
    protected array $renderAreas = ['modal'];

    public string $accIdSirus = '';
    public string $namaSirus = '';
    public string $dkSirus = '';
    public string $akunIdLama = '';
    public string $akunId = '';

    public function mount(): void
    {
        $this->registerAreas(['modal']);
    }

    private function wajibBolehKelola(): void
    {
        abort_unless(auth()->user()?->can('akun.kelola'), 403);
    }

    #[On('master.peta-akun.openEdit')]
    public function openEdit(string $accIdSirus): void
    {
        $this->wajibBolehKelola();
        $this->resetValidation();
        $peta = DB::table('sikeumst_peta_akun_sirus')->where('acc_id_sirus', $accIdSirus)->first();
        $sirus = rescue(fn () => BaganAkun::akunSirusList()[$accIdSirus] ?? null, null, false);
        if ($peta === null && $sirus === null) {
            $this->dispatch('toast', type: 'error', message: 'Akun SIRUS tidak ditemukan.');
            return;
        }
        $this->accIdSirus = $accIdSirus;
        $this->namaSirus = (string) ($sirus['acc_name'] ?? $peta->nama_sirus ?? '');
        $this->dkSirus = (string) ($sirus['dk_status'] ?? '');
        $this->akunIdLama = (string) ($peta->akun_id ?? '');
        $this->akunId = $this->akunIdLama;
        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'peta-akun-actions');
    }

    protected function rules(): array
    {
        return [
            'akunId' => ['required', function (string $atribut, $nilai, $gagal) {
                $akun = DB::table('sikeumst_akuns')->where('akun_id', $nilai)->first();
                if (!$akun || $akun->tipe !== 'D' || $akun->aktif !== '1') {
                    $gagal('Tujuan harus akun Detail yang aktif.');
                }
            }],
        ];
    }

    protected function messages(): array
    {
        return ['required' => ':attribute wajib dipilih.'];
    }

    protected function validationAttributes(): array
    {
        return ['akunId' => 'Akun SIKEU'];
    }

    public function save(): void
    {
        $this->wajibBolehKelola();
        $this->validate();
        if ($this->akunId === $this->akunIdLama) {
            $this->closeModal();
            return;
        }

        DB::transaction(function () {
            $ada = DB::table('sikeumst_peta_akun_sirus')->where('acc_id_sirus', $this->accIdSirus)->exists();
            if ($ada) {
                DB::table('sikeumst_peta_akun_sirus')->where('acc_id_sirus', $this->accIdSirus)
                    ->update(['akun_id' => $this->akunId, 'nama_sirus' => $this->namaSirus ?: null, 'updated_at' => now()]);
            }
            if (!$ada) {
                DB::table('sikeumst_peta_akun_sirus')->insert([
                    'acc_id_sirus' => $this->accIdSirus, 'nama_sirus' => $this->namaSirus ?: null, 'akun_id' => $this->akunId,
                    'dibuat_oleh' => auth()->id(), 'created_at' => now(),
                ]);
            }
            BaganAkun::catatAudit($ada ? 'UBAH' : 'TAMBAH', 'SIKEUMST_PETA_AKUN_SIRUS', $this->accIdSirus,
                $ada ? ['akun_id' => $this->akunIdLama] : null, ['akun_id' => $this->akunId]);
        });

        $this->dispatch('toast', type: 'success', message: "Akun SIRUS {$this->accIdSirus} dipetakan ke {$this->akunId}.");
        $this->closeModal();
        $this->dispatch('master.peta-akun.saved');
    }

    #[On('master.peta-akun.requestDelete')]
    public function deletePeta(string $accIdSirus): void
    {
        $this->wajibBolehKelola();
        try {
            $masihAda = isset(BaganAkun::akunSirusList(true)[$accIdSirus]);
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: 'API SIRUS tidak terbaca — peta tidak dihapus supaya tak ada akun yang lepas.');
            return;
        }
        if ($masihAda) {
            $this->dispatch('toast', type: 'error', message: 'Akun ini masih ada di SIRUS. Ubah tujuannya, jangan dihapus.');
            return;
        }
        $peta = DB::table('sikeumst_peta_akun_sirus')->where('acc_id_sirus', $accIdSirus)->first();
        if ($peta) {
            DB::transaction(function () use ($accIdSirus, $peta) {
                DB::table('sikeumst_peta_akun_sirus')->where('acc_id_sirus', $accIdSirus)->delete();
                BaganAkun::catatAudit('HAPUS', 'SIKEUMST_PETA_AKUN_SIRUS', $accIdSirus, (array) $peta, null);
            });
        }
        $this->dispatch('toast', type: 'success', message: "Peta {$accIdSirus} dihapus.");
        $this->dispatch('master.peta-akun.saved');
    }

    public function closeModal(): void
    {
        $this->reset(['accIdSirus', 'namaSirus', 'dkSirus', 'akunIdLama', 'akunId']);
        $this->resetValidation();
        $this->dispatch('close-modal', name: 'peta-akun-actions');
        $this->resetVersion();
    }
};
?>

<div>
    <x-modal name="peta-akun-actions" size="full" height="full" focusable>
        <x-dirty-modal-content
            name="peta-akun-actions"
            event="master.peta-akun.saved"
            label="Peta Akun"
            :wireKey="$this->renderKey('modal', [$accIdSirus ?: 'kosong'])">

            <x-modul-dokumen.header judul="Peta Akun SIRUS {{ $accIdSirus }}"
                ikon="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" tutupAlpine="tryClose()">
                Transaksi SIRUS di akun ini masuk ke akun SIKEU yang dipilih saat snapshot diposting.
            </x-modul-dokumen.header>

            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20">
              <div class="grid items-start grid-cols-1 gap-4 lg:grid-cols-2">
                <x-border-form title="Akun SIRUS">
                    <dl class="grid grid-cols-3 gap-2 text-sm">
                        <dt class="text-muted dark:text-gray-400">Kode</dt>
                        <dd class="col-span-2 font-mono text-ink dark:text-gray-200">{{ $accIdSirus }}</dd>
                        <dt class="text-muted dark:text-gray-400">Nama</dt>
                        <dd class="col-span-2 text-ink dark:text-gray-200">{{ $namaSirus ?: '-' }}</dd>
                        <dt class="text-muted dark:text-gray-400">Saldo normal SIRUS</dt>
                        <dd class="col-span-2 text-ink dark:text-gray-200">{{ ['D' => 'Debit', 'K' => 'Kredit'][$dkSirus] ?? '-' }}</dd>
                    </dl>
                </x-border-form>

                <x-border-form title="Akun SIKEU *">
                    <x-select-input wire:model="akunId" :error="$errors->has('akunId')" class="w-full">
                        <option value="">- pilih akun detail -</option>
                        @foreach (BaganAkun::detailPerGolongan() as $golonganId => $golongan)
                            <optgroup label="{{ $golonganId }} {{ $golongan['nama'] }}">
                                @foreach ($golongan['akun'] as $akunDetailId => $namaAkun)
                                    <option value="{{ $akunDetailId }}">{{ $akunDetailId }} {{ $namaAkun }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </x-select-input>
                    <x-input-error :messages="$errors->get('akunId')" class="mt-1" />
                    @if ($akunIdLama !== '')
                        <p class="mt-2 text-xs text-muted dark:text-gray-400">Sebelumnya: <span class="font-mono">{{ $akunIdLama }}</span>. Hanya berlaku untuk posting berikutnya.</p>
                    @endif
                </x-border-form>
              </div>
            </div>

            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto bg-surface-soft border-t border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex items-center justify-end gap-2">
                    <x-secondary-button type="button" x-on:click="tryClose()"><x-ikon nama="batal" /> Batal</x-secondary-button>
                    <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-2"><x-ikon nama="simpan" /> Simpan</span>
                        <span wire:loading wire:target="save">Menyimpan...</span>
                    </x-primary-button>
                </div>
            </div>

        </x-dirty-modal-content>
    </x-modal>
</div>

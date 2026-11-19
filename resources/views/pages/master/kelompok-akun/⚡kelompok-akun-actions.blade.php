<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use App\Support\BaganAkun;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/**
 * Modal ubah kelompok akun: nama, laporan (Neraca/Laba Rugi), saldo normal bawaan, urutan.
 * Kode kelompok tetap (= digit pertama kode akun), kelompok tidak ditambah/dihapus dari layar.
 * Laporan & saldo normal dikunci bila ada akun kelompok ini yang sudah dijurnal / bersaldo —
 * memindah laporan setelah ada saldo merusak tutup buku tahun yang sudah lewat.
 */
new class extends Component {
    use WithRenderVersioningTrait;

    public array $renderVersions = [];
    protected array $renderAreas = ['modal'];

    public string $kelompokId = '';
    public array $form = [];
    public bool $sudahDipakai = false;

    public function mount(): void
    {
        $this->registerAreas(['modal']);
        $this->resetForm();
    }

    private function wajibBolehKelola(): void
    {
        abort_unless(auth()->user()?->can('akun.kelola'), 403);
    }

    private function kelompokSudahDipakai(string $kelompokId): bool
    {
        $akunList = DB::table('sikeumst_akuns')->where('kelompok_id', $kelompokId)->select('akun_id');

        return DB::table('sikeutxn_jurnal_dtls')->whereIn('akun_id', $akunList)->exists()
            || DB::table('sikeutxn_saldo_akuns')->whereIn('akun_id', $akunList)->exists();
    }

    #[On('master.kelompok-akun.openEdit')]
    public function openEdit(string $kelompokId): void
    {
        $this->wajibBolehKelola();
        $kelompok = DB::table('sikeumst_akun_kelompoks')->where('kelompok_id', $kelompokId)->first();
        if (!$kelompok) {
            $this->dispatch('toast', type: 'error', message: 'Kelompok tidak ditemukan.');
            return;
        }
        $this->resetForm();
        $this->kelompokId = $kelompokId;
        $this->sudahDipakai = $this->kelompokSudahDipakai($kelompokId);
        $this->form = [
            'nama' => (string) $kelompok->nama,
            'jenis_laporan' => (string) $kelompok->jenis_laporan,
            'saldo_normal' => (string) $kelompok->saldo_normal,
            'urut' => (string) $kelompok->urut,
        ];
        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'kelompok-akun-actions');
    }

    protected function rules(): array
    {
        return [
            'form.nama' => ['required', 'string', 'max:50', function (string $atribut, $nilai, $gagal) {
                if (!BaganAkun::teksAman((string) $nilai)) {
                    $gagal('Hanya huruf, angka & tanda baca biasa.');
                }
            }],
            'form.jenis_laporan' => 'required|in:N,L',
            'form.saldo_normal' => 'required|in:D,K',
            'form.urut' => 'required|integer|min:0|max:999',
        ];
    }

    protected function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'max' => ':attribute maksimal :max.',
            'min' => ':attribute minimal :min.',
            'integer' => ':attribute harus angka.',
            'in' => ':attribute tidak valid.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.nama' => 'Nama Kelompok',
            'form.jenis_laporan' => 'Laporan',
            'form.saldo_normal' => 'Saldo Normal',
            'form.urut' => 'Urutan',
        ];
    }

    public function save(): void
    {
        $this->wajibBolehKelola();
        $this->form['nama'] = mb_strtoupper(trim($this->form['nama']));
        $this->validate();

        $lama = (array) DB::table('sikeumst_akun_kelompoks')->where('kelompok_id', $this->kelompokId)->first();
        $baru = [
            'nama' => $this->form['nama'],
            'jenis_laporan' => $this->form['jenis_laporan'],
            'saldo_normal' => $this->form['saldo_normal'],
            'urut' => (int) $this->form['urut'],
        ];
        if (($lama['jenis_laporan'] !== $baru['jenis_laporan'] || $lama['saldo_normal'] !== $baru['saldo_normal'])
            && $this->kelompokSudahDipakai($this->kelompokId)) {
            $this->addError('form.jenis_laporan', 'Akun kelompok ini sudah dijurnal: laporan & saldo normal tidak boleh diubah.');
            return;
        }

        DB::transaction(function () use ($lama, $baru) {
            DB::table('sikeumst_akun_kelompoks')->where('kelompok_id', $this->kelompokId)->update($baru);
            BaganAkun::catatAudit('UBAH', 'SIKEUMST_AKUN_KELOMPOKS', $this->kelompokId, array_intersect_key($lama, $baru), $baru);
        });

        $this->dispatch('toast', type: 'success', message: "Kelompok {$this->kelompokId} diperbarui.");
        $this->closeModal();
        $this->dispatch('master.kelompok-akun.saved');
    }

    public function closeModal(): void
    {
        $this->resetForm();
        $this->dispatch('close-modal', name: 'kelompok-akun-actions');
        $this->resetVersion();
    }

    private function resetForm(): void
    {
        $this->kelompokId = '';
        $this->form = ['nama' => '', 'jenis_laporan' => 'N', 'saldo_normal' => 'D', 'urut' => '0'];
        $this->sudahDipakai = false;
        $this->resetValidation();
    }
};
?>

<div>
    <x-modal name="kelompok-akun-actions" size="full" height="full" focusable>
        <x-dirty-modal-content
            name="kelompok-akun-actions"
            event="master.kelompok-akun.saved"
            label="Kelompok Akun"
            :wireKey="$this->renderKey('modal', [$kelompokId ?: 'kosong'])">

            <x-modul-dokumen.header judul="Ubah Kelompok Akun {{ $kelompokId }}"
                ikon="M4 6h16M4 12h16M4 18h16" tutupAlpine="tryClose()">
                Semua akun berkode {{ $kelompokId }}.x ikut laporan & saldo normal bawaan kelompok ini.
            </x-modul-dokumen.header>

            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20">
                <div class="grid items-start grid-cols-1 gap-4 lg:grid-cols-2">

                    <x-border-form title="Kelompok">
                        <div class="space-y-4">
                            <div>
                                <x-input-label value="Kode" />
                                <x-text-input :value="$kelompokId" disabled class="w-full mt-1 font-mono" />
                                <p class="mt-1 text-[11px] text-muted dark:text-gray-400">Tetap — sama dengan digit pertama kode akun.</p>
                            </div>
                            <div>
                                <x-input-label value="Nama Kelompok *" />
                                <x-text-input wire:model="form.nama" maxlength="50" :error="$errors->has('form.nama')" class="w-full mt-1"
                                    x-on:keydown.enter.prevent="$wire.save()" />
                                <x-input-error :messages="$errors->get('form.nama')" class="mt-1" />
                                <p class="mt-1 text-[11px] text-muted dark:text-gray-400">Disimpan huruf besar. Contoh: badan hukum yayasan (ISAK 35) mengganti EKUITAS menjadi ASET NETO.</p>
                            </div>
                            <div>
                                <x-input-label value="Urutan di Laporan *" />
                                <x-text-input wire:model="form.urut" type="number" min="0" max="999" :error="$errors->has('form.urut')" class="w-full mt-1" />
                                <x-input-error :messages="$errors->get('form.urut')" class="mt-1" />
                            </div>
                        </div>
                    </x-border-form>

                    <x-border-form title="Setup Laporan">
                        @if ($sudahDipakai)
                            <p class="mb-3 text-xs text-fg-warning">Akun kelompok ini sudah dijurnal: laporan & saldo normal dikunci.</p>
                        @endif
                        <div class="space-y-4">
                            <div>
                                <x-input-label value="Masuk Laporan *" />
                                <x-select-input wire:model="form.jenis_laporan" :disabled="$sudahDipakai" :error="$errors->has('form.jenis_laporan')" class="w-full mt-1">
                                    <option value="N">Neraca (Laporan Posisi Keuangan)</option>
                                    <option value="L">Laba Rugi</option>
                                </x-select-input>
                                <x-input-error :messages="$errors->get('form.jenis_laporan')" class="mt-1" />
                                <p class="mt-1 text-[11px] text-muted dark:text-gray-400">
                                    Neraca: saldo akhir dibawa ke tahun berikutnya. Laba Rugi: mutasi per periode, dinolkan ke Saldo Laba saat tutup buku.
                                </p>
                            </div>
                            <div>
                                <x-input-label value="Saldo Normal Bawaan *" />
                                <x-select-input wire:model="form.saldo_normal" :disabled="$sudahDipakai" :error="$errors->has('form.saldo_normal')" class="w-full mt-1">
                                    <option value="D">Debit</option>
                                    <option value="K">Kredit</option>
                                </x-select-input>
                                <x-input-error :messages="$errors->get('form.saldo_normal')" class="mt-1" />
                                <p class="mt-1 text-[11px] text-muted dark:text-gray-400">Hanya bawaan untuk akun baru; saldo normal akun yang sudah ada tidak ikut berubah.</p>
                            </div>
                        </div>
                    </x-border-form>
                </div>
            </div>

            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto bg-surface-soft border-t border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex items-center justify-end gap-2">
                    <x-secondary-button type="button" x-on:click="tryClose()">Batal</x-secondary-button>
                    <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Perbarui</span>
                        <span wire:loading wire:target="save">Menyimpan...</span>
                    </x-primary-button>
                </div>
            </div>

        </x-dirty-modal-content>
    </x-modal>
</div>

<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use App\Support\BaganAkun;
use App\Support\FormatKodeAkun;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/**
 * Modal tambah/ubah akun + aksi nonaktif & hapus untuk Bagan Akun. Semua aksi dijaga gate akun.kelola di server.
 *
 * Aturan:
 *   - Akun baru di bawah header mana pun selain tingkat terakhir; format kode dari pengaturan (FormatKodeAkun).
 *     Tingkat terakhir selalu Detail, tingkat di atasnya boleh pilih Header/Detail.
 *   - Tingkat 1 = kelompok: ditambah/dihapus di Master › Kelompok Akun; ubah namanya di sini ikut ke kelompok.
 *   - Kode, induk, tipe tidak bisa diubah setelah dibuat (kode dipakai FK jurnal/peta/saldo).
 *   - Akun yang sudah dijurnal / bersaldo: saldo normal & sifat kas dikunci.
 *   - Nonaktif detail ditolak bila masih jadi tujuan peta SIRUS; nonaktif header ditolak bila masih punya anak aktif.
 *   - Hapus hanya bila tak punya anak, tak dipetakan, tak dijurnal (FK lain tertangkap ORA-02292).
 */
new class extends Component {
    use WithRenderVersioningTrait;

    public string $formMode = 'create'; // create|edit
    public array $renderVersions = [];
    protected array $renderAreas = ['modal'];

    public array $form = [];

    /** Tingkat & tipe akun yang sedang diisi — diturunkan dari induk (create) atau dari baris (edit). */
    public int $tingkat = 4;
    public string $tipe = 'D';
    public bool $sudahDipakai = false;

    /** Tipe boleh dipilih (tambah akun di bawah tingkat terakhir); di tingkat terakhir selalu Detail. */
    public bool $tipeBebas = false;

    public function mount(): void
    {
        $this->registerAreas(['modal']);
        $this->resetForm();
    }

    private function wajibBolehKelola(): void
    {
        abort_unless(auth()->user()?->can('akun.kelola'), 403);
    }

    // ─── Open ─────────────────────────────────────────────────────────────────
    #[On('master.bagan-akun.openCreate')]
    public function openCreate(?string $indukId = null): void
    {
        $this->wajibBolehKelola();
        $this->resetForm();
        $this->formMode = 'create';
        if ($indukId !== null) {
            $this->form['induk_id'] = $indukId;
            $this->terapkanInduk();
        }
        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'bagan-akun-actions');
    }

    #[On('master.bagan-akun.openEdit')]
    public function openEdit(string $akunId): void
    {
        $this->wajibBolehKelola();
        $akun = DB::table('sikeumst_akuns')->where('akun_id', $akunId)->first();
        if (!$akun) {
            $this->dispatch('toast', type: 'error', message: 'Akun tidak ditemukan.');
            return;
        }
        $this->resetForm();
        $this->formMode = 'edit';
        $this->tingkat = (int) $akun->tingkat;
        $this->tipe = (string) $akun->tipe;
        $this->sudahDipakai = BaganAkun::sudahDipakai($akunId);
        $this->form = [
            'akun_id' => (string) $akun->akun_id,
            'induk_id' => (string) ($akun->induk_id ?? ''),
            'nama' => (string) $akun->nama,
            'saldo_normal' => (string) $akun->saldo_normal,
            'kas_status' => (string) $akun->kas_status,
            'kategori_arus_kas' => (string) ($akun->kategori_arus_kas ?? ''),
            'kontrol_hp' => (string) ($akun->kontrol_hp ?? ''),
            'pusat_biaya_wajib' => (string) $akun->pusat_biaya_wajib,
            'keterangan' => (string) ($akun->keterangan ?? ''),
        ];
        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'bagan-akun-actions');
    }

    public function updatedFormIndukId(): void
    {
        if ($this->formMode === 'create') {
            $this->terapkanInduk();
        }
    }

    /** Isi saran kode, tingkat, tipe & bawaan sifat dari induk terpilih. */
    private function terapkanInduk(): void
    {
        $induk = DB::table('sikeumst_akuns')->where('akun_id', $this->form['induk_id'])->first();
        $tingkatAnak = $induk ? BaganAkun::tingkatAnak($induk) : null;
        if ($tingkatAnak === null) {
            $this->form['akun_id'] = '';
            return;
        }
        $this->tingkat = $tingkatAnak;
        $this->tipeBebas = $tingkatAnak < FormatKodeAkun::jumlahTingkat();
        $this->tipe = $this->tipeBebas ? 'H' : 'D';
        $this->form['akun_id'] = FormatKodeAkun::saranKode($induk->akun_id, $tingkatAnak);
        $this->form['saldo_normal'] = (string) $induk->saldo_normal;
        // Bawaan sifat ikut saudara (dipakai bila akunnya Detail)
        $saudaraKas = DB::table('sikeumst_akuns')->where('induk_id', $induk->akun_id)->where('kas_status', '1')->exists();
        $this->form['kas_status'] = $saudaraKas ? '1' : '0';
        $this->form['kategori_arus_kas'] = $saudaraKas ? '' : (string) (DB::table('sikeumst_akuns')
            ->where('induk_id', $induk->akun_id)->whereNotNull('kategori_arus_kas')->orderBy('akun_id')->value('kategori_arus_kas') ?? 'O');
    }

    public function updatedTipe(): void
    {
        if (!$this->tipeBebas || !in_array($this->tipe, ['H', 'D'], true)) {
            $this->tipe = 'D';
        }
    }

    // ─── Validasi ─────────────────────────────────────────────────────────────
    protected function rules(): array
    {
        $teksAman = function (string $atribut, $nilai, $gagal) {
            if (!BaganAkun::teksAman((string) $nilai)) {
                $gagal('Hanya huruf, angka & tanda baca biasa — simbol seperti tanda pisah panjang tidak tersimpan benar di database.');
            }
        };
        $rules = [
            'form.nama' => ['required', 'string', 'max:150', $teksAman],
            'form.keterangan' => ['nullable', 'string', 'max:500', $teksAman],
        ];
        if ($this->tipe === 'D') {
            $rules += [
                'form.saldo_normal' => 'required|in:D,K',
                'form.kas_status' => 'required|in:0,1',
                'form.kategori_arus_kas' => 'nullable|in:O,I,F',
                'form.kontrol_hp' => 'nullable|in:H,P',
                'form.pusat_biaya_wajib' => 'required|in:0,1',
            ];
        }
        if ($this->formMode === 'create') {
            $rules['form.induk_id'] = ['required', function (string $atribut, $nilai, $gagal) {
                $induk = DB::table('sikeumst_akuns')->where('akun_id', $nilai)->where('aktif', '1')->first();
                if (!$induk || BaganAkun::tingkatAnak($induk) === null) {
                    $gagal('Induk harus akun Header aktif yang belum di tingkat terakhir.');
                }
            }];
            $rules['form.akun_id'] = ['required', 'unique:sikeumst_akuns,akun_id', function (string $atribut, $nilai, $gagal) {
                $induk = DB::table('sikeumst_akuns')->where('akun_id', $this->form['induk_id'])->first();
                $tingkatAnak = $induk ? BaganAkun::tingkatAnak($induk) : null;
                if ($tingkatAnak !== null && !FormatKodeAkun::kodeSah((string) $nilai, $tingkatAnak, $induk->akun_id)) {
                    $gagal(FormatKodeAkun::petunjuk($tingkatAnak, $induk->akun_id));
                }
                if (strlen((string) $nilai) > FormatKodeAkun::PANJANG_KODE_MAKS) {
                    $gagal('Kode maksimal ' . FormatKodeAkun::PANJANG_KODE_MAKS . ' karakter.');
                }
            }];
            $rules['tipe'] = 'required|in:H,D';
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'max' => ':attribute maksimal :max karakter.',
            'in' => ':attribute tidak valid.',
            'unique' => ':attribute sudah dipakai akun lain.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.induk_id' => 'Induk',
            'form.akun_id' => 'Kode Akun',
            'form.nama' => 'Nama Akun',
            'form.saldo_normal' => 'Saldo Normal',
            'form.kas_status' => 'Akun Kas/Bank',
            'form.kategori_arus_kas' => 'Kategori Arus Kas',
            'form.kontrol_hp' => 'Kontrol Hutang/Piutang',
            'form.pusat_biaya_wajib' => 'Wajib Pusat Biaya',
            'tipe' => 'Tipe Akun',
            'form.keterangan' => 'Keterangan',
        ];
    }

    // ─── Save ─────────────────────────────────────────────────────────────────
    public function save(): void
    {
        $this->wajibBolehKelola();
        $this->form['nama'] = trim($this->form['nama']);
        $this->form['akun_id'] = trim($this->form['akun_id']);
        $this->form['keterangan'] = trim($this->form['keterangan']);
        if ($this->formMode === 'create' && !$this->tipeBebas) {
            $this->tipe = 'D';
        }
        if ($this->form['kas_status'] === '1') {
            $this->form['kategori_arus_kas'] = ''; // akun kas tidak punya kategori arus kas — ia sendiri sumber arusnya
        }
        $this->validate();

        $isian = ['nama' => $this->form['nama'], 'keterangan' => $this->form['keterangan'] ?: null];
        if ($this->tipe === 'D') {
            $isian += [
                'saldo_normal' => $this->form['saldo_normal'],
                'kas_status' => $this->form['kas_status'],
                'kategori_arus_kas' => $this->form['kategori_arus_kas'] ?: null,
                'kontrol_hp' => $this->form['kontrol_hp'] ?: null,
                'pusat_biaya_wajib' => $this->form['pusat_biaya_wajib'],
            ];
        }

        if ($this->formMode === 'create') {
            $induk = DB::table('sikeumst_akuns')->where('akun_id', $this->form['induk_id'])->first();
            $baru = $isian + [
                'akun_id' => $this->form['akun_id'],
                'kelompok_id' => $induk->kelompok_id,
                'induk_id' => $induk->akun_id,
                'tingkat' => $this->tingkat,
                'tipe' => $this->tipe,
                'saldo_normal' => $isian['saldo_normal'] ?? $induk->saldo_normal,
                'dibuat_oleh' => auth()->id(),
                'created_at' => now(),
            ];
            DB::transaction(function () use ($baru) {
                DB::table('sikeumst_akuns')->insert($baru);
                BaganAkun::catatAudit('TAMBAH', 'SIKEUMST_AKUNS', $baru['akun_id'], null, $baru);
            });
            $this->dispatch('toast', type: 'success', message: "Akun {$baru['akun_id']} ditambahkan.");
        }

        if ($this->formMode === 'edit') {
            $akunId = $this->form['akun_id'];
            $lama = (array) DB::table('sikeumst_akuns')->where('akun_id', $akunId)->first();
            if (BaganAkun::sudahDipakai($akunId) && $this->tipe === 'D'
                && ($lama['saldo_normal'] !== $isian['saldo_normal'] || $lama['kas_status'] !== $isian['kas_status'])) {
                $this->addError('form.saldo_normal', 'Akun sudah dijurnal: saldo normal & sifat kas tidak boleh diubah. Buat akun baru lalu reklasifikasi.');
                return;
            }
            DB::transaction(function () use ($akunId, $lama, $isian) {
                DB::table('sikeumst_akuns')->where('akun_id', $akunId)->update($isian + ['updated_at' => now()]);
                if ((int) $lama['tingkat'] === 1) {
                    // Akun tingkat 1 = kelompok: namanya satu, disimpan di dua tabel
                    DB::table('sikeumst_akun_kelompoks')->where('kelompok_id', $akunId)->update(['nama' => mb_strtoupper($isian['nama'])]);
                }
                BaganAkun::catatAudit('UBAH', 'SIKEUMST_AKUNS', $akunId, array_intersect_key($lama, $isian), $isian);
            });
            $this->dispatch('toast', type: 'success', message: "Akun {$akunId} diperbarui.");
        }

        $this->closeModal();
        $this->dispatch('master.bagan-akun.saved');
    }

    // ─── Nonaktif / aktif ─────────────────────────────────────────────────────
    #[On('master.bagan-akun.toggleAktif')]
    public function toggleAktif(string $akunId): void
    {
        $this->wajibBolehKelola();
        $akun = DB::table('sikeumst_akuns')->where('akun_id', $akunId)->first();
        if (!$akun || (int) $akun->tingkat < 2) {
            $this->dispatch('toast', type: 'error', message: 'Kelompok diatur di Master › Kelompok Akun.');
            return;
        }

        if ($akun->aktif === '1') {
            $petaList = DB::table('sikeumst_peta_akun_sirus')->where('akun_id', $akunId)->pluck('acc_id_sirus');
            if ($petaList->isNotEmpty()) {
                $this->dispatch('toast', type: 'error', message: "Akun masih tujuan peta SIRUS ({$petaList->take(5)->implode(', ')}" . ($petaList->count() > 5 ? ', ...' : '') . '). Pindahkan petanya dulu.');
                return;
            }
            if (DB::table('sikeumst_akuns')->where('induk_id', $akunId)->where('aktif', '1')->exists()) {
                $this->dispatch('toast', type: 'error', message: 'Masih ada akun anak yang aktif. Nonaktifkan anaknya dulu.');
                return;
            }
        }
        if ($akun->aktif === '0' && DB::table('sikeumst_akuns')->where('akun_id', $akun->induk_id)->value('aktif') !== '1') {
            $this->dispatch('toast', type: 'error', message: 'Induknya nonaktif. Aktifkan induknya dulu.');
            return;
        }

        $aktifBaru = $akun->aktif === '1' ? '0' : '1';
        DB::transaction(function () use ($akunId, $akun, $aktifBaru) {
            DB::table('sikeumst_akuns')->where('akun_id', $akunId)->update(['aktif' => $aktifBaru, 'updated_at' => now()]);
            BaganAkun::catatAudit($aktifBaru === '1' ? 'AKTIFKAN' : 'NONAKTIFKAN', 'SIKEUMST_AKUNS', $akunId, ['aktif' => $akun->aktif], ['aktif' => $aktifBaru]);
        });
        $this->dispatch('toast', type: 'success', message: "Akun {$akunId} " . ($aktifBaru === '1' ? 'diaktifkan.' : 'dinonaktifkan.'));
        $this->dispatch('master.bagan-akun.saved');
    }

    // ─── Hapus ────────────────────────────────────────────────────────────────
    #[On('master.bagan-akun.requestDelete')]
    public function deleteAkun(string $akunId): void
    {
        $this->wajibBolehKelola();
        $akun = DB::table('sikeumst_akuns')->where('akun_id', $akunId)->first();
        if (!$akun || (int) $akun->tingkat < 2) {
            $this->dispatch('toast', type: 'error', message: 'Kelompok dihapus lewat Master › Kelompok Akun.');
            return;
        }
        if (DB::table('sikeumst_akuns')->where('induk_id', $akunId)->exists()) {
            $this->dispatch('toast', type: 'error', message: 'Akun masih punya anak. Hapus/pindahkan anaknya dulu.');
            return;
        }
        if (DB::table('sikeumst_peta_akun_sirus')->where('akun_id', $akunId)->exists()) {
            $this->dispatch('toast', type: 'error', message: 'Akun masih tujuan peta SIRUS. Pindahkan petanya dulu.');
            return;
        }
        if (BaganAkun::sudahDipakai($akunId)) {
            $this->dispatch('toast', type: 'error', message: 'Akun sudah dijurnal — tidak bisa dihapus, nonaktifkan saja.');
            return;
        }

        try {
            DB::transaction(function () use ($akunId, $akun) {
                DB::table('sikeumst_akuns')->where('akun_id', $akunId)->delete();
                BaganAkun::catatAudit('HAPUS', 'SIKEUMST_AKUNS', $akunId, (array) $akun, null);
            });
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'ORA-02292')) {
                $this->dispatch('toast', type: 'error', message: 'Akun masih dipakai data lain (kategori aset, mitra, tahun buku, dst.) — nonaktifkan saja.');
                return;
            }
            throw $exception;
        }
        $this->dispatch('toast', type: 'success', message: "Akun {$akunId} dihapus.");
        $this->dispatch('master.bagan-akun.saved');
    }

    // ─── Close ────────────────────────────────────────────────────────────────
    public function closeModal(): void
    {
        $this->resetForm();
        $this->dispatch('close-modal', name: 'bagan-akun-actions');
        $this->resetVersion();
    }

    private function resetForm(): void
    {
        $this->form = [
            'akun_id' => '', 'induk_id' => '', 'nama' => '', 'saldo_normal' => 'D', 'kas_status' => '0',
            'kategori_arus_kas' => 'O', 'kontrol_hp' => '', 'pusat_biaya_wajib' => '0', 'keterangan' => '',
        ];
        $this->tingkat = 4;
        $this->tipe = 'D';
        $this->sudahDipakai = false;
        $this->tipeBebas = false;
        $this->resetValidation();
    }
};
?>

<div>
    <x-modal name="bagan-akun-actions" size="full" height="full" focusable>
        <x-dirty-modal-content
            name="bagan-akun-actions"
            event="master.bagan-akun.saved"
            label="Akun"
            :wireKey="$this->renderKey('modal', [$formMode, $form['akun_id'] ?: 'baru'])">

            <x-modul-dokumen.header :judul="$formMode === 'edit' ? 'Ubah Akun ' . $form['akun_id'] : 'Tambah Akun'"
                ikon="M4 6h16M4 10h16M4 14h10M4 18h6" tutupAlpine="tryClose()">
                {{ $tipe === 'D' ? 'Akun Detail — boleh dijurnal.' : 'Akun Header — wadah akun di bawahnya, tidak dijurnal.' }}
                Format kode: {{ FormatKodeAkun::contoh() }} ({{ FormatKodeAkun::jumlahTingkat() }} tingkat).
                <x-slot:badge><x-badge class="shrink-0 whitespace-nowrap" :variant="$formMode === 'edit' ? 'warning' : 'success'">{{ $formMode === 'edit' ? 'Mode: Edit' : 'Mode: Tambah' }}</x-badge></x-slot:badge>
            </x-modul-dokumen.header>

            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20">
              <div class="grid items-start grid-cols-1 gap-4 lg:grid-cols-2">

                <x-border-form title="Identitas Akun">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <x-input-label value="Induk *" />
                            @if ($formMode === 'create')
                                <x-select-input wire:model.live="form.induk_id" :error="$errors->has('form.induk_id')" class="w-full mt-1">
                                    <option value="">- pilih induk -</option>
                                    @foreach (BaganAkun::indukList() as $induk)
                                        <option value="{{ $induk->akun_id }}">{{ str_repeat(' ', ((int) $induk->tingkat - 1) * 3) }}{{ $induk->akun_id }} {{ $induk->nama }}</option>
                                    @endforeach
                                </x-select-input>
                                <x-input-error :messages="$errors->get('form.induk_id')" class="mt-1" />
                            @else
                                <x-text-input :value="$form['induk_id'] ?: '-'" disabled class="w-full mt-1" />
                            @endif
                        </div>
                        <div>
                            <x-input-label value="Kode Akun *" />
                            <x-text-input wire:model="form.akun_id" maxlength="30" :disabled="$formMode === 'edit' || $form['induk_id'] === ''"
                                :error="$errors->has('form.akun_id')" class="w-full mt-1 font-mono" />
                            <x-input-error :messages="$errors->get('form.akun_id')" class="mt-1" />
                            @if ($formMode === 'create' && $form['induk_id'] !== '')
                                <p class="mt-1 text-[11px] text-muted dark:text-gray-400">
                                    Tingkat {{ $tingkat }}, saran otomatis.
                                    @if (!$tipeBebas && FormatKodeAkun::awalKontra() > 0)
                                        Nomor {{ FormatKodeAkun::awalKontra() }} ke atas untuk akun kontra.
                                    @endif
                                </p>
                            @endif
                        </div>
                        @if ($formMode === 'create' && $form['induk_id'] !== '')
                            <div class="sm:col-span-3">
                                <x-input-label value="Tipe Akun *" />
                                <x-select-input wire:model.live="tipe" :disabled="!$tipeBebas" :error="$errors->has('tipe')" class="w-full mt-1">
                                    <option value="H">Header — wadah akun di bawahnya</option>
                                    <option value="D">Detail — boleh dijurnal</option>
                                </x-select-input>
                                <x-input-error :messages="$errors->get('tipe')" class="mt-1" />
                                @if (!$tipeBebas)
                                    <p class="mt-1 text-[11px] text-muted dark:text-gray-400">Tingkat terakhir selalu Detail.</p>
                                @endif
                            </div>
                        @endif
                        <div class="sm:col-span-3">
                            <x-input-label value="Nama Akun *" />
                            <x-text-input wire:model="form.nama" maxlength="150" :error="$errors->has('form.nama')" class="w-full mt-1"
                                x-on:keydown.enter.prevent="$wire.save()" />
                            <x-input-error :messages="$errors->get('form.nama')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-3">
                            <x-input-label value="Keterangan" />
                            <x-text-input wire:model="form.keterangan" maxlength="500" :error="$errors->has('form.keterangan')" class="w-full mt-1"
                                placeholder="Opsional — isi akun ini, batasannya, contoh transaksi" />
                            <x-input-error :messages="$errors->get('form.keterangan')" class="mt-1" />
                        </div>
                    </div>
                </x-border-form>

                @if ($tipe === 'D')
                    <x-border-form title="Sifat Akun">
                        @if ($sudahDipakai)
                            <p class="mb-3 text-xs text-fg-warning">Akun sudah dijurnal: saldo normal & sifat kas dikunci.</p>
                        @endif
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label value="Saldo Normal *" />
                                <x-select-input wire:model="form.saldo_normal" :disabled="$sudahDipakai" :error="$errors->has('form.saldo_normal')" class="w-full mt-1">
                                    <option value="D">Debit</option>
                                    <option value="K">Kredit</option>
                                </x-select-input>
                                <x-input-error :messages="$errors->get('form.saldo_normal')" class="mt-1" />
                                <p class="mt-1 text-[11px] text-muted dark:text-gray-400">Ikut induk; beda dari kelompoknya = akun kontra (mis. akumulasi penyusutan).</p>
                            </div>
                            <div>
                                <x-input-label value="Akun Kas/Bank" />
                                <x-select-input wire:model.live="form.kas_status" :disabled="$sudahDipakai" class="w-full mt-1">
                                    <option value="0">Bukan kas</option>
                                    <option value="1">Kas/Bank — dasar laporan arus kas</option>
                                </x-select-input>
                            </div>
                            <div>
                                <x-input-label value="Kategori Arus Kas" />
                                <x-select-input wire:model="form.kategori_arus_kas" :disabled="$form['kas_status'] === '1'" :error="$errors->has('form.kategori_arus_kas')" class="w-full mt-1">
                                    <option value="">-</option>
                                    @foreach (BaganAkun::LABEL_ARUS_KAS as $kode => $label)
                                        <option value="{{ $kode }}">{{ $label }}</option>
                                    @endforeach
                                </x-select-input>
                                <p class="mt-1 text-[11px] text-muted dark:text-gray-400">Masuk bagian mana di arus kas saat akun ini jadi lawan transaksi kas.</p>
                            </div>
                            <div>
                                <x-input-label value="Kontrol Hutang/Piutang" />
                                <x-select-input wire:model="form.kontrol_hp" :error="$errors->has('form.kontrol_hp')" class="w-full mt-1">
                                    <option value="">Bukan akun kontrol</option>
                                    @foreach (BaganAkun::LABEL_KONTROL_HP as $kode => $label)
                                        <option value="{{ $kode }}">{{ $label }} — wajib kartu per mitra</option>
                                    @endforeach
                                </x-select-input>
                            </div>
                            <div>
                                <x-input-label value="Wajib Pusat Biaya" />
                                <x-select-input wire:model="form.pusat_biaya_wajib" class="w-full mt-1">
                                    <option value="0">Tidak</option>
                                    <option value="1">Ya — baris jurnal wajib isi unit</option>
                                </x-select-input>
                            </div>
                        </div>
                    </x-border-form>
                @endif
              </div>
            </div>

            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto bg-surface-soft border-t border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex items-center justify-end gap-2">
                    <x-secondary-button type="button" x-on:click="tryClose()">Batal</x-secondary-button>
                    <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">{{ $formMode === 'edit' ? 'Perbarui' : 'Simpan' }}</span>
                        <span wire:loading wire:target="save">Menyimpan...</span>
                    </x-primary-button>
                </div>
            </div>

        </x-dirty-modal-content>
    </x-modal>
</div>

<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('/dashboard', 'dashboard')->name('dashboard');
});

Route::middleware(['auth', 'menu'])->group(function () {
    // Daftarkan modul SIKEU di sini (buku besar, laporan keuangan, tutup buku), lalu tambahkan
    // entri menunya di App\Services\AppMenu::definitions() — daftar role di sana sekaligus membatasi akses halaman.

    // ── MASTER ──
    Route::livewire('/master/kelompok-akun', 'pages::master.kelompok-akun.kelompok-akun')
        ->name('master.kelompok-akun');
    Route::livewire('/master/bagan-akun', 'pages::master.bagan-akun.bagan-akun')
        ->name('master.bagan-akun');
    Route::livewire('/master/peta-akun', 'pages::master.peta-akun.peta-akun')
        ->name('master.peta-akun');
    Route::livewire('/master/format-kode', 'pages::master.format-kode.format-kode')
        ->name('master.format-kode');

    // ── BUKU BESAR ──
    Route::livewire('/buku-besar/periode', 'pages::buku-besar.periode.periode')
        ->name('buku-besar.periode');
    Route::livewire('/buku-besar/saldo-awal', 'pages::buku-besar.saldo-awal.saldo-awal')
        ->name('buku-besar.saldo-awal');
    Route::livewire('/buku-besar/neraca-saldo', 'pages::buku-besar.neraca-saldo.neraca-saldo')
        ->name('buku-besar.neraca-saldo');
    Route::livewire('/buku-besar/buku-besar', 'pages::buku-besar.buku-besar.buku-besar')
        ->name('buku-besar.buku-besar');

    // ── LAPORAN ──
    Route::livewire('/laporan/laba-rugi', 'pages::laporan.laba-rugi.laba-rugi')
        ->name('laporan.laba-rugi');

    // ── CUTOFF JURNAL ──
    Route::livewire('/cutoff/snapshot-jurnal', 'pages::cutoff.snapshot-jurnal.snapshot-jurnal')
        ->name('cutoff.snapshot-jurnal');
    Route::livewire('/cutoff/banding-versi', 'pages::cutoff.banding-versi.banding-versi')
        ->name('cutoff.banding-versi');

    // ── SISTEM ──
    Route::livewire('/sistem/identitas', 'pages::sistem.identitas-instansi.identitas-instansi')
        ->name('sistem.identitas');
    Route::livewire('/sistem/user-control', 'pages::sistem.user-control.user-control')
        ->name('sistem.user-control');
    Route::livewire('/sistem/role-control', 'pages::sistem.role-control.role-control')
        ->name('sistem.role-control');
    Route::livewire('/sistem/sambungan-sirus', 'pages::sistem.sambungan-sirus.sambungan-sirus')
        ->name('sistem.sambungan-sirus');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';

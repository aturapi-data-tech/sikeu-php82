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

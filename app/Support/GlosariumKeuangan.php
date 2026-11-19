<?php

namespace App\Support;

/**
 * Glosarium istilah keuangan untuk Panduan › Istilah Keuangan. Satu sumber — tambah / ubah istilah di sini.
 * Contoh memakai konteks rumah sakit; angka contoh bersifat ilustrasi. Teks ASCII + tanda baca biasa.
 */
class GlosariumKeuangan
{
    /**
     * @return array<string, array{judul: string, pengantar: string, istilah: array<int, array{0: string, 1: string, 2: string}>}>
     *         [id bagian => [judul, pengantar, [[istilah, arti, contoh], ...]]]
     */
    public static function bagian(): array
    {
        return [
            'dasar' => [
                'judul' => '1. Dasar pembukuan',
                'pengantar' => 'Istilah yang dipakai di semua layar SIKEU.',
                'istilah' => [
                    ['Akun', 'Wadah untuk mencatat satu jenis nilai.', '1.1.01.001 Kas Kasir TU'],
                    ['Bagan Akun (COA)', 'Daftar semua akun beserta susunannya (kelompok, golongan, rincian).', 'Data Induk > Bagan Akun'],
                    ['Debit (D) / Kredit (K)', 'Sisi kiri / kanan pencatatan. Bukan berarti "tambah" atau "kurang" - artinya tergantung jenis akun.', 'Kas bertambah dicatat di D; utang bertambah dicatat di K.'],
                    ['Saldo normal', 'Sisi tempat akun biasanya bersaldo.', 'Kas = Debit, Utang = Kredit, Pendapatan = Kredit, Beban = Debit.'],
                    ['Double entry (berpasangan)', 'Setiap transaksi dicatat minimal di dua akun dengan nilai Debit = Kredit.', 'Pasien bayar tunai Rp 100.000: Kas D 100.000, Pendapatan K 100.000.'],
                    ['Jurnal', 'Catatan satu transaksi: akun mana yang didebit dan dikredit.', 'SR-202607-P01-03 = jurnal SIRUS tanggal 3 Juli.'],
                    ['Posting', 'Memasukkan jurnal ke buku besar sehingga resmi dan masuk laporan.', 'Pembukuan > Tarik Data SIRUS > Sahkan & Posting.'],
                    ['Buku Besar', 'Kumpulan mutasi satu akun dengan saldo berjalannya.', 'Buku Besar akun Kas TU bulan Juli.'],
                    ['Mutasi', 'Perubahan saldo dalam satu periode (jumlah debit dan jumlah kredit).', 'Mutasi kas bulan Juli.'],
                    ['Saldo awal / saldo akhir', 'Nilai akun di awal / akhir periode. Saldo akhir = saldo awal + debit - kredit (akun bersaldo normal debit).', 'Kas per 1 Januari dan per 31 Juli.'],
                    ['Neraca Saldo (Trial Balance)', 'Daftar saldo semua akun untuk membuktikan jumlah debit = jumlah kredit.', 'Pembukuan > Neraca Saldo, badge "Seimbang".'],
                ],
            ],
            'neraca' => [
                'judul' => '2. Neraca (Laporan Posisi Keuangan)',
                'pengantar' => 'Gambaran apa yang dimiliki RS dan dari mana sumbernya PADA SATU TANGGAL. Rumus dasar: Aset = Liabilitas + Ekuitas.',
                'istilah' => [
                    ['Aset', 'Semua yang dimiliki / dikuasai RS dan memberi manfaat ekonomi.', 'Kas, piutang, obat, gedung, alat medis.'],
                    ['Aset lancar', 'Aset yang menjadi kas atau habis terpakai dalam satu tahun.', 'Kas, piutang pasien, persediaan obat.'],
                    ['Aset tidak lancar', 'Aset yang dipakai lebih dari satu tahun.', 'Tanah, gedung, alat medis, perangkat lunak.'],
                    ['Aset tetap', 'Aset berwujud yang dipakai untuk operasional, bukan untuk dijual.', 'Gedung, alat medis, kendaraan.'],
                    ['Penyusutan', 'Pembebanan harga aset tetap sedikit demi sedikit selama umur pakainya.', 'Alat Rp 120 juta umur 10 tahun = beban Rp 1 juta per bulan.'],
                    ['Akumulasi penyusutan', 'Total penyusutan yang sudah dibebankan; akun kontra yang mengurangi nilai aset tetap.', 'Tampil sebagai pengurang di "Aset tetap - neto".'],
                    ['Nilai buku (neto)', 'Harga perolehan dikurangi akumulasi penyusutan / cadangan.', '"Aset tetap - neto", "Piutang usaha - neto".'],
                    ['Piutang', 'Hak menagih uang dari pihak lain.', 'Piutang pasien, piutang klaim BPJS, piutang karyawan.'],
                    ['Cadangan kerugian piutang', 'Perkiraan bagian piutang yang tidak akan tertagih; akun kontra piutang.', 'Klaim BPJS yang dispute bertahun-tahun.'],
                    ['Persediaan', 'Barang yang disimpan untuk dipakai atau dijual.', 'Obat dan BMHP di gudang farmasi & apotek.'],
                    ['Uang muka / biaya dibayar dimuka', 'Uang sudah dibayar tetapi barang/jasanya belum diterima - termasuk aset.', 'DP pembelian ke PBF, sewa dibayar setahun di muka.'],
                    ['Liabilitas (kewajiban)', 'Kewajiban membayar ke pihak lain.', 'Utang PBF, utang bank, utang pajak, utang jasa dokter.'],
                    ['Jangka pendek / jangka panjang', 'Jatuh tempo paling lama satu tahun / lebih dari satu tahun.', 'Utang PBF (pendek), pinjaman bank 5 tahun (panjang).'],
                    ['Uang muka pasien (deposit)', 'Uang pasien yang diterima SEBELUM tagihan final - liabilitas, bukan pendapatan.', 'Angsuran awal pasien rawat inap.'],
                    ['Beban masih harus dibayar (akrual)', 'Beban yang sudah terjadi tetapi belum dibayar.', 'Listrik Juli yang dibayar bulan Agustus.'],
                    ['Ekuitas', 'Hak pemilik: Aset dikurangi Liabilitas. Kekayaan bersih milik pemegang saham.', 'Modal + saldo laba + laba tahun berjalan.'],
                    ['Modal disetor', 'Uang yang disetor pemegang saham saat mendirikan / menambah modal PT.', 'Akun 3.1.01.001.'],
                    ['Saldo laba (laba ditahan)', 'Akumulasi laba tahun-tahun lalu yang tidak dibagikan sebagai dividen.', 'Akun 3.1.02.001.'],
                    ['Laba tahun berjalan', 'Laba sejak 1 Januari sampai tanggal neraca - sama dengan laba bersih kumulatif di Laba Rugi.', 'Baris "Laba (rugi) tahun berjalan" di Neraca.'],
                    ['Dividen', 'Laba yang dibagikan kepada pemegang saham; mengurangi saldo laba. Hanya untuk PT.', 'Keputusan RUPS.'],
                ],
            ],
            'laba-rugi' => [
                'judul' => '3. Laba Rugi',
                'pengantar' => 'Kinerja RS SELAMA SATU PERIODE. Rumus dasar: Pendapatan - Beban = Laba (Rugi).',
                'istilah' => [
                    ['Pendapatan usaha', 'Hasil dari kegiatan utama RS.', 'Jasa rawat jalan, rawat inap, kamar operasi, penjualan obat.'],
                    ['Pengurang pendapatan', 'Mengurangi pendapatan: retur, potongan, selisih tarif BPJS.', 'Retur obat, subsidi / potongan pasien.'],
                    ['Pendapatan neto', 'Pendapatan usaha dikurangi pengurangnya.', 'Baris "PENDAPATAN USAHA NETO".'],
                    ['Beban pokok pelayanan', 'Biaya yang LANGSUNG melekat pada pelayanan pasien.', 'HPP obat, BMHP, jasa medis dokter, gizi pasien.'],
                    ['HPP (Harga Pokok Penjualan)', 'Harga beli obat / barang yang terjual.', 'Obat dibeli Rp 8.000 dijual Rp 10.000: HPP Rp 8.000, laba kotor Rp 2.000.'],
                    ['BMHP', 'Bahan Medis Habis Pakai.', 'Kasa, spuit, cairan infus yang dipakai di ruangan.'],
                    ['Laba kotor', 'Pendapatan neto dikurangi beban pokok pelayanan.', 'Baris "LABA KOTOR PELAYANAN".'],
                    ['Beban usaha (operasional)', 'Biaya penunjang yang TIDAK langsung melekat pada pelayanan.', 'Gaji staf administrasi, listrik, ATK, penyusutan.'],
                    ['Laba usaha', 'Laba kotor dikurangi beban usaha.', 'Baris "LABA USAHA".'],
                    ['Pendapatan / beban lain-lain', 'Di luar kegiatan utama RS.', 'Bunga bank, laba jual aset, sewa kantin, bunga pinjaman.'],
                    ['Laba sebelum pajak', 'Laba usaha ditambah pendapatan lain dikurangi beban lain.', 'Dasar menghitung PPh badan.'],
                    ['Beban pajak penghasilan', 'PPh badan atas laba (tarif 22%).', 'Dihitung saat tutup tahun dari rekonsiliasi fiskal.'],
                    ['Laba (rugi) bersih', 'Hasil akhir periode; masuk ke Ekuitas sebagai laba tahun berjalan.', 'Baris terakhir Laba Rugi.'],
                    ['Kumulatif (YTD)', 'Year to date: dari Januari sampai bulan laporan.', 'Kolom "01 s/d 07/2026".'],
                ],
            ],
            'prinsip' => [
                'judul' => '4. Prinsip yang sering muncul',
                'pengantar' => 'Aturan main penyusunan laporan sesuai SAK EP.',
                'istilah' => [
                    ['Basis akrual', 'Transaksi dicatat SAAT TERJADI, bukan saat uang masuk / keluar.', 'Pasien dirawat Juli = pendapatan Juli walau dibayar Agustus.'],
                    ['Basis kas', 'Transaksi dicatat saat uang berpindah. Tidak dipakai untuk laporan keuangan SAK.', '-'],
                    ['Jurnal penyesuaian', 'Koreksi akhir bulan supaya laporan sesuai basis akrual.', 'Pendapatan pasien RI yang masih dirawat, gaji terutang, penyusutan.'],
                    ['Cut-off', 'Batas tanggal transaksi yang masuk ke suatu periode.', 'Snapshot = foto data SIRUS per cut-off.'],
                    ['Tutup buku', 'Menutup tahun: saldo akun Laba Rugi dinolkan ke saldo laba, saldo Neraca dibawa ke tahun berikutnya.', 'Jurnal penutup akhir Desember.'],
                    ['Akun kontra', 'Akun yang mengurangi akun induknya.', 'Akumulasi penyusutan, cadangan piutang, diskon pembelian.'],
                    ['Akun penampung (suspense)', 'Tempat sementara untuk nilai yang belum jelas; harus nol sebelum laporan final.', '1.1.09.001 Akun Penampung.'],
                    ['Rekonsiliasi', 'Mencocokkan dua sumber data yang seharusnya sama.', 'Kas di buku vs rekening koran; persediaan di buku vs kartu stok.'],
                    ['Materialitas', 'Seberapa besar suatu angka memengaruhi keputusan pembaca laporan.', 'Selisih Rp 100.000 tidak material; selisih miliaran sangat material.'],
                ],
            ],
            'laporan' => [
                'judul' => '5. Laporan keuangan lengkap (SAK EP, PT)',
                'pengantar' => 'Lima laporan yang membentuk satu set laporan keuangan.',
                'istilah' => [
                    ['Laporan Posisi Keuangan (Neraca)', 'Posisi aset, liabilitas, dan ekuitas pada satu tanggal.', 'Laporan Keuangan > Neraca.'],
                    ['Laporan Laba Rugi', 'Pendapatan dan beban selama satu periode.', 'Laporan Keuangan > Laba Rugi.'],
                    ['Laporan Perubahan Ekuitas', 'Dari ekuitas awal ke ekuitas akhir: laba, setoran modal, dividen.', 'Belum tersedia.'],
                    ['Laporan Arus Kas', 'Uang masuk & keluar dalam tiga kelompok: operasi, investasi, pendanaan.', 'Belum tersedia.'],
                    ['Arus kas operasi', 'Dari kegiatan utama.', 'Terima pembayaran pasien, bayar PBF, bayar gaji.'],
                    ['Arus kas investasi', 'Beli / jual aset jangka panjang.', 'Beli alat medis, jual kendaraan lama.'],
                    ['Arus kas pendanaan', 'Dari / kepada pemberi dana.', 'Pinjaman bank, setoran modal, dividen.'],
                    ['CALK', 'Catatan atas Laporan Keuangan: kebijakan akuntansi & rincian tiap pos.', 'Belum tersedia.'],
                    ['PT vs Yayasan', 'PT memakai Ekuitas & Laba; yayasan (nirlaba) memakai Aset Neto (tanpa / dengan pembatasan) & Surplus (Defisit), tanpa dividen.', 'RS ini berbadan hukum PT.'],
                ],
            ],
            'sikeu' => [
                'judul' => '6. Istilah khusus SIKEU',
                'pengantar' => 'Istilah yang muncul di layar-layar SIKEU.',
                'istilah' => [
                    ['Tarik data SIRUS (hasil tarikan / versi / snapshot)', 'Menyalin jurnal SIRUS satu bulan ke SIKEU. Tiap tarikan disimpan sebagai versi baru yang dibekukan; tarik ulang bila data SIRUS berubah, versi lama tidak ditimpa.', 'Pembukuan > Tarik Data SIRUS (Tarik dari SIRUS / Tarik & Sahkan Setahun).'],
                    ['Bandingkan versi', 'Mencari transaksi yang baru, hilang, atau berubah antara dua hasil tarikan.', 'Pembukuan > Bandingkan Versi.'],
                    ['Sahkan', 'Memilih hasil tarikan / saldo awal yang dipakai sebagai pembukuan resmi.', 'Tarik Data SIRUS, Saldo Awal.'],
                    ['Peta akun', 'Pasangan akun SIRUS ke akun SIKEU.', 'Data Induk > Peta Akun SIRUS.'],
                    ['Kunci periode', 'Bulan dinyatakan final; tidak bisa diposting ulang kecuali dibuka dengan alasan.', 'Tarik Data SIRUS > Kunci.'],
                    ['Template laporan', 'Susunan baris laporan yang disimpan sebagai data, bisa diubah tanpa program.', 'Baris Laba Rugi & Neraca.'],
                    ['Akun yatim', 'Akun yang tidak masuk baris laporan mana pun sehingga angkanya hilang dari laporan.', 'Peringatan merah di atas laporan.'],
                    ['Header / Detail', 'Akun wadah (tidak dijurnal) / akun yang dijurnal.', 'Header 1.1.01 Kas dan Setara Kas; Detail 1.1.01.001.'],
                    ['Status Sementara / Final', 'Laporan dari periode yang belum / sudah dikunci.', 'Badge di atas laporan.'],
                ],
            ],
        ];
    }
}

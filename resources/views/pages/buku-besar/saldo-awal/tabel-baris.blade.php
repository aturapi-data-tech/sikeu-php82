{{-- Tabel baris saldo awal (draft / sah). Variabel: $barisList, $bolehUbah, $total (header jurnal). Dipakai ⚡saldo-awal. --}}
<div class="overflow-x-auto">
    <table class="ds-table ds-table-entri">
        <thead>
            <tr>
                <th>Akun</th>
                <th>Nama Akun</th>
                <th class="text-right">Debit</th>
                <th class="text-right">Kredit</th>
                <th>Keterangan</th>
                @if ($bolehUbah)
                    <th class="ds-c">Aksi</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($barisList as $baris)
                @php $berlawanan = ((float) $baris['kredit'] > 0 && $baris['saldo_normal'] === 'D') || ((float) $baris['debit'] > 0 && $baris['saldo_normal'] === 'K'); @endphp
                <tr wire:key="sa-{{ $total->jurnal_id }}-{{ $baris['akun_id'] }}">
                    <td class="ds-td-token">{{ $baris['akun_id'] }}</td>
                    <td class="text-body dark:text-gray-300">{{ $baris['nama'] }}</td>
                    <td class="text-right tabular-nums {{ $berlawanan ? 'text-fg-danger-strong font-medium' : '' }}">{{ (float) $baris['debit'] != 0 ? \App\Support\Format::uang($baris['debit']) : '' }}</td>
                    <td class="text-right tabular-nums {{ $berlawanan ? 'text-fg-danger-strong font-medium' : '' }}">{{ (float) $baris['kredit'] != 0 ? \App\Support\Format::uang($baris['kredit']) : '' }}</td>
                    <td class="text-xs text-muted dark:text-gray-400">{{ $baris['keterangan'] }}</td>
                    @if ($bolehUbah)
                        <td class="ds-c">
                            <div class="flex justify-end gap-2">
                                <x-action-edit wire:click="bukaBaris('{{ $baris['akun_id'] }}')" />
                                <x-action-delete :action="'hapusBaris(\'' . $baris['akun_id'] . '\')'" title="Hapus Baris"
                                    message="Hapus saldo awal akun {{ $baris['akun_id'] }} dari draft?" />
                            </div>
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="6" class="px-6 py-6 text-center text-muted">Belum ada baris.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="font-bold bg-surface-card text-ink dark:bg-gray-800 dark:text-gray-100">
                <td colspan="2" class="px-3 py-3">TOTAL</td>
                <td class="px-3 py-3 text-right tabular-nums">{{ \App\Support\Format::uang($total->total_debit) }}</td>
                <td class="px-3 py-3 text-right tabular-nums">{{ \App\Support\Format::uang($total->total_kredit) }}</td>
                <td colspan="{{ $bolehUbah ? 2 : 1 }}"></td>
            </tr>
        </tfoot>
    </table>
    <p class="px-4 py-2 text-[11px] text-muted dark:text-gray-400">Angka merah = saldo berlawanan dengan saldo normal akunnya (mis. persediaan bersaldo kredit) — periksa sumbernya di SIRUS.</p>
</div>

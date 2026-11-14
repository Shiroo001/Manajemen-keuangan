<?php
if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed.');
}

$appsScriptTemplate = <<<'JS'
const SECRET_KEY = ""; // Opsional: samakan dengan Secret Token di aplikasi jika diisi

function doPost(e) {
  try {
    const payload = JSON.parse(e.postData.contents);
    if (SECRET_KEY && payload.secret !== SECRET_KEY) {
      return ContentService.createTextOutput(JSON.stringify({ ok: false, error: "Unauthorized" }))
        .setMimeType(ContentService.MimeType.JSON);
    }

    const ss = SpreadsheetApp.getActiveSpreadsheet();

    // 1. Sheet Transaksi
    let trxSheet = ss.getSheetByName("Transaksi") || ss.insertSheet("Transaksi");
    trxSheet.clear();
    trxSheet.appendRow(["ID", "Tanggal", "Tipe", "Kategori", "Dompet", "Nominal", "Catatan"]);
    trxSheet.getRange("A1:G1").setFontWeight("bold").setBackground("#0f172a").setFontColor("#ffffff");
    if (payload.transactions && payload.transactions.length > 0) {
      const rows = payload.transactions.map(t => [
        t.id,
        t.transaction_date,
        t.type === "income" ? "Pemasukan" : "Pengeluaran",
        t.category_name || "",
        t.wallet_name || "",
        Number(t.amount),
        t.description || ""
      ]);
      trxSheet.getRange(2, 1, rows.length, 7).setValues(rows);
      trxSheet.getRange(2, 6, rows.length, 1).setNumberFormat("#,##0");
    }

    // 2. Sheet Dompet
    let walSheet = ss.getSheetByName("Dompet") || ss.insertSheet("Dompet");
    walSheet.clear();
    walSheet.appendRow(["ID", "Nama Dompet", "Saldo Saat Ini", "Terakhir Sync"]);
    walSheet.getRange("A1:D1").setFontWeight("bold").setBackground("#0f172a").setFontColor("#ffffff");
    if (payload.wallets && payload.wallets.length > 0) {
      const wRows = payload.wallets.map(w => [w.id, w.name, Number(w.balance), payload.synced_at]);
      walSheet.getRange(2, 1, wRows.length, 4).setValues(wRows);
      walSheet.getRange(2, 3, wRows.length, 1).setNumberFormat("#,##0");
    }

    // 3. Sheet Transfer
    let trfSheet = ss.getSheetByName("Transfer") || ss.insertSheet("Transfer");
    trfSheet.clear();
    trfSheet.appendRow(["ID", "Tanggal", "Dari Dompet", "Ke Dompet", "Nominal", "Catatan"]);
    trfSheet.getRange("A1:F1").setFontWeight("bold").setBackground("#0f172a").setFontColor("#ffffff");
    if (payload.transfers && payload.transfers.length > 0) {
      const tRows = payload.transfers.map(tr => [
        tr.id,
        tr.transfer_date,
        tr.from_name || "",
        tr.to_name || "",
        Number(tr.amount),
        tr.note || ""
      ]);
      trfSheet.getRange(2, 1, tRows.length, 6).setValues(tRows);
      trfSheet.getRange(2, 5, tRows.length, 1).setNumberFormat("#,##0");
    }

    return ContentService.createTextOutput(JSON.stringify({ ok: true, synced_at: payload.synced_at }))
      .setMimeType(ContentService.MimeType.JSON);
  } catch (err) {
    return ContentService.createTextOutput(JSON.stringify({ ok: false, error: err.toString() }))
      .setMimeType(ContentService.MimeType.JSON);
  }
}
JS;
?>
<!-- ========================================== -->
<!-- MODAL 1: CREATE / EDIT TRANSACTION         -->
<!-- ========================================== -->
<div id="modalTrx" class="hidden fixed inset-0 bg-black/60 backdrop-blur-[1px] flex items-center justify-center p-4 z-50" data-modal-backdrop>
    <div class="bg-white dark:bg-slate-900 border border-transparent dark:border-slate-800 rounded-xl shadow-xl w-full max-w-md p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 id="modalTrxTitle" class="text-lg font-bold text-slate-900 dark:text-white">Catat Transaksi Baru</h3>
            <button type="button" onclick="closeModal('modalTrx')" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 font-bold text-xl leading-none">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="trxAction" value="create_transaction">
            <input type="hidden" name="id" id="trxId" value="">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Tipe Transaksi</label>
                    <select name="type" id="trxType" onchange="filterCategoryDropdown('trxType', 'trxCategory')" class="w-full bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm">
                        <option value="expense">Pengeluaran (Expense)</option>
                        <option value="income">Pemasukan (Income)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Tanggal</label>
                    <input type="date" name="transaction_date" id="trxDate" value="<?= date('Y-m-d') ?>" required class="w-full bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm [color-scheme:light] dark:[color-scheme:dark]">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Dompet</label>
                    <select name="wallet_id" id="trxWallet" required class="w-full bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm">
                        <?php foreach ($activeWallets as $w): ?>
                            <option value="<?= $w['id'] ?>"><?= e($w['name']) ?> (<?= rupiah($w['balance']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Kategori</label>
                    <select name="category_id" id="trxCategory" required class="w-full bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm">
                        <?php foreach ($activeCategories as $c): ?>
                            <option value="<?= $c['id'] ?>" data-type="<?= $c['type'] ?>"><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Nominal (Rp)</label>
                <input type="number" step="0.01" min="1" name="amount" id="trxAmount" required placeholder="Contoh: 50000" class="w-full bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Catatan (Opsional)</label>
                <input type="text" name="description" id="trxDesc" maxlength="255" placeholder="Makan siang, bayar listrik, dll." class="w-full bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm">
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeModal('modalTrx')" class="px-4 py-2 text-sm bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-lg">Batal</button>
                <button type="submit" class="px-4 py-2 text-sm bg-emerald-600 hover:bg-emerald-500 text-white font-semibold rounded-lg">Simpan Transaksi</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 2: TRANSFER ANTAR-DOMPET             -->
<!-- ========================================== -->
<div id="modalTransfer" class="hidden fixed inset-0 bg-black/60 backdrop-blur-[1px] flex items-center justify-center p-4 z-50" data-modal-backdrop>
    <div class="bg-white dark:bg-slate-900 border border-transparent dark:border-slate-800 rounded-xl shadow-xl w-full max-w-lg p-6 max-h-[85vh] overflow-y-auto space-y-5">
        <div class="flex justify-between items-center">
            <div>
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Transfer Saldo Antar-Dompet</h3>
                <p class="text-xs text-slate-400">Memindahkan dana antar dompet tanpa memengaruhi total pemasukan/pengeluaran.</p>
            </div>
            <button type="button" onclick="closeModal('modalTransfer')" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 font-bold text-xl leading-none">&times;</button>
        </div>

        <form method="POST" class="space-y-3 bg-slate-50 dark:bg-slate-800/60 p-4 rounded-xl border border-slate-200 dark:border-slate-700">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_transfer">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Dari Dompet</label>
                    <select name="from_wallet_id" required class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-2 text-sm">
                        <?php foreach ($activeWallets as $w): ?>
                            <option value="<?= $w['id'] ?>"><?= e($w['name']) ?> (<?= rupiah($w['balance']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Ke Dompet Tujuan</label>
                    <select name="to_wallet_id" required class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-2 text-sm">
                        <?php foreach ($activeWallets as $idx => $w): ?>
                            <option value="<?= $w['id'] ?>" <?= $idx === 1 ? 'selected' : '' ?>><?= e($w['name']) ?> (<?= rupiah($w['balance']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Nominal Transfer (Rp)</label>
                    <input type="number" step="0.01" min="1" name="amount" required placeholder="100000" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Tanggal</label>
                    <input type="date" name="transfer_date" value="<?= date('Y-m-d') ?>" required class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm [color-scheme:light] dark:[color-scheme:dark]">
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Catatan (Opsional)</label>
                <input type="text" name="note" maxlength="255" placeholder="Contoh: Tarik tunai ATM, Top up e-wallet" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm">
            </div>
            <button type="submit" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold rounded-lg transition">
                ⇄ Pindahkan Saldo Sekarang
            </button>
        </form>

        <div>
            <h4 class="text-xs font-bold uppercase text-slate-400 mb-2">Riwayat Transfer Terakhir</h4>
            <?php if (empty($recentTransfers)): ?>
                <p class="text-xs text-slate-400">Belum ada riwayat transfer antar-dompet.</p>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($recentTransfers as $trf): ?>
                        <div class="flex justify-between items-center p-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-lg text-xs">
                            <div>
                                <p class="font-bold text-slate-800 dark:text-slate-100">
                                    <?= e($trf['from_name']) ?> &rarr; <?= e($trf['to_name']) ?>
                                    <span class="ml-1.5 text-indigo-600 dark:text-indigo-400"><?= rupiah($trf['amount']) ?></span>
                                </p>
                                <p class="text-slate-400"><?= e($trf['transfer_date']) ?><?= $trf['note'] ? ' &bull; ' . e($trf['note']) : '' ?></p>
                            </div>
                            <form method="POST" onsubmit="return confirm('Batalkan transfer ini dan kembalikan saldo?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_transfer">
                                <input type="hidden" name="id" value="<?= $trf['id'] ?>">
                                <button type="submit" class="text-rose-500 hover:underline font-semibold">Batalkan</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 3: TRANSAKSI OTOMATIS (RECURRING)    -->
<!-- ========================================== -->
<div id="modalRecurring" class="hidden fixed inset-0 bg-black/60 backdrop-blur-[1px] flex items-center justify-center p-4 z-50" data-modal-backdrop>
    <div class="bg-white dark:bg-slate-900 border border-transparent dark:border-slate-800 rounded-xl shadow-xl w-full max-w-xl p-6 max-h-[85vh] overflow-y-auto space-y-5">
        <div class="flex justify-between items-center">
            <div>
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Jadwal Transaksi Otomatis (Bulanan)</h3>
                <p class="text-xs text-slate-400">Otomatis dicatat setiap bulan saat aplikasi dibuka pada/setelah tanggal jatuh tempo.</p>
            </div>
            <button type="button" onclick="closeModal('modalRecurring')" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 font-bold text-xl leading-none">&times;</button>
        </div>

        <form method="POST" class="space-y-3 bg-slate-50 dark:bg-slate-800/60 p-4 rounded-xl border border-slate-200 dark:border-slate-700">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_recurring">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Tipe</label>
                    <select name="type" id="recType" onchange="filterCategoryDropdown('recType', 'recCategory')" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-2 text-sm">
                        <option value="expense">Pengeluaran</option>
                        <option value="income">Pemasukan</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Setiap Tanggal (1-28)</label>
                    <input type="number" min="1" max="28" name="day_of_month" value="1" required class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Nominal (Rp)</label>
                    <input type="number" step="0.01" min="1" name="amount" required placeholder="500000" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm">
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Dompet</label>
                    <select name="wallet_id" required class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-2 text-sm">
                        <?php foreach ($activeWallets as $w): ?>
                            <option value="<?= $w['id'] ?>"><?= e($w['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Kategori</label>
                    <select name="category_id" id="recCategory" required class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-2 text-sm">
                        <?php foreach ($activeCategories as $c): ?>
                            <option value="<?= $c['id'] ?>" data-type="<?= $c['type'] ?>"><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Keterangan Rutin</label>
                <input type="text" name="description" maxlength="255" required placeholder="Contoh: Gaji Bulanan, Bayar Kos, Paket WiFi" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-sm">
            </div>
            <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300 cursor-pointer">
                <input type="checkbox" name="run_this_month" value="1" class="rounded border-slate-300">
                <span>Langsung eksekusi &amp; catat untuk bulan ini juga</span>
            </label>
            <button type="submit" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold rounded-lg transition">
                + Simpan Jadwal Otomatis
            </button>
        </form>

        <div class="space-y-2">
            <h4 class="text-xs font-bold uppercase text-slate-400">Daftar Jadwal Aktif &amp; Nonaktif</h4>
            <?php if (empty($recurringList)): ?>
                <p class="text-xs text-slate-400">Belum ada jadwal transaksi rutin.</p>
            <?php endif; ?>
            <?php foreach ($recurringList as $r): ?>
                <div class="flex justify-between items-center gap-3 p-3 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-lg text-xs <?= $r['is_active'] ? '' : 'opacity-50' ?>">
                    <div>
                        <p class="font-bold text-slate-800 dark:text-slate-100">
                            Tgl <?= $r['day_of_month'] ?> &bull; <?= e($r['description']) ?>
                            <span class="ml-1.5 font-bold <?= $r['type'] === 'income' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' ?>">
                                (<?= $r['type'] === 'income' ? '+' : '-' ?><?= rupiah($r['amount']) ?>)
                            </span>
                        </p>
                        <p class="text-slate-400">
                            <?= e($r['wallet_name']) ?> &bull; <?= e($r['category_name']) ?>
                            &bull; Terakhir jalan: <?= $r['last_executed_month'] ? e($r['last_executed_month']) : 'Belum pernah' ?>
                        </p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_recurring">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button type="submit" class="px-2 py-1 rounded font-semibold <?= $r['is_active'] ? 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300' : 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' ?>">
                                <?= $r['is_active'] ? 'Jeda' : 'Aktifkan' ?>
                            </button>
                        </form>
                        <form method="POST" onsubmit="return confirm('Hapus jadwal otomatis ini?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_recurring">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button type="submit" class="text-rose-500 hover:underline font-semibold">Hapus</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 4: MANAGE WALLETS                    -->
<!-- ========================================== -->
<div id="modalWallet" class="hidden fixed inset-0 bg-black/60 backdrop-blur-[1px] flex items-center justify-center p-4 z-50" data-modal-backdrop>
    <div class="bg-white dark:bg-slate-900 border border-transparent dark:border-slate-800 rounded-xl shadow-xl w-full max-w-lg p-6 max-h-[85vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Kelola Dompet</h3>
            <button type="button" onclick="closeModal('modalWallet')" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 font-bold text-xl leading-none">&times;</button>
        </div>

        <form method="POST" class="grid grid-cols-12 gap-2 mb-6 bg-slate-50 dark:bg-slate-800/60 p-3 rounded-lg border border-slate-200 dark:border-slate-700">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_wallet">
            <input type="text" name="name" maxlength="100" required placeholder="Nama Dompet (misal: BCA)" class="col-span-12 sm:col-span-6 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-1.5 text-sm">
            <input type="number" step="0.01" name="balance" required placeholder="Saldo Awal" value="0" class="col-span-8 sm:col-span-4 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-1.5 text-sm">
            <button type="submit" class="col-span-4 sm:col-span-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-500 py-1.5">Tambah</button>
        </form>

        <div class="space-y-2">
            <?php foreach ($activeWallets as $w): ?>
                <div class="flex items-center gap-2 border border-slate-200 dark:border-slate-700 p-2.5 rounded-lg">
                    <form method="POST" class="flex-1 flex items-center gap-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_wallet">
                        <input type="hidden" name="id" value="<?= $w['id'] ?>">
                        <input type="text" name="name" maxlength="100" value="<?= e($w['name']) ?>" required class="w-1/2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded px-2 py-1 text-sm">
                        <input type="number" step="0.01" name="balance" value="<?= $w['balance'] ?>" required class="w-1/2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded px-2 py-1 text-sm">
                        <button type="submit" class="text-xs bg-blue-600 hover:bg-blue-500 text-white px-2.5 py-1.5 rounded font-semibold">Simpan</button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Pindahkan dompet ini ke Trash?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_wallet">
                        <input type="hidden" name="id" value="<?= $w['id'] ?>">
                        <button type="submit" class="text-xs bg-rose-100 dark:bg-rose-950/80 text-rose-700 dark:text-rose-300 px-2.5 py-1.5 rounded font-semibold">Hapus</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 5: MANAGE CATEGORIES                 -->
<!-- ========================================== -->
<div id="modalCategory" class="hidden fixed inset-0 bg-black/60 backdrop-blur-[1px] flex items-center justify-center p-4 z-50" data-modal-backdrop>
    <div class="bg-white dark:bg-slate-900 border border-transparent dark:border-slate-800 rounded-xl shadow-xl w-full max-w-lg p-6 max-h-[85vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Kelola Kategori</h3>
            <button type="button" onclick="closeModal('modalCategory')" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 font-bold text-xl leading-none">&times;</button>
        </div>

        <form method="POST" class="grid grid-cols-12 gap-2 mb-6 bg-slate-50 dark:bg-slate-800/60 p-3 rounded-lg border border-slate-200 dark:border-slate-700">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_category">
            <input type="text" name="name" maxlength="100" required placeholder="Nama Kategori" class="col-span-12 sm:col-span-6 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-1.5 text-sm">
            <select name="type" class="col-span-8 sm:col-span-4 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded-lg px-2 py-1.5 text-sm">
                <option value="expense">Pengeluaran</option>
                <option value="income">Pemasukan</option>
            </select>
            <button type="submit" class="col-span-4 sm:col-span-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-500 py-1.5">Tambah</button>
        </form>

        <div class="space-y-2">
            <?php foreach ($activeCategories as $c): ?>
                <div class="flex items-center gap-2 border border-slate-200 dark:border-slate-700 p-2.5 rounded-lg">
                    <form method="POST" class="flex-1 flex items-center gap-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_category">
                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                        <input type="text" name="name" maxlength="100" value="<?= e($c['name']) ?>" required class="w-1/2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded px-2 py-1 text-sm">
                        <select name="type" class="w-1/2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded px-2 py-1 text-sm">
                            <option value="expense" <?= $c['type'] === 'expense' ? 'selected' : '' ?>>Pengeluaran</option>
                            <option value="income" <?= $c['type'] === 'income' ? 'selected' : '' ?>>Pemasukan</option>
                        </select>
                        <button type="submit" class="text-xs bg-blue-600 hover:bg-blue-500 text-white px-2.5 py-1.5 rounded font-semibold">Simpan</button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Pindahkan kategori ini ke Trash?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_category">
                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                        <button type="submit" class="text-xs bg-rose-100 dark:bg-rose-950/80 text-rose-700 dark:text-rose-300 px-2.5 py-1.5 rounded font-semibold">Hapus</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 6: TRASH BIN                         -->
<!-- ========================================== -->
<div id="modalTrash" class="hidden fixed inset-0 bg-black/60 backdrop-blur-[1px] flex items-center justify-center p-4 z-50" data-modal-backdrop>
    <div class="bg-white dark:bg-slate-900 border border-transparent dark:border-slate-800 rounded-xl shadow-xl w-full max-w-2xl p-6 max-h-[85vh] overflow-y-auto space-y-6">
        <div class="flex justify-between items-center">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Trash Bin (Soft Deleted)</h3>
            <button type="button" onclick="closeModal('modalTrash')" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 font-bold text-xl leading-none">&times;</button>
        </div>

        <div>
            <h4 class="text-xs font-bold uppercase text-slate-400 mb-2">Transaksi Terhapus (<?= count($trashedTransactions) ?>)</h4>
            <?php if (empty($trashedTransactions)): ?>
                <p class="text-sm text-slate-400">Kosong.</p>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($trashedTransactions as $tt): ?>
                        <div class="flex flex-wrap justify-between items-center gap-2 p-3 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-lg text-sm">
                            <div>
                                <span class="font-semibold text-slate-800 dark:text-slate-100"><?= e($tt['category_name']) ?></span> &bull; <span class="text-slate-600 dark:text-slate-300"><?= e($tt['wallet_name']) ?></span>
                                <span class="ml-2 font-bold <?= $tt['type'] === 'income' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' ?>"><?= rupiah($tt['amount']) ?></span>
                                <p class="text-xs text-slate-400">Tanggal: <?= e($tt['transaction_date']) ?> | Dihapus: <?= e($tt['deleted_at']) ?></p>
                            </div>
                            <div class="flex gap-2">
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="restore_transaction">
                                    <input type="hidden" name="id" value="<?= $tt['id'] ?>">
                                    <button type="submit" class="px-2.5 py-1 text-xs bg-emerald-600 hover:bg-emerald-500 text-white rounded font-semibold">Restore</button>
                                </form>
                                <form method="POST" onsubmit="return confirm('Hapus permanen dari database?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="force_delete_transaction">
                                    <input type="hidden" name="id" value="<?= $tt['id'] ?>">
                                    <button type="submit" class="px-2.5 py-1 text-xs bg-rose-600 hover:bg-rose-500 text-white rounded font-semibold">Hapus Permanen</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <h4 class="text-xs font-bold uppercase text-slate-400 mb-2">Dompet Terhapus (<?= count($trashedWallets) ?>)</h4>
                <?php if (empty($trashedWallets)): ?>
                    <p class="text-xs text-slate-400">Kosong.</p>
                <?php endif; ?>
                <?php foreach ($trashedWallets as $tw): ?>
                    <div class="flex justify-between items-center p-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-lg text-sm mb-2">
                        <span class="text-slate-800 dark:text-slate-100"><?= e($tw['name']) ?></span>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="restore_wallet">
                            <input type="hidden" name="id" value="<?= $tw['id'] ?>">
                            <button type="submit" class="px-2 py-1 text-xs bg-emerald-600 hover:bg-emerald-500 text-white rounded font-semibold">Restore</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
            <div>
                <h4 class="text-xs font-bold uppercase text-slate-400 mb-2">Kategori Terhapus (<?= count($trashedCategories) ?>)</h4>
                <?php if (empty($trashedCategories)): ?>
                    <p class="text-xs text-slate-400">Kosong.</p>
                <?php endif; ?>
                <?php foreach ($trashedCategories as $tc): ?>
                    <div class="flex justify-between items-center p-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-lg text-sm mb-2">
                        <span class="text-slate-800 dark:text-slate-100"><?= e($tc['name']) ?></span>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="restore_category">
                            <input type="hidden" name="id" value="<?= $tc['id'] ?>">
                            <button type="submit" class="px-2 py-1 text-xs bg-emerald-600 hover:bg-emerald-500 text-white rounded font-semibold">Restore</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 7: BACKUP, EXPORT & GOOGLE SHEETS    -->
<!-- ========================================== -->
<div id="modalBackupSync" class="hidden fixed inset-0 bg-black/60 backdrop-blur-[1px] flex items-center justify-center p-4 z-50" data-modal-backdrop>
    <div class="bg-white dark:bg-slate-900 border border-transparent dark:border-slate-800 rounded-xl shadow-xl w-full max-w-2xl p-6 max-h-[88vh] overflow-y-auto space-y-6">
        <div class="flex justify-between items-center">
            <div>
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Ekspor, Backup &amp; Google Sheets</h3>
                <p class="text-xs text-slate-400">Unduh laporan lokal atau hubungkan otomatis ke Google Spreadsheet.</p>
            </div>
            <button type="button" onclick="closeModal('modalBackupSync')" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 font-bold text-xl leading-none">&times;</button>
        </div>

        <!-- Bagian 1: Export CSV & Backup Database -->
        <div class="bg-slate-50 dark:bg-slate-800/60 p-4 rounded-xl border border-slate-200 dark:border-slate-700 space-y-3">
            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">1. Ekspor Laporan &amp; Backup Lokal</h4>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                <a href="<?= e(exportCsvUrl()) ?>" class="flex items-center justify-center gap-1.5 px-3 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold rounded-lg transition text-center">
                    ↓ Export CSV (Filter Aktif)
                </a>
                <a href="export.php?export=backup_json" class="flex items-center justify-center gap-1.5 px-3 py-2.5 bg-slate-800 dark:bg-slate-700 hover:bg-slate-700 dark:hover:bg-slate-600 text-white text-xs font-semibold rounded-lg transition text-center">
                    ↓ Backup Full (.JSON)
                </a>
                <a href="export.php?export=backup_sql" class="flex items-center justify-center gap-1.5 px-3 py-2.5 bg-slate-800 dark:bg-slate-700 hover:bg-slate-700 dark:hover:bg-slate-600 text-white text-xs font-semibold rounded-lg transition text-center">
                    ↓ Backup Full (.SQL)
                </a>
            </div>

            <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Restore akan mengganti seluruh data saat ini dengan isi file backup JSON. Lanjutkan?')" class="pt-2 border-t border-slate-200 dark:border-slate-700 flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="restore_backup_json">
                <input type="file" name="backup_file" accept=".json,application/json" required class="flex-1 text-xs text-slate-600 dark:text-slate-300 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-slate-200 dark:file:bg-slate-700 file:text-slate-800 dark:file:text-slate-100 hover:file:bg-slate-300">
                <button type="submit" class="px-3.5 py-2 bg-amber-600 hover:bg-amber-500 text-white text-xs font-semibold rounded-lg transition shrink-0">
                    ↑ Restore JSON
                </button>
            </form>
        </div>

        <!-- Bagian 2: Auto-Connect Google Sheets -->
        <div class="bg-slate-50 dark:bg-slate-800/60 p-4 rounded-xl border border-slate-200 dark:border-slate-700 space-y-4">
            <div class="flex flex-wrap justify-between items-center gap-2">
                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">2. Auto-Sync Google Sheets (Real-Time)</h4>
                <span class="text-[11px] px-2 py-0.5 rounded-full font-semibold <?= $gsheetSettings['auto_sync'] && $gsheetSettings['url'] !== '' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                    <?= $gsheetSettings['auto_sync'] && $gsheetSettings['url'] !== '' ? '● Auto-Sync Aktif' : '○ Nonaktif' ?>
                </span>
            </div>

            <form method="POST" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_gsheet_settings">

                <div>
                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">URL Web App Google Apps Script</label>
                    <input type="url" name="gsheet_webhook_url" value="<?= e($gsheetSettings['url']) ?>" placeholder="https://script.google.com/macros/s/AKfycb.../exec" class="w-full bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-xs">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-center">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Secret Token (Opsional)</label>
                        <input type="text" name="gsheet_secret" value="<?= e($gsheetSettings['secret']) ?>" placeholder="Kosongkan jika tidak pakai" class="w-full bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-xs">
                    </div>
                    <label class="flex items-center gap-2 text-xs text-slate-700 dark:text-slate-300 cursor-pointer pt-4">
                        <input type="checkbox" name="gsheet_auto_sync" value="1" <?= $gsheetSettings['auto_sync'] ? 'checked' : '' ?> class="rounded border-slate-300">
                        <span>Auto-sync tiap kali ada transaksi/transfer baru</span>
                    </label>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                    <span class="text-[11px] text-slate-400">Status: <?= e($gsheetSettings['last_status']) ?></span>
                    <div class="flex gap-2">
                        <button type="submit" class="px-3.5 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold rounded-lg transition">
                            Simpan Pengaturan
                        </button>
                    </div>
                </div>
            </form>

            <?php if ($gsheetSettings['url'] !== ''): ?>
                <form method="POST" class="pt-2 border-t border-slate-200 dark:border-slate-700 flex justify-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="sync_gsheet_now">
                    <button type="submit" class="px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold rounded-lg transition">
                        ↻ Sync Sekarang ke Google Sheets
                    </button>
                </form>
            <?php endif; ?>

            <!-- Panduan & Kode Apps Script -->
            <details class="pt-2 border-t border-slate-200 dark:border-slate-700 text-xs">
                <summary class="cursor-pointer font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">
                    Lihat Cara Setup &amp; Kode Google Apps Script (Gratis, Tanpa API Key)
                </summary>
                <div class="mt-3 space-y-2 text-slate-600 dark:text-slate-300">
                    <ol class="list-decimal list-inside space-y-1">
                        <li>Buka Google Spreadsheet baru &rarr; klik menu <b>Extensions &rarr; Apps Script</b>.</li>
                        <li>Hapus kode bawaan, lalu tempel kode di bawah ini dan klik <b>Save</b>.</li>
                        <li>Klik <b>Deploy &rarr; New deployment &rarr; Web app</b>.</li>
                        <li>Set <i>Execute as</i>: <b>Me</b>, dan <i>Who has access</i>: <b>Anyone</b>, lalu klik <b>Deploy</b>.</li>
                        <li>Salin <b>Web app URL</b> yang muncul dan tempelkan ke kolom URL di atas.</li>
                    </ol>
                    <div class="relative mt-2">
                        <button type="button" onclick="copyAppsScriptCode(this)" class="absolute top-2 right-2 px-2.5 py-1 bg-slate-700 hover:bg-slate-600 text-white text-[11px] font-semibold rounded">
                            Salin Kode
                        </button>
                        <pre id="appsScriptCode" class="p-3 bg-slate-900 text-emerald-300 rounded-lg overflow-x-auto text-[11px] leading-relaxed max-h-48"><?= e($appsScriptTemplate) ?></pre>
                    </div>
                </div>
            </details>
        </div>
    </div>
</div>

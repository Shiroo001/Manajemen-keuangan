<?php
if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed.');
}

// ==========================================
// 1. EKSEKUSI OTOMATIS JADWAL RECURRING
// ==========================================
$currentYM = date('Y-m');
$todayDay  = (int)date('j');

$dueRecurrings = $pdo->prepare("
    SELECT r.* FROM recurring_transactions r
    INNER JOIN wallets w ON r.wallet_id = w.id AND w.deleted_at IS NULL
    INNER JOIN categories c ON r.category_id = c.id AND c.deleted_at IS NULL AND c.type = r.type
    WHERE r.is_active = 1
      AND r.day_of_month <= ?
      AND (r.last_executed_month IS NULL OR r.last_executed_month <> ?)
");
$dueRecurrings->execute([$todayDay, $currentYM]);
$autoExecutedCount = 0;

foreach ($dueRecurrings->fetchAll() as $rec) {
    try {
        $pdo->beginTransaction();
        $execDate = $currentYM . '-' . str_pad((string)$rec['day_of_month'], 2, '0', STR_PAD_LEFT);
        $desc     = trim(($rec['description'] ?: 'Transaksi Rutin') . ' [Otomatis]');

        $pdo->prepare("INSERT INTO transactions (wallet_id, category_id, type, amount, description, transaction_date) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$rec['wallet_id'], $rec['category_id'], $rec['type'], $rec['amount'], $desc, $execDate]);

        $op = $rec['type'] === 'income' ? '+' : '-';
        $pdo->prepare("UPDATE wallets SET balance = balance $op ? WHERE id = ?")
            ->execute([$rec['amount'], $rec['wallet_id']]);

        $pdo->prepare("UPDATE recurring_transactions SET last_executed_month = ? WHERE id = ?")
            ->execute([$currentYM, $rec['id']]);

        $pdo->commit();
        $autoExecutedCount++;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

if ($autoExecutedCount > 0) {
    sync_to_google_sheets($pdo, 'recurring_auto');
    if (empty($_SESSION['flash'])) {
        flash('success', "$autoExecutedCount transaksi rutin bulan ini otomatis dicatat dan saldo dompet telah diperbarui.");
    }
}

// ==========================================
// 2. HANDLER AKSI CRUD, TRANSFER, RECURRING, BACKUP & SYNC
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $shouldSync = false;

    try {
        verify_csrf();

        // --- CREATE TRANSACTION ---
        if ($action === 'create_transaction') {
            $walletId   = (int)($_POST['wallet_id'] ?? 0);
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $type       = ($_POST['type'] ?? '') === 'income' ? 'income' : 'expense';
            $amount     = round((float)($_POST['amount'] ?? 0), 2);
            $desc       = mb_substr(trim($_POST['description'] ?? ''), 0, 255);
            $date       = validate_date($_POST['transaction_date'] ?: date('Y-m-d'));

            if ($amount <= 0) {
                throw new Exception('Nominal transaksi harus lebih dari 0.');
            }

            $pdo->beginTransaction();

            assert_active_wallet($pdo, $walletId);
            assert_active_category($pdo, $categoryId, $type);

            $pdo->prepare("INSERT INTO transactions (wallet_id, category_id, type, amount, description, transaction_date) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$walletId, $categoryId, $type, $amount, $desc, $date]);

            $op = $type === 'income' ? '+' : '-';
            $pdo->prepare("UPDATE wallets SET balance = balance $op ? WHERE id = ?")->execute([$amount, $walletId]);

            $pdo->commit();
            $shouldSync = true;
            flash('success', 'Transaksi dicatat dan saldo dompet diperbarui.');
        }

        // --- UPDATE TRANSACTION ---
        elseif ($action === 'update_transaction') {
            $id            = (int)($_POST['id'] ?? 0);
            $newWalletId   = (int)($_POST['wallet_id'] ?? 0);
            $newCategoryId = (int)($_POST['category_id'] ?? 0);
            $newType       = ($_POST['type'] ?? '') === 'income' ? 'income' : 'expense';
            $newAmount     = round((float)($_POST['amount'] ?? 0), 2);
            $newDesc       = mb_substr(trim($_POST['description'] ?? ''), 0, 255);
            $newDate       = validate_date($_POST['transaction_date'] ?: date('Y-m-d'));

            if ($newAmount <= 0) {
                throw new Exception('Nominal transaksi harus lebih dari 0.');
            }

            $pdo->beginTransaction();

            $oldStmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $oldStmt->execute([$id]);
            $old = $oldStmt->fetch();
            if (!$old) {
                throw new Exception('Transaksi tidak ditemukan atau sudah dihapus.');
            }

            assert_active_wallet($pdo, $newWalletId);
            assert_active_category($pdo, $newCategoryId, $newType);

            $revertOp = $old['type'] === 'income' ? '-' : '+';
            $pdo->prepare("UPDATE wallets SET balance = balance $revertOp ? WHERE id = ?")
                ->execute([$old['amount'], $old['wallet_id']]);

            $pdo->prepare("UPDATE transactions SET wallet_id = ?, category_id = ?, type = ?, amount = ?, description = ?, transaction_date = ? WHERE id = ?")
                ->execute([$newWalletId, $newCategoryId, $newType, $newAmount, $newDesc, $newDate, $id]);

            $applyOp = $newType === 'income' ? '+' : '-';
            $pdo->prepare("UPDATE wallets SET balance = balance $applyOp ? WHERE id = ?")
                ->execute([$newAmount, $newWalletId]);

            $pdo->commit();
            $shouldSync = true;
            flash('success', 'Transaksi diperbarui dan saldo disesuaikan.');
        }

        // --- SOFT DELETE TRANSACTION ---
        elseif ($action === 'delete_transaction') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $stmt->execute([$id]);
            $trx = $stmt->fetch();
            if (!$trx) {
                throw new Exception('Transaksi tidak ditemukan.');
            }

            $revertOp = $trx['type'] === 'income' ? '-' : '+';
            $pdo->prepare("UPDATE wallets SET balance = balance $revertOp ? WHERE id = ?")
                ->execute([$trx['amount'], $trx['wallet_id']]);

            $pdo->prepare("UPDATE transactions SET deleted_at = NOW() WHERE id = ?")->execute([$id]);

            $pdo->commit();
            $shouldSync = true;
            flash('success', 'Transaksi dipindahkan ke Trash dan saldo dikembalikan.');
        }

        // --- RESTORE & FORCE DELETE TRANSACTION ---
        elseif ($action === 'restore_transaction') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND deleted_at IS NOT NULL FOR UPDATE");
            $stmt->execute([$id]);
            $trx = $stmt->fetch();
            if (!$trx) {
                throw new Exception('Transaksi di Trash tidak ditemukan.');
            }

            assert_active_wallet($pdo, (int)$trx['wallet_id']);

            $applyOp = $trx['type'] === 'income' ? '+' : '-';
            $pdo->prepare("UPDATE wallets SET balance = balance $applyOp ? WHERE id = ?")
                ->execute([$trx['amount'], $trx['wallet_id']]);

            $pdo->prepare("UPDATE transactions SET deleted_at = NULL WHERE id = ?")->execute([$id]);

            $pdo->commit();
            $shouldSync = true;
            flash('success', 'Transaksi dipulihkan dan saldo diterapkan kembali.');
        }
        elseif ($action === 'force_delete_transaction') {
            $id   = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ? AND deleted_at IS NOT NULL");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                throw new Exception('Transaksi di Trash tidak ditemukan.');
            }
            flash('success', 'Transaksi dihapus permanen.');
        }

        // --- TRANSFER ANTAR-DOMPET ---
        elseif ($action === 'create_transfer') {
            $fromId = (int)($_POST['from_wallet_id'] ?? 0);
            $toId   = (int)($_POST['to_wallet_id'] ?? 0);
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $note   = mb_substr(trim($_POST['note'] ?? ''), 0, 255);
            $date   = validate_date($_POST['transfer_date'] ?: date('Y-m-d'));

            if ($fromId === $toId) {
                throw new Exception('Dompet asal dan tujuan tidak boleh sama.');
            }
            if ($amount <= 0) {
                throw new Exception('Nominal transfer harus lebih dari 0.');
            }

            $pdo->beginTransaction();

            assert_active_wallet($pdo, $fromId);
            assert_active_wallet($pdo, $toId);

            $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE id = ? AND deleted_at IS NULL")->execute([$amount, $fromId]);
            $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE id = ? AND deleted_at IS NULL")->execute([$amount, $toId]);
            $pdo->prepare("INSERT INTO transfers (from_wallet_id, to_wallet_id, amount, note, transfer_date) VALUES (?, ?, ?, ?, ?)")
                ->execute([$fromId, $toId, $amount, $note, $date]);

            $pdo->commit();
            $shouldSync = true;
            flash('success', 'Saldo berhasil dipindahkan antar-dompet tanpa mengubah laporan pemasukan/pengeluaran.');
        }
        elseif ($action === 'delete_transfer') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM transfers WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $trf = $stmt->fetch();
            if (!$trf) {
                throw new Exception('Data transfer tidak ditemukan.');
            }

            $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE id = ?")->execute([$trf['amount'], $trf['from_wallet_id']]);
            $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE id = ?")->execute([$trf['amount'], $trf['to_wallet_id']]);
            $pdo->prepare("DELETE FROM transfers WHERE id = ?")->execute([$id]);

            $pdo->commit();
            $shouldSync = true;
            flash('success', 'Transfer dibatalkan dan saldo masing-masing dompet dikembalikan.');
        }

        // --- RECURRING TRANSACTIONS ---
        elseif ($action === 'create_recurring') {
            $walletId   = (int)($_POST['wallet_id'] ?? 0);
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $type       = ($_POST['type'] ?? '') === 'income' ? 'income' : 'expense';
            $amount     = round((float)($_POST['amount'] ?? 0), 2);
            $desc       = mb_substr(trim($_POST['description'] ?? ''), 0, 255);
            $day        = max(1, min(28, (int)($_POST['day_of_month'] ?? 1)));
            $runNow     = !empty($_POST['run_this_month']);

            if ($amount <= 0) {
                throw new Exception('Nominal harus lebih dari 0.');
            }
            if ($desc === '') {
                throw new Exception('Keterangan rutin wajib diisi.');
            }

            $pdo->beginTransaction();

            assert_active_wallet($pdo, $walletId);
            assert_active_category($pdo, $categoryId, $type);

            $lastMonth = null;
            if ($runNow) {
                $execDate = $currentYM . '-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
                $fullDesc = trim($desc . ' [Otomatis]');
                $pdo->prepare("INSERT INTO transactions (wallet_id, category_id, type, amount, description, transaction_date) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$walletId, $categoryId, $type, $amount, $fullDesc, $execDate]);

                $op = $type === 'income' ? '+' : '-';
                $pdo->prepare("UPDATE wallets SET balance = balance $op ? WHERE id = ?")->execute([$amount, $walletId]);
                $lastMonth  = $currentYM;
                $shouldSync = true;
            } elseif ($day <= $todayDay) {
                $lastMonth = $currentYM;
            }

            $pdo->prepare("INSERT INTO recurring_transactions (wallet_id, category_id, type, amount, description, day_of_month, last_executed_month) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$walletId, $categoryId, $type, $amount, $desc, $day, $lastMonth]);

            $pdo->commit();
            flash('success', $runNow ? 'Jadwal rutin disimpan & langsung dicatat untuk bulan ini.' : 'Jadwal transaksi rutin berhasil dibuat.');
        }
        elseif ($action === 'toggle_recurring') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE recurring_transactions SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
            flash('success', 'Status jadwal transaksi rutin diperbarui.');
        }
        elseif ($action === 'delete_recurring') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM recurring_transactions WHERE id = ?")->execute([$id]);
            flash('success', 'Jadwal transaksi rutin dihapus.');
        }

        // --- CRUD WALLETS ---
        elseif ($action === 'create_wallet') {
            $name    = mb_substr(trim($_POST['name'] ?? ''), 0, 100);
            $balance = round((float)($_POST['balance'] ?? 0), 2);
            if ($name === '') {
                throw new Exception('Nama dompet wajib diisi.');
            }
            $pdo->prepare("INSERT INTO wallets (name, balance) VALUES (?, ?)")->execute([$name, $balance]);
            $shouldSync = true;
            flash('success', 'Dompet berhasil ditambahkan.');
        }
        elseif ($action === 'update_wallet') {
            $id      = (int)($_POST['id'] ?? 0);
            $name    = mb_substr(trim($_POST['name'] ?? ''), 0, 100);
            $balance = round((float)($_POST['balance'] ?? 0), 2);
            if ($name === '') {
                throw new Exception('Nama dompet wajib diisi.');
            }
            $pdo->prepare("UPDATE wallets SET name = ?, balance = ? WHERE id = ? AND deleted_at IS NULL")->execute([$name, $balance, $id]);
            $shouldSync = true;
            flash('success', 'Dompet berhasil diperbarui.');
        }
        elseif ($action === 'delete_wallet') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE wallets SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
            $shouldSync = true;
            flash('success', 'Dompet dipindahkan ke Trash.');
        }
        elseif ($action === 'restore_wallet') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE wallets SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL")->execute([$id]);
            $shouldSync = true;
            flash('success', 'Dompet dipulihkan.');
        }

        // --- CRUD CATEGORIES ---
        elseif ($action === 'create_category') {
            $name = mb_substr(trim($_POST['name'] ?? ''), 0, 100);
            $type = ($_POST['type'] ?? '') === 'income' ? 'income' : 'expense';
            if ($name === '') {
                throw new Exception('Nama kategori wajib diisi.');
            }
            $pdo->prepare("INSERT INTO categories (name, type) VALUES (?, ?)")->execute([$name, $type]);
            flash('success', 'Kategori berhasil ditambahkan.');
        }
        elseif ($action === 'update_category') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = mb_substr(trim($_POST['name'] ?? ''), 0, 100);
            $type = ($_POST['type'] ?? '') === 'income' ? 'income' : 'expense';
            if ($name === '') {
                throw new Exception('Nama kategori wajib diisi.');
            }
            $pdo->prepare("UPDATE categories SET name = ?, type = ? WHERE id = ? AND deleted_at IS NULL")->execute([$name, $type, $id]);
            flash('success', 'Kategori berhasil diperbarui.');
        }
        elseif ($action === 'delete_category') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE categories SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")->execute([$id]);
            flash('success', 'Kategori dipindahkan ke Trash.');
        }
        elseif ($action === 'restore_category') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE categories SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL")->execute([$id]);
            flash('success', 'Kategori dipulihkan.');
        }

        // --- GOOGLE SHEETS INTEGRATION SETTINGS ---
        elseif ($action === 'save_gsheet_settings') {
            $url      = trim($_POST['gsheet_webhook_url'] ?? '');
            $secret   = trim($_POST['gsheet_secret'] ?? '');
            $autoSync = !empty($_POST['gsheet_auto_sync']) ? '1' : '0';

            if ($url !== '' && !preg_match('#^https://script\.google\.com/macros/s/[a-zA-Z0-9_-]+/exec$#', $url)) {
                throw new Exception('URL Web App tidak valid. Pastikan berawalan https://script.google.com/macros/s/... dan berakhiran /exec');
            }

            set_setting($pdo, 'gsheet_webhook_url', $url);
            set_setting($pdo, 'gsheet_secret', $secret);
            set_setting($pdo, 'gsheet_auto_sync', $url !== '' ? $autoSync : '0');

            if ($url !== '' && $autoSync === '1') {
                $res = sync_to_google_sheets($pdo, 'manual');
                flash($res['ok'] ? 'success' : 'error', 'Pengaturan disimpan. ' . $res['message']);
            } else {
                flash('success', 'Pengaturan integrasi Google Sheets berhasil disimpan.');
            }
        }
        elseif ($action === 'sync_gsheet_now') {
            $res = sync_to_google_sheets($pdo, 'manual');
            flash($res['ok'] ? 'success' : 'error', $res['message']);
        }

        // --- RESTORE BACKUP JSON ---
        elseif ($action === 'restore_backup_json') {
            if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Gagal mengunggah file backup JSON.');
            }

            $raw = file_get_contents($_FILES['backup_file']['tmp_name']);
            $data = json_decode((string)$raw, true);

            if (!is_array($data) || !isset($data['tables']) || !is_array($data['tables'])) {
                throw new Exception('Format file backup JSON tidak valid.');
            }

            $tables = $data['tables'];
            $requiredTables = ['wallets', 'categories', 'transactions', 'transfers', 'recurring_transactions'];
            foreach ($requiredTables as $tbl) {
                if (!isset($tables[$tbl]) || !is_array($tables[$tbl])) {
                    throw new Exception("Tabel '$tbl' tidak ditemukan di dalam file backup.");
                }
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $pdo->beginTransaction();

            $pdo->exec('DELETE FROM recurring_transactions');
            $pdo->exec('DELETE FROM transfers');
            $pdo->exec('DELETE FROM transactions');
            $pdo->exec('DELETE FROM categories');
            $pdo->exec('DELETE FROM wallets');

            $insWallet = $pdo->prepare('INSERT INTO wallets (id, name, balance, deleted_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($tables['wallets'] as $r) {
                $insWallet->execute([$r['id'], $r['name'], $r['balance'], $r['deleted_at'] ?? null, $r['created_at'] ?? date('Y-m-d H:i:s'), $r['updated_at'] ?? date('Y-m-d H:i:s')]);
            }

            $insCat = $pdo->prepare('INSERT INTO categories (id, name, type, deleted_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($tables['categories'] as $r) {
                $insCat->execute([$r['id'], $r['name'], $r['type'], $r['deleted_at'] ?? null, $r['created_at'] ?? date('Y-m-d H:i:s'), $r['updated_at'] ?? date('Y-m-d H:i:s')]);
            }

            $insTrx = $pdo->prepare('INSERT INTO transactions (id, wallet_id, category_id, type, amount, description, transaction_date, deleted_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($tables['transactions'] as $r) {
                $insTrx->execute([$r['id'], $r['wallet_id'], $r['category_id'], $r['type'], $r['amount'], $r['description'] ?? null, $r['transaction_date'], $r['deleted_at'] ?? null, $r['created_at'] ?? date('Y-m-d H:i:s'), $r['updated_at'] ?? date('Y-m-d H:i:s')]);
            }

            $insTrf = $pdo->prepare('INSERT INTO transfers (id, from_wallet_id, to_wallet_id, amount, note, transfer_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($tables['transfers'] as $r) {
                $insTrf->execute([$r['id'], $r['from_wallet_id'], $r['to_wallet_id'], $r['amount'], $r['note'] ?? null, $r['transfer_date'], $r['created_at'] ?? date('Y-m-d H:i:s')]);
            }

            $insRec = $pdo->prepare('INSERT INTO recurring_transactions (id, wallet_id, category_id, type, amount, description, day_of_month, last_executed_month, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($tables['recurring_transactions'] as $r) {
                $insRec->execute([$r['id'], $r['wallet_id'], $r['category_id'], $r['type'], $r['amount'], $r['description'] ?? null, $r['day_of_month'], $r['last_executed_month'] ?? null, $r['is_active'] ?? 1, $r['created_at'] ?? date('Y-m-d H:i:s')]);
            }

            $pdo->commit();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            $shouldSync = true;
            flash('success', 'Seluruh database berhasil dipulihkan dari file backup JSON.');
        }
        else {
            throw new Exception('Aksi tidak dikenali.');
        }

        if ($shouldSync) {
            $syncRes = sync_to_google_sheets($pdo, $action);
            if (!$syncRes['ok'] && empty($syncRes['skipped']) && isset($_SESSION['flash'])) {
                $_SESSION['flash']['msg'] .= ' (Catatan Sync Sheets: ' . $syncRes['message'] . ')';
            }
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        flash('error', $e->getMessage());
    }

    header('Location: index.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

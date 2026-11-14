<?php
define('APP_INIT', true);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/queries.php';

$exportType = $_GET['export'] ?? 'csv';
$timestamp  = date('Y-m-d_His');

// ==========================================
// 1. EXPORT CSV TRANSAKSI (SESUAI FILTER AKTIF)
// ==========================================
if ($exportType === 'csv') {
    $filename = "laporan_transaksi_{$timestamp}.csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM agar karakter terbaca rapi di Excel & Google Sheets
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, ['ID', 'Tanggal', 'Tipe', 'Kategori', 'Dompet', 'Nominal', 'Catatan']);

    foreach ($transactions as $t) {
        fputcsv($out, [
            $t['id'],
            $t['transaction_date'],
            $t['type'] === 'income' ? 'Pemasukan' : 'Pengeluaran',
            $t['category_name'] ?? '',
            $t['wallet_name'] ?? '',
            number_format((float)$t['amount'], 2, '.', ''),
            $t['description'] ?? '',
        ]);
    }

    fclose($out);
    exit;
}

// ==========================================
// 2. BACKUP FULL DATABASE (.JSON)
// ==========================================
if ($exportType === 'backup_json') {
    $filename = "backup_keuangan_{$timestamp}.json";
    $tables   = ['wallets', 'categories', 'transactions', 'transfers', 'recurring_transactions'];
    $dump     = [
        'app'         => 'Expense Tracker Local XAMPP',
        'exported_at' => date('Y-m-d H:i:s'),
        'tables'      => [],
    ];

    foreach ($tables as $tbl) {
        $dump['tables'][$tbl] = $pdo->query("SELECT * FROM `$tbl` ORDER BY id ASC")->fetchAll();
    }

    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================
// 3. BACKUP FULL DATABASE (.SQL)
// ==========================================
if ($exportType === 'backup_sql') {
    $filename = "backup_keuangan_{$timestamp}.sql";
    $tables   = ['wallets', 'categories', 'transactions', 'transfers', 'recurring_transactions'];

    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "-- Expense Tracker SQL Backup\n");
    fwrite($out, "-- Generated at: " . date('Y-m-d H:i:s') . "\n\n");
    fwrite($out, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

    foreach ($tables as $tbl) {
        $rows = $pdo->query("SELECT * FROM `$tbl` ORDER BY id ASC")->fetchAll();
        fwrite($out, "-- Table: `$tbl`\n");
        fwrite($out, "DELETE FROM `$tbl`;\n");

        if (!empty($rows)) {
            $cols       = array_keys($rows[0]);
            $colList    = implode(', ', array_map(static fn($c) => "`$c`", $cols));
            $valuesList = [];

            foreach ($rows as $row) {
                $escaped = array_map(static function ($val) use ($pdo) {
                    return $val === null ? 'NULL' : $pdo->quote((string)$val);
                }, array_values($row));
                $valuesList[] = '(' . implode(', ', $escaped) . ')';
            }

            fwrite($out, "INSERT INTO `$tbl` ($colList) VALUES\n" . implode(",\n", $valuesList) . ";\n");
        }
        fwrite($out, "\n");
    }

    fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($out);
    exit;
}

http_response_code(400);
exit('Format ekspor tidak valid.');

<?php
if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed.');
}

// ==========================================
// 1. MASTER DATA DOMPET, KATEGORI, TRANSFER & RECURRING
// ==========================================
$activeWallets  = $pdo->query("SELECT * FROM wallets WHERE deleted_at IS NULL ORDER BY id ASC")->fetchAll();
$trashedWallets = $pdo->query("SELECT * FROM wallets WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC")->fetchAll();

$activeCategories  = $pdo->query("SELECT * FROM categories WHERE deleted_at IS NULL ORDER BY type ASC, name ASC")->fetchAll();
$trashedCategories = $pdo->query("SELECT * FROM categories WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC")->fetchAll();

$recentTransfers = $pdo->query("
    SELECT tr.*, wf.name AS from_name, wt.name AS to_name
    FROM transfers tr
    LEFT JOIN wallets wf ON tr.from_wallet_id = wf.id
    LEFT JOIN wallets wt ON tr.to_wallet_id = wt.id
    ORDER BY tr.transfer_date DESC, tr.id DESC
    LIMIT 15
")->fetchAll();

$recurringList = $pdo->query("
    SELECT r.*, w.name AS wallet_name, c.name AS category_name
    FROM recurring_transactions r
    LEFT JOIN wallets w ON r.wallet_id = w.id
    LEFT JOIN categories c ON r.category_id = c.id
    ORDER BY r.is_active DESC, r.day_of_month ASC
")->fetchAll();

$gsheetSettings = [
    'url'         => get_setting($pdo, 'gsheet_webhook_url', ''),
    'secret'      => get_setting($pdo, 'gsheet_secret', ''),
    'auto_sync'   => get_setting($pdo, 'gsheet_auto_sync', '0') === '1',
    'last_sync'   => get_setting($pdo, 'gsheet_last_sync', ''),
    'last_status' => get_setting($pdo, 'gsheet_last_status', 'Belum pernah disinkronkan'),
];

// ==========================================
// 2. FILTER & SEARCH TRANSAKSI
// ==========================================
$allowedPeriods = ['today', '7days', '30days', 'this_month', 'month', 'all'];
$period         = in_array($_GET['period'] ?? '', $allowedPeriods, true) ? $_GET['period'] : 'this_month';

$rawMonth    = $_GET['month'] ?? ($period === 'this_month' ? date('Y-m') : '');
$filterMonth = preg_match('/^\d{4}-\d{2}$/', $rawMonth) ? $rawMonth : '';

$filterWallet   = ($_GET['wallet_id'] ?? '') !== '' ? (string)(int)$_GET['wallet_id'] : '';
$filterCategory = ($_GET['category_id'] ?? '') !== '' ? (string)(int)$_GET['category_id'] : '';
$filterType     = in_array($_GET['type'] ?? '', ['income', 'expense'], true) ? $_GET['type'] : '';
$searchQuery    = trim($_GET['q'] ?? '');

$where       = ["t.deleted_at IS NULL"];
$params      = [];
$periodLabel = 'Bulan Ini (' . date('M Y') . ')';

if ($period === 'today') {
    $where[]     = "t.transaction_date = CURDATE()";
    $periodLabel = 'Hari Ini (' . date('d M Y') . ')';
    $filterMonth = '';
} elseif ($period === '7days') {
    $where[]     = "t.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND t.transaction_date <= CURDATE()";
    $periodLabel = '7 Hari Terakhir';
    $filterMonth = '';
} elseif ($period === '30days') {
    $where[]     = "t.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND t.transaction_date <= CURDATE()";
    $periodLabel = '1 Bulan Terakhir (30 Hari)';
    $filterMonth = '';
} elseif ($period === 'all') {
    $periodLabel = 'Semua Waktu';
    $filterMonth = '';
} else {
    $targetMonth = $filterMonth !== '' ? $filterMonth : date('Y-m');
    $filterMonth = $targetMonth;
    $where[]     = "DATE_FORMAT(t.transaction_date, '%Y-%m') = ?";
    $params[]    = $targetMonth;
    $periodLabel = 'Bulan ' . date('M Y', strtotime($targetMonth . '-01'));
}

if ($filterWallet !== '') {
    $where[]  = "t.wallet_id = ?";
    $params[] = (int)$filterWallet;
}
if ($filterCategory !== '') {
    $where[]  = "t.category_id = ?";
    $params[] = (int)$filterCategory;
}
if ($filterType !== '') {
    $where[]  = "t.type = ?";
    $params[] = $filterType;
}
if ($searchQuery !== '') {
    $where[]  = "(t.description LIKE ? OR c.name LIKE ? OR w.name LIKE ?)";
    $like     = '%' . $searchQuery . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sqlTrx = "
    SELECT t.*, w.name AS wallet_name, w.deleted_at AS wallet_deleted,
           c.name AS category_name, c.deleted_at AS category_deleted
    FROM transactions t
    LEFT JOIN wallets w ON t.wallet_id = w.id
    LEFT JOIN categories c ON t.category_id = c.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY t.transaction_date DESC, t.id DESC
";
$stmtTrx = $pdo->prepare($sqlTrx);
$stmtTrx->execute($params);
$transactions = $stmtTrx->fetchAll();

$trashedTransactions = $pdo->query("
    SELECT t.*, w.name AS wallet_name, c.name AS category_name
    FROM transactions t
    LEFT JOIN wallets w ON t.wallet_id = w.id
    LEFT JOIN categories c ON t.category_id = c.id
    WHERE t.deleted_at IS NOT NULL
    ORDER BY t.deleted_at DESC
")->fetchAll();

// ==========================================
// 3. RINGKASAN SALDO & AGREGASI GRAFIK
// ==========================================
$totalBalance = array_sum(array_column($activeWallets, 'balance'));
$totalIncome  = 0;
$totalExpense = 0;
$incomeCount  = 0;

$expenseByCategory = [];
$trendMap          = [];

$chronoTransactions = array_reverse($transactions);
$groupByMonth       = ($period === 'all' && count($chronoTransactions) > 31);

foreach ($chronoTransactions as $t) {
    $amt = (float)$t['amount'];
    if ($t['type'] === 'income') {
        $totalIncome += $amt;
        $incomeCount++;
    } else {
        $totalExpense += $amt;
        $catName                     = $t['category_name'] ?: 'Lainnya';
        $expenseByCategory[$catName] = ($expenseByCategory[$catName] ?? 0) + $amt;
    }

    $key = $groupByMonth
        ? date('M Y', strtotime($t['transaction_date']))
        : date('d M', strtotime($t['transaction_date']));

    if (!isset($trendMap[$key])) {
        $trendMap[$key] = ['income' => 0, 'expense' => 0];
    }
    $trendMap[$key][$t['type']] += $amt;
}

arsort($expenseByCategory);

$chartData = [
    'trend' => [
        'labels'  => array_keys($trendMap),
        'income'  => array_column($trendMap, 'income'),
        'expense' => array_column($trendMap, 'expense'),
    ],
    'category' => [
        'labels' => array_keys($expenseByCategory),
        'values' => array_values($expenseByCategory),
    ]
];

function buildQueryParams(array $overrides = []): array {
    $base = [
        'period'      => $_GET['period'] ?? 'this_month',
        'month'       => $_GET['month'] ?? '',
        'wallet_id'   => $_GET['wallet_id'] ?? '',
        'category_id' => $_GET['category_id'] ?? '',
        'type'        => $_GET['type'] ?? '',
        'q'           => $_GET['q'] ?? '',
    ];
    $merged = array_merge($base, $overrides);
    return array_filter($merged, static fn($v) => $v !== '');
}

function filterUrl(array $overrides = []): string {
    $cleaned = buildQueryParams($overrides);
    return 'index.php' . (!empty($cleaned) ? '?' . http_build_query($cleaned) : '');
}

function exportCsvUrl(): string {
    $cleaned = buildQueryParams(['export' => 'csv']);
    return 'export.php?' . http_build_query($cleaned);
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

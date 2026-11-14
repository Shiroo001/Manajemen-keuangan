<?php
define('APP_INIT', true);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/queries.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Tracker — Local XAMPP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
        (function() {
            const savedTheme = localStorage.getItem('et_theme') || localStorage.getItem('theme') || 'system';
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (savedTheme === 'dark' || (savedTheme === 'system' && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
</head>
<body class="bg-slate-100 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen transition-colors duration-200">

    <!-- Top Navbar -->
    <header class="bg-slate-900 text-white shadow border-b border-transparent dark:border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold tracking-tight">Expense Tracker</h1>
                <p class="text-xs text-slate-400">Local Desktop Finance &bull; XAMPP MySQL</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 sm:gap-2.5">
                <select id="themeSelect" onchange="changeTheme(this.value)" aria-label="Pilih Mode Tampilan"
                    class="px-3 py-2 text-xs bg-slate-800 hover:bg-slate-700 text-white rounded-lg border border-slate-700 focus:outline-none cursor-pointer transition">
                    <option value="light">☀️ Terang</option>
                    <option value="dark">🌙 Gelap</option>
                    <option value="system">💻 Sistem</option>
                </select>

                <button type="button" onclick="openModal('modalTransfer')" class="px-3 py-2 text-xs font-medium bg-indigo-600/90 hover:bg-indigo-500 text-white rounded-lg transition">
                    ⇄ Transfer Saldo
                </button>
                <button type="button" onclick="openModal('modalRecurring')" class="px-3 py-2 text-xs font-medium bg-slate-800 hover:bg-slate-700 rounded-lg border border-slate-700 transition">
                    ↻ Otomatis (<?= count(array_filter($recurringList, static fn($r) => (int)$r['is_active'] === 1)) ?>)
                </button>
                <button type="button" onclick="openModal('modalBackupSync')" class="px-3 py-2 text-xs font-medium bg-slate-800 hover:bg-slate-700 rounded-lg border border-slate-700 transition inline-flex items-center gap-1.5">
                    <span>☁ Backup &amp; Sheets</span>
                    <?php if ($gsheetSettings['auto_sync'] && $gsheetSettings['url'] !== ''): ?>
                        <span class="w-2 h-2 rounded-full bg-emerald-400" title="Auto-Sync Google Sheets Aktif"></span>
                    <?php endif; ?>
                </button>
                <button type="button" onclick="openModal('modalWallet')" class="px-3 py-2 text-xs font-medium bg-slate-800 hover:bg-slate-700 rounded-lg border border-slate-700 transition">
                    Dompet
                </button>
                <button type="button" onclick="openModal('modalCategory')" class="px-3 py-2 text-xs font-medium bg-slate-800 hover:bg-slate-700 rounded-lg border border-slate-700 transition">
                    Kategori
                </button>
                <button type="button" onclick="openModal('modalTrash')" class="px-3 py-2 text-xs font-medium bg-slate-800 hover:bg-slate-700 rounded-lg border border-slate-700 transition relative">
                    Trash
                    <?php $trashCount = count($trashedTransactions) + count($trashedWallets) + count($trashedCategories); ?>
                    <?php if ($trashCount > 0): ?>
                        <span class="ml-1 px-1.5 py-0.5 text-[10px] bg-amber-500 text-slate-900 font-bold rounded-full"><?= $trashCount ?></span>
                    <?php endif; ?>
                </button>
                <button type="button" onclick="openCreateTrxModal()" class="px-4 py-2 text-xs font-semibold bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg shadow transition">
                    + Catat Transaksi
                </button>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 space-y-6">

        <!-- Flash Notification -->
        <?php if ($flash): ?>
            <div class="p-4 rounded-lg border <?= $flash['type'] === 'success' ? 'bg-emerald-50 dark:bg-emerald-950/60 border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200' : 'bg-rose-50 dark:bg-rose-950/60 border-rose-300 dark:border-rose-800 text-rose-800 dark:text-rose-200' ?> flex justify-between items-center gap-4">
                <span class="text-sm font-medium"><?= e($flash['msg']) ?></span>
                <button type="button" onclick="this.parentElement.remove()" class="text-xs font-bold opacity-60 hover:opacity-100 shrink-0">TUTUP</button>
            </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 lg:gap-6">
            <div class="bg-white dark:bg-slate-900 p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-800">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Total Saldo Aktif</p>
                <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1"><?= rupiah($totalBalance) ?></p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Akumulasi dari <?= count($activeWallets) ?> dompet aktif</p>
            </div>
            <div class="bg-white dark:bg-slate-900 p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-800">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Pemasukan (<?= e($periodLabel) ?>)</p>
                <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">+ <?= rupiah($totalIncome) ?></p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Total <?= $incomeCount ?> transaksi masuk</p>
            </div>
            <div class="bg-white dark:bg-slate-900 p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-800">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Pengeluaran (<?= e($periodLabel) ?>)</p>
                <p class="text-2xl font-bold text-rose-600 dark:text-rose-400 mt-1">- <?= rupiah($totalExpense) ?></p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Selisih Bersih: <?= rupiah($totalIncome - $totalExpense) ?></p>
            </div>
        </div>

        <!-- Grafik Keuangan -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <div class="lg:col-span-7 bg-white dark:bg-slate-900 p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-800">
                <div class="flex justify-between items-center mb-3">
                    <h2 class="font-bold text-slate-800 dark:text-slate-100 text-sm">Tren Arus Kas (<?= e($periodLabel) ?>)</h2>
                    <span class="text-xs text-slate-400">Pemasukan vs Pengeluaran</span>
                </div>
                <div class="h-60 relative">
                    <?php if (empty($chartData['trend']['labels'])): ?>
                        <div class="h-full flex items-center justify-center text-sm text-slate-400">Belum ada data transaksi pada periode ini.</div>
                    <?php else: ?>
                        <canvas id="trendChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>

            <div class="lg:col-span-5 bg-white dark:bg-slate-900 p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-800">
                <div class="flex justify-between items-center mb-3">
                    <h2 class="font-bold text-slate-800 dark:text-slate-100 text-sm">Proporsi Pengeluaran</h2>
                    <span class="text-xs text-slate-400">Per Kategori</span>
                </div>
                <div class="h-60 relative">
                    <?php if (empty($chartData['category']['labels'])): ?>
                        <div class="h-full flex items-center justify-center text-sm text-slate-400">Tidak ada pengeluaran pada periode ini.</div>
                    <?php else: ?>
                        <canvas id="categoryChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Main Responsive Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- Left Sidebar: Wallets & Categories -->
            <div class="lg:col-span-4 space-y-6">
                <div class="bg-white dark:bg-slate-900 p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-800">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="font-bold text-slate-800 dark:text-slate-100">Daftar Dompet</h2>
                        <div class="flex gap-3">
                            <button type="button" onclick="openModal('modalTransfer')" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline font-semibold">⇄ Transfer</button>
                            <button type="button" onclick="openModal('modalWallet')" class="text-xs text-emerald-600 dark:text-emerald-400 hover:underline font-semibold">+ Tambah</button>
                        </div>
                    </div>
                    <div class="space-y-3">
                        <?php if (empty($activeWallets)): ?>
                            <p class="text-sm text-slate-400">Belum ada dompet aktif.</p>
                        <?php endif; ?>
                        <?php foreach ($activeWallets as $w): ?>
                            <div class="p-3.5 rounded-lg bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700 flex justify-between items-center">
                                <div>
                                    <p class="font-semibold text-slate-800 dark:text-slate-100 text-sm"><?= e($w['name']) ?></p>
                                    <p class="text-xs text-slate-400">ID #<?= $w['id'] ?></p>
                                </div>
                                <p class="font-bold text-sm <?= $w['balance'] < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' ?>">
                                    <?= rupiah($w['balance']) ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-800">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="font-bold text-slate-800 dark:text-slate-100">Kategori Tersedia</h2>
                        <button type="button" onclick="openModal('modalCategory')" class="text-xs text-emerald-600 dark:text-emerald-400 hover:underline font-semibold">+ Tambah</button>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($activeCategories as $c): ?>
                            <span class="px-2.5 py-1 text-xs rounded-md font-medium <?= $c['type'] === 'income' ? 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800' : 'bg-rose-50 dark:bg-rose-950/60 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800' ?>">
                                <?= e($c['name']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Right Content: Search, Quick Period Pills, Filter Bar & Transactions Table -->
            <div class="lg:col-span-8 space-y-4">

                <!-- Filter & Search Box -->
                <div class="bg-white dark:bg-slate-900 p-4 rounded-xl shadow-sm border border-slate-200 dark:border-slate-800 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2 pb-2 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs font-semibold text-slate-400 mr-1">Periode Cepat:</span>
                            <?php
                            $quickPeriods = [
                                'today'      => 'Hari Ini',
                                '7days'      => '7 Hari Terakhir',
                                '30days'     => '1 Bulan Terakhir',
                                'this_month' => 'Bulan Ini',
                                'all'        => 'Semua'
                            ];
                            foreach ($quickPeriods as $key => $label):
                                $isActive = ($period === $key);
                                $href     = filterUrl(['period' => $key, 'month' => ($key === 'this_month' ? date('Y-m') : '')]);
                            ?>
                                <a href="<?= e($href) ?>"
                                   class="px-3 py-1 rounded-full text-xs font-semibold transition <?= $isActive ? 'bg-emerald-600 text-white shadow-sm' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' ?>">
                                    <?= e($label) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <a href="<?= e(exportCsvUrl()) ?>" class="px-3 py-1 rounded-lg text-xs font-semibold bg-emerald-50 dark:bg-emerald-950/70 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 hover:bg-emerald-100 dark:hover:bg-emerald-900/70 transition">
                            ↓ Export CSV
                        </a>
                    </div>

                    <form method="GET" class="space-y-3">
                        <input type="hidden" name="period" id="filterPeriodInput" value="<?= e($period) ?>">

                        <!-- Search Bar -->
                        <div class="relative">
                            <input type="text" name="q" value="<?= e($searchQuery) ?>"
                                   placeholder="🔍 Cari catatan, kategori, atau nama dompet (misal: kopi, listrik, BCA)..."
                                   class="w-full text-sm bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded-lg px-3.5 py-2 focus:outline-none focus:border-emerald-500">
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Pilih Bulan</label>
                                <input type="month" name="month" value="<?= e($filterMonth) ?>"
                                       onchange="document.getElementById('filterPeriodInput').value='month'"
                                       class="w-full text-sm bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-1.5 [color-scheme:light] dark:[color-scheme:dark]">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Dompet</label>
                                <select name="wallet_id" class="w-full text-sm bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-1.5">
                                    <option value="">Semua Dompet</option>
                                    <?php foreach ($activeWallets as $w): ?>
                                        <option value="<?= $w['id'] ?>" <?= $filterWallet == $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Kategori</label>
                                <select name="category_id" class="w-full text-sm bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-1.5">
                                    <option value="">Semua Kategori</option>
                                    <?php foreach ($activeCategories as $c): ?>
                                        <option value="<?= $c['id'] ?>" <?= $filterCategory == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Tipe</label>
                                <select name="type" class="w-full text-sm bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 rounded-lg px-2.5 py-1.5">
                                    <option value="">Semua Tipe</option>
                                    <option value="income" <?= $filterType === 'income' ? 'selected' : '' ?>>Pemasukan</option>
                                    <option value="expense" <?= $filterType === 'expense' ? 'selected' : '' ?>>Pengeluaran</option>
                                </select>
                            </div>
                            <div class="flex gap-2">
                                <button type="submit" class="w-full bg-slate-800 dark:bg-slate-700 hover:bg-slate-700 dark:hover:bg-slate-600 text-white text-sm font-medium py-1.5 px-3 rounded-lg transition">Cari</button>
                                <a href="index.php?period=all" class="bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-sm font-medium py-1.5 px-3 rounded-lg text-center transition">Reset</a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Table -->
                <div class="bg-white dark:bg-slate-900 rounded-xl shadow-sm border border-slate-200 dark:border-slate-800 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200 dark:border-slate-800 text-xs uppercase text-slate-500 dark:text-slate-400">
                                    <th class="py-3 px-4">Tanggal</th>
                                    <th class="py-3 px-4">Kategori &amp; Catatan</th>
                                    <th class="py-3 px-4">Dompet</th>
                                    <th class="py-3 px-4 text-right">Nominal</th>
                                    <th class="py-3 px-4 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-sm">
                                <?php if (empty($transactions)): ?>
                                    <tr>
                                        <td colspan="5" class="py-8 text-center text-slate-400">Tidak ada transaksi yang cocok dengan pencarian/filter ini.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($transactions as $t): ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                                        <td class="py-3 px-4 whitespace-nowrap text-slate-600 dark:text-slate-400"><?= e($t['transaction_date']) ?></td>
                                        <td class="py-3 px-4">
                                            <span class="font-semibold text-slate-800 dark:text-slate-100"><?= e($t['category_name']) ?></span>
                                            <?php if ($t['category_deleted']): ?>
                                                <span class="text-[10px] bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300 px-1.5 py-0.5 rounded">Deleted</span>
                                            <?php endif; ?>
                                            <?php if ($t['description']): ?>
                                                <p class="text-xs text-slate-500 dark:text-slate-400"><?= e($t['description']) ?></p>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 text-slate-700 dark:text-slate-300 whitespace-nowrap">
                                            <?= e($t['wallet_name']) ?>
                                            <?php if ($t['wallet_deleted']): ?>
                                                <span class="text-[10px] bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300 px-1.5 py-0.5 rounded">Deleted</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 text-right font-bold whitespace-nowrap <?= $t['type'] === 'income' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' ?>">
                                            <?= $t['type'] === 'income' ? '+' : '-' ?> <?= rupiah($t['amount']) ?>
                                        </td>
                                        <td class="py-3 px-4 text-center whitespace-nowrap">
                                            <button type="button" onclick='openEditTrxModal(<?= json_encode($t, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' class="text-xs text-blue-600 dark:text-blue-400 hover:underline font-semibold mr-2">Edit</button>
                                            <form method="POST" class="inline" onsubmit="return confirm('Pindahkan transaksi ini ke Trash? Saldo dompet akan otomatis dikembalikan.')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_transaction">
                                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                                <button type="submit" class="text-xs text-rose-600 dark:text-rose-400 hover:underline font-semibold">Hapus</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php require_once __DIR__ . '/modals.php'; ?>

    <script>
        window.CHART_DATA = <?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    </script>
    <script src="app.js"></script>
</body>
</html>

<?php
if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed.');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

$host   = '127.0.0.1';
$user   = 'root';
$pass   = '';
$dbName = 'expense_tracker';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbName`");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wallets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            deleted_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_wallets_deleted (deleted_at)
        ) ENGINE=InnoDB;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            type ENUM('income', 'expense') NOT NULL,
            deleted_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_categories_deleted (deleted_at, type)
        ) ENGINE=InnoDB;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            wallet_id INT NOT NULL,
            category_id INT NOT NULL,
            type ENUM('income', 'expense') NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            transaction_date DATE NOT NULL,
            deleted_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_trx_date_deleted (transaction_date, deleted_at),
            CONSTRAINT fk_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id),
            CONSTRAINT fk_category FOREIGN KEY (category_id) REFERENCES categories(id)
        ) ENGINE=InnoDB;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transfers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_wallet_id INT NOT NULL,
            to_wallet_id INT NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            note VARCHAR(255) DEFAULT NULL,
            transfer_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_transfers_date (transfer_date),
            CONSTRAINT fk_from_wallet FOREIGN KEY (from_wallet_id) REFERENCES wallets(id),
            CONSTRAINT fk_to_wallet FOREIGN KEY (to_wallet_id) REFERENCES wallets(id)
        ) ENGINE=InnoDB;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS recurring_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            wallet_id INT NOT NULL,
            category_id INT NOT NULL,
            type ENUM('income', 'expense') NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            day_of_month TINYINT NOT NULL DEFAULT 1,
            last_executed_month VARCHAR(7) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rec_active (is_active, day_of_month),
            CONSTRAINT fk_rec_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id),
            CONSTRAINT fk_rec_category FOREIGN KEY (category_id) REFERENCES categories(id)
        ) ENGINE=InnoDB;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;
    ");

    if ((int)$pdo->query("SELECT COUNT(*) FROM wallets")->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO wallets (name, balance) VALUES ('Cash', 0), ('Rekening Bank', 0)");
    }

    if ((int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn() === 0) {
        $pdo->exec("
            INSERT INTO categories (name, type) VALUES 
            ('Gaji', 'income'),
            ('Bonus / Lainnya', 'income'),
            ('Makan & Minum', 'expense'),
            ('Transportasi', 'expense'),
            ('Tagihan & Utilitas', 'expense')
        ");
    }
} catch (PDOException $e) {
    error_log('Database Error: ' . $e->getMessage());
    die('Koneksi database gagal. Pastikan layanan MySQL di XAMPP sudah aktif.');
}

function e($str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function rupiah($angka): string {
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}

function flash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        throw new Exception('Token keamanan (CSRF) tidak valid atau sesi kedaluwarsa. Silakan ulangi.');
    }
}

function validate_date(string $date): string {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        throw new Exception('Format tanggal tidak valid.');
    }
    return $date;
}

function assert_active_wallet(PDO $pdo, int $walletId): array {
    $stmt = $pdo->prepare('SELECT id, name, balance FROM wallets WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$walletId]);
    $wallet = $stmt->fetch();
    if (!$wallet) {
        throw new Exception('Dompet tidak ditemukan atau sudah berada di Trash.');
    }
    return $wallet;
}

function assert_active_category(PDO $pdo, int $categoryId, string $expectedType): array {
    $stmt = $pdo->prepare('SELECT id, name, type FROM categories WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$categoryId]);
    $category = $stmt->fetch();
    if (!$category) {
        throw new Exception('Kategori tidak ditemukan atau sudah berada di Trash.');
    }
    if ($category['type'] !== $expectedType) {
        throw new Exception('Tipe kategori tidak sesuai dengan tipe transaksi (' . $expectedType . ').');
    }
    return $category;
}

function get_setting(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false && $val !== null ? (string)$val : $default;
}

function set_setting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare('
        INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ');
    $stmt->execute([$key, $value]);
}

function sync_to_google_sheets(PDO $pdo, string $event = 'auto'): array {
    $webhookUrl = trim(get_setting($pdo, 'gsheet_webhook_url'));
    $autoSync   = get_setting($pdo, 'gsheet_auto_sync', '0') === '1';
    $secret     = get_setting($pdo, 'gsheet_secret', '');

    if ($webhookUrl === '') {
        return ['ok' => false, 'message' => 'URL Webhook Google Apps Script belum diatur.'];
    }

    if ($event !== 'manual' && !$autoSync) {
        return ['ok' => false, 'skipped' => true, 'message' => 'Auto-sync nonaktif.'];
    }

    $wallets = $pdo->query("SELECT id, name, balance FROM wallets WHERE deleted_at IS NULL ORDER BY id ASC")->fetchAll();
    $transactions = $pdo->query("
        SELECT t.id, t.transaction_date, t.type, t.amount, t.description,
               w.name AS wallet_name, c.name AS category_name
        FROM transactions t
        LEFT JOIN wallets w ON t.wallet_id = w.id
        LEFT JOIN categories c ON t.category_id = c.id
        WHERE t.deleted_at IS NULL
        ORDER BY t.transaction_date DESC, t.id DESC
    ")->fetchAll();
    $transfers = $pdo->query("
        SELECT tr.id, tr.transfer_date, tr.amount, tr.note,
               wf.name AS from_name, wt.name AS to_name
        FROM transfers tr
        LEFT JOIN wallets wf ON tr.from_wallet_id = wf.id
        LEFT JOIN wallets wt ON tr.to_wallet_id = wt.id
        ORDER BY tr.transfer_date DESC, tr.id DESC
    ")->fetchAll();

    $now = date('Y-m-d H:i:s');
    $payload = json_encode([
        'secret'       => $secret,
        'event'        => $event,
        'synced_at'    => $now,
        'wallets'      => $wallets,
        'transactions' => $transactions,
        'transfers'    => $transfers,
    ], JSON_UNESCAPED_UNICODE);

    $responseBody = false;
    $httpCode     = 0;
    $errMsg       = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $responseBody = curl_exec($ch);
        $httpCode     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($responseBody === false) {
            $errMsg = curl_error($ch);
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'          => 'POST',
                'header'          => "Content-Type: application/json\r\n",
                'content'         => $payload,
                'timeout'         => 10,
                'follow_location' => 1,
                'ignore_errors'   => true,
            ],
        ]);
        $responseBody = @file_get_contents($webhookUrl, false, $ctx);
        if ($responseBody === false) {
            $errMsg = 'Gagal menghubungi server Google Apps Script.';
        } else {
            $httpCode = 200;
        }
    }

    if ($responseBody === false || $httpCode >= 400) {
        $statusText = 'Gagal (' . ($errMsg ?: 'HTTP ' . $httpCode) . ')';
        set_setting($pdo, 'gsheet_last_status', $statusText);
        return ['ok' => false, 'message' => $statusText];
    }

    $decoded = json_decode((string)$responseBody, true);
    if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false) {
        $statusText = 'Ditolak Script: ' . ($decoded['error'] ?? 'Unknown error');
        set_setting($pdo, 'gsheet_last_status', $statusText);
        return ['ok' => false, 'message' => $statusText];
    }

    set_setting($pdo, 'gsheet_last_sync', $now);
    set_setting($pdo, 'gsheet_last_status', 'Berhasil pada ' . $now);

    return ['ok' => true, 'message' => 'Data berhasil disinkronkan ke Google Sheets (' . $now . ').'];
}

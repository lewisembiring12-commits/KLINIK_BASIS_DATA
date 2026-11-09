<?php

date_default_timezone_set('Asia/Makassar'); // WITA, ubah bila klinik berada di zona waktu lain

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=db_klinik;charset=utf8mb4',
        'root',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $pdo->exec("SET time_zone = '+08:00'"); // samakan dengan zona waktu PHP di atas
} catch (PDOException $e) {
    die('Koneksi database gagal: ' . $e->getMessage());
}

const BULAN = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const METODE_BAYAR = ['Tunai', 'Debit', 'QRIS', 'BPJS', 'Asuransi'];

/* ---------- Format & tampilan ---------- */

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiah(float|int|string $value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

function tgl(?string $value, bool $withTime = false): string
{
    $time = $value ? strtotime((string) $value) : false;
    if ($time === false) {
        return '-';
    }

    $text = date('j', $time) . ' ' . BULAN[(int) date('n', $time)] . ' ' . date('Y', $time);

    return $withTime ? $text . ', ' . date('H:i', $time) : $text;
}

function tanggal_valid(mixed $value, string $default): string
{
    $date = is_string($value) ? DateTime::createFromFormat('!Y-m-d', $value) : false;
    $errors = DateTime::getLastErrors();

    if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
        return $date->format('Y-m-d');
    }

    return $default;
}

function umur(string $birthDate): string
{
    $diff = (new DateTime($birthDate))->diff(new DateTime('today'));

    return $diff->y >= 1 ? $diff->y . ' th' : $diff->m . ' bln';
}

function badge(string $status): string
{
    $map = [
        'Menunggu' => 'wait', 'Diperiksa' => 'prog', 'Selesai' => 'done', 'Batal' => 'cancel',
        'Belum Bayar' => 'unpaid', 'Sebagian' => 'part', 'Lunas' => 'paid',
    ];

    return '<span class="badge b-' . ($map[$status] ?? 'wait') . '">' . e($status) . '</span>';
}

function opts(array $rows, string $value, string $label, $selected = null): string
{
    $html = '';
    foreach ($rows as $r) {
        $isSelected = (string) $selected === (string) $r[$value] ? ' selected' : '';
        $html .= '<option value="' . e($r[$value]) . '"' . $isSelected . '>' . e($r[$label]) . '</option>';
    }

    return $html;
}

/* ---------- Query singkat ---------- */

function rows(PDO $pdo, string $sql, array $params = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function row(PDO $pdo, string $sql, array $params = [])
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetch() ?: null;
}

function scalar(PDO $pdo, string $sql, array $params = [])
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchColumn();
}

/* ---------- Pesan sekali tampil (flash) & redirect ---------- */

function flash(?string $message = null, string $type = 'ok')
{
    if ($message !== null) {
        $_SESSION['flash'] = [$message, $type];

        return null;
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $flash;
}

function redirect(string $url, ?string $message = null, string $type = 'ok')
{
    if ($message !== null && $message !== '') {
        flash($message, $type);
    }

    header('Location: ' . $url);
    exit;
}

/* ---------- Transaksi: hitung ulang total & status ---------- */

function hitung_transaksi(PDO $pdo, int $id): void
{
    $subtotal = (float) scalar($pdo, 'SELECT COALESCE(SUM(subtotal), 0) FROM transaksi_detail WHERE id_transaksi = ?', [$id]);
    $paid = (float) scalar($pdo, 'SELECT COALESCE(SUM(jumlah_bayar), 0) FROM pembayaran_transaksi WHERE id_transaksi = ?', [$id]);
    $invoice = row($pdo, 'SELECT diskon, status_bayar FROM transaksi WHERE id_transaksi = ?', [$id]);
    if (!$invoice) {
        throw new RuntimeException('Invoice tidak ditemukan.');
    }

    $discount = min((float) $invoice['diskon'], $subtotal);
    // Perbaikan operator pengurangan yang terpotong sebelumnya
    $bill = round($subtotal - $discount, 2);

    if ($invoice['status_bayar'] === 'Batal') {
        $status = 'Batal';
    } elseif (round($paid, 2) >= $bill) {
        $status = 'Lunas';
    } elseif ($paid > 0) {
        $status = 'Sebagian';
    } else {
        $status = 'Belum Bayar';
    }

    $pdo->prepare(
        'UPDATE transaksi SET subtotal = ?, diskon = ?, total_dibayar = ?, status_bayar = ? WHERE id_transaksi = ?'
    )->execute([$subtotal, $discount, $paid, $status, $id]);
}

/* ---------- Layout ---------- */

function head(string $title, string $active = ''): void
{
    $menu = [
        'Layanan' => [
            'index.php' => 'Dashboard',
            'kunjungan.php' => 'Pendaftaran',
            'pemeriksaan.php' => 'Pemeriksaan dokter',
            'transaksi.php' => 'Transaksi',
            'laporan.php' => 'Laporan',
        ],
        'Data master' => [
            'master.php?t=pasien' => 'Pasien',
            'master.php?t=dokter' => 'Dokter',
            'master.php?t=obat' => 'Obat',
            'master.php?t=penyakit' => 'Penyakit',
            'master.php?t=tindakan' => 'Tindakan & tarif',
        ],
    ];

    echo '<!doctype html><html lang="id"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . ' | Klinik Sehat</title>'
        . '<link rel="stylesheet" href="style.css"></head><body>'
        . '<aside><div class="brand">Klinik Sehat'
        . '<small>Sistem informasi klinik</small></div><nav>';

    foreach ($menu as $group => $items) {
        echo '<div class="nav-group">' . e($group) . '</div>';
        foreach ($items as $u => $n) {
            $class = $u === $active ? ' class="on"' : '';
            echo '<a href="' . e($u) . '"' . $class . '>' . e($n) . '</a>';
        }
    }

    echo '</nav></aside><main><h1>' . e($title) . '</h1>';

    if ($flash = flash()) {
        echo '<p class="msg' . ($flash[1] === 'err' ? ' err' : '') . '">' . e($flash[0]) . '</p>';
    }
}

function foot(): void
{
    // Memperbaiki typo huruf 'e' yang nyasar sebelumnya
    echo '</main></body></html>';
}
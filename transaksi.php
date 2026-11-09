<?php

require_once __DIR__ . '/config.php';

$from = tanggal_valid($_GET['dari'] ?? '', date('Y-m-d', strtotime('-30 days')));
$to = tanggal_valid($_GET['sampai'] ?? '', date('Y-m-d'));
$status = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');
$statuses = ['Belum Bayar', 'Sebagian', 'Lunas', 'Batal'];

$where = ['DATE(t.tanggal_transaksi) BETWEEN ? AND ?'];
$params = [$from, $to];

if (in_array($status, $statuses, true)) {
    $where[] = 't.status_bayar = ?';
    $params[] = $status;
}
if ($search !== '') {
    $where[] = '(p.nama_pasien LIKE ? OR p.no_rekam_medis LIKE ? OR t.no_invoice LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
}

$list = rows(
    $pdo,
    'SELECT t.id_transaksi, t.no_invoice, t.tanggal_transaksi, t.total_tagihan, t.total_dibayar, t.status_bayar,
            (t.total_tagihan - t.total_dibayar) AS sisa,
            p.nama_pasien, p.no_rekam_medis, d.nama_dokter
     FROM transaksi t
     JOIN kunjungan k ON k.id_kunjungan = t.id_kunjungan
     JOIN pasien p    ON p.id_pasien = k.id_pasien
     JOIN dokter d    ON d.id_dokter = k.id_dokter
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY t.tanggal_transaksi DESC, t.id_transaksi DESC
     LIMIT 300',
    $params
);

$billed = $received = $outstanding = 0;
foreach ($list as $item) {
    if ($item['status_bayar'] === 'Batal') {
        continue;
    }
    $billed += $item['total_tagihan'];
    $received += $item['total_dibayar'];
    $outstanding += $item['sisa'];
}

head('Transaksi', 'transaksi.php');
?>

<section>
    <form method="get" class="filters">
        <label>
            Dari
            <input type="date" name="dari" value="<?= e($from) ?>">
        </label>
        <label>
            Sampai
            <input type="date" name="sampai" value="<?= e($to) ?>">
        </label>
        <label>
            Status
            <select name="status">
                <option value="">Semua status</option>
                <?php foreach ($statuses as $item): ?>
                    <option <?= $status === $item ? 'selected' : '' ?>><?= e($item) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Cari pasien / No. RM / invoice
            <input name="q" value="<?= e($search) ?>">
        </label>
        <button>Tampilkan</button>
    </form>
</section>

<div class="stats">
    <div><b><?= e(rupiah($billed)) ?></b>Total tagihan</div>
    <div><b><?= e(rupiah($received)) ?></b>Sudah dibayar</div>
    <div><b><?= e(rupiah($outstanding)) ?></b>Belum dibayar</div>
    <div><b><?= count($list) ?></b>Invoice</div>
</div>

<section>
    <div class="scroll">
        <table>
            <tr>
                <th>Invoice</th>
                <th>Tanggal</th>
                <th>Pasien</th>
                <th>Dokter</th>
                <th class="n">Tagihan</th>
                <th class="n">Dibayar</th>
                <th class="n">Sisa</th>
                <th>Status</th>
                <th></th>
            </tr>
            <?php foreach ($list as $item): ?>
                <tr>
                    <td><?= e($item['no_invoice']) ?></td>
                    <td><?= e(tgl($item['tanggal_transaksi'])) ?></td>
                    <td>
                        <?= e($item['nama_pasien']) ?><br>
                        <small class="muted"><?= e($item['no_rekam_medis']) ?></small>
                    </td>
                    <td><?= e($item['nama_dokter']) ?></td>
                    <td class="n"><?= e(rupiah($item['total_tagihan'])) ?></td>
                    <td class="n"><?= e(rupiah($item['total_dibayar'])) ?></td>
                    <td class="n"><?= $item['status_bayar'] === 'Batal' ? '-' : e(rupiah($item['sisa'])) ?></td>
                    <td><?= badge($item['status_bayar']) ?></td>
                    <td class="r">
                        <a href="invoice.php?id=<?= e($item['id_transaksi']) ?>">
                            <?= in_array($item['status_bayar'], ['Belum Bayar', 'Sebagian'], true) ? 'Bayar' : 'Buka' ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$list): ?>
                <tr>
                    <td colspan="9" class="muted">
                        Tidak ada invoice pada filter ini. Invoice dibuat otomatis setelah dokter menyimpan pemeriksaan.
                    </td>
                </tr>
            <?php endif; ?>
        </table>
    </div>
</section>

<?php foot(); ?>

<?php

require_once __DIR__ . '/config.php';

$date = $_GET['tgl'] ?? date('Y-m-d');
if (!DateTime::createFromFormat('Y-m-d', $date)) {
    $date = date('Y-m-d');
}
$doctorId = (int) ($_GET['dokter'] ?? 0);
$status = $_GET['status'] ?? '';
$statuses = ['Menunggu', 'Diperiksa', 'Selesai', 'Batal'];

$doctors = rows($pdo, 'SELECT id_dokter, nama_dokter FROM dokter WHERE status_aktif = 1 ORDER BY nama_dokter');

$where = ['DATE(k.tanggal_kunjungan) = ?'];
$params = [$date];
if ($doctorId) {
    $where[] = 'k.id_dokter = ?';
    $params[] = $doctorId;
}
if (in_array($status, $statuses, true)) {
    $where[] = 'k.status = ?';
    $params[] = $status;
}

$queue = rows(
    $pdo,
    "SELECT k.id_kunjungan, k.no_antrian, k.keluhan, k.status,
            p.nama_pasien, p.no_rekam_medis, p.tanggal_lahir, p.jenis_kelamin, p.alergi,
            d.nama_dokter, s.nama_spesialisasi,
            pm.vonis, t.id_transaksi, t.status_bayar
     FROM kunjungan k
     JOIN pasien p        ON p.id_pasien = k.id_pasien
     JOIN dokter d        ON d.id_dokter = k.id_dokter
     JOIN spesialisasi s  ON s.id_spesialisasi = d.id_spesialisasi
     LEFT JOIN pemeriksaan pm ON pm.id_kunjungan = k.id_kunjungan
     LEFT JOIN transaksi t    ON t.id_kunjungan = k.id_kunjungan
     WHERE " . implode(' AND ', $where) . "
     ORDER BY FIELD(k.status, 'Diperiksa', 'Menunggu', 'Selesai', 'Batal'), k.no_antrian, k.id_kunjungan",
    $params
);

$count = array_count_values(array_column($queue, 'status'));

head('Pemeriksaan dokter', 'pemeriksaan.php');
?>

<section>
    <form method="get" class="filters">
        <label>
            Tanggal
            <input type="date" name="tgl" value="<?= e($date) ?>">
        </label>
        <label>
            Dokter
            <select name="dokter">
                <option value="">Semua dokter</option>
                <?= opts($doctors, 'id_dokter', 'nama_dokter', $doctorId) ?>
            </select>
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
        <button>Tampilkan</button>
    </form>
</section>

<div class="chips">
    <?php foreach ($statuses as $item): ?>
        <span><?= e($item) ?>: <b><?= (int) ($count[$item] ?? 0) ?></b></span>
    <?php endforeach; ?>
</div>

<section>
    <div class="scroll">
        <table>
            <tr>
                <th class="n">No.</th>
                <th>Pasien</th>
                <th>Dokter</th>
                <th>Keluhan</th>
                <th>Status</th>
                <th>Vonis</th>
                <th></th>
            </tr>
            <?php foreach ($queue as $visit): ?>
                <tr>
                    <td class="n"><?= e($visit['no_antrian'] ?: '-') ?></td>
                    <td>
                        <b><?= e($visit['nama_pasien']) ?></b><br>
                        <small class="muted">
                            <?= e($visit['no_rekam_medis']) ?>,
                            <?= $visit['jenis_kelamin'] === 'L' ? 'L' : 'P' ?>,
                            <?= e(umur($visit['tanggal_lahir'])) ?>
                        </small>
                        <?php if ($visit['alergi']): ?>
                            <br><small style="color:var(--warn);font-weight:600">Alergi: <?= e($visit['alergi']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= e($visit['nama_dokter']) ?><br>
                        <small class="muted"><?= e($visit['nama_spesialisasi']) ?></small>
                    </td>
                    <td><?= e($visit['keluhan']) ?></td>
                    <td><?= badge($visit['status']) ?></td>
                    <td><?= e($visit['vonis'] ?: '-') ?></td>
                    <td class="r">
                        <?php if (in_array($visit['status'], ['Menunggu', 'Diperiksa'], true)): ?>
                            <a class="btn sm" href="periksa.php?id=<?= e($visit['id_kunjungan']) ?>">
                                <?= $visit['status'] === 'Menunggu' ? 'Mulai periksa' : 'Lanjutkan' ?>
                            </a>
                        <?php elseif ($visit['status'] === 'Selesai'): ?>
                            <a href="periksa.php?id=<?= e($visit['id_kunjungan']) ?>">Rekam medis</a>
                            <?php if ($visit['id_transaksi']): ?>
                                &middot; <a href="invoice.php?id=<?= e($visit['id_transaksi']) ?>">Invoice</a>
                                <?= badge($visit['status_bayar']) ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$queue): ?>
                <tr>
                    <td colspan="7" class="muted">
                        Tidak ada pasien pada filter ini. Pasien muncul di sini setelah didaftarkan di menu
                        <a href="kunjungan.php">Pendaftaran</a>.
                    </td>
                </tr>
            <?php endif; ?>
        </table>
    </div>
</section>

<?php foot(); ?>

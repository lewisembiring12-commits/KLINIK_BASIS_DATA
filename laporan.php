<?php

require_once __DIR__ . '/config.php';

$from = tanggal_valid($_GET['dari'] ?? '', date('Y-m-d', strtotime('-30 days')));
$to = tanggal_valid($_GET['sampai'] ?? '', date('Y-m-d'));
$range = [$from, $to];

$received = (float) scalar(
    $pdo,
    'SELECT COALESCE(SUM(jumlah_bayar), 0) FROM pembayaran_transaksi WHERE DATE(tanggal_bayar) BETWEEN ? AND ?',
    $range
);
$billed = (float) scalar(
    $pdo,
    "SELECT COALESCE(SUM(total_tagihan), 0) FROM transaksi
     WHERE status_bayar <> 'Batal' AND DATE(tanggal_transaksi) BETWEEN ? AND ?",
    $range
);
$outstanding = (float) scalar(
    $pdo,
    "SELECT COALESCE(SUM(total_tagihan - total_dibayar), 0) FROM transaksi
     WHERE status_bayar IN ('Belum Bayar', 'Sebagian') AND DATE(tanggal_transaksi) BETWEEN ? AND ?",
    $range
);
$visits = (int) scalar(
    $pdo,
    "SELECT COUNT(*) FROM kunjungan WHERE status = 'Selesai' AND DATE(tanggal_kunjungan) BETWEEN ? AND ?",
    $range
);

$byMethod = rows(
    $pdo,
    'SELECT metode_bayar, COUNT(*) AS n, SUM(jumlah_bayar) AS total
     FROM pembayaran_transaksi
     WHERE DATE(tanggal_bayar) BETWEEN ? AND ?
     GROUP BY metode_bayar ORDER BY total DESC',
    $range
);

$byComponent = rows(
    $pdo,
    "SELECT d.jenis, COUNT(*) AS n, SUM(d.subtotal) AS total
     FROM transaksi_detail d
     JOIN transaksi t ON t.id_transaksi = d.id_transaksi
     WHERE t.status_bayar <> 'Batal' AND DATE(t.tanggal_transaksi) BETWEEN ? AND ?
     GROUP BY d.jenis
     ORDER BY FIELD(d.jenis, 'Konsultasi', 'Tindakan', 'Obat', 'Lainnya')",
    $range
);

$byDoctor = rows(
    $pdo,
    "SELECT dk.nama_dokter, COUNT(*) AS n, SUM(t.total_tagihan) AS tagihan, SUM(t.total_dibayar) AS dibayar
     FROM transaksi t
     JOIN kunjungan k ON k.id_kunjungan = t.id_kunjungan
     JOIN dokter dk   ON dk.id_dokter = k.id_dokter
     WHERE t.status_bayar <> 'Batal' AND DATE(t.tanggal_transaksi) BETWEEN ? AND ?
     GROUP BY dk.id_dokter, dk.nama_dokter ORDER BY tagihan DESC",
    $range
);

$byDay = rows(
    $pdo,
    "SELECT DATE(tanggal_transaksi) AS tgl, COUNT(*) AS n, SUM(total_tagihan) AS total
     FROM transaksi
     WHERE status_bayar <> 'Batal' AND DATE(tanggal_transaksi) BETWEEN ? AND ?
     GROUP BY DATE(tanggal_transaksi) ORDER BY tgl DESC",
    $range
);
$maxDay = $byDay ? max(array_column($byDay, 'total')) : 0;

$topDiseases = rows(
    $pdo,
    "SELECT pn.kode_icd10, pn.nama_penyakit, COUNT(*) AS n
     FROM diagnosa dg
     JOIN kunjungan k ON k.id_kunjungan = dg.id_kunjungan
     JOIN penyakit pn ON pn.id_penyakit = dg.id_penyakit
     WHERE k.status = 'Selesai' AND DATE(k.tanggal_kunjungan) BETWEEN ? AND ?
     GROUP BY pn.id_penyakit, pn.kode_icd10, pn.nama_penyakit ORDER BY n DESC LIMIT 5",
    $range
);

head('Laporan', 'laporan.php');
?>

<section class="no-print">
    <form method="get" class="filters">
        <label>
            Dari
            <input type="date" name="dari" value="<?= e($from) ?>">
        </label>
        <label>
            Sampai
            <input type="date" name="sampai" value="<?= e($to) ?>">
        </label>
        <button>Tampilkan</button>
        <button type="button" class="sec" onclick="window.print()">Cetak</button>
    </form>
</section>

<p class="hint">Periode <?= e(tgl($from)) ?> sampai <?= e(tgl($to)) ?></p>

<div class="stats">
    <div><b><?= e(rupiah($received)) ?></b>Uang diterima</div>
    <div><b><?= e(rupiah($billed)) ?></b>Total tagihan</div>
    <div><b><?= e(rupiah($outstanding)) ?></b>Belum dibayar</div>
    <div><b><?= $visits ?></b>Kunjungan selesai</div>
</div>

<div class="two">
    <section>
        <h2>Uang diterima per metode bayar</h2>
        <table>
            <?php foreach ($byMethod as $item): ?>
                <tr>
                    <td><?= e($item['metode_bayar']) ?> <span class="muted">(<?= e($item['n']) ?>x)</span></td>
                    <td class="n"><?= e(rupiah($item['total'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$byMethod): ?>
                <tr><td class="muted">Belum ada pembayaran pada periode ini.</td></tr>
            <?php endif; ?>
        </table>
    </section>

    <section>
        <h2>Tagihan per komponen</h2>
        <table>
            <?php foreach ($byComponent as $item): ?>
                <tr>
                    <td><?= e($item['jenis']) ?></td>
                    <td class="n"><?= e(rupiah($item['total'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$byComponent): ?>
                <tr><td class="muted">Belum ada tagihan pada periode ini.</td></tr>
            <?php endif; ?>
        </table>
    </section>
</div>

<section style="margin-top:20px">
    <h2>Per dokter</h2>
    <div class="scroll">
        <table>
            <tr>
                <th>Dokter</th>
                <th class="n">Invoice</th>
                <th class="n">Tagihan</th>
                <th class="n">Dibayar</th>
            </tr>
            <?php foreach ($byDoctor as $item): ?>
                <tr>
                    <td><?= e($item['nama_dokter']) ?></td>
                    <td class="n"><?= e($item['n']) ?></td>
                    <td class="n"><?= e(rupiah($item['tagihan'])) ?></td>
                    <td class="n"><?= e(rupiah($item['dibayar'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$byDoctor): ?>
                <tr><td colspan="4" class="muted">Belum ada data pada periode ini.</td></tr>
            <?php endif; ?>
        </table>
    </div>
</section>

<div class="two">
    <section>
        <h2>Tagihan per hari</h2>
        <table>
            <?php foreach ($byDay as $item): ?>
                <tr>
                    <td style="width:110px"><?= e(tgl($item['tgl'])) ?></td>
                    <td><span class="bar" style="width:<?= $maxDay > 0 ? round($item['total'] / $maxDay * 100) : 0 ?>%"></span></td>
                    <td class="n"><?= e(rupiah($item['total'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$byDay): ?>
                <tr><td class="muted">Belum ada tagihan pada periode ini.</td></tr>
            <?php endif; ?>
        </table>
    </section>

    <section>
        <h2>Diagnosa tersering</h2>
        <table>
            <?php foreach ($topDiseases as $item): ?>
                <tr>
                    <td><?= e($item['nama_penyakit']) ?> <span class="muted"><?= e($item['kode_icd10']) ?></span></td>
                    <td class="n"><?= e($item['n']) ?> kasus</td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$topDiseases): ?>
                <tr><td class="muted">Belum ada diagnosa pada periode ini.</td></tr>
            <?php endif; ?>
        </table>
    </section>
</div>

<?php foot(); ?>

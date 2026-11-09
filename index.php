<?php

require_once __DIR__ . '/config.php';

head('Dashboard', 'index.php');

$stats = [
    'Pasien terdaftar' => scalar($pdo, 'SELECT COUNT(*) FROM pasien'),
    'Dokter aktif' => scalar($pdo, 'SELECT COUNT(*) FROM dokter WHERE status_aktif = 1'),
    'Antrian aktif hari ini' => scalar(
        $pdo,
        "SELECT COUNT(*) FROM kunjungan
         WHERE DATE(tanggal_kunjungan) = CURDATE() AND status IN ('Menunggu', 'Diperiksa')"
    ),
    'Kunjungan bulan ini' => scalar(
        $pdo,
        "SELECT COUNT(*) FROM kunjungan
         WHERE DATE_FORMAT(tanggal_kunjungan, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')"
    ),
    'Pendapatan hari ini' => rupiah(scalar(
        $pdo,
        'SELECT COALESCE(SUM(jumlah_bayar), 0) FROM pembayaran_transaksi WHERE DATE(tanggal_bayar) = CURDATE()'
    )),
    'Pendapatan 30 hari' => rupiah(scalar(
        $pdo,
        'SELECT COALESCE(SUM(jumlah_bayar), 0) FROM pembayaran_transaksi
         WHERE DATE(tanggal_bayar) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)'
    )),
    'Tagihan belum lunas' => rupiah(scalar(
        $pdo,
        "SELECT COALESCE(SUM(total_tagihan - total_dibayar), 0) FROM transaksi
         WHERE status_bayar IN ('Belum Bayar', 'Sebagian')"
    )),
    'Obat perlu perhatian' => scalar(
        $pdo,
        'SELECT COUNT(*) FROM obat
         WHERE stok < 100 OR tanggal_kadaluarsa <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)'
    ),
];

$queue = rows(
    $pdo,
    "SELECT k.id_kunjungan, k.no_antrian, k.status, p.nama_pasien, d.nama_dokter
     FROM kunjungan k
     JOIN pasien p ON p.id_pasien = k.id_pasien
     JOIN dokter d ON d.id_dokter = k.id_dokter
     WHERE DATE(k.tanggal_kunjungan) = CURDATE() AND k.status IN ('Menunggu', 'Diperiksa')
     ORDER BY FIELD(k.status, 'Diperiksa', 'Menunggu'), k.no_antrian
     LIMIT 6"
);

$unpaid = rows(
    $pdo,
    "SELECT id_transaksi, no_invoice, nama_pasien, sisa_tagihan, status_bayar
     FROM v_transaksi
     WHERE status_bayar IN ('Belum Bayar', 'Sebagian')
     ORDER BY tanggal_transaksi
     LIMIT 6"
);

// Pasien yang dijadwalkan kontrol dan belum kembali sejak pemeriksaan itu
$followUps = rows(
    $pdo,
    "SELECT pm.tanggal_kontrol, p.nama_pasien, p.no_telp, d.nama_dokter
     FROM pemeriksaan pm
     JOIN kunjungan k ON k.id_kunjungan = pm.id_kunjungan
     JOIN pasien p    ON p.id_pasien = k.id_pasien
     JOIN dokter d    ON d.id_dokter = k.id_dokter
     WHERE pm.vonis = 'Kontrol Ulang'
       AND pm.tanggal_kontrol BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
       AND NOT EXISTS (SELECT 1 FROM kunjungan k2
                       WHERE k2.id_pasien = k.id_pasien AND k2.tanggal_kunjungan > k.tanggal_kunjungan)
     ORDER BY pm.tanggal_kontrol
     LIMIT 6"
);

$diseases = rows(
    $pdo,
    'SELECT p.nama_penyakit, COUNT(*) AS jumlah
     FROM diagnosa d
     JOIN penyakit p USING (id_penyakit)
     GROUP BY p.id_penyakit, p.nama_penyakit
     ORDER BY jumlah DESC
     LIMIT 5'
);

$medicines = rows(
    $pdo,
    'SELECT nama_obat, stok, satuan, tanggal_kadaluarsa FROM obat
     WHERE stok < 100 OR tanggal_kadaluarsa <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
     ORDER BY stok
     LIMIT 8'
);
?>

<div class="stats">
    <?php foreach ($stats as $label => $value): ?>
        <div>
            <b><?= e($value) ?></b>
            <?= e($label) ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="two">
    <section>
        <h2>Antrian hari ini</h2>
        <table>
            <?php foreach ($queue as $row): ?>
                <tr>
                    <td class="n"><?= e($row['no_antrian'] ?: '-') ?></td>
                    <td>
                        <?= e($row['nama_pasien']) ?><br>
                        <small class="muted"><?= e($row['nama_dokter']) ?></small>
                    </td>
                    <td><?= badge($row['status']) ?></td>
                    <td class="r"><a href="periksa.php?id=<?= e($row['id_kunjungan']) ?>">Periksa</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$queue): ?>
                <tr><td class="muted">Tidak ada pasien menunggu. Daftarkan pasien di menu <a href="kunjungan.php">Pendaftaran</a>.</td></tr>
            <?php endif; ?>
        </table>
    </section>

    <section>
        <h2>Tagihan belum lunas</h2>
        <table>
            <?php foreach ($unpaid as $row): ?>
                <tr>
                    <td>
                        <?= e($row['nama_pasien']) ?><br>
                        <small class="muted"><?= e($row['no_invoice']) ?></small>
                    </td>
                    <td class="n"><?= e(rupiah($row['sisa_tagihan'])) ?></td>
                    <td><?= badge($row['status_bayar']) ?></td>
                    <td class="r"><a href="invoice.php?id=<?= e($row['id_transaksi']) ?>">Bayar</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$unpaid): ?>
                <tr><td class="muted">Semua tagihan sudah lunas.</td></tr>
            <?php endif; ?>
        </table>
    </section>

    <section>
        <h2>Jadwal kontrol pasien</h2>
        <table>
            <?php foreach ($followUps as $row): ?>
                <?php $overdue = $row['tanggal_kontrol'] < date('Y-m-d'); ?>
                <tr>
                    <td>
                        <?= e($row['nama_pasien']) ?><br>
                        <small class="muted"><?= e($row['nama_dokter']) ?>, <?= e($row['no_telp'] ?: 'tanpa telepon') ?></small>
                    </td>
                    <td class="n"><?= e(tgl($row['tanggal_kontrol'])) ?></td>
                    <td><?= $overdue ? '<span class="badge b-unpaid">Terlewat</span>' : '<span class="badge b-prog">Akan datang</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$followUps): ?>
                <tr><td class="muted">Tidak ada jadwal kontrol dalam 30 hari ke depan atau yang terlewat.</td></tr>
            <?php endif; ?>
        </table>
    </section>

    <section>
        <h2>Stok obat perlu perhatian</h2>
        <table>
            <?php foreach ($medicines as $row): ?>
                <?php $nearExpiry = $row['tanggal_kadaluarsa'] && $row['tanggal_kadaluarsa'] <= date('Y-m-d', strtotime('+90 days')); ?>
                <tr>
                    <td>
                        <?= e($row['nama_obat']) ?>
                        <?php if ($nearExpiry): ?>
                            <br><small style="color:var(--warn)">Kadaluarsa <?= e(tgl($row['tanggal_kadaluarsa'])) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="n"><?= e($row['stok']) ?> <?= e($row['satuan']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$medicines): ?>
                <tr><td class="muted">Semua stok aman dan belum ada yang mendekati kadaluarsa.</td></tr>
            <?php endif; ?>
        </table>
    </section>

    <section>
        <h2>Penyakit tersering</h2>
        <table>
            <?php foreach ($diseases as $row): ?>
                <tr>
                    <td><?= e($row['nama_penyakit']) ?></td>
                    <td class="n"><?= e($row['jumlah']) ?> kasus</td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$diseases): ?>
                <tr><td class="muted">Belum ada diagnosa tercatat.</td></tr>
            <?php endif; ?>
        </table>
    </section>
</div>

<?php foot(); ?>

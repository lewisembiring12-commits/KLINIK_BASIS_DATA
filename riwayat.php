<?php

require_once __DIR__ . '/config.php';

$patient = row($pdo, 'SELECT * FROM pasien WHERE id_pasien = ?', [(int) ($_GET['id'] ?? 0)]);

if (!$patient) {
    head('Riwayat pasien', 'master.php?t=pasien');
    echo '<p class="msg err">Pasien tidak ditemukan. '
        . '<a href="master.php?t=pasien">Kembali ke daftar pasien</a></p>';
    foot();
    exit;
}

$history = rows(
    $pdo,
    "SELECT v.id_kunjungan, v.tanggal_kunjungan, v.nama_dokter, v.nama_spesialisasi, v.keluhan, v.diagnosa,
            k.status, pm.vonis, pm.tanggal_kontrol,
            t.id_transaksi, t.total_tagihan, t.status_bayar,
            (SELECT GROUP_CONCAT(CONCAT(o.nama_obat, ' x', dr.jumlah) SEPARATOR ', ')
             FROM resep r
             JOIN detail_resep dr ON dr.id_resep = r.id_resep
             JOIN obat o ON o.id_obat = dr.id_obat
             WHERE r.id_kunjungan = v.id_kunjungan) AS obat
     FROM v_riwayat_pasien v
     JOIN kunjungan k ON k.id_kunjungan = v.id_kunjungan
     LEFT JOIN pemeriksaan pm ON pm.id_kunjungan = v.id_kunjungan
     LEFT JOIN transaksi t    ON t.id_kunjungan = v.id_kunjungan
     WHERE v.id_pasien = ?
     ORDER BY v.tanggal_kunjungan DESC",
    [$patient['id_pasien']]
);

head('Riwayat berobat: ' . $patient['nama_pasien'], 'master.php?t=pasien');
?>

<section class="banner">
    <div>
        <h2><?= e($patient['nama_pasien']) ?></h2>
        <p>
            <?= e($patient['no_rekam_medis']) ?>,
            <?= $patient['jenis_kelamin'] === 'L' ? 'laki-laki' : 'perempuan' ?>,
            lahir <?= e(tgl($patient['tanggal_lahir'])) ?> (<?= e(umur($patient['tanggal_lahir'])) ?>),
            gol. darah <?= e($patient['golongan_darah']) ?>
        </p>
        <p><?= e($patient['alamat'] ?: 'Alamat belum diisi') ?>, <?= e($patient['no_telp'] ?: 'tanpa telepon') ?></p>
    </div>
    <div class="side no-print">
        <a class="btn sm" href="kunjungan.php">Daftarkan kunjungan</a>
    </div>
    <?php if ($patient['alergi']): ?>
        <div class="alert">Alergi: <?= e($patient['alergi']) ?></div>
    <?php endif; ?>
</section>

<section>
    <div class="scroll">
        <table>
            <tr>
                <th>Tanggal</th>
                <th>Dokter</th>
                <th>Keluhan</th>
                <th>Diagnosa</th>
                <th>Vonis</th>
                <th>Obat</th>
                <th>Tagihan</th>
                <th></th>
            </tr>
            <?php foreach ($history as $record): ?>
                <tr>
                    <td><?= e(tgl($record['tanggal_kunjungan'])) ?></td>
                    <td>
                        <?= e($record['nama_dokter']) ?><br>
                        <small class="muted"><?= e($record['nama_spesialisasi']) ?></small>
                    </td>
                    <td><?= e($record['keluhan']) ?></td>
                    <td><?= e($record['diagnosa'] ?: '-') ?></td>
                    <td>
                        <?= e($record['vonis'] ?: '-') ?>
                        <?php if ($record['tanggal_kontrol']): ?>
                            <br><small class="muted">kontrol <?= e(tgl($record['tanggal_kontrol'])) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= e($record['obat'] ?: '-') ?></td>
                    <td>
                        <?php if ($record['id_transaksi']): ?>
                            <a href="invoice.php?id=<?= e($record['id_transaksi']) ?>"><?= e(rupiah($record['total_tagihan'])) ?></a><br>
                            <?= badge($record['status_bayar']) ?>
                        <?php else: ?>
                            <span class="muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="r"><a href="periksa.php?id=<?= e($record['id_kunjungan']) ?>">Detail</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$history): ?>
                <tr><td colspan="8" class="muted">Pasien ini belum pernah berkunjung. Daftarkan kunjungan pertamanya dari menu Pendaftaran.</td></tr>
            <?php endif; ?>
        </table>
    </div>
</section>

<?php foot(); ?>

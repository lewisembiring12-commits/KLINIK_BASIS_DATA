<?php

require_once __DIR__ . '/config.php';

$id = (int) ($_GET['id'] ?? 0);
if (!$id && isset($_GET['k'])) {
    $id = (int) scalar($pdo, 'SELECT id_transaksi FROM transaksi WHERE id_kunjungan = ?', [(int) $_GET['k']]);
}

$self = 'invoice.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    try {
        $pdo->beginTransaction();

        $current = row($pdo, 'SELECT * FROM transaksi WHERE id_transaksi = ? FOR UPDATE', [$id]);
        if (!$current) {
            throw new RuntimeException('Invoice tidak ditemukan.');
        }
        if ($current['status_bayar'] === 'Batal') {
            throw new RuntimeException('Invoice ini sudah dibatalkan dan tidak bisa diubah.');
        }
        if ($current['status_bayar'] === 'Lunas') {
            throw new RuntimeException('Invoice ini sudah lunas dan tidak bisa diubah.');
        }

        $message = '';

        if (isset($_POST['bayar'])) {
            $due = round((float) $current['total_tagihan'] - (float) $current['total_dibayar'], 2);
            $amount = round((float) ($_POST['jumlah'] ?? 0), 2);
            $method = $_POST['metode'] ?? '';

            if (!in_array($method, METODE_BAYAR, true)) {
                throw new RuntimeException('Pilih metode pembayaran.');
            }
            if ($amount <= 0) {
                throw new RuntimeException('Jumlah pembayaran harus lebih dari 0.');
            }
            if ($amount > $due) {
                throw new RuntimeException('Jumlah pembayaran melebihi sisa tagihan (' . rupiah($due) . ').');
            }

            $received = null;
            if ($method === 'Tunai') {
                $received = trim($_POST['diterima'] ?? '') === '' ? $amount : round((float) $_POST['diterima'], 2);
                if ($received < $amount) {
                    throw new RuntimeException('Uang yang diterima kurang dari jumlah pembayaran.');
                }
            }

            $pdo->prepare(
                'INSERT INTO pembayaran_transaksi
                    (id_transaksi, metode_bayar, jumlah_bayar, uang_diterima, no_referensi, kasir)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $id,
                $method,
                $amount,
                $received,
                trim($_POST['referensi'] ?? '') ?: null,
                trim($_POST['kasir'] ?? '') ?: null,
            ]);

            $message = 'Pembayaran ' . rupiah($amount) . ' via ' . $method . ' dicatat.';
            if ($received !== null && $received > $amount) {
                $message .= ' Kembalian: ' . rupiah($received - $amount) . '.';
            }
        } elseif (isset($_POST['diskon'])) {
            $subtotal = (float) scalar($pdo, 'SELECT COALESCE(SUM(subtotal), 0) FROM transaksi_detail WHERE id_transaksi = ?', [$id]);
            $discount = round((float) ($_POST['nominal'] ?? 0), 2);
            if ($discount < 0 || $discount > $subtotal) {
                throw new RuntimeException('Diskon harus antara 0 dan subtotal (' . rupiah($subtotal) . ').');
            }

            $pdo->prepare('UPDATE transaksi SET diskon = ? WHERE id_transaksi = ?')->execute([$discount, $id]);
            $message = 'Diskon diperbarui menjadi ' . rupiah($discount) . '.';
        } elseif (isset($_POST['tambah'])) {
            $description = trim($_POST['deskripsi'] ?? '');
            $qty = (int) ($_POST['qty'] ?? 1);
            $price = round((float) ($_POST['harga'] ?? 0), 2);
            if ($description === '' || $qty < 1 || $price < 0) {
                throw new RuntimeException('Isi nama biaya, jumlah minimal 1, dan harga tidak negatif.');
            }

            $pdo->prepare(
                "INSERT INTO transaksi_detail (id_transaksi, jenis, deskripsi, qty, harga_satuan)
                 VALUES (?, 'Lainnya', ?, ?, ?)"
            )->execute([$id, $description, $qty, $price]);
            $message = 'Biaya "' . $description . '" ditambahkan.';
        } elseif (isset($_POST['hapus_item'])) {
            $statement = $pdo->prepare(
                "DELETE FROM transaksi_detail WHERE id_detail_transaksi = ? AND id_transaksi = ? AND jenis = 'Lainnya'"
            );
            $statement->execute([$_POST['hapus_item'], $id]);
            $message = $statement->rowCount() ? 'Biaya dihapus.' : 'Hanya biaya tambahan yang bisa dihapus.';
        } elseif (isset($_POST['batal_invoice'])) {
            if ((float) $current['total_dibayar'] > 0) {
                throw new RuntimeException('Invoice yang sudah menerima pembayaran tidak bisa dibatalkan.');
            }

            $pdo->prepare("UPDATE transaksi SET status_bayar = 'Batal' WHERE id_transaksi = ?")->execute([$id]);
            $message = 'Invoice dibatalkan.';
        }

        hitung_transaksi($pdo, $id);

        $after = row($pdo, 'SELECT total_tagihan, total_dibayar FROM transaksi WHERE id_transaksi = ?', [$id]);
        if ((float) $after['total_tagihan'] < (float) $after['total_dibayar']) {
            throw new RuntimeException('Perubahan ditolak: total tagihan tidak boleh lebih kecil dari jumlah yang sudah dibayar.');
        }

        $pdo->commit();
        redirect($self, $message);
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash($exception->getMessage(), 'err');
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('Gagal menyimpan: ' . $exception->getMessage(), 'err');
    }
}

$invoice = $id ? row(
    $pdo,
    'SELECT t.*, k.tanggal_kunjungan, k.id_pasien,
            p.nama_pasien, p.no_rekam_medis, p.tanggal_lahir, p.jenis_kelamin, p.alamat, p.no_telp,
            d.nama_dokter, s.nama_spesialisasi
     FROM transaksi t
     JOIN kunjungan k    ON k.id_kunjungan = t.id_kunjungan
     JOIN pasien p       ON p.id_pasien = k.id_pasien
     JOIN dokter d       ON d.id_dokter = k.id_dokter
     JOIN spesialisasi s ON s.id_spesialisasi = d.id_spesialisasi
     WHERE t.id_transaksi = ?',
    [$id]
) : null;

if (!$invoice) {
    head('Invoice', 'transaksi.php');
    echo '<p class="msg err">Invoice tidak ditemukan. <a href="transaksi.php">Kembali ke daftar transaksi</a></p>';
    foot();
    exit;
}

$items = rows(
    $pdo,
    "SELECT * FROM transaksi_detail WHERE id_transaksi = ?
     ORDER BY FIELD(jenis, 'Konsultasi', 'Tindakan', 'Obat', 'Lainnya'), id_detail_transaksi",
    [$id]
);
$payments = rows($pdo, 'SELECT * FROM pembayaran_transaksi WHERE id_transaksi = ? ORDER BY tanggal_bayar, id_bayar', [$id]);

$due = (float) $invoice['total_tagihan'] - (float) $invoice['total_dibayar'];
$open = in_array($invoice['status_bayar'], ['Belum Bayar', 'Sebagian'], true);

head('Invoice ' . $invoice['no_invoice'], 'transaksi.php');
?>

<div class="print-only">
    <h2>Klinik Sehat</h2>
    <p>Invoice / bukti pembayaran</p>
</div>

<section class="banner">
    <div>
        <h2><?= e($invoice['nama_pasien']) ?></h2>
        <p>
            <?= e($invoice['no_rekam_medis']) ?>,
            <?= $invoice['jenis_kelamin'] === 'L' ? 'laki-laki' : 'perempuan' ?>,
            <?= e(umur($invoice['tanggal_lahir'])) ?>
        </p>
        <p>Kunjungan <?= e(tgl($invoice['tanggal_kunjungan'], true)) ?>, <?= e($invoice['nama_dokter']) ?> (<?= e($invoice['nama_spesialisasi']) ?>)</p>
    </div>
    <div class="side">
        <?= badge($invoice['status_bayar']) ?>
        <p>
            <b><?= e($invoice['no_invoice']) ?></b><br>
            Dibuat <?= e(tgl($invoice['tanggal_transaksi'], true)) ?>
        </p>
    </div>
</section>

<section>
    <h2>Rincian tagihan</h2>
    <div class="scroll">
        <table>
            <tr>
                <th>Keterangan</th>
                <th>Jenis</th>
                <th class="n">Jumlah</th>
                <th class="n">Harga</th>
                <th class="n">Subtotal</th>
                <?php if ($open): ?><th class="no-print"></th><?php endif; ?>
            </tr>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e($item['deskripsi']) ?></td>
                    <td><?= e($item['jenis']) ?></td>
                    <td class="n"><?= e($item['qty']) ?></td>
                    <td class="n"><?= e(rupiah($item['harga_satuan'])) ?></td>
                    <td class="n"><?= e(rupiah($item['subtotal'])) ?></td>
                    <?php if ($open): ?>
                        <td class="r no-print">
                            <?php if ($item['jenis'] === 'Lainnya'): ?>
                                <form method="post" onsubmit="return confirm('Hapus biaya ini?')">
                                    <button class="del" name="hapus_item" value="<?= e($item['id_detail_transaksi']) ?>">Hapus</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?>
                <tr><td colspan="6" class="muted">Belum ada rincian biaya.</td></tr>
            <?php endif; ?>
            <tfoot>
                <tr><td colspan="4" class="n">Subtotal</td><td class="n"><?= e(rupiah($invoice['subtotal'])) ?></td><?= $open ? '<td class="no-print"></td>' : '' ?></tr>
                <tr><td colspan="4" class="n">Diskon</td><td class="n">- <?= e(rupiah($invoice['diskon'])) ?></td><?= $open ? '<td class="no-print"></td>' : '' ?></tr>
                <tr class="grand"><td colspan="4" class="n">Total tagihan</td><td class="n"><?= e(rupiah($invoice['total_tagihan'])) ?></td><?= $open ? '<td class="no-print"></td>' : '' ?></tr>
                <tr><td colspan="4" class="n">Sudah dibayar</td><td class="n"><?= e(rupiah($invoice['total_dibayar'])) ?></td><?= $open ? '<td class="no-print"></td>' : '' ?></tr>
                <tr><td colspan="4" class="n">Sisa tagihan</td><td class="n"><?= e(rupiah(max(0, $due))) ?></td><?= $open ? '<td class="no-print"></td>' : '' ?></tr>
            </tfoot>
        </table>
    </div>
</section>

<section>
    <h2>Riwayat pembayaran</h2>
    <div class="scroll">
        <table>
            <tr>
                <th>Tanggal</th>
                <th>Metode</th>
                <th>Referensi</th>
                <th>Kasir</th>
                <th class="n">Diterima</th>
                <th class="n">Dibayar</th>
            </tr>
            <?php foreach ($payments as $payment): ?>
                <tr>
                    <td><?= e(tgl($payment['tanggal_bayar'], true)) ?></td>
                    <td><?= e($payment['metode_bayar']) ?></td>
                    <td><?= e($payment['no_referensi'] ?: '-') ?></td>
                    <td><?= e($payment['kasir'] ?: '-') ?></td>
                    <td class="n"><?= $payment['uang_diterima'] !== null ? e(rupiah($payment['uang_diterima'])) : '-' ?></td>
                    <td class="n"><?= e(rupiah($payment['jumlah_bayar'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$payments): ?>
                <tr><td colspan="6" class="muted">Belum ada pembayaran.</td></tr>
            <?php endif; ?>
        </table>
    </div>
</section>

<div class="actions no-print" style="margin:0 0 20px">
    <button type="button" class="sec" onclick="window.print()">Cetak invoice</button>
    <a href="periksa.php?id=<?= e($invoice['id_kunjungan']) ?>">Rekam medis</a>
    <a href="transaksi.php">Daftar transaksi</a>
</div>

<?php if ($open): ?>
    <section class="no-print">
        <h2>Terima pembayaran</h2>
        <form method="post" class="grid" id="pay">
            <label>
                Jumlah dibayar (sisa <?= e(rupiah($due)) ?>)
                <input name="jumlah" id="jumlah" type="number" min="1" max="<?= e($due) ?>" step="any" value="<?= e($due) ?>" required>
            </label>
            <label>
                Metode
                <select name="metode" id="metode" required>
                    <?php foreach (METODE_BAYAR as $method): ?>
                        <option><?= e($method) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label id="f-diterima">
                Uang diterima (tunai)
                <input name="diterima" id="diterima" type="number" min="0" step="any" placeholder="sama dengan jumlah">
            </label>
            <label>
                No. referensi (kartu / QRIS / klaim)
                <input name="referensi" maxlength="50">
            </label>
            <label>
                Kasir
                <input name="kasir" maxlength="60">
            </label>
            <div class="act actions">
                <button name="bayar" value="1">Catat pembayaran</button>
                <span class="muted" id="kembalian"></span>
            </div>
        </form>
    </section>

    <div class="two no-print">
        <section>
            <h2>Diskon</h2>
            <form method="post" class="filters">
                <label>
                    Nominal diskon (Rp)
                    <input name="nominal" type="number" min="0" step="any" value="<?= e($invoice['diskon']) ?>">
                </label>
                <button name="diskon" value="1" class="sec">Terapkan diskon</button>
            </form>
        </section>

        <section>
            <h2>Biaya tambahan</h2>
            <form method="post" class="grid">
                <label class="full">
                    Nama biaya
                    <input name="deskripsi" placeholder="Administrasi, surat keterangan sehat, ..." required>
                </label>
                <label>
                    Jumlah
                    <input name="qty" type="number" min="1" value="1">
                </label>
                <label>
                    Harga satuan
                    <input name="harga" type="number" min="0" step="any" value="0">
                </label>
                <div class="act">
                    <button name="tambah" value="1" class="sec">Tambahkan biaya</button>
                </div>
            </form>
        </section>
    </div>

    <?php if ((float) $invoice['total_dibayar'] == 0): ?>
        <form method="post" class="no-print" onsubmit="return confirm('Batalkan invoice ini?')">
            <button name="batal_invoice" value="1" class="del">Batalkan invoice</button>
        </form>
    <?php endif; ?>

    <script>
        const method = document.getElementById('metode');
        const received = document.getElementById('diterima');
        const amount = document.getElementById('jumlah');
        const change = document.getElementById('kembalian');
        const rp = n => 'Rp ' + Math.round(n).toLocaleString('id-ID');

        function refresh() {
            const cash = method.value === 'Tunai';
            document.getElementById('f-diterima').hidden = !cash;
            const diff = (+received.value || 0) - (+amount.value || 0);
            change.textContent = cash && received.value !== '' && diff >= 0 ? 'Kembalian ' + rp(diff) : '';
        }
        [method, received, amount].forEach(el => el.addEventListener('input', refresh));
        refresh();
    </script>
<?php endif; ?>

<?php foot(); ?>

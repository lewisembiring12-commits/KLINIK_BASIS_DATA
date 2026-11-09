<?php

require_once __DIR__ . '/config.php';

const KESADARAN = ['Compos Mentis', 'Apatis', 'Somnolen', 'Sopor', 'Koma'];
const KEADAAN_UMUM = ['Baik', 'Sedang', 'Buruk'];
const VONIS = ['Sembuh', 'Rawat Jalan', 'Kontrol Ulang', 'Rujuk', 'Rawat Inap'];
const PROGNOSIS = ['Baik', 'Dubia', 'Buruk'];

// kolom => [label, minimum, maksimum] untuk menolak salah ketik pada tanda vital
const BATAS_VITAL = [
    'td_sistol' => ['Tekanan darah sistolik', 40, 300],
    'td_diastol' => ['Tekanan darah diastolik', 20, 200],
    'nadi' => ['Nadi', 20, 250],
    'laju_napas' => ['Laju napas', 5, 80],
    'suhu' => ['Suhu', 30, 45],
    'spo2' => ['SpO2', 50, 100],
    'berat_badan' => ['Berat badan', 1, 400],
    'tinggi_badan' => ['Tinggi badan', 20, 250],
];

/** Ubah array paralel (name="x[]") menjadi daftar baris; baris dengan kolom pertama kosong dilewati. */
function ambil_baris(array $post, array $keys): array
{
    $result = [];
    foreach ($post[$keys[0]] ?? [] as $i => $first) {
        if (trim((string) $first) === '') {
            continue;
        }

        $line = [];
        foreach ($keys as $key) {
            $line[$key] = trim((string) ($post[$key][$i] ?? ''));
        }
        $result[] = $line;
    }

    return $result;
}

/**
 * Simpan pemeriksaan, diagnosa, tindakan, resep (stok berkurang lewat trigger),
 * tutup kunjungan, dan buat invoice. Semua dalam satu transaksi database.
 * Mengembalikan id_transaksi.
 */
function simpan_pemeriksaan(PDO $pdo, array $visit, array $in): int
{
    $visitId = (int) $visit['id_kunjungan'];

    $text = static function (string $key) use ($in) {
        $value = trim((string) ($in[$key] ?? ''));

        return $value === '' ? null : $value;
    };
    $pick = static fn(string $key, array $allowed, string $default) =>
        in_array($in[$key] ?? '', $allowed, true) ? $in[$key] : $default;

    if ($text('keluhan_utama') === null) {
        throw new RuntimeException('Keluhan utama wajib diisi.');
    }

    $data = ['id_kunjungan' => $visitId];

    foreach ([
        'keluhan_utama', 'riwayat_sekarang', 'riwayat_dahulu', 'riwayat_keluarga',
        'pf_kepala_leher', 'pf_thorax_paru', 'pf_jantung', 'pf_abdomen', 'pf_ekstremitas', 'pf_kulit',
        'status_lokalis', 'diagnosa_kerja', 'rencana_terapi', 'edukasi',
    ] as $field) {
        $data[$field] = $text($field);
    }

    foreach (BATAS_VITAL as $field => [$label, $min, $max]) {
        $value = $text($field);
        if ($value !== null && (!is_numeric($value) || $value < $min || $value > $max)) {
            throw new RuntimeException("$label di luar batas wajar ($min sampai $max).");
        }
        $data[$field] = $value;
    }

    if ($data['td_sistol'] !== null && $data['td_diastol'] !== null && $data['td_sistol'] <= $data['td_diastol']) {
        throw new RuntimeException('Tekanan sistolik harus lebih besar dari diastolik.');
    }

    $data['kesadaran'] = $pick('kesadaran', KESADARAN, 'Compos Mentis');
    $data['keadaan_umum'] = $pick('keadaan_umum', KEADAAN_UMUM, 'Baik');
    $data['prognosis'] = $pick('prognosis', PROGNOSIS, 'Baik');

    $vonis = $pick('vonis', VONIS, 'Rawat Jalan');
    $data['vonis'] = $vonis;

    $rest = $text('istirahat_hari');
    $data['istirahat_hari'] = ($rest !== null && ctype_digit($rest)) ? min((int) $rest, 90) : 0;

    $control = $text('tanggal_kontrol');
    if ($vonis === 'Kontrol Ulang') {
        if ($control === null || !DateTime::createFromFormat('Y-m-d', $control)) {
            throw new RuntimeException('Isi tanggal kontrol yang valid untuk vonis Kontrol Ulang.');
        }
    } else {
        $control = null;
    }
    $data['tanggal_kontrol'] = $control;

    $referral = $text('rujuk_ke');
    if ($vonis === 'Rujuk' && $referral === null) {
        throw new RuntimeException('Isi tujuan rujukan untuk vonis Rujuk.');
    }
    $data['rujuk_ke'] = $vonis === 'Rujuk' ? $referral : null;

    $diagnoses = ambil_baris($in, ['dx_id', 'dx_cat']);
    if (!$diagnoses) {
        throw new RuntimeException('Pilih minimal satu diagnosa.');
    }
    if (count(array_unique(array_column($diagnoses, 'dx_id'))) !== count($diagnoses)) {
        throw new RuntimeException('Ada diagnosa yang dipilih lebih dari sekali.');
    }

    $procedures = ambil_baris($in, ['tdk_id', 'tdk_jml', 'tdk_hasil']);
    $medicines = ambil_baris($in, ['ob_id', 'ob_jml', 'ob_dosis', 'ob_aturan', 'ob_hari']);

    $pdo->beginTransaction();

    try {
        $status = scalar($pdo, 'SELECT status FROM kunjungan WHERE id_kunjungan = ? FOR UPDATE', [$visitId]);
        $already = scalar($pdo, 'SELECT COUNT(*) FROM pemeriksaan WHERE id_kunjungan = ?', [$visitId]);
        if (!in_array($status, ['Menunggu', 'Diperiksa'], true) || $already) {
            throw new RuntimeException('Pemeriksaan kunjungan ini sudah disimpan atau kunjungan dibatalkan.');
        }

        $columns = array_keys($data);
        $pdo->prepare(
            'INSERT INTO pemeriksaan (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')'
        )->execute(array_values($data));
        $examId = (int) $pdo->lastInsertId();

        // Baris tagihan: [jenis, id_ref, deskripsi, qty, harga]
        $lines = [];
        if ((float) $visit['tarif_konsultasi'] > 0) {
            $lines[] = ['Konsultasi', $visit['id_dokter'], 'Konsultasi ' . $visit['nama_dokter'], 1, $visit['tarif_konsultasi']];
        }

        // Diagnosa: yang pertama menjadi diagnosa utama
        foreach ($diagnoses as $index => $diagnosis) {
            $pdo->prepare(
                'INSERT INTO diagnosa (id_kunjungan, id_penyakit, jenis_diagnosa, catatan) VALUES (?, ?, ?, ?)'
            )->execute([
                $visitId,
                $diagnosis['dx_id'],
                $index === 0 ? 'Primer' : 'Sekunder',
                $diagnosis['dx_cat'] !== '' ? $diagnosis['dx_cat'] : null,
            ]);
        }

        // Penunjang & tindakan: tarif diambil dari master, bukan dari isian form
        $seen = [];
        foreach ($procedures as $procedure) {
            $item = row($pdo, 'SELECT * FROM tindakan WHERE id_tindakan = ? AND status_aktif = 1', [$procedure['tdk_id']]);
            if (!$item) {
                throw new RuntimeException('Ada tindakan yang tidak valid atau sudah dinonaktifkan.');
            }
            if (isset($seen[$item['id_tindakan']])) {
                throw new RuntimeException($item['nama_tindakan'] . ' dipilih lebih dari sekali. Gunakan kolom jumlah.');
            }
            $seen[$item['id_tindakan']] = true;

            $qty = max(1, (int) $procedure['tdk_jml']);
            $pdo->prepare(
                'INSERT INTO pemeriksaan_tindakan (id_pemeriksaan, id_tindakan, jumlah, tarif, hasil) VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $examId,
                $item['id_tindakan'],
                $qty,
                $item['tarif'],
                $procedure['tdk_hasil'] !== '' ? $procedure['tdk_hasil'] : null,
            ]);
            $lines[] = ['Tindakan', $item['id_tindakan'], $item['nama_tindakan'], $qty, $item['tarif']];
        }

        // Resep: stok dikunci dulu, lalu trigger database yang menguranginya
        if ($medicines) {
            $pdo->prepare('INSERT INTO resep (id_kunjungan, catatan) VALUES (?, ?)')
                ->execute([$visitId, $data['rencana_terapi']]);
            $prescriptionId = (int) $pdo->lastInsertId();

            $seen = [];
            foreach ($medicines as $medicine) {
                $item = row($pdo, 'SELECT * FROM obat WHERE id_obat = ? FOR UPDATE', [$medicine['ob_id']]);
                if (!$item) {
                    throw new RuntimeException('Ada obat yang tidak ditemukan.');
                }
                if (isset($seen[$item['id_obat']])) {
                    throw new RuntimeException($item['nama_obat'] . ' dipilih lebih dari sekali. Gabungkan dalam satu baris.');
                }
                $seen[$item['id_obat']] = true;

                $qty = (int) $medicine['ob_jml'];
                if ($qty < 1) {
                    throw new RuntimeException('Jumlah ' . $item['nama_obat'] . ' harus lebih dari 0.');
                }
                if ($item['tanggal_kadaluarsa'] && $item['tanggal_kadaluarsa'] < date('Y-m-d')) {
                    throw new RuntimeException($item['nama_obat'] . ' sudah kadaluarsa dan tidak bisa diresepkan.');
                }
                if ((int) $item['stok'] < $qty) {
                    throw new RuntimeException(
                        'Stok ' . $item['nama_obat'] . ' tidak cukup: tersisa ' . $item['stok'] . ' ' . $item['satuan'] . '.'
                    );
                }

                $pdo->prepare(
                    'INSERT INTO detail_resep (id_resep, id_obat, jumlah, dosis, aturan_pakai, lama_hari)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([
                    $prescriptionId,
                    $item['id_obat'],
                    $qty,
                    $medicine['ob_dosis'] !== '' ? $medicine['ob_dosis'] : null,
                    $medicine['ob_aturan'] !== '' ? $medicine['ob_aturan'] : null,
                    ctype_digit($medicine['ob_hari']) && $medicine['ob_hari'] !== '' ? $medicine['ob_hari'] : null,
                ]);
                $lines[] = ['Obat', $item['id_obat'], $item['nama_obat'], $qty, $item['harga_satuan']];
            }
        }

        // Tutup kunjungan; ringkasan tanda vital ikut disalin agar halaman lama tetap konsisten
        $bloodPressure = ($data['td_sistol'] !== null && $data['td_diastol'] !== null)
            ? $data['td_sistol'] . '/' . $data['td_diastol']
            : null;
        $pdo->prepare(
            "UPDATE kunjungan SET status = 'Selesai', catatan_dokter = ?,
                    tekanan_darah = COALESCE(?, tekanan_darah), suhu_badan = COALESCE(?, suhu_badan),
                    berat_badan = COALESCE(?, berat_badan), tinggi_badan = COALESCE(?, tinggi_badan)
             WHERE id_kunjungan = ?"
        )->execute([
            $text('catatan_dokter'),
            $bloodPressure,
            $data['suhu'],
            $data['berat_badan'],
            $data['tinggi_badan'],
            $visitId,
        ]);

        // Invoice
        $invoiceNo = 'INV-' . date('Ymd') . '-' . str_pad((string) $visitId, 4, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO transaksi (no_invoice, id_kunjungan) VALUES (?, ?)')->execute([$invoiceNo, $visitId]);
        $invoiceId = (int) $pdo->lastInsertId();

        foreach ($lines as [$type, $ref, $description, $qty, $price]) {
            $pdo->prepare(
                'INSERT INTO transaksi_detail (id_transaksi, jenis, id_ref, deskripsi, qty, harga_satuan)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$invoiceId, $type, $ref, $description, $qty, $price]);
        }
        hitung_transaksi($pdo, $invoiceId);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return $invoiceId;
}

/* ---------------------------------------------------------------------
   Muat data kunjungan
   --------------------------------------------------------------------- */

$id = (int) ($_GET['id'] ?? 0);

$visit = row(
    $pdo,
    'SELECT k.*, p.nama_pasien, p.no_rekam_medis, p.tanggal_lahir, p.jenis_kelamin, p.golongan_darah, p.alergi,
            d.nama_dokter, d.tarif_konsultasi, s.nama_spesialisasi
     FROM kunjungan k
     JOIN pasien p       ON p.id_pasien = k.id_pasien
     JOIN dokter d       ON d.id_dokter = k.id_dokter
     JOIN spesialisasi s ON s.id_spesialisasi = d.id_spesialisasi
     WHERE k.id_kunjungan = ?',
    [$id]
);

if (!$visit) {
    head('Pemeriksaan dokter', 'pemeriksaan.php');
    echo '<p class="msg err">Kunjungan tidak ditemukan. <a href="pemeriksaan.php">Kembali ke daftar pemeriksaan</a></p>';
    foot();
    exit;
}

$exam = row($pdo, 'SELECT * FROM pemeriksaan WHERE id_kunjungan = ?', [$id]);
$invoice = row($pdo, 'SELECT * FROM transaksi WHERE id_kunjungan = ?', [$id]);
$locked = $exam !== null || in_array($visit['status'], ['Selesai', 'Batal'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $locked) {
    redirect('periksa.php?id=' . $id, 'Pemeriksaan ini sudah dikunci dan tidak bisa disimpan ulang.', 'err');
}

$old = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $invoiceId = simpan_pemeriksaan($pdo, $visit, $_POST);
        redirect('invoice.php?id=' . $invoiceId, 'Pemeriksaan tersimpan dan tagihan sudah dibuat. Lanjutkan ke pembayaran.');
    } catch (RuntimeException $exception) {
        flash($exception->getMessage(), 'err');
    } catch (PDOException $exception) {
        flash('Gagal menyimpan pemeriksaan: ' . $exception->getMessage(), 'err');
    }
    $old = $_POST;
}

if (!$locked && $visit['status'] === 'Menunggu') {
    $pdo->prepare("UPDATE kunjungan SET status = 'Diperiksa' WHERE id_kunjungan = ? AND status = 'Menunggu'")->execute([$id]);
    $visit['status'] = 'Diperiksa';
}

$history = rows(
    $pdo,
    'SELECT id_kunjungan, tanggal_kunjungan, nama_dokter, diagnosa
     FROM v_riwayat_pasien
     WHERE id_pasien = ? AND id_kunjungan <> ?
     ORDER BY tanggal_kunjungan DESC LIMIT 3',
    [$visit['id_pasien'], $id]
);

head($locked ? 'Rekam medis kunjungan' : 'Pemeriksaan dokter', 'pemeriksaan.php');
?>

<section class="banner">
    <div>
        <h2><?= e($visit['nama_pasien']) ?></h2>
        <p>
            <?= e($visit['no_rekam_medis']) ?>,
            <?= $visit['jenis_kelamin'] === 'L' ? 'laki-laki' : 'perempuan' ?>,
            <?= e(umur($visit['tanggal_lahir'])) ?>,
            gol. darah <?= e($visit['golongan_darah']) ?>
        </p>
    </div>
    <div class="side">
        <?= badge($visit['status']) ?>
        <p>
            Antrian <?= e($visit['no_antrian'] ?: '-') ?>, <?= e(tgl($visit['tanggal_kunjungan'], true)) ?><br>
            <?= e($visit['nama_dokter']) ?> (<?= e($visit['nama_spesialisasi']) ?>)
        </p>
    </div>
    <?php if ($visit['alergi']): ?>
        <div class="alert">Alergi: <?= e($visit['alergi']) ?></div>
    <?php endif; ?>
    <?php if ($history): ?>
        <details class="hist">
            <summary>Riwayat kunjungan sebelumnya (<?= count($history) ?> terakhir)</summary>
            <ul>
                <?php foreach ($history as $past): ?>
                    <li>
                        <a href="periksa.php?id=<?= e($past['id_kunjungan']) ?>"><?= e(tgl($past['tanggal_kunjungan'])) ?></a>,
                        <?= e($past['nama_dokter']) ?>:
                        <?= e($past['diagnosa'] ?: 'belum ada diagnosa') ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
</section>

<?php if ($locked): ?>
    <?php
    $kv = static fn(string $label, $value, bool $wide = false) =>
        '<div' . ($wide ? ' class="wide"' : '') . '><dt>' . e($label) . '</dt><dd>'
        . (trim((string) $value) === '' ? '<span class="muted">-</span>' : nl2br(e($value)))
        . '</dd></div>';

    $diagnoses = rows(
        $pdo,
        'SELECT dg.jenis_diagnosa, dg.catatan, pn.kode_icd10, pn.nama_penyakit
         FROM diagnosa dg JOIN penyakit pn ON pn.id_penyakit = dg.id_penyakit
         WHERE dg.id_kunjungan = ? ORDER BY dg.jenis_diagnosa, dg.id_diagnosa',
        [$id]
    );
    $prescription = rows(
        $pdo,
        'SELECT dr.jumlah, dr.dosis, dr.aturan_pakai, dr.lama_hari, o.nama_obat, o.satuan
         FROM resep r
         JOIN detail_resep dr ON dr.id_resep = r.id_resep
         JOIN obat o ON o.id_obat = dr.id_obat
         WHERE r.id_kunjungan = ? ORDER BY dr.id_detail',
        [$id]
    );
    $procedures = $exam ? rows(
        $pdo,
        'SELECT pt.jumlah, pt.tarif, pt.hasil, t.kode_tindakan, t.nama_tindakan, t.kategori
         FROM pemeriksaan_tindakan pt JOIN tindakan t ON t.id_tindakan = pt.id_tindakan
         WHERE pt.id_pemeriksaan = ? ORDER BY pt.id_pt',
        [$exam['id_pemeriksaan']]
    ) : [];
    ?>

    <div class="actions no-print" style="margin:0 0 16px">
        <?php if ($invoice): ?>
            <a class="btn" href="invoice.php?id=<?= e($invoice['id_transaksi']) ?>">Lihat invoice</a>
            <?= badge($invoice['status_bayar']) ?>
        <?php endif; ?>
        <button type="button" class="sec" onclick="window.print()">Cetak resume</button>
        <a href="pemeriksaan.php">Kembali ke daftar pemeriksaan</a>
    </div>

    <?php if ($visit['status'] === 'Batal'): ?>
        <p class="msg">Kunjungan ini dibatalkan sebelum diperiksa.</p>
    <?php elseif (!$exam): ?>
        <p class="msg">Kunjungan ini dicatat sebelum formulir pemeriksaan tersedia, jadi hanya data ringkas yang ada.</p>
        <section>
            <dl class="kv">
                <?= $kv('Keluhan', $visit['keluhan'], true) ?>
                <?= $kv('Tekanan darah', $visit['tekanan_darah']) ?>
                <?= $kv('Suhu', $visit['suhu_badan'] !== null ? $visit['suhu_badan'] . ' °C' : '') ?>
                <?= $kv('Catatan dokter', $visit['catatan_dokter'], true) ?>
            </dl>
        </section>
    <?php else: ?>
        <section>
            <h2>Anamnesis</h2>
            <dl class="kv">
                <?= $kv('Keluhan utama', $exam['keluhan_utama'], true) ?>
                <?= $kv('Riwayat penyakit sekarang', $exam['riwayat_sekarang']) ?>
                <?= $kv('Riwayat penyakit dahulu', $exam['riwayat_dahulu']) ?>
                <?= $kv('Riwayat penyakit keluarga', $exam['riwayat_keluarga']) ?>
            </dl>
        </section>

        <section>
            <h2>Pemeriksaan fisik</h2>
            <dl class="kv">
                <?= $kv('Tekanan darah', $exam['td_sistol'] !== null ? $exam['td_sistol'] . '/' . $exam['td_diastol'] . ' mmHg' : '') ?>
                <?= $kv('Nadi', $exam['nadi'] !== null ? $exam['nadi'] . ' x/menit' : '') ?>
                <?= $kv('Laju napas', $exam['laju_napas'] !== null ? $exam['laju_napas'] . ' x/menit' : '') ?>
                <?= $kv('Suhu', $exam['suhu'] !== null ? $exam['suhu'] . ' °C' : '') ?>
                <?= $kv('SpO2', $exam['spo2'] !== null ? $exam['spo2'] . '%' : '') ?>
                <?= $kv('Berat / tinggi', $exam['berat_badan'] !== null ? $exam['berat_badan'] . ' kg / ' . $exam['tinggi_badan'] . ' cm' : '') ?>
                <?= $kv('BMI', $exam['bmi']) ?>
                <?= $kv('Kesadaran', $exam['kesadaran']) ?>
                <?= $kv('Keadaan umum', $exam['keadaan_umum']) ?>
                <?= $kv('Kepala dan leher', $exam['pf_kepala_leher']) ?>
                <?= $kv('Thorax dan paru', $exam['pf_thorax_paru']) ?>
                <?= $kv('Jantung', $exam['pf_jantung']) ?>
                <?= $kv('Abdomen', $exam['pf_abdomen']) ?>
                <?= $kv('Ekstremitas', $exam['pf_ekstremitas']) ?>
                <?= $kv('Kulit', $exam['pf_kulit']) ?>
                <?= $kv('Status lokalis', $exam['status_lokalis'], true) ?>
            </dl>
        </section>

        <?php if ($procedures): ?>
            <section>
                <h2>Penunjang dan tindakan</h2>
                <div class="scroll">
                    <table>
                        <tr><th>Kode</th><th>Pemeriksaan / tindakan</th><th>Kategori</th><th class="n">Jumlah</th><th>Hasil</th><th class="n">Tarif</th></tr>
                        <?php foreach ($procedures as $item): ?>
                            <tr>
                                <td><?= e($item['kode_tindakan']) ?></td>
                                <td><?= e($item['nama_tindakan']) ?></td>
                                <td><?= e($item['kategori']) ?></td>
                                <td class="n"><?= e($item['jumlah']) ?></td>
                                <td><?= e($item['hasil'] ?: '-') ?></td>
                                <td class="n"><?= e(rupiah($item['tarif'] * $item['jumlah'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($visit['status'] !== 'Batal'): ?>
        <section>
            <h2>Diagnosa</h2>
            <div class="scroll">
                <table>
                    <tr><th>ICD-10</th><th>Penyakit</th><th>Jenis</th><th>Catatan</th></tr>
                    <?php foreach ($diagnoses as $item): ?>
                        <tr>
                            <td><?= e($item['kode_icd10']) ?></td>
                            <td><?= e($item['nama_penyakit']) ?></td>
                            <td><?= e($item['jenis_diagnosa']) ?></td>
                            <td><?= e($item['catatan'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$diagnoses): ?>
                        <tr><td colspan="4" class="muted">Belum ada diagnosa tercatat.</td></tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php if ($exam): ?>
                <dl class="kv" style="margin-top:12px">
                    <?= $kv('Assessment / diagnosa banding', $exam['diagnosa_kerja'], true) ?>
                </dl>
            <?php endif; ?>
        </section>

        <section>
            <h2>Terapi dan resep</h2>
            <?php if ($exam): ?>
                <dl class="kv" style="margin-bottom:12px">
                    <?= $kv('Terapi non-obat', $exam['rencana_terapi']) ?>
                    <?= $kv('Edukasi pasien', $exam['edukasi']) ?>
                </dl>
            <?php endif; ?>
            <div class="scroll">
                <table>
                    <tr><th>Obat</th><th class="n">Jumlah</th><th>Dosis</th><th>Aturan pakai</th><th class="n">Lama (hari)</th></tr>
                    <?php foreach ($prescription as $item): ?>
                        <tr>
                            <td><?= e($item['nama_obat']) ?></td>
                            <td class="n"><?= e($item['jumlah'] . ' ' . $item['satuan']) ?></td>
                            <td><?= e($item['dosis'] ?: '-') ?></td>
                            <td><?= e($item['aturan_pakai'] ?: '-') ?></td>
                            <td class="n"><?= e($item['lama_hari'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$prescription): ?>
                        <tr><td colspan="5" class="muted">Tidak ada obat diresepkan.</td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </section>

        <section>
            <h2>Vonis dokter</h2>
            <dl class="kv">
                <?php if ($exam): ?>
                    <div><dt>Vonis</dt><dd><b><?= e($exam['vonis']) ?></b></dd></div>
                    <?= $kv('Prognosis', $exam['prognosis']) ?>
                    <?= $kv('Surat istirahat', $exam['istirahat_hari'] ? $exam['istirahat_hari'] . ' hari' : 'Tidak ada') ?>
                    <?= $kv('Tanggal kontrol', $exam['tanggal_kontrol'] ? tgl($exam['tanggal_kontrol']) : '') ?>
                    <?= $kv('Dirujuk ke', $exam['rujuk_ke']) ?>
                <?php endif; ?>
                <?= $kv('Catatan dokter', $visit['catatan_dokter'], true) ?>
            </dl>
        </section>
    <?php endif; ?>

<?php else: ?>
    <?php
    $val = static fn(string $key, $default = '') => $old[$key] ?? $default;

    [$bpSys, $bpDia] = array_pad(array_map('trim', explode('/', (string) $visit['tekanan_darah'])), 2, '');
    $bpSys = ctype_digit($bpSys) ? $bpSys : '';
    $bpDia = ctype_digit($bpDia) ? $bpDia : '';

    $procedureList = rows($pdo, 'SELECT * FROM tindakan WHERE status_aktif = 1 ORDER BY kategori, nama_tindakan');
    $diseaseList = rows($pdo, "SELECT id_penyakit, CONCAT(kode_icd10, ' - ', nama_penyakit) AS nama FROM penyakit ORDER BY nama_penyakit");
    $medicineList = rows($pdo, 'SELECT * FROM obat ORDER BY nama_obat');

    $procedureOptions = static function ($selected = '') use ($procedureList) {
        $html = '<option value="">- pilih -</option>';
        $group = null;
        foreach ($procedureList as $item) {
            if ($item['kategori'] !== $group) {
                $html .= ($group !== null ? '</optgroup>' : '') . '<optgroup label="' . e($item['kategori']) . '">';
                $group = $item['kategori'];
            }
            $html .= '<option value="' . e($item['id_tindakan']) . '" data-harga="' . e($item['tarif']) . '"'
                . ((string) $selected === (string) $item['id_tindakan'] ? ' selected' : '') . '>'
                . e($item['nama_tindakan'] . ' - ' . rupiah($item['tarif'])) . '</option>';
        }

        return $html . ($group !== null ? '</optgroup>' : '');
    };

    $medicineOptions = static function ($selected = '') use ($medicineList) {
        $html = '<option value="">- pilih obat -</option>';
        foreach ($medicineList as $item) {
            $expired = $item['tanggal_kadaluarsa'] && $item['tanggal_kadaluarsa'] < date('Y-m-d');
            $note = $expired ? ' (kadaluarsa)' : ((int) $item['stok'] <= 0 ? ' (habis)' : '');
            $html .= '<option value="' . e($item['id_obat']) . '" data-harga="' . e($item['harga_satuan']) . '"'
                . (($expired || (int) $item['stok'] <= 0) ? ' disabled' : '')
                . ((string) $selected === (string) $item['id_obat'] ? ' selected' : '') . '>'
                . e($item['nama_obat'] . ' - stok ' . $item['stok'] . ' ' . $item['satuan'] . ' - ' . rupiah($item['harga_satuan']) . $note)
                . '</option>';
        }

        return $html;
    };

    $procedureRow = static fn($id = '', $qty = 1, $result = '') =>
        '<div class="line" style="--cols: 3fr 90px 3fr auto">'
        . '<label>Pemeriksaan / tindakan<select name="tdk_id[]">' . $procedureOptions($id) . '</select></label>'
        . '<label>Jumlah<input name="tdk_jml[]" type="number" min="1" value="' . e($qty) . '"></label>'
        . '<label>Hasil / keterangan<input name="tdk_hasil[]" value="' . e($result) . '"></label>'
        . '<button type="button" class="rm">Hapus</button></div>';

    $diagnosisRow = static fn($id = '', $note = '') =>
        '<div class="line" style="--cols: 3fr 3fr auto">'
        . '<label>Diagnosa<select name="dx_id[]"><option value="">- pilih diagnosa -</option>'
        . opts($diseaseList, 'id_penyakit', 'nama', $id) . '</select></label>'
        . '<label>Catatan<input name="dx_cat[]" value="' . e($note) . '"></label>'
        . '<button type="button" class="rm">Hapus</button></div>';

    $medicineRow = static fn($id = '', $qty = '', $dose = '', $rule = '', $days = '') =>
        '<div class="line" style="--cols: 3fr 90px 1fr 2fr 90px auto">'
        . '<label>Obat<select name="ob_id[]">' . $medicineOptions($id) . '</select></label>'
        . '<label>Jumlah<input name="ob_jml[]" type="number" min="1" value="' . e($qty) . '"></label>'
        . '<label>Dosis<input name="ob_dosis[]" placeholder="500 mg" value="' . e($dose) . '"></label>'
        . '<label>Aturan pakai<input name="ob_aturan[]" placeholder="3 x 1 sesudah makan" value="' . e($rule) . '"></label>'
        . '<label>Hari<input name="ob_hari[]" type="number" min="1" value="' . e($days) . '"></label>'
        . '<button type="button" class="rm">Hapus</button></div>';

    $oldProcedures = ambil_baris($old, ['tdk_id', 'tdk_jml', 'tdk_hasil']);
    $oldDiagnoses = ambil_baris($old, ['dx_id', 'dx_cat']);
    $oldMedicines = ambil_baris($old, ['ob_id', 'ob_jml', 'ob_dosis', 'ob_aturan', 'ob_hari']);
    ?>

    <form method="post" id="fp" data-konsul="<?= e($visit['tarif_konsultasi']) ?>">

        <section>
            <div class="sec-title"><span class="no">1</span><h2>Anamnesis</h2></div>
            <div class="grid">
                <label class="full">
                    Keluhan utama
                    <textarea name="keluhan_utama" rows="2" required><?= e($val('keluhan_utama', $visit['keluhan'])) ?></textarea>
                </label>
                <label>
                    Riwayat penyakit sekarang
                    <textarea name="riwayat_sekarang" rows="3" placeholder="Sejak kapan, perjalanan keluhan, gejala penyerta"><?= e($val('riwayat_sekarang')) ?></textarea>
                </label>
                <label>
                    Riwayat penyakit dahulu
                    <textarea name="riwayat_dahulu" rows="3" placeholder="Penyakit kronis, operasi, rawat inap"><?= e($val('riwayat_dahulu')) ?></textarea>
                </label>
                <label>
                    Riwayat penyakit keluarga
                    <textarea name="riwayat_keluarga" rows="3"><?= e($val('riwayat_keluarga')) ?></textarea>
                </label>
            </div>
        </section>

        <section>
            <div class="sec-title"><span class="no">2</span><h2>Pemeriksaan fisik</h2></div>
            <div class="grid vitals">
                <label>Sistolik (mmHg)<input name="td_sistol" type="number" min="0" value="<?= e($val('td_sistol', $bpSys)) ?>"></label>
                <label>Diastolik (mmHg)<input name="td_diastol" type="number" min="0" value="<?= e($val('td_diastol', $bpDia)) ?>"></label>
                <label>Nadi (x/menit)<input name="nadi" type="number" min="0" value="<?= e($val('nadi')) ?>"></label>
                <label>Napas (x/menit)<input name="laju_napas" type="number" min="0" value="<?= e($val('laju_napas')) ?>"></label>
                <label>Suhu (°C)<input name="suhu" type="number" step="0.1" min="0" value="<?= e($val('suhu', $visit['suhu_badan'])) ?>"></label>
                <label>SpO2 (%)<input name="spo2" type="number" min="0" max="100" value="<?= e($val('spo2')) ?>"></label>
                <label>Berat (kg)<input name="berat_badan" type="number" step="0.1" min="0" value="<?= e($val('berat_badan', $visit['berat_badan'])) ?>"></label>
                <label>Tinggi (cm)<input name="tinggi_badan" type="number" step="0.1" min="0" value="<?= e($val('tinggi_badan', $visit['tinggi_badan'])) ?>"></label>
                <label>BMI<input id="bmi" readonly tabindex="-1" placeholder="otomatis"></label>
            </div>
            <div class="grid" style="margin-bottom:14px">
                <label>
                    Kesadaran
                    <select name="kesadaran">
                        <?php foreach (KESADARAN as $item): ?>
                            <option <?= $val('kesadaran', 'Compos Mentis') === $item ? 'selected' : '' ?>><?= e($item) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Keadaan umum
                    <select name="keadaan_umum">
                        <?php foreach (KEADAAN_UMUM as $item): ?>
                            <option <?= $val('keadaan_umum', 'Baik') === $item ? 'selected' : '' ?>><?= e($item) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <p class="hint">
                Tulis temuan per sistem.
                <button type="button" class="sec sm" id="fill-normal">Isi temuan normal pada kolom kosong</button>
            </p>
            <div class="grid">
                <label>Kepala dan leher<textarea name="pf_kepala_leher" rows="2"><?= e($val('pf_kepala_leher')) ?></textarea></label>
                <label>Thorax dan paru<textarea name="pf_thorax_paru" rows="2"><?= e($val('pf_thorax_paru')) ?></textarea></label>
                <label>Jantung<textarea name="pf_jantung" rows="2"><?= e($val('pf_jantung')) ?></textarea></label>
                <label>Abdomen<textarea name="pf_abdomen" rows="2"><?= e($val('pf_abdomen')) ?></textarea></label>
                <label>Ekstremitas<textarea name="pf_ekstremitas" rows="2"><?= e($val('pf_ekstremitas')) ?></textarea></label>
                <label>Kulit<textarea name="pf_kulit" rows="2"><?= e($val('pf_kulit')) ?></textarea></label>
                <label class="full">Status lokalis (bila ada kelainan setempat)<textarea name="status_lokalis" rows="2"><?= e($val('status_lokalis')) ?></textarea></label>
            </div>
        </section>

        <section>
            <div class="sec-title"><span class="no">3</span><h2>Pemeriksaan penunjang dan tindakan</h2></div>
            <p class="hint">Laboratorium, radiologi, EKG, atau tindakan medis. Tarif otomatis masuk ke tagihan.</p>
            <div id="rows-tdk">
                <?php foreach ($oldProcedures as $line): ?>
                    <?= $procedureRow($line['tdk_id'], $line['tdk_jml'], $line['tdk_hasil']) ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="sec sm" id="add-tdk">Tambah pemeriksaan / tindakan</button>
        </section>

        <section>
            <div class="sec-title"><span class="no">4</span><h2>Diagnosa</h2></div>
            <p class="hint">Diagnosa pertama menjadi diagnosa utama, sisanya sekunder.</p>
            <div id="rows-dx">
                <?php foreach ($oldDiagnoses ?: [['dx_id' => '', 'dx_cat' => '']] as $line): ?>
                    <?= $diagnosisRow($line['dx_id'], $line['dx_cat']) ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="sec sm" id="add-dx">Tambah diagnosa</button>
            <label style="margin-top:14px">
                Assessment / diagnosa banding
                <textarea name="diagnosa_kerja" rows="2"><?= e($val('diagnosa_kerja')) ?></textarea>
            </label>
        </section>

        <section>
            <div class="sec-title"><span class="no">5</span><h2>Terapi dan resep</h2></div>
            <?php if ($visit['alergi']): ?>
                <p class="alert" style="margin:0 0 12px">Periksa kembali resep terhadap alergi pasien: <?= e($visit['alergi']) ?></p>
            <?php endif; ?>
            <div class="grid" style="margin-bottom:14px">
                <label>Terapi non-obat<textarea name="rencana_terapi" rows="2"><?= e($val('rencana_terapi')) ?></textarea></label>
                <label>Edukasi pasien<textarea name="edukasi" rows="2"><?= e($val('edukasi')) ?></textarea></label>
            </div>
            <div id="rows-obat">
                <?php foreach ($oldMedicines as $line): ?>
                    <?= $medicineRow($line['ob_id'], $line['ob_jml'], $line['ob_dosis'], $line['ob_aturan'], $line['ob_hari']) ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="sec sm" id="add-obat">Tambah obat</button>
        </section>

        <section>
            <div class="sec-title"><span class="no">6</span><h2>Vonis dan tindak lanjut</h2></div>
            <div class="grid">
                <label>
                    Vonis dokter
                    <select name="vonis" id="vonis">
                        <?php foreach (VONIS as $item): ?>
                            <option <?= $val('vonis', 'Rawat Jalan') === $item ? 'selected' : '' ?>><?= e($item) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Prognosis
                    <select name="prognosis">
                        <?php foreach (PROGNOSIS as $item): ?>
                            <option <?= $val('prognosis', 'Baik') === $item ? 'selected' : '' ?>><?= e($item) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Surat istirahat (hari)
                    <input name="istirahat_hari" type="number" min="0" max="90" value="<?= e($val('istirahat_hari', 0)) ?>">
                </label>
                <label id="f-kontrol" hidden>
                    Tanggal kontrol
                    <input name="tanggal_kontrol" type="date" min="<?= e(date('Y-m-d')) ?>" value="<?= e($val('tanggal_kontrol')) ?>">
                </label>
                <label id="f-rujuk" hidden>
                    Dirujuk ke
                    <input name="rujuk_ke" placeholder="Nama rumah sakit / poli" value="<?= e($val('rujuk_ke')) ?>">
                </label>
                <label class="full">
                    Catatan dokter
                    <textarea name="catatan_dokter" rows="2"><?= e($val('catatan_dokter')) ?></textarea>
                </label>
            </div>
        </section>

        <section>
            <h2>Perkiraan tagihan</h2>
            <div class="cost">
                <div><span>Konsultasi <?= e($visit['nama_dokter']) ?></span><span id="c-kons">-</span></div>
                <div><span>Penunjang dan tindakan</span><span id="c-tdk">-</span></div>
                <div><span>Obat</span><span id="c-obt">-</span></div>
                <div class="total"><span>Total</span><span id="c-tot">-</span></div>
            </div>
            <div class="actions">
                <button type="submit">Simpan pemeriksaan dan buat tagihan</button>
                <a href="pemeriksaan.php">Kembali tanpa menyimpan</a>
            </div>
        </section>
    </form>

    <template id="t-tdk"><?= $procedureRow() ?></template>
    <template id="t-dx"><?= $diagnosisRow() ?></template>
    <template id="t-obat"><?= $medicineRow() ?></template>

    <script>
        (function () {
            const form = document.getElementById('fp');
            const rp = n => 'Rp ' + Math.round(n).toLocaleString('id-ID');
            const NORMAL = {
                pf_kepala_leher: 'Konjungtiva tidak anemis, sklera tidak ikterik, faring tidak hiperemis, tidak ada pembesaran KGB.',
                pf_thorax_paru: 'Simetris, vesikuler +/+, ronkhi -/-, wheezing -/-.',
                pf_jantung: 'BJ I-II reguler, murmur (-), gallop (-).',
                pf_abdomen: 'Supel, bising usus normal, nyeri tekan (-), hepar dan lien tidak teraba.',
                pf_ekstremitas: 'Akral hangat, CRT < 2 detik, edema (-).',
                pf_kulit: 'Turgor baik, tidak ada ruam.'
            };

            function addRow(buttonId, boxId, templateId) {
                document.getElementById(buttonId).addEventListener('click', () => {
                    document.getElementById(boxId)
                        .appendChild(document.getElementById(templateId).content.cloneNode(true));
                });
            }
            addRow('add-tdk', 'rows-tdk', 't-tdk');
            addRow('add-dx', 'rows-dx', 't-dx');
            addRow('add-obat', 'rows-obat', 't-obat');

            // Pastikan selalu ada satu baris diagnosa
            if (!document.querySelector('#rows-dx .line')) {
                document.getElementById('add-dx').click();
            }

            form.addEventListener('click', ev => {
                if (!ev.target.classList.contains('rm')) return;
                const line = ev.target.closest('.line');
                const box = line.parentElement;
                if (box.id === 'rows-dx' && box.children.length === 1) {
                    line.querySelectorAll('select, input').forEach(f => { f.value = ''; });
                } else {
                    line.remove();
                }
                recalc();
            });

            function recalc() {
                let tdk = 0, obt = 0;
                document.querySelectorAll('#rows-tdk .line').forEach(line => {
                    const o = line.querySelector('select').selectedOptions[0];
                    const qty = Math.max(1, +line.querySelector('[name="tdk_jml[]"]').value || 1);
                    if (o && o.value) tdk += (+o.dataset.harga || 0) * qty;
                });
                document.querySelectorAll('#rows-obat .line').forEach(line => {
                    const o = line.querySelector('select').selectedOptions[0];
                    const qty = +line.querySelector('[name="ob_jml[]"]').value || 0;
                    if (o && o.value) obt += (+o.dataset.harga || 0) * qty;
                });
                const kons = +form.dataset.konsul || 0;
                document.getElementById('c-kons').textContent = rp(kons);
                document.getElementById('c-tdk').textContent = rp(tdk);
                document.getElementById('c-obt').textContent = rp(obt);
                document.getElementById('c-tot').textContent = rp(kons + tdk + obt);

                const bb = +form.elements.berat_badan.value, tb = +form.elements.tinggi_badan.value;
                document.getElementById('bmi').value = bb > 0 && tb > 0 ? (bb / Math.pow(tb / 100, 2)).toFixed(1) : '';
            }
            form.addEventListener('input', recalc);
            form.addEventListener('change', recalc);

            const vonis = document.getElementById('vonis');
            function toggleVonis() {
                document.getElementById('f-kontrol').hidden = vonis.value !== 'Kontrol Ulang';
                document.getElementById('f-rujuk').hidden = vonis.value !== 'Rujuk';
            }
            vonis.addEventListener('change', toggleVonis);

            document.getElementById('fill-normal').addEventListener('click', () => {
                Object.entries(NORMAL).forEach(([name, text]) => {
                    const field = form.elements[name];
                    if (!field.value.trim()) field.value = text;
                });
            });

            form.addEventListener('submit', ev => {
                if (!confirm('Simpan pemeriksaan? Data akan dikunci, stok obat dikurangi, dan tagihan dibuat.')) {
                    ev.preventDefault();
                    return;
                }
                form.querySelector('[type=submit]').disabled = true;
            });

            toggleVonis();
            recalc();
        })();
    </script>
<?php endif; ?>

<?php foot(); ?>

<?php

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['batal'])) {
            $statement = $pdo->prepare(
                "UPDATE kunjungan SET status = 'Batal' WHERE id_kunjungan = ? AND status = 'Menunggu'"
            );
            $statement->execute([$_POST['batal']]);

            redirect(
                'kunjungan.php',
                $statement->rowCount()
                    ? 'Pendaftaran dibatalkan.'
                    : 'Hanya pendaftaran berstatus Menunggu yang bisa dibatalkan.',
                $statement->rowCount() ? 'ok' : 'err'
            );
        }

        $patient = (int) ($_POST['pasien'] ?? 0);
        $doctor = (int) ($_POST['dokter'] ?? 0);
        $complaint = trim($_POST['keluhan'] ?? '');

        if (!$patient || !$doctor || $complaint === '') {
            throw new RuntimeException('Pasien, dokter, dan keluhan wajib diisi.');
        }

        $active = scalar(
            $pdo,
            "SELECT COUNT(*) FROM kunjungan
             WHERE id_pasien = ? AND id_dokter = ? AND DATE(tanggal_kunjungan) = CURDATE()
               AND status IN ('Menunggu', 'Diperiksa')",
            [$patient, $doctor]
        );
        if ($active) {
            throw new RuntimeException('Pasien ini sudah terdaftar hari ini pada dokter yang sama dan belum selesai diperiksa.');
        }

        $queue = (int) scalar(
            $pdo,
            'SELECT COALESCE(MAX(no_antrian), 0) + 1 FROM kunjungan
             WHERE id_dokter = ? AND DATE(tanggal_kunjungan) = CURDATE()',
            [$doctor]
        );

        $number = static function (string $key) {
            $value = trim($_POST[$key] ?? '');

            return ($value !== '' && is_numeric($value) && $value > 0) ? $value : null;
        };
        $bloodPressure = trim($_POST['td'] ?? '');

        $pdo->prepare(
            "INSERT INTO kunjungan
                (id_pasien, id_dokter, no_antrian, tanggal_kunjungan, keluhan,
                 tekanan_darah, suhu_badan, berat_badan, tinggi_badan, status)
             VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, 'Menunggu')"
        )->execute([
            $patient,
            $doctor,
            $queue,
            $complaint,
            $bloodPressure !== '' ? $bloodPressure : null,
            $number('suhu'),
            $number('bb'),
            $number('tb'),
        ]);

        redirect('kunjungan.php', "Pasien didaftarkan. Nomor antrian: $queue.");
    } catch (RuntimeException $exception) {
        flash($exception->getMessage(), 'err');
    } catch (PDOException $exception) {
        flash('Gagal menyimpan: ' . $exception->getMessage(), 'err');
    }
}

$dayName = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'][date('N') - 1];

$patients = rows(
    $pdo,
    "SELECT id_pasien, alergi,
            CONCAT(no_rekam_medis, ' - ', nama_pasien) AS nama
     FROM pasien ORDER BY nama_pasien"
);

$doctors = rows(
    $pdo,
    "SELECT d.id_dokter,
            CONCAT(d.nama_dokter, ' (', s.nama_spesialisasi, ')',
                   IF(EXISTS(SELECT 1 FROM jadwal_dokter j
                             WHERE j.id_dokter = d.id_dokter AND j.hari = ?),
                      ' - praktik hari ini', '')) AS nama
     FROM dokter d
     JOIN spesialisasi s USING (id_spesialisasi)
     WHERE d.status_aktif = 1
     ORDER BY d.nama_dokter",
    [$dayName]
);

$today = rows(
    $pdo,
    'SELECT k.id_kunjungan, k.id_pasien, k.no_antrian, k.tanggal_kunjungan, k.keluhan, k.status,
            p.nama_pasien, p.no_rekam_medis, d.nama_dokter
     FROM kunjungan k
     JOIN pasien p ON p.id_pasien = k.id_pasien
     JOIN dokter d ON d.id_dokter = k.id_dokter
     WHERE DATE(k.tanggal_kunjungan) = CURDATE()
     ORDER BY k.tanggal_kunjungan DESC, k.id_kunjungan DESC'
);

head('Pendaftaran kunjungan', 'kunjungan.php');
?>

<section>
    <h2>Daftarkan pasien</h2>
    <form method="post" class="grid">
        <label>
            Pasien
            <select name="pasien" id="pasien" required>
                <option value="">- pilih pasien -</option>
                <?php foreach ($patients as $patient): ?>
                    <option value="<?= e($patient['id_pasien']) ?>" data-alergi="<?= e($patient['alergi']) ?>">
                        <?= e($patient['nama']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Dokter
            <select name="dokter" required>
                <option value="">- pilih dokter -</option>
                <?= opts($doctors, 'id_dokter', 'nama') ?>
            </select>
        </label>
        <p class="alert" id="alergi-hint" hidden></p>
        <label class="full">
            Keluhan
            <textarea name="keluhan" rows="2" required></textarea>
        </label>
        <label>
            Tekanan darah
            <input name="td" placeholder="120/80">
        </label>
        <label>
            Suhu badan (°C)
            <input name="suhu" type="number" step="0.1" min="30" max="45">
        </label>
        <label>
            Berat badan (kg)
            <input name="bb" type="number" step="0.1" min="0">
        </label>
        <label>
            Tinggi badan (cm)
            <input name="tb" type="number" step="0.1" min="0">
        </label>
        <div class="act actions">
            <button>Daftarkan</button>
            <span class="muted">Pasien baru? Tambahkan dulu di <a href="master.php?t=pasien">data pasien</a>.</span>
        </div>
    </form>
</section>

<section>
    <h2>Pendaftaran hari ini</h2>
    <div class="scroll">
        <table>
            <tr>
                <th class="n">No.</th>
                <th>Jam</th>
                <th>Pasien</th>
                <th>Dokter</th>
                <th>Keluhan</th>
                <th>Status</th>
                <th></th>
            </tr>
            <?php foreach ($today as $visit): ?>
                <tr>
                    <td class="n"><?= e($visit['no_antrian'] ?: '-') ?></td>
                    <td><?= e(date('H:i', strtotime($visit['tanggal_kunjungan']))) ?></td>
                    <td>
                        <a href="riwayat.php?id=<?= e($visit['id_pasien']) ?>">
                            <?= e($visit['nama_pasien']) ?>
                        </a><br>
                        <small class="muted"><?= e($visit['no_rekam_medis']) ?></small>
                    </td>
                    <td><?= e($visit['nama_dokter']) ?></td>
                    <td><?= e($visit['keluhan']) ?></td>
                    <td><?= badge($visit['status']) ?></td>
                    <td class="r">
                        <?php if ($visit['status'] === 'Menunggu'): ?>
                            <form method="post" onsubmit="return confirm('Batalkan pendaftaran ini?')">
                                <button class="del" name="batal" value="<?= e($visit['id_kunjungan']) ?>">Batalkan</button>
                            </form>
                        <?php elseif ($visit['status'] !== 'Batal'): ?>
                            <a href="periksa.php?id=<?= e($visit['id_kunjungan']) ?>">Buka</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$today): ?>
                <tr><td colspan="7" class="muted">Belum ada pendaftaran hari ini. Isi formulir di atas untuk mendaftarkan pasien pertama.</td></tr>
            <?php endif; ?>
        </table>
    </div>
    <p class="hint" style="margin-top:10px">Dokter memeriksa pasien dari menu <a href="pemeriksaan.php">Pemeriksaan dokter</a>.</p>
</section>

<script>
    const patientSelect = document.getElementById('pasien');
    const allergyHint = document.getElementById('alergi-hint');
    patientSelect.addEventListener('change', () => {
        const allergy = patientSelect.selectedOptions[0].dataset.alergi || '';
        allergyHint.hidden = !allergy;
        allergyHint.textContent = allergy ? 'Alergi pasien: ' + allergy : '';
    });
</script>

<?php foot(); ?>

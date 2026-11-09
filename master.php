<?php

require_once __DIR__ . '/config.php';

// Format kolom: 'Label|tipe' (tipe: text, date, number, spes, atau daftar pilihan dipisah koma).
$tables = [
    'pasien' => [
        'Data pasien',
        'id_pasien',
        [
            'no_rekam_medis' => 'No. RM',
            'nik' => 'NIK',
            'nama_pasien' => 'Nama pasien',
            'tanggal_lahir' => 'Tanggal lahir|date',
            'jenis_kelamin' => 'Jenis kelamin|L,P',
            'golongan_darah' => 'Gol. darah|A,B,AB,O,Tidak Tahu',
            'no_telp' => 'No. telepon',
            'alamat' => 'Alamat',
            'alergi' => 'Alergi',
        ],
    ],
    'dokter' => [
        'Data dokter',
        'id_dokter',
        [
            'nama_dokter' => 'Nama dokter',
            'id_spesialisasi' => 'Spesialis|spes',
            'no_sip' => 'No. SIP',
            'no_telp' => 'No. telepon',
            'email' => 'Email',
            'tarif_konsultasi' => 'Tarif konsultasi|number',
        ],
    ],
    'obat' => [
        'Data obat',
        'id_obat',
        [
            'kode_obat' => 'Kode',
            'nama_obat' => 'Nama obat',
            'golongan' => 'Golongan|Bebas,Bebas Terbatas,Keras,Narkotika,Psikotropika',
            'bentuk_sediaan' => 'Sediaan|Tablet,Kapsul,Sirup,Salep,Injeksi,Tetes,Serbuk',
            'satuan' => 'Satuan',
            'stok' => 'Stok|number',
            'harga_satuan' => 'Harga satuan|number',
            'tanggal_kadaluarsa' => 'Kadaluarsa|date',
        ],
    ],
    'penyakit' => [
        'Data penyakit',
        'id_penyakit',
        [
            'kode_icd10' => 'Kode ICD-10',
            'nama_penyakit' => 'Nama penyakit',
            'kategori' => 'Kategori',
            'deskripsi' => 'Deskripsi',
        ],
    ],
    'tindakan' => [
        'Tindakan & tarif',
        'id_tindakan',
        [
            'kode_tindakan' => 'Kode',
            'nama_tindakan' => 'Nama tindakan / pemeriksaan',
            'kategori' => 'Kategori|Tindakan Medis,Laboratorium,Radiologi,Penunjang Lain',
            'tarif' => 'Tarif|number',
        ],
    ],
];

// Kolom yang ditampilkan sebagai rupiah di daftar
$moneyFields = ['harga_satuan', 'tarif', 'tarif_konsultasi'];

$table = $_GET['t'] ?? 'pasien';
if (!isset($tables[$table])) {
    $table = 'pasien';
}

[$title, $primaryKey, $fields] = $tables[$table];
$message = '';
$spes = $pdo->query('SELECT * FROM spesialisasi')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['hapus'])) {
            $pdo->prepare("DELETE FROM $table WHERE $primaryKey = ?")->execute([$_POST['hapus']]);
            $message = 'Data dihapus.';
        } else {
            $data = [];
            foreach ($fields as $field => $specification) {
                $isNumber = str_ends_with($specification, '|number');
                $value = trim($_POST[$field] ?? '');
                $data[$field] = $value === '' ? ($isNumber ? 0 : null) : $value;
            }

            if (!empty($_POST['id'])) {
                $setClause = implode(' = ?, ', array_keys($data)) . ' = ?';
                $pdo->prepare("UPDATE $table SET $setClause WHERE $primaryKey = ?")
                    ->execute([...array_values($data), $_POST['id']]);
                $message = 'Data diperbarui.';
            } else {
                $columns = implode(', ', array_keys($data));
                $placeholders = implode(', ', array_fill(0, count($data), '?'));
                $pdo->prepare("INSERT INTO $table ($columns) VALUES ($placeholders)")
                    ->execute(array_values($data));
                $message = 'Data ditambahkan.';
            }
        }
    } catch (PDOException $exception) {
        $message = 'Gagal: ' . (
            $exception->getCode() == 23000
                ? 'data duplikat, kosong, atau masih dipakai data lain.'
                : $exception->getMessage()
        );
    }
}

$row = [];
if (isset($_GET['edit'])) {
    $statement = $pdo->prepare("SELECT * FROM $table WHERE $primaryKey = ?");
    $statement->execute([$_GET['edit']]);
    $row = $statement->fetch() ?: [];
}

head($title, "master.php?t=$table");
if ($message) {
    echo '<p class="msg">' . e($message) . '</p>';
}
?>

<section>
    <h2><?= $row ? 'Ubah data' : 'Tambah data' ?></h2>
    <form method="post" class="grid">
        <input type="hidden" name="id" value="<?= e($row[$primaryKey] ?? '') ?>">
        <?php foreach ($fields as $field => $specification): ?>
            <?php
            [$label, $type] = explode('|', $specification . '|');
            $value = $row[$field] ?? '';
            ?>
            <label>
                <?= e($label) ?>
                <?php if ($type === 'spes'): ?>
                    <select name="<?= e($field) ?>">
                        <?php foreach ($spes as $specialization): ?>
                            <option value="<?= e($specialization['id_spesialisasi']) ?>" <?= $value == $specialization['id_spesialisasi'] ? 'selected' : '' ?>>
                                <?= e($specialization['nama_spesialisasi']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif (str_contains($type, ',')): ?>
                    <select name="<?= e($field) ?>">
                        <?php foreach (explode(',', $type) as $option): ?>
                            <option <?= $value === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input name="<?= e($field) ?>" type="<?= e($type ?: 'text') ?>"
                        <?= $type === 'number' ? 'step="any"' : '' ?> value="<?= e($value) ?>">
                <?php endif; ?>
            </label>
        <?php endforeach; ?>
        <div class="act">
            <button>Simpan data</button>
            <?php if ($row): ?>
                <a href="master.php?t=<?= e($table) ?>">Batal</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section>
    <h2>Daftar <?= e(strtolower($title)) ?></h2>
    <div class="scroll">
        <table>
            <tr>
                <?php foreach ($fields as $specification): ?>
                    <th><?= e(explode('|', $specification)[0]) ?></th>
                <?php endforeach; ?>
                <th></th>
            </tr>
            <?php
            $query = $table === 'dokter'
                ? 'SELECT d.id_dokter, d.nama_dokter, s.nama_spesialisasi AS id_spesialisasi,
                          d.no_sip, d.no_telp, d.email, d.tarif_konsultasi
                   FROM dokter d
                   JOIN spesialisasi s USING (id_spesialisasi)
                   ORDER BY 1 DESC'
                : "SELECT * FROM $table ORDER BY $primaryKey DESC";
            ?>
            <?php foreach ($pdo->query($query) as $record): ?>
                <tr>
                    <?php foreach ($fields as $field => $_): ?>
                        <td><?= in_array($field, $moneyFields, true) ? e(rupiah($record[$field])) : e($record[$field]) ?></td>
                    <?php endforeach; ?>
                    <td class="r">
                        <?php if ($table === 'pasien'): ?>
                            <a href="riwayat.php?id=<?= e($record[$primaryKey]) ?>">Riwayat</a>
                        <?php endif; ?>
                        <a href="master.php?t=<?= e($table) ?>&edit=<?= e($record[$primaryKey]) ?>">Ubah</a>
                        <form method="post" onsubmit="return confirm('Hapus data ini?')">
                            <button class="del" name="hapus" value="<?= e($record[$primaryKey]) ?>">Hapus</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</section>

<?php foot(); ?>

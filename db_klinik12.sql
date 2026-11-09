-- =====================================================================
-- DATABASE SISTEM KLINIK v12  (INSTALASI BARU)
-- DBMS : MySQL 8.0+ / MariaDB 10.5+
-- Isi  : seluruh isi db_klinik11 + pemeriksaan dokter (SOAP, vonis),
--        master tindakan, transaksi, rincian tagihan, pembayaran.
-- PERHATIAN: file ini menjalankan DROP DATABASE db_klinik. Bila database
--            sudah berisi data yang ingin dipertahankan, gunakan
--            db_klinik12_update.sql.
-- =====================================================================

DROP DATABASE IF EXISTS db_klinik;
CREATE DATABASE db_klinik CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db_klinik;

-- ---------------------------------------------------------------------
-- 1. TABEL MASTER
-- ---------------------------------------------------------------------

CREATE TABLE pasien (
    id_pasien       INT AUTO_INCREMENT PRIMARY KEY,
    no_rekam_medis  VARCHAR(15)  NOT NULL UNIQUE,
    nik             CHAR(16)     UNIQUE,
    nama_pasien     VARCHAR(100) NOT NULL,
    tanggal_lahir   DATE         NOT NULL,
    jenis_kelamin   ENUM('L','P') NOT NULL,
    golongan_darah  ENUM('A','B','AB','O','Tidak Tahu') NOT NULL DEFAULT 'Tidak Tahu',
    alamat          TEXT,
    no_telp         VARCHAR(15),
    alergi          TEXT,
    tanggal_daftar  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_nama_pasien (nama_pasien)
) ENGINE=InnoDB;

CREATE TABLE spesialisasi (
    id_spesialisasi   INT AUTO_INCREMENT PRIMARY KEY,
    nama_spesialisasi VARCHAR(60) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE dokter (
    id_dokter       INT AUTO_INCREMENT PRIMARY KEY,
    nama_dokter     VARCHAR(100) NOT NULL,
    id_spesialisasi INT          NOT NULL,
    no_sip          VARCHAR(30)  NOT NULL UNIQUE,   -- Surat Izin Praktik
    no_telp         VARCHAR(15),
    email           VARCHAR(100),
    status_aktif    TINYINT(1)   NOT NULL DEFAULT 1,
    CONSTRAINT fk_dokter_spesialisasi FOREIGN KEY (id_spesialisasi)
        REFERENCES spesialisasi(id_spesialisasi)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE jadwal_dokter (
    id_jadwal   INT AUTO_INCREMENT PRIMARY KEY,
    id_dokter   INT NOT NULL,
    hari        ENUM('Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu') NOT NULL,
    jam_mulai   TIME NOT NULL,
    jam_selesai TIME NOT NULL,
    CONSTRAINT fk_jadwal_dokter FOREIGN KEY (id_dokter)
        REFERENCES dokter(id_dokter)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_jam CHECK (jam_selesai > jam_mulai)
) ENGINE=InnoDB;

CREATE TABLE penyakit (
    id_penyakit   INT AUTO_INCREMENT PRIMARY KEY,
    kode_icd10    VARCHAR(10)  NOT NULL UNIQUE,
    nama_penyakit VARCHAR(150) NOT NULL,
    kategori      VARCHAR(60),
    deskripsi     TEXT
) ENGINE=InnoDB;

CREATE TABLE obat (
    id_obat             INT AUTO_INCREMENT PRIMARY KEY,
    kode_obat           VARCHAR(15)  NOT NULL UNIQUE,
    nama_obat           VARCHAR(100) NOT NULL,
    golongan            ENUM('Bebas','Bebas Terbatas','Keras','Narkotika','Psikotropika') NOT NULL,
    bentuk_sediaan      ENUM('Tablet','Kapsul','Sirup','Salep','Injeksi','Tetes','Serbuk') NOT NULL,
    satuan              VARCHAR(20)  NOT NULL,
    stok                INT          NOT NULL DEFAULT 0,
    harga_satuan        DECIMAL(12,2) NOT NULL,
    tanggal_kadaluarsa  DATE,
    CONSTRAINT chk_stok  CHECK (stok >= 0),
    CONSTRAINT chk_harga CHECK (harga_satuan >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. TABEL TRANSAKSI
-- ---------------------------------------------------------------------

-- Riwayat datang berobat
CREATE TABLE kunjungan (
    id_kunjungan      INT AUTO_INCREMENT PRIMARY KEY,
    id_pasien         INT      NOT NULL,
    id_dokter         INT      NOT NULL,
    tanggal_kunjungan DATETIME NOT NULL,
    keluhan           TEXT,
    tekanan_darah     VARCHAR(10),
    suhu_badan        DECIMAL(4,1),
    berat_badan       DECIMAL(5,2),
    tinggi_badan      DECIMAL(5,2),
    status            ENUM('Menunggu','Diperiksa','Selesai','Batal') NOT NULL DEFAULT 'Menunggu',
    catatan_dokter    TEXT,
    CONSTRAINT fk_kunjungan_pasien FOREIGN KEY (id_pasien)
        REFERENCES pasien(id_pasien) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_kunjungan_dokter FOREIGN KEY (id_dokter)
        REFERENCES dokter(id_dokter) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_tanggal (tanggal_kunjungan)
) ENGINE=InnoDB;

-- Data penyakit setiap pasien (satu kunjungan bisa punya banyak diagnosa)
CREATE TABLE diagnosa (
    id_diagnosa    INT AUTO_INCREMENT PRIMARY KEY,
    id_kunjungan   INT NOT NULL,
    id_penyakit    INT NOT NULL,
    jenis_diagnosa ENUM('Primer','Sekunder') NOT NULL DEFAULT 'Primer',
    catatan        TEXT,
    CONSTRAINT fk_diagnosa_kunjungan FOREIGN KEY (id_kunjungan)
        REFERENCES kunjungan(id_kunjungan) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_diagnosa_penyakit FOREIGN KEY (id_penyakit)
        REFERENCES penyakit(id_penyakit) ON UPDATE CASCADE ON DELETE RESTRICT,
    UNIQUE KEY uq_kunjungan_penyakit (id_kunjungan, id_penyakit)
) ENGINE=InnoDB;

CREATE TABLE resep (
    id_resep     INT AUTO_INCREMENT PRIMARY KEY,
    id_kunjungan INT NOT NULL,
    tanggal_resep DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    catatan      TEXT,
    CONSTRAINT fk_resep_kunjungan FOREIGN KEY (id_kunjungan)
        REFERENCES kunjungan(id_kunjungan) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE detail_resep (
    id_detail   INT AUTO_INCREMENT PRIMARY KEY,
    id_resep    INT NOT NULL,
    id_obat     INT NOT NULL,
    jumlah      INT NOT NULL,
    dosis       VARCHAR(50),
    aturan_pakai VARCHAR(100),
    lama_hari   INT,
    CONSTRAINT fk_detail_resep FOREIGN KEY (id_resep)
        REFERENCES resep(id_resep) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_detail_obat FOREIGN KEY (id_obat)
        REFERENCES obat(id_obat) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_jumlah CHECK (jumlah > 0),
    UNIQUE KEY uq_resep_obat (id_resep, id_obat)
) ENGINE=InnoDB;

CREATE TABLE pembayaran (
    id_pembayaran    INT AUTO_INCREMENT PRIMARY KEY,
    id_kunjungan     INT NOT NULL UNIQUE,
    biaya_konsultasi DECIMAL(12,2) NOT NULL DEFAULT 0,
    biaya_obat       DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_bayar      DECIMAL(12,2) GENERATED ALWAYS AS (biaya_konsultasi + biaya_obat) STORED,
    metode_bayar     ENUM('Tunai','Debit','QRIS','BPJS','Asuransi') NOT NULL,
    status_bayar     ENUM('Belum Lunas','Lunas') NOT NULL DEFAULT 'Belum Lunas',
    tanggal_bayar    DATETIME,
    CONSTRAINT fk_bayar_kunjungan FOREIGN KEY (id_kunjungan)
        REFERENCES kunjungan(id_kunjungan) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. TRIGGER: stok obat otomatis berkurang saat obat diresepkan
--    (jika stok tidak cukup, CHECK chk_stok akan menolak insert)
-- ---------------------------------------------------------------------
DELIMITER $$
CREATE TRIGGER trg_kurangi_stok_obat
AFTER INSERT ON detail_resep
FOR EACH ROW
BEGIN
    UPDATE obat SET stok = stok - NEW.jumlah WHERE id_obat = NEW.id_obat;
END$$
DELIMITER ;

-- ---------------------------------------------------------------------
-- 4. VIEW: ringkasan riwayat berobat pasien
-- ---------------------------------------------------------------------
CREATE VIEW v_riwayat_pasien AS
SELECT  p.id_pasien,
        p.nama_pasien,
        k.id_kunjungan,
        k.tanggal_kunjungan,
        d.nama_dokter,
        s.nama_spesialisasi,
        k.keluhan,
        GROUP_CONCAT(DISTINCT pn.nama_penyakit SEPARATOR ', ') AS diagnosa
FROM pasien p
JOIN kunjungan k      ON k.id_pasien = p.id_pasien
JOIN dokter d         ON d.id_dokter = k.id_dokter
JOIN spesialisasi s   ON s.id_spesialisasi = d.id_spesialisasi
LEFT JOIN diagnosa dg ON dg.id_kunjungan = k.id_kunjungan
LEFT JOIN penyakit pn ON pn.id_penyakit = dg.id_penyakit
GROUP BY p.id_pasien, p.nama_pasien, k.id_kunjungan, k.tanggal_kunjungan,
         d.nama_dokter, s.nama_spesialisasi, k.keluhan;

-- ---------------------------------------------------------------------
-- 5. DATA CONTOH
-- ---------------------------------------------------------------------

INSERT INTO spesialisasi (nama_spesialisasi) VALUES
('Dokter Umum'), ('Penyakit Dalam'), ('Anak'), ('THT');

INSERT INTO dokter (nama_dokter, id_spesialisasi, no_sip, no_telp, email) VALUES
('dr. Rina Wijaya',           1, 'SIP-2020-001', '081111111111', 'rina@klinik.id'),
('dr. Hendra Kusuma, Sp.PD',  2, 'SIP-2018-014', '082222222222', 'hendra@klinik.id'),
('dr. Maya Sari, Sp.A',       3, 'SIP-2019-027', '083333333333', 'maya@klinik.id'),
('dr. Andi Prasetyo, Sp.THT', 4, 'SIP-2021-033', '084444444444', 'andi@klinik.id');

INSERT INTO jadwal_dokter (id_dokter, hari, jam_mulai, jam_selesai) VALUES
(1, 'Senin',  '08:00', '14:00'),
(1, 'Rabu',   '08:00', '14:00'),
(2, 'Selasa', '08:00', '12:00'),
(2, 'Kamis',  '13:00', '17:00'),
(3, 'Senin',  '13:00', '17:00'),
(4, 'Jumat',  '09:00', '13:00');

INSERT INTO pasien (no_rekam_medis, nik, nama_pasien, tanggal_lahir, jenis_kelamin, golongan_darah, alamat, no_telp, alergi) VALUES
('RM-0001', '3571012345670001', 'Budi Santoso', '1985-03-12', 'L', 'O',  'Jl. Merdeka No. 10, Ubud',    '081234567890', 'Penisilin'),
('RM-0002', '3573026789120002', 'Siti Aminah',  '1992-07-25', 'P', 'A',  'Jl. Raya Mas No. 5, Ubud',    '081298765432', NULL),
('RM-0003', '3578019001010003', 'Agus Pratama', '1970-11-02', 'L', 'B',  'Jl. Monkey Forest No. 21',    '085711223344', NULL),
('RM-0004', '3374055501150004', 'Dewi Lestari', '2015-01-18', 'P', 'AB', 'Jl. Hanoman No. 8, Padang Tegal', '087788990011', 'Udang');

INSERT INTO penyakit (kode_icd10, nama_penyakit, kategori, deskripsi) VALUES
('J06.9', 'Infeksi Saluran Pernapasan Akut (ISPA)', 'Pernapasan',     'Infeksi saluran napas atas'),
('K29.7', 'Gastritis',                              'Pencernaan',     'Peradangan lambung'),
('I10',   'Hipertensi Esensial',                    'Kardiovaskular', 'Tekanan darah tinggi'),
('E11',   'Diabetes Melitus Tipe 2',                'Endokrin',       'Gangguan kadar gula darah'),
('A09',   'Diare dan Gastroenteritis',              'Infeksi',        'Peradangan saluran cerna akibat infeksi');

INSERT INTO obat (kode_obat, nama_obat, golongan, bentuk_sediaan, satuan, stok, harga_satuan, tanggal_kadaluarsa) VALUES
('OB-001', 'Paracetamol 500 mg', 'Bebas', 'Tablet', 'tablet', 500, 1000,  '2028-05-01'),
('OB-002', 'Omeprazole 20 mg',   'Keras', 'Kapsul', 'kapsul', 300, 3500,  '2028-01-15'),
('OB-003', 'Amlodipine 5 mg',    'Keras', 'Tablet', 'tablet', 400, 2000,  '2028-03-20'),
('OB-004', 'Metformin 500 mg',   'Keras', 'Tablet', 'tablet', 400, 1500,  '2028-02-10'),
('OB-005', 'Oralit',             'Bebas', 'Serbuk', 'sachet', 150, 2500,  '2027-12-31'),
('OB-006', 'Zinc 20 mg',         'Bebas Terbatas', 'Tablet', 'tablet', 200, 3000, '2028-04-30');

INSERT INTO kunjungan (id_pasien, id_dokter, tanggal_kunjungan, keluhan, tekanan_darah, suhu_badan, berat_badan, tinggi_badan, status, catatan_dokter) VALUES
(1, 1, '2026-10-20 09:00:00', 'Demam dan batuk 3 hari',              '120/80',  38.2, 70.0, 170.0, 'Selesai', 'Istirahat cukup, banyak minum air putih'),
(2, 1, '2026-10-21 10:30:00', 'Mual dan nyeri ulu hati',             '110/70',  36.8, 55.0, 160.0, 'Selesai', 'Hindari makanan pedas dan asam'),
(3, 2, '2026-10-22 08:15:00', 'Pusing dan tengkuk terasa kaku',      '160/100', 36.6, 82.0, 168.0, 'Selesai', 'Kurangi garam, kontrol 1 minggu lagi'),
(3, 2, '2026-10-26 08:30:00', 'Kontrol tekanan darah dan gula darah','145/90',  36.5, 81.0, 168.0, 'Selesai', 'Gula darah puasa 150 mg/dL'),
(4, 3, '2026-10-26 13:00:00', 'Diare sejak kemarin, 5x sehari',      '-',       37.5, 20.0, 115.0, 'Selesai', 'Pantau tanda dehidrasi');

INSERT INTO diagnosa (id_kunjungan, id_penyakit, jenis_diagnosa, catatan) VALUES
(1, 1, 'Primer',   NULL),
(2, 2, 'Primer',   NULL),
(3, 3, 'Primer',   'Hipertensi derajat 2'),
(4, 3, 'Primer',   NULL),
(4, 4, 'Sekunder', 'Terdiagnosis baru'),
(5, 5, 'Primer',   'Dehidrasi ringan');

INSERT INTO resep (id_kunjungan, catatan) VALUES
(1, NULL), (2, NULL), (3, NULL), (4, 'Obat untuk 30 hari'), (5, NULL);

INSERT INTO detail_resep (id_resep, id_obat, jumlah, dosis, aturan_pakai, lama_hari) VALUES
(1, 1, 9,  '500 mg', '3 x 1 tablet sesudah makan',        3),
(2, 2, 10, '20 mg',  '1 x 1 kapsul sebelum makan pagi',   10),
(3, 3, 30, '5 mg',   '1 x 1 tablet pagi hari',            30),
(4, 3, 30, '5 mg',   '1 x 1 tablet pagi hari',            30),
(4, 4, 60, '500 mg', '2 x 1 tablet sesudah makan',        30),
(5, 5, 10, '1 sachet', 'Larutkan dalam 200 ml air, setiap selesai diare', 3),
(5, 6, 10, '20 mg',  '1 x 1 tablet',                      10);

INSERT INTO pembayaran (id_kunjungan, biaya_konsultasi, biaya_obat, metode_bayar, status_bayar, tanggal_bayar) VALUES
(1, 100000, 9000,   'Tunai', 'Lunas', '2026-10-20 09:30:00'),
(2, 100000, 35000,  'QRIS',  'Lunas', '2026-10-21 11:00:00'),
(3, 250000, 60000,  'BPJS',  'Lunas', '2026-10-22 08:45:00'),
(4, 250000, 150000, 'Debit', 'Lunas', '2026-10-26 09:00:00'),
(5, 200000, 55000,  'Tunai', 'Lunas', '2026-10-26 13:30:00');


-- =====================================================================
-- BAGIAN BARU v12
-- =====================================================================

-- ---------------------------------------------------------------------
-- A. PERUBAHAN TABEL YANG SUDAH ADA
-- ---------------------------------------------------------------------

-- Tarif konsultasi per dokter (dipakai otomatis saat tagihan dibuat)
ALTER TABLE dokter
    ADD COLUMN tarif_konsultasi DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER email;

UPDATE dokter SET tarif_konsultasi = CASE id_spesialisasi
    WHEN 1 THEN 100000   -- Dokter Umum
    WHEN 2 THEN 250000   -- Penyakit Dalam
    WHEN 3 THEN 200000   -- Anak
    WHEN 4 THEN 225000   -- THT
    ELSE 100000 END;

-- Nomor antrian per dokter per hari
ALTER TABLE kunjungan
    ADD COLUMN no_antrian SMALLINT UNSIGNED NULL AFTER id_dokter;

UPDATE kunjungan SET no_antrian = 1 WHERE no_antrian IS NULL;

-- ---------------------------------------------------------------------
-- B. MASTER TINDAKAN & PENUNJANG (beserta tarifnya)
-- ---------------------------------------------------------------------

CREATE TABLE tindakan (
    id_tindakan   INT AUTO_INCREMENT PRIMARY KEY,
    kode_tindakan VARCHAR(15)  NOT NULL UNIQUE,
    nama_tindakan VARCHAR(120) NOT NULL,
    kategori      ENUM('Tindakan Medis','Laboratorium','Radiologi','Penunjang Lain') NOT NULL,
    tarif         DECIMAL(12,2) NOT NULL DEFAULT 0,
    status_aktif  TINYINT(1)   NOT NULL DEFAULT 1,
    CONSTRAINT chk_tarif CHECK (tarif >= 0)
) ENGINE=InnoDB;

INSERT INTO tindakan (kode_tindakan, nama_tindakan, kategori, tarif) VALUES
('TDK-001', 'Nebulisasi',                  'Tindakan Medis', 75000),
('TDK-002', 'Injeksi (IM/IV)',             'Tindakan Medis', 35000),
('TDK-003', 'Jahit luka (per 5 jahitan)',  'Tindakan Medis', 150000),
('TDK-004', 'Rawat luka',                  'Tindakan Medis', 60000),
('LAB-001', 'Gula darah sewaktu',          'Laboratorium',   25000),
('LAB-002', 'Darah lengkap',               'Laboratorium',   85000),
('LAB-003', 'Asam urat',                   'Laboratorium',   30000),
('LAB-004', 'Kolesterol total',            'Laboratorium',   40000),
('RAD-001', 'Rontgen thorax',              'Radiologi',      180000),
('PNJ-001', 'EKG',                         'Penunjang Lain', 100000);

-- ---------------------------------------------------------------------
-- C. PEMERIKSAAN DOKTER (rekam medis terstruktur, format SOAP)
--    1 kunjungan = 1 pemeriksaan. Diagnosa ICD-10 tetap di tabel
--    `diagnosa`, resep tetap di `resep` / `detail_resep`.
-- ---------------------------------------------------------------------

CREATE TABLE pemeriksaan (
    id_pemeriksaan   INT AUTO_INCREMENT PRIMARY KEY,
    id_kunjungan     INT NOT NULL UNIQUE,
    waktu_periksa    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- S: anamnesis
    keluhan_utama    TEXT NOT NULL,
    riwayat_sekarang TEXT,
    riwayat_dahulu   TEXT,
    riwayat_keluarga TEXT,

    -- O: tanda vital
    td_sistol        SMALLINT UNSIGNED,
    td_diastol       SMALLINT UNSIGNED,
    nadi             SMALLINT UNSIGNED,
    laju_napas       SMALLINT UNSIGNED,
    suhu             DECIMAL(4,1),
    spo2             TINYINT UNSIGNED,
    berat_badan      DECIMAL(5,2),
    tinggi_badan     DECIMAL(5,2),
    bmi              DECIMAL(5,1) GENERATED ALWAYS AS
                     (ROUND(berat_badan / POW(NULLIF(tinggi_badan, 0) / 100, 2), 1)) STORED,

    -- O: pemeriksaan fisik
    kesadaran        ENUM('Compos Mentis','Apatis','Somnolen','Sopor','Koma') NOT NULL DEFAULT 'Compos Mentis',
    keadaan_umum     ENUM('Baik','Sedang','Buruk') NOT NULL DEFAULT 'Baik',
    pf_kepala_leher  TEXT,
    pf_thorax_paru   TEXT,
    pf_jantung       TEXT,
    pf_abdomen       TEXT,
    pf_ekstremitas   TEXT,
    pf_kulit         TEXT,
    status_lokalis   TEXT,

    -- A: assessment, P: plan
    diagnosa_kerja   TEXT,
    rencana_terapi   TEXT,
    edukasi          TEXT,

    -- Vonis dokter & tindak lanjut
    vonis            ENUM('Sembuh','Rawat Jalan','Kontrol Ulang','Rujuk','Rawat Inap') NOT NULL DEFAULT 'Rawat Jalan',
    prognosis        ENUM('Baik','Dubia','Buruk') NOT NULL DEFAULT 'Baik',
    istirahat_hari   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    tanggal_kontrol  DATE,
    rujuk_ke         VARCHAR(150),

    CONSTRAINT fk_pemeriksaan_kunjungan FOREIGN KEY (id_kunjungan)
        REFERENCES kunjungan(id_kunjungan) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_waktu_periksa (waktu_periksa),
    INDEX idx_tanggal_kontrol (tanggal_kontrol)
) ENGINE=InnoDB;

-- Pemeriksaan penunjang & tindakan yang dilakukan pada satu pemeriksaan.
-- Tarif disalin saat tindakan dilakukan, jadi perubahan tarif di master
-- tidak mengubah tagihan yang sudah dibuat.
CREATE TABLE pemeriksaan_tindakan (
    id_pt          INT AUTO_INCREMENT PRIMARY KEY,
    id_pemeriksaan INT NOT NULL,
    id_tindakan    INT NOT NULL,
    jumlah         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    tarif          DECIMAL(12,2) NOT NULL,
    hasil          TEXT,
    CONSTRAINT fk_pt_pemeriksaan FOREIGN KEY (id_pemeriksaan)
        REFERENCES pemeriksaan(id_pemeriksaan) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_pt_tindakan FOREIGN KEY (id_tindakan)
        REFERENCES tindakan(id_tindakan) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_pt_jumlah CHECK (jumlah > 0),
    UNIQUE KEY uq_pemeriksaan_tindakan (id_pemeriksaan, id_tindakan)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- D. TRANSAKSI (invoice, rincian tagihan, pembayaran/cicilan)
--    Menggantikan tabel `pembayaran` lama. Tabel lama dibiarkan sebagai
--    arsip; aplikasi tidak lagi menulis ke sana.
-- ---------------------------------------------------------------------

CREATE TABLE transaksi (
    id_transaksi      INT AUTO_INCREMENT PRIMARY KEY,
    no_invoice        VARCHAR(20) NOT NULL UNIQUE,
    id_kunjungan      INT NOT NULL UNIQUE,
    tanggal_transaksi DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    subtotal          DECIMAL(12,2) NOT NULL DEFAULT 0,
    diskon            DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_tagihan     DECIMAL(12,2) GENERATED ALWAYS AS (subtotal - diskon) STORED,
    total_dibayar     DECIMAL(12,2) NOT NULL DEFAULT 0,
    status_bayar      ENUM('Belum Bayar','Sebagian','Lunas','Batal') NOT NULL DEFAULT 'Belum Bayar',
    catatan           TEXT,
    CONSTRAINT fk_transaksi_kunjungan FOREIGN KEY (id_kunjungan)
        REFERENCES kunjungan(id_kunjungan) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_diskon CHECK (diskon >= 0),
    CONSTRAINT chk_dibayar CHECK (total_dibayar >= 0),
    INDEX idx_tanggal_transaksi (tanggal_transaksi),
    INDEX idx_status_bayar (status_bayar)
) ENGINE=InnoDB;

CREATE TABLE transaksi_detail (
    id_detail_transaksi INT AUTO_INCREMENT PRIMARY KEY,
    id_transaksi        INT NOT NULL,
    jenis               ENUM('Konsultasi','Tindakan','Obat','Lainnya') NOT NULL,
    id_ref              INT NULL,            -- id_dokter / id_tindakan / id_obat
    deskripsi           VARCHAR(200) NOT NULL,
    qty                 INT NOT NULL DEFAULT 1,
    harga_satuan        DECIMAL(12,2) NOT NULL,
    subtotal            DECIMAL(14,2) GENERATED ALWAYS AS (qty * harga_satuan) STORED,
    CONSTRAINT fk_td_transaksi FOREIGN KEY (id_transaksi)
        REFERENCES transaksi(id_transaksi) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_td_qty CHECK (qty > 0),
    CONSTRAINT chk_td_harga CHECK (harga_satuan >= 0)
) ENGINE=InnoDB;

CREATE TABLE pembayaran_transaksi (
    id_bayar      INT AUTO_INCREMENT PRIMARY KEY,
    id_transaksi  INT NOT NULL,
    tanggal_bayar DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    metode_bayar  ENUM('Tunai','Debit','QRIS','BPJS','Asuransi') NOT NULL,
    jumlah_bayar  DECIMAL(12,2) NOT NULL,
    uang_diterima DECIMAL(12,2) NULL,        -- khusus tunai, untuk hitung kembalian
    no_referensi  VARCHAR(50),
    kasir         VARCHAR(60),
    CONSTRAINT fk_pbt_transaksi FOREIGN KEY (id_transaksi)
        REFERENCES transaksi(id_transaksi) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_jumlah_bayar CHECK (jumlah_bayar > 0),
    INDEX idx_tanggal_bayar (tanggal_bayar)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- E. VIEW: ringkasan transaksi
-- ---------------------------------------------------------------------
CREATE VIEW v_transaksi AS
SELECT  t.id_transaksi,
        t.no_invoice,
        t.tanggal_transaksi,
        t.subtotal,
        t.diskon,
        t.total_tagihan,
        t.total_dibayar,
        (t.total_tagihan - t.total_dibayar) AS sisa_tagihan,
        t.status_bayar,
        k.id_kunjungan,
        p.id_pasien,
        p.no_rekam_medis,
        p.nama_pasien,
        d.nama_dokter
FROM transaksi t
JOIN kunjungan k ON k.id_kunjungan = t.id_kunjungan
JOIN pasien p    ON p.id_pasien = k.id_pasien
JOIN dokter d    ON d.id_dokter = k.id_dokter;

-- ---------------------------------------------------------------------
-- F. DATA CONTOH PEMERIKSAAN DOKTER (untuk 5 kunjungan yang sudah ada)
-- ---------------------------------------------------------------------

INSERT INTO pemeriksaan
    (id_kunjungan, waktu_periksa, keluhan_utama, riwayat_sekarang, riwayat_dahulu, riwayat_keluarga,
     td_sistol, td_diastol, nadi, laju_napas, suhu, spo2, berat_badan, tinggi_badan,
     kesadaran, keadaan_umum, pf_kepala_leher, pf_thorax_paru, pf_jantung, pf_abdomen, pf_ekstremitas, pf_kulit, status_lokalis,
     diagnosa_kerja, rencana_terapi, edukasi, vonis, prognosis, istirahat_hari, tanggal_kontrol, rujuk_ke)
VALUES
(1, '2026-10-20 09:10:00', 'Demam dan batuk 3 hari',
 'Demam naik turun, batuk berdahak, pilek. Tidak sesak napas.', 'Tidak ada penyakit kronis. Alergi penisilin.', NULL,
 120, 80, 88, 20, 38.2, 98, 70.0, 170.0,
 'Compos Mentis', 'Sedang',
 'Faring hiperemis, tonsil T1-T1 tenang, tidak ada pembesaran KGB.',
 'Simetris, vesikuler +/+, ronkhi -/-, wheezing -/-.',
 'BJ I-II reguler, murmur (-), gallop (-).',
 'Supel, bising usus normal, nyeri tekan (-).',
 'Akral hangat, CRT < 2 detik, edema (-).',
 'Tidak ada ruam.', NULL,
 'ISPA, kemungkinan viral.', 'Antipiretik. Antibiotik tidak diberikan (alergi penisilin, tidak ada tanda infeksi bakteri).',
 'Istirahat cukup, banyak minum air putih, kembali bila demam lebih dari 3 hari atau sesak.',
 'Rawat Jalan', 'Baik', 2, NULL, NULL),

(2, '2026-10-21 10:40:00', 'Mual dan nyeri ulu hati',
 'Nyeri ulu hati 2 hari, memberat saat telat makan, disertai mual. Tidak muntah, tidak BAB hitam.', 'Pernah keluhan serupa 6 bulan lalu.', NULL,
 110, 70, 80, 18, 36.8, 99, 55.0, 160.0,
 'Compos Mentis', 'Baik',
 'Konjungtiva tidak anemis, sklera tidak ikterik.',
 'Vesikuler +/+, ronkhi -/-.',
 'BJ I-II reguler.',
 'Supel, nyeri tekan epigastrium (+), bising usus normal.',
 'Akral hangat.',
 'Tidak ada kelainan.', NULL,
 'Gastritis akut.', 'Penekan asam lambung, makan teratur porsi kecil tapi sering.',
 'Hindari makanan pedas, asam, kopi. Kembali bila muntah darah atau BAB hitam.',
 'Rawat Jalan', 'Baik', 0, NULL, NULL),

(3, '2026-10-22 08:25:00', 'Pusing dan tengkuk terasa kaku',
 'Pusing dan tengkuk kaku sejak 3 hari, terutama pagi hari. Tidak ada kelemahan anggota gerak.', 'Belum pernah diperiksa tekanan darah sebelumnya.', 'Ayah menderita hipertensi.',
 160, 100, 84, 18, 36.6, 98, 82.0, 168.0,
 'Compos Mentis', 'Sedang',
 'Tidak ada kelainan, tidak ada kaku kuduk.',
 'Vesikuler +/+, ronkhi -/-, wheezing -/-.',
 'BJ I-II reguler, murmur (-).',
 'Supel, nyeri tekan (-).',
 'Akral hangat, edema (-).',
 'Tidak ada kelainan.', NULL,
 'Hipertensi derajat 2.', 'Antihipertensi satu kali sehari, diet rendah garam.',
 'Kurangi garam dan makanan berlemak, olahraga ringan teratur, cek tekanan darah berkala.',
 'Kontrol Ulang', 'Dubia', 0, '2026-10-29', NULL),

(4, '2026-10-26 08:40:00', 'Kontrol tekanan darah dan gula darah',
 'Pusing berkurang. Sering haus dan sering BAK malam hari.', 'Hipertensi sejak 22 Oktober 2026.', 'Ayah hipertensi, ibu diabetes.',
 145, 90, 80, 18, 36.5, 98, 81.0, 168.0,
 'Compos Mentis', 'Baik',
 'Tidak ada kelainan.',
 'Vesikuler +/+, ronkhi -/-.',
 'BJ I-II reguler, murmur (-).',
 'Supel, nyeri tekan (-).',
 'Akral hangat, edema (-), sensasi kaki normal.',
 'Tidak ada luka.', NULL,
 'Hipertensi terkontrol sebagian, diabetes melitus tipe 2 baru terdiagnosis (GDP 150 mg/dL).',
 'Lanjut amlodipine, tambah metformin. Diet rendah gula dan garam.',
 'Jaga pola makan, olahraga rutin 30 menit, kontrol gula darah dan tekanan darah bulan depan.',
 'Kontrol Ulang', 'Dubia', 0, '2026-11-25', NULL),

(5, '2026-10-26 13:10:00', 'Diare sejak kemarin, 5 kali sehari',
 'BAB cair 5 kali, tanpa lendir dan darah, muntah 1 kali. Masih mau minum.', 'Alergi udang.', NULL,
 NULL, NULL, 110, 26, 37.5, 98, 20.0, 115.0,
 'Compos Mentis', 'Sedang',
 'Mata tidak cekung, mukosa bibir agak kering.',
 'Vesikuler +/+, ronkhi -/-.',
 'BJ I-II reguler.',
 'Supel, bising usus meningkat, nyeri tekan (-).',
 'Akral hangat, CRT < 2 detik, turgor kembali cepat.',
 'Tidak ada kelainan.', NULL,
 'Gastroenteritis akut dengan dehidrasi ringan.', 'Rehidrasi oral dan zinc selama 10 hari.',
 'Beri minum sedikit-sedikit tapi sering, lanjutkan ASI/makan. Kembali bila tidak mau minum, lemas, atau BAB berdarah.',
 'Rawat Jalan', 'Baik', 0, NULL, NULL);

-- ---------------------------------------------------------------------
-- G. MIGRASI: kunjungan berstatus Selesai -> transaksi baru
--    (biaya konsultasi & obat dari `pembayaran` lama / resep yang ada,
--     pembayaran Lunas lama ikut dipindahkan sebagai riwayat bayar)
-- ---------------------------------------------------------------------

INSERT INTO transaksi (no_invoice, id_kunjungan, tanggal_transaksi)
SELECT CONCAT('INV-', DATE_FORMAT(k.tanggal_kunjungan, '%Y%m%d'), '-', LPAD(k.id_kunjungan, 4, '0')),
       k.id_kunjungan, k.tanggal_kunjungan
FROM kunjungan k
WHERE k.status = 'Selesai';

INSERT INTO transaksi_detail (id_transaksi, jenis, id_ref, deskripsi, qty, harga_satuan)
SELECT t.id_transaksi, 'Konsultasi', d.id_dokter, CONCAT('Konsultasi ', d.nama_dokter), 1,
       COALESCE(pb.biaya_konsultasi, d.tarif_konsultasi)
FROM transaksi t
JOIN kunjungan k ON k.id_kunjungan = t.id_kunjungan
JOIN dokter d    ON d.id_dokter = k.id_dokter
LEFT JOIN pembayaran pb ON pb.id_kunjungan = k.id_kunjungan
WHERE COALESCE(pb.biaya_konsultasi, d.tarif_konsultasi) > 0;

INSERT INTO transaksi_detail (id_transaksi, jenis, id_ref, deskripsi, qty, harga_satuan)
SELECT t.id_transaksi, 'Obat', o.id_obat, o.nama_obat, dr.jumlah, o.harga_satuan
FROM transaksi t
JOIN resep r          ON r.id_kunjungan = t.id_kunjungan
JOIN detail_resep dr  ON dr.id_resep = r.id_resep
JOIN obat o           ON o.id_obat = dr.id_obat;

INSERT INTO pembayaran_transaksi (id_transaksi, tanggal_bayar, metode_bayar, jumlah_bayar)
SELECT t.id_transaksi, COALESCE(pb.tanggal_bayar, t.tanggal_transaksi), pb.metode_bayar, pb.total_bayar
FROM pembayaran pb
JOIN transaksi t ON t.id_kunjungan = pb.id_kunjungan
WHERE pb.status_bayar = 'Lunas' AND pb.total_bayar > 0;

UPDATE transaksi t SET
    t.subtotal      = (SELECT COALESCE(SUM(d.subtotal), 0) FROM transaksi_detail d WHERE d.id_transaksi = t.id_transaksi),
    t.total_dibayar = (SELECT COALESCE(SUM(b.jumlah_bayar), 0) FROM pembayaran_transaksi b WHERE b.id_transaksi = t.id_transaksi);

UPDATE transaksi SET status_bayar = CASE
    WHEN total_dibayar >= total_tagihan THEN 'Lunas'
    WHEN total_dibayar > 0              THEN 'Sebagian'
    ELSE 'Belum Bayar' END;

-- ---------------------------------------------------------------------
-- H. CONTOH QUERY
-- ---------------------------------------------------------------------

-- a. Rekam pemeriksaan lengkap satu pasien
SELECT k.tanggal_kunjungan, pm.keluhan_utama, pm.td_sistol, pm.td_diastol, pm.suhu, pm.bmi,
       pm.diagnosa_kerja, pm.vonis, pm.tanggal_kontrol
FROM pemeriksaan pm
JOIN kunjungan k ON k.id_kunjungan = pm.id_kunjungan
JOIN pasien p    ON p.id_pasien = k.id_pasien
WHERE p.nama_pasien = 'Agus Pratama'
ORDER BY k.tanggal_kunjungan;

-- b. Tagihan yang belum lunas
SELECT no_invoice, nama_pasien, total_tagihan, total_dibayar, sisa_tagihan
FROM v_transaksi
WHERE status_bayar IN ('Belum Bayar', 'Sebagian');

-- c. Pendapatan per metode bayar
SELECT metode_bayar, COUNT(*) AS jumlah_transaksi, SUM(jumlah_bayar) AS total
FROM pembayaran_transaksi
GROUP BY metode_bayar
ORDER BY total DESC;

-- d. Pendapatan per komponen (konsultasi / tindakan / obat)
SELECT d.jenis, SUM(d.subtotal) AS total
FROM transaksi_detail d
JOIN transaksi t ON t.id_transaksi = d.id_transaksi
WHERE t.status_bayar <> 'Batal'
GROUP BY d.jenis;

-- e. Pasien yang dijadwalkan kontrol dan belum kembali
SELECT p.nama_pasien, p.no_telp, pm.tanggal_kontrol
FROM pemeriksaan pm
JOIN kunjungan k ON k.id_kunjungan = pm.id_kunjungan
JOIN pasien p    ON p.id_pasien = k.id_pasien
WHERE pm.vonis = 'Kontrol Ulang'
  AND NOT EXISTS (SELECT 1 FROM kunjungan k2
                  WHERE k2.id_pasien = k.id_pasien AND k2.tanggal_kunjungan > k.tanggal_kunjungan);

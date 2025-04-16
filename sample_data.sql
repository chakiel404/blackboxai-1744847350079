-- Sample data for pengguna (users)
INSERT INTO pengguna (nama, email, kata_sandi, peran, dibuat_pada, diperbarui_pada) VALUES
('Admin System', 'admin@justemdl.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', NOW(), NOW()),
('Budi Santoso', 'budi.santoso@justemdl.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'guru', NOW(), NOW()),
('Dewi Putri', 'dewi.putri@justemdl.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'guru', NOW(), NOW()),
('Ahmad Siswa', 'ahmad@justemdl.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'siswa', NOW(), NOW()),
('Siti Siswi', 'siti@justemdl.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'siswa', NOW(), NOW());

-- Sample data for kelas (classes)
INSERT INTO kelas (nama, tingkat, dibuat_pada, diperbarui_pada) VALUES
('X IPA 1', '10', NOW(), NOW()),
('X IPA 2', '10', NOW(), NOW()),
('XI IPA 1', '11', NOW(), NOW()),
('XI IPA 2', '11', NOW(), NOW());

-- Sample data for guru (teachers)
INSERT INTO guru (pengguna_id, nip, telepon, alamat, dibuat_pada, diperbarui_pada) VALUES
(2, '198507132010012001', '081234567890', 'Jl. Guru No. 1', NOW(), NOW()),
(3, '199003242011012002', '081234567891', 'Jl. Guru No. 2', NOW(), NOW());

-- Sample data for siswa (students)
INSERT INTO siswa (pengguna_id, nis, kelas_id, telepon, alamat, dibuat_pada, diperbarui_pada) VALUES
(4, '2024010001', 1, '081234567892', 'Jl. Siswa No. 1', NOW(), NOW()),
(5, '2024010002', 1, '081234567893', 'Jl. Siswa No. 2', NOW(), NOW());

-- Sample data for mata_pelajaran (subjects)
INSERT INTO mata_pelajaran (nama, kode, deskripsi, dibuat_pada, diperbarui_pada) VALUES
('Matematika', 'MTK001', 'Pelajaran Matematika Dasar', NOW(), NOW()),
('Fisika', 'FIS001', 'Pelajaran Fisika Dasar', NOW(), NOW()),
('Biologi', 'BIO001', 'Pelajaran Biologi Dasar', NOW(), NOW()),
('Kimia', 'KIM001', 'Pelajaran Kimia Dasar', NOW(), NOW());

-- Sample data for jadwal (schedules)
INSERT INTO jadwal (kelas_id, mata_pelajaran_id, guru_id, hari, waktu_mulai, waktu_selesai, semester, dibuat_pada, diperbarui_pada) VALUES
(1, 1, 1, 'Senin', '07:00:00', '08:30:00', 'Ganjil', NOW(), NOW()),
(1, 2, 1, 'Selasa', '07:00:00', '08:30:00', 'Ganjil', NOW(), NOW()),
(2, 3, 2, 'Rabu', '07:00:00', '08:30:00', 'Ganjil', NOW(), NOW()),
(2, 4, 2, 'Kamis', '07:00:00', '08:30:00', 'Ganjil', NOW(), NOW());

-- Sample data for materi (materials)
INSERT INTO materi (jadwal_id, judul, konten, dibuat_pada, diperbarui_pada) VALUES
(1, 'Pengenalan Aljabar', 'Materi pengenalan dasar aljabar dan penggunaannya', NOW(), NOW()),
(2, 'Hukum Newton', 'Pengenalan hukum newton dan aplikasinya', NOW(), NOW()),
(3, 'Sel dan Organisme', 'Pengenalan struktur sel dan organisme', NOW(), NOW()),
(4, 'Atom dan Molekul', 'Pengenalan struktur atom dan molekul', NOW(), NOW());

-- Sample data for tugas (assignments)
INSERT INTO tugas (jadwal_id, judul, deskripsi, tanggal_jatuh_tempo, bobot_nilai, semester, dibuat_pada, diperbarui_pada) VALUES
(1, 'Tugas Aljabar 1', 'Kerjakan soal aljabar halaman 10-15', '2024-04-20 23:59:59', 100.00, 'Ganjil', NOW(), NOW()),
(2, 'Praktikum Fisika', 'Laporan praktikum hukum newton', '2024-04-21 23:59:59', 100.00, 'Ganjil', NOW(), NOW()),
(3, 'Laporan Biologi', 'Pengamatan sel hewan dan tumbuhan', '2024-04-22 23:59:59', 100.00, 'Ganjil', NOW(), NOW()),
(4, 'Quiz Kimia', 'Quiz tentang struktur atom', '2024-04-23 23:59:59', 100.00, 'Ganjil', NOW(), NOW());

-- Sample data for pengumpulan_tugas (assignment submissions)
INSERT INTO pengumpulan_tugas (tugas_id, siswa_id, komentar_siswa, status, dikumpulkan_pada, dibuat_pada, diperbarui_pada) VALUES
(1, 1, 'Tugas aljabar sudah selesai', 'belum_dinilai', NOW(), NOW(), NOW()),
(2, 1, 'Laporan praktikum fisika', 'belum_dinilai', NOW(), NOW(), NOW()),
(1, 2, 'Tugas aljabar selesai dikerjakan', 'belum_dinilai', NOW(), NOW(), NOW()),
(2, 2, 'Laporan praktikum selesai', 'belum_dinilai', NOW(), NOW(), NOW());

-- Sample data for nilai (grades)
INSERT INTO nilai (pengumpulan_tugas_id, siswa_id, mata_pelajaran_id, skor, komentar_guru, semester, dinilai_oleh, dinilai_pada, dibuat_pada, diperbarui_pada) VALUES
(1, 1, 1, 85.00, 'Bagus, teruskan!', 'Ganjil', 1, NOW(), NOW(), NOW()),
(2, 1, 2, 90.00, 'Laporan sangat baik', 'Ganjil', 1, NOW(), NOW(), NOW()),
(3, 2, 1, 88.00, 'Pengerjaan rapi', 'Ganjil', 1, NOW(), NOW(), NOW()),
(4, 2, 2, 87.00, 'Laporan lengkap', 'Ganjil', 1, NOW(), NOW(), NOW());

-- Sample data for jenis_penilaian (assessment types)
INSERT INTO jenis_penilaian (nama, bobot, deskripsi, dibuat_pada, diperbarui_pada) VALUES
('Tugas', 30.00, 'Penilaian tugas harian', NOW(), NOW()),
('Quiz', 20.00, 'Penilaian quiz', NOW(), NOW()),
('UTS', 25.00, 'Penilaian Ujian Tengah Semester', NOW(), NOW()),
('UAS', 25.00, 'Penilaian Ujian Akhir Semester', NOW(), NOW());

-- Sample data for rekap_nilai (grade recaps)
INSERT INTO rekap_nilai (siswa_id, mata_pelajaran_id, semester, nilai_akhir, dibuat_pada, diperbarui_pada) VALUES
(1, 1, 'Ganjil', 87.50, NOW(), NOW()),
(1, 2, 'Ganjil', 88.00, NOW(), NOW()),
(2, 1, 'Ganjil', 85.00, NOW(), NOW()),
(2, 2, 'Ganjil', 86.00, NOW(), NOW()); 
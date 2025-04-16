<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/auth.php';
require_once __DIR__ . '/middleware/validation_helper.php';

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

// Verify token and get user data
$user = verifyToken();

switch($method) {
    case 'GET':
        $siswa_id = isset($_GET['siswa_id']) ? $_GET['siswa_id'] : null;
        $mata_pelajaran_id = isset($_GET['mata_pelajaran_id']) ? $_GET['mata_pelajaran_id'] : null;
        $semester = isset($_GET['semester']) ? $_GET['semester'] : null;
        $kelas_id = isset($_GET['kelas_id']) ? $_GET['kelas_id'] : null;

        // Validasi semester jika ada
        if ($semester && !validateSemester($semester)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Format semester tidak valid"]);
            exit;
        }

        // Tentukan query berdasarkan peran pengguna
        if ($user['peran'] === 'guru') {
            // Guru dapat melihat rekap nilai untuk mata pelajaran yang mereka ajar
            $query = "SELECT rn.id, rn.siswa_id, rn.mata_pelajaran_id, rn.semester, 
                            rn.nilai_akhir, rn.dibuat_pada, rn.diperbarui_pada,
                            s.nama as nama_siswa, mp.nama as nama_mata_pelajaran, 
                            k.nama as nama_kelas
                     FROM rekap_nilai rn
                     JOIN siswa s ON rn.siswa_id = s.id
                     JOIN mata_pelajaran mp ON rn.mata_pelajaran_id = mp.id
                     JOIN kelas k ON s.kelas_id = k.id
                     JOIN jadwal j ON j.mata_pelajaran_id = rn.mata_pelajaran_id AND j.kelas_id = k.id
                     WHERE j.guru_id = ?";
            
            $params = [$user['id']];
            $types = "i";
            
            if ($kelas_id) {
                // Validasi kelas
                if (!validateId($kelas_id)) {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "ID kelas tidak valid"]);
                    exit;
                }

                // Verifikasi akses guru ke kelas
                $check_query = "SELECT 1 FROM jadwal WHERE guru_id = ? AND kelas_id = ?";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bind_param("ii", $user['id'], $kelas_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows === 0) {
                    http_response_code(403);
                    echo json_encode(["status" => "error", "message" => "Anda tidak memiliki akses ke kelas ini"]);
                    exit;
                }

                $query .= " AND k.id = ?";
                $params[] = $kelas_id;
                $types .= "i";
            }
        } elseif ($user['peran'] === 'siswa') {
            // Siswa hanya dapat melihat rekap nilai mereka sendiri
            $query = "SELECT rn.id, rn.siswa_id, rn.mata_pelajaran_id, rn.semester, 
                            rn.nilai_akhir, rn.dibuat_pada, rn.diperbarui_pada,
                            mp.nama as nama_mata_pelajaran, k.nama as nama_kelas
                     FROM rekap_nilai rn
                     JOIN mata_pelajaran mp ON rn.mata_pelajaran_id = mp.id
                     JOIN siswa s ON rn.siswa_id = s.id
                     JOIN kelas k ON s.kelas_id = k.id
                     WHERE rn.siswa_id = ?";
            
            $params = [$user['id']];
            $types = "i";
        } elseif ($user['peran'] === 'admin') {
            // Admin dapat melihat semua rekap nilai
            $query = "SELECT rn.id, rn.siswa_id, rn.mata_pelajaran_id, rn.semester, 
                            rn.nilai_akhir, rn.dibuat_pada, rn.diperbarui_pada,
                            s.nama as nama_siswa, mp.nama as nama_mata_pelajaran, 
                            k.nama as nama_kelas
                     FROM rekap_nilai rn
                     JOIN siswa s ON rn.siswa_id = s.id
                     JOIN mata_pelajaran mp ON rn.mata_pelajaran_id = mp.id
                     JOIN kelas k ON s.kelas_id = k.id";
            
            $params = [];
            $types = "";
            
            if ($kelas_id) {
                // Validasi kelas
                if (!validateId($kelas_id)) {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "ID kelas tidak valid"]);
                    exit;
                }
                $query .= " WHERE k.id = ?";
                $params[] = $kelas_id;
                $types .= "i";
            }
        } else {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Akses ditolak"]);
            exit;
        }
        
        // Tambahkan filter opsional
        if ($siswa_id && ($user['peran'] === 'guru' || $user['peran'] === 'admin')) {
            // Validasi siswa
            if (!validateId($siswa_id)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "ID siswa tidak valid"]);
                exit;
            }

            // Verifikasi keberadaan siswa
            $check_query = "SELECT 1 FROM siswa WHERE id = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bind_param("i", $siswa_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "Siswa tidak ditemukan"]);
                exit;
            }

            if (strpos($query, "WHERE") !== false) {
                $query .= " AND rn.siswa_id = ?";
            } else {
                $query .= " WHERE rn.siswa_id = ?";
            }
            $params[] = $siswa_id;
            $types .= "i";
        }
        
        if ($mata_pelajaran_id) {
            // Validasi mata pelajaran
            if (!validateId($mata_pelajaran_id)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "ID mata pelajaran tidak valid"]);
                exit;
            }

            // Verifikasi keberadaan mata pelajaran
            $check_query = "SELECT 1 FROM mata_pelajaran WHERE id = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bind_param("i", $mata_pelajaran_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "Mata pelajaran tidak ditemukan"]);
                exit;
            }

            if (strpos($query, "WHERE") !== false) {
                $query .= " AND rn.mata_pelajaran_id = ?";
            } else {
                $query .= " WHERE rn.mata_pelajaran_id = ?";
            }
            $params[] = $mata_pelajaran_id;
            $types .= "i";
        }
        
        if ($semester) {
            if (strpos($query, "WHERE") !== false) {
                $query .= " AND rn.semester = ?";
            } else {
                $query .= " WHERE rn.semester = ?";
            }
            $params[] = $semester;
            $types .= "s";
        }
        
        // Tentukan pengurutan
        $query .= " ORDER BY rn.nilai_akhir DESC, mp.nama ASC, s.nama ASC";
        
        $stmt = $db->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $rekap_nilai = [];
        while ($row = $result->fetch_assoc()) {
            // Format nilai_akhir sebagai decimal(5,2)
            $row['nilai_akhir'] = number_format((float)$row['nilai_akhir'], 2, '.', '');
            $rekap_nilai[] = $row;
        }
        
        // Jika ingin mendapatkan detail penilaian untuk setiap rekap nilai
        if (isset($_GET['dengan_detail']) && $_GET['dengan_detail'] == 'true') {
            foreach ($rekap_nilai as &$rekap) {
                $rekap['detail_nilai'] = [];
                
                $query = "SELECT n.id, n.pengumpulan_tugas_id, n.siswa_id, 
                                n.mata_pelajaran_id, n.skor, n.komentar_guru,
                                n.semester, n.dinilai_oleh, n.dinilai_pada,
                                n.dibuat_pada, n.diperbarui_pada,
                                t.judul as judul_tugas
                         FROM nilai n
                         JOIN pengumpulan_tugas pt ON n.pengumpulan_tugas_id = pt.id
                         JOIN tugas t ON pt.tugas_id = t.id
                         WHERE n.siswa_id = ?
                         AND n.mata_pelajaran_id = ?
                         AND n.semester = ?
                         ORDER BY n.dinilai_pada DESC";
                $stmt = $db->prepare($query);
                $stmt->bind_param("iis", 
                    $rekap['siswa_id'], 
                    $rekap['mata_pelajaran_id'], 
                    $rekap['semester']
                );
                $stmt->execute();
                $detail_result = $stmt->get_result();
                
                while ($detail = $detail_result->fetch_assoc()) {
                    // Format skor sebagai decimal(5,2)
                    $detail['skor'] = number_format((float)$detail['skor'], 2, '.', '');
                    $rekap['detail_nilai'][] = $detail;
                }
            }
        }
        
        echo json_encode($rekap_nilai);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Metode tidak diizinkan"]);
        break;
}
?> 
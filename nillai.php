<?php
require_once __DIR__ . '/config/cors.php';
setCorsHeaders();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/auth.php';
require_once __DIR__ . '/middleware/validation_helper.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Function to handle and return errors
function returnError($code, $message, $debug = null) {
    http_response_code($code);
    $response = [
        'status' => 'error',
        'message' => $message
    ];
    
    if ($debug !== null) {
        $response['debug'] = $debug;
    }
    
    echo json_encode($response);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    returnError(500, 'Database connection error', $e->getMessage());
}

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verify JWT token
try {
    $user = verifyToken();
} catch (Exception $e) {
    returnError(401, 'Authentication error', $e->getMessage());
}

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents("php://input"), true);

switch($method) {
    case 'GET':
        try {
            $siswa_id = isset($_GET['siswa_id']) ? $_GET['siswa_id'] : null;
            $mata_pelajaran_id = isset($_GET['mata_pelajaran_id']) ? $_GET['mata_pelajaran_id'] : null;
            $pengumpulan_tugas_id = isset($_GET['pengumpulan_tugas_id']) ? $_GET['pengumpulan_tugas_id'] : null;
            $semester = isset($_GET['semester']) ? $_GET['semester'] : null;

            if ($user['peran'] === 'guru') {
                // Get grades for teacher's subjects
                $query = "SELECT n.*, s.nama as nama_siswa, mp.nama as nama_mata_pelajaran,
                         pt.jalur_file as file_tugas, t.judul as judul_tugas
                         FROM nilai n 
                         JOIN siswa s ON n.siswa_id = s.id 
                         JOIN mata_pelajaran mp ON n.mata_pelajaran_id = mp.id 
                         LEFT JOIN pengumpulan_tugas pt ON n.pengumpulan_tugas_id = pt.id
                         LEFT JOIN tugas t ON pt.tugas_id = t.id
                         JOIN jadwal j ON j.mata_pelajaran_id = mp.id 
                         WHERE j.guru_id = ?";
                
                $params = [$user['id']];
                $types = "i";
            } else if ($user['peran'] === 'siswa') {
                // Get student's own grades
                $query = "SELECT n.*, mp.nama as nama_mata_pelajaran, p.nama as nama_guru,
                         pt.jalur_file as file_tugas, t.judul as judul_tugas
                         FROM nilai n 
                         JOIN mata_pelajaran mp ON n.mata_pelajaran_id = mp.id 
                         LEFT JOIN pengumpulan_tugas pt ON n.pengumpulan_tugas_id = pt.id
                         LEFT JOIN tugas t ON pt.tugas_id = t.id
                         LEFT JOIN guru g ON n.dinilai_oleh = g.id
                         LEFT JOIN pengguna p ON g.pengguna_id = p.id
                         WHERE n.siswa_id = ?";
                
                $params = [$user['id']];
                $types = "i";
            } else if ($user['peran'] === 'admin') {
                // Admin can see all grades
                $query = "SELECT n.*, s.nama as nama_siswa, mp.nama as nama_mata_pelajaran,
                         pt.jalur_file as file_tugas, t.judul as judul_tugas
                         FROM nilai n 
                         JOIN siswa s ON n.siswa_id = s.id 
                         JOIN mata_pelajaran mp ON n.mata_pelajaran_id = mp.id
                         LEFT JOIN pengumpulan_tugas pt ON n.pengumpulan_tugas_id = pt.id
                         LEFT JOIN tugas t ON pt.tugas_id = t.id";
                
                $params = [];
                $types = "";
            }
            
            if ($siswa_id) {
                if (empty($params)) {
                    $query .= " WHERE n.siswa_id = ?";
                } else {
                    $query .= " AND n.siswa_id = ?";
                }
                $params[] = $siswa_id;
                $types .= "i";
            }
            
            if ($mata_pelajaran_id) {
                if (empty($params)) {
                    $query .= " WHERE n.mata_pelajaran_id = ?";
                } else {
                    $query .= " AND n.mata_pelajaran_id = ?";
                }
                $params[] = $mata_pelajaran_id;
                $types .= "i";
            }
            
            if ($pengumpulan_tugas_id) {
                if (empty($params)) {
                    $query .= " WHERE n.pengumpulan_tugas_id = ?";
                } else {
                    $query .= " AND n.pengumpulan_tugas_id = ?";
                }
                $params[] = $pengumpulan_tugas_id;
                $types .= "i";
            }
            
            if ($semester) {
                if (!validateSemester($semester)) {
                    returnError(400, "Format semester tidak valid");
                }
                
                if (empty($params)) {
                    $query .= " WHERE n.semester = ?";
                } else {
                    $query .= " AND n.semester = ?";
                }
                $params[] = $semester;
                $types .= "s";
            }
            
            $stmt = $db->prepare($query);
            if (!$stmt) {
                returnError(500, "Prepare statement error", $db->error);
            }
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            if (!$stmt->execute()) {
                returnError(500, "Execute error", $stmt->error);
            }
            
            $result = $stmt->get_result();
            
            $nilai = [];
            while ($row = $result->fetch_assoc()) {
                // Convert numeric score to number format
                $row['skor'] = floatval($row['skor']);
                $nilai[] = $row;
            }
            
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'message' => 'Daftar nilai berhasil diambil',
                'data' => $nilai
            ]);
        } catch (Exception $e) {
            returnError(500, "Error retrieving grades", $e->getMessage());
        }
        break;

    case 'POST':
        try {
            if ($user['peran'] !== 'guru' && $user['peran'] !== 'admin') {
                returnError(403, "Hanya guru yang dapat menambahkan nilai");
            }

            if (!isset($data['siswa_id']) || !isset($data['mata_pelajaran_id']) || 
                !isset($data['semester']) || !isset($data['skor'])) {
                returnError(400, "Semua field harus diisi");
            }

            // Validate semester format
            if (!validateSemester($data['semester'])) {
                returnError(400, "Format semester tidak valid");
            }

            // Validate nilai range
            if (!validateScore($data['skor'])) {
                returnError(400, "Nilai harus antara 0-100");
            }

            // Check if student exists
            $check_siswa = "SELECT id FROM siswa WHERE id = ?";
            $stmt_siswa = $db->prepare($check_siswa);
            $stmt_siswa->bind_param("i", $data['siswa_id']);
            $stmt_siswa->execute();
            $result_siswa = $stmt_siswa->get_result();
            if ($result_siswa->num_rows === 0) {
                returnError(400, "Siswa tidak ditemukan");
            }

            // Check if subject exists and teacher has access
            $check_mapel = "SELECT COUNT(*) as count FROM mata_pelajaran mp 
                           LEFT JOIN jadwal j ON mp.id = j.mata_pelajaran_id 
                           WHERE mp.id = ? AND (j.guru_id = ? OR ? = 'admin')";
            $stmt_mapel = $db->prepare($check_mapel);
            $stmt_mapel->bind_param("iis", $data['mata_pelajaran_id'], $user['id'], $user['peran']);
            $stmt_mapel->execute();
            $result_mapel = $stmt_mapel->get_result();
            $row = $result_mapel->fetch_assoc();
            if ($row['count'] === 0 && $user['peran'] !== 'admin') {
                returnError(400, "Mata pelajaran tidak ditemukan atau Anda tidak memiliki akses");
            }

            // Check for existing combination (excluding pengumpulan_tugas_id)
            $pengumpulan_tugas_id = isset($data['pengumpulan_tugas_id']) ? $data['pengumpulan_tugas_id'] : null;
            
            if ($pengumpulan_tugas_id) {
                // Check if pengumpulan_tugas exists and belongs to the student
                $check_pengumpulan = "SELECT id, siswa_id FROM pengumpulan_tugas WHERE id = ?";
                $stmt_pengumpulan = $db->prepare($check_pengumpulan);
                $stmt_pengumpulan->bind_param("i", $pengumpulan_tugas_id);
                $stmt_pengumpulan->execute();
                $result_pengumpulan = $stmt_pengumpulan->get_result();
                
                if ($result_pengumpulan->num_rows === 0) {
                    returnError(400, "Pengumpulan tugas tidak ditemukan");
                }
                
                $pengumpulan = $result_pengumpulan->fetch_assoc();
                if ($pengumpulan['siswa_id'] != $data['siswa_id']) {
                    returnError(400, "Pengumpulan tugas tidak terkait dengan siswa yang dipilih");
                }
                
                // Check if grade already exists for this pengumpulan_tugas
                $check_existing = "SELECT id FROM nilai WHERE pengumpulan_tugas_id = ?";
                $stmt_existing = $db->prepare($check_existing);
                $stmt_existing->bind_param("i", $pengumpulan_tugas_id);
                $stmt_existing->execute();
                $result_existing = $stmt_existing->get_result();
                
                if ($result_existing->num_rows > 0) {
                    returnError(409, "Nilai untuk tugas ini sudah ada");
                }
            } else {
                // Check if grade already exists for this combination of siswa_id, mata_pelajaran_id, and semester (without tugas)
                $check_existing = "SELECT id FROM nilai 
                                 WHERE siswa_id = ? AND mata_pelajaran_id = ? AND semester = ? AND pengumpulan_tugas_id IS NULL";
                $stmt_existing = $db->prepare($check_existing);
                $stmt_existing->bind_param("iis", $data['siswa_id'], $data['mata_pelajaran_id'], $data['semester']);
                $stmt_existing->execute();
                $result_existing = $stmt_existing->get_result();
                
                if ($result_existing->num_rows > 0) {
                    returnError(409, "Nilai dasar untuk kombinasi ini sudah ada");
                }
            }

            // Begin transaction
            $transaction_started = false;
            try {
                $db->begin_transaction();
                $transaction_started = true;
                
                // Add komentar_guru if provided or set to null
                $komentar = isset($data['komentar_guru']) ? $data['komentar_guru'] : null;
                $dinilai_oleh = $user['id'];
                
                // Prepare query based on whether pengumpulan_tugas_id is provided
                if ($pengumpulan_tugas_id) {
                    $query = "INSERT INTO nilai (pengumpulan_tugas_id, siswa_id, mata_pelajaran_id, skor, 
                             komentar_guru, semester, dinilai_oleh, dinilai_pada, dibuat_pada, diperbarui_pada) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())";
                    $stmt = $db->prepare($query);
                    
                    $stmt->bind_param("iiidssi", 
                        $pengumpulan_tugas_id,
                        $data['siswa_id'], 
                        $data['mata_pelajaran_id'], 
                        $data['skor'],
                        $komentar,
                        $data['semester'],
                        $dinilai_oleh
                    );
                } else {
                    $query = "INSERT INTO nilai (siswa_id, mata_pelajaran_id, skor, 
                             komentar_guru, semester, dinilai_oleh, dinilai_pada, dibuat_pada, diperbarui_pada) 
                             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())";
                    $stmt = $db->prepare($query);
                    
                    $stmt->bind_param("iidssi", 
                        $data['siswa_id'], 
                        $data['mata_pelajaran_id'], 
                        $data['skor'],
                        $komentar,
                        $data['semester'],
                        $dinilai_oleh
                    );
                }
                
                if (!$stmt->execute()) {
                    if ($transaction_started) {
                        $db->rollback();
                    }
                    returnError(500, "Gagal menambahkan nilai", $stmt->error);
                }
                
                $nilai_id = $db->insert_id;
                
                // If this was a grade for a submission, update the submission status
                if ($pengumpulan_tugas_id) {
                    $update_submission = "UPDATE pengumpulan_tugas SET status = 'sudah_dinilai' WHERE id = ?";
                    $stmt_update = $db->prepare($update_submission);
                    $stmt_update->bind_param("i", $pengumpulan_tugas_id);
                    
                    if (!$stmt_update->execute()) {
                        if ($transaction_started) {
                            $db->rollback();
                        }
                        returnError(500, "Gagal memperbarui status pengumpulan", $stmt_update->error);
                    }
                }
                
                if ($transaction_started) {
                    $db->commit();
                }
                
                http_response_code(201);
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Nilai berhasil ditambahkan',
                    'id' => $nilai_id
                ]);
            } catch (Exception $e) {
                if ($transaction_started) {
                    $db->rollback();
                }
                throw $e;
            }
        } catch (Exception $e) {
            returnError(500, "Error creating grade", $e->getMessage());
        }
        break;

    case 'PUT':
        try {
            if ($user['peran'] !== 'guru' && $user['peran'] !== 'admin') {
                returnError(403, "Hanya guru yang dapat memperbarui nilai");
            }

            if (!isset($data['id']) || !isset($data['skor'])) {
                returnError(400, "ID dan nilai harus diisi");
            }

            // Validate nilai range
            if (!validateScore($data['skor'])) {
                returnError(400, "Nilai harus antara 0-100");
            }

            // Check if grade exists and teacher has access
            $check_grade = "SELECT n.siswa_id, n.pengumpulan_tugas_id, COUNT(*) as count 
                           FROM nilai n 
                           LEFT JOIN jadwal j ON n.mata_pelajaran_id = j.mata_pelajaran_id 
                           WHERE n.id = ? AND (j.guru_id = ? OR ? = 'admin')";
            $stmt_grade = $db->prepare($check_grade);
            $stmt_grade->bind_param("iis", $data['id'], $user['id'], $user['peran']);
            $stmt_grade->execute();
            $result_grade = $stmt_grade->get_result();
            $row = $result_grade->fetch_assoc();
            if ($row['count'] === 0) {
                returnError(400, "Nilai tidak ditemukan atau Anda tidak memiliki akses");
            }
            
            $pengumpulan_tugas_id = $row['pengumpulan_tugas_id'];

            // Begin transaction
            $transaction_started = false;
            try {
                $db->begin_transaction();
                $transaction_started = true;

                $query = "UPDATE nilai SET skor = ?, diperbarui_pada = NOW()";
                
                // Add komentar_guru if provided
                if (isset($data['komentar_guru'])) {
                    $query .= ", komentar_guru = ?";
                }
                
                $query .= " WHERE id = ?";
                $stmt = $db->prepare($query);
                
                if (isset($data['komentar_guru'])) {
                    $stmt->bind_param("dsi", $data['skor'], $data['komentar_guru'], $data['id']);
                } else {
                    $stmt->bind_param("di", $data['skor'], $data['id']);
                }
                
                if (!$stmt->execute()) {
                    if ($transaction_started) {
                        $db->rollback();
                    }
                    returnError(500, "Gagal memperbarui nilai", $stmt->error);
                }
                
                // If this was a grade for a submission, ensure the submission status is updated
                if ($pengumpulan_tugas_id) {
                    $update_submission = "UPDATE pengumpulan_tugas SET status = 'sudah_dinilai' WHERE id = ?";
                    $stmt_update = $db->prepare($update_submission);
                    $stmt_update->bind_param("i", $pengumpulan_tugas_id);
                    
                    if (!$stmt_update->execute()) {
                        if ($transaction_started) {
                            $db->rollback();
                        }
                        returnError(500, "Gagal memperbarui status pengumpulan", $stmt_update->error);
                    }
                }
                
                if ($transaction_started) {
                    $db->commit();
                }
                
                http_response_code(200);
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Nilai berhasil diperbarui'
                ]);
            } catch (Exception $e) {
                if ($transaction_started) {
                    $db->rollback();
                }
                throw $e;
            }
        } catch (Exception $e) {
            returnError(500, "Error updating grade", $e->getMessage());
        }
        break;

    case 'DELETE':
        try {
            if ($user['peran'] !== 'guru' && $user['peran'] !== 'admin') {
                returnError(403, "Hanya guru yang dapat menghapus nilai");
            }

            if (!isset($data['id'])) {
                returnError(400, "ID nilai harus diisi");
            }

            // Check if grade exists and teacher has access, also get pengumpulan_tugas_id if exists
            $check_grade = "SELECT n.pengumpulan_tugas_id, COUNT(*) as count 
                           FROM nilai n 
                           LEFT JOIN jadwal j ON n.mata_pelajaran_id = j.mata_pelajaran_id 
                           WHERE n.id = ? AND (j.guru_id = ? OR ? = 'admin')";
            $stmt_grade = $db->prepare($check_grade);
            $stmt_grade->bind_param("iis", $data['id'], $user['id'], $user['peran']);
            $stmt_grade->execute();
            $result_grade = $stmt_grade->get_result();
            $row = $result_grade->fetch_assoc();
            if ($row['count'] === 0) {
                returnError(400, "Nilai tidak ditemukan atau Anda tidak memiliki akses");
            }
            
            $pengumpulan_tugas_id = $row['pengumpulan_tugas_id'];

            // Begin transaction
            $transaction_started = false;
            try {
                $db->begin_transaction();
                $transaction_started = true;

                $query = "DELETE FROM nilai WHERE id = ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param("i", $data['id']);
                
                if (!$stmt->execute()) {
                    if ($transaction_started) {
                        $db->rollback();
                    }
                    returnError(500, "Gagal menghapus nilai", $stmt->error);
                }
                
                // If this was a grade for a submission, reset the submission status
                if ($pengumpulan_tugas_id) {
                    $update_submission = "UPDATE pengumpulan_tugas SET status = 'belum_dinilai' WHERE id = ?";
                    $stmt_update = $db->prepare($update_submission);
                    $stmt_update->bind_param("i", $pengumpulan_tugas_id);
                    
                    if (!$stmt_update->execute()) {
                        if ($transaction_started) {
                            $db->rollback();
                        }
                        returnError(500, "Gagal memperbarui status pengumpulan", $stmt_update->error);
                    }
                }
                
                if ($transaction_started) {
                    $db->commit();
                }
                
                http_response_code(200);
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Nilai berhasil dihapus'
                ]);
            } catch (Exception $e) {
                if ($transaction_started) {
                    $db->rollback();
                }
                throw $e;
            }
        } catch (Exception $e) {
            returnError(500, "Error deleting grade", $e->getMessage());
        }
        break;

    default:
        returnError(405, "Metode tidak diizinkan");
        break;
}
?> 
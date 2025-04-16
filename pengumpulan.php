<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
// ini_set('display_startup_errors', 0); // Also disable startup errors

require_once __DIR__ . '/config/cors.php';
setCorsHeaders();
// Log errors to file
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/auth.php';

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents("php://input"));

// Verify student or teacher role
$user = checkRole(['siswa', 'guru']);
if (!$user) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak. Hanya guru atau siswa yang dapat mengakses endpoint ini.']);
    exit;
}

switch($method) {
    case 'GET':
        // Determine if specific ID is requested
        if (isset($_GET['id'])) {
            $pengumpulan_id = $_GET['id'];
            
            // Prepare query based on role
            if ($user['peran'] === 'guru') {
                // Guru can view any submission for tasks in their schedule
                $query = "SELECT p.*, s.nama as nama_siswa, t.judul as judul_tugas 
                         FROM pengumpulan_tugas p 
                         JOIN siswa s ON p.siswa_id = s.id 
                         JOIN tugas t ON p.tugas_id = t.id
                         JOIN jadwal j ON t.jadwal_id = j.id
                         JOIN guru g ON j.guru_id = g.id
                         WHERE p.id = ? AND g.pengguna_id = ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param('ii', $pengumpulan_id, $user['id']);
            } else {
                // Siswa can only view their own submissions
                $query = "SELECT p.*, s.nama as nama_siswa, t.judul as judul_tugas 
                         FROM pengumpulan_tugas p 
                         JOIN siswa s ON p.siswa_id = s.id 
                         JOIN tugas t ON p.tugas_id = t.id
                         WHERE p.id = ? AND s.pengguna_id = ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param('ii', $pengumpulan_id, $user['id']);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Pengumpulan tugas tidak ditemukan']);
                exit;
            }
            
            $pengumpulan = $result->fetch_assoc();
            echo json_encode(['status' => 'success', 'data' => $pengumpulan]);
        } 
        // Get submissions based on tugas_id
        elseif (isset($_GET['tugas_id'])) {
            $tugas_id = $_GET['tugas_id'];
            
            // Prepare query based on role
            if ($user['peran'] === 'guru') {
                // Get guru_id first
                $guru_query = "SELECT id FROM guru WHERE pengguna_id = ?";
                $guru_stmt = $db->prepare($guru_query);
                if ($guru_stmt === false) {
                    http_response_code(500);
                    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
                    exit;
                }
                $guru_stmt->bind_param('i', $user['id']);
                $guru_stmt->execute();
                $guru_result = $guru_stmt->get_result();
                
                if ($guru_result->num_rows === 0) {
                    // Return empty result if no guru found
                    echo json_encode(['status' => 'success', 'data' => []]);
                    exit;
                }
                
                $guru = $guru_result->fetch_assoc();
                $guru_id = $guru['id'];
                
                // Guru can view all submissions for a task that belongs to them
                $query = "SELECT p.*, t.judul as judul_tugas, p.siswa_id
                         FROM pengumpulan_tugas p 
                         JOIN tugas t ON p.tugas_id = t.id
                         JOIN jadwal j ON t.jadwal_id = j.id
                         WHERE p.tugas_id = ? AND j.guru_id = ?";
                $stmt = $db->prepare($query);
                if ($stmt === false) {
                    http_response_code(500);
                    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
                    exit;
                }
                $stmt->bind_param('ii', $tugas_id, $guru_id);
            } else {
                // Siswa can only view their own submissions for a task
                $query = "SELECT p.*, t.judul as judul_tugas 
                         FROM pengumpulan_tugas p 
                         JOIN siswa s ON p.siswa_id = s.id 
                         JOIN tugas t ON p.tugas_id = t.id
                         WHERE p.tugas_id = ? AND s.pengguna_id = ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param('ii', $tugas_id, $user['id']);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            $pengumpulan = [];
            while ($row = $result->fetch_assoc()) {
                $pengumpulan[] = $row;
            }
            
            echo json_encode(['status' => 'success', 'data' => $pengumpulan]);
        }
        // Get submissions based on siswa_id
        elseif (isset($_GET['siswa_id'])) {
            $siswa_id = $_GET['siswa_id'];
            
            // Siswa can only view their own submissions
            if ($user['peran'] === 'siswa') {
                $query = "SELECT p.*, t.judul as judul_tugas 
                         FROM pengumpulan_tugas p 
                         JOIN siswa s ON p.siswa_id = s.id 
                         JOIN tugas t ON p.tugas_id = t.id
                         WHERE s.id = ? AND s.pengguna_id = ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param('ii', $siswa_id, $user['id']);
            } else {
                // Guru can view submissions from students in their classes
                $query = "SELECT p.*, t.judul as judul_tugas 
                         FROM pengumpulan_tugas p 
                         JOIN siswa s ON p.siswa_id = s.id 
                         JOIN tugas t ON p.tugas_id = t.id
                         JOIN jadwal j ON t.jadwal_id = j.id
                         JOIN guru g ON j.guru_id = g.id
                         WHERE s.id = ? AND g.pengguna_id = ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param('ii', $siswa_id, $user['id']);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            $pengumpulan = [];
            while ($row = $result->fetch_assoc()) {
                $pengumpulan[] = $row;
            }
            
            echo json_encode(['status' => 'success', 'data' => $pengumpulan]);
        }
        // Get all submissions for the user
        else {
            if ($user['peran'] === 'guru') {
                // Guru gets all submissions for tasks in their schedule
                // Get guru_id first
                $guru_query = "SELECT id FROM guru WHERE pengguna_id = ?";
                $guru_stmt = $db->prepare($guru_query);
                if ($guru_stmt === false) {
                    http_response_code(500);
                    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
                    exit;
                }
                $guru_stmt->bind_param('i', $user['id']);
                $guru_stmt->execute();
                $guru_result = $guru_stmt->get_result();
                
                if ($guru_result->num_rows === 0) {
                    // Return empty result if no guru found
                    echo json_encode(['status' => 'success', 'data' => []]);
                    exit;
                }
                
                $guru = $guru_result->fetch_assoc();
                $guru_id = $guru['id'];
                
                // Simplified query to avoid issues - remove s.nama which causes an error
                $query = "SELECT p.*, t.judul as judul_tugas 
                         FROM pengumpulan_tugas p 
                         JOIN siswa s ON p.siswa_id = s.id 
                         JOIN tugas t ON p.tugas_id = t.id
                         JOIN jadwal j ON t.jadwal_id = j.id
                         WHERE j.guru_id = ?";
                $stmt = $db->prepare($query);
                if ($stmt === false) {
                    http_response_code(500);
                    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
                    exit;
                }
                $stmt->bind_param('i', $guru_id);
            } else {
                // Siswa gets their own submissions
                $query = "SELECT p.*, t.judul as judul_tugas 
                         FROM pengumpulan_tugas p 
                         JOIN siswa s ON p.siswa_id = s.id 
                         JOIN tugas t ON p.tugas_id = t.id
                         WHERE s.pengguna_id = ?";
                $stmt = $db->prepare($query);
                if ($stmt === false) {
                    http_response_code(500);
                    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
                    exit;
                }
                $stmt->bind_param('i', $user['id']);
            }
            
            $stmt->execute();
            if ($stmt->error) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]);
                exit;
            }
            $result = $stmt->get_result();
            
            $pengumpulan = [];
            while ($row = $result->fetch_assoc()) {
                $pengumpulan[] = $row;
            }
            
            echo json_encode(['status' => 'success', 'data' => $pengumpulan]);
        }
        break;

    case 'POST':
        // Only students can submit tasks
        if ($user['peran'] !== 'siswa') {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Akses ditolak. Hanya siswa yang dapat mengumpulkan tugas.']);
            exit;
        }

        // Get POST data
        $data = isset($_POST) && !empty($_POST) ? $_POST : json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        if (!isset($data['tugas_id'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'ID tugas wajib diisi']);
            exit;
        }

        // Get student ID from database based on pengguna_id
        $query = "SELECT id, kelas_id FROM siswa WHERE pengguna_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Data siswa tidak ditemukan']);
            exit;
        }
        
        $siswa = $result->fetch_assoc();
        $siswa_id = $siswa['id'];
        $kelas_id = $siswa['kelas_id'];

        // Verify that the tugas is for this student's class
        $query = "SELECT t.id FROM tugas t 
                 JOIN jadwal j ON t.jadwal_id = j.id 
                 WHERE t.id = ? AND j.kelas_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('ii', $data['tugas_id'], $kelas_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Tugas ini tidak tersedia untuk kelas Anda']);
            exit;
        }

        // Check if already submitted
        $query = "SELECT id FROM pengumpulan_tugas WHERE siswa_id = ? AND tugas_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('ii', $siswa_id, $data['tugas_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $pengumpulan = $result->fetch_assoc();
            $pengumpulan_id = $pengumpulan['id'];
            
            // Update existing submission
            $query = "UPDATE pengumpulan_tugas SET 
                     komentar_siswa = ?, 
                     status = 'belum_dinilai',
                     dikumpulkan_pada = CURRENT_TIMESTAMP, 
                     diperbarui_pada = CURRENT_TIMESTAMP 
                     WHERE id = ?";
            $stmt = $db->prepare($query);
            
            $komentar = isset($data['komentar_siswa']) ? $data['komentar_siswa'] : null;
            $stmt->bind_param('si', $komentar, $pengumpulan_id);
            
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Pengumpulan tugas berhasil diperbarui', 'id' => $pengumpulan_id]);
            } else {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Gagal memperbarui pengumpulan tugas']);
            }
            
            exit;
        }

        // Handle file upload if provided
        $jalur_file = null;
        
        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $uploaded_file = $_FILES['file'];
            $allowed_types = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'txt'];
            $file_ext = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($file_ext, $allowed_types)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Tipe file tidak diizinkan']);
                exit;
            }
            
            $jalur_file = 'pengumpulan/' . uniqid() . '_' . $siswa_id . '.' . $file_ext;
            
            if (!move_uploaded_file($uploaded_file['tmp_name'], '../storage/' . $jalur_file)) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Gagal mengupload file']);
                exit;
            }
        }

        // Insert new submission
        $query = "INSERT INTO pengumpulan_tugas (tugas_id, siswa_id, jalur_file, komentar_siswa, status, dikumpulkan_pada, dibuat_pada, diperbarui_pada) 
                 VALUES (?, ?, ?, ?, 'belum_dinilai', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
        $stmt = $db->prepare($query);
        
        $komentar = isset($data['komentar_siswa']) ? $data['komentar_siswa'] : null;
        $stmt->bind_param('iiss', $data['tugas_id'], $siswa_id, $jalur_file, $komentar);
        
        if ($stmt->execute()) {
            $pengumpulan_id = $db->insert_id;
            echo json_encode(['status' => 'success', 'message' => 'Pengumpulan tugas berhasil', 'id' => $pengumpulan_id]);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Gagal mengumpulkan tugas']);
        }
        break;
        
    case 'PUT':
        if ($user['peran'] === 'guru') {
            // Teachers can update submission status (grading)
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['id'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'ID pengumpulan tugas wajib diisi']);
                exit;
            }

            // Check if the teacher has access to this submission
            $query = "SELECT COUNT(*) as count FROM pengumpulan_tugas p 
                     JOIN tugas t ON p.tugas_id = t.id 
                     JOIN jadwal j ON t.jadwal_id = j.id 
                     JOIN guru g ON j.guru_id = g.id 
                     WHERE p.id = ? AND g.pengguna_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param('ii', $data['id'], $user['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            if ($row['count'] == 0) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Tidak memiliki akses untuk mengubah pengumpulan tugas ini']);
                exit;
            }

            // Update submission status
            $query = "UPDATE pengumpulan_tugas SET 
                     status = ?, 
                     diperbarui_pada = CURRENT_TIMESTAMP 
                     WHERE id = ?";
            $stmt = $db->prepare($query);
            
            $status = isset($data['status']) ? $data['status'] : 'belum_dinilai';
            $stmt->bind_param('si', $status, $data['id']);
            
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Status pengumpulan tugas berhasil diperbarui']);
            } else {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Gagal memperbarui status pengumpulan tugas']);
            }
        } 
        elseif ($user['peran'] === 'siswa') {
            // Students can update their own submissions
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['id'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'ID pengumpulan tugas wajib diisi']);
                exit;
            }

            // Get student ID from database
            $query = "SELECT id FROM siswa WHERE pengguna_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param('i', $user['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Data siswa tidak ditemukan']);
                exit;
            }
            
            $siswa = $result->fetch_assoc();
            $siswa_id = $siswa['id'];

            // Check if the student owns this submission
            $query = "SELECT COUNT(*) as count FROM pengumpulan_tugas WHERE id = ? AND siswa_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param('ii', $data['id'], $siswa_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            if ($row['count'] == 0) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Tidak memiliki akses untuk mengubah pengumpulan tugas ini']);
                exit;
            }

            // Update submission
            $query = "UPDATE pengumpulan_tugas SET 
                     komentar_siswa = ?, 
                     status = 'belum_dinilai',
                     dikumpulkan_pada = CURRENT_TIMESTAMP,
                     diperbarui_pada = CURRENT_TIMESTAMP 
                     WHERE id = ?";
            $stmt = $db->prepare($query);
            
            $komentar = isset($data['komentar_siswa']) ? $data['komentar_siswa'] : null;
            $stmt->bind_param('si', $komentar, $data['id']);
            
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Pengumpulan tugas berhasil diperbarui']);
            } else {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Gagal memperbarui pengumpulan tugas']);
            }
        } 
        else {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Akses ditolak']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Metode tidak diizinkan']);
        break;
}
?> 
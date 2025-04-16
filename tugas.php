<?php
// Tambahkan error reporting untuk debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/cors.php';
setCorsHeaders();

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/auth.php';
require_once __DIR__ . '/middleware/validation_helper.php';

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

// Debug incoming request
$method = $_SERVER['REQUEST_METHOD'];
error_log("Incoming request method: " . $method);
error_log("Request data: " . print_r($_POST, true));
error_log("Request files: " . (isset($_FILES) ? print_r($_FILES, true) : "No files"));

// Check HTTP headers for method override (common in API clients)
function getRequestHeaders() {
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
    }
    return $headers;
}

$headers = getRequestHeaders();
error_log("Request headers: " . print_r($headers, true));

if (isset($headers['X-HTTP-Method-Override'])) {
    $original_method = $method;
    $method = strtoupper($headers['X-HTTP-Method-Override']);
    error_log("Method overridden from $original_method to: $method via X-HTTP-Method-Override header");
}

// Check for _method field in POST for PUT/DELETE requests
if ($method === 'POST' && isset($_POST['_method'])) {
    $method = strtoupper($_POST['_method']);
    error_log("Method overridden from POST to: " . $method);
}

// For multipart form data, check if we have ID and are missing method
if ($method === 'POST' && !isset($_POST['_method'])) {
    // Check if we have the ID and are missing the method
    if (isset($_POST['id'])) {
        error_log("ID found in POST data without _method, assuming PUT");
        $method = 'PUT';
    }
}

try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    returnError(500, 'Database connection error', $e->getMessage());
}

// Verify JWT token
try {
    $user = checkRole(['guru', 'siswa']);
    if (!$user) {
        returnError(403, 'Akses ditolak. Hanya guru atau siswa yang dapat mengakses endpoint ini.');
    }
} catch (Exception $e) {
    returnError(401, 'Authentication error', $e->getMessage());
}

// More detailed debug info
if ($method === 'PUT') {
    error_log("Processing PUT request with data: " . print_r($_POST, true));
    if (isset($_POST['id'])) {
        error_log("Updating tugas with ID: " . $_POST['id']);
    } else {
        error_log("Missing ID for update operation!");
    }
}

// Jika metode bukan GET, verifikasi bahwa user adalah guru
if ($method !== 'GET' && $user['peran'] !== 'guru') {
    returnError(403, 'Akses ditolak. Hanya guru yang dapat mengubah tugas.');
}

// Jika guru, ambil tugas berdasarkan jadwal yang dia ajar
// Jika siswa, ambil tugas berdasarkan kelasnya
switch($method) {
    case 'GET':
        // Jika guru, ambil tugas berdasarkan jadwal yang dia ajar
        if ($user['peran'] === 'guru') {
            $query = "SELECT t.*, mp.nama as nama_mata_pelajaran, k.nama as nama_kelas 
                     FROM tugas t 
                     JOIN jadwal j ON t.jadwal_id = j.id 
                     JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id 
                     JOIN kelas k ON j.kelas_id = k.id 
                     WHERE j.guru_id = ?";
            $stmt = $db->prepare($query);
            if ($stmt === false) {
                returnError(500, 'Database query error: ' . $db->error);
            }
            $stmt->bind_param('i', $user['id']);
        } else {
            // Jika siswa, ambil tugas berdasarkan kelasnya
            $query = "SELECT t.*, j.hari, j.waktu_mulai, j.waktu_selesai, k.nama as nama_kelas, 
                            mp.nama as nama_mata_pelajaran, g.pengguna_id, p.nama as nama_guru 
                     FROM tugas t 
                     JOIN jadwal j ON t.jadwal_id = j.id 
                     JOIN kelas k ON j.kelas_id = k.id 
                     JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id 
                     JOIN guru g ON j.guru_id = g.id
                     JOIN pengguna p ON g.pengguna_id = p.id
                     JOIN siswa s ON s.kelas_id = j.kelas_id
                     WHERE s.pengguna_id = ?";
            $stmt = $db->prepare($query);
            if ($stmt === false) {
                returnError(500, 'Database query error: ' . $db->error);
            }
            $stmt->bind_param('i', $user['id']);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tugas = [];
        while ($row = $result->fetch_assoc()) {
            $tugas[] = $row;
        }
        
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Daftar tugas berhasil diambil',
            'data' => $tugas
        ]);
        break;

    case 'POST':
        try {
            // Parse JSON input for regular POST data
            $json = null;
            $data = null;
            
            if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
                $json = file_get_contents('php://input');
                if (!$json) {
                    returnError(400, 'Invalid JSON input');
                }
                
                $data = json_decode($json, true);
                if (!$data) {
                    returnError(400, 'Failed to parse JSON: ' . json_last_error_msg());
                }
            } else {
                // For multipart/form-data (file uploads)
                $data = $_POST;
            }

            // Validasi input
            if (!isset($data['jadwal_id']) || !isset($data['judul']) || !isset($data['deskripsi']) || 
                !isset($data['tanggal_jatuh_tempo']) || !isset($data['semester'])) {
                returnError(400, 'Semua field wajib diisi', $data);
            }

            // Validasi format semester
            if (!validateSemester($data['semester'])) {
                returnError(400, 'Format semester tidak valid. Gunakan format "Ganjil YYYY/YYYY" atau "Genap YYYY/YYYY"');
            }

            // Validasi bobot nilai
            if (isset($data['bobot_nilai']) && !validateDecimal($data['bobot_nilai'], 5, 2)) {
                returnError(400, 'Bobot nilai harus berupa angka desimal dengan maksimal 5 digit dan 2 angka di belakang koma');
            }

            // Validasi jadwal
            $check_query = "SELECT COUNT(*) as count FROM jadwal j JOIN guru g ON j.guru_id = g.id WHERE j.id = ? AND g.pengguna_id = ?";
            $stmt = $db->prepare($check_query);
            $stmt->bind_param('ii', $data['jadwal_id'], $user['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            if ($row['count'] == 0) {
                returnError(400, 'Jadwal tidak ditemukan atau tidak memiliki akses');
            }

            // Handle file upload
            $file = null;
            $tipe_file = null;
            $ukuran_file = null;
            
            if (isset($_FILES['file'])) {
                $uploaded_file = $_FILES['file'];
                $allowed_types = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'mp4', 'mp3'];
                $file_ext = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
                
                if (!in_array($file_ext, $allowed_types)) {
                    returnError(400, 'Tipe file tidak diizinkan');
                }
                
                $file = 'tugas/' . uniqid() . '.' . $file_ext;
                $tipe_file = $file_ext;
                $ukuran_file = $uploaded_file['size'];
                
                if (!move_uploaded_file($uploaded_file['tmp_name'], '../storage/' . $file)) {
                    returnError(500, 'Gagal mengupload file');
                }
            }

            // Set bobot nilai default jika tidak disediakan
            $bobot_nilai = isset($data['bobot_nilai']) ? $data['bobot_nilai'] : 100.00;

            $db->begin_transaction();
            
            if ($file) {
                $query = "INSERT INTO tugas (jadwal_id, judul, deskripsi, file, tipe_file, ukuran_file, tanggal_jatuh_tempo, bobot_nilai, semester, dibuat_pada, diperbarui_pada) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
                $stmt = $db->prepare($query);
                
                if ($stmt === false) {
                    throw new Exception("Prepare failed: " . $db->error);
                }
                
                // Konversi ke tipe data yang benar
                $jadwal_id = intval($data['jadwal_id']);
                $bobot_nilai = floatval($bobot_nilai);
                $ukuran_file = intval($ukuran_file);
                
                $bind_result = $stmt->bind_param('issssdss', 
                    $jadwal_id, 
                    $data['judul'], 
                    $data['deskripsi'],
                    $file,
                    $tipe_file,
                    $ukuran_file,
                    $data['tanggal_jatuh_tempo'],
                    $bobot_nilai,
                    $data['semester']
                );
            } else {
                $query = "INSERT INTO tugas (jadwal_id, judul, deskripsi, tanggal_jatuh_tempo, bobot_nilai, semester, dibuat_pada, diperbarui_pada) 
                        VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
                $stmt = $db->prepare($query);
                
                if ($stmt === false) {
                    throw new Exception("Prepare failed: " . $db->error);
                }
                
                // Konversi ke tipe data yang benar
                $jadwal_id = intval($data['jadwal_id']);
                $bobot_nilai = floatval($bobot_nilai);
                
                $bind_result = $stmt->bind_param('issdss', 
                    $jadwal_id, 
                    $data['judul'], 
                    $data['deskripsi'],
                    $data['tanggal_jatuh_tempo'],
                    $bobot_nilai,
                    $data['semester']
                );
            }
            
            if ($bind_result === false) {
                throw new Exception("Bind failed: " . $stmt->error);
            }
            
            if ($stmt->execute()) {
                $task_id = $db->insert_id;
                
                // Get created task details
                $get_query = "SELECT t.*, j.hari, j.waktu_mulai, j.waktu_selesai, j.semester,
                             mp.nama as nama_mata_pelajaran, k.nama as nama_kelas,
                             g.id as guru_id, p.nama as nama_guru
                           FROM tugas t 
                           JOIN jadwal j ON t.jadwal_id = j.id
                           JOIN guru g ON j.guru_id = g.id
                           JOIN pengguna p ON g.pengguna_id = p.id
                           JOIN kelas k ON j.kelas_id = k.id 
                           JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id 
                           WHERE t.id = ?";
                           
                $get_stmt = $db->prepare($get_query);
                if (!$get_stmt) {
                    throw new Exception("Prepare statement error in get_query: " . $db->error);
                }
                
                $get_stmt->bind_param('i', $task_id);
                if (!$get_stmt->execute()) {
                    throw new Exception("Execute error in get_query: " . $get_stmt->error);
                }
                
                $get_result = $get_stmt->get_result();
                $tugas = $get_result->fetch_assoc();
                
                $db->commit();
                
                http_response_code(201);
                echo json_encode([
                    'status' => 'success', 
                    'message' => 'Tugas berhasil ditambahkan', 
                    'data' => $tugas
                ]);
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            if (isset($db) && $db->ping()) {
                $db->rollback();
            }
            returnError(500, 'Gagal menambahkan tugas: ' . $e->getMessage());
        }
        break;

    case 'PUT':
        // Only teachers can update tasks
        if ($user['peran'] !== 'guru') {
            returnError(403, 'Akses ditolak. Hanya guru yang dapat mengubah tugas.');
        }

        // Check if data is coming from application/json or form-data
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
        
        if (strpos($contentType, 'application/json') !== false) {
            // Parse JSON data
            $data = json_decode(file_get_contents('php://input'), true);
        } else {
            // Get form data
            $data = $_POST;
        }
        
        if (!isset($data['id'])) {
            returnError(400, 'ID tugas wajib diisi');
        }

        // Validasi input
        if (!isset($data['jadwal_id']) || !isset($data['judul']) || !isset($data['deskripsi']) || 
            !isset($data['tanggal_jatuh_tempo']) || !isset($data['semester'])) {
            returnError(400, 'Semua field wajib diisi');
        }

        // Validasi format semester
        if (!validateSemester($data['semester'])) {
            returnError(400, 'Format semester tidak valid. Gunakan format "Ganjil YYYY/YYYY" atau "Genap YYYY/YYYY"');
        }

        // Validasi bobot nilai
        if (isset($data['bobot_nilai']) && !validateDecimal($data['bobot_nilai'], 5, 2)) {
            returnError(400, 'Bobot nilai harus berupa angka desimal dengan maksimal 5 digit dan 2 angka di belakang koma');
        }

        // Validasi jadwal dan akses guru
        $check_query = "SELECT COUNT(*) as count FROM tugas t 
                       JOIN jadwal j ON t.jadwal_id = j.id 
                       JOIN guru g ON j.guru_id = g.id
                       WHERE t.id = ? AND g.pengguna_id = ?";
        $stmt = $db->prepare($check_query);
        $stmt->bind_param('ii', $data['id'], $user['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        if ($row['count'] == 0) {
            returnError(403, 'Tidak memiliki akses untuk mengubah tugas ini');
        }

        // Handle file upload
        $file = null;
        $tipe_file = null;
        $ukuran_file = null;
        
        if (isset($_FILES['file'])) {
            $uploaded_file = $_FILES['file'];
            $allowed_types = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'mp4', 'mp3'];
            $file_ext = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($file_ext, $allowed_types)) {
                returnError(400, 'Tipe file tidak diizinkan');
            }
            
            $file = 'tugas/' . uniqid() . '.' . $file_ext;
            $tipe_file = $file_ext;
            $ukuran_file = $uploaded_file['size'];
            
            if (!move_uploaded_file($uploaded_file['tmp_name'], '../storage/' . $file)) {
                returnError(500, 'Gagal mengupload file');
            }
        }

        try {
            // Jika tidak ada file baru, pertahankan file yang ada
            if (!$file) {
                $query = "UPDATE tugas SET 
                        jadwal_id = ?,
                        judul = ?,
                        deskripsi = ?,
                        tanggal_jatuh_tempo = ?,
                        bobot_nilai = ?,
                        semester = ?,
                        diperbarui_pada = CURRENT_TIMESTAMP
                        WHERE id = ?";
                $stmt = $db->prepare($query);
                
                if ($stmt === false) {
                    throw new Exception("Prepare failed: " . $db->error);
                }
                
                // Set bobot nilai default jika tidak disediakan
                $bobot_nilai = isset($data['bobot_nilai']) ? $data['bobot_nilai'] : 100.00;
                
                // Konversi ke tipe data yang benar
                $jadwal_id = intval($data['jadwal_id']);
                $bobot_nilai = floatval($bobot_nilai);
                $tugas_id = intval($data['id']);
                
                $bind_result = $stmt->bind_param('issdssi', 
                    $jadwal_id,
                    $data['judul'],
                    $data['deskripsi'],
                    $data['tanggal_jatuh_tempo'],
                    $bobot_nilai,
                    $data['semester'],
                    $tugas_id
                );
                
                if ($bind_result === false) {
                    throw new Exception("Bind failed: " . $stmt->error);
                }
            } else {
                // Update dengan file baru
                $query = "UPDATE tugas SET 
                        jadwal_id = ?,
                        judul = ?,
                        deskripsi = ?,
                        file = ?,
                        tipe_file = ?,
                        ukuran_file = ?,
                        tanggal_jatuh_tempo = ?,
                        bobot_nilai = ?,
                        semester = ?,
                        diperbarui_pada = CURRENT_TIMESTAMP
                        WHERE id = ?";
                $stmt = $db->prepare($query);
                
                if ($stmt === false) {
                    throw new Exception("Prepare failed: " . $db->error);
                }
                
                // Set bobot nilai default jika tidak disediakan
                $bobot_nilai = isset($data['bobot_nilai']) ? $data['bobot_nilai'] : 100.00;
                
                // Konversi ke tipe data yang benar
                $jadwal_id = intval($data['jadwal_id']);
                $bobot_nilai = floatval($bobot_nilai);
                $ukuran_file = $ukuran_file !== null ? intval($ukuran_file) : null;
                $tugas_id = intval($data['id']);
                
                $bind_result = $stmt->bind_param('issssidssi', 
                    $jadwal_id,
                    $data['judul'],
                    $data['deskripsi'],
                    $file,
                    $tipe_file,
                    $ukuran_file,
                    $data['tanggal_jatuh_tempo'],
                    $bobot_nilai,
                    $data['semester'],
                    $tugas_id
                );
                
                if ($bind_result === false) {
                    throw new Exception("Bind failed: " . $stmt->error);
                }
            }
            
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Tugas berhasil diperbarui']);
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            returnError(500, 'Gagal memperbarui tugas: ' . $e->getMessage());
        }
        break;

    case 'DELETE':
        // Only teachers can delete tasks
        if ($user['peran'] !== 'guru') {
            returnError(403, 'Akses ditolak. Hanya guru yang dapat menghapus tugas.');
        }

        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['id'])) {
            // Coba ambil dari URL
            $tugas_id = isset($_GET['id']) ? $_GET['id'] : null;
            
            if (!$tugas_id) {
                returnError(400, 'ID tugas wajib diisi');
            }
        } else {
            $tugas_id = $data['id'];
        }

        // Validasi akses guru
        $check_query = "SELECT COUNT(*) as count FROM tugas t 
                       JOIN jadwal j ON t.jadwal_id = j.id 
                       JOIN guru g ON j.guru_id = g.id
                       WHERE t.id = ? AND g.pengguna_id = ?";
        $stmt = $db->prepare($check_query);
        $stmt->bind_param('ii', $tugas_id, $user['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        if ($row['count'] == 0) {
            returnError(403, 'Tidak memiliki akses untuk menghapus tugas ini');
        }

        try {
            $query = "DELETE FROM tugas WHERE id = ?";
            $stmt = $db->prepare($query);
            
            if ($stmt === false) {
                throw new Exception("Prepare failed: " . $db->error);
            }
            
            // Konversi ke integer
            $tugas_id = intval($tugas_id);
            
            $bind_result = $stmt->bind_param('i', $tugas_id);
            
            if ($bind_result === false) {
                throw new Exception("Bind failed: " . $stmt->error);
            }
            
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Tugas berhasil dihapus']);
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            returnError(500, 'Gagal menghapus tugas: ' . $e->getMessage());
        }
        break;

    default:
        returnError(405, 'Metode tidak diizinkan');
        break;
}
?> 
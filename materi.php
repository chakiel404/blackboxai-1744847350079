<?php
require_once __DIR__ . '/config/cors.php';
setCorsHeaders();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/auth.php';

// Enable error reporting for debugging (can be removed in production)
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

// FIX: Hanya batasi akses untuk POST, PUT, DELETE (bukan GET)
$method = $_SERVER['REQUEST_METHOD'];

// Debug incoming request
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

// For multipart form data, we need to check for contents as well
if ($method === 'POST' && !isset($_POST['_method'])) {
    // Check if we have the ID and are missing the method
    if (isset($_POST['id'])) {
        error_log("ID found in POST data without _method, assuming PUT");
        $method = 'PUT';
    }
}

// More detailed debug info
if ($method === 'PUT') {
    error_log("Processing PUT request with data: " . print_r($_POST, true));
    if (isset($_POST['id'])) {
        error_log("Updating material with ID: " . $_POST['id']);
    } else {
        error_log("Missing ID for update operation!");
    }
}

// Jika metode bukan GET, verifikasi bahwa user adalah guru atau admin
if ($method !== 'GET' && $user['peran'] !== 'guru' && $user['peran'] !== 'admin') {
    returnError(403, 'Akses ditolak. Hanya guru yang dapat mengubah materi.');
}

// FIX: Get the guru_id for the authenticated teacher (if applicable)
$guru_id = null;
$siswa_id = null;

if ($user['peran'] === 'guru') {
    $query = "SELECT id FROM guru WHERE pengguna_id = ?";
    $stmt = $db->prepare($query);
    if (!$stmt) {
        returnError(500, 'Database error preparing guru query', $db->error);
    }
    
    $stmt->bind_param('i', $user['id']);
    if (!$stmt->execute()) {
        returnError(500, 'Database error executing guru query', $stmt->error);
    }
    
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        returnError(403, 'Akses ditolak. Data guru tidak ditemukan.');
    }
    
    $guru_data = $result->fetch_assoc();
    $guru_id = $guru_data['id'];
} else if ($user['peran'] === 'siswa') {
    $query = "SELECT id FROM siswa WHERE pengguna_id = ?";
    $stmt = $db->prepare($query);
    if (!$stmt) {
        returnError(500, 'Database error preparing siswa query', $db->error);
    }
    
    $stmt->bind_param('i', $user['id']);
    if (!$stmt->execute()) {
        returnError(500, 'Database error executing siswa query', $stmt->error);
    }
    
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        returnError(403, 'Akses ditolak. Data siswa tidak ditemukan.');
    }
    
    $siswa_data = $result->fetch_assoc();
    $siswa_id = $siswa_data['id'];
}

switch($method) {
    case 'GET':
        try {
            getAllMaterials($db, $user, $guru_id, $siswa_id);
        } catch (Exception $e) {
            returnError(500, 'Error retrieving materials', $e->getMessage());
        }
        break;

    case 'POST':
        try {
            addMaterial($db, $user, $guru_id);
        } catch (Exception $e) {
            returnError(500, 'Error creating material', $e->getMessage());
        }
        break;

    case 'PUT':
        try {
            updateMaterial($db, $user, $guru_id);
        } catch (Exception $e) {
            returnError(500, 'Error updating material', $e->getMessage());
        }
        break;

    case 'DELETE':
        try {
            deleteMaterial($db, $user, $guru_id);
        } catch (Exception $e) {
            returnError(500, 'Error deleting material', $e->getMessage());
        }
        break;

    default:
        returnError(405, 'Metode tidak diizinkan');
        break;
}

function getAllMaterials($db, $user, $guru_id, $siswa_id) {
    try {
        // If guru, get materials based on schedules they teach
        if ($user['peran'] === 'guru') {
            $query = "SELECT m.*, j.hari, j.waktu_mulai, j.waktu_selesai, 
                         p.nama as nama_guru, g.id as guru_id,
                         k.nama as nama_kelas, mp.nama as nama_mata_pelajaran 
                   FROM materi m 
                   JOIN jadwal j ON m.jadwal_id = j.id
                   JOIN guru g ON j.guru_id = g.id
                   JOIN pengguna p ON g.pengguna_id = p.id
                   JOIN kelas k ON j.kelas_id = k.id 
                   JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id 
                   WHERE j.guru_id = ?";
            $stmt = $db->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare statement error: " . $db->error);
            }
            
            $stmt->bind_param('i', $guru_id); // FIX: Use guru_id instead of user['id']
        } else if ($user['peran'] === 'admin') {
            // Admin can see all materials
            $query = "SELECT m.*, j.hari, j.waktu_mulai, j.waktu_selesai, 
                         p.nama as nama_guru, g.id as guru_id,
                         k.nama as nama_kelas, mp.nama as nama_mata_pelajaran 
                   FROM materi m 
                   JOIN jadwal j ON m.jadwal_id = j.id
                   JOIN guru g ON j.guru_id = g.id
                   JOIN pengguna p ON g.pengguna_id = p.id
                   JOIN kelas k ON j.kelas_id = k.id 
                   JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id";
            $stmt = $db->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare statement error: " . $db->error);
            }
        } else if ($user['peran'] === 'siswa') {
            // If siswa, get materials based on their class
            $query = "SELECT m.*, j.hari, j.waktu_mulai, j.waktu_selesai,
                         p.nama as nama_guru, g.id as guru_id,
                         k.nama as nama_kelas, mp.nama as nama_mata_pelajaran 
                   FROM materi m 
                   JOIN jadwal j ON m.jadwal_id = j.id
                   JOIN guru g ON j.guru_id = g.id
                   JOIN pengguna p ON g.pengguna_id = p.id
                   JOIN kelas k ON j.kelas_id = k.id 
                   JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id 
                   JOIN siswa s ON k.id = s.kelas_id
                   WHERE s.id = ?";
            $stmt = $db->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare statement error: " . $db->error);
            }
            
            $stmt->bind_param('i', $siswa_id);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Execute error: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        
        $materials = [];
        while ($row = $result->fetch_assoc()) {
            $materials[] = $row;
        }
        
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Daftar materi berhasil diambil',
            'data' => $materials
        ]);
    } catch (Exception $e) {
        throw new Exception("Error in getAllMaterials: " . $e->getMessage());
    }
}

function addMaterial($db, $user, $guru_id) {
    try {
        $transaction_started = false;
        
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
        
        // Validate input
        if (!isset($data['judul']) || !isset($data['deskripsi']) || !isset($data['jadwal_id'])) {
            returnError(400, 'Judul, deskripsi, dan jadwal_id harus diisi');
        }

        // Verify the schedule belongs to the teacher
        $check_schedule = "SELECT id FROM jadwal WHERE id = ? AND guru_id = ?";
        $stmt_check = $db->prepare($check_schedule);
        if (!$stmt_check) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $stmt_check->bind_param('ii', $data['jadwal_id'], $guru_id); // FIX: Use guru_id instead of user['id']
        if (!$stmt_check->execute()) {
            throw new Exception("Execute error in check_schedule: " . $stmt_check->error);
        }
        
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows === 0) {
            returnError(403, 'Jadwal tidak ditemukan atau Anda tidak memiliki akses');
        }

        // Handle file upload
        $file = null;
        
        if (isset($_FILES['file'])) {
            $uploaded_file = $_FILES['file'];
            
            // Debug file upload info
            error_log("File upload received: " . print_r($uploaded_file, true));
            error_log("File name: " . $uploaded_file['name']);
            error_log("File type: " . $uploaded_file['type']);
            error_log("File size: " . $uploaded_file['size']);
            error_log("File tmp_name: " . $uploaded_file['tmp_name']);
            error_log("File error: " . $uploaded_file['error']);
            
            // Check for upload errors
            if ($uploaded_file['error'] !== UPLOAD_ERR_OK) {
                // Handle specific upload errors
                $error_message = 'Unknown error';
                switch ($uploaded_file['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $error_message = 'File too large';
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $error_message = 'File only partially uploaded';
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $error_message = 'No file was uploaded';
                        break;
                    case UPLOAD_ERR_NO_TMP_DIR:
                        $error_message = 'Missing temporary folder';
                        break;
                    case UPLOAD_ERR_CANT_WRITE:
                        $error_message = 'Failed to write file to disk';
                        break;
                    case UPLOAD_ERR_EXTENSION:
                        $error_message = 'A PHP extension stopped the file upload';
                        break;
                }
                error_log("File upload error: " . $error_message);
                returnError(400, 'File upload error: ' . $error_message);
            }
            
            $allowed_types = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'mp4', 'mp3'];
            $file_ext = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
            
            error_log("File extension: " . $file_ext);
            
            if (!in_array($file_ext, $allowed_types)) {
                error_log("File type not allowed: " . $file_ext);
                returnError(400, 'Tipe file tidak diizinkan');
            }
            
            $file = 'materi/' . uniqid() . '.' . $file_ext;
            error_log("Generated file path: " . $file);
            
            // Make sure the directory exists
            $storage_dir = './storage/materi';
            error_log("Storage directory: " . $storage_dir);
            
            if (!is_dir($storage_dir)) {
                error_log("Creating directory: " . $storage_dir);
                $mkdir_result = mkdir($storage_dir, 0777, true);
                error_log("mkdir result: " . ($mkdir_result ? 'success' : 'failed'));
                
                if (!$mkdir_result) {
                    error_log("Failed to create directory: " . error_get_last()['message']);
                    returnError(500, 'Failed to create storage directory');
                }
            }
            
            $full_path = './storage/' . $file;
            error_log("Full path for file: " . $full_path);
            
            if (!move_uploaded_file($uploaded_file['tmp_name'], $full_path)) {
                error_log("Failed to move uploaded file: " . error_get_last()['message']);
                error_log("Source file exists: " . (file_exists($uploaded_file['tmp_name']) ? 'yes' : 'no'));
                error_log("Destination folder writable: " . (is_writable(dirname($full_path)) ? 'yes' : 'no'));
                returnError(500, 'Gagal mengunggah file');
            }
            
            error_log("File successfully moved to: " . $full_path);
            error_log("File exists: " . (file_exists($full_path) ? 'yes' : 'no'));
        }

        // Begin transaction
        $db->begin_transaction();
        $transaction_started = true;

        $query = "INSERT INTO materi (judul, konten, jalur_file, jadwal_id, dibuat_pada, diperbarui_pada) 
                  VALUES (?, ?, ?, ?, NOW(), NOW())";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $stmt->bind_param('sssi', 
            $data['judul'],
            $data['deskripsi'],
            $file,
            $data['jadwal_id']
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Execute error in INSERT: " . $stmt->error);
        }
        
        $material_id = $db->insert_id;
        
        // Get the created material
        $get_query = "SELECT m.*, j.hari, j.waktu_mulai, j.waktu_selesai, j.semester,
                           p.nama as nama_guru, g.id as guru_id,
                           k.nama as nama_kelas, mp.nama as nama_mata_pelajaran 
                     FROM materi m 
                     JOIN jadwal j ON m.jadwal_id = j.id
                     JOIN guru g ON j.guru_id = g.id
                     JOIN pengguna p ON g.pengguna_id = p.id
                     JOIN kelas k ON j.kelas_id = k.id 
                     JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id 
                     WHERE m.id = ?";
        $get_stmt = $db->prepare($get_query);
        if (!$get_stmt) {
            throw new Exception("Prepare statement error in get_query: " . $db->error);
        }
        
        $get_stmt->bind_param('i', $material_id);
        if (!$get_stmt->execute()) {
            throw new Exception("Execute error in get_query: " . $get_stmt->error);
        }
        
        $get_result = $get_stmt->get_result();
        $material = $get_result->fetch_assoc();
        
        // Commit transaction
        $db->commit();
        $transaction_started = false;
        
        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Materi berhasil ditambahkan',
            'data' => $material
        ]);
    } catch (Exception $e) {
        // Rollback on error
        if ($transaction_started) {
            $db->rollback();
        }
        
        // Delete uploaded file if exists
        if (isset($file) && !empty($file) && file_exists('./storage/' . $file)) {
            unlink('./storage/' . $file);
        }
        
        throw new Exception("Error in addMaterial: " . $e->getMessage());
    }
}

function updateMaterial($db, $user, $guru_id) {
    try {
        $transaction_started = false;
        
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
        
        if (!isset($data['id'])) {
            returnError(400, 'ID materi harus disediakan');
        }

        // Validate input
        if (!isset($data['judul']) || !isset($data['deskripsi']) || !isset($data['jadwal_id'])) {
            returnError(400, 'Judul, deskripsi, dan jadwal_id harus diisi');
        }

        // Verify guru has access to this material through schedule
        $check_query = "SELECT COUNT(*) as count 
                       FROM materi m 
                       JOIN jadwal j ON m.jadwal_id = j.id 
                       WHERE m.id = ? AND j.guru_id = ?";
        $stmt = $db->prepare($check_query);
        if (!$stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $stmt->bind_param('ii', $data['id'], $guru_id); // FIX: Use guru_id instead of user['id']
        if (!$stmt->execute()) {
            throw new Exception("Execute error in check_query: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        if ($row['count'] == 0) {
            returnError(400, 'Materi tidak ditemukan atau Anda tidak memiliki akses');
        }

        // Verify the new schedule belongs to the teacher
        $check_schedule = "SELECT id FROM jadwal WHERE id = ? AND guru_id = ?";
        $stmt_check = $db->prepare($check_schedule);
        if (!$stmt_check) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $stmt_check->bind_param('ii', $data['jadwal_id'], $guru_id); // FIX: Use guru_id instead of user['id']
        if (!$stmt_check->execute()) {
            throw new Exception("Execute error in check_schedule: " . $stmt_check->error);
        }
        
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows === 0) {
            returnError(403, 'Jadwal tidak ditemukan atau Anda tidak memiliki akses');
        }

        // Get the old file path to delete if new file is uploaded
        $old_file_query = "SELECT jalur_file FROM materi WHERE id = ?";
        $old_file_stmt = $db->prepare($old_file_query);
        if (!$old_file_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $old_file_stmt->bind_param('i', $data['id']);
        if (!$old_file_stmt->execute()) {
            throw new Exception("Execute error in old_file_query: " . $old_file_stmt->error);
        }
        
        $old_file_result = $old_file_stmt->get_result();
        $old_file_row = $old_file_result->fetch_assoc();
        $old_file = $old_file_row['jalur_file'];

        // Handle file upload
        $file = null;
        
        if (isset($_FILES['file']) && $_FILES['file']['size'] > 0) {
            $uploaded_file = $_FILES['file'];
            
            // Debug file upload info for update
            error_log("UPDATE - File upload received: " . print_r($uploaded_file, true));
            error_log("UPDATE - File name: " . $uploaded_file['name']);
            error_log("UPDATE - File type: " . $uploaded_file['type']);
            error_log("UPDATE - File size: " . $uploaded_file['size']);
            error_log("UPDATE - File tmp_name: " . $uploaded_file['tmp_name']);
            error_log("UPDATE - File error: " . $uploaded_file['error']);
            
            // Check for upload errors
            if ($uploaded_file['error'] !== UPLOAD_ERR_OK) {
                // Handle specific upload errors
                $error_message = 'Unknown error';
                switch ($uploaded_file['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $error_message = 'File too large';
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $error_message = 'File only partially uploaded';
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $error_message = 'No file was uploaded';
                        break;
                    case UPLOAD_ERR_NO_TMP_DIR:
                        $error_message = 'Missing temporary folder';
                        break;
                    case UPLOAD_ERR_CANT_WRITE:
                        $error_message = 'Failed to write file to disk';
                        break;
                    case UPLOAD_ERR_EXTENSION:
                        $error_message = 'A PHP extension stopped the file upload';
                        break;
                }
                error_log("UPDATE - File upload error: " . $error_message);
                returnError(400, 'File upload error: ' . $error_message);
            }
            
            $allowed_types = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'mp4', 'mp3'];
            $file_ext = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
            
            error_log("UPDATE - File extension: " . $file_ext);
            
            if (!in_array($file_ext, $allowed_types)) {
                error_log("UPDATE - File type not allowed: " . $file_ext);
                returnError(400, 'Tipe file tidak diizinkan');
            }
            
            $file = 'materi/' . uniqid() . '.' . $file_ext;
            error_log("UPDATE - Generated file path: " . $file);
            
            // Make sure the directory exists
            $storage_dir = './storage/materi';
            error_log("UPDATE - Storage directory: " . $storage_dir);
            
            if (!is_dir($storage_dir)) {
                error_log("UPDATE - Creating directory: " . $storage_dir);
                $mkdir_result = mkdir($storage_dir, 0777, true);
                error_log("UPDATE - mkdir result: " . ($mkdir_result ? 'success' : 'failed'));
                
                if (!$mkdir_result) {
                    error_log("UPDATE - Failed to create directory: " . error_get_last()['message']);
                    returnError(500, 'Failed to create storage directory');
                }
            }
            
            $full_path = './storage/' . $file;
            error_log("UPDATE - Full path for file: " . $full_path);
            
            if (!move_uploaded_file($uploaded_file['tmp_name'], $full_path)) {
                error_log("UPDATE - Failed to move uploaded file: " . error_get_last()['message']);
                error_log("UPDATE - Source file exists: " . (file_exists($uploaded_file['tmp_name']) ? 'yes' : 'no'));
                error_log("UPDATE - Destination folder writable: " . (is_writable(dirname($full_path)) ? 'yes' : 'no'));
                returnError(500, 'Gagal mengunggah file');
            }
            
            error_log("UPDATE - File successfully moved to: " . $full_path);
            error_log("UPDATE - File exists: " . (file_exists($full_path) ? 'yes' : 'no'));
        }

        // Begin transaction
        $db->begin_transaction();
        $transaction_started = true;

        $query = "UPDATE materi SET 
                  judul = ?, 
                  konten = ?, 
                  jalur_file = COALESCE(?, jalur_file),
                  jadwal_id = ?,
                  diperbarui_pada = NOW() 
                  WHERE id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $stmt->bind_param('sssii', 
            $data['judul'],
            $data['deskripsi'],
            $file,
            $data['jadwal_id'],
            $data['id']
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Execute error in UPDATE: " . $stmt->error);
        }
        
        // Get the updated material
        $get_query = "SELECT m.*, j.hari, j.waktu_mulai, j.waktu_selesai, j.semester,
                           p.nama as nama_guru, g.id as guru_id,
                           k.nama as nama_kelas, mp.nama as nama_mata_pelajaran 
                     FROM materi m 
                     JOIN jadwal j ON m.jadwal_id = j.id
                     JOIN guru g ON j.guru_id = g.id
                     JOIN pengguna p ON g.pengguna_id = p.id
                     JOIN kelas k ON j.kelas_id = k.id 
                     JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id 
                     WHERE m.id = ?";
        $get_stmt = $db->prepare($get_query);
        if (!$get_stmt) {
            throw new Exception("Prepare statement error in get_query: " . $db->error);
        }
        
        $get_stmt->bind_param('i', $data['id']);
        if (!$get_stmt->execute()) {
            throw new Exception("Execute error in get_query: " . $get_stmt->error);
        }
        
        $get_result = $get_stmt->get_result();
        $material = $get_result->fetch_assoc();
        
        // Delete old file if we uploaded a new one
        if ($file && !empty($old_file) && file_exists('./storage/' . $old_file)) {
            unlink('./storage/' . $old_file);
        }
        
        // Commit transaction
        $db->commit();
        $transaction_started = false;
        
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Materi berhasil diperbarui',
            'data' => $material
        ]);
    } catch (Exception $e) {
        // Rollback on error
        if ($transaction_started) {
            $db->rollback();
        }
        
        // Delete uploaded file if exists and there was an error
        if (isset($file) && !empty($file) && file_exists('./storage/' . $file)) {
            unlink('./storage/' . $file);
        }
        
        throw new Exception("Error in updateMaterial: " . $e->getMessage());
    }
}

function deleteMaterial($db, $user, $guru_id) {
    try {
        $transaction_started = false;
        
        $json = file_get_contents('php://input');
        if (!$json) {
            returnError(400, 'Invalid JSON input');
        }
        
        $data = json_decode($json, true);
        if (!$data) {
            returnError(400, 'Failed to parse JSON: ' . json_last_error_msg());
        }
        
        if (!isset($data['id'])) {
            returnError(400, 'ID materi harus disediakan');
        }

        // Verify guru has access to this material through schedule
        $check_query = "SELECT COUNT(*) as count 
                       FROM materi m 
                       JOIN jadwal j ON m.jadwal_id = j.id 
                       WHERE m.id = ? AND j.guru_id = ?";
        $stmt = $db->prepare($check_query);
        if (!$stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $stmt->bind_param('ii', $data['id'], $guru_id); // FIX: Use guru_id instead of user['id']
        if (!$stmt->execute()) {
            throw new Exception("Execute error in check_query: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        if ($row['count'] == 0) {
            returnError(400, 'Materi tidak ditemukan atau Anda tidak memiliki akses');
        }

        // Get file path to delete
        $query = "SELECT jalur_file FROM materi WHERE id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $stmt->bind_param('i', $data['id']);
        if (!$stmt->execute()) {
            throw new Exception("Execute error in file path query: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $file = $row['jalur_file'];

        // Begin transaction
        $db->begin_transaction();
        $transaction_started = true;

        // Delete material
        $query = "DELETE FROM materi WHERE id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $stmt->bind_param('i', $data['id']);
        if (!$stmt->execute()) {
            throw new Exception("Execute error in DELETE: " . $stmt->error);
        }
        
        // Commit transaction
        $db->commit();
        $transaction_started = false;
        
        // Delete file if exists
        if (!empty($file) && file_exists('./storage/' . $file)) {
            unlink('./storage/' . $file);
        }
        
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Materi berhasil dihapus'
        ]);
    } catch (Exception $e) {
        // Rollback on error
        if ($transaction_started) {
            $db->rollback();
        }
        
        throw new Exception("Error in deleteMaterial: " . $e->getMessage());
    }
}
?> 
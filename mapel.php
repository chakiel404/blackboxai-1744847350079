<?php
require_once __DIR__ . '/config/cors.php';
setCorsHeaders();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/auth.php';

// Disable direct error display - log errors instead
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

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
    
    // Fix any null diperbarui_pada values
    $fix_query = "UPDATE mata_pelajaran SET diperbarui_pada = dibuat_pada WHERE diperbarui_pada IS NULL";
    $db->query($fix_query);
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
    verifyToken();
} catch (Exception $e) {
    returnError(401, 'Authentication error', $e->getMessage());
}

$method = $_SERVER['REQUEST_METHOD'];

// Get ID from query string if available
$id = isset($_GET['id']) ? $_GET['id'] : null;

switch($method) {
    case 'GET':
        try {
            if ($id) {
                getMapelById($db, $id);
            } else {
                getAllMapel($db);
            }
        } catch (Exception $e) {
            returnError(500, 'Error retrieving subjects', $e->getMessage());
        }
        break;
        
    case 'POST':
        try {
            addMapel($db);
        } catch (Exception $e) {
            returnError(500, 'Error creating subject', $e->getMessage());
        }
        break;
        
    case 'PUT':
        try {
            updateMapel($db);
        } catch (Exception $e) {
            returnError(500, 'Error updating subject', $e->getMessage());
        }
        break;
        
    case 'DELETE':
        try {
            deleteMapel($db);
        } catch (Exception $e) {
            returnError(500, 'Error deleting subject', $e->getMessage());
        }
        break;
        
    default:
        returnError(405, 'Metode tidak diizinkan');
        break;
}

// Get all subjects
function getAllMapel($db) {
    // Get role and teacher ID from query parameters
    $role = isset($_GET['role']) ? $_GET['role'] : 'admin';
    $guruId = isset($_GET['guru_id']) ? (int)$_GET['guru_id'] : null;
    
    // Handle pagination
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $perHalaman = isset($_GET['perHalaman']) ? (int)$_GET['perHalaman'] : 10;
    
    // Safety check for pagination values
    if ($page < 1) $page = 1;
    if ($perHalaman < 1) $perHalaman = 10;
    if ($perHalaman > 100) $perHalaman = 100; // limit to prevent large queries
    
    $offset = ($page - 1) * $perHalaman;
    
    try {
        // Build base query according to role
        if ($role === 'guru' && $guruId) {
            // For teachers, get only their assigned subjects
            $count_query = "SELECT COUNT(DISTINCT mp.id) as total 
                          FROM mata_pelajaran mp
                          INNER JOIN guru_mata_pelajaran gmp ON mp.id = gmp.mata_pelajaran_id
                          WHERE gmp.guru_id = ?";
            
            $count_stmt = $db->prepare($count_query);
            if (!$count_stmt) {
                throw new Exception("Prepare statement error in count query: " . $db->error);
            }
            
            $count_stmt->bind_param("i", $guruId);
            if (!$count_stmt->execute()) {
                throw new Exception("Execute error in count query: " . $count_stmt->error);
            }
            
            $count_result = $count_stmt->get_result();
            $total_records = $count_result->fetch_assoc()['total'];
            
            // Main query for teachers
            $query = "SELECT DISTINCT mp.* 
                    FROM mata_pelajaran mp
                    INNER JOIN guru_mata_pelajaran gmp ON mp.id = gmp.mata_pelajaran_id
                    WHERE gmp.guru_id = ?
                    ORDER BY mp.nama ASC
                    LIMIT ? OFFSET ?";
            
            $stmt = $db->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare statement error: " . $db->error);
            }
            
            $stmt->bind_param("iii", $guruId, $perHalaman, $offset);
        } else {
            // For admin, get all subjects
            $count_query = "SELECT COUNT(*) as total FROM mata_pelajaran";
            $count_result = $db->query($count_query);
            if (!$count_result) {
                throw new Exception("Error in count query: " . $db->error);
            }
            $total_records = $count_result->fetch_assoc()['total'];
            
            // Main query for admin
            $query = "SELECT * FROM mata_pelajaran ORDER BY nama ASC LIMIT ? OFFSET ?";
            $stmt = $db->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare statement error: " . $db->error);
            }
            
            $stmt->bind_param("ii", $perHalaman, $offset);
        }
        
        // Execute the query
        if (!$stmt->execute()) {
            throw new Exception("Execute error: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $mapels = [];
        
        while ($mapel = $result->fetch_assoc()) {
            $mapels[] = $mapel;
        }
        
        $total_pages = ceil($total_records / $perHalaman);
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Daftar mata pelajaran berhasil diambil',
            'data' => $mapels,
            'pagination' => [
                'totalRecords' => $total_records,
                'totalPages' => $total_pages,
                'currentPage' => $page,
                'perHalaman' => $perHalaman
            ]
        ]);
        
    } catch (Exception $e) {
        returnError(500, 'Gagal mengambil daftar mata pelajaran', $e->getMessage());
    }
}

// Get subject by ID
function getMapelById($db, $id) {
    try {
        $query = "SELECT * FROM mata_pelajaran WHERE id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            throw new Exception("Execute error: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            returnError(404, 'Mata pelajaran tidak ditemukan');
        }
        
        $subject = $result->fetch_assoc();
        
        // Ensure no null values
        $subject['nama'] = $subject['nama'] ?? "";
        $subject['kode'] = $subject['kode'] ?? "";
        $subject['deskripsi'] = $subject['deskripsi'] ?? "";
        $subject['dibuat_pada'] = $subject['dibuat_pada'] ?? date("Y-m-d H:i:s");
        $subject['diperbarui_pada'] = $subject['diperbarui_pada'] ?? date("Y-m-d H:i:s");
        
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Data mata pelajaran berhasil diambil',
            'data' => $subject
        ]);
    } catch (Exception $e) {
        throw new Exception("Database error in getMapelById: " . $e->getMessage());
    }
}

// Add new subject
function addMapel($db) {
    try {
        // Use this flag for transaction management
        $transaction_started = false;
        
        $json = file_get_contents("php://input");
        if (!$json) {
            returnError(400, 'Invalid JSON input');
        }
        
        $data = json_decode($json);
        if (!$data) {
            returnError(400, 'Failed to parse JSON: ' . json_last_error_msg());
        }
        
        // Validate input
        if (empty($data->nama)) {
            returnError(400, 'Nama mata pelajaran harus diisi');
        }
        
        if (empty($data->kode)) {
            returnError(400, 'Kode mata pelajaran harus diisi');
        }
        
        // Check if kode already exists
        $check_query = "SELECT COUNT(*) as count FROM mata_pelajaran WHERE kode = ?";
        $check_stmt = $db->prepare($check_query);
        if (!$check_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $check_stmt->bind_param("s", $data->kode);
        if (!$check_stmt->execute()) {
            throw new Exception("Execute error: " . $check_stmt->error);
        }
        
        $check_result = $check_stmt->get_result();
        $kode_count = $check_result->fetch_assoc()['count'];
        
        if ($kode_count > 0) {
            returnError(400, 'Kode mata pelajaran sudah terdaftar');
        }
        
        // Begin transaction
        $db->beginTransaction();
        $transaction_started = true;
        
        $deskripsi = !empty($data->deskripsi) ? $data->deskripsi : null;
        
        $query = "INSERT INTO mata_pelajaran (nama, kode, deskripsi, dibuat_pada, diperbarui_pada) 
                  VALUES (?, ?, ?, NOW(), NOW())";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $stmt->bind_param("sss", $data->nama, $data->kode, $deskripsi);
        if (!$stmt->execute()) {
            throw new Exception("Execute error: " . $stmt->error);
        }
        
        $subject_id = $db->insert_id;
        
        // Get the created subject
        $get_query = "SELECT * FROM mata_pelajaran WHERE id = ?";
        $get_stmt = $db->prepare($get_query);
        if (!$get_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $get_stmt->bind_param("i", $subject_id);
        if (!$get_stmt->execute()) {
            throw new Exception("Execute error: " . $get_stmt->error);
        }
        
        $get_result = $get_stmt->get_result();
        $subject = $get_result->fetch_assoc();
        
        // Ensure no null values
        $subject['nama'] = $subject['nama'] ?? "";
        $subject['kode'] = $subject['kode'] ?? "";
        $subject['deskripsi'] = $subject['deskripsi'] ?? "";
        $subject['dibuat_pada'] = $subject['dibuat_pada'] ?? date("Y-m-d H:i:s");
        $subject['diperbarui_pada'] = $subject['diperbarui_pada'] ?? date("Y-m-d H:i:s");
        
        // Commit transaction
        $db->commit();
        $transaction_started = false;
        
        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Mata pelajaran berhasil ditambahkan',
            'data' => $subject
        ]);
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($transaction_started) {
            $db->rollback();
        }
        throw new Exception("Database error in addMapel: " . $e->getMessage());
    }
}

// Update subject
function updateMapel($db) {
    try {
        // Use this flag for transaction management
        $transaction_started = false;
        
        $json = file_get_contents("php://input");
        if (!$json) {
            returnError(400, 'Invalid JSON input');
        }
        
        $data = json_decode($json);
        if (!$data) {
            returnError(400, 'Failed to parse JSON: ' . json_last_error_msg());
        }
        
        // Validate input
        if (empty($data->id) || empty($data->nama) || empty($data->kode)) {
            returnError(400, 'ID, nama, dan kode mata pelajaran harus diisi');
        }
        
        // Check if subject exists
        $check_query = "SELECT * FROM mata_pelajaran WHERE id = ?";
        $check_stmt = $db->prepare($check_query);
        if (!$check_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $check_stmt->bind_param("i", $data->id);
        if (!$check_stmt->execute()) {
            throw new Exception("Execute error: " . $check_stmt->error);
        }
        
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows === 0) {
            returnError(404, 'Mata pelajaran tidak ditemukan');
        }
        
        // Check if kode already exists (but not for this record)
        $check_kode_query = "SELECT COUNT(*) as count FROM mata_pelajaran WHERE kode = ? AND id != ?";
        $check_kode_stmt = $db->prepare($check_kode_query);
        if (!$check_kode_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $check_kode_stmt->bind_param("si", $data->kode, $data->id);
        if (!$check_kode_stmt->execute()) {
            throw new Exception("Execute error: " . $check_kode_stmt->error);
        }
        
        $check_kode_result = $check_kode_stmt->get_result();
        $kode_count = $check_kode_result->fetch_assoc()['count'];
        
        if ($kode_count > 0) {
            returnError(400, 'Kode mata pelajaran sudah terdaftar');
        }
        
        // Begin transaction
        $db->beginTransaction();
        $transaction_started = true;
        
        $deskripsi = !empty($data->deskripsi) ? $data->deskripsi : null;
        
        $query = "UPDATE mata_pelajaran SET 
                 nama = ?,
                 kode = ?,
                 deskripsi = ?,
                 diperbarui_pada = NOW()
                 WHERE id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $stmt->bind_param("sssi", $data->nama, $data->kode, $deskripsi, $data->id);
        if (!$stmt->execute()) {
            throw new Exception("Execute error: " . $stmt->error);
        }
        
        // Get the updated subject
        $get_query = "SELECT * FROM mata_pelajaran WHERE id = ?";
        $get_stmt = $db->prepare($get_query);
        if (!$get_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $get_stmt->bind_param("i", $data->id);
        if (!$get_stmt->execute()) {
            throw new Exception("Execute error: " . $get_stmt->error);
        }
        
        $get_result = $get_stmt->get_result();
        $subject = $get_result->fetch_assoc();
        
        // Ensure no null values
        $subject['nama'] = $subject['nama'] ?? "";
        $subject['kode'] = $subject['kode'] ?? "";
        $subject['deskripsi'] = $subject['deskripsi'] ?? "";
        $subject['dibuat_pada'] = $subject['dibuat_pada'] ?? date("Y-m-d H:i:s");
        $subject['diperbarui_pada'] = $subject['diperbarui_pada'] ?? date("Y-m-d H:i:s");
        
        // Commit transaction
        $db->commit();
        $transaction_started = false;
        
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Mata pelajaran berhasil diperbarui',
            'data' => $subject
        ]);
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($transaction_started) {
            $db->rollback();
        }
        throw new Exception("Database error in updateMapel: " . $e->getMessage());
    }
}

// Delete subject
function deleteMapel($db) {
    try {
        // Use this flag for transaction management
        $transaction_started = false;
        
        $json = file_get_contents("php://input");
        if (!$json) {
            returnError(400, 'Invalid JSON input');
        }
        
        $data = json_decode($json);
        if (!$data) {
            returnError(400, 'Failed to parse JSON: ' . json_last_error_msg());
        }
        
        // Validate input
        if (empty($data->id)) {
            returnError(400, 'ID mata pelajaran harus diisi');
        }
        
        // Check if subject exists
        $check_query = "SELECT * FROM mata_pelajaran WHERE id = ?";
        $check_stmt = $db->prepare($check_query);
        if (!$check_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $check_stmt->bind_param("i", $data->id);
        if (!$check_stmt->execute()) {
            throw new Exception("Execute error: " . $check_stmt->error);
        }
        
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows === 0) {
            returnError(404, 'Mata pelajaran tidak ditemukan');
        }
        
        // Check if subject is used in other tables (e.g., jadwal)
        $check_jadwal_query = "SELECT COUNT(*) as count FROM jadwal WHERE mata_pelajaran_id = ?";
        $check_jadwal_stmt = $db->prepare($check_jadwal_query);
        if (!$check_jadwal_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $check_jadwal_stmt->bind_param("i", $data->id);
        if (!$check_jadwal_stmt->execute()) {
            throw new Exception("Execute error: " . $check_jadwal_stmt->error);
        }
        
        $check_jadwal_result = $check_jadwal_stmt->get_result();
        $jadwal_count = $check_jadwal_result->fetch_assoc()['count'];
        
        if ($jadwal_count > 0) {
            returnError(400, 'Mata pelajaran tidak dapat dihapus karena masih digunakan di jadwal');
        }
        
        // Begin transaction
        $db->beginTransaction();
        $transaction_started = true;
        
        $query = "DELETE FROM mata_pelajaran WHERE id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $stmt->bind_param("i", $data->id);
        if (!$stmt->execute()) {
            throw new Exception("Execute error: " . $stmt->error);
        }
        
        // Commit transaction
        $db->commit();
        $transaction_started = false;
        
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Mata pelajaran berhasil dihapus'
        ]);
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($transaction_started) {
            $db->rollback();
        }
        throw new Exception("Database error in deleteMapel: " . $e->getMessage());
    }
}
?> 
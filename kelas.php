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
    verifyToken();
} catch (Exception $e) {
    returnError(401, 'Authentication error', $e->getMessage());
}

$method = $_SERVER['REQUEST_METHOD'];

// Get ID from query string if available
$id = isset($_GET['id']) ? $_GET['id'] : null;

// Support method override from POST (mobile app compatibility)
$json = file_get_contents("php://input");
if ($method === 'POST' && $json) {
    $data = json_decode($json);
    if ($data && isset($data->_method)) {
        if (in_array(strtoupper($data->_method), ['PUT', 'DELETE'])) {
            $method = strtoupper($data->_method);
            // If ID is sent in the body but not in URL, use it
            if (!$id && isset($data->id)) {
                $id = $data->id;
            }
        }
    }
}

switch($method) {
    case 'GET':
        try {
            if ($id) {
                getClassById($db, $id);
            } else {
                getClasses($db);
            }
        } catch (Exception $e) {
            returnError(500, 'Error retrieving classes', $e->getMessage());
        }
        break;
        
    case 'POST':
        try {
            addClass($db);
        } catch (Exception $e) {
            returnError(500, 'Error creating class', $e->getMessage());
        }
        break;
        
    case 'PUT':
        try {
            updateClass($db);
        } catch (Exception $e) {
            returnError(500, 'Error updating class', $e->getMessage());
        }
        break;
        
    case 'DELETE':
        try {
            deleteClass($db);
        } catch (Exception $e) {
            returnError(500, 'Error deleting class', $e->getMessage());
        }
        break;
        
    default:
        returnError(405, 'Metode tidak diizinkan');
        break;
}

// Get all classes
function getClasses($db) {
    try {
        $query = "SELECT * FROM kelas";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Execute error: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        
        $classes = [];
        while ($row = $result->fetch_assoc()) {
            $classes[] = $row;
        }
        
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Daftar kelas berhasil diambil',
            'data' => $classes
        ]);
    } catch (Exception $e) {
        throw new Exception("Database error in getClasses: " . $e->getMessage());
    }
}

// Get class by ID
function getClassById($db, $id) {
    try {
        $query = "SELECT * FROM kelas WHERE id = ?";
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
            returnError(404, 'Kelas tidak ditemukan');
        }
        
        $class = $result->fetch_assoc();
        
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Data kelas berhasil diambil',
            'data' => $class
        ]);
    } catch (Exception $e) {
        throw new Exception("Database error in getClassById: " . $e->getMessage());
    }
}

// Add new class
function addClass($db) {
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
        if (empty($data->nama) || empty($data->tingkat)) {
            returnError(400, 'Nama dan tingkat kelas harus diisi');
        }
        
        // Begin transaction
        $db->begin_transaction();
        $transaction_started = true;
        
        $query = "INSERT INTO kelas (nama, tingkat, dibuat_pada, diperbarui_pada) 
                  VALUES (?, ?, NOW(), NOW())";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $stmt->bind_param("ss", $data->nama, $data->tingkat);
        if (!$stmt->execute()) {
            throw new Exception("Execute error: " . $stmt->error);
        }
        
        $class_id = $db->insert_id;
        
        // Get the created class
        $get_query = "SELECT * FROM kelas WHERE id = ?";
        $get_stmt = $db->prepare($get_query);
        if (!$get_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $get_stmt->bind_param("i", $class_id);
        if (!$get_stmt->execute()) {
            throw new Exception("Execute error: " . $get_stmt->error);
        }
        
        $get_result = $get_stmt->get_result();
        $class = $get_result->fetch_assoc();
        
        // Commit transaction
        $db->commit();
        $transaction_started = false;
        
        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Kelas berhasil ditambahkan',
            'data' => $class
        ]);
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($transaction_started) {
            $db->rollback();
        }
        throw new Exception("Database error in addClass: " . $e->getMessage());
    }
}

// Update class
function updateClass($db) {
    try {
        // Use this flag for transaction management
        $transaction_started = false;
        
        // Get ID from URL query parameter
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        
        $json = file_get_contents("php://input");
        if (!$json) {
            returnError(400, 'Invalid JSON input');
        }
        
        $data = json_decode($json);
        if (!$data) {
            returnError(400, 'Failed to parse JSON: ' . json_last_error_msg());
        }
        
        // If ID not in URL, try to get from JSON body
        if (!$id && isset($data->id)) {
            $id = $data->id;
        }
        
        // Validate input
        if (empty($id) || empty($data->nama) || empty($data->tingkat)) {
            returnError(400, 'ID, nama dan tingkat kelas harus diisi');
        }
        
        // Check if class exists
        $check_query = "SELECT * FROM kelas WHERE id = ?";
        $check_stmt = $db->prepare($check_query);
        if (!$check_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $check_stmt->bind_param("i", $id);
        if (!$check_stmt->execute()) {
            throw new Exception("Execute error: " . $check_stmt->error);
        }
        
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows === 0) {
            returnError(404, 'Kelas tidak ditemukan');
        }
        
        // Begin transaction
        $db->begin_transaction();
        $transaction_started = true;
        
        $query = "UPDATE kelas SET 
                 nama = ?,
                 tingkat = ?,
                 diperbarui_pada = NOW()
                 WHERE id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $stmt->bind_param("ssi", $data->nama, $data->tingkat, $id);
        if (!$stmt->execute()) {
            throw new Exception("Execute error: " . $stmt->error);
        }
        
        // Get the updated class
        $get_query = "SELECT * FROM kelas WHERE id = ?";
        $get_stmt = $db->prepare($get_query);
        if (!$get_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $get_stmt->bind_param("i", $id);
        if (!$get_stmt->execute()) {
            throw new Exception("Execute error: " . $get_stmt->error);
        }
        
        $get_result = $get_stmt->get_result();
        $class = $get_result->fetch_assoc();
        
        // Commit transaction
        $db->commit();
        $transaction_started = false;
        
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Kelas berhasil diperbarui',
            'data' => $class
        ]);
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($transaction_started) {
            $db->rollback();
        }
        throw new Exception("Database error in updateClass: " . $e->getMessage());
    }
}

// Delete class
function deleteClass($db) {
    try {
        // Use this flag for transaction management
        $transaction_started = false;
        
        // Get ID from URL query parameter
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        
        // Try to get ID from JSON body if not in URL
        $json = file_get_contents("php://input");
        if ($json) {
            $data = json_decode($json);
            // If data is valid and ID not in URL but in body
            if ($data && !$id && isset($data->id)) {
                $id = $data->id;
            }
        }
        
        // Validate input
        if (empty($id)) {
            returnError(400, 'ID kelas harus diisi');
        }
        
        // Check if class exists
        $check_query = "SELECT * FROM kelas WHERE id = ?";
        $check_stmt = $db->prepare($check_query);
        if (!$check_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $check_stmt->bind_param("i", $id);
        if (!$check_stmt->execute()) {
            throw new Exception("Execute error: " . $check_stmt->error);
        }
        
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows === 0) {
            returnError(404, 'Kelas tidak ditemukan');
        }
        
        // Check if class is used in other tables (e.g., jadwal, siswa)
        $check_jadwal_query = "SELECT COUNT(*) as count FROM jadwal WHERE kelas_id = ?";
        $check_jadwal_stmt = $db->prepare($check_jadwal_query);
        if (!$check_jadwal_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $check_jadwal_stmt->bind_param("i", $id);
        if (!$check_jadwal_stmt->execute()) {
            throw new Exception("Execute error: " . $check_jadwal_stmt->error);
        }
        
        $check_jadwal_result = $check_jadwal_stmt->get_result();
        $jadwal_count = $check_jadwal_result->fetch_assoc()['count'];
        
        if ($jadwal_count > 0) {
            returnError(400, 'Kelas tidak dapat dihapus karena masih digunakan di jadwal');
        }
        
        $check_siswa_query = "SELECT COUNT(*) as count FROM siswa WHERE kelas_id = ?";
        $check_siswa_stmt = $db->prepare($check_siswa_query);
        if (!$check_siswa_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $check_siswa_stmt->bind_param("i", $id);
        if (!$check_siswa_stmt->execute()) {
            throw new Exception("Execute error: " . $check_siswa_stmt->error);
        }
        
        $check_siswa_result = $check_siswa_stmt->get_result();
        $siswa_count = $check_siswa_result->fetch_assoc()['count'];
        
        if ($siswa_count > 0) {
            returnError(400, 'Kelas tidak dapat dihapus karena masih digunakan di data siswa');
        }
        
        // Begin transaction
        $db->begin_transaction();
        $transaction_started = true;
        
        $query = "DELETE FROM kelas WHERE id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            throw new Exception("Execute error: " . $stmt->error);
        }
        
        // Commit transaction
        $db->commit();
        $transaction_started = false;
        
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Kelas berhasil dihapus'
        ]);
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($transaction_started) {
            $db->rollback();
        }
        throw new Exception("Database error in deleteClass: " . $e->getMessage());
    }
}
?> 
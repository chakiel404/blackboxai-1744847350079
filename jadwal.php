<?php
require_once __DIR__ . '/config/cors.php';
setCorsHeaders();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/auth.php';

// Enable error reporting for debugging (can be removed in production)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php-errors.log');

// Function to handle and return errors
function returnError($code, $message, $debug = null) {
    http_response_code($code);
    $response = [
        'status' => 'error',
        'message' => $message
    ];
    
    // In development environment, include detailed debug info
    if ($debug !== null) {
        $response['debug'] = $debug;
        
        // Log error to file for tracing
        error_log("JADWAL API ERROR ($code): $message | Debug: " . print_r($debug, true), 0);
    }
    
    echo json_encode($response);
    exit;
}

// Add this function at the top after the returnError function
function ensureIntegerIds(&$data) {
    if (is_array($data)) {
        // Cast common ID fields to integers
        $idFields = ['id', 'guru_id', 'kelas_id', 'mata_pelajaran_id', 'pengguna_id', 'pengguna'];
        
        foreach ($idFields as $field) {
            if (isset($data[$field]) && !is_null($data[$field]) && is_numeric($data[$field])) {
                $data[$field] = (int)$data[$field];
            } else if ($field === 'pengguna' && isset($data[$field])) {
                // More aggressive handling of 'pengguna' field to avoid type errors
                // Always remove this field since it's causing type issues
                unset($data[$field]);
            }
        }
        
        // Process nested objects
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                ensureIntegerIds($value);
            }
        }
    }
}

// Add this function after ensureIntegerIds to clean responses
function cleanupJadwalResponse(&$data) {
    // Remove any fields that might cause type conflicts
    $fieldsToRemove = ['pengguna'];
    
    if (is_array($data)) {
        // Remove problematic fields at the root level
        foreach ($fieldsToRemove as $field) {
            if (isset($data[$field])) {
                unset($data[$field]);
            }
        }
        
        // If the data contains a 'guru' field, also remove any 'pengguna' field in it
        if (isset($data['guru']) && is_array($data['guru'])) {
            foreach ($fieldsToRemove as $field) {
                if (isset($data['guru'][$field])) {
                    unset($data['guru'][$field]);
                }
            }
            
            // Make sure guru_pengguna_id is an integer
            if (isset($data['guru']['pengguna_id']) && !is_null($data['guru']['pengguna_id'])) {
                $data['guru']['pengguna_id'] = (int)$data['guru']['pengguna_id'];
            }
        }
        
        // Process nested arrays
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                cleanupJadwalResponse($value);
            }
        }
    }
}

// Function to transform database rows into properly structured objects
function transformJadwalRow(&$row) {
    if (isset($row['guru_id']) && isset($row['nama_guru'])) {
        // Create a properly structured guru object
        $row['guru'] = [
            'id' => (int)$row['guru_id'],
            'pengguna_id' => isset($row['guru_pengguna_id']) ? (int)$row['guru_pengguna_id'] : null,
            'nip' => $row['guru_nip'] ?? '',
            'telepon' => $row['guru_telepon'] ?? null,
            'alamat' => $row['guru_alamat'] ?? null,
            'foto' => $row['guru_foto'] ?? null,
            'nama' => $row['nama_guru'] ?? '',
        ];
        
        // Remove the individual guru fields from the root level
        unset($row['guru_nip']);
        unset($row['guru_telepon']);
        unset($row['guru_alamat']);
        unset($row['guru_foto']);
    }
    
    // Ensure all IDs are integers
    ensureIntegerIds($row);
    
    // Clean up any pengguna fields
    cleanupJadwalResponse($row);
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
$guru_id = isset($_GET['guru_id']) ? $_GET['guru_id'] : null;
$kelas_id = isset($_GET['kelas_id']) ? $_GET['kelas_id'] : null;
$mata_pelajaran_id = isset($_GET['mata_pelajaran_id']) ? $_GET['mata_pelajaran_id'] : null;
$id = isset($_GET['id']) ? $_GET['id'] : null;

switch($method) {
    case 'GET':
        try {
            if ($id) {
                getJadwalById($db, $id);
            } else if ($guru_id) {
                getJadwalByGuru($db, $guru_id);
            } else if ($kelas_id) {
                getJadwalByKelas($db, $kelas_id);
            } else if ($mata_pelajaran_id) {
                getJadwalByMapel($db, $mata_pelajaran_id);
            } else {
                getAllJadwal($db);
            }
        } catch (Exception $e) {
            returnError(500, 'Error retrieving schedules', $e->getMessage());
        }
        break;
        
    case 'POST':
        try {
            addJadwal($db);
        } catch (Exception $e) {
            returnError(500, 'Error creating schedule', $e->getMessage());
        }
        break;
        
    case 'PUT':
        try {
            updateJadwal($db);
        } catch (Exception $e) {
            returnError(500, 'Error updating schedule', $e->getMessage());
        }
        break;
        
    case 'DELETE':
        try {
            deleteJadwal($db);
        } catch (Exception $e) {
            returnError(500, 'Error deleting schedule', $e->getMessage());
        }
        break;
        
    default:
        returnError(405, 'Metode tidak diizinkan');
        break;
}

// Get all jadwal
function getAllJadwal($db) {
    try {
        // Debug admin token information
        $headers = getallheaders();
        $token = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : 'No token';
        
        // Check user role and get user data
        $user = verifyToken(true);
        
        // Debug user data from token
        error_log("JADWAL API DEBUG: User data from token: " . print_r($user, true));
        
        // Validate user data is available
        if (!$user || !isset($user['peran'])) {
            returnError(401, 'Token tidak valid atau tidak memiliki informasi peran');
        }
        
        $userRole = $user['peran'];
        $userId = isset($user['id']) ? (int)$user['id'] : null;
        
        // Log user role and ID
        error_log("JADWAL API DEBUG: User Role: $userRole, User ID: $userId");
        
        // Base query for jadwal with joins - fixed the query to properly join the tables
        // and include guru as a nested object with proper type handling
        $baseQuery = "SELECT j.*, 
                     m.nama as nama_mapel, 
                     k.nama as nama_kelas, 
                     p.nama as nama_guru,
                     g.id as guru_id,
                     g.pengguna_id as guru_pengguna_id,
                     g.nip as guru_nip,
                     g.telepon as guru_telepon,
                     g.alamat as guru_alamat,
                     g.foto as guru_foto
                     FROM jadwal j 
                     LEFT JOIN mata_pelajaran m ON j.mata_pelajaran_id = m.id 
                     LEFT JOIN kelas k ON j.kelas_id = k.id 
                     LEFT JOIN guru g ON j.guru_id = g.id
                     LEFT JOIN pengguna p ON g.pengguna_id = p.id";
        
        $params = [];
        $types = "";
        
        // If user is a teacher, only show their schedules
        if ($userRole === 'guru') {
            // Get guru_id for this teacher
            $guruQuery = "SELECT id FROM guru WHERE pengguna_id = ?";
            $guruStmt = $db->prepare($guruQuery);
            if (!$guruStmt) {
                throw new Exception("Prepare statement error: " . $db->error);
            }
            
            $guruStmt->bind_param("i", $userId);
            if (!$guruStmt->execute()) {
                throw new Exception("Execute error: " . $guruStmt->error);
            }
            
            $guruResult = $guruStmt->get_result();
            if ($guruResult->num_rows === 0) {
                returnError(404, 'Data guru tidak ditemukan');
            }
            
            $guruData = $guruResult->fetch_assoc();
            $guruId = (int)$guruData['id'];
            
            // Modify query to filter by guru_id
            $query = $baseQuery . " WHERE j.guru_id = ? ORDER BY j.hari ASC, j.waktu_mulai ASC";
            $params[] = $guruId;
            $types .= "i";
        } 
        // For admin users, show all schedules without filtering
        else if ($userRole === 'admin') {
            $query = $baseQuery . " ORDER BY j.hari ASC, j.waktu_mulai ASC";
        }
        // Default case, either role is not recognized or missing
        else {
            returnError(403, 'Akses ditolak. Peran pengguna tidak valid atau tidak memiliki izin.');
        }
        
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        // Bind parameters if any
        if (!empty($params) && !empty($types)) {
            $stmt->bind_param($types, ...$params);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Execute error: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        
        $jadwals = [];
        while ($row = $result->fetch_assoc()) {
            // Ensure all IDs are integers
            $row['id'] = (int)$row['id'];
            $row['kelas_id'] = (int)$row['kelas_id'];
            $row['mata_pelajaran_id'] = (int)$row['mata_pelajaran_id'];
            $row['guru_id'] = (int)$row['guru_id'];
            
            // If guru_pengguna_id exists, make sure it's an integer
            if (isset($row['guru_pengguna_id']) && !is_null($row['guru_pengguna_id'])) {
                $row['guru_pengguna_id'] = (int)$row['guru_pengguna_id'];
            }
            
            // Process all integer fields recursively
            ensureIntegerIds($row);
            
            // Transform the row into a properly structured object
            transformJadwalRow($row);
            
            $jadwals[] = $row;
        }
        
        http_response_code(200);
        
        // Process the entire response array to ensure all integer IDs
        $response = [
            'status' => 'success',
            'message' => 'Daftar jadwal berhasil diambil',
            'data' => $jadwals
        ];
        
        // Apply integer type conversion recursively to all data
        ensureIntegerIds($response['data']);
        
        // Clean up the response
        cleanupJadwalResponse($response['data']);
        
        echo json_encode($response, JSON_NUMERIC_CHECK);
    } catch (Exception $e) {
        returnError(500, "Database error in getAllJadwal: " . $e->getMessage());
    }
}

// Get jadwal by ID
function getJadwalById($db, $id) {
    try {
        // Cast ID to integer
        $id = (int)$id;
        
        $query = "SELECT j.*, 
                 m.nama as nama_mapel, 
                 k.nama as nama_kelas, 
                 p.nama as nama_guru,
                 g.id as guru_id,
                 g.pengguna_id as guru_pengguna_id
                 FROM jadwal j 
                 LEFT JOIN mata_pelajaran m ON j.mata_pelajaran_id = m.id 
                 LEFT JOIN kelas k ON j.kelas_id = k.id 
                 LEFT JOIN guru g ON j.guru_id = g.id
                 LEFT JOIN pengguna p ON g.pengguna_id = p.id
                 WHERE j.id = ?";
                 
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Database error: " . $db->error);
        }
        
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            throw new Exception("Query execution error: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            returnError(404, 'Jadwal tidak ditemukan');
        }
        
        $jadwal = $result->fetch_assoc();
        
        // Ensure all IDs are integers
        $jadwal['id'] = (int)$jadwal['id'];
        $jadwal['kelas_id'] = (int)$jadwal['kelas_id'];
        $jadwal['mata_pelajaran_id'] = (int)$jadwal['mata_pelajaran_id'];
        $jadwal['guru_id'] = (int)$jadwal['guru_id'];
        
        // If guru_pengguna_id exists, make sure it's an integer
        if (isset($jadwal['guru_pengguna_id']) && !is_null($jadwal['guru_pengguna_id'])) {
            $jadwal['guru_pengguna_id'] = (int)$jadwal['guru_pengguna_id'];
        }
        
        // Transform the row into a properly structured object
        transformJadwalRow($jadwal);
        
        http_response_code(200);
        
        // Process the entire response array to ensure all integer IDs
        $response = [
            'status' => 'success',
            'message' => 'Jadwal berhasil diambil',
            'data' => $jadwal
        ];
        
        // Apply integer type conversion recursively to all data
        ensureIntegerIds($response['data']);
        
        // Clean up the response
        cleanupJadwalResponse($response['data']);
        
        echo json_encode($response, JSON_NUMERIC_CHECK);
    } catch (Exception $e) {
        returnError(500, "Database error in getJadwalById: " . $e->getMessage());
    }
}

// Get jadwal by kelas
function getJadwalByKelas($db, $kelas_id) {
    try {
        // Ensure kelas_id is an integer
        $kelas_id = (int)$kelas_id;
        
        // Query to get jadwal for a specific kelas
        $query = "SELECT j.*, 
                 m.nama as nama_mapel, 
                 k.nama as nama_kelas, 
                 p.nama as nama_guru,
                 g.id as guru_id,
                 g.pengguna_id as guru_pengguna_id
                 FROM jadwal j 
                 LEFT JOIN mata_pelajaran m ON j.mata_pelajaran_id = m.id 
                 LEFT JOIN kelas k ON j.kelas_id = k.id 
                 LEFT JOIN guru g ON j.guru_id = g.id
                 LEFT JOIN pengguna p ON g.pengguna_id = p.id
                 WHERE j.kelas_id = ?
                 ORDER BY j.hari ASC, j.waktu_mulai ASC";
        
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Database error: " . $db->error);
        }
        
        $stmt->bind_param("i", $kelas_id);
        if (!$stmt->execute()) {
            throw new Exception("Query execution error: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        
        $jadwals = [];
        while ($row = $result->fetch_assoc()) {
            // Ensure all IDs are integers
            $row['id'] = (int)$row['id'];
            $row['kelas_id'] = (int)$row['kelas_id'];
            $row['mata_pelajaran_id'] = (int)$row['mata_pelajaran_id'];
            $row['guru_id'] = (int)$row['guru_id'];
            
            // If guru_pengguna_id exists, make sure it's an integer
            if (isset($row['guru_pengguna_id']) && !is_null($row['guru_pengguna_id'])) {
                $row['guru_pengguna_id'] = (int)$row['guru_pengguna_id'];
            }
            
            // Process all integer fields recursively
            ensureIntegerIds($row);
            
            // Transform the row into a properly structured object
            transformJadwalRow($row);
            
            $jadwals[] = $row;
        }
        
        http_response_code(200);
        
        // Process the entire response array to ensure all integer IDs
        $response = [
            'status' => 'success',
            'message' => 'Daftar jadwal berhasil diambil',
            'data' => $jadwals
        ];
        
        // Apply integer type conversion recursively to all data
        ensureIntegerIds($response['data']);
        
        // Clean up the response
        cleanupJadwalResponse($response['data']);
        
        echo json_encode($response, JSON_NUMERIC_CHECK);
    } catch (Exception $e) {
        returnError(500, "Database error in getJadwalByKelas: " . $e->getMessage());
    }
}

// Get jadwal by mapel
function getJadwalByMapel($db, $mapel_id) {
    try {
        // Ensure mapel_id is an integer
        $mapel_id = (int)$mapel_id;
        
        // Query to get jadwal for a specific mata pelajaran
        $query = "SELECT j.*, 
                 m.nama as nama_mapel, 
                 k.nama as nama_kelas, 
                 p.nama as nama_guru,
                 g.id as guru_id,
                 g.pengguna_id as guru_pengguna_id
                 FROM jadwal j 
                 LEFT JOIN mata_pelajaran m ON j.mata_pelajaran_id = m.id 
                 LEFT JOIN kelas k ON j.kelas_id = k.id 
                 LEFT JOIN guru g ON j.guru_id = g.id
                 LEFT JOIN pengguna p ON g.pengguna_id = p.id
                 WHERE j.mata_pelajaran_id = ?
                 ORDER BY j.hari ASC, j.waktu_mulai ASC";
        
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Database error: " . $db->error);
        }
        
        $stmt->bind_param("i", $mapel_id);
        if (!$stmt->execute()) {
            throw new Exception("Query execution error: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        
        $jadwals = [];
        while ($row = $result->fetch_assoc()) {
            // Ensure all IDs are integers
            $row['id'] = (int)$row['id'];
            $row['kelas_id'] = (int)$row['kelas_id'];
            $row['mata_pelajaran_id'] = (int)$row['mata_pelajaran_id'];
            $row['guru_id'] = (int)$row['guru_id'];
            
            // If guru_pengguna_id exists, make sure it's an integer
            if (isset($row['guru_pengguna_id']) && !is_null($row['guru_pengguna_id'])) {
                $row['guru_pengguna_id'] = (int)$row['guru_pengguna_id'];
            }
            
            // Process all integer fields recursively
            ensureIntegerIds($row);
            
            // Transform the row into a properly structured object
            transformJadwalRow($row);
            
            $jadwals[] = $row;
        }
        
        http_response_code(200);
        
        // Process the entire response array to ensure all integer IDs
        $response = [
            'status' => 'success',
            'message' => 'Daftar jadwal berhasil diambil',
            'data' => $jadwals
        ];
        
        // Apply integer type conversion recursively to all data
        ensureIntegerIds($response['data']);
        
        // Clean up the response
        cleanupJadwalResponse($response['data']);
        
        echo json_encode($response, JSON_NUMERIC_CHECK);
    } catch (Exception $e) {
        returnError(500, "Database error in getJadwalByMapel: " . $e->getMessage());
    }
}

// Get jadwal by guru
function getJadwalByGuru($db, $guru_id) {
    try {
        // Ensure guru_id is an integer
        $guru_id = (int)$guru_id;
        
        // Query to get jadwal for a specific guru
        $query = "SELECT j.*, 
                 m.nama as nama_mapel, 
                 k.nama as nama_kelas, 
                 p.nama as nama_guru,
                 g.id as guru_id,
                 g.pengguna_id as guru_pengguna_id
                 FROM jadwal j 
                 LEFT JOIN mata_pelajaran m ON j.mata_pelajaran_id = m.id 
                 LEFT JOIN kelas k ON j.kelas_id = k.id 
                 LEFT JOIN guru g ON j.guru_id = g.id
                 LEFT JOIN pengguna p ON g.pengguna_id = p.id
                 WHERE j.guru_id = ?
                 ORDER BY j.hari ASC, j.waktu_mulai ASC";
        
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Database error: " . $db->error);
        }
        
        $stmt->bind_param("i", $guru_id);
        if (!$stmt->execute()) {
            throw new Exception("Execute error: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        
        $jadwals = [];
        while ($row = $result->fetch_assoc()) {
            // Ensure all IDs are integers
            $row['id'] = (int)$row['id'];
            $row['kelas_id'] = (int)$row['kelas_id'];
            $row['mata_pelajaran_id'] = (int)$row['mata_pelajaran_id'];
            $row['guru_id'] = (int)$row['guru_id'];
            
            // If guru_pengguna_id exists, make sure it's an integer
            if (isset($row['guru_pengguna_id']) && !is_null($row['guru_pengguna_id'])) {
                $row['guru_pengguna_id'] = (int)$row['guru_pengguna_id'];
            }
            
            // Process all integer fields recursively
            ensureIntegerIds($row);
            
            // Transform the row into a properly structured object
            transformJadwalRow($row);
            
            $jadwals[] = $row;
        }
        
        // Return jadwals
        http_response_code(200);
        
        // Process the entire response array to ensure all integer IDs
        $response = [
            'status' => 'success',
            'message' => 'Daftar jadwal berhasil diambil',
            'data' => $jadwals
        ];
        
        // Apply integer type conversion recursively to all data
        ensureIntegerIds($response['data']);
        
        // Clean up the response
        cleanupJadwalResponse($response['data']);
        
        echo json_encode($response, JSON_NUMERIC_CHECK);
    } catch (Exception $e) {
        returnError(500, "Database error in getJadwalByGuru: " . $e->getMessage());
    }
}

// Add new jadwal
function addJadwal($db) {
    try {
        // Flag for transaction management
        $transaction_started = false;
        
        // Get input data
        $json = file_get_contents("php://input");
        if (!$json) {
            returnError(400, 'JSON input tidak ditemukan');
        }
        
        $data = json_decode($json);
        if (!$data) {
            returnError(400, 'Failed to parse JSON: ' . json_last_error_msg());
        }
        
        // Validate required fields
        $required_fields = ['hari', 'waktu_mulai', 'waktu_selesai', 'guru_id', 'kelas_id', 'mata_pelajaran_id'];
        foreach ($required_fields as $field) {
            if (!isset($data->$field) || empty($data->$field)) {
                returnError(400, "Field $field harus diisi");
            }
        }
        
        // Validate hari value
        $valid_days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
        if (!in_array($data->hari, $valid_days)) {
            returnError(400, "Nilai hari tidak valid. Harus salah satu dari: " . implode(", ", $valid_days));
        }
        
        // Validate time format
        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $data->waktu_mulai) ||
            !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $data->waktu_selesai)) {
            returnError(400, "Format waktu tidak valid. Gunakan format HH:MM:SS");
        }
        
        // Validate that start time is before end time
        if (strtotime($data->waktu_mulai) >= strtotime($data->waktu_selesai)) {
            returnError(400, "Waktu mulai harus lebih awal dari waktu selesai");
        }
        
        // Check if guru exists
        $check_guru_query = "SELECT id FROM guru WHERE id = ?";
        $check_guru_stmt = $db->prepare($check_guru_query);
        if (!$check_guru_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $check_guru_stmt->bind_param('i', $data->guru_id);
        if (!$check_guru_stmt->execute()) {
            throw new Exception("Execute error: " . $check_guru_stmt->error);
        }
        
        $check_guru_result = $check_guru_stmt->get_result();
        if ($check_guru_result->num_rows === 0) {
            returnError(404, 'Guru dengan ID tersebut tidak ditemukan');
        }
        
        // Check if kelas exists
        $check_kelas_query = "SELECT id FROM kelas WHERE id = ?";
        $check_kelas_stmt = $db->prepare($check_kelas_query);
        if (!$check_kelas_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $check_kelas_stmt->bind_param('i', $data->kelas_id);
        if (!$check_kelas_stmt->execute()) {
            throw new Exception("Execute error: " . $check_kelas_stmt->error);
        }
        
        $check_kelas_result = $check_kelas_stmt->get_result();
        if ($check_kelas_result->num_rows === 0) {
            returnError(404, 'Kelas dengan ID tersebut tidak ditemukan');
        }
        
        // Check if mata pelajaran exists
        $check_mapel_query = "SELECT id FROM mata_pelajaran WHERE id = ?";
        $check_mapel_stmt = $db->prepare($check_mapel_query);
        if (!$check_mapel_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $check_mapel_stmt->bind_param('i', $data->mata_pelajaran_id);
        if (!$check_mapel_stmt->execute()) {
            throw new Exception("Execute error: " . $check_mapel_stmt->error);
        }
        
        $check_mapel_result = $check_mapel_stmt->get_result();
        if ($check_mapel_result->num_rows === 0) {
            returnError(404, 'Mata pelajaran dengan ID tersebut tidak ditemukan');
        }
        
        // Check for overlapping schedules for the class
        $overlap_kelas_query = "SELECT * FROM jadwal 
                              WHERE kelas_id = ? 
                              AND hari = ? 
                              AND ((waktu_mulai <= ? AND waktu_selesai > ?) 
                                  OR (waktu_mulai < ? AND waktu_selesai >= ?) 
                                  OR (waktu_mulai >= ? AND waktu_selesai <= ?))";
        $overlap_kelas_stmt = $db->prepare($overlap_kelas_query);
        if (!$overlap_kelas_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $overlap_kelas_stmt->bind_param('isssssss', 
            $data->kelas_id, 
            $data->hari, 
            $data->waktu_selesai, $data->waktu_mulai, 
            $data->waktu_selesai, $data->waktu_mulai, 
            $data->waktu_mulai, $data->waktu_selesai
        );
        
        if (!$overlap_kelas_stmt->execute()) {
            throw new Exception("Execute error: " . $overlap_kelas_stmt->error);
        }
        
        $overlap_kelas_result = $overlap_kelas_stmt->get_result();
        if ($overlap_kelas_result->num_rows > 0) {
            $overlap = $overlap_kelas_result->fetch_assoc();
            returnError(409, "Jadwal bentrok dengan jadwal kelas yang sudah ada: {$overlap['waktu_mulai']} - {$overlap['waktu_selesai']}");
        }
        
        // Check for overlapping schedules for the teacher
        $overlap_guru_query = "SELECT * FROM jadwal 
                             WHERE guru_id = ? 
                             AND hari = ? 
                             AND ((waktu_mulai <= ? AND waktu_selesai > ?) 
                                OR (waktu_mulai < ? AND waktu_selesai >= ?) 
                                OR (waktu_mulai >= ? AND waktu_selesai <= ?))";
        $overlap_guru_stmt = $db->prepare($overlap_guru_query);
        if (!$overlap_guru_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $overlap_guru_stmt->bind_param('isssssss', 
            $data->guru_id, 
            $data->hari, 
            $data->waktu_selesai, $data->waktu_mulai, 
            $data->waktu_selesai, $data->waktu_mulai, 
            $data->waktu_mulai, $data->waktu_selesai
        );
        
        if (!$overlap_guru_stmt->execute()) {
            throw new Exception("Execute error: " . $overlap_guru_stmt->error);
        }
        
        $overlap_guru_result = $overlap_guru_stmt->get_result();
        if ($overlap_guru_result->num_rows > 0) {
            $overlap = $overlap_guru_result->fetch_assoc();
            returnError(409, "Jadwal bentrok dengan jadwal guru yang sudah ada: {$overlap['waktu_mulai']} - {$overlap['waktu_selesai']}");
        }
        
        // Begin transaction
        $db->begin_transaction();
        $transaction_started = true;
        
        // Add jadwal
        $query = "INSERT INTO jadwal (guru_id, mata_pelajaran_id, kelas_id, hari, waktu_mulai, waktu_selesai, dibuat_pada, diperbarui_pada) 
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $stmt->bind_param('iiisss', 
            $data->guru_id, 
            $data->mata_pelajaran_id, 
            $data->kelas_id, 
            $data->hari, 
            $data->waktu_mulai, 
            $data->waktu_selesai
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Execute error: " . $stmt->error);
        }
        
        $jadwal_id = $db->insert_id;
        
        // Commit transaction
        $db->commit();
        $transaction_started = false;
        
        // Get the created jadwal with related information
        $get_query = "SELECT j.*, 
                     m.nama as nama_mapel, 
                     k.nama as nama_kelas, 
                     p.nama as nama_guru,
                     g.id as guru_id,
                     g.pengguna_id as guru_pengguna_id,
                     g.nip as guru_nip,
                     g.telepon as guru_telepon,
                     g.alamat as guru_alamat,
                     g.foto as guru_foto
                     FROM jadwal j 
                     LEFT JOIN mata_pelajaran m ON j.mata_pelajaran_id = m.id 
                     LEFT JOIN kelas k ON j.kelas_id = k.id 
                     LEFT JOIN guru g ON j.guru_id = g.id 
                     LEFT JOIN pengguna p ON g.pengguna_id = p.id
                     WHERE j.id = ?";
        $get_stmt = $db->prepare($get_query);
        if (!$get_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $get_stmt->bind_param('i', $jadwal_id);
        if (!$get_stmt->execute()) {
            throw new Exception("Execute error: " . $get_stmt->error);
        }
        
        $get_result = $get_stmt->get_result();
        $jadwal = $get_result->fetch_assoc();
        
        // Ensure all IDs are integers
        $jadwal['id'] = (int)$jadwal['id'];
        $jadwal['kelas_id'] = (int)$jadwal['kelas_id'];
        $jadwal['mata_pelajaran_id'] = (int)$jadwal['mata_pelajaran_id'];
        $jadwal['guru_id'] = (int)$jadwal['guru_id'];
        
        // If guru_pengguna_id exists, make sure it's an integer
        if (isset($jadwal['guru_pengguna_id']) && !is_null($jadwal['guru_pengguna_id'])) {
            $jadwal['guru_pengguna_id'] = (int)$jadwal['guru_pengguna_id'];
        }
        
        // Transform the row data into a properly structured object
        transformJadwalRow($jadwal);
        
        // Format the response
        http_response_code(201);
        
        // Process the entire response array to ensure all integer IDs
        $response = [
            'status' => 'success',
            'message' => 'Jadwal berhasil ditambahkan',
            'data' => $jadwal
        ];
        
        // Apply integer type conversion recursively to all data
        ensureIntegerIds($response['data']);
        
        // Clean up the response
        cleanupJadwalResponse($response['data']);
        
        echo json_encode($response, JSON_NUMERIC_CHECK);
    } catch (Exception $e) {
        if ($transaction_started) {
            $db->rollback();
        }
        
        $error_code = 500;
        $error_message = 'Server error: ' . $e->getMessage();
        
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            $error_code = 409;
            $error_message = 'Jadwal sudah ada untuk kombinasi kelas, guru, mata pelajaran, dan waktu yang sama';
        }
        
        returnError($error_code, $error_message);
    }
}

// Update jadwal
function updateJadwal($db) {
    try {
        // Flag for transaction management
        $transaction_started = false;
        
        // Get data from input
        $json = file_get_contents("php://input");
        if (!$json) {
            returnError(400, 'JSON input tidak ditemukan');
        }
        
        $data = json_decode($json);
        if (!$data) {
            returnError(400, 'Failed to parse JSON: ' . json_last_error_msg());
        }
        
        // Check for ID in query string or in the JSON body
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        $jadwal_id = isset($data->id) ? $data->id : $id;
        
        if (!$jadwal_id) {
            returnError(400, 'ID jadwal harus diisi');
        }
        
        // Check if jadwal exists and get current data
        $check_query = "SELECT * FROM jadwal WHERE id = ?";
        $check_stmt = $db->prepare($check_query);
        if (!$check_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $check_stmt->bind_param('i', $jadwal_id);
        if (!$check_stmt->execute()) {
            throw new Exception("Execute error: " . $check_stmt->error);
        }
        
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows === 0) {
            returnError(404, 'Jadwal tidak ditemukan');
        }
        
        $current_jadwal = $check_result->fetch_assoc();
        
        // Set default values from current jadwal if not provided
        $hari = isset($data->hari) && !empty($data->hari) ? $data->hari : $current_jadwal['hari'];
        $waktu_mulai = isset($data->waktu_mulai) && !empty($data->waktu_mulai) ? $data->waktu_mulai : $current_jadwal['waktu_mulai'];
        $waktu_selesai = isset($data->waktu_selesai) && !empty($data->waktu_selesai) ? $data->waktu_selesai : $current_jadwal['waktu_selesai'];
        $guru_id = isset($data->guru_id) && !empty($data->guru_id) ? (int)$data->guru_id : (int)$current_jadwal['guru_id'];
        $kelas_id = isset($data->kelas_id) && !empty($data->kelas_id) ? (int)$data->kelas_id : (int)$current_jadwal['kelas_id'];
        $mata_pelajaran_id = isset($data->mata_pelajaran_id) && !empty($data->mata_pelajaran_id) ? (int)$data->mata_pelajaran_id : (int)$current_jadwal['mata_pelajaran_id'];
        
        // Ensure the ID is also properly cast to integer
        $jadwal_id = (int)$jadwal_id;
        
        // Additional validation to ensure none of our parameters are null or empty
        if (empty($hari) || empty($waktu_mulai) || empty($waktu_selesai) || 
            empty($guru_id) || empty($kelas_id) || empty($mata_pelajaran_id)) {
            returnError(400, "Semua field harus diisi");
        }
        
        // Ensure all IDs are valid integers
        if ($guru_id <= 0 || $kelas_id <= 0 || $mata_pelajaran_id <= 0 || $jadwal_id <= 0) {
            returnError(400, "Semua ID harus berupa angka positif");
        }
        
        // Validate hari value if provided
        if (isset($data->hari) && !empty($data->hari)) {
            $valid_days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
            if (!in_array($hari, $valid_days)) {
                returnError(400, "Nilai hari tidak valid. Harus salah satu dari: " . implode(", ", $valid_days));
            }
        }
        
        // Validate time format if provided
        if ((isset($data->waktu_mulai) && !empty($data->waktu_mulai) && 
             !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $waktu_mulai)) || 
            (isset($data->waktu_selesai) && !empty($data->waktu_selesai) && 
             !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $waktu_selesai))) {
            returnError(400, "Format waktu tidak valid. Gunakan format HH:MM:SS");
        }
        
        // Validate that start time is before end time
        if (strtotime($waktu_mulai) >= strtotime($waktu_selesai)) {
            returnError(400, "Waktu mulai harus lebih awal dari waktu selesai");
        }
        
        // Check if guru exists if guru_id is provided
        if (isset($data->guru_id) && !empty($data->guru_id)) {
            $check_guru_query = "SELECT id FROM guru WHERE id = ?";
            $check_guru_stmt = $db->prepare($check_guru_query);
            if (!$check_guru_stmt) {
                throw new Exception("Prepare statement error for guru check: " . $db->error);
            }
            
            $check_guru_stmt->bind_param('i', $guru_id);
            if (!$check_guru_stmt->execute()) {
                throw new Exception("Execute error for guru check: " . $check_guru_stmt->error);
            }
            
            $check_guru_result = $check_guru_stmt->get_result();
            if ($check_guru_result->num_rows === 0) {
                returnError(404, 'Guru dengan ID tersebut tidak ditemukan');
            }
        }
        
        // Check if kelas exists if kelas_id is provided
        if (isset($data->kelas_id) && !empty($data->kelas_id)) {
            $check_kelas_query = "SELECT id FROM kelas WHERE id = ?";
            $check_kelas_stmt = $db->prepare($check_kelas_query);
            if (!$check_kelas_stmt) {
                throw new Exception("Prepare statement error for kelas check: " . $db->error);
            }
            
            $check_kelas_stmt->bind_param('i', $kelas_id);
            if (!$check_kelas_stmt->execute()) {
                throw new Exception("Execute error for kelas check: " . $check_kelas_stmt->error);
            }
            
            $check_kelas_result = $check_kelas_stmt->get_result();
            if ($check_kelas_result->num_rows === 0) {
                returnError(404, 'Kelas dengan ID tersebut tidak ditemukan');
            }
        }
        
        // Check if mata pelajaran exists if mata_pelajaran_id is provided
        if (isset($data->mata_pelajaran_id) && !empty($data->mata_pelajaran_id)) {
            $check_mapel_query = "SELECT id FROM mata_pelajaran WHERE id = ?";
            $check_mapel_stmt = $db->prepare($check_mapel_query);
            if (!$check_mapel_stmt) {
                throw new Exception("Prepare statement error for mapel check: " . $db->error);
            }
            
            $check_mapel_stmt->bind_param('i', $mata_pelajaran_id);
            if (!$check_mapel_stmt->execute()) {
                throw new Exception("Execute error for mapel check: " . $check_mapel_stmt->error);
            }
            
            $check_mapel_result = $check_mapel_stmt->get_result();
            if ($check_mapel_result->num_rows === 0) {
                returnError(404, 'Mata pelajaran dengan ID tersebut tidak ditemukan');
            }
        }
        
        // Check for overlapping schedules for the same class, excluding the current jadwal
        $check_overlap_kelas_query = "SELECT * FROM jadwal 
                                    WHERE kelas_id = ? AND hari = ? AND id != ?
                                    AND ((waktu_mulai <= ? AND waktu_selesai > ?) 
                                    OR (waktu_mulai < ? AND waktu_selesai >= ?) 
                                    OR (waktu_mulai >= ? AND waktu_selesai <= ?))";
        $check_overlap_kelas_stmt = $db->prepare($check_overlap_kelas_query);
        if (!$check_overlap_kelas_stmt) {
            throw new Exception("Prepare statement error for kelas overlap check: " . $db->error);
        }
        
        // Debug log the values going into the bind_param call
        error_log("DEBUG: kelas_id={$kelas_id}, hari={$hari}, jadwal_id={$jadwal_id}, waktu_selesai={$waktu_selesai}, waktu_mulai={$waktu_mulai}");
        
        // For class overlap check, ensure all parameters are correct
        // i - integer, s - string, 
        // The correct order is: kelas_id (i), hari (s), jadwal_id (i), 
        // waktu_selesai (s), waktu_mulai (s), waktu_selesai (s), waktu_mulai (s), waktu_mulai (s), waktu_selesai (s)
        $check_overlap_kelas_stmt->bind_param('isisssssss', 
            $kelas_id, 
            $hari,
            $jadwal_id,
            $waktu_selesai, 
            $waktu_mulai, 
            $waktu_selesai, 
            $waktu_mulai, 
            $waktu_mulai, 
            $waktu_selesai
        );
        
        try {
            if (!$check_overlap_kelas_stmt->execute()) {
                throw new Exception("Execute error for kelas overlap check: " . $check_overlap_kelas_stmt->error);
            }
            error_log("DEBUG: Kelas overlap check executed successfully");
            
            // Process the results
            $check_overlap_kelas_result = $check_overlap_kelas_stmt->get_result();
            if ($check_overlap_kelas_result->num_rows > 0) {
                $overlap = $check_overlap_kelas_result->fetch_assoc();
                returnError(409, "Jadwal bertabrakan dengan jadwal lain untuk kelas yang sama: {$overlap['waktu_mulai']} - {$overlap['waktu_selesai']}");
            }
        } catch (Exception $e) {
            error_log("DEBUG: Kelas overlap check failed: " . $e->getMessage());
            throw $e;
        }
        
        // Check for overlapping schedules for the same guru, excluding the current jadwal
        $check_overlap_guru_query = "SELECT * FROM jadwal 
                                  WHERE guru_id = ? AND hari = ? AND id != ?
                                  AND ((waktu_mulai <= ? AND waktu_selesai > ?) 
                                  OR (waktu_mulai < ? AND waktu_selesai >= ?) 
                                  OR (waktu_mulai >= ? AND waktu_selesai <= ?))";
        $check_overlap_guru_stmt = $db->prepare($check_overlap_guru_query);
        if (!$check_overlap_guru_stmt) {
            throw new Exception("Prepare statement error for guru overlap check: " . $db->error);
        }
        
        // Debug log the values for guru overlap check
        error_log("DEBUG: guru_id={$guru_id}, hari={$hari}, jadwal_id={$jadwal_id}, waktu_selesai={$waktu_selesai}, waktu_mulai={$waktu_mulai}");
        
        // For teacher overlap check, ensure all parameters are correct
        // i - integer, s - string
        // The correct order is: guru_id (i), hari (s), jadwal_id (i), 
        // waktu_selesai (s), waktu_mulai (s), waktu_selesai (s), waktu_mulai (s), waktu_mulai (s), waktu_selesai (s)
        $check_overlap_guru_stmt->bind_param('isisssssss', 
            $guru_id, 
            $hari,
            $jadwal_id,
            $waktu_selesai, 
            $waktu_mulai, 
            $waktu_selesai, 
            $waktu_mulai, 
            $waktu_mulai, 
            $waktu_selesai
        );
        
        try {
            if (!$check_overlap_guru_stmt->execute()) {
                throw new Exception("Execute error for guru overlap check: " . $check_overlap_guru_stmt->error);
            }
            error_log("DEBUG: Guru overlap check executed successfully");
            
            // Process the results
            $check_overlap_guru_result = $check_overlap_guru_stmt->get_result();
            if ($check_overlap_guru_result->num_rows > 0) {
                $overlap = $check_overlap_guru_result->fetch_assoc();
                returnError(409, "Jadwal bertabrakan dengan jadwal lain untuk guru yang sama: {$overlap['waktu_mulai']} - {$overlap['waktu_selesai']}");
            }
        } catch (Exception $e) {
            error_log("DEBUG: Guru overlap check failed: " . $e->getMessage());
            throw $e;
        }
        
        // Begin transaction
        $db->begin_transaction();
        $transaction_started = true;
        
        // Update jadwal
        $query = "UPDATE jadwal SET 
                 guru_id = ?,
                 mata_pelajaran_id = ?,
                 kelas_id = ?,
                 hari = ?,
                 waktu_mulai = ?,
                 waktu_selesai = ?,
                 diperbarui_pada = NOW()
                 WHERE id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $stmt->bind_param('iiisssi', 
            $guru_id, 
            $mata_pelajaran_id, 
            $kelas_id, 
            $hari, 
            $waktu_mulai, 
            $waktu_selesai,
            $jadwal_id
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Execute error: " . $stmt->error);
        }
        
        // Commit transaction
        $db->commit();
        $transaction_started = false;
        
        // Get the updated jadwal
        $get_query = "SELECT j.*, 
                     m.nama as nama_mapel, 
                     k.nama as nama_kelas, 
                     p.nama as nama_guru,
                     g.id as guru_id,
                     g.pengguna_id as guru_pengguna_id,
                     g.nip as guru_nip,
                     g.telepon as guru_telepon,
                     g.alamat as guru_alamat,
                     g.foto as guru_foto
                     FROM jadwal j 
                     LEFT JOIN mata_pelajaran m ON j.mata_pelajaran_id = m.id 
                     LEFT JOIN kelas k ON j.kelas_id = k.id 
                     LEFT JOIN guru g ON j.guru_id = g.id 
                     LEFT JOIN pengguna p ON g.pengguna_id = p.id
                     WHERE j.id = ?";
        $get_stmt = $db->prepare($get_query);
        if (!$get_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $get_stmt->bind_param('i', $jadwal_id);
        if (!$get_stmt->execute()) {
            throw new Exception("Execute error: " . $get_stmt->error);
        }
        
        $get_result = $get_stmt->get_result();
        $jadwal = $get_result->fetch_assoc();
        
        // Ensure all IDs are integers
        $jadwal['id'] = (int)$jadwal['id'];
        $jadwal['kelas_id'] = (int)$jadwal['kelas_id'];
        $jadwal['mata_pelajaran_id'] = (int)$jadwal['mata_pelajaran_id'];
        $jadwal['guru_id'] = (int)$jadwal['guru_id'];
        
        // If guru_pengguna_id exists, make sure it's an integer
        if (isset($jadwal['guru_pengguna_id']) && !is_null($jadwal['guru_pengguna_id'])) {
            $jadwal['guru_pengguna_id'] = (int)$jadwal['guru_pengguna_id'];
        }
        
        // Transform the row data into a properly structured object
        transformJadwalRow($jadwal);
        
        // Format the response
        http_response_code(200);
        
        // Process the entire response array to ensure all integer IDs
        $response = [
            'status' => 'success',
            'message' => 'Jadwal berhasil diupdate',
            'data' => $jadwal
        ];
        
        // Apply integer type conversion recursively to all data
        ensureIntegerIds($response['data']);
        
        // Clean up the response
        cleanupJadwalResponse($response['data']);
        
        echo json_encode($response, JSON_NUMERIC_CHECK);
    } catch (Exception $e) {
        if ($transaction_started) {
            $db->rollback();
        }
        
        $error_code = 500;
        $error_message = 'Server error: ' . $e->getMessage();
        
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            $error_code = 409;
            $error_message = 'Jadwal sudah ada untuk kombinasi kelas, guru, mata pelajaran, dan waktu yang sama';
        }
        
        returnError($error_code, $error_message);
    }
}

// Delete jadwal
function deleteJadwal($db) {
    try {
        // Flag for transaction management
        $transaction_started = false;
        
        // Get ID from query string or JSON body
        $id = null;
        
        // Check for ID in query string
        if (isset($_GET['id'])) {
            $id = $_GET['id'];
        } else {
            // Try to get ID from JSON body
            $json = file_get_contents("php://input");
            if ($json) {
                $data = json_decode($json);
                if ($data && isset($data->id)) {
                    $id = $data->id;
                }
            }
        }
        
        if (!$id) {
            returnError(400, 'ID jadwal harus diisi');
        }
        
        // Simple check if jadwal exists - avoid complex joins that cause issues
        $check_query = "SELECT j.* FROM jadwal j WHERE j.id = ?";
        $check_stmt = $db->prepare($check_query);
        if (!$check_stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $check_stmt->bind_param('i', $id);
        if (!$check_stmt->execute()) {
            throw new Exception("Execute error: " . $check_stmt->error);
        }
        
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows === 0) {
            returnError(404, 'Jadwal tidak ditemukan');
        }
        
        $jadwal = $check_result->fetch_assoc();
        
        // Begin transaction
        $db->begin_transaction();
        $transaction_started = true;
        
        // Delete jadwal
        $query = "DELETE FROM jadwal WHERE id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare statement error: " . $db->error);
        }
        
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            throw new Exception("Execute error: " . $stmt->error);
        }
        
        if ($stmt->affected_rows === 0) {
            throw new Exception("Tidak ada jadwal yang dihapus");
        }
        
        // Commit transaction
        $db->commit();
        $transaction_started = false;
        
        // Format the response - with minimal data to avoid issues
        http_response_code(200);
        $response = [
            'status' => 'success',
            'message' => 'Jadwal berhasil dihapus',
            'data' => [
                'jadwal_id' => (int)$id
            ]
        ];
        
        echo json_encode($response, JSON_NUMERIC_CHECK);
    } catch (Exception $e) {
        if ($transaction_started) {
            $db->rollback();
        }
        
        $error_code = 500;
        $error_message = 'Server error: ' . $e->getMessage();
        
        if (strpos($e->getMessage(), 'Tidak ada jadwal yang dihapus') !== false) {
            $error_code = 400;
            $error_message = 'Jadwal tidak dapat dihapus karena tidak ditemukan';
        } else if (strpos($e->getMessage(), 'foreign key constraint fails') !== false) {
            $error_code = 409;
            $error_message = 'Jadwal tidak dapat dihapus karena sedang digunakan oleh data lain';
        }
        
        returnError($error_code, $error_message);
    }
}
?> 
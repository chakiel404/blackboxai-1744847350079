<?php
// This file handles mata_pelajaran operations directly for compatibility with the Flutter app
require_once __DIR__ . '/config/cors.php';
setCorsHeaders();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/auth.php';

// Set up error handling
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Function to handle errors
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

// Verify JWT token
try {
    $user = verifyToken();
} catch (Exception $e) {
    returnError(401, 'Authentication error', $e->getMessage());
}

// Try to connect to database
try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Fix any null diperbarui_pada values
    $fix_query = "UPDATE mata_pelajaran SET diperbarui_pada = dibuat_pada WHERE diperbarui_pada IS NULL";
    $db->query($fix_query);
} catch (Exception $e) {
    returnError(500, 'Database connection error', $e->getMessage());
}

// Process the request
$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? $_GET['id'] : null;

switch($method) {
    case 'GET':
        // Handle GET requests for mata_pelajaran with pagination
        try {
            if ($id) {
                // If ID is specified, get a specific mata_pelajaran
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
            } else {
                // Handle pagination
                $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                $perHalaman = isset($_GET['perHalaman']) ? (int)$_GET['perHalaman'] : 10;
                $role = isset($_GET['role']) ? $_GET['role'] : 'admin';
                $guru_id = isset($_GET['guru_id']) ? (int)$_GET['guru_id'] : null;
                
                // Safety check for pagination values
                if ($page < 1) $page = 1;
                if ($perHalaman < 1) $perHalaman = 10;
                if ($perHalaman > 100) $perHalaman = 100; // limit to prevent large queries
                
                $offset = ($page - 1) * $perHalaman;
                
                // Build base query according to role
                if ($role === 'guru' && $guru_id) {
                    // For teachers, get only their assigned subjects
                    $count_query = "SELECT COUNT(DISTINCT mp.id) as total 
                                  FROM mata_pelajaran mp
                                  INNER JOIN guru_mata_pelajaran gmp ON mp.id = gmp.mata_pelajaran_id
                                  WHERE gmp.guru_id = ?";
                    
                    $count_stmt = $db->prepare($count_query);
                    if (!$count_stmt) {
                        throw new Exception("Prepare statement error in count query: " . $db->error);
                    }
                    
                    $count_stmt->bind_param("i", $guru_id);
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
                    
                    $stmt->bind_param("iii", $guru_id, $perHalaman, $offset);
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
                $subjects = [];
                
                while ($row = $result->fetch_assoc()) {
                    // Ensure no null values
                    $row['nama'] = $row['nama'] ?? "";
                    $row['kode'] = $row['kode'] ?? "";
                    $row['deskripsi'] = $row['deskripsi'] ?? "";
                    $row['dibuat_pada'] = $row['dibuat_pada'] ?? date("Y-m-d H:i:s");
                    $row['diperbarui_pada'] = $row['diperbarui_pada'] ?? date("Y-m-d H:i:s");
                    $subjects[] = $row;
                }
                
                $total_pages = ceil($total_records / $perHalaman);
                
                http_response_code(200);
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Daftar mata pelajaran berhasil diambil',
                    'data' => $subjects,
                    'pagination' => [
                        'totalRecords' => $total_records,
                        'totalPages' => $total_pages,
                        'currentPage' => $page,
                        'perHalaman' => $perHalaman
                    ]
                ]);
            }
        } catch (Exception $e) {
            returnError(500, 'Error retrieving subjects', $e->getMessage());
        }
        break;
        
    case 'POST':
        // Handle POST for creating new mata pelajaran
        try {
            $json = file_get_contents("php://input");
            error_log("POST data: " . $json);
            
            if (empty($json)) {
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
            $check_stmt->bind_param("s", $data->kode);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $kode_count = $check_result->fetch_assoc()['count'];
            
            if ($kode_count > 0) {
                returnError(400, 'Kode mata pelajaran sudah terdaftar');
            }
            
            // Begin transaction
            $database->beginTransaction();
            
            $deskripsi = !empty($data->deskripsi) ? $data->deskripsi : null;
            
            $query = "INSERT INTO mata_pelajaran (nama, kode, deskripsi, dibuat_pada, diperbarui_pada) 
                     VALUES (?, ?, ?, NOW(), NOW())";
            $stmt = $db->prepare($query);
            
            $stmt->bind_param("sss", $data->nama, $data->kode, $deskripsi);
            
            if (!$stmt->execute()) {
                $database->rollback();
                returnError(500, 'Failed to insert mata pelajaran: ' . $stmt->error);
            }
            
            $subject_id = $db->insert_id;
            
            // Get the created subject
            $get_query = "SELECT * FROM mata_pelajaran WHERE id = ?";
            $get_stmt = $db->prepare($get_query);
            $get_stmt->bind_param("i", $subject_id);
            $get_stmt->execute();
            $get_result = $get_stmt->get_result();
            $subject = $get_result->fetch_assoc();
            
            // Ensure no null values
            $subject['nama'] = $subject['nama'] ?? "";
            $subject['kode'] = $subject['kode'] ?? "";
            $subject['deskripsi'] = $subject['deskripsi'] ?? "";
            $subject['dibuat_pada'] = $subject['dibuat_pada'] ?? date("Y-m-d H:i:s");
            $subject['diperbarui_pada'] = $subject['diperbarui_pada'] ?? date("Y-m-d H:i:s");
            
            $database->commit();
            
            http_response_code(201);
            echo json_encode([
                'status' => 'success',
                'message' => 'Mata pelajaran berhasil ditambahkan',
                'data' => $subject
            ]);
        } catch (Exception $e) {
            if (isset($database) && method_exists($database, 'rollback')) {
                $database->rollback();
            }
            returnError(500, 'Error creating subject: ' . $e->getMessage());
        }
        break;
        
    case 'PUT':
        // Handle PUT for updating mata pelajaran
        try {
            $json = file_get_contents("php://input");
            error_log("PUT data: " . $json);
            
            if (empty($json)) {
                // If no JSON body but ID in URL, try to use query params
                if ($id) {
                    $data = new stdClass();
                    $data->id = $id;
                    $data->nama = isset($_GET['nama']) ? $_GET['nama'] : null;
                    $data->kode = isset($_GET['kode']) ? $_GET['kode'] : null;
                    $data->deskripsi = isset($_GET['deskripsi']) ? $_GET['deskripsi'] : null;
                } else {
                    returnError(400, 'Invalid JSON input');
                }
            } else {
                $data = json_decode($json);
                if (!$data) {
                    returnError(400, 'Failed to parse JSON: ' . json_last_error_msg());
                }
                
                // If ID was provided in URL, use that
                if ($id && !isset($data->id)) {
                    $data->id = $id;
                }
            }
            
            // Validate input
            if (empty($data->id) || empty($data->nama) || empty($data->kode)) {
                returnError(400, 'ID, nama, dan kode mata pelajaran harus diisi');
            }
            
            // Check if subject exists
            $check_query = "SELECT * FROM mata_pelajaran WHERE id = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bind_param("i", $data->id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                returnError(404, 'Mata pelajaran tidak ditemukan');
            }
            
            // Check if kode already exists (but not for this record)
            $check_kode_query = "SELECT COUNT(*) as count FROM mata_pelajaran WHERE kode = ? AND id != ?";
            $check_kode_stmt = $db->prepare($check_kode_query);
            $check_kode_stmt->bind_param("si", $data->kode, $data->id);
            $check_kode_stmt->execute();
            $check_kode_result = $check_kode_stmt->get_result();
            $kode_count = $check_kode_result->fetch_assoc()['count'];
            
            if ($kode_count > 0) {
                returnError(400, 'Kode mata pelajaran sudah terdaftar');
            }
            
            // Begin transaction
            $database->beginTransaction();
            
            $deskripsi = isset($data->deskripsi) ? $data->deskripsi : null;
            
            $query = "UPDATE mata_pelajaran SET 
                     nama = ?,
                     kode = ?,
                     deskripsi = ?,
                     diperbarui_pada = NOW()
                     WHERE id = ?";
            $stmt = $db->prepare($query);
            
            $stmt->bind_param("sssi", $data->nama, $data->kode, $deskripsi, $data->id);
            
            if (!$stmt->execute()) {
                $database->rollback();
                returnError(500, 'Failed to update mata pelajaran: ' . $stmt->error);
            }
            
            // Get the updated subject
            $get_query = "SELECT * FROM mata_pelajaran WHERE id = ?";
            $get_stmt = $db->prepare($get_query);
            $get_stmt->bind_param("i", $data->id);
            $get_stmt->execute();
            $get_result = $get_stmt->get_result();
            $subject = $get_result->fetch_assoc();
            
            // Ensure no null values
            $subject['nama'] = $subject['nama'] ?? "";
            $subject['kode'] = $subject['kode'] ?? "";
            $subject['deskripsi'] = $subject['deskripsi'] ?? "";
            $subject['dibuat_pada'] = $subject['dibuat_pada'] ?? date("Y-m-d H:i:s");
            $subject['diperbarui_pada'] = $subject['diperbarui_pada'] ?? date("Y-m-d H:i:s");
            
            $database->commit();
            
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'message' => 'Mata pelajaran berhasil diperbarui',
                'data' => $subject
            ]);
        } catch (Exception $e) {
            if (isset($database) && method_exists($database, 'rollback')) {
                $database->rollback();
            }
            returnError(500, 'Error updating subject: ' . $e->getMessage());
        }
        break;
        
    case 'DELETE':
        // Handle DELETE for mata pelajaran
        try {
            // Get ID either from URL or request body
            $delete_id = $id;
            
            if (!$delete_id) {
                $json = file_get_contents("php://input");
                error_log("DELETE data: " . $json);
                
                if (!empty($json)) {
                    $data = json_decode($json);
                    if ($data && isset($data->id)) {
                        $delete_id = $data->id;
                    }
                }
            }
            
            if (!$delete_id) {
                returnError(400, 'ID mata pelajaran harus diisi');
            }
            
            // Check if subject exists
            $check_query = "SELECT * FROM mata_pelajaran WHERE id = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bind_param("i", $delete_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                returnError(404, 'Mata pelajaran tidak ditemukan');
            }
            
            // Check if subject is used in jadwal
            $check_jadwal_query = "SELECT COUNT(*) as count FROM jadwal WHERE mata_pelajaran_id = ?";
            $check_jadwal_stmt = $db->prepare($check_jadwal_query);
            $check_jadwal_stmt->bind_param("i", $delete_id);
            $check_jadwal_stmt->execute();
            $check_jadwal_result = $check_jadwal_stmt->get_result();
            $jadwal_count = $check_jadwal_result->fetch_assoc()['count'];
            
            if ($jadwal_count > 0) {
                returnError(400, 'Mata pelajaran tidak dapat dihapus karena masih digunakan di jadwal');
            }
            
            // Begin transaction
            $database->beginTransaction();
            
            $query = "DELETE FROM mata_pelajaran WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("i", $delete_id);
            
            if (!$stmt->execute()) {
                $database->rollback();
                returnError(500, 'Failed to delete mata pelajaran: ' . $stmt->error);
            }
            
            $database->commit();
            
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'message' => 'Mata pelajaran berhasil dihapus'
            ]);
        } catch (Exception $e) {
            if (isset($database) && method_exists($database, 'rollback')) {
                $database->rollback();
            }
            returnError(500, 'Error deleting subject: ' . $e->getMessage());
        }
        break;
        
    default:
        returnError(405, 'Metode tidak diizinkan');
        break;
}
?> 
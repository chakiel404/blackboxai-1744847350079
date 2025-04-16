<?php
require_once __DIR__ . '/config/cors.php';
setCorsHeaders();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/auth.php';

// Disable direct error display for security in production
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php-errors.log');

// Function to handle errors consistently
function returnResponse($status, $message, $data = null, $statusCode = 200) {
    http_response_code($statusCode);
    $response = [
        'status' => $status,
        'message' => $message
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response);
    exit;
}

// Connect to database
try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    returnResponse('error', 'Database connection error: ' . $e->getMessage(), null, 500);
}

// Verify token and get user data
try {
    $user = verifyToken();
    if (!$user || empty($user['id'])) {
        returnResponse('error', 'Invalid authentication token', null, 401);
    }
} catch (Exception $e) {
    returnResponse('error', 'Authentication error: ' . $e->getMessage(), null, 401);
}

$method = $_SERVER['REQUEST_METHOD'];

switch($method) {
    case 'GET':
        try {
            // Cast user ID to integer to avoid type mismatch
            $userId = (int)$user['id'];
            
            $query = "SELECT p.id, p.nama, p.email, p.peran, p.dibuat_pada, p.diperbarui_pada, 
                          CASE 
                             WHEN p.peran = 'guru' THEN (SELECT g.id FROM guru g WHERE g.pengguna_id = p.id)
                             WHEN p.peran = 'siswa' THEN (SELECT s.id FROM siswa s WHERE s.pengguna_id = p.id)
                             ELSE NULL
                          END as detail_id
                   FROM pengguna p
                   WHERE p.id = ?";
                   
            $stmt = $db->prepare($query);
            if (!$stmt) {
                throw new Exception("Database error: " . $db->error);
            }
            
            $stmt->bind_param("i", $userId);
            if (!$stmt->execute()) {
                throw new Exception("Query execution error: " . $stmt->error);
            }
            
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                returnResponse('error', 'User not found', null, 404);
            }
            
            $profil = $result->fetch_assoc();
            
            // Ensure all IDs are integers
            $profil['id'] = (int)$profil['id'];
            $profil['detail_id'] = $profil['detail_id'] !== null ? (int)$profil['detail_id'] : null;
            
            // Get additional details based on role
            if ($profil['peran'] === 'guru' && $profil['detail_id']) {
                $guruQuery = "SELECT id, nip, telepon, alamat, foto FROM guru WHERE id = ?";
                $guruStmt = $db->prepare($guruQuery);
                $guruStmt->bind_param("i", $profil['detail_id']);
                $guruStmt->execute();
                $guruResult = $guruStmt->get_result();
                
                if ($guruResult->num_rows > 0) {
                    $guru = $guruResult->fetch_assoc();
                    $profil['guru'] = $guru;
                    // Make sure IDs are integers
                    $profil['guru']['id'] = (int)$profil['guru']['id'];
                    $profil['guru']['pengguna_id'] = (int)$profil['id'];
                }
            } else if ($profil['peran'] === 'siswa' && $profil['detail_id']) {
                $siswaQuery = "SELECT s.id, s.nis, s.telepon, s.alamat, s.foto, s.kelas_id, k.nama as kelas_nama 
                              FROM siswa s 
                              LEFT JOIN kelas k ON s.kelas_id = k.id 
                              WHERE s.id = ?";
                $siswaStmt = $db->prepare($siswaQuery);
                $siswaStmt->bind_param("i", $profil['detail_id']);
                $siswaStmt->execute();
                $siswaResult = $siswaStmt->get_result();
                
                if ($siswaResult->num_rows > 0) {
                    $siswa = $siswaResult->fetch_assoc();
                    $profil['siswa'] = $siswa;
                    // Make sure IDs are integers
                    $profil['siswa']['id'] = (int)$profil['siswa']['id'];
                    $profil['siswa']['pengguna_id'] = (int)$profil['id'];
                    $profil['siswa']['kelas_id'] = $siswa['kelas_id'] !== null ? (int)$profil['siswa']['kelas_id'] : null;
                }
            }
            
            // Remove the temporary field
            unset($profil['detail_id']);
            
            returnResponse('success', 'Profile data retrieved successfully', $profil);
        } catch (Exception $e) {
            returnResponse('error', 'Error retrieving profile: ' . $e->getMessage(), null, 500);
        }
        break;
        
    case 'PUT':
        try {
            $data = json_decode(file_get_contents("php://input"));
            if (!$data) {
                returnResponse('error', 'Invalid JSON input', null, 400);
            }
            
            // Cast user ID to integer
            $userId = (int)$user['id'];
            $updates = [];
            $params = [];
            $types = "";

            if (!empty($data->nama)) {
                $updates[] = "nama = ?";
                $params[] = $data->nama;
                $types .= "s";
            }

            if (!empty($data->email)) {
                // Validate email format
                if (!filter_var($data->email, FILTER_VALIDATE_EMAIL)) {
                    returnResponse('error', 'Invalid email format', null, 400);
                }

                // Check for duplicate email
                $check_query = "SELECT id FROM pengguna WHERE email = ? AND id != ?";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bind_param("si", $data->email, $userId);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    returnResponse('error', 'Email already in use', null, 400);
                }

                $updates[] = "email = ?";
                $params[] = $data->email;
                $types .= "s";
            }

            if (!empty($data->kata_sandi)) {
                // Validate password
                if (strlen($data->kata_sandi) < 8) {
                    returnResponse('error', 'Password must be at least 8 characters', null, 400);
                }

                $updates[] = "kata_sandi = ?";
                $params[] = password_hash($data->kata_sandi, PASSWORD_DEFAULT);
                $types .= "s";
            }

            if (!empty($updates)) {
                $updates[] = "diperbarui_pada = NOW()";
                
                $query = "UPDATE pengguna SET " . implode(", ", $updates) . " WHERE id = ?";
                $params[] = $userId;
                $types .= "i";
                
                $stmt = $db->prepare($query);
                if (!$stmt) {
                    throw new Exception("Database error: " . $db->error);
                }
                
                $stmt->bind_param($types, ...$params);
                
                if (!$stmt->execute()) {
                    throw new Exception("Query execution error: " . $stmt->error);
                }
                
                if ($stmt->affected_rows > 0) {
                    // Get updated profile data
                    $profileQuery = "SELECT id, nama, email, peran, dibuat_pada, diperbarui_pada FROM pengguna WHERE id = ?";
                    $profileStmt = $db->prepare($profileQuery);
                    $profileStmt->bind_param("i", $userId);
                    $profileStmt->execute();
                    $profileResult = $profileStmt->get_result();
                    $updatedProfile = $profileResult->fetch_assoc();
                    
                    // Cast ID to integer
                    $updatedProfile['id'] = (int)$updatedProfile['id'];
                    
                    returnResponse('success', 'Profile updated successfully', $updatedProfile);
                } else {
                    returnResponse('success', 'No changes made to profile', null);
                }
            } else {
                returnResponse('error', 'No data provided for update', null, 400);
            }
        } catch (Exception $e) {
            returnResponse('error', 'Error updating profile: ' . $e->getMessage(), null, 500);
        }
        break;
        
    default:
        returnResponse('error', 'Method not allowed', null, 405);
        break;
}
?> 
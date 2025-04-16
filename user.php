<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/cors.php';
setCorsHeaders();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/auth.php';

// Function to handle and return errors
function returnError($code, $message, $debug = null) {
    http_response_code($code);
    $response = [
        'status' => 'error',
        'message' => $message
    ];
    
    if ($debug !== null && ini_get('display_errors')) {
        $response['debug'] = $debug;
    }
    
    echo json_encode($response);
    exit;
}

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
$database = new Database();
$db = $database->getConnection();
} catch (Exception $e) {
    returnError(500, 'Database connection error', $e->getMessage());
}

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents("php://input"));

// Try to verify token and role, but continue processing for specific endpoints that might not require auth
try {
    $user = verifyToken();
} catch (Exception $e) {
    // For non-GET requests, require authentication
    if ($method !== 'GET') {
        returnError(401, 'Authentication required', $e->getMessage());
    }
    $user = null;
}

switch($method) {
    case 'GET':
        try {
            // Check if an ID is provided in the query string
            if (isset($_GET['id'])) {
                // Get specific user by ID with foto from the appropriate table based on role
                $query = "SELECT id, nama, email, peran, dibuat_pada, diperbarui_pada 
                         FROM pengguna WHERE id = ?";
                $stmt = $db->prepare($query);
                
                if (!$stmt) {
                    returnError(500, 'Prepare statement error', $db->error);
                }
                
                $stmt->bind_param("i", $_GET['id']);
                
                if (!$stmt->execute()) {
                    returnError(500, 'Execute error', $stmt->error);
                }
                
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    returnError(404, 'User not found');
                }
                
                $pengguna = $result->fetch_assoc();
                echo json_encode($pengguna);
                
            } else {
                // Require admin role for listing all users
                if (!$user || $user['peran'] !== 'admin') {
                    returnError(403, 'Akses ditolak. Hanya admin yang dapat melihat daftar pengguna');
                }
                
                // Get all users with their respective photos from appropriate tables
                $query = "SELECT id, nama, email, peran, dibuat_pada, diperbarui_pada 
                         FROM pengguna ORDER BY id";
        $stmt = $db->prepare($query);
                
                if (!$stmt) {
                    returnError(500, 'Prepare statement error', $db->error);
                }
                
                if (!$stmt->execute()) {
                    returnError(500, 'Execute error', $stmt->error);
                }
        
        $result = $stmt->get_result();
        $pengguna = [];
                
        while ($row = $result->fetch_assoc()) {
            $pengguna[] = $row;
        }
                
        echo json_encode($pengguna);
            }
        } catch (Exception $e) {
            returnError(500, 'Error retrieving users', $e->getMessage());
        }
        break;

    case 'POST':
        try {
            // Only admin can create new users
            if ($user['peran'] !== 'admin') {
                returnError(403, 'Akses ditolak. Hanya admin yang dapat membuat pengguna baru');
            }
            
            if (!$data) {
                returnError(400, 'Invalid JSON data');
            }
            
            if (empty($data->email) || empty($data->kata_sandi) || empty($data->nama) || empty($data->peran)) {
                returnError(400, 'Data yang diperlukan tidak lengkap');
            }
            
            // Validate password length
            if (strlen($data->kata_sandi) < 6) {
                returnError(400, 'Kata sandi minimal 6 karakter');
            }

            // Validate role
            if (!in_array($data->peran, ['admin', 'guru', 'siswa'])) {
                returnError(400, 'Peran tidak valid');
            }

            // Validate email format
            if (!filter_var($data->email, FILTER_VALIDATE_EMAIL)) {
                returnError(400, 'Format email tidak valid');
            }

            // Check email uniqueness
            $check_query = "SELECT 1 FROM pengguna WHERE email = ?";
            $check_stmt = $db->prepare($check_query);
            
            if (!$check_stmt) {
                returnError(500, 'Prepare statement error', $db->error);
            }
            
            $check_stmt->bind_param("s", $data->email);
            
            if (!$check_stmt->execute()) {
                returnError(500, 'Execute error', $check_stmt->error);
            }
            
            $check_result = $check_stmt->get_result();
            if ($check_result->num_rows > 0) {
                returnError(400, 'Email sudah terdaftar');
            }

            // Start transaction to handle multiple tables
            $db->begin_transaction();

            try {
                // Insert new user
                $query = "INSERT INTO pengguna (nama, email, kata_sandi, peran, dibuat_pada, diperbarui_pada) 
                        VALUES (?, ?, ?, ?, NOW(), NOW())";
                $stmt = $db->prepare($query);
                
                if (!$stmt) {
                    throw new Exception('Prepare statement error: ' . $db->error);
                }
                
                $kata_sandi = password_hash($data->kata_sandi, PASSWORD_DEFAULT);
                
                $stmt->bind_param("ssss", 
                    $data->nama, 
                    $data->email, 
                    $kata_sandi,
                    $data->peran
                );
                
                if (!$stmt->execute()) {
                    throw new Exception('Execute error: ' . $stmt->error);
                }

                $pengguna_id = $db->insert_id;

                // If role is guru or siswa, insert into the appropriate table with photo
                if ($data->peran === 'guru') {
                    $guru_query = "INSERT INTO guru (pengguna_id, nip, foto, dibuat_pada, diperbarui_pada) 
                                VALUES (?, ?, ?, NOW(), NOW())";
                    $guru_stmt = $db->prepare($guru_query);
                    
                    if (!$guru_stmt) {
                        throw new Exception('Prepare statement error: ' . $db->error);
                    }
                    
                    $nip = isset($data->nip) ? $data->nip : '';
                    $foto = isset($data->foto) ? $data->foto : null;
                    
                    $guru_stmt->bind_param("iss", $pengguna_id, $nip, $foto);
                    
                    if (!$guru_stmt->execute()) {
                        throw new Exception('Execute error: ' . $guru_stmt->error);
                    }
                } 
                else if ($data->peran === 'siswa') {
                    $siswa_query = "INSERT INTO siswa (pengguna_id, nis, kelas_id, foto, dibuat_pada, diperbarui_pada) 
                                VALUES (?, ?, ?, ?, NOW(), NOW())";
                    $siswa_stmt = $db->prepare($siswa_query);
                    
                    if (!$siswa_stmt) {
                        throw new Exception('Prepare statement error: ' . $db->error);
                    }
                    
                    $nis = isset($data->nis) ? $data->nis : '';
                    $kelas_id = isset($data->kelas_id) ? $data->kelas_id : 1; // Default to first class if not provided
                    $foto = isset($data->foto) ? $data->foto : null;
                    
                    $siswa_stmt->bind_param("isis", $pengguna_id, $nis, $kelas_id, $foto);
                    
                    if (!$siswa_stmt->execute()) {
                        throw new Exception('Execute error: ' . $siswa_stmt->error);
                    }
                }

                // Commit the transaction
                $db->commit();

                // Get the created user data with foto if available
                $get_query = "SELECT p.id, p.nama, p.email, p.peran, p.dibuat_pada, p.diperbarui_pada,
                             CASE 
                                WHEN p.peran = 'guru' THEN g.foto
                                WHEN p.peran = 'siswa' THEN s.foto
                                ELSE NULL
                             END as foto
                             FROM pengguna p
                             LEFT JOIN guru g ON p.id = g.pengguna_id
                             LEFT JOIN siswa s ON p.id = s.pengguna_id
                             WHERE p.id = ?";
                $get_stmt = $db->prepare($get_query);
                
                if (!$get_stmt) {
                    returnError(500, 'Prepare statement error', $db->error);
                }
                
                $get_stmt->bind_param("i", $pengguna_id);
                
                if (!$get_stmt->execute()) {
                    returnError(500, 'Execute error', $get_stmt->error);
                }
                
                $get_result = $get_stmt->get_result();
                $pengguna = $get_result->fetch_assoc();

                http_response_code(201);
                echo json_encode([
                    "status" => "success", 
                    "message" => "Pengguna berhasil dibuat",
                    "data" => $pengguna
                ]);
            }
            catch (Exception $e) {
                // Rollback transaction on error
                $db->rollback();
                throw $e;
            }
        } catch (Exception $e) {
            returnError(500, 'Error creating user', $e->getMessage());
        }
        break;

    case 'PUT':
        try {
            if (!$data) {
                returnError(400, 'Invalid JSON data');
            }
            
            if (empty($data->id)) {
                returnError(400, 'ID pengguna diperlukan');
            }

            // Only admin or the user themselves can update user info
            if ($user['peran'] !== 'admin' && $user['id'] != $data->id) {
                returnError(403, 'Akses ditolak. Anda hanya dapat memperbarui data diri sendiri');
            }

            // Check if user exists and get their role
            $check_query = "SELECT peran FROM pengguna WHERE id = ?";
            $check_stmt = $db->prepare($check_query);
            
            if (!$check_stmt) {
                returnError(500, 'Prepare statement error', $db->error);
            }
            
            $check_stmt->bind_param("i", $data->id);
            
            if (!$check_stmt->execute()) {
                returnError(500, 'Execute error', $check_stmt->error);
            }
            
            $check_result = $check_stmt->get_result();
            if ($check_result->num_rows === 0) {
                returnError(404, 'Pengguna tidak ditemukan');
            }

            $current_user = $check_result->fetch_assoc();
            $current_role = $current_user['peran'];

            // Only admin can change roles
            if (!empty($data->peran) && $user['peran'] !== 'admin') {
                returnError(403, 'Akses ditolak. Hanya admin yang dapat mengubah peran');
            }

            // Check if email already exists for other users
            if (!empty($data->email)) {
                $email_query = "SELECT 1 FROM pengguna WHERE email = ? AND id != ?";
                $email_stmt = $db->prepare($email_query);
                
                if (!$email_stmt) {
                    returnError(500, 'Prepare statement error', $db->error);
                }
                
                $email_stmt->bind_param("si", $data->email, $data->id);
                
                if (!$email_stmt->execute()) {
                    returnError(500, 'Execute error', $email_stmt->error);
                }
                
                $email_result = $email_stmt->get_result();
                if ($email_result->num_rows > 0) {
                    returnError(400, 'Email sudah digunakan');
                }

                // Validate email format
                if (!filter_var($data->email, FILTER_VALIDATE_EMAIL)) {
                    returnError(400, 'Format email tidak valid');
                }
            }

            // Validate role if provided
            if (!empty($data->peran) && !in_array($data->peran, ['admin', 'guru', 'siswa'])) {
                returnError(400, 'Peran tidak valid');
            }

            // Start transaction for updating multiple tables
            $db->begin_transaction();

            try {
                // Update user in pengguna table
                $set_parts = [];
                $types = "";
                $params = [];

                if (!empty($data->nama)) {
                    $set_parts[] = "nama = ?";
                    $types .= "s";
                    $params[] = $data->nama;
                }

                if (!empty($data->email)) {
                    $set_parts[] = "email = ?";
                    $types .= "s";
                    $params[] = $data->email;
                }

                if (!empty($data->peran)) {
                    $set_parts[] = "peran = ?";
                    $types .= "s";
                    $params[] = $data->peran;
                }

                if (!empty($data->kata_sandi)) {
                    if (strlen($data->kata_sandi) < 6) {
                        returnError(400, 'Kata sandi minimal 6 karakter');
                    }
                    $set_parts[] = "kata_sandi = ?";
                    $types .= "s";
                    $params[] = password_hash($data->kata_sandi, PASSWORD_DEFAULT);
                }

                $set_parts[] = "diperbarui_pada = NOW()";

                if (count($params) > 0) {  // Only update if there are fields to update
                    $query = "UPDATE pengguna SET " . implode(", ", $set_parts) . " WHERE id = ?";
                    $types .= "i";
                    $params[] = $data->id;

                    $stmt = $db->prepare($query);
                    
                    if (!$stmt) {
                        throw new Exception('Prepare statement error: ' . $db->error);
                    }
                    
                    $stmt->bind_param($types, ...$params);
                    
                    if (!$stmt->execute()) {
                        throw new Exception('Execute error: ' . $stmt->error);
                    }
                    
                    // Jika pengguna yang diupdate adalah guru, update juga data di tabel guru
                    if ($current_role === 'guru') {
                        // Check if guru record exists and get guru ID
                        $check_guru_query = "SELECT id FROM guru WHERE pengguna_id = ?";
                        $check_guru_stmt = $db->prepare($check_guru_query);
                        
                        if (!$check_guru_stmt) {
                            throw new Exception('Prepare statement error: ' . $db->error);
                        }
                        
                        $check_guru_stmt->bind_param("i", $data->id);
                        
                        if (!$check_guru_stmt->execute()) {
                            throw new Exception('Execute error: ' . $check_guru_stmt->error);
                        }
                        
                        $check_guru_result = $check_guru_stmt->get_result();
                        
                        if ($check_guru_result->num_rows > 0) {
                            $guru_row = $check_guru_result->fetch_assoc();
                            $guru_id = $guru_row['id'];
                            
                            // Persiapkan data untuk update tabel guru
                            $guru_updates = [];
                            $guru_params = [];
                            $guru_types = "";
                            
                            // Update data guru jika ada properti yang relevan
                            if (!empty($data->nip)) {
                                $guru_updates[] = "nip = ?";
                                $guru_types .= "s";
                                $guru_params[] = $data->nip;
                            }
                            
                            if (!empty($data->telepon)) {
                                $guru_updates[] = "telepon = ?";
                                $guru_types .= "s";
                                $guru_params[] = $data->telepon;
                            }
                            
                            if (!empty($data->alamat)) {
                                $guru_updates[] = "alamat = ?";
                                $guru_types .= "s";
                                $guru_params[] = $data->alamat;
                            }
                            
                            if (count($guru_params) > 0) {
                                $guru_updates[] = "diperbarui_pada = NOW()";
                                $guru_query = "UPDATE guru SET " . implode(", ", $guru_updates) . " WHERE id = ?";
                                $guru_types .= "i";
                                $guru_params[] = $guru_id;
                                
                                $guru_stmt = $db->prepare($guru_query);
                                
                                if (!$guru_stmt) {
                                    throw new Exception('Prepare statement error: ' . $db->error);
                                }
                                
                                $guru_stmt->bind_param($guru_types, ...$guru_params);
                                
                                if (!$guru_stmt->execute()) {
                                    throw new Exception('Execute error: ' . $guru_stmt->error);
                                }
                            }
                        }
                    }
                }

                // Update foto in the appropriate table based on the user's role
                if (!empty($data->foto)) {
                    if ($current_role === 'guru') {
                        // Check if guru record exists
                        $check_guru_query = "SELECT 1 FROM guru WHERE pengguna_id = ?";
                        $check_guru_stmt = $db->prepare($check_guru_query);
                        
                        if (!$check_guru_stmt) {
                            throw new Exception('Prepare statement error: ' . $db->error);
                        }
                        
                        $check_guru_stmt->bind_param("i", $data->id);
                        
                        if (!$check_guru_stmt->execute()) {
                            throw new Exception('Execute error: ' . $check_guru_stmt->error);
                        }
                        
                        $check_guru_result = $check_guru_stmt->get_result();
                        
                        if ($check_guru_result->num_rows > 0) {
                            // Update guru foto
                            $update_foto_query = "UPDATE guru SET foto = ?, diperbarui_pada = NOW() WHERE pengguna_id = ?";
                            $update_foto_stmt = $db->prepare($update_foto_query);
                            
                            if (!$update_foto_stmt) {
                                throw new Exception('Prepare statement error: ' . $db->error);
                            }
                            
                            $update_foto_stmt->bind_param("si", $data->foto, $data->id);
                            
                            if (!$update_foto_stmt->execute()) {
                                throw new Exception('Execute error: ' . $update_foto_stmt->error);
                            }
                        } else {
                            // Insert new guru record
                            $insert_guru_query = "INSERT INTO guru (pengguna_id, nip, foto, dibuat_pada, diperbarui_pada) 
                                                VALUES (?, ?, ?, NOW(), NOW())";
                            $insert_guru_stmt = $db->prepare($insert_guru_query);
                            
                            if (!$insert_guru_stmt) {
                                throw new Exception('Prepare statement error: ' . $db->error);
                            }
                            
                            $nip = isset($data->nip) ? $data->nip : '';
                            
                            $insert_guru_stmt->bind_param("iss", $data->id, $nip, $data->foto);
                            
                            if (!$insert_guru_stmt->execute()) {
                                throw new Exception('Execute error: ' . $insert_guru_stmt->error);
                            }
                        }
                    } 
                    else if ($current_role === 'siswa') {
                        // Check if siswa record exists
                        $check_siswa_query = "SELECT 1 FROM siswa WHERE pengguna_id = ?";
                        $check_siswa_stmt = $db->prepare($check_siswa_query);
                        
                        if (!$check_siswa_stmt) {
                            throw new Exception('Prepare statement error: ' . $db->error);
                        }
                        
                        $check_siswa_stmt->bind_param("i", $data->id);
                        
                        if (!$check_siswa_stmt->execute()) {
                            throw new Exception('Execute error: ' . $check_siswa_stmt->error);
                        }
                        
                        $check_siswa_result = $check_siswa_stmt->get_result();
                        
                        if ($check_siswa_result->num_rows > 0) {
                            // Update siswa foto
                            $update_foto_query = "UPDATE siswa SET foto = ?, diperbarui_pada = NOW() WHERE pengguna_id = ?";
                            $update_foto_stmt = $db->prepare($update_foto_query);
                            
                            if (!$update_foto_stmt) {
                                throw new Exception('Prepare statement error: ' . $db->error);
                            }
                            
                            $update_foto_stmt->bind_param("si", $data->foto, $data->id);
                            
                            if (!$update_foto_stmt->execute()) {
                                throw new Exception('Execute error: ' . $update_foto_stmt->error);
                            }
                        } else {
                            // Insert new siswa record
                            $insert_siswa_query = "INSERT INTO siswa (pengguna_id, nis, kelas_id, foto, dibuat_pada, diperbarui_pada) 
                                                VALUES (?, ?, ?, ?, NOW(), NOW())";
                            $insert_siswa_stmt = $db->prepare($insert_siswa_query);
                            
                            if (!$insert_siswa_stmt) {
                                throw new Exception('Prepare statement error: ' . $db->error);
                            }
                            
                            $nis = isset($data->nis) ? $data->nis : '';
                            $kelas_id = isset($data->kelas_id) ? $data->kelas_id : 1; // Default to first class if not provided
                            
                            $insert_siswa_stmt->bind_param("isis", $data->id, $nis, $kelas_id, $data->foto);
                            
                            if (!$insert_siswa_stmt->execute()) {
                                throw new Exception('Execute error: ' . $insert_siswa_stmt->error);
                            }
                        }
                    }
                    // No need to handle admin role for foto
                }

                // If role is changing, we need to handle the related tables
                if (!empty($data->peran) && $data->peran !== $current_role) {
                    // If changing to admin, delete records from guru or siswa tables
                    if ($data->peran === 'admin') {
                        if ($current_role === 'guru') {
                            $delete_query = "DELETE FROM guru WHERE pengguna_id = ?";
                            $delete_stmt = $db->prepare($delete_query);
                            
                            if (!$delete_stmt) {
                                throw new Exception('Prepare statement error: ' . $db->error);
                            }
                            
                            $delete_stmt->bind_param("i", $data->id);
                            
                            if (!$delete_stmt->execute()) {
                                throw new Exception('Execute error: ' . $delete_stmt->error);
                            }
                        } 
                        else if ($current_role === 'siswa') {
                            $delete_query = "DELETE FROM siswa WHERE pengguna_id = ?";
                            $delete_stmt = $db->prepare($delete_query);
                            
                            if (!$delete_stmt) {
                                throw new Exception('Prepare statement error: ' . $db->error);
                            }
                            
                            $delete_stmt->bind_param("i", $data->id);
                            
                            if (!$delete_stmt->execute()) {
                                throw new Exception('Execute error: ' . $delete_stmt->error);
                            }
                        }
                    }
                    // If changing from admin to guru/siswa, create appropriate record
                    else if ($current_role === 'admin') {
                        if ($data->peran === 'guru') {
                            $insert_query = "INSERT INTO guru (pengguna_id, nip, foto, dibuat_pada, diperbarui_pada) 
                                           VALUES (?, ?, ?, NOW(), NOW())";
                            $insert_stmt = $db->prepare($insert_query);
                            
                            if (!$insert_stmt) {
                                throw new Exception('Prepare statement error: ' . $db->error);
                            }
                            
                            $nip = isset($data->nip) ? $data->nip : '';
                            $foto = isset($data->foto) ? $data->foto : null;
                            
                            $insert_stmt->bind_param("iss", $data->id, $nip, $foto);
                            
                            if (!$insert_stmt->execute()) {
                                throw new Exception('Execute error: ' . $insert_stmt->error);
                            }
                        } 
                        else if ($data->peran === 'siswa') {
                            $insert_query = "INSERT INTO siswa (pengguna_id, nis, kelas_id, foto, dibuat_pada, diperbarui_pada) 
                                           VALUES (?, ?, ?, ?, NOW(), NOW())";
                            $insert_stmt = $db->prepare($insert_query);
                            
                            if (!$insert_stmt) {
                                throw new Exception('Prepare statement error: ' . $db->error);
                            }
                            
                            $nis = isset($data->nis) ? $data->nis : '';
                            $kelas_id = isset($data->kelas_id) ? $data->kelas_id : 1; // Default class
                            $foto = isset($data->foto) ? $data->foto : null;
                            
                            $insert_stmt->bind_param("isis", $data->id, $nis, $kelas_id, $foto);
                            
                            if (!$insert_stmt->execute()) {
                                throw new Exception('Execute error: ' . $insert_stmt->error);
                            }
                        }
                    }
                    // If changing between guru and siswa, delete one record and create another
                    else if ($current_role === 'guru' && $data->peran === 'siswa') {
                        // Delete from guru
                        $delete_query = "DELETE FROM guru WHERE pengguna_id = ?";
                        $delete_stmt = $db->prepare($delete_query);
                        
                        if (!$delete_stmt) {
                            throw new Exception('Prepare statement error: ' . $db->error);
                        }
                        
                        $delete_stmt->bind_param("i", $data->id);
                        
                        if (!$delete_stmt->execute()) {
                            throw new Exception('Execute error: ' . $delete_stmt->error);
                        }
                        
                        // Insert into siswa
                        $insert_query = "INSERT INTO siswa (pengguna_id, nis, kelas_id, foto, dibuat_pada, diperbarui_pada) 
                                       VALUES (?, ?, ?, ?, NOW(), NOW())";
                        $insert_stmt = $db->prepare($insert_query);
                        
                        if (!$insert_stmt) {
                            throw new Exception('Prepare statement error: ' . $db->error);
                        }
                        
                        $nis = isset($data->nis) ? $data->nis : '';
                        $kelas_id = isset($data->kelas_id) ? $data->kelas_id : 1; // Default class
                        $foto = isset($data->foto) ? $data->foto : null;
                        
                        $insert_stmt->bind_param("isis", $data->id, $nis, $kelas_id, $foto);
                        
                        if (!$insert_stmt->execute()) {
                            throw new Exception('Execute error: ' . $insert_stmt->error);
                        }
                    }
                    else if ($current_role === 'siswa' && $data->peran === 'guru') {
                        // Delete from siswa
                        $delete_query = "DELETE FROM siswa WHERE pengguna_id = ?";
                        $delete_stmt = $db->prepare($delete_query);
                        
                        if (!$delete_stmt) {
                            throw new Exception('Prepare statement error: ' . $db->error);
                        }
                        
                        $delete_stmt->bind_param("i", $data->id);
                        
                        if (!$delete_stmt->execute()) {
                            throw new Exception('Execute error: ' . $delete_stmt->error);
                        }
                        
                        // Insert into guru
                        $insert_query = "INSERT INTO guru (pengguna_id, nip, foto, dibuat_pada, diperbarui_pada) 
                                       VALUES (?, ?, ?, NOW(), NOW())";
                        $insert_stmt = $db->prepare($insert_query);
                        
                        if (!$insert_stmt) {
                            throw new Exception('Prepare statement error: ' . $db->error);
                        }
                        
                        $nip = isset($data->nip) ? $data->nip : '';
                        $foto = isset($data->foto) ? $data->foto : null;
                        
                        $insert_stmt->bind_param("iss", $data->id, $nip, $foto);
                        
                        if (!$insert_stmt->execute()) {
                            throw new Exception('Execute error: ' . $insert_stmt->error);
                        }
                    }
                }

                // Commit all changes
                $db->commit();

                // Get the updated user data
                $get_query = "SELECT p.id, p.nama, p.email, p.peran, p.dibuat_pada, p.diperbarui_pada,
                             CASE 
                                WHEN p.peran = 'guru' THEN g.foto
                                WHEN p.peran = 'siswa' THEN s.foto
                                ELSE NULL
                             END as foto
                             FROM pengguna p
                             LEFT JOIN guru g ON p.id = g.pengguna_id
                             LEFT JOIN siswa s ON p.id = s.pengguna_id
                             WHERE p.id = ?";
                $get_stmt = $db->prepare($get_query);
                
                if (!$get_stmt) {
                    returnError(500, 'Prepare statement error', $db->error);
                }
                
                $get_stmt->bind_param("i", $data->id);
                
                if (!$get_stmt->execute()) {
                    returnError(500, 'Execute error', $get_stmt->error);
                }
                
                $get_result = $get_stmt->get_result();
                $pengguna = $get_result->fetch_assoc();

                    http_response_code(200);
                    echo json_encode([
                        "status" => "success",
                        "message" => "Pengguna berhasil diperbarui",
                        "data" => $pengguna
                    ]);
            }
            catch (Exception $e) {
                // Rollback transaction on error
                $db->rollback();
                throw $e;
            }
        } catch (Exception $e) {
            returnError(500, 'Error updating user', $e->getMessage());
        }
        break;

    case 'DELETE':
        try {
            // Only admin can delete users
            if ($user['peran'] !== 'admin') {
                returnError(403, 'Akses ditolak. Hanya admin yang dapat menghapus pengguna');
            }
            
            if (!$data) {
                returnError(400, 'Invalid JSON data');
            }
            
            if (empty($data->id)) {
                returnError(400, 'ID pengguna diperlukan');
            }

            // Prevent deleting self
            if ($data->id == $user['id']) {
                returnError(400, 'Anda tidak dapat menghapus akun anda sendiri');
            }

            // Check if user exists
            $check_query = "SELECT peran FROM pengguna WHERE id = ?";
            $check_stmt = $db->prepare($check_query);
            
            if (!$check_stmt) {
                returnError(500, 'Prepare statement error', $db->error);
            }
            
            $check_stmt->bind_param("i", $data->id);
            
            if (!$check_stmt->execute()) {
                returnError(500, 'Execute error', $check_stmt->error);
            }
            
            $check_result = $check_stmt->get_result();
            if ($check_result->num_rows === 0) {
                returnError(404, 'Pengguna tidak ditemukan');
            }
            
            // Get user role
            $user_data = $check_result->fetch_assoc();
            
            // Prevent deleting the last admin
            if ($user_data['peran'] === 'admin') {
                $admin_query = "SELECT COUNT(*) as count FROM pengguna WHERE peran = 'admin'";
                $admin_stmt = $db->prepare($admin_query);
                
                if (!$admin_stmt) {
                    returnError(500, 'Prepare statement error', $db->error);
                }
                
                if (!$admin_stmt->execute()) {
                    returnError(500, 'Execute error', $admin_stmt->error);
                }
                
                $admin_result = $admin_stmt->get_result();
                $admin_count = $admin_result->fetch_assoc()['count'];
                
                if ($admin_count <= 1) {
                    returnError(400, 'Tidak dapat menghapus admin terakhir');
                }
            }

            // Delete user - cascade will handle related records in guru/siswa tables
            $query = "DELETE FROM pengguna WHERE id = ?";
            $stmt = $db->prepare($query);
            
            if (!$stmt) {
                returnError(500, 'Prepare statement error', $db->error);
            }
            
            $stmt->bind_param("i", $data->id);
            
            if (!$stmt->execute()) {
                returnError(500, 'Execute error', $stmt->error);
            }

            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "message" => "Pengguna berhasil dihapus"
            ]);
        } catch (Exception $e) {
            returnError(500, 'Error deleting user', $e->getMessage());
        }
        break;

    default:
        returnError(405, 'Method not allowed');
        break;
}
?> 
<?php
require_once __DIR__ . '/config/cors.php';
setCorsHeaders();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/auth.php';

$database = new Database();
$db = $database->getConnection();

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verify JWT token
verifyToken();

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Get ID from query string if available
$id = isset($_GET['id']) ? $_GET['id'] : null;

// Route request to the appropriate method
switch ($method) {
    case 'GET':
        if ($id) {
            getTeacherById($db, $id);
        } else {
            getTeachers($db);
        }
        break;
    case 'POST':
        addTeacher($db);
        break;
    case 'PUT':
        if (!$id) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'ID guru diperlukan']);
            exit;
        }
        updateTeacher($db, $id);
        break;
    case 'DELETE':
        if (!$id) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'ID guru diperlukan']);
            exit;
        }
        deleteTeacher($db, $id);
        break;
    default:
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Metode tidak diizinkan']);
        break;
}

// Get all teachers
function getTeachers($db) {
    $query = "SELECT g.*, u.nama, u.email, u.peran, u.id as user_id, 
              u.dibuat_pada as user_dibuat_pada, u.diperbarui_pada as user_diperbarui_pada 
              FROM guru g
              JOIN pengguna u ON g.pengguna_id = u.id
              ORDER BY g.id DESC";
    
    $stmt = $db->prepare($query);
    
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
        exit;
    }
    
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Query execution error: ' . $stmt->error]);
        exit;
    }
    
    $result = $stmt->get_result();

    $teachers = [];
    while ($row = $result->fetch_assoc()) {
        // Buat struktur data yang benar dengan objek pengguna bersarang
        $teachers[] = [
            'id' => $row['id'],
            'pengguna_id' => $row['pengguna_id'],
            'nip' => $row['nip'],
            'alamat' => $row['alamat'],
            'telepon' => $row['telepon'],
            'foto' => $row['foto'],
            'dibuat_pada' => $row['dibuat_pada'],
            'diperbarui_pada' => $row['diperbarui_pada'],
            'pengguna' => [
                'id' => $row['user_id'],
                'nama' => $row['nama'],
                'email' => $row['email'],
                'peran' => $row['peran'],
                'dibuat_pada' => $row['user_dibuat_pada'],
                'diperbarui_pada' => $row['user_diperbarui_pada']
            ]
        ];
    }

    http_response_code(200);
    echo json_encode($teachers);
}

// Get teacher by ID
function getTeacherById($db, $id) {
    $query = "SELECT g.*, u.nama, u.email, u.peran, u.id as user_id,
              u.dibuat_pada as user_dibuat_pada, u.diperbarui_pada as user_diperbarui_pada
              FROM guru g
              JOIN pengguna u ON g.pengguna_id = u.id
              WHERE g.id = ?";
    
    $stmt = $db->prepare($query);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $db->error]);
        exit;
    }
    
    $stmt->bind_param("i", $id);
    
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Query execution error: ' . $stmt->error]);
        exit;
    }
    
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $teacher = [
            'id' => $row['id'],
            'pengguna_id' => $row['pengguna_id'],
            'nip' => $row['nip'],
            'alamat' => $row['alamat'],
            'telepon' => $row['telepon'],
            'foto' => $row['foto'],
            'dibuat_pada' => $row['dibuat_pada'],
            'diperbarui_pada' => $row['diperbarui_pada'],
            'pengguna' => [
                'id' => $row['user_id'],
                'nama' => $row['nama'],
                'email' => $row['email'],
                'peran' => $row['peran'],
                'dibuat_pada' => $row['user_dibuat_pada'],
                'diperbarui_pada' => $row['user_diperbarui_pada']
            ]
        ];

        http_response_code(200);
        echo json_encode($teacher);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Guru tidak ditemukan']);
    }
}

// Add new teacher
function addTeacher($db) {
    $data = json_decode(file_get_contents("php://input"));

    // Validate required fields
    if (empty($data->nip) || empty($data->nama) || empty($data->jenis_kelamin) || 
        empty($data->email) || empty($data->kata_sandi)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Field yang diperlukan: nip, nama, jenis_kelamin, email, kata_sandi']);
        exit;
    }

    // Validate NIP format (should be 18 digits)
    if (!preg_match('/^\d{18}$/', $data->nip)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'NIP harus 18 digit']);
        exit;
    }

    // Validate password length
    if (strlen($data->kata_sandi) < 6) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Kata sandi minimal 6 karakter']);
        exit;
    }

    // Validate email format
    if (!filter_var($data->email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Format email tidak valid']);
        exit;
    }

    // Validate jenis_kelamin
    if (!in_array($data->jenis_kelamin, ['L', 'P'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Jenis kelamin harus L atau P']);
        exit;
    }

    // Check if NIP already exists
    $check_query = "SELECT id FROM guru WHERE nip = ?";
    $check_stmt = $db->prepare($check_query);
    if (!$check_stmt) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Gagal mempersiapkan query: ' . $db->error]);
        exit;
    }
    $check_stmt->bind_param("s", $data->nip);
    if (!$check_stmt->execute()) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Gagal mengeksekusi query: ' . $check_stmt->error]);
        exit;
    }
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'NIP sudah digunakan']);
        exit;
    }

    // Check if email already exists
    $check_email_query = "SELECT id FROM pengguna WHERE email = ?";
    $check_email_stmt = $db->prepare($check_email_query);
    if (!$check_email_stmt) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Gagal mempersiapkan query: ' . $db->error]);
        exit;
    }
    $check_email_stmt->bind_param("s", $data->email);
    if (!$check_email_stmt->execute()) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Gagal mengeksekusi query: ' . $check_email_stmt->error]);
        exit;
    }
    $check_email_result = $check_email_stmt->get_result();

    if ($check_email_result->num_rows > 0) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'Email sudah digunakan']);
        exit;
    }

    // Start transaction
    $db->begin_transaction();

    try {
        // Hash password
        $hashed_password = password_hash($data->kata_sandi, PASSWORD_DEFAULT);

        // Insert into pengguna table
        $user_query = "INSERT INTO pengguna (nama, email, kata_sandi, peran, dibuat_pada) VALUES (?, ?, ?, 'guru', NOW())";
        $user_stmt = $db->prepare($user_query);
        if (!$user_stmt) {
            throw new Exception("Gagal mempersiapkan query pengguna: " . $db->error);
        }
        $user_stmt->bind_param("sss", $data->nama, $data->email, $hashed_password);
        if (!$user_stmt->execute()) {
            throw new Exception("Gagal mengeksekusi query pengguna: " . $user_stmt->error);
        }
        $user_id = $db->insert_id;

        // Insert into guru table
        $teacher_query = "INSERT INTO guru (pengguna_id, nip, alamat, telepon, foto, dibuat_pada) 
                        VALUES (?, ?, ?, ?, ?, NOW())";
        $teacher_stmt = $db->prepare($teacher_query);
        if (!$teacher_stmt) {
            throw new Exception("Gagal mempersiapkan query guru: " . $db->error);
        }
        
        // Handle optional fields
        $alamat = isset($data->alamat) ? $data->alamat : null;
        $telepon = isset($data->telepon) ? $data->telepon : null;
        $foto = isset($data->foto) ? $data->foto : null;
        
        $teacher_stmt->bind_param("issss", $user_id, $data->nip, $alamat, $telepon, $foto);
        if (!$teacher_stmt->execute()) {
            throw new Exception("Gagal mengeksekusi query guru: " . $teacher_stmt->error);
        }
        $teacher_id = $db->insert_id;

        // Commit transaction
        if (!$db->commit()) {
            throw new Exception("Gagal melakukan commit transaksi: " . $db->error);
        }

        http_response_code(201);
        echo json_encode([
            'id' => $teacher_id,
            'pengguna_id' => $user_id,
            'nip' => $data->nip,
            'alamat' => $alamat,
            'telepon' => $telepon,
            'foto' => $foto,
            'dibuat_pada' => date('Y-m-d H:i:s'),
            'diperbarui_pada' => date('Y-m-d H:i:s'),
            'pengguna' => [
                'id' => $user_id,
                'nama' => $data->nama,
                'email' => $data->email,
                'peran' => 'guru',
                'dibuat_pada' => date('Y-m-d H:i:s'),
                'diperbarui_pada' => date('Y-m-d H:i:s')
            ]
        ]);
    } catch (Exception $e) {
        // Rollback transaction on error
        $db->rollback();
        error_log("Error in addTeacher: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'status' => 'error', 
            'message' => 'Terjadi kesalahan database: ' . $e->getMessage(),
            'debug' => [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]
        ]);
    }
}

// Update teacher
function updateTeacher($db, $id) {
    // Get PUT data
    $data = json_decode(file_get_contents("php://input"));
    
    // Check if teacher exists
    $check_query = "SELECT t.*, u.id as user_id, u.email, u.nama FROM guru t JOIN pengguna u ON t.pengguna_id = u.id WHERE t.id = ?";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Guru tidak ditemukan']);
        exit;
    }

    $teacher = $check_result->fetch_assoc();
    $user_id = $teacher['pengguna_id'];

    // Start transaction
    $db->begin_transaction();

    try {
        // Struktur data baru dengan "pengguna"
        if (isset($data->pengguna) && is_object($data->pengguna)) {
            // Update pengguna table dari objek pengguna
            $user_updates = [];
            $user_params = [];
            $user_types = "";

            if (isset($data->pengguna->email) && $data->pengguna->email !== $teacher['email']) {
                // Check if new email already exists
                $check_email_query = "SELECT id FROM pengguna WHERE email = ? AND id != ?";
                $check_email_stmt = $db->prepare($check_email_query);
                $check_email_stmt->bind_param("si", $data->pengguna->email, $user_id);
                $check_email_stmt->execute();
                $check_email_result = $check_email_stmt->get_result();

                if ($check_email_result->num_rows > 0) {
                    $db->rollback();
                    http_response_code(409);
                    echo json_encode(['status' => 'error', 'message' => 'Email sudah digunakan']);
                    exit;
                }

                $user_updates[] = "email = ?";
                $user_params[] = $data->pengguna->email;
                $user_types .= "s";
            }

            if (isset($data->pengguna->nama) && $data->pengguna->nama !== $teacher['nama']) {
                $user_updates[] = "nama = ?";
                $user_params[] = $data->pengguna->nama;
                $user_types .= "s";
            }

            if (isset($data->pengguna->kata_sandi)) {
                // Validate password length
                if (strlen($data->pengguna->kata_sandi) < 6) {
                    $db->rollback();
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Kata sandi minimal 6 karakter']);
                    exit;
                }

                $hashed_password = password_hash($data->pengguna->kata_sandi, PASSWORD_DEFAULT);
                $user_updates[] = "kata_sandi = ?";
                $user_params[] = $hashed_password;
                $user_types .= "s";
            }

            if (!empty($user_updates)) {
                $user_updates[] = "diperbarui_pada = NOW()";
                $user_query = "UPDATE pengguna SET " . implode(", ", $user_updates) . " WHERE id = ?";
                $user_params[] = $user_id;
                $user_types .= "i";
                
                $user_stmt = $db->prepare($user_query);
                
                if (!$user_stmt) {
                    throw new Exception("Gagal mempersiapkan query pengguna: " . $db->error);
                }
                
                $user_stmt->bind_param($user_types, ...$user_params);
                
                if (!$user_stmt->execute()) {
                    throw new Exception("Gagal mengeksekusi query pengguna: " . $user_stmt->error);
                }
            }
        } else {
            // Kompatibilitas dengan struktur lama (direct properties)
            $user_updates = [];
            $user_params = [];
            $user_types = "";

            if (isset($data->email) && $data->email !== $teacher['email']) {
                // Check if new email already exists
                $check_email_query = "SELECT id FROM pengguna WHERE email = ? AND id != ?";
                $check_email_stmt = $db->prepare($check_email_query);
                $check_email_stmt->bind_param("si", $data->email, $user_id);
                $check_email_stmt->execute();
                $check_email_result = $check_email_stmt->get_result();

                if ($check_email_result->num_rows > 0) {
                    $db->rollback();
                    http_response_code(409);
                    echo json_encode(['status' => 'error', 'message' => 'Email sudah digunakan']);
                    exit;
                }

                $user_updates[] = "email = ?";
                $user_params[] = $data->email;
                $user_types .= "s";
            }

            if (isset($data->nama) && $data->nama !== $teacher['nama']) {
                $user_updates[] = "nama = ?";
                $user_params[] = $data->nama;
                $user_types .= "s";
            }

            if (isset($data->kata_sandi)) {
                // Validate password length
                if (strlen($data->kata_sandi) < 6) {
                    $db->rollback();
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Kata sandi minimal 6 karakter']);
                    exit;
                }

                $hashed_password = password_hash($data->kata_sandi, PASSWORD_DEFAULT);
                $user_updates[] = "kata_sandi = ?";
                $user_params[] = $hashed_password;
                $user_types .= "s";
            }

            if (!empty($user_updates)) {
                $user_updates[] = "diperbarui_pada = NOW()";
                $user_query = "UPDATE pengguna SET " . implode(", ", $user_updates) . " WHERE id = ?";
                $user_params[] = $user_id;
                $user_types .= "i";
                
                $user_stmt = $db->prepare($user_query);
                
                if (!$user_stmt) {
                    throw new Exception("Gagal mempersiapkan query pengguna: " . $db->error);
                }
                
                $user_stmt->bind_param($user_types, ...$user_params);
                
                if (!$user_stmt->execute()) {
                    throw new Exception("Gagal mengeksekusi query pengguna: " . $user_stmt->error);
                }
            }
        }

        // Update guru table
        $teacher_updates = [];
        $teacher_params = [];
        $teacher_types = "";

        if (isset($data->nip) && $data->nip !== $teacher['nip']) {
            // Check if new NIP already exists
            $check_nip_query = "SELECT id FROM guru WHERE nip = ? AND id != ?";
            $check_nip_stmt = $db->prepare($check_nip_query);
            $check_nip_stmt->bind_param("si", $data->nip, $id);
            $check_nip_stmt->execute();
            $check_nip_result = $check_nip_stmt->get_result();

            if ($check_nip_result->num_rows > 0) {
                $db->rollback();
                http_response_code(409);
                echo json_encode(['status' => 'error', 'message' => 'NIP sudah digunakan']);
                exit;
            }

            $teacher_updates[] = "nip = ?";
            $teacher_params[] = $data->nip;
            $teacher_types .= "s";
        }

        if (isset($data->alamat)) {
            $teacher_updates[] = "alamat = ?";
            $teacher_params[] = $data->alamat;
            $teacher_types .= "s";
        }

        if (isset($data->telepon)) {
            $teacher_updates[] = "telepon = ?";
            $teacher_params[] = $data->telepon;
            $teacher_types .= "s";
        }

        if (isset($data->foto)) {
            $teacher_updates[] = "foto = ?";
            $teacher_params[] = $data->foto;
            $teacher_types .= "s";
        }

        if (!empty($teacher_updates)) {
            $teacher_updates[] = "diperbarui_pada = NOW()";
            $teacher_query = "UPDATE guru SET " . implode(", ", $teacher_updates) . " WHERE id = ?";
            $teacher_stmt = $db->prepare($teacher_query);
            
            $teacher_params[] = $id;
            $teacher_types .= "i";
            
            $teacher_stmt->bind_param($teacher_types, ...$teacher_params);
            if (!$teacher_stmt->execute()) {
                throw new Exception("Gagal mengeksekusi query guru: " . $teacher_stmt->error);
            }
        }

        // Commit transaction
        if (!$db->commit()) {
            throw new Exception("Gagal melakukan commit transaksi: " . $db->error);
        }

        // Get updated teacher data
        $query = "SELECT g.*, u.nama, u.email, u.peran, u.id as user_id,
                 u.dibuat_pada as user_dibuat_pada, u.diperbarui_pada as user_diperbarui_pada  
                 FROM guru g
                 JOIN pengguna u ON g.pengguna_id = u.id 
                 WHERE g.id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $updated_teacher = $result->fetch_assoc();

        http_response_code(200);
        echo json_encode([
            'id' => $updated_teacher['id'],
            'pengguna_id' => $updated_teacher['pengguna_id'],
            'nip' => $updated_teacher['nip'],
            'alamat' => $updated_teacher['alamat'],
            'telepon' => $updated_teacher['telepon'],
            'foto' => $updated_teacher['foto'],
            'dibuat_pada' => $updated_teacher['dibuat_pada'],
            'diperbarui_pada' => $updated_teacher['diperbarui_pada'],
            'pengguna' => [
                'id' => $updated_teacher['user_id'],
                'nama' => $updated_teacher['nama'],
                'email' => $updated_teacher['email'],
                'peran' => $updated_teacher['peran'],
                'dibuat_pada' => $updated_teacher['user_dibuat_pada'],
                'diperbarui_pada' => $updated_teacher['user_diperbarui_pada']
            ]
        ]);
    } catch (Exception $e) {
        $db->rollback();
        error_log("Error in updateTeacher: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Terjadi kesalahan saat memperbarui guru: ' . $e->getMessage(),
            'debug' => [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]
        ]);
    }
}

// Delete teacher
function deleteTeacher($db, $id) {
    // Check if teacher exists
    $check_query = "SELECT pengguna_id FROM guru WHERE id = ?";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Guru tidak ditemukan']);
        exit;
    }

    $teacher = $check_result->fetch_assoc();
    $user_id = $teacher['pengguna_id'];

    // Start transaction
    $db->begin_transaction();

    try {
        // Delete from guru table
        $teacher_query = "DELETE FROM guru WHERE id = ?";
        $teacher_stmt = $db->prepare($teacher_query);
        $teacher_stmt->bind_param("i", $id);
        $teacher_stmt->execute();

        // Delete from pengguna table
        $user_query = "DELETE FROM pengguna WHERE id = ?";
        $user_stmt = $db->prepare($user_query);
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();

        // Commit transaction
        $db->commit();

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Guru berhasil dihapus'
        ]);
    } catch (Exception $e) {
        // Rollback transaction on error
        $db->rollback();
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Terjadi kesalahan database: ' . $e->getMessage()]);
    }
}
?> 
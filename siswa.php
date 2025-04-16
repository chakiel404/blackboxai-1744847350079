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
require_once __DIR__ . '/middleware/validation_helper.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Fix any null diperbarui_pada values in existing records
    $fix_query = "UPDATE siswa SET diperbarui_pada = dibuat_pada WHERE diperbarui_pada IS NULL";
    $db->query($fix_query);
    
    $fix_query = "UPDATE pengguna SET diperbarui_pada = dibuat_pada WHERE diperbarui_pada IS NULL";
    $db->query($fix_query);
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Database connection error",
        "debug" => [
            "error" => $e->getMessage(),
            "trace" => $e->getTraceAsString()
        ]
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents("php://input"));

// Support method override for mobile compatibility
if ($method === 'POST' && !empty($data) && isset($data->_method)) {
    $allowed_methods = ['PUT', 'DELETE'];
    $override_method = strtoupper($data->_method);
    
    if (in_array($override_method, $allowed_methods)) {
        error_log("Method override detected: POST -> $override_method");
        $method = $override_method;
    }
}

// Verify token and get user
$user = verifyToken();
if (!$user) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit();
}

// Check if user is admin or guru
$is_admin = $user['peran'] === 'admin';
$is_teacher = $user['peran'] === 'guru';

if (!$is_admin && !$is_teacher) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Akses ditolak"]);
    exit();
}

$teacher_user = $is_teacher ? $user : null;

switch($method) {
    case 'GET':
        // Log user role and request
        error_log("GET /siswa.php - User role: " . $user['peran']);
        
        $kelas_id = isset($_GET['kelas_id']) ? $_GET['kelas_id'] : null;
        $mata_pelajaran_id = isset($_GET['mata_pelajaran_id']) ? $_GET['mata_pelajaran_id'] : null;
        $search = isset($_GET['search']) ? $_GET['search'] : null;
        $page = isset($_GET['page']) ? $_GET['page'] : 1;
        $per_page = isset($_GET['per_halaman']) ? $_GET['per_halaman'] : 10;
        
        // Start with base query that joins pengguna and kelas
        if ($is_admin) {
            // For admin, get all students
            $query = "SELECT s.*, 
                      p.nama, p.email, p.peran, 
                      k.nama as nama_kelas
                      FROM siswa s 
                      JOIN pengguna p ON s.pengguna_id = p.id
                      JOIN kelas k ON s.kelas_id = k.id";
            
            // Add search if provided
            if ($search) {
                $query .= " WHERE (p.nama LIKE ? OR s.nis LIKE ? OR p.email LIKE ?)";
                $searchParam = "%$search%";
            }
            
            // Add kelas filter if provided
            if ($kelas_id) {
                if (strpos($query, 'WHERE') !== false) {
                    $query .= " AND s.kelas_id = ?";
                } else {
                    $query .= " WHERE s.kelas_id = ?";
                }
            }
            
            // Add ORDER BY
            $query .= " ORDER BY s.id DESC";
            
            // Prepare statement with the appropriate parameters
            $stmt = $db->prepare($query);
            if (!$stmt) {
                error_log("Error preparing query: " . $db->error);
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Database error: " . $db->error]);
                exit;
            }
            
            // Bind parameters in the correct order
            if ($search && $kelas_id) {
                $stmt->bind_param("sssi", $searchParam, $searchParam, $searchParam, $kelas_id);
            } else if ($search) {
                $stmt->bind_param("sss", $searchParam, $searchParam, $searchParam);
            } else if ($kelas_id) {
                $stmt->bind_param("i", $kelas_id);
            }
            
            // Log query for debugging
            error_log("Running query: " . $query . " with params: " . json_encode([
                'search' => $search,
                'kelas_id' => $kelas_id
            ]));
        } else if ($is_teacher) {
            // Existing teacher queries remain the same
            if ($kelas_id && $mata_pelajaran_id) {
                // Get students by class and subject
                $query = "SELECT s.*, k.nama as nama_kelas, mp.nama as nama_mata_pelajaran 
                         FROM siswa s 
                         JOIN kelas k ON s.kelas_id = k.id 
                         JOIN mata_pelajaran mp ON ? = mp.id 
                         WHERE s.kelas_id = ? 
                         AND EXISTS (
                             SELECT 1 FROM jadwal j 
                             WHERE j.kelas_id = s.kelas_id 
                             AND j.mata_pelajaran_id = ? 
                             AND j.guru_id = ?
                         )";
                $stmt = $db->prepare($query);
                $stmt->bind_param("iiii", $mata_pelajaran_id, $kelas_id, $mata_pelajaran_id, $teacher_user['id']);
            } else if ($kelas_id) {
                // Get students by class
                $query = "SELECT s.*, k.nama as nama_kelas 
                         FROM siswa s 
                         JOIN kelas k ON s.kelas_id = k.id 
                         WHERE s.kelas_id = ? 
                         AND EXISTS (
                             SELECT 1 FROM jadwal j 
                             WHERE j.kelas_id = s.kelas_id 
                             AND j.guru_id = ?
                         )";
                $stmt = $db->prepare($query);
                $stmt->bind_param("ii", $kelas_id, $teacher_user['id']);
            } else if ($mata_pelajaran_id) {
                // Get students by subject
                $query = "SELECT DISTINCT s.*, k.nama as nama_kelas, mp.nama as nama_mata_pelajaran 
                         FROM siswa s 
                         JOIN kelas k ON s.kelas_id = k.id 
                         JOIN mata_pelajaran mp ON ? = mp.id 
                         JOIN jadwal j ON j.kelas_id = s.kelas_id 
                         WHERE j.mata_pelajaran_id = ? 
                         AND j.guru_id = ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param("iii", $mata_pelajaran_id, $mata_pelajaran_id, $teacher_user['id']);
            } else {
                // Get all students for teacher's classes
                $query = "SELECT DISTINCT s.*, k.nama as nama_kelas 
                         FROM siswa s 
                         JOIN kelas k ON s.kelas_id = k.id 
                         JOIN jadwal j ON j.kelas_id = s.kelas_id 
                         WHERE j.guru_id = ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param("i", $teacher_user['id']);
            }
        }
        
        // Execute the query
        $stmt->execute();
        $result = $stmt->get_result();
        
        // Check if we got an error
        if (!$result) {
            error_log("Database error: " . $db->error);
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Database error: " . $db->error]);
            exit;
        }
        
        $siswa = [];
        while ($row = $result->fetch_assoc()) {
            // Get the user data
            $user_id = $row['pengguna_id'];
            $user_query = "SELECT id, nama, email, peran, dibuat_pada, diperbarui_pada FROM pengguna WHERE id = ?";
            $user_stmt = $db->prepare($user_query);
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_data = $user_result->fetch_assoc();
            
            // Ensure no null values in user data
            $user_data['nama'] = $user_data['nama'] ?? "";
            $user_data['email'] = $user_data['email'] ?? "";
            $user_data['peran'] = $user_data['peran'] ?? "";
            $user_data['dibuat_pada'] = $user_data['dibuat_pada'] ?? date("Y-m-d H:i:s");
            $user_data['diperbarui_pada'] = $user_data['diperbarui_pada'] ?? date("Y-m-d H:i:s");
            
            // Format student data properly
            $student = [
                'id' => $row['id'],
                'pengguna_id' => $row['pengguna_id'],
                'nis' => $row['nis'],
                'kelas_id' => $row['kelas_id'],
                'telepon' => $row['telepon'] ?? "",
                'alamat' => $row['alamat'] ?? "",
                'foto' => $row['foto'] ?? "",
                'dibuat_pada' => $row['dibuat_pada'] ?? date("Y-m-d H:i:s"),
                'diperbarui_pada' => $row['diperbarui_pada'] ?? date("Y-m-d H:i:s"),
                'pengguna' => $user_data,
                'kelas' => [
                    'id' => $row['kelas_id'],
                    'nama' => $row['nama_kelas'] ?? ""
                ]
            ];
            
            // Add any additional data from the teacher queries
            if (isset($row['nama_mata_pelajaran'])) {
                $student['mata_pelajaran'] = [
                    'id' => $mata_pelajaran_id,
                    'nama' => $row['nama_mata_pelajaran']
                ];
            }
            
            // Add assignment status for each student if subject_id is provided
            if ($mata_pelajaran_id) {
                $query = "SELECT COUNT(*) as total_tugas, 
                         SUM(CASE WHEN pt.status = 'diterima' THEN 1 ELSE 0 END) as tugas_diterima,
                         SUM(CASE WHEN pt.status = 'perlu_revisi' THEN 1 ELSE 0 END) as tugas_revisi
                         FROM tugas t 
                         LEFT JOIN pengumpulan_tugas pt ON t.id = pt.tugas_id AND pt.siswa_id = ?
                         WHERE t.mata_pelajaran_id = ? AND t.kelas_id = ?";
                
                $stmt = $db->prepare($query);
                $stmt->bind_param("iii", $row['id'], $mata_pelajaran_id, $row['kelas_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                $stats = $result->fetch_assoc();
                $student['statistik_tugas'] = $stats;
            }
            
            $siswa[] = $student;
        }
        
        // Log response
        error_log("Returning " . count($siswa) . " students");
        
        // Return JSON response
        header('Content-Type: application/json');
        echo json_encode($siswa);
        break;

    case 'POST':
        if (!empty($data->email) && !empty($data->password) && !empty($data->nis) && 
            !empty($data->nama) && !empty($data->kelas_id)) {
            
            // Log request data for debugging
            error_log("Creating student with NIS: " . $data->nis . " (type: " . gettype($data->nis) . ")");
            
            // Validasi password
            if (!validatePassword($data->password)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Password minimal 8 karakter"]);
                exit;
            }

            // Validasi email
            if (!validateEmail($data->email)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Format email tidak valid"]);
                exit;
            }

            // Validasi telepon jika ada
            if (isset($data->telepon) && !validatePhone($data->telepon)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Format nomor telepon tidak valid"]);
                exit;
            }
            
            // Ensure NIS is a string for validation
            $data->nis = (string)$data->nis;
            
            // Debug dump of NIS data
            error_log("Raw data dump for NIS: " . json_encode([
                'original' => $data->nis,
                'type' => gettype($data->nis),
                'length' => strlen($data->nis),
                'isEmpty' => empty($data->nis),
                'isNull' => $data->nis === null,
                'ctype_digit' => ctype_digit($data->nis),
                'is_numeric' => is_numeric($data->nis),
                'cleanValue' => preg_replace('/[^0-9]/', '', $data->nis)
            ]));
            
            // Validasi format NIS
            if (!validateNIS($data->nis)) {
                error_log("NIS validation failed for: " . $data->nis);
                http_response_code(400);
                echo json_encode([
                    "status" => "error", 
                    "message" => "Format NIS tidak valid",
                    "debug" => [
                        "nis" => $data->nis,
                        "type" => gettype($data->nis),
                        "length" => strlen($data->nis),
                        "cleaned" => preg_replace('/[^0-9]/', '', $data->nis),
                        "requirements" => "NIS harus berisi 5-20 digit angka tanpa spasi atau karakter khusus"
                    ]
                ]);
                exit;
            }

            // Cek duplikasi NIS
            $check_query = "SELECT 1 FROM siswa WHERE nis = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bind_param("s", $data->nis);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "NIS sudah terdaftar"]);
                exit;
            }

            // Cek keberadaan kelas
            $check_query = "SELECT 1 FROM kelas WHERE id = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bind_param("i", $data->kelas_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows === 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Kelas tidak ditemukan"]);
                exit;
            }

            try {
                $database->beginTransaction();

                // Create user first
                $query = "INSERT INTO pengguna (nama, email, kata_sandi, peran, dibuat_pada) VALUES (?, ?, ?, 'siswa', NOW())";
                $stmt = $db->prepare($query);
                if (!$stmt) {
                    throw new Exception("Gagal mempersiapkan query pengguna: " . $db->error);
                }
                
                $password = password_hash($data->password, PASSWORD_DEFAULT);
                $stmt->bind_param("sss", $data->nama, $data->email, $password);
                if (!$stmt->execute()) {
                    throw new Exception("Gagal mengeksekusi query pengguna: " . $stmt->error);
                }
                
                $pengguna_id = $db->insert_id;

                // Then create student - ensure we handle alamat properly
                $query = "INSERT INTO siswa (pengguna_id, nis, kelas_id, telepon, alamat, foto, dibuat_pada) VALUES (?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $db->prepare($query);
                if (!$stmt) {
                    throw new Exception("Gagal mempersiapkan query siswa: " . $db->error);
                }
                
                // Improved handling of null/empty values
                $telepon = !empty($data->telepon) ? $data->telepon : null;
                $alamat = !empty($data->alamat) ? $data->alamat : null;
                $foto = !empty($data->foto) ? $data->foto : null;
                
                // Extra logging for alamat field
                error_log("Alamat value: " . ($alamat === null ? "NULL" : $alamat));
                
                // If foto string kosong, jadikan NULL
                if ($foto === '') {
                    $foto = null;
                }
                
                error_log("Binding params for student: nis=" . $data->nis . ", kelas_id=" . $data->kelas_id . ", telepon=" . ($telepon ?? 'NULL') . ", alamat=" . ($alamat ?? 'NULL') . ", foto=" . ($foto ? 'HAS_FOTO' : 'NULL'));
                
                $stmt->bind_param("isssss", 
                    $pengguna_id, 
                    $data->nis, 
                    $data->kelas_id, 
                    $telepon, 
                    $alamat, 
                    $foto
                );
                if (!$stmt->execute()) {
                    throw new Exception("Gagal mengeksekusi query siswa: " . $stmt->error);
                }

                $database->commit();

                http_response_code(201);
                echo json_encode([
                    "status" => "success", 
                    "message" => "Siswa berhasil ditambahkan",
                    "data" => [
                        "id" => $db->insert_id,
                        "pengguna_id" => $pengguna_id,
                        "nis" => $data->nis,
                        "nama" => $data->nama,
                        "email" => $data->email,
                        "kelas_id" => $data->kelas_id,
                        "telepon" => $data->telepon ?? "",
                        "alamat" => $data->alamat ?? "",
                        "foto" => $data->foto ?? "",
                        "dibuat_pada" => date("Y-m-d H:i:s"),
                        "diperbarui_pada" => date("Y-m-d H:i:s")
                    ]
                ]);
            } catch (Exception $e) {
                $database->rollback();
                error_log("Error in addStudent: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    "status" => "error", 
                    "message" => "Gagal menambahkan siswa: " . $e->getMessage(),
                    "debug" => [
                        "error" => $e->getMessage(),
                        "trace" => $e->getTraceAsString()
                    ]
                ]);
            }
        } else {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Semua field wajib diisi"]);
        }
        break;

    case 'PUT':
        // Get ID from query parameter as fallback
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        if (empty($id) && !empty($data->id)) {
            $id = $data->id;
        }
        
        if (!empty($id) && !empty($data->nis) && !empty($data->nama) && !empty($data->kelas_id)) {
            // Set the ID in data object if it was from query parameter
            $data->id = $id;
            
            // Log update request data
            error_log("Updating student ID " . $data->id . " with NIS: " . $data->nis . " (type: " . gettype($data->nis) . ")");
            
            // Ensure NIS is a string for validation
            $data->nis = (string)$data->nis;
            
            // Debug dump of NIS data for update
            error_log("Raw data dump for NIS UPDATE: " . json_encode([
                'original' => $data->nis,
                'type' => gettype($data->nis),
                'length' => strlen($data->nis),
                'isEmpty' => empty($data->nis),
                'isNull' => $data->nis === null,
                'ctype_digit' => ctype_digit($data->nis),
                'is_numeric' => is_numeric($data->nis),
                'cleanValue' => preg_replace('/[^0-9]/', '', $data->nis)
            ]));
            
            // Validasi format NIS
            if (!validateNIS($data->nis)) {
                error_log("NIS validation failed for update: " . $data->nis);
                http_response_code(400);
                echo json_encode([
                    "status" => "error", 
                    "message" => "Format NIS tidak valid",
                    "debug" => [
                        "nis" => $data->nis,
                        "type" => gettype($data->nis),
                        "length" => strlen($data->nis),
                        "cleaned" => preg_replace('/[^0-9]/', '', $data->nis),
                        "requirements" => "NIS harus berisi 5-20 digit angka tanpa spasi atau karakter khusus"
                    ]
                ]);
                exit;
            }

            // Cek duplikasi NIS
            $check_query = "SELECT 1 FROM siswa WHERE nis = ? AND id != ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bind_param("si", $data->nis, $data->id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "NIS sudah terdaftar"]);
                exit;
            }

            // Cek keberadaan kelas
            $check_query = "SELECT 1 FROM kelas WHERE id = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bind_param("i", $data->kelas_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows === 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Kelas tidak ditemukan"]);
                exit;
            }

            $query = "UPDATE siswa SET nis = ?, kelas_id = ?, telepon = ?, alamat = ?, foto = ?, diperbarui_pada = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt = $db->prepare($query);
            
            // Improved handling of null/empty values
            $telepon = !empty($data->telepon) ? $data->telepon : null;
            $alamat = !empty($data->alamat) ? $data->alamat : null;
            $foto = !empty($data->foto) ? $data->foto : null;
            
            // Extra logging for alamat field
            error_log("Alamat value for update: " . ($alamat === null ? "NULL" : $alamat));
            
            // If foto is empty string, set it to null
            if ($foto === '') {
                $foto = null;
            }
            
            error_log("Binding params for updating student: id=" . $data->id . ", nis=" . $data->nis . ", kelas_id=" . $data->kelas_id . ", telepon=" . ($telepon ?? 'NULL') . ", alamat=" . ($alamat ?? 'NULL') . ", foto=" . ($foto ? 'HAS_FOTO' : 'NULL'));
            
            $stmt->bind_param("sisssi", 
                $data->nis, 
                $data->kelas_id, 
                $telepon, 
                $alamat, 
                $foto,
                $data->id
            );
            
            // Also update the related user information
            $query2 = "UPDATE pengguna SET nama = ?, email = ?, diperbarui_pada = CURRENT_TIMESTAMP WHERE id = (SELECT pengguna_id FROM siswa WHERE id = ?)";
            $stmt2 = $db->prepare($query2);
            $stmt2->bind_param("ssi", $data->nama, $data->email, $data->id);
            
            try {
                $database->beginTransaction();
                $stmt->execute();
                $stmt2->execute();
                $database->commit();
                
                // Get the updated student data
                $query = "SELECT s.*, p.nama, p.email 
                        FROM siswa s 
                        JOIN pengguna p ON s.pengguna_id = p.id 
                        WHERE s.id = ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param("i", $data->id);
                $stmt->execute();
                $result = $stmt->get_result();
                $student = $result->fetch_assoc();
                
                http_response_code(200);
                echo json_encode([
                    "status" => "success", 
                    "message" => "Siswa berhasil diperbarui",
                    "data" => [
                        "id" => $student['id'],
                        "pengguna_id" => $student['pengguna_id'],
                        "nis" => $student['nis'],
                        "nama" => $student['nama'],
                        "email" => $student['email'],
                        "kelas_id" => $student['kelas_id'],
                        "telepon" => $student['telepon'] ?? "",
                        "alamat" => $student['alamat'] ?? "",
                        "foto" => $student['foto'] ?? "",
                        "dibuat_pada" => $student['dibuat_pada'] ?? date("Y-m-d H:i:s"),
                        "diperbarui_pada" => $student['diperbarui_pada'] ?? date("Y-m-d H:i:s")
                    ]
                ]);
            } catch (Exception $e) {
                $database->rollback();
                error_log("Error updating student: " . $e->getMessage());
                http_response_code(400);
                echo json_encode([
                    "status" => "error", 
                    "message" => "Gagal memperbarui siswa: " . $e->getMessage(),
                    "debug" => [
                        "error" => $e->getMessage(),
                        "trace" => $e->getTraceAsString()
                    ]
                ]);
            }
        } else {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID siswa, NIS, nama dan ID kelas wajib diisi"]);
        }
        break;

    case 'DELETE':
        // Get ID from query parameter as fallback
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        if (empty($id) && !empty($data->id)) {
            $id = $data->id;
        }
        
        if (!empty($id)) {
            try {
                $database->beginTransaction();

                // Get pengguna_id first
                $query = "SELECT pengguna_id FROM siswa WHERE id = ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();

                if ($row) {
                    // Delete student
                    $query = "DELETE FROM siswa WHERE id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->bind_param("i", $id);
                    $stmt->execute();

                    // Delete user
                    $query = "DELETE FROM pengguna WHERE id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->bind_param("i", $row['pengguna_id']);
                    $stmt->execute();

                    $database->commit();
                    http_response_code(200);
                    echo json_encode(["status" => "success", "message" => "Siswa berhasil dihapus"]);
                } else {
                    throw new Exception("Siswa tidak ditemukan");
                }
            } catch (Exception $e) {
                $database->rollback();
                error_log("Error deleting student: " . $e->getMessage());
                http_response_code(400);
                echo json_encode([
                    "status" => "error", 
                    "message" => "Gagal menghapus siswa: " . $e->getMessage(),
                    "debug" => [
                        "error" => $e->getMessage(),
                        "trace" => $e->getTraceAsString()
                    ]
                ]);
            }
        } else {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID siswa wajib diisi"]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Metode tidak diizinkan"]);
        break;
}
?> 
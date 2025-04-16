<?php
require_once __DIR__ . '/../config/jwt.php';

function generateJWT($payload) {
    return JWT::encode($payload);
}

function verifyToken($returnData = false) {
    $headers = getallheaders();
    
    if (!isset($headers['Authorization'])) {
        error_log("AUTH DEBUG: No Authorization header found");
        if ($returnData) {
            return null;
        }
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Token tidak ditemukan"]);
        exit();
    }

    $token = str_replace('Bearer ', '', $headers['Authorization']);
    error_log("AUTH DEBUG: Token received: " . substr($token, 0, 20) . "...");
    $decoded = JWT::decode($token);

    if (!$decoded) {
        error_log("AUTH DEBUG: Token decode failed");
        if ($returnData) {
            return null;
        }
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Token tidak valid"]);
        exit();
    }

    error_log("AUTH DEBUG: Token decoded: " . json_encode($decoded));
    
    // Validate role is present
    if (!isset($decoded['peran']) || empty($decoded['peran']) || !in_array($decoded['peran'], ['admin', 'guru', 'siswa'])) {
        error_log("AUTH DEBUG: Invalid or missing role in token");
        if ($returnData) {
            return null;
        }
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Token tidak memiliki peran yang valid"]);
        exit();
    }
    
    // Log the role for debugging
    error_log("AUTH DEBUG: User role: " . $decoded['peran']);
    
    // Ensure ID is always an integer if it exists
    if (isset($decoded['id']) && !is_null($decoded['id'])) {
        $decoded['id'] = (int)$decoded['id'];
    }
    
    // Also ensure any other potential IDs in the token are integers if they exist
    if (isset($decoded['guru_id']) && !is_null($decoded['guru_id'])) {
        $decoded['guru_id'] = (int)$decoded['guru_id'];
    }
    
    if (isset($decoded['siswa_id']) && !is_null($decoded['siswa_id'])) {
        $decoded['siswa_id'] = (int)$decoded['siswa_id'];
    }
    
    if (isset($decoded['pengguna_id']) && !is_null($decoded['pengguna_id'])) {
        $decoded['pengguna_id'] = (int)$decoded['pengguna_id'];
    }
    
    if (isset($decoded['kelas_id']) && !is_null($decoded['kelas_id'])) {
        $decoded['kelas_id'] = (int)$decoded['kelas_id'];
    }
    
    return $decoded;
}

function checkRole($allowedRoles) {
    $user = verifyToken();
    
    if (!in_array($user['peran'], $allowedRoles)) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Akses ditolak"]);
        exit();
    }
    
    return $user;
}

function checkMaterialOwnership($materiId) {
    global $db;
    $user = verifyToken();
    
    if ($user['peran'] === 'admin') {
        return true;
    }
    
    $stmt = $db->prepare("SELECT guru_id FROM materi m JOIN jadwal j ON m.jadwal_id = j.id WHERE m.id = ?");
    $stmt->bind_param("i", $materiId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Materi tidak ditemukan"]);
        exit();
    }
    
    $materi = $result->fetch_assoc();
    if ($user['peran'] === 'guru' && $materi['guru_id'] != $user['id']) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Akses ditolak. Anda hanya dapat mengakses materi yang anda buat"]);
        exit();
    }
    
    return true;
}

function checkAssignmentOwnership($tugasId) {
    global $db;
    $user = verifyToken();
    
    if ($user['peran'] === 'admin') {
        return true;
    }
    
    $stmt = $db->prepare("SELECT guru_id FROM tugas t JOIN jadwal j ON t.jadwal_id = j.id WHERE t.id = ?");
    $stmt->bind_param("i", $tugasId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Tugas tidak ditemukan"]);
        exit();
    }
    
    $tugas = $result->fetch_assoc();
    if ($user['peran'] === 'guru' && $tugas['guru_id'] != $user['id']) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Akses ditolak. Anda hanya dapat mengakses tugas yang anda buat"]);
        exit();
    }
    
    return true;
}

function checkClassAccess($kelasId) {
    global $db;
    $user = verifyToken();
    
    if ($user['peran'] === 'admin') {
        return true;
    }
    
    // Cek apakah guru mengajar di kelas ini
    if ($user['peran'] === 'guru') {
        $stmt = $db->prepare("SELECT 1 FROM jadwal WHERE kelas_id = ? AND guru_id = ?");
        $stmt->bind_param("ii", $kelasId, $user['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Akses ditolak. Anda tidak mengajar di kelas ini"]);
            exit();
        }
    }
    
    // Cek apakah siswa berada di kelas ini
    if ($user['peran'] === 'siswa') {
        $stmt = $db->prepare("SELECT 1 FROM siswa WHERE pengguna_id = ? AND kelas_id = ?");
        $stmt->bind_param("ii", $user['id'], $kelasId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Akses ditolak. Anda tidak terdaftar di kelas ini"]);
            exit();
        }
    }
    
    return true;
}

function getTeacherMaterials($guruId) {
    global $db;
    $user = verifyToken();
    
    if ($user['peran'] === 'admin' || ($user['peran'] === 'guru' && $user['id'] == $guruId)) {
        $stmt = $db->prepare("
            SELECT m.*, j.hari, j.waktu_mulai, j.waktu_selesai, mp.nama as nama_mapel, k.nama as nama_kelas
            FROM materi m
            JOIN jadwal j ON m.jadwal_id = j.id
            JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id
            JOIN kelas k ON j.kelas_id = k.id
            WHERE j.guru_id = ?
            ORDER BY m.dibuat_pada DESC");
        $stmt->bind_param("i", $guruId);
        $stmt->execute();
        $result = $stmt->get_result();
        $materials = [];
        
        while ($row = $result->fetch_assoc()) {
            $materials[] = $row;
        }
        
        return $materials;
    } else {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Akses ditolak"]);
        exit();
    }
}

function getStudentMaterials($siswaId) {
    global $db;
    $user = verifyToken();
    
    if ($user['peran'] === 'admin' || ($user['peran'] === 'siswa' && $user['id'] == $siswaId)) {
        $stmt = $db->prepare("
            SELECT m.*, j.hari, j.waktu_mulai, j.waktu_selesai, mp.nama as nama_mapel, k.nama as nama_kelas, g.nama as nama_guru
            FROM materi m
            JOIN jadwal j ON m.jadwal_id = j.id
            JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id
            JOIN kelas k ON j.kelas_id = k.id
            JOIN guru g ON j.guru_id = g.id
            JOIN siswa s ON s.kelas_id = k.id
            WHERE s.pengguna_id = ?
            ORDER BY m.dibuat_pada DESC
        ");
        $stmt->bind_param("i", $siswaId);
        $stmt->execute();
        $result = $stmt->get_result();
        $materials = [];
        
        while ($row = $result->fetch_assoc()) {
            $materials[] = $row;
        }
        
        return $materials;
    } else {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Akses ditolak"]);
        exit();
    }
}

function getTeacherAssignments($guruId) {
    global $db;
    $user = verifyToken();
    
    if ($user['peran'] === 'admin' || ($user['peran'] === 'guru' && $user['id'] == $guruId)) {
        $stmt = $db->prepare("
            SELECT t.*, j.hari, j.waktu_mulai, j.waktu_selesai, mp.nama as nama_mapel, k.nama as nama_kelas
            FROM tugas t
            JOIN jadwal j ON t.jadwal_id = j.id
            JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id
            JOIN kelas k ON j.kelas_id = k.id
            WHERE j.guru_id = ?
            ORDER BY t.tanggal_jatuh_tempo ASC");
        $stmt->bind_param("i", $guruId);
        $stmt->execute();
        $result = $stmt->get_result();
        $assignments = [];
        
        while ($row = $result->fetch_assoc()) {
            $assignments[] = $row;
        }
        
        return $assignments;
    } else {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Akses ditolak"]);
        exit();
    }
}

function getStudentAssignments($siswaId) {
    global $db;
    $user = verifyToken();
    
    if ($user['peran'] === 'admin' || ($user['peran'] === 'siswa' && $user['id'] == $siswaId)) {
        $stmt = $db->prepare("
            SELECT t.*, j.hari, j.waktu_mulai, j.waktu_selesai, mp.nama as nama_mapel, k.nama as nama_kelas,
                  g.nama as nama_guru, pt.status, pt.dikumpulkan_pada, n.skor
            FROM tugas t
            JOIN jadwal j ON t.jadwal_id = j.id
            JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id
            JOIN kelas k ON j.kelas_id = k.id
            JOIN guru g ON j.guru_id = g.id
            JOIN siswa s ON s.kelas_id = k.id
            LEFT JOIN pengumpulan_tugas pt ON pt.tugas_id = t.id AND pt.siswa_id = s.id
            LEFT JOIN nilai n ON n.pengumpulan_tugas_id = pt.id
            WHERE s.pengguna_id = ?
            ORDER BY t.tanggal_jatuh_tempo ASC
        ");
        $stmt->bind_param("i", $siswaId);
        $stmt->execute();
        $result = $stmt->get_result();
        $assignments = [];
        
        while ($row = $result->fetch_assoc()) {
            $assignments[] = $row;
        }
        
        return $assignments;
    } else {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Akses ditolak"]);
        exit();
    }
}

// Fungsi untuk mendapatkan jadwal kelas
function getClassSchedule($kelasId) {
    global $db;
    $user = verifyToken();
    
    // Pemeriksaan akses kelas sudah dilakukan di checkClassAccess()
    checkClassAccess($kelasId);
    
    $stmt = $db->prepare("
        SELECT j.*, mp.nama as nama_mapel, mp.kode as kode_mapel, g.nama as nama_guru 
        FROM jadwal j
        JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id
        JOIN guru g ON j.guru_id = g.id
        WHERE j.kelas_id = ?
        ORDER BY 
            CASE j.hari
                WHEN 'Senin' THEN 1
                WHEN 'Selasa' THEN 2
                WHEN 'Rabu' THEN 3
                WHEN 'Kamis' THEN 4
                WHEN 'Jumat' THEN 5
                WHEN 'Sabtu' THEN 6
                WHEN 'Minggu' THEN 7
            END,
            j.waktu_mulai ASC
    ");
    $stmt->bind_param("i", $kelasId);
    $stmt->execute();
    $result = $stmt->get_result();
    $schedules = [];
    
    while ($row = $result->fetch_assoc()) {
        $schedules[] = $row;
    }
    
    return $schedules;
}

// Fungsi untuk mendapatkan materi berdasarkan mata pelajaran
function getSubjectMaterials($mapelId) {
    global $db;
    $user = verifyToken();
    
    if ($user['peran'] === 'admin') {
        $stmt = $db->prepare("
            SELECT m.*, j.hari, j.waktu_mulai, j.waktu_selesai, k.nama as nama_kelas, g.nama as nama_guru 
            FROM materi m
            JOIN jadwal j ON m.jadwal_id = j.id
            JOIN kelas k ON j.kelas_id = k.id
            JOIN guru g ON j.guru_id = g.id
            WHERE j.mata_pelajaran_id = ?
            ORDER BY m.dibuat_pada DESC
        ");
        $stmt->bind_param("i", $mapelId);
    } else if ($user['peran'] === 'guru') {
        $stmt = $db->prepare("
            SELECT m.*, j.hari, j.waktu_mulai, j.waktu_selesai, k.nama as nama_kelas 
            FROM materi m
            JOIN jadwal j ON m.jadwal_id = j.id
            JOIN kelas k ON j.kelas_id = k.id
            WHERE j.mata_pelajaran_id = ? AND j.guru_id = ?
            ORDER BY m.dibuat_pada DESC
        ");
        $stmt->bind_param("ii", $mapelId, $user['id']);
    } else if ($user['peran'] === 'siswa') {
        // Mengambil id siswa dari tabel siswa berdasarkan pengguna_id
        $siswaStmt = $db->prepare("SELECT id, kelas_id FROM siswa WHERE pengguna_id = ?");
        $siswaStmt->bind_param("i", $user['id']);
        $siswaStmt->execute();
        $siswaResult = $siswaStmt->get_result();
        $siswa = $siswaResult->fetch_assoc();
        
        if (!$siswa) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Data siswa tidak ditemukan"]);
            exit();
        }
        
        $stmt = $db->prepare("
            SELECT m.*, j.hari, j.waktu_mulai, j.waktu_selesai, g.nama as nama_guru 
            FROM materi m
            JOIN jadwal j ON m.jadwal_id = j.id
            JOIN guru g ON j.guru_id = g.id
            WHERE j.mata_pelajaran_id = ? AND j.kelas_id = ?
            ORDER BY m.dibuat_pada DESC
        ");
        $stmt->bind_param("ii", $mapelId, $siswa['kelas_id']);
    } else {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Akses ditolak"]);
        exit();
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $materials = [];
    
    while ($row = $result->fetch_assoc()) {
        $materials[] = $row;
    }
    
    return $materials;
}

// Fungsi untuk mendapatkan tugas berdasarkan mata pelajaran
function getSubjectAssignments($mapelId) {
    global $db;
    $user = verifyToken();
    
    if ($user['peran'] === 'admin') {
        $stmt = $db->prepare("
            SELECT t.*, j.hari, j.waktu_mulai, j.waktu_selesai, k.nama as nama_kelas, g.nama as nama_guru 
            FROM tugas t
            JOIN jadwal j ON t.jadwal_id = j.id
            JOIN kelas k ON j.kelas_id = k.id
            JOIN guru g ON j.guru_id = g.id
            WHERE j.mata_pelajaran_id = ?
            ORDER BY t.tanggal_jatuh_tempo ASC
        ");
        $stmt->bind_param("i", $mapelId);
    } else if ($user['peran'] === 'guru') {
        $stmt = $db->prepare("
            SELECT t.*, j.hari, j.waktu_mulai, j.waktu_selesai, k.nama as nama_kelas 
            FROM tugas t
            JOIN jadwal j ON t.jadwal_id = j.id
            JOIN kelas k ON j.kelas_id = k.id
            WHERE j.mata_pelajaran_id = ? AND j.guru_id = ?
            ORDER BY t.tanggal_jatuh_tempo ASC
        ");
        $stmt->bind_param("ii", $mapelId, $user['id']);
    } else if ($user['peran'] === 'siswa') {
        // Mengambil id siswa dari tabel siswa berdasarkan pengguna_id
        $siswaStmt = $db->prepare("SELECT id, kelas_id FROM siswa WHERE pengguna_id = ?");
        $siswaStmt->bind_param("i", $user['id']);
        $siswaStmt->execute();
        $siswaResult = $siswaStmt->get_result();
        $siswa = $siswaResult->fetch_assoc();
        
        if (!$siswa) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Data siswa tidak ditemukan"]);
            exit();
        }
        
        $stmt = $db->prepare("
            SELECT t.*, j.hari, j.waktu_mulai, j.waktu_selesai, g.nama as nama_guru, 
                   pt.status, pt.dikumpulkan_pada, n.skor
            FROM tugas t
            JOIN jadwal j ON t.jadwal_id = j.id
            JOIN guru g ON j.guru_id = g.id
            LEFT JOIN pengumpulan_tugas pt ON pt.tugas_id = t.id AND pt.siswa_id = ?
            LEFT JOIN nilai n ON n.pengumpulan_tugas_id = pt.id
            WHERE j.mata_pelajaran_id = ? AND j.kelas_id = ?
            ORDER BY t.tanggal_jatuh_tempo ASC
        ");
        $stmt->bind_param("iii", $siswa['id'], $mapelId, $siswa['kelas_id']);
    } else {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Akses ditolak"]);
        exit();
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $assignments = [];
    
    while ($row = $result->fetch_assoc()) {
        $assignments[] = $row;
    }
    
    return $assignments;
}

// Fungsi untuk mendapatkan kelas yang diajar guru
function getTeacherClasses($guruId) {
    global $db;
    $user = verifyToken();
    
    if ($user['peran'] === 'admin' || ($user['peran'] === 'guru' && $user['id'] == $guruId)) {
        $stmt = $db->prepare("
            SELECT DISTINCT k.*, COUNT(DISTINCT s.id) as jumlah_siswa
            FROM kelas k
            JOIN jadwal j ON k.id = j.kelas_id
            LEFT JOIN siswa s ON s.kelas_id = k.id
            WHERE j.guru_id = ?
            GROUP BY k.id
            ORDER BY k.tingkat ASC, k.nama ASC
        ");
        $stmt->bind_param("i", $guruId);
        $stmt->execute();
        $result = $stmt->get_result();
        $classes = [];
        
        while ($row = $result->fetch_assoc()) {
            $classes[] = $row;
        }
        
        return $classes;
    } else {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Akses ditolak"]);
        exit();
    }
}

// Fungsi untuk mendapatkan nilai siswa
function getStudentGrades($siswaId) {
    global $db;
    $user = verifyToken();
    
    if ($user['peran'] === 'admin' || ($user['peran'] === 'siswa' && $user['id'] == $siswaId) || $user['peran'] === 'guru') {
        // Jika guru, perlu cek apakah siswa berada di kelas yang diajar
        if ($user['peran'] === 'guru') {
            $stmt = $db->prepare("
                SELECT 1
                FROM siswa s
                JOIN kelas k ON s.kelas_id = k.id
                JOIN jadwal j ON j.kelas_id = k.id
                WHERE s.pengguna_id = ? AND j.guru_id = ?
            ");
            $stmt->bind_param("ii", $siswaId, $user['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                http_response_code(403);
                echo json_encode(["status" => "error", "message" => "Akses ditolak. Anda tidak mengajar siswa ini"]);
                exit();
            }
        }
        
        $stmt = $db->prepare("
            SELECT rn.*, mp.nama as nama_mapel, CONCAT(rn.semester, ' - ', mp.nama) as semester_mapel
            FROM rekap_nilai rn
            JOIN mata_pelajaran mp ON rn.mata_pelajaran_id = mp.id
            JOIN siswa s ON rn.siswa_id = s.id
            WHERE s.pengguna_id = ?
            ORDER BY rn.semester DESC, mp.nama ASC
        ");
        $stmt->bind_param("i", $siswaId);
        $stmt->execute();
        $result = $stmt->get_result();
        $grades = [];
        
        while ($row = $result->fetch_assoc()) {
            $grades[] = $row;
        }
        
        return $grades;
    } else {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Akses ditolak"]);
        exit();
    }
}

// Fungsi untuk mendapatkan materi kelas
function getClassMaterials($kelasId, $mapelId = null) {
    global $db;
    $user = verifyToken();
    
    // Pemeriksaan akses kelas sudah dilakukan di checkClassAccess()
    checkClassAccess($kelasId);
    
    $query = "
        SELECT m.*, j.hari, j.waktu_mulai, j.waktu_selesai, mp.nama as nama_mapel, mp.kode as kode_mapel, g.nama as nama_guru
        FROM materi m
        JOIN jadwal j ON m.jadwal_id = j.id
        JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id
        JOIN guru g ON j.guru_id = g.id
        WHERE j.kelas_id = ?
    ";
    
    $params = [$kelasId];
    $types = "i";
    
    if ($mapelId) {
        $query .= " AND j.mata_pelajaran_id = ?";
        $params[] = $mapelId;
        $types .= "i";
    }
    
    $query .= " ORDER BY m.dibuat_pada DESC";
    
    $stmt = $db->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $materials = [];
    
    while ($row = $result->fetch_assoc()) {
        // Jika user adalah guru, cek apakah materi miliknya
        if ($user['peran'] === 'guru') {
            $row['is_owner'] = ($row['guru_id'] == $user['id']);
        }
        $materials[] = $row;
    }
    
    return $materials;
}

// Fungsi untuk mendapatkan tugas kelas
function getClassAssignments($kelasId, $mapelId = null) {
    global $db;
    $user = verifyToken();
    
    // Pemeriksaan akses kelas sudah dilakukan di checkClassAccess()
    checkClassAccess($kelasId);
    
    $query = "
        SELECT t.*, j.hari, j.waktu_mulai, j.waktu_selesai, mp.nama as nama_mapel, mp.kode as kode_mapel, g.nama as nama_guru
        FROM tugas t
        JOIN jadwal j ON t.jadwal_id = j.id
        JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id
        JOIN guru g ON j.guru_id = g.id
        WHERE j.kelas_id = ?
    ";
    
    $params = [$kelasId];
    $types = "i";
    
    if ($mapelId) {
        $query .= " AND j.mata_pelajaran_id = ?";
        $params[] = $mapelId;
        $types .= "i";
    }
    
    $query .= " ORDER BY t.tanggal_jatuh_tempo ASC";
    
    $stmt = $db->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $assignments = [];
    
    while ($row = $result->fetch_assoc()) {
        // Jika user adalah guru, cek apakah tugas miliknya
        if ($user['peran'] === 'guru') {
            $row['is_owner'] = ($row['guru_id'] == $user['id']);
        }
        
        // Jika user adalah siswa, ambil status pengumpulan
        if ($user['peran'] === 'siswa') {
            // Mengambil id siswa dari tabel siswa berdasarkan pengguna_id
            $siswaStmt = $db->prepare("SELECT id FROM siswa WHERE pengguna_id = ?");
            $siswaStmt->bind_param("i", $user['id']);
            $siswaStmt->execute();
            $siswaResult = $siswaStmt->get_result();
            $siswa = $siswaResult->fetch_assoc();
            
            if ($siswa) {
                $submissionStmt = $db->prepare("
                    SELECT pt.*, n.skor, n.komentar_guru
                    FROM pengumpulan_tugas pt
                    LEFT JOIN nilai n ON n.pengumpulan_tugas_id = pt.id
                    WHERE pt.tugas_id = ? AND pt.siswa_id = ?
                ");
                $submissionStmt->bind_param("ii", $row['id'], $siswa['id']);
                $submissionStmt->execute();
                $submissionResult = $submissionStmt->get_result();
                
                if ($submissionResult->num_rows > 0) {
                    $submission = $submissionResult->fetch_assoc();
                    $row['pengumpulan'] = $submission;
                } else {
                    $row['pengumpulan'] = null;
                }
            }
        }
        
        $assignments[] = $row;
    }
    
    return $assignments;
}

// Fungsi untuk log aktivitas
function logActivity($userId, $targetTable, $targetId, $action, $description) {
    global $db;
    
    $stmt = $db->prepare("
        INSERT INTO log_aktivitas (pengguna_id, target_tabel, target_id, aksi, deskripsi, waktu)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("isiss", $userId, $targetTable, $targetId, $action, $description);
    $stmt->execute();
    
    return $db->insert_id;
}
?> 
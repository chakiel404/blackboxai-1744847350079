<?php
// Set JSON content type before anything else to ensure proper response format
header('Content-Type: application/json');

// Custom error handler to capture PHP errors and return as JSON
function jsonErrorHandler($errno, $errstr, $errfile, $errline) {
    $error = [
        'status' => 'error',
        'message' => 'PHP Error: ' . $errstr,
        'details' => [
            'file' => $errfile,
            'line' => $errline,
            'type' => $errno
        ]
    ];
    
    // Log error
    error_log("PHP Error in auth.php: $errstr in $errfile on line $errline");
    
    // Return JSON error and exit
    http_response_code(500);
    echo json_encode($error);
    exit;
}

// Set custom error handler
set_error_handler('jsonErrorHandler');

// Capture fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $message = [
            'status' => 'error',
            'message' => 'Fatal PHP Error: ' . $error['message'],
            'details' => [
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $error['type']
            ]
        ];
        http_response_code(500);
        echo json_encode($message);
    }
});

require_once __DIR__ . '/config/cors.php';
setCorsHeaders();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/auth.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    $input = file_get_contents("php://input");
    if ($input === false) {
        throw new Exception("Failed to read input data");
    }
    
    $data = json_decode($input);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON data: " . json_last_error_msg());
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validate input
        if (empty($data->email) || empty($data->kata_sandi)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Email dan kata sandi diperlukan']);
            exit;
        }

        // Check if input is email, NIP, or NIS
        $identifier = $data->email;
        $query = "";
        $params = [];
        $types = "";

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            // Login with email
            $query = "SELECT p.id, p.email, p.kata_sandi, p.peran, p.nama,
                            CASE 
                                WHEN p.peran = 'guru' THEN g.nip
                                WHEN p.peran = 'siswa' THEN s.nis
                                ELSE NULL
                            END as identifier,
                            CASE 
                                WHEN p.peran = 'siswa' THEN s.kelas_id
                                ELSE NULL
                            END as kelas_id
                     FROM pengguna p
                     LEFT JOIN guru g ON p.id = g.pengguna_id AND p.peran = 'guru'
                     LEFT JOIN siswa s ON p.id = s.pengguna_id AND p.peran = 'siswa'
                     WHERE p.email = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("s", $identifier);
        } else {
            // Check NIP format (18 digits)
            if (preg_match('/^\d{18}$/', $identifier)) {
                // Login with NIP (teacher)
                $query = "SELECT p.id, p.email, p.kata_sandi, 'guru' as peran, p.nama, g.nip as identifier, NULL as kelas_id
                         FROM pengguna p
                         JOIN guru g ON p.id = g.pengguna_id 
                         WHERE g.nip = ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param("s", $identifier);
            } 
            // Check NIS format
            else if (preg_match('/^\d{10}$/', $identifier)) {
                // Login with NIS (student)
                $query = "SELECT p.id, p.email, p.kata_sandi, 'siswa' as peran, p.nama, s.nis as identifier, s.kelas_id
                         FROM pengguna p 
                         JOIN siswa s ON p.id = s.pengguna_id 
                         WHERE s.nis = ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param("s", $identifier);
            } else {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Format NIP atau NIS tidak valid']);
                exit;
            }
        }

        try {
            if (!$stmt) {
                throw new Exception("Failed to prepare statement: " . $db->error);
            }

            if (!$stmt->execute()) {
                throw new Exception("Failed to execute query: " . $stmt->error);
            }

            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            
            if ($user) {
                // Verify password
                if (password_verify($data->kata_sandi, $user['kata_sandi'])) {
                    // Generate JWT token with proper role data
                    $tokenPayload = [
                        'id' => (int)$user['id'],
                        'email' => $user['email'],
                        'peran' => $user['peran']
                    ];
                    
                    // Only include kelas_id for students if it exists
                    if ($user['peran'] === 'siswa' && !is_null($user['kelas_id'])) {
                        $tokenPayload['kelas_id'] = (int)$user['kelas_id'];
                    }
                    
                    $token = generateJWT($tokenPayload);

                    // Prepare response data
                    $response_data = [
                        'id' => (int)$user['id'],
                        'nama' => $user['nama'],
                        'email' => $user['email'],
                        'peran' => $user['peran']
                    ];

                    // Add role-specific identifier
                    if ($user['peran'] === 'guru' && !is_null($user['identifier'])) {
                        $response_data['nip'] = $user['identifier'];
                    } else if ($user['peran'] === 'siswa' && !is_null($user['identifier'])) {
                        $response_data['nis'] = $user['identifier'];
                        if (!is_null($user['kelas_id'])) {
                            $response_data['kelas_id'] = (int)$user['kelas_id'];
                        }
                    }

                    http_response_code(200);
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Login berhasil',
                        'token' => $token,
                        'user' => $response_data
                    ]);
                } else {
                    http_response_code(401);
                    echo json_encode(['status' => 'error', 'message' => 'Kata sandi salah']);
                }
            } else {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Pengguna tidak ditemukan']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    } else {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Metode tidak diizinkan']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

function getUserInfo($userId) {
    global $db;
    
    $query = "SELECT id, nama, email, peran, status FROM pengguna WHERE id = ?";
    $stmt = $db->prepare($query);
    if (!$stmt) {
        returnError(500, 'Database error preparing user query', $db->error);
    }
    
    // Ensure userId is an integer
    $userId = intval($userId);
    $stmt->bind_param('i', $userId);
    
    if (!$stmt->execute()) {
        returnError(500, 'Database error executing user query', $stmt->error);
    }
    
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        returnError(404, 'User not found');
    }
    
    $user = $result->fetch_assoc();
    
    // Convert id to integer
    $user['id'] = intval($user['id']);
    
    return $user;
}
?> 
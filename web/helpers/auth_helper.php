<?php
require_once __DIR__ . '/../config/database.php';

class AuthHelper {
    private $db;
    private static $secret_key = "your-secret-key-here";
    private static $algorithm = 'HS256';

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    // Generate JWT token
    public function generateJWT($payload) {
        $header = json_encode(['typ' => 'JWT', 'alg' => self::$algorithm]);
        $payload = json_encode($payload);
        
        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::$secret_key, true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    // Verify JWT token
    public function verifyJWT($token) {
        $parts = explode('.', $token);
        
        if (count($parts) != 3) {
            return false;
        }
        
        $header = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[0]));
        $payload = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]));
        $signature = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[2]));
        
        $validSignature = hash_hmac('sha256', $parts[0] . "." . $parts[1], self::$secret_key, true);
        
        if (hash_equals($signature, $validSignature)) {
            return json_decode($payload, true);
        }
        
        return false;
    }

    // Login user
    public function login($identifier, $password) {
        try {
            // Check if input is email, NIP, or NIS
            $query = "";
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
            } elseif (preg_match('/^\d{18}$/', $identifier)) {
                // Login with NIP (teacher)
                $query = "SELECT p.id, p.email, p.kata_sandi, 'guru' as peran, p.nama, 
                                g.nip as identifier, NULL as kelas_id
                         FROM pengguna p
                         JOIN guru g ON p.id = g.pengguna_id 
                         WHERE g.nip = ?";
            } elseif (preg_match('/^\d{10}$/', $identifier)) {
                // Login with NIS (student)
                $query = "SELECT p.id, p.email, p.kata_sandi, 'siswa' as peran, p.nama, 
                                s.nis as identifier, s.kelas_id
                         FROM pengguna p 
                         JOIN siswa s ON p.id = s.pengguna_id 
                         WHERE s.nis = ?";
            } else {
                return [
                    'success' => false,
                    'message' => 'Format email/NIP/NIS tidak valid'
                ];
            }

            $stmt = $this->db->prepare($query);
            $stmt->bind_param("s", $identifier);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            if ($user && password_verify($password, $user['kata_sandi'])) {
                // Generate JWT token
                $tokenPayload = [
                    'id' => (int)$user['id'],
                    'email' => $user['email'],
                    'peran' => $user['peran']
                ];
                
                if ($user['peran'] === 'siswa' && !is_null($user['kelas_id'])) {
                    $tokenPayload['kelas_id'] = (int)$user['kelas_id'];
                }
                
                $token = $this->generateJWT($tokenPayload);

                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_name'] = $user['nama'];
                $_SESSION['user_role'] = $user['peran'];
                $_SESSION['token'] = $token;

                if ($user['peran'] === 'siswa') {
                    $_SESSION['kelas_id'] = $user['kelas_id'];
                }

                return [
                    'success' => true,
                    'user' => [
                        'id' => (int)$user['id'],
                        'nama' => $user['nama'],
                        'email' => $user['email'],
                        'peran' => $user['peran'],
                        'identifier' => $user['identifier'],
                        'kelas_id' => ($user['peran'] === 'siswa') ? (int)$user['kelas_id'] : null
                    ],
                    'token' => $token
                ];
            }

            return [
                'success' => false,
                'message' => 'Email/NIP/NIS atau kata sandi salah'
            ];

        } catch (Exception $e) {
            error_log("Login Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Terjadi kesalahan saat login'
            ];
        }
    }

    // Logout user
    public function logout() {
        session_unset();
        session_destroy();
        return true;
    }

    // Check if user is logged in
    public function isLoggedIn() {
        return isset($_SESSION['user_id']) && isset($_SESSION['token']);
    }

    // Get current user data
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }

        try {
            $query = "SELECT p.*, 
                            CASE 
                                WHEN p.peran = 'guru' THEN g.nip
                                WHEN p.peran = 'siswa' THEN s.nis
                                ELSE NULL
                            END as identifier,
                            CASE 
                                WHEN p.peran = 'guru' THEN g.id
                                WHEN p.peran = 'siswa' THEN s.id
                                ELSE NULL
                            END as role_id
                     FROM pengguna p
                     LEFT JOIN guru g ON p.id = g.pengguna_id AND p.peran = 'guru'
                     LEFT JOIN siswa s ON p.id = s.pengguna_id AND p.peran = 'siswa'
                     WHERE p.id = ?";

            $stmt = $this->db->prepare($query);
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            if ($user) {
                unset($user['kata_sandi']); // Remove password from result
                return $user;
            }

            return null;
        } catch (Exception $e) {
            error_log("Get Current User Error: " . $e->getMessage());
            return null;
        }
    }

    // Check user role
    public function hasRole($requiredRoles) {
        if (!$this->isLoggedIn()) {
            return false;
        }

        if (!is_array($requiredRoles)) {
            $requiredRoles = [$requiredRoles];
        }

        return in_array($_SESSION['user_role'], $requiredRoles);
    }

    // Update user password
    public function updatePassword($userId, $currentPassword, $newPassword) {
        try {
            // First verify current password
            $stmt = $this->db->prepare("SELECT kata_sandi FROM pengguna WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            if (!$user || !password_verify($currentPassword, $user['kata_sandi'])) {
                return [
                    'success' => false,
                    'message' => 'Kata sandi saat ini tidak valid'
                ];
            }

            // Update with new password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("UPDATE pengguna SET kata_sandi = ? WHERE id = ?");
            $stmt->bind_param("si", $hashedPassword, $userId);
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'Kata sandi berhasil diperbarui'
                ];
            }

            return [
                'success' => false,
                'message' => 'Gagal memperbarui kata sandi'
            ];

        } catch (Exception $e) {
            error_log("Update Password Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui kata sandi'
            ];
        }
    }
}

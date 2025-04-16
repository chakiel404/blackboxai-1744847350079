<?php
require_once __DIR__ . '/config/cors.php';
setCorsHeaders();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/jwt.php';

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

// Main logout logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Extract token from Authorization header
    $headers = getallheaders();
    if (!isset($headers['Authorization'])) {
        returnError(401, 'Header Authorization diperlukan');
    }
    
    $token = str_replace('Bearer ', '', $headers['Authorization']);
    
    try {
        // Decode token using JWT class
        $decoded = JWT::decode($token);
        
        if (!$decoded) {
            returnError(401, 'Token tidak valid');
        }
        
        // For security, you could implement token blacklisting here
        // But that would require a database table to store blacklisted tokens
        // Example:
        // $blacklist_query = "INSERT INTO token_blacklist (token, expiry) VALUES (?, NOW() + INTERVAL 1 DAY)";
        // $blacklist_stmt = $db->prepare($blacklist_query);
        // $blacklist_stmt->bind_param("s", $token);
        // $blacklist_stmt->execute();
        
        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "message" => "Berhasil keluar"
        ]);
    } catch (Exception $e) {
        returnError(401, 'Token tidak valid', $e->getMessage());
    }
} else {
    returnError(405, 'Metode tidak diizinkan');
}
?> 
<?php
// Simple CORS test script
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-HTTP-Method-Override");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 3600");
header("Content-Type: application/json");

// Log the request for debugging
error_log("CORS test request received: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);

// Handle OPTIONS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Return basic info about the request
echo json_encode([
    'status' => 'success',
    'message' => 'CORS test successful',
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'headers' => getallheaders(),
    'time' => date('Y-m-d H:i:s'),
]);
?> 
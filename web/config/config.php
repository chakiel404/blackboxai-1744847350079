<?php
session_start();

// Base configuration
define('BASE_PATH', dirname(__DIR__));
define('UPLOAD_PATH', BASE_PATH . '/assets/uploads');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB

// Allowed file types
define('ALLOWED_FILE_TYPES', [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png'
]);

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', BASE_PATH . '/logs/error.log');

// Create logs directory if it doesn't exist
if (!file_exists(BASE_PATH . '/logs')) {
    mkdir(BASE_PATH . '/logs', 0777, true);
}

// Function to redirect with message
function redirect($location, $message = '', $type = 'success') {
    if (!empty($message)) {
        $_SESSION['flash'] = [
            'message' => $message,
            'type' => $type
        ];
    }
    header("Location: $location");
    exit();
}

// Function to display flash messages
function display_flash_message() {
    if (isset($_SESSION['flash'])) {
        $message = $_SESSION['flash']['message'];
        $type = $_SESSION['flash']['type'];
        unset($_SESSION['flash']);
        
        return "<div class='alert alert-{$type} alert-dismissible fade show' role='alert'>
                    {$message}
                    <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                </div>";
    }
    return '';
}

// Function to check if user is logged in
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// Function to check user role
function has_role($required_roles) {
    if (!is_logged_in()) {
        return false;
    }
    
    if (!is_array($required_roles)) {
        $required_roles = [$required_roles];
    }
    
    return in_array($_SESSION['user_role'], $required_roles);
}

// Function to sanitize input
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Function to generate CSRF token
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Function to verify CSRF token
function verify_csrf_token($token) {
    if (!isset($_SESSION['csrf_token']) || empty($token) || $token !== $_SESSION['csrf_token']) {
        return false;
    }
    return true;
}

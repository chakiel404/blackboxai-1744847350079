<?php
/**
 * Set CORS headers for all API responses
 */
function setCorsHeaders() {
    // Allow requests from any origin in development
    // In production, you'd want to restrict this to your app's domain
    header("Access-Control-Allow-Origin: *");
    
    // Allow the following HTTP methods
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    
    // Allow the following headers
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-HTTP-Method-Override");
    
    // Allow credentials (cookies, auth headers)
    header("Access-Control-Allow-Credentials: true");
    
    // Set max age for preflight requests (in seconds)
    header("Access-Control-Max-Age: 86400");
    
    // For non-OPTIONS requests, set standard headers
    if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
        // Set content type for non-preflight requests
        header("Content-Type: application/json; charset=UTF-8");
    } else {
        // Return 200 OK for preflight requests
        http_response_code(200);
        exit;
    }
}
?> 
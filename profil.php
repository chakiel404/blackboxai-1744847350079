<?php
// This file serves as a simple proxy to ensure backwards compatibility
// with clients that might be using the 'profil.php' endpoint

require_once __DIR__ . '/config/cors.php';
setCorsHeaders();

// Include the actual profile implementation
require_once __DIR__ . '/profile.php';
?> 
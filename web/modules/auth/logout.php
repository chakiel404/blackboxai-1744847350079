<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth_helper.php';

$auth = new AuthHelper();

// Perform logout
$auth->logout();

// Redirect to login page with success message
redirect('/web/modules/auth/login.php', 'You have been successfully logged out.');

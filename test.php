<?php
header('Content-Type: application/json');
echo json_encode([
    'status' => 'success',
    'message' => 'Rewrite test successful!',
    'server' => $_SERVER,
]);
?> 
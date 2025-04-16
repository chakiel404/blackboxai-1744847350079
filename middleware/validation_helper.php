<?php
function validateNIP($nip) {
    return strlen($nip) === 18 && ctype_digit($nip);
}

function validateNIS($nis) {
    // Super detailed logging
    error_log("Raw NIS input: '" . $nis . "' (type: " . gettype($nis) . ", length: " . strlen($nis) . ")");
    
    // If it's numeric, cast to string
    if (is_numeric($nis)) {
        $nis = (string)$nis;
        error_log("Converted numeric NIS to string: " . $nis);
    }
    
    // Cleanup: trim whitespace and remove any non-digit characters
    $original = $nis;
    $nis = trim($nis);
    $nis = preg_replace('/[^0-9]/', '', $nis);
    
    if ($original !== $nis) {
        error_log("Cleaned NIS: '" . $nis . "' (from: '" . $original . "')");
    }
    
    // Check if we have a valid NIS after cleanup
    $length = strlen($nis);
    $isValid = $length >= 5 && $length <= 20; // Be very permissive with length
    
    error_log("Final NIS validation: '" . $nis . "' (length: " . $length . ") is " . ($isValid ? "VALID" : "INVALID"));
    return $isValid;
}

function validatePhone($phone) {
    return preg_match('/^[0-9]{10,15}$/', $phone);
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validatePassword($password) {
    return strlen($password) >= 8;
}

/**
 * Validates a decimal number based on total digits and decimal places
 *
 * @param mixed $value The decimal value to validate
 * @param int $maxDigits Maximum total digits allowed (including decimal places)
 * @param int $decimalPlaces Maximum decimal places allowed
 * @return bool True if the value is a valid decimal within the specified constraints
 */
function validateDecimal($value, $maxDigits = 5, $decimalPlaces = 2) {
    // Ensure value is numeric
    if (!is_numeric($value)) {
        return false;
    }
    
    // Convert to string for analysis
    $value = (string)$value;
    
    // Split into integer and decimal parts
    $parts = explode('.', $value);
    $integerPart = $parts[0];
    $decimalPart = isset($parts[1]) ? $parts[1] : '';
    
    // Remove negative sign from count
    $integerPart = ltrim($integerPart, '-');
    
    // Calculate total digits (excluding decimal point)
    $totalDigits = strlen($integerPart) + strlen($decimalPart);
    
    // Check constraints
    if ($totalDigits > $maxDigits) {
        return false;
    }
    
    if (strlen($decimalPart) > $decimalPlaces) {
        return false;
    }
    
    return true;
}

function validateFile($file, $allowed_types = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'], $max_size = 5242880) {
    if (!isset($file['error']) || is_array($file['error'])) {
        return false;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    if ($file['size'] > $max_size) {
        return false;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($file['tmp_name']);
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    $allowed_mime_types = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png'
    ];

    return in_array($extension, $allowed_types) && 
           isset($allowed_mime_types[$extension]) && 
           $mime_type === $allowed_mime_types[$extension];
}

function validateScore($score) {
    return is_numeric($score) && $score >= 0 && $score <= 100;
}

function validateSemester($semester) {
    return preg_match('/^(Ganjil|Genap)\s\d{4}\/\d{4}$/', $semester);
}

function validateDay($day) {
    $valid_days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    return in_array($day, $valid_days);
}

function validateTime($time) {
    return preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time);
}

function validateDate($date) {
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $date);
    return $d && $d->format('Y-m-d H:i:s') === $date;
}
?> 
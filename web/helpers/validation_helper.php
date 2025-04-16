<?php
class ValidationHelper {
    // Validate NIP (18 digits)
    public static function validateNIP($nip) {
        return strlen($nip) === 18 && ctype_digit($nip);
    }

    // Validate NIS (10 digits)
    public static function validateNIS($nis) {
        // If it's numeric, cast to string
        if (is_numeric($nis)) {
            $nis = (string)$nis;
        }
        
        // Cleanup: trim whitespace and remove any non-digit characters
        $nis = trim($nis);
        $nis = preg_replace('/[^0-9]/', '', $nis);
        
        return strlen($nis) === 10;
    }

    // Validate phone number (10-15 digits)
    public static function validatePhone($phone) {
        return preg_match('/^[0-9]{10,15}$/', $phone);
    }

    // Validate email
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    // Validate password (minimum 8 characters)
    public static function validatePassword($password) {
        return strlen($password) >= 8;
    }

    // Validate decimal number
    public static function validateDecimal($value, $maxDigits = 5, $decimalPlaces = 2) {
        if (!is_numeric($value)) {
            return false;
        }
        
        $value = (string)$value;
        $parts = explode('.', $value);
        $integerPart = $parts[0];
        $decimalPart = isset($parts[1]) ? $parts[1] : '';
        
        $integerPart = ltrim($integerPart, '-');
        $totalDigits = strlen($integerPart) + strlen($decimalPart);
        
        if ($totalDigits > $maxDigits) {
            return false;
        }
        
        if (strlen($decimalPart) > $decimalPlaces) {
            return false;
        }
        
        return true;
    }

    // Validate file upload
    public static function validateFile($file, $allowed_types = [], $max_size = 5242880) {
        if (!isset($file['error']) || is_array($file['error'])) {
            return [
                'valid' => false,
                'message' => 'Invalid file parameter'
            ];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = self::getFileUploadErrorMessage($file['error']);
            return [
                'valid' => false,
                'message' => $message
            ];
        }

        if ($file['size'] > $max_size) {
            return [
                'valid' => false,
                'message' => 'File size exceeds maximum limit'
            ];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($file['tmp_name']);
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (empty($allowed_types)) {
            $allowed_types = array_keys(ALLOWED_FILE_TYPES);
        }

        if (!in_array($extension, $allowed_types)) {
            return [
                'valid' => false,
                'message' => 'File type not allowed'
            ];
        }

        if (!isset(ALLOWED_FILE_TYPES[$extension]) || $mime_type !== ALLOWED_FILE_TYPES[$extension]) {
            return [
                'valid' => false,
                'message' => 'Invalid file type'
            ];
        }

        return [
            'valid' => true,
            'message' => 'File is valid'
        ];
    }

    // Validate score (0-100)
    public static function validateScore($score) {
        return is_numeric($score) && $score >= 0 && $score <= 100;
    }

    // Validate semester format (e.g., "Ganjil 2023/2024")
    public static function validateSemester($semester) {
        return preg_match('/^(Ganjil|Genap)\s\d{4}\/\d{4}$/', $semester);
    }

    // Validate day of week
    public static function validateDay($day) {
        $valid_days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        return in_array($day, $valid_days);
    }

    // Validate time format (HH:MM)
    public static function validateTime($time) {
        return preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time);
    }

    // Validate date format (YYYY-MM-DD HH:MM:SS)
    public static function validateDate($date) {
        $d = DateTime::createFromFormat('Y-m-d H:i:s', $date);
        return $d && $d->format('Y-m-d H:i:s') === $date;
    }

    // Get file upload error message
    private static function getFileUploadErrorMessage($error_code) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the file upload';
            default:
                return 'Unknown upload error';
        }
    }

    // Validate required fields
    public static function validateRequired($fields, $data) {
        $errors = [];
        foreach ($fields as $field) {
            if (!isset($data[$field]) || trim($data[$field]) === '') {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        return $errors;
    }

    // Sanitize array of data
    public static function sanitizeData($data) {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeData($value);
            } else {
                $sanitized[$key] = self::sanitizeInput($value);
            }
        }
        return $sanitized;
    }

    // Sanitize single input
    public static function sanitizeInput($data) {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
        return $data;
    }
}

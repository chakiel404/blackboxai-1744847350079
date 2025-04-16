<?php
class FileHelper {
    private $upload_path;
    private $allowed_types;
    private $max_size;

    public function __construct($upload_path = null, $allowed_types = null, $max_size = null) {
        $this->upload_path = $upload_path ?? UPLOAD_PATH;
        $this->allowed_types = $allowed_types ?? ALLOWED_FILE_TYPES;
        $this->max_size = $max_size ?? MAX_FILE_SIZE;
    }

    /**
     * Upload a file
     * @param array $file The $_FILES array element
     * @param string $directory Subdirectory within upload path
     * @return array Status of upload with file info or error message
     */
    public function uploadFile($file, $directory = '') {
        try {
            // Validate file
            $validation = ValidationHelper::validateFile($file, array_keys($this->allowed_types), $this->max_size);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => $validation['message']
                ];
            }

            // Create target directory if it doesn't exist
            $target_dir = rtrim($this->upload_path, '/') . '/' . trim($directory, '/');
            if (!empty($directory) && !file_exists($target_dir)) {
                if (!mkdir($target_dir, 0777, true)) {
                    throw new Exception("Failed to create directory: $target_dir");
                }
            }

            // Generate unique filename
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $filename = $this->generateUniqueFilename($target_dir, $extension);
            $target_file = $target_dir . '/' . $filename;

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $target_file)) {
                throw new Exception("Failed to move uploaded file");
            }

            // Set proper permissions
            chmod($target_file, 0644);

            return [
                'success' => true,
                'file_name' => $filename,
                'file_path' => str_replace($this->upload_path, '', $target_file),
                'full_path' => $target_file,
                'file_type' => $file['type'],
                'file_size' => $file['size']
            ];

        } catch (Exception $e) {
            error_log("File Upload Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to upload file: " . $e->getMessage()
            ];
        }
    }

    /**
     * Delete a file
     * @param string $filepath Path to file relative to upload directory
     * @return array Status of deletion
     */
    public function deleteFile($filepath) {
        try {
            $full_path = $this->upload_path . '/' . ltrim($filepath, '/');
            
            if (!file_exists($full_path)) {
                return [
                    'success' => false,
                    'message' => 'File does not exist'
                ];
            }

            if (!unlink($full_path)) {
                throw new Exception("Failed to delete file");
            }

            // Remove empty directories
            $this->removeEmptyDirectories(dirname($full_path));

            return [
                'success' => true,
                'message' => 'File deleted successfully'
            ];

        } catch (Exception $e) {
            error_log("File Deletion Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to delete file: " . $e->getMessage()
            ];
        }
    }

    /**
     * Generate a unique filename
     * @param string $directory Target directory
     * @param string $extension File extension
     * @return string Unique filename
     */
    private function generateUniqueFilename($directory, $extension) {
        do {
            $filename = uniqid() . '.' . $extension;
        } while (file_exists($directory . '/' . $filename));
        
        return $filename;
    }

    /**
     * Remove empty directories recursively
     * @param string $directory Directory path
     */
    private function removeEmptyDirectories($directory) {
        if ($directory == $this->upload_path) {
            return;
        }

        if (is_dir($directory)) {
            $files = scandir($directory);
            if (count($files) <= 2) { // . and ..
                rmdir($directory);
                $this->removeEmptyDirectories(dirname($directory));
            }
        }
    }

    /**
     * Get file information
     * @param string $filepath Path to file relative to upload directory
     * @return array File information or error
     */
    public function getFileInfo($filepath) {
        try {
            $full_path = $this->upload_path . '/' . ltrim($filepath, '/');
            
            if (!file_exists($full_path)) {
                return [
                    'success' => false,
                    'message' => 'File does not exist'
                ];
            }

            $info = pathinfo($full_path);
            $size = filesize($full_path);
            $mime = mime_content_type($full_path);

            return [
                'success' => true,
                'info' => [
                    'name' => $info['basename'],
                    'extension' => $info['extension'],
                    'mime_type' => $mime,
                    'size' => $size,
                    'path' => $filepath,
                    'full_path' => $full_path,
                    'created' => filectime($full_path),
                    'modified' => filemtime($full_path)
                ]
            ];

        } catch (Exception $e) {
            error_log("Get File Info Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to get file info: " . $e->getMessage()
            ];
        }
    }

    /**
     * Check if file exists
     * @param string $filepath Path to file relative to upload directory
     * @return bool Whether file exists
     */
    public function fileExists($filepath) {
        $full_path = $this->upload_path . '/' . ltrim($filepath, '/');
        return file_exists($full_path);
    }

    /**
     * Create a directory
     * @param string $directory Directory path relative to upload directory
     * @return array Status of creation
     */
    public function createDirectory($directory) {
        try {
            $full_path = $this->upload_path . '/' . trim($directory, '/');
            
            if (file_exists($full_path)) {
                return [
                    'success' => false,
                    'message' => 'Directory already exists'
                ];
            }

            if (!mkdir($full_path, 0777, true)) {
                throw new Exception("Failed to create directory");
            }

            return [
                'success' => true,
                'message' => 'Directory created successfully'
            ];

        } catch (Exception $e) {
            error_log("Create Directory Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to create directory: " . $e->getMessage()
            ];
        }
    }

    /**
     * List files in a directory
     * @param string $directory Directory path relative to upload directory
     * @return array List of files or error
     */
    public function listFiles($directory = '') {
        try {
            $full_path = $this->upload_path . '/' . trim($directory, '/');
            
            if (!is_dir($full_path)) {
                return [
                    'success' => false,
                    'message' => 'Directory does not exist'
                ];
            }

            $files = [];
            $dir = new DirectoryIterator($full_path);
            foreach ($dir as $fileinfo) {
                if (!$fileinfo->isDot()) {
                    $files[] = [
                        'name' => $fileinfo->getFilename(),
                        'path' => $directory . '/' . $fileinfo->getFilename(),
                        'type' => $fileinfo->getType(),
                        'size' => $fileinfo->getSize(),
                        'modified' => $fileinfo->getMTime()
                    ];
                }
            }

            return [
                'success' => true,
                'files' => $files
            ];

        } catch (Exception $e) {
            error_log("List Files Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to list files: " . $e->getMessage()
            ];
        }
    }
}

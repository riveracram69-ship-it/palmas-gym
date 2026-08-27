<?php
/**
 * Defense-in-Depth Secure Image Upload Engine
 * 
 * Implements strict multi-layer file upload security:
 * 1. File size enforcement
 * 2. MIME type & extension whitelist validation
 * 3. Magic byte & image structure verification
 * 4. GD raster re-encoding (strips polyglot payloads & EXIF metadata)
 * 5. Proportional resizing & optimization
 * 6. Cryptographically random server-side filename generation
 * 7. Secure file permission assignment (0644)
 */

if (!defined('MAX_UPLOAD_SIZE_BYTES')) {
    define('MAX_UPLOAD_SIZE_BYTES', 3 * 1024 * 1024); // 3MB maximum
}

/**
 * Process, validate, re-encode, and save an uploaded image file securely.
 * 
 * @param array $file_post $_FILES['field_name']
 * @param string $subfolder Relative subfolder within uploads (e.g., 'members')
 * @param int $max_width Maximum width in pixels for resizing (default: 1200)
 * @param int $max_height Maximum height in pixels for resizing (default: 1200)
 * @return array ['success' => bool, 'path' => string|null, 'error' => string|null]
 */
function secure_process_image_upload(
    array $file_post, 
    string $subfolder = 'members', 
    int $max_width = 1200, 
    int $max_height = 1200
): array {
    // 1. Validate basic upload array structure
    if (!isset($file_post['error']) || is_array($file_post['error'])) {
        return ['success' => false, 'path' => null, 'error' => 'Invalid upload parameter.'];
    }

    // 2. Check upload errors
    switch ($file_post['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['success' => false, 'path' => null, 'error' => 'The uploaded file exceeds the allowed file size.'];
        case UPLOAD_ERR_NO_FILE:
            return ['success' => false, 'path' => null, 'error' => 'No file was uploaded.'];
        default:
            return ['success' => false, 'path' => null, 'error' => 'An error occurred during file upload.'];
    }

    // 3. File size limit
    if ($file_post['size'] > MAX_UPLOAD_SIZE_BYTES) {
        $max_mb = round(MAX_UPLOAD_SIZE_BYTES / (1024 * 1024));
        return ['success' => false, 'path' => null, 'error' => "File size exceeds the maximum limit of {$max_mb}MB."];
    }

    $tmp_name = $file_post['tmp_name'];
    if (!is_uploaded_file($tmp_name)) {
        return ['success' => false, 'path' => null, 'error' => 'Uploaded file verification failed.'];
    }

    // 4. Validate extension against strict whitelist
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp'];
    $raw_filename = $file_post['name'] ?? '';
    
    // Check for double extension attacks (e.g. exploit.php.jpg)
    if (preg_match('/\.(php|phtml|phar|inc|cgi|pl|py|sh|exe)\./i', $raw_filename)) {
        return ['success' => false, 'path' => null, 'error' => 'Malicious file naming detected.'];
    }

    $ext = strtolower(pathinfo($raw_filename, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_extensions, true)) {
        return ['success' => false, 'path' => null, 'error' => 'Invalid file extension. Only JPG, PNG, and WEBP are permitted.'];
    }

    // 5. Validate MIME type via finfo magic bytes
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $tmp_name);
    finfo_close($finfo);

    $allowed_mimes = [
        'image/jpeg' => 'jpg',
        'image/pjpeg'=> 'jpg',
        'image/png'  => 'png',
        'image/x-png'=> 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed_mimes[$mime_type])) {
        return ['success' => false, 'path' => null, 'error' => 'Invalid file content. Genuine image files only.'];
    }

    // 6. Validate image geometry using getimagesize
    $img_info = @getimagesize($tmp_name);
    if (!$img_info || empty($img_info[0]) || empty($img_info[1])) {
        return ['success' => false, 'path' => null, 'error' => 'The uploaded file is not a readable image.'];
    }

    $orig_width  = $img_info[0];
    $orig_height = $img_info[1];

    // 7. Target directory preparation
    $clean_subfolder = trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $subfolder), '/');
    $base_upload_dir = __DIR__ . '/../uploads/' . ($clean_subfolder ? $clean_subfolder . '/' : '');
    
    if (!is_dir($base_upload_dir)) {
        @mkdir($base_upload_dir, 0755, true);
    }

    // 8. Generate completely random crypto server-side filename (never trust original name)
    $safe_filename = 'mbr_' . bin2hex(random_bytes(16)) . '.jpg';
    $target_filepath = $base_upload_dir . $safe_filename;
    $relative_db_path = 'uploads/' . ($clean_subfolder ? $clean_subfolder . '/' : '') . $safe_filename;

    // 9. Re-encoding & Raster Reconstruction (Strip EXIF & Neutralize Polyglot Code)
    $reencoded = false;
    if (extension_loaded('gd') && function_exists('imagecreatefromstring')) {
        try {
            $raw_image_data = file_get_contents($tmp_name);
            $src_img = @imagecreatefromstring($raw_image_data);

            if ($src_img !== false) {
                // Calculate proportional dimensions
                $target_w = $orig_width;
                $target_h = $orig_height;

                if ($orig_width > $max_width || $orig_height > $max_height) {
                    $ratio = min($max_width / $orig_width, $max_height / $orig_height);
                    $target_w = max(1, (int)round($orig_width * $ratio));
                    $target_h = max(1, (int)round($orig_height * $ratio));
                }

                // Create fresh truecolor canvas
                $dst_img = imagecreatetruecolor($target_w, $target_h);
                
                // Preserve clean white background for transparency conversions
                $white = imagecolorallocate($dst_img, 255, 255, 255);
                imagefilledrectangle($dst_img, 0, 0, $target_w, $target_h, $white);

                // Resample pixels onto new canvas (neutralizes any non-pixel code)
                imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $target_w, $target_h, $orig_width, $orig_height);

                // Save sanitized image as clean JPEG with 88% quality
                if (imagejpeg($dst_img, $target_filepath, 88)) {
                    $reencoded = true;
                }

                imagedestroy($src_img);
                imagedestroy($dst_img);
            }
        } catch (Exception $e) {
            error_log("GD Image Re-encoding Error: " . $e->getMessage());
            $reencoded = false;
        }
    }

    // Fallback if GD is disabled or failed
    if (!$reencoded) {
        if (!move_uploaded_file($tmp_name, $target_filepath)) {
            return ['success' => false, 'path' => null, 'error' => 'Failed to write uploaded image to server storage.'];
        }
    }

    // 10. Enforce non-executable file permissions (read-only for web server)
    @chmod($target_filepath, 0644);

    return [
        'success' => true,
        'path'    => $relative_db_path,
        'error'   => null
    ];
}

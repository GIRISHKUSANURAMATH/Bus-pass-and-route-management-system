<?php
/**
 * Secure image uploads.
 *  - size limit
 *  - real MIME type from file contents (finfo), not the browser-supplied type
 *  - must decode as an image (getimagesize)
 *  - random file name + extension derived from the detected MIME type
 *  - stored outside /public (uploads/), served only through public/file.php
 *    after an authorization check; uploads/.htaccess also blocks direct access
 *    and disables script execution.
 *
 * @return array{ok:bool, file?:string, error?:string}
 */
declare(strict_types=1);

function handle_image_upload(?array $file, string $destDir, int $maxBytes, bool $required = false): array
{
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $required ? ['ok' => false, 'error' => 'Please choose an image file.'] : ['ok' => true, 'file' => null];
    }
    if (!is_int($file['error'] ?? null) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => match ($file['error'] ?? -1) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
            default => 'Upload failed. Please try again.',
        }];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
        return ['ok' => false, 'error' => 'File must be smaller than ' . round($maxBytes / 1048576, 1) . ' MB.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(UPLOAD_IMAGE_MIMES[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG or WEBP images are allowed.'];
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || $info[0] < 50 || $info[1] < 50 || $info[0] > 8000 || $info[1] > 8000) {
        return ['ok' => false, 'error' => 'The file is not a valid image.'];
    }
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
        app_log('Upload dir not writable: ' . $destDir);
        return ['ok' => false, 'error' => 'Server storage error. Please contact the administrator.'];
    }
    $name = bin2hex(random_bytes(16)) . '.' . UPLOAD_IMAGE_MIMES[$mime];
    if (!move_uploaded_file($file['tmp_name'], $destDir . '/' . $name)) {
        app_log('move_uploaded_file failed for ' . $destDir);
        return ['ok' => false, 'error' => 'Could not save the file.'];
    }
    @chmod($destDir . '/' . $name, 0644);
    return ['ok' => true, 'file' => $name];
}

/** Only names produced by handle_image_upload() are accepted (blocks path traversal). */
function safe_upload_name(?string $name): ?string
{
    return ($name !== null && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $name)) ? $name : null;
}

function delete_upload(string $dir, ?string $name): void
{
    $name = safe_upload_name($name);
    if ($name && is_file($dir . '/' . $name)) {
        @unlink($dir . '/' . $name);
    }
}

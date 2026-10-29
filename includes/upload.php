<?php
/**
 * KATALE — Secure file upload helper.
 * Validates MIME type via getimagesize or file info, enforces a size limit,
 * and writes with a random safe filename to prevent path traversal or
 * executable uploads.
 *
 * Returns the stored filename on success, or null if no file was uploaded.
 * Throws RuntimeException on validation failure.
 */
function handle_image_upload(string $fieldName): ?string
{
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$fieldName];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The file failed to upload. Please try again.');
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('The image is too large (max 2MB).');
    }

    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false || !in_array($imageInfo['mime'], ALLOWED_IMAGE_TYPES, true)) {
        throw new RuntimeException('Only JPEG, PNG, or WebP images are allowed.');
    }

    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $ext = $extMap[$imageInfo['mime']];
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $filename)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }

    return $filename;
}

function handle_hero_media_upload(string $fieldName): ?string
{
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The media file failed to upload. Please try again.');
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('The media is too large (max 2MB).');
    }

    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? @finfo_file($finfo, $file['tmp_name']) : null;
    @finfo_close($finfo);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
    ];

    if ($mime === null || !isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, WebP, MP4, WebM, or MOV files are allowed for the hero slider.');
    }

    $ext = $allowed[$mime];
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $filename)) {
        throw new RuntimeException('Could not save the uploaded slider media.');
    }

    return $filename;
}

function delete_uploaded_image(?string $filename): void
{
    if ($filename && is_file(UPLOAD_DIR . $filename)) {
        @unlink(UPLOAD_DIR . $filename);
    }
}

function delete_uploaded_media(?string $filename): void
{
    delete_uploaded_image($filename);
}

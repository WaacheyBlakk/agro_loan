<?php
// src/upload.php
require_once __DIR__ . '/db.php';
$config = require __DIR__ . '/config.php';
$uploadDir = $config['upload_dir'] ?? __DIR__ . '/../uploads/';

function handle_stage_upload($stage_id, $farmer_id, $file) {
    global $config;
    $uploadDir = rtrim($config['upload_dir'], '/');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Basic validation
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Upload error code: ' . $file['error']);
    }
    $maxSize = 50 * 1024 * 1024; // 50MB
    if ($file['size'] > $maxSize) throw new Exception('File too large');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $ext = null;
    $type = 'other';
    $allowedImage = ['image/jpeg','image/png','image/gif','image/webp'];
    $allowedVideo = ['video/mp4','video/quicktime','video/x-msvideo','video/x-ms-wmv','video/x-msvideo','video/3gpp'];

    if (in_array($mime, $allowedImage)) {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $type = 'image';
    } elseif (in_array($mime, $allowedVideo)) {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'mp4';
        $type = 'video';
    } else {
        throw new Exception('Unsupported file type: ' . $mime);
    }

    // Fetch application id
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT application_id FROM loan_stages WHERE id = ?");
    $stmt->execute([$stage_id]);
    $row = $stmt->fetch();
    if (!$row) throw new Exception('Invalid stage');

    $appId = $row['application_id'];
    $targetDir = "{$uploadDir}/app_{$appId}/stage_{$stage_id}";
    if (!is_dir($targetDir)) mkdir($targetDir, 0775, true);

    $filename = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $targetPath = "{$targetDir}/{$filename}";
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception('Failed to move uploaded file');
    }

    // ✅ Insert proof with 'pending' status
    $stmt = $pdo->prepare("
        INSERT INTO stage_proofs (stage_id, farmer_id, filename, file_type, status)
        VALUES (?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([$stage_id, $farmer_id, $filename, $type]);

    // Update loan stage status to under_review
    $stmt2 = $pdo->prepare("UPDATE loan_stages SET status = 'under_review' WHERE id = ?");
    $stmt2->execute([$stage_id]);

    return ['path' => $targetPath, 'filename' => $filename];
}

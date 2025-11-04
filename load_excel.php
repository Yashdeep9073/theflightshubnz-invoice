<?php
session_start();
require "./database/config.php";

if (!isset($_SESSION["admin_id"])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$fileId = $_GET['file'] ?? 0;
if ($fileId <= 0) {
    echo json_encode([['']]);
    exit;
}

// === Get file + permission check ===
$stmt = $db->prepare("
    SELECT ff.id, fd.content 
    FROM file_folders ff 
    LEFT JOIN file_data fd ON ff.id = fd.file_folder_id 
    WHERE ff.id = ? AND ff.type = 'file'
    AND (ff.created_by = ? OR EXISTS (
        SELECT 1 FROM file_permissions fp 
        WHERE fp.file_folder_id = ? AND fp.user_id = ?
    ))
");
$stmt->bind_param("iiii", $fileId, $_SESSION["admin_id"], $fileId, $_SESSION["admin_id"]);
$stmt->execute();
$result = $stmt->get_result();
$file = $result->fetch_assoc();

if (!$file) {
    echo json_encode([['']]);
    exit;
}

if (!empty($file['content'])) {
    $data = json_decode($file['content'], true);
    echo json_encode($data ?: [['']]);
} else {
    echo json_encode([['']]);
}
?>
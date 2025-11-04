<?php
session_start();
require "./database/config.php";

if (!isset($_SESSION["admin_id"])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$admin_id = base64_decode($_SESSION["admin_id"]);
$file_id = (int)($_GET['file_id'] ?? 0);

if ($file_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid file ID']);
    exit;
}

try {
    // Verify ownership
    $stmt = $db->prepare("SELECT created_by FROM file_folders WHERE id = ?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $file = $result->fetch_assoc();
    
    if (!$file || $file['created_by'] != $admin_id) {
        echo json_encode(['success' => false, 'error' => 'No permission']);
        exit;
    }
    
    // Get existing shares
    $stmt = $db->prepare("
        SELECT user_id, permission 
        FROM file_permissions 
        WHERE file_folder_id = ?
    ");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $shares = $result->fetch_all(MYSQLI_ASSOC);
    
    $users = [];
    $permission = 'view'; // default
    
    foreach ($shares as $share) {
        $users[] = $share['user_id'];
        // If any user has edit permission, set that as the current permission
        if ($share['permission'] === 'edit') {
            $permission = 'edit';
        }
    }
    
    echo json_encode([
        'success' => true,
        'users' => $users,
        'permission' => $permission
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
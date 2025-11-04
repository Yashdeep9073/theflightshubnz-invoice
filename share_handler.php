<?php
session_start();

require "./database/config.php";
if (!isset($_SESSION["admin_id"])) {
    header("Location: " . getenv("BASE_URL"));
    exit();
}

$admin_id = $_SESSION["admin_id"];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fileId = (int)($_POST['file_id'] ?? 0);
    $users = $_POST['users'] ?? [];
    $perm = $_POST['permission'] ?? 'view';
    
    // Debug logging (you can remove this later)
    error_log("Share attempt - File ID: $fileId, User ID: $admin_id, Users to share: " . implode(',', $users));
    
    try {
        // Verify file exists and get ownership info
        $stmt = $db->prepare("SELECT id, created_by, name, type FROM file_folders WHERE id = ?");
        $stmt->bind_param("i", $fileId);
        $stmt->execute();
        $result = $stmt->get_result();
        $file = $result->fetch_assoc();
        
        if (!$file) {
            $_SESSION['error'] = "File not found (ID: $fileId)";
            error_log("File not found with ID: $fileId");
        } else {
            error_log("File found: " . $file['name'] . ", Owner: " . $file['created_by'] . ", Current User: $admin_id");
            
            if ($file['created_by'] == $admin_id) {
                // Clear existing permissions
                $stmt = $db->prepare("DELETE FROM file_permissions WHERE file_folder_id = ?");
                $stmt->bind_param("i", $fileId);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to clear existing permissions: " . $stmt->error);
                }
                
                // Add new permissions if users selected
                $sharedCount = 0;
                if (!empty($users) && is_array($users)) {
                    $ins = $db->prepare("INSERT INTO file_permissions (file_folder_id, user_id, permission, granted_by) VALUES (?, ?, ?, ?)");
                    
                    foreach ($users as $u) {
                        $userId = (int)$u;
                        if ($userId > 0 && $userId != $admin_id) {
                            $ins->bind_param("iisi", $fileId, $userId, $perm, $admin_id);
                            if ($ins->execute()) {
                                $sharedCount++;
                            } else {
                                error_log("Failed to share with user $userId: " . $ins->error);
                            }
                        }
                    }
                    $ins->close();
                }
                
                if ($sharedCount > 0) {
                    $_SESSION['success'] = "✅ '" . htmlspecialchars($file['name']) . "' shared successfully with $sharedCount user(s) (" . ucfirst($perm) . " permission)";
                } else {
                    $_SESSION['success'] = "✅ Sharing updated - '" . htmlspecialchars($file['name']) . "' is no longer shared with anyone";
                }
                
            } else {
                $_SESSION['error'] = "❌ You don't have permission to share '" . htmlspecialchars($file['name']) . "'. Only the owner can share files.";
                error_log("Permission denied - File owner: " . $file['created_by'] . ", Current user: $admin_id");
            }
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "❌ Failed to share: " . $e->getMessage();
        error_log("Share error: " . $e->getMessage());
    }
} else {
    $_SESSION['error'] = "❌ Invalid request method";
}

// Store the messages in session for display on the next page
if (isset($_SERVER['HTTP_REFERER'])) {
    header("Location: " . $_SERVER['HTTP_REFERER']);
} else {
    header("Location: " . getenv("BASE_URL") . "spreadsheet");
}
exit;
?>
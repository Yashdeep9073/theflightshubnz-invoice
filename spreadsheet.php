<?php
session_start();
require "./database/config.php";
if (!isset($_SESSION["admin_id"])) {
    header("Location: index.php");
    exit();
}
$admin_id = base64_decode($_SESSION["admin_id"]);

// Function to encode/decode file IDs for security
function encodeFileId($id)
{
    return base64_encode($id . '_' . time());
}

function decodeFileId($encoded)
{
    $decoded = base64_decode($encoded);
    $parts = explode('_', $decoded);
    return $parts[0] ?? 0;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ADD FOLDER
    if ($action === 'add_folder') {
        $name = trim($_POST['name'] ?? '');
        $parent = !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null;

        if ($name) {
            try {
                $stmt = $db->prepare("INSERT INTO file_folders (name, type, parent_id, created_by) VALUES (?, ?, ?, ?)");
                $type = 'folder';
                $stmt->bind_param("ssii", $name, $type, $parent, $admin_id);
                $stmt->execute();
                $_SESSION['success'] = "Folder created successfully";
            } catch (Exception $e) {
                $_SESSION['error'] = "Failed to create folder: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Folder name is required";
        }
        header("Location: spreadsheet.php");
        exit;
    }

    // ADD FILE
    if ($action === 'add_file') {
        $name = trim($_POST['name'] ?? '');
        $parent = !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null;

        if ($name) {
            try {
                $luckysheetData = [
                    "name" => $name,
                    "config" => [],
                    "data" => [
                        [
                            "name" => "Sheet1",
                            "color" => "",
                            "status" => "1",
                            "order" => "0",
                            "row" => 84,
                            "column" => 60,
                            "config" => [],
                            "index" => 0,
                            "chart" => [],
                            "celldata" => []
                        ]
                    ]
                ];

                $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) . '_' . time() . '.json';
                $filepath = "uploads/spreadsheets/" . $filename;
                if (!is_dir('uploads/spreadsheets'))
                    mkdir('uploads/spreadsheets', 0755, true);

                file_put_contents($filepath, json_encode($luckysheetData));

                $stmt = $db->prepare("INSERT INTO file_folders (name, type, parent_id, file_path, created_by) VALUES (?, ?, ?, ?, ?)");
                $type = 'file';
                $stmt->bind_param("ssisi", $name, $type, $parent, $filepath, $admin_id);
                $stmt->execute();

                $_SESSION['success'] = "Spreadsheet created successfully";
            } catch (Exception $e) {
                $_SESSION['error'] = "Failed to create spreadsheet: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "File name is required";
        }
        header("Location: spreadsheet.php");
        exit;
    }

    // SHARE
    if ($action === 'share') {
        $fileId = (int) ($_POST['file_id'] ?? 0);
        $users = $_POST['users'] ?? [];
        $perm = $_POST['permission'] ?? 'view';

        try {
            $stmt = $db->prepare("SELECT id, name, type, created_by FROM file_folders WHERE id = ?");
            $stmt->bind_param("i", $fileId);
            $stmt->execute();
            $result = $stmt->get_result();
            $file = $result->fetch_assoc();

            if (!$file) {
                $_SESSION['error'] = "Item not found";
            } else if ($file['created_by'] != $admin_id) {
                $_SESSION['error'] = "You can only share your own items.";
            } else {
                $stmt = $db->prepare("SELECT user_id FROM file_permissions WHERE file_folder_id = ?");
                $stmt->bind_param("i", $fileId);
                $stmt->execute();
                $current = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $currentUsers = array_column($current, 'user_id');
                $submittedUsers = array_map('intval', $users);

                $toAdd = array_diff($submittedUsers, $currentUsers);
                $toRemove = array_diff($currentUsers, $submittedUsers);

                if (!empty($toRemove)) {
                    $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
                    $stmt = $db->prepare("DELETE FROM file_permissions WHERE file_folder_id = ? AND user_id IN ($placeholders)");
                    $params = array_merge([$fileId], $toRemove);
                    $types = str_repeat('i', count($params));
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                }

                if (!empty($toAdd)) {
                    $ins = $db->prepare("INSERT INTO file_permissions (file_folder_id, user_id, permission, granted_by) VALUES (?, ?, ?, ?)");
                    foreach ($toAdd as $uid) {
                        if ($uid > 0 && $uid != $admin_id) {
                            $ins->bind_param("iisi", $fileId, $uid, $perm, $admin_id);
                            $ins->execute();
                        }
                    }
                }

                if (!empty($submittedUsers)) {
                    $upd = $db->prepare("UPDATE file_permissions SET permission = ? WHERE file_folder_id = ? AND user_id = ?");
                    foreach ($submittedUsers as $uid) {
                        if ($uid > 0 && $uid != $admin_id) {
                            $upd->bind_param("sii", $perm, $fileId, $uid);
                            $upd->execute();
                        }
                    }
                }

                if ($file['type'] === 'folder' && (!empty($toAdd) || !empty($toRemove))) {
                    shareFolderContents($fileId, $toAdd, $toRemove, $perm, $admin_id, $db);
                }

                $changes = count($toAdd) + count($toRemove);
                $_SESSION['success'] = $changes > 0
                    ? "Sharing updated for '{$file['name']}'"
                    : "No changes made to sharing.";
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Share failed: " . $e->getMessage();
        }
        header("Location: spreadsheet.php");
        exit;
    }

    // DELETE
    if ($action === 'delete') {
        $itemId = (int) ($_POST['item_id'] ?? 0);

        try {
            // Fetch item info and check if it's shared or owned by current admin
            $stmt = $db->prepare("
            SELECT ff.*, COUNT(fp.id) as shared_count
            FROM file_folders ff
            LEFT JOIN file_permissions fp ON ff.id = fp.file_folder_id
            WHERE ff.id = ?
            GROUP BY ff.id
        ");
            $stmt->bind_param("i", $itemId);
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();

            if (!$item) {
                $_SESSION['error'] = "Item not found.";
            } else if ($item['created_by'] != $admin_id) {
                $_SESSION['error'] = "You can only delete your own items.";
            } else if ($item['shared_count'] > 0) {
                $_SESSION['error'] = "Cannot delete shared item. Unshare first.";
            } else {
                // Delete all related files if it's a folder
                if ($item['type'] === 'folder') {
                    // Fetch all files in this folder
                    $stmtFiles = $db->prepare("SELECT id, file_path FROM file_folders WHERE parent_id = ?");
                    $stmtFiles->bind_param("i", $itemId);
                    $stmtFiles->execute();
                    $files = $stmtFiles->get_result();

                    while ($file = $files->fetch_assoc()) {
                        if ($file['file_path'] && file_exists($file['file_path'])) {
                            unlink($file['file_path']); // delete physical file
                        }

                        // delete file record from database
                        $delFileStmt = $db->prepare("DELETE FROM file_folders WHERE id = ?");
                        $delFileStmt->bind_param("i", $file['id']);
                        $delFileStmt->execute();
                    }

                    // Optionally, remove the folder directory itself (if stored on disk)
                    if ($item['file_path'] && is_dir($item['file_path'])) {
                        rmdir($item['file_path']); // remove empty directory
                    }
                } else {
                    // If it's a single file, remove it from filesystem
                    if ($item['file_path'] && file_exists($item['file_path'])) {
                        unlink($item['file_path']);
                    }
                }

                // Delete the folder/file record itself
                $stmt = $db->prepare("DELETE FROM file_folders WHERE id = ?");
                $stmt->bind_param("i", $itemId);
                $stmt->execute();

                $_SESSION['success'] = ucfirst($item['type']) . " '{$item['name']}' deleted successfully.";
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Delete failed: " . $e->getMessage();
        }

        header("Location: spreadsheet.php");
        exit;
    }

}

function shareFolderContents($folderId, $add, $remove, $perm, $adminId, $db)
{
    $stmt = $db->prepare("SELECT id, type FROM file_folders WHERE parent_id = ?");
    $stmt->bind_param("i", $folderId);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($items as $item) {
        if (!empty($remove)) {
            $placeholders = implode(',', array_fill(0, count($remove), '?'));
            $del = $db->prepare("DELETE FROM file_permissions WHERE file_folder_id = ? AND user_id IN ($placeholders)");
            $params = array_merge([$item['id']], $remove);
            $types = str_repeat('i', count($params));
            $del->bind_param($types, ...$params);
            $del->execute();
        }
        if (!empty($add)) {
            $ins = $db->prepare("INSERT INTO file_permissions (file_folder_id, user_id, permission, granted_by) VALUES (?, ?, ?, ?)");
            foreach ($add as $uid) {
                if ($uid > 0 && $uid != $adminId) {
                    $ins->bind_param("iisi", $item['id'], $uid, $perm, $adminId);
                    $ins->execute();
                }
            }
        }
        if ($item['type'] === 'folder') {
            shareFolderContents($item['id'], $add, $remove, $perm, $adminId, $db);
        }
    }
}

// Fetch My Items + Shared Users
$myTree = [];
$fileSharedUsers = [];
try {

    $stmtFetchCompanySettings = $db->prepare("SELECT * FROM company_settings");
    $stmtFetchCompanySettings->execute();
    $companySettings = $stmtFetchCompanySettings->get_result()->fetch_array(MYSQLI_ASSOC);

    $stmt = $db->prepare("
        SELECT ff.*, 
               GROUP_CONCAT(DISTINCT fp.user_id) AS shared_with,
               GROUP_CONCAT(DISTINCT fp.permission) AS shared_perm,
               COUNT(DISTINCT fp.id) as shared_count
        FROM file_folders ff
        LEFT JOIN file_permissions fp ON ff.id = fp.file_folder_id
        WHERE ff.created_by = ?
        GROUP BY ff.id
        ORDER BY ff.name
    ");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $myItems = $result->fetch_all(MYSQLI_ASSOC);

    $itemMap = [];
    foreach ($myItems as $item) {
        $itemMap[$item['id']] = $item;
        $itemMap[$item['id']]['children'] = [];

        $sharedUsers = !empty($item['shared_with']) ? explode(',', $item['shared_with']) : [];
        $perm = !empty($item['shared_perm']) ? explode(',', $item['shared_perm'])[0] : 'view';
        $fileSharedUsers[$item['id']] = [
            'users' => $sharedUsers,
            'permission' => $perm,
            'count' => $item['shared_count']
        ];
    }

    foreach ($myItems as $item) {
        if (!$item['parent_id']) {
            $myTree[] = &$itemMap[$item['id']];
        } else if (isset($itemMap[$item['parent_id']])) {
            $itemMap[$item['parent_id']]['children'][] = &$itemMap[$item['id']];
        }
    }
} catch (Exception $e) {
    $_SESSION['error'] = "Load error: " . $e->getMessage();
}

// Shared With Me
// Shared With Me - Now includes inherited permissions via parent folders
$sharedItems = [];
$accessibleItemIds = []; // Will hold all IDs user can access

try {
    // Step 1: Find all items the user has DIRECT permission on
    $stmt = $db->prepare("
        SELECT file_folder_id, permission 
        FROM file_permissions 
        WHERE user_id = ? AND granted_by != ?
    ");
    $stmt->bind_param("ii", $admin_id, $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $directAccess = [];
    while ($row = $result->fetch_assoc()) {
        $directAccess[$row['file_folder_id']] = $row['permission'];
        $accessibleItemIds[] = $row['file_folder_id'];
    }

    // Step 2: Find all ancestors of directly shared items (so user can navigate up)
    // And all descendants (so user sees everything inside shared folders)
    if (!empty($accessibleItemIds)) {
        $placeholders = implode(',', array_fill(0, count($accessibleItemIds), '?'));
        
        // Get all descendants (children, grandchildren, etc.)
        $descendantsQuery = "
            WITH RECURSIVE descendants AS (
                SELECT id, parent_id 
                FROM file_folders 
                WHERE id IN ($placeholders)
                
                UNION ALL
                
                SELECT ff.id, ff.parent_id 
                FROM file_folders ff
                INNER JOIN descendants d ON ff.parent_id = d.id
            )
            SELECT id FROM descendants
        ";
        $stmt = $db->prepare($descendantsQuery);
        $stmt->bind_param(str_repeat('i', count($accessibleItemIds)), ...$accessibleItemIds);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (!in_array($row['id'], $accessibleItemIds)) {
                $accessibleItemIds[] = $row['id'];
            }
        }
    }

    // Step 3: Now fetch ALL items (folders + files) that are in accessible scope
    if (!empty($accessibleItemIds)) {
        $placeholders = implode(',', array_fill(0, count($accessibleItemIds), '?'));
        
        $stmt = $db->prepare("
            SELECT ff.*, a.admin_username AS owner_name,
                   COALESCE(fp.permission, 'view') AS permission
            FROM file_folders ff
            JOIN admin a ON ff.created_by = a.admin_id
            LEFT JOIN file_permissions fp ON ff.id = fp.file_folder_id AND fp.user_id = ?
            WHERE ff.id IN ($placeholders)
              AND ff.created_by != ?
            ORDER BY ff.parent_id, ff.name
        ");
        $params = array_merge([$admin_id], $accessibleItemIds, [$admin_id]);
        $types = 'i' . str_repeat('i', count($accessibleItemIds)) . 'i';
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $sharedItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    error_log("Shared items error: " . $e->getMessage());
}

// Build shared items tree structure
$sharedTree = [];
$sharedItemMap = [];

foreach ($sharedItems as $item) {
    $itemId = $item['id'];
    $sharedItemMap[$itemId] = $item;
    $sharedItemMap[$itemId]['children'] = [];
}

foreach ($sharedItems as $item) {
    if (!$item['parent_id'] || !isset($sharedItemMap[$item['parent_id']])) {
        $sharedTree[] = &$sharedItemMap[$item['id']];
    } else {
        $sharedItemMap[$item['parent_id']]['children'][] = &$sharedItemMap[$item['id']];
    }
}

// All Admins
$allAdmins = [];
try {
    $stmt = $db->prepare("SELECT admin_id, admin_username FROM admin WHERE admin_id != ? ORDER BY admin_username");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $allAdmins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
}

// Updated render functions with encoded IDs
function renderMyTree($items, $level = 0)
{
    global $fileSharedUsers;
    foreach ($items as $item):
        $shared = $fileSharedUsers[$item['id']] ?? ['users' => [], 'permission' => 'view', 'count' => 0];
        $hasChildren = !empty($item['children']);
        $isShared = $shared['count'] > 0;
        $encodedId = base64_encode($item['id']);
        ?>
        <?php if ($item['type'] === 'folder'): ?>
            <li class="fm-folder-item">
                <div class="fm-item">
                    <div class="d-flex align-items-center flex-grow-1">
                        <?php if ($hasChildren): ?>
                            <span class="fm-folder-toggle collapsed" title="Toggle folder">
                                <i class="fas fa-chevron-down"></i>
                            </span>
                        <?php else: ?>
                            <span class="fm-folder-toggle" style="visibility: hidden;">
                                <i class="fas fa-chevron-down"></i>
                            </span>
                        <?php endif; ?>
                        <div class="fm-item-icon">
                            <i class="fas fa-folder"></i>
                        </div>
                        <div class="fm-item-content">
                            <div class="fm-item-name">
                                <?= htmlspecialchars($item['name']) ?>
                                <?php if ($isShared): ?>
                                    <span class="badge bg-success shared-badge"><?= $shared['count'] ?> shared</span>
                                <?php endif; ?>
                            </div>
                            <div class="fm-item-meta">
                                Folder
                            </div>
                        </div>
                    </div>
                    <div class="fm-item-actions">
                        <button class="btn btn-sm btn-outline-primary add-in-btn" data-id="<?= $item['id'] ?>" title="Add File">
                            <i class="fas fa-plus"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-primary share-btn" data-id="<?= $item['id'] ?>"
                            data-name="<?= htmlspecialchars($item['name']) ?>" data-type="folder"
                            data-shared-users='<?= json_encode($shared['users']) ?>' data-permission="<?= $shared['permission'] ?>">
                            <i class="fas fa-share-alt"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger delete-btn" data-id="<?= $item['id'] ?>"
                            data-name="<?= htmlspecialchars($item['name']) ?>" data-type="folder"
                            data-shared="<?= $isShared ? '1' : '0' ?>">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <?php if ($hasChildren): ?>
                    <ul class="fm-folder-children" style="display: none;">
                        <?php renderMyTree($item['children'], $level + 1); ?>
                    </ul>
                <?php endif; ?>
            </li>

        <?php else: ?>
            <li class="fm-file-item">
                <div class="fm-item">
                    <div class="d-flex align-items-center flex-grow-1">
                        <span class="fm-folder-toggle" style="visibility: hidden;">
                            <i class="fas fa-chevron-down"></i>
                        </span>
                        <div class="fm-item-icon">
                            <i class="fas fa-file"></i>
                        </div>
                        <div class="fm-item-content">
                            <div class="fm-item-name">
                                <a href="editor.php?file=<?= $encodedId ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($item['name']) ?>
                                </a>
                                <?php if ($isShared): ?>
                                    <span class="badge bg-success shared-badge"><?= $shared['count'] ?> shared</span>
                                <?php endif; ?>
                            </div>
                            <div class="fm-item-meta">
                                Spreadsheet File
                            </div>
                        </div>
                    </div>
                    <div class="fm-item-actions">
                        <button class="btn btn-sm btn-outline-primary share-btn" data-id="<?= $item['id'] ?>"
                            data-name="<?= htmlspecialchars($item['name']) ?>" data-type="file"
                            data-shared-users='<?= json_encode($shared['users']) ?>' data-permission="<?= $shared['permission'] ?>">
                            <i class="fas fa-share-alt"></i>
                        </button>
                        <a href="editor.php?file=<?= $encodedId ?>" class="btn btn-sm btn-outline-warning" title="Edit">
                            <i class="fas fa-edit"></i>
                        </a>
                        <a href="download_excel.php?file=<?= $encodedId ?>" class="btn btn-sm btn-outline-info" title="Download">
                            <i class="fas fa-download"></i>
                        </a>
                        <button class="btn btn-sm btn-outline-danger delete-btn" data-id="<?= $item['id'] ?>"
                            data-name="<?= htmlspecialchars($item['name']) ?>" data-type="file"
                            data-shared="<?= $isShared ? '1' : '0' ?>">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </li>
        <?php endif; ?>
    <?php endforeach;
}

function renderSharedTree($items, $level = 0)
{
    foreach ($items as $item):
        $hasChildren = !empty($item['children']);
        $encodedId = base64_encode($item['id']);
        ?>
        <?php if ($item['type'] === 'folder'): ?>
            <li class="fm-folder-item">
                <div class="fm-item">
                    <div class="d-flex align-items-center flex-grow-1">
                        <?php if ($hasChildren): ?>
                            <span class="fm-folder-toggle collapsed" title="Toggle folder">
                                <i class="fas fa-chevron-down"></i>
                            </span>
                        <?php else: ?>
                            <span class="fm-folder-toggle" style="visibility: hidden;">
                                <i class="fas fa-chevron-down"></i>
                            </span>
                        <?php endif; ?>
                        <div class="fm-item-icon">
                            <i class="fas fa-folder"></i>
                        </div>
                        <div class="fm-item-content">
                            <div class="fm-item-name">
                                <?= htmlspecialchars($item['name']) ?>
                            </div>
                            <div class="fm-item-meta">
                                Shared by <?= htmlspecialchars($item['owner_name']) ?>
                                <span class="badge bg-<?= $item['permission'] === 'edit' ? 'success' : 'info' ?> ms-2">
                                    <?= $item['permission'] === 'edit' ? 'Can Edit' : 'View Only' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if ($hasChildren): ?>
                    <ul class="fm-folder-children" style="display: none;">
                        <?php renderSharedTree($item['children'], $level + 1); ?>
                    </ul>
                <?php endif; ?>
            </li>
        <?php else: ?>
            <li class="fm-file-item">
                <div class="fm-item">
                    <div class="d-flex align-items-center flex-grow-1">
                        <span class="fm-folder-toggle" style="visibility: hidden;">
                            <i class="fas fa-chevron-down"></i>
                        </span>
                        <div class="fm-item-icon">
                            <i class="fas fa-file"></i>
                        </div>
                        <div class="fm-item-content">
                            <div class="fm-item-name">
                                <a href="editor.php?file=<?= $encodedId ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($item['name']) ?>
                                </a>
                            </div>
                            <div class="fm-item-meta">
                                Shared by <?= htmlspecialchars($item['owner_name']) ?>
                                <span class="badge bg-<?= $item['permission'] === 'edit' ? 'success' : 'info' ?> ms-2">
                                    <?= $item['permission'] === 'edit' ? 'Can Edit' : 'View Only' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="fm-item-actions">
                        <a href="editor.php?file=<?= $encodedId ?>" class="btn btn-sm btn-outline-warning" title="Edit">
                            <i class="fas fa-edit"></i>
                        </a>
                        <a href="download_excel.php?file=<?= $encodedId ?>" class="btn btn-sm btn-outline-dark" title="Download">
                            <i class="fas fa-download"></i>
                        </a>
                    </div>
                </div>
            </li>
        <?php endif; ?>
    <?php endforeach;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spreadsheet File Manager</title>
    <link rel="shortcut icon" type="image/x-icon"
        href="<?= isset($companySettings['favicon']) ? $companySettings['favicon'] : "assets/img/fav/vis-favicon.png" ?>">
    <!-- toast  -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css" />

    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <link rel="stylesheet" href="assets/css/bootstrap.min.css">

    <link rel="stylesheet" href="assets/css/bootstrap-datetimepicker.min.css">

    <link rel="stylesheet" href="assets/css/animate.css">

    <link rel="stylesheet" href="assets/css/feather.css">

    <link rel="stylesheet" href="assets/plugins/select2/css/select2.min.css">

    <link rel="stylesheet" href="assets/plugins/bootstrap-tagsinput/bootstrap-tagsinput.css">

    <link rel="stylesheet" href="assets/css/dataTables.bootstrap5.min.css">

    <link rel="stylesheet" href="assets/plugins/fontawesome/css/fontawesome.min.css">
    <link rel="stylesheet" href="assets/plugins/fontawesome/css/all.min.css">

    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root {
            --primary-color: #086AD8;
            --primary-light: #e8f1fd;
            --border-color: #e0e0e0;
            --hover-bg: #f8f9fa;
        }

        .file-manager-container {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .fm-header {
            background-color: var(--primary-light);
            border-bottom: 1px solid var(--border-color);
            padding: 15px 20px;
        }

        .fm-tabs {
            border-bottom: 1px solid var(--border-color);
        }

        .fm-tabs .nav-link {
            color: #6c757d;
            font-weight: 500;
            padding: 12px 20px;
            border: none;
            border-bottom: 2px solid transparent;
        }

        .fm-tabs .nav-link.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
            background: transparent;
        }

        .fm-content {
            padding: 20px;
        }

        .fm-actions {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-color);
            background: #f8f9fa;
        }

        .fm-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .fm-item {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            transition: background-color 0.2s;
        }

        .fm-item:hover {
            background-color: var(--hover-bg);
        }

        .fm-item:last-child {
            border-bottom: none;
        }

        .fm-item-icon {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            margin-right: 12px;
            color: var(--primary-color);
            background-color: var(--primary-light);
        }

        .fm-item-content {
            flex: 1;
        }

        .fm-item-name {
            font-weight: 500;
            color: #333;
            margin-bottom: 2px;
        }

        .fm-item-meta {
            font-size: 12px;
            color: #6c757d;
        }

        .fm-item-actions {
            display: flex;
            gap: 5px;
        }

        .fm-item-actions .btn {
            padding: 5px 8px;
            font-size: 12px;
        }

        .fm-folder-toggle {
            cursor: pointer;
            margin-right: 8px;
            color: #6c757d;
            width: 20px;
            text-align: center;
            transition: transform 0.2s;
        }

        .fm-folder-toggle.collapsed i {
            transform: rotate(-90deg);
        }

        .fm-folder-children {
            margin-left: 30px;
            border-left: 1px dashed var(--border-color);
            padding-left: 15px;
        }

        .fm-empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .fm-empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #dee2e6;
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: #0759c0;
            border-color: #0759c0;
        }

        .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-outline-primary:hover {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .badge.bg-primary {
            background-color: var(--primary-color) !important;
        }

        .modal-dialog-centered {
            display: flex;
            align-items: center;
            min-height: calc(100% - 1rem);
        }

        /* Shared item styling */
        .shared-badge {
            font-size: 11px;
            margin-left: 8px;
        }
    </style>
</head>

<body>

    <div class="main-wrapper">
        <!-- Header Start -->
        <div class="header">
            <?php require_once("header.php"); ?>
        </div>
        <!-- Header End -->

        <!-- Sidebar Start -->
        <div class="sidebar" id="sidebar">
            <?php require_once("sidebar.php"); ?>
        </div>

        <div class="sidebar collapsed-sidebar" id="collapsed-sidebar">
            <?php require_once("sidebar-collapsed.php"); ?>
        </div>

        <div class="sidebar horizontal-sidebar">
            <?php require_once("sidebar-horizontal.php"); ?>
        </div>
        <!-- Sidebar End -->

        <div class="page-wrapper">
            <div class="content">
                <div class="page-header">
                    <div class="add-item d-flex justify-content-between">
                        <div class="page-title">
                            <h4>File Manager</h4>
                            <h6>Manage files & folders</h6>
                        </div>
                    </div>
                </div>

                <div class="file-manager-container">
                    <div class="fm-header">
                        <h5 class="mb-0">Spreadsheet Files</h5>
                    </div>

                    <ul class="nav fm-tabs" id="folderTab">
                        <li class="nav-item">
                            <a class="nav-link active" data-bs-toggle="tab" href="#my-folder">My Folder</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="tab" href="#shared-folder">Shared With Me</a>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <!-- MY FOLDER -->
                        <div class="tab-pane fade show active" id="my-folder">
                            <div class="fm-actions d-flex gap-2">
                                <button id="btnAddFile" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i>
                                    New File</button>
                                <button id="btnAddFolder" class="btn btn-primary btn-sm"><i
                                        class="fas fa-folder-plus me-1"></i> New Folder</button>
                            </div>
                            <div class="fm-content">
                                <?php if (empty($myTree)): ?>
                                    <div class="fm-empty-state">
                                        <i class="fas fa-folder-open"></i>
                                        <p>No items yet. Create your first file or folder!</p>
                                    </div>
                                <?php else: ?>
                                    <ul class="fm-list">
                                        <?php renderMyTree($myTree); ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- SHARED WITH ME -->
                        <div class="tab-pane fade" id="shared-folder">
                            <div class="fm-content">
                                <?php if (empty($sharedTree)): ?>
                                    <div class="fm-empty-state">
                                        <i class="fas fa-share-alt"></i>
                                        <p>No shared items yet.</p>
                                    </div>
                                <?php else: ?>
                                    <ul class="fm-list">
                                        <?php renderSharedTree($sharedTree); ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODALS -->
    <div class="modal fade" id="modalFolder" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-md">
            <form method="POST" class="modal-content">
                <input type="hidden" name="action" value="add_folder">
                <input type="hidden" name="parent_id" id="folderParent">
                <div class="modal-header">
                    <h5 class="modal-title">New Folder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="folderName" class="form-label">Folder Name</label>
                        <input type="text" name="name" class="form-control" id="folderName"
                            placeholder="Enter folder name" required>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i
                            class="fa fa-close me-2"></i>Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Create</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="modalFile" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-md">
            <form method="POST" class="modal-content">
                <input type="hidden" name="action" value="add_file">
                <input type="hidden" name="parent_id" id="fileParent">
                <div class="modal-header">
                    <h5 class="modal-title">New Spreadsheet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="fileName" class="form-label">File Name</label>
                        <input type="text" name="name" class="form-control" id="fileName" placeholder="Enter file name"
                            required>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        A blank spreadsheet will be created.
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i
                            class="fa fa-close me-2"></i>Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Create</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="modalShare" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-md">
            <form method="POST" class="modal-content">
                <input type="hidden" name="action" value="share">
                <input type="hidden" name="file_id" id="shareId">
                <div class="modal-header">
                    <h5 class="modal-title" id="shareModalTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Users</label>
                        <select name="users[]" class="form-control select2-multiple" multiple id="shareUsersSelect">
                            <?php foreach ($allAdmins as $a): ?>
                                <option value="<?= $a['admin_id'] ?>"><?= htmlspecialchars($a['admin_username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Permission</label>
                        <select name="permission" class="form-control" id="sharePermission">
                            <option value="view">View Only</option>
                            <option value="edit">Can Edit</option>
                        </select>
                    </div>

                    <div class="alert alert-info mb-0">
                        <i class="fas fa-lightbulb me-2"></i>
                        <strong>Tip:</strong> To remove all sharing, deselect all users and click Share.
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i
                            class="fa fa-close me-2"></i>Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-share me-2"></i>Update</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="modalDelete" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-md">
            <form method="POST" class="modal-content">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="item_id" id="deleteId">
                <div class="modal-header text-danger">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete <strong id="deleteItemName"></strong>?</p>
                    <div id="deleteWarning" class="alert alert-warning" style="display:none">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        This item is shared. Unshare first.
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-2"></i>Delete</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i
                            class="fa fa-close me-2"></i>Cancel</button>

                </div>
            </form>
        </div>
    </div>

    <!-- IMPORTANT: Load jQuery FIRST -->
    <script src="assets/js/jquery-3.7.1.min.js"></script>

    <!-- Load Mousewheel plugin BEFORE Luckysheet -->
    <script src="https://cdn.jsdelivr.net/npm/jquery-mousewheel@3.1.13/jquery.mousewheel.min.js"></script>

    <!-- Then load Luckysheet -->
    <script src="https://cdn.jsdelivr.net/npm/luckysheet/dist/plugins/js/plugin.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/luckysheet/dist/luckysheet.umd.js"></script>

    <!-- Then load other scripts -->
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script src="assets/js/feather.min.js"></script>
    <script src="assets/js/jquery.slimscroll.min.js"></script>
    <script src="assets/plugins/select2/js/select2.min.js"></script>
    <script src="assets/js/jquery.dataTables.min.js"></script>
    <script src="assets/js/dataTables.bootstrap5.min.js"></script>
    <script src="assets/js/script.js"></script>
    <script src="assets/js/custom-select2.js"></script>
    <script src="assets/js/custom.js"></script>

    <script>
        // Single Notyf instance - create it once
        const notyf = new Notyf({
            duration: 4000,
            position: {
                x: 'right',
                y: 'top'
            },
            types: [
                {
                    type: 'success',
                    background: '#4dc76f',
                    icon: {
                        className: 'fas fa-check',
                        tagName: 'i',
                        text: ''
                    }
                },
                {
                    type: 'error',
                    background: '#ff1916',
                    icon: {
                        className: 'fas fa-times',
                        tagName: 'i',
                        text: ''
                    }
                }
            ]
        });

        // Show notifications when DOM is ready
        $(document).ready(function () {
            <?php if (isset($_SESSION['success'])): ?>
                notyf.success("<?= addslashes($_SESSION['success']) ?>");
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                notyf.error("<?= addslashes($_SESSION['error']) ?>");
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            function initSelect2() {
                $('.select2-multiple').select2({
                    width: '100%',
                    placeholder: 'Choose users...',
                    allowClear: true
                });
            }

            // Fixed folder toggle functionality
            $(document).on('click', '.fm-folder-toggle', function () {
                const $toggle = $(this);
                const $children = $toggle.closest('.fm-folder-item').find('> .fm-folder-children');

                $toggle.toggleClass('collapsed');
                $children.slideToggle(200);
            });

            $('#btnAddFolder').click(() => {
                $('#folderParent').val('');
                $('#modalFolder').modal('show');
            });

            $('#btnAddFile').click(() => {
                $('#fileParent').val('');
                $('#modalFile').modal('show');
            });

            $(document).on('click', '.add-in-btn', function () {
                $('#fileParent').val($(this).data('id'));
                $('#modalFile').modal('show');
            });

            $(document).on('click', '.share-btn', function () {
                const data = $(this).data();
                $('#shareId').val(data.id);
                $('#shareModalTitle').text('Share ' + (data.type === 'folder' ? 'Folder' : 'File') + ': ' + data.name);
                $('#sharePermission').val(data.permission);
                $('#folderShareInfo').toggle(data.type === 'folder');
                $('#fileShareInfo').toggle(data.type !== 'folder');

                initSelect2();
                $('#shareUsersSelect').val(data.sharedUsers).trigger('change');
                $('#modalShare').modal('show');
            });

            $('#modalShare').on('hidden.bs.modal', () => {
                $('.select2-multiple').select2('destroy');
            });

            $(document).on('click', '.delete-btn', function () {
                const d = $(this).data();
                $('#deleteId').val(d.id);
                $('#deleteItemName').text(d.type + ': ' + d.name);
                $('#deleteWarning').toggle(d.shared == '1');
                $('#modalDelete .btn-danger').prop('disabled', d.shared == '1');
                $('#modalDelete').modal('show');
            });
        });
    </script>
</body>

</html>
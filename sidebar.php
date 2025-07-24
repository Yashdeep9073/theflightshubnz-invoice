<?php
$adminId = base64_decode($_SESSION['admin_id']) ?? null; // Use null coalescing operator for safety

try {
    $roleId = null; // Initialize role ID


    if (isset($adminId)) {
        // Fetch admin role
        $stmtAdmin = $db->prepare("SELECT admin_role FROM admin WHERE admin_id = ?");
        $stmtAdmin->bind_param('i', $adminId);
        $stmtAdmin->execute();
        $adminResponse = $stmtAdmin->get_result()->fetch_all(MYSQLI_ASSOC);

        if ($adminResponse) {
            $roleId = $adminResponse['0']['admin_role']; // Set role ID for admin
        } else {
            $_SESSION['error'] = "Admin not found for ID: " . $adminId;
            header('Location: index.php');
            exit();
        }
    } else {
        // No session ID found, redirect to login
        $_SESSION['error'] = "You must be logged in to access this page.";
        header('Location: index.php');
        exit();
    }


    // Fetch role details
    $stmtRolesData = $db->prepare("SELECT * FROM roles WHERE role_id = ?");
    $stmtRolesData->bind_param('i', $roleId);
    $stmtRolesData->execute();
    $roleData = $stmtRolesData->get_result()->fetch_all(MYSQLI_ASSOC);

    if (!$roleData) {
        $_SESSION['error'] = "Role not found for ID: " . $roleId;
        header('Location: index.php');
        exit();
    }

    // Fetch permissions for the role
    $stmtPrivileges = $db->prepare("
        SELECT p.permission_name 
        FROM permissions p
        JOIN role_permissions rp ON p.permission_id = rp.permission_id
        WHERE rp.role_id = ?
    ");
    $stmtPrivileges->bind_param('i', $roleId);
    $stmtPrivileges->execute();
    $privileges = $stmtPrivileges->get_result()->fetch_all(MYSQLI_ASSOC);
    $privileges = array_column($privileges, 'permission_name');

} catch (Exception $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header('Location: index.php');
    exit();
}

// Function to check if admin has a specific permission
function hasPermission($privilege, $privileges, $roleName)
{
    // If role is admin, grant all permissions
    if (strtolower($roleName) === 'admin') {
        return true;
    }
    // For other roles, check specific permissions
    return in_array($privilege, $privileges);
}

// Check if user is admin
$isAdmin = strtolower($roleData['0']['role_name']) === 'admin';
?>


<div class="sidebar-inner slimscroll">
    <div id="sidebar-menu" class="sidebar-menu">
        <ul>

            <?php if ($isAdmin || hasPermission('Dashboard', $privileges, $roleData['0']['role_name'])): ?>
                <li class="submenu-open">
                    <h6 class="submenu-hdr">Dashboard</h6>
                    <ul>
                        <li>
                            <a href="admin-dashboard.php"><i data-feather="grid"></i><span>Dashboard</span></a>
                        </li>
                    </ul>
                </li>
            <?php endif; ?>

            <?php if ($isAdmin || hasPermission('Invoice', $privileges, $roleData['0']['role_name'])): ?>
                <li class="submenu-open">
                    <h6 class="submenu-hdr">Invoice</h6>
                    <ul>

                        <li class="submenu">
                            <a href="javascript:void(0);"><i data-feather="file-text"></i><span>Invoice</span><span
                                    class="menu-arrow"></span></a>
                            <ul>
                                <?php if ($isAdmin || hasPermission('Manage Invoice', $privileges, $roleData['0']['role_name'])): ?>
                                    <li><a href="manage-invoice.php">Manage Invoice</a></li>
                                <?php endif; ?>

                                <?php if ($isAdmin || hasPermission('Recycle Bin', $privileges, $roleData['0']['role_name'])): ?>
                                    <li>
                                        <a href="recycle-bin.php">Recycle-bin</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </li>

                    </ul>
                </li>
            <?php endif; ?>

            <?php if ($isAdmin || hasPermission('Payments', $privileges, $roleData['0']['role_name'])): ?>
                <li class="submenu-open">
                    <h6 class="submenu-hdr">Payments</h6>
                    <ul>
                        <li class="submenu">
                            <a href="javascript:void(0);"><i data-feather="credit-card"></i><span>Payments</span><span
                                    class="menu-arrow"></span></a>
                            <ul>
                                <li><a href="manage-payments.php">All Payments</a></li>
                                <li>
                                    <a href="payment-paid.php">Paid Payments</a>
                                </li>
                                <li>
                                    <a href="payment-pending.php">Pending Payments</a>
                                </li>
                                <li>
                                    <a href="payment-cancelled.php">Cancelled Payments</a>
                                </li>
                                <li>
                                    <a href="payment-refunded.php">Refunded Payments</a>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </li>
            <?php endif; ?>

            <?php if ($isAdmin || hasPermission('Utility', $privileges, $roleData['0']['role_name'])): ?>
                <li class="submenu-open">
                    <h6 class="submenu-hdr">Utility</h6>
                    <ul>
                        <li class="submenu">
                            <a href="javascript:void(0);"><i data-feather="tool"></i><span>Utility</span><span
                                    class="menu-arrow"></span></a>
                            <ul>
                                <li>
                                    <a href="airlines.php">Airlines</a>
                                </li>
                                <li>
                                    <a href="services.php">Services</a>
                                </li>
                            </ul>
                        </li>

                    </ul>
                </li>
            <?php endif; ?>


            <?php if ($isAdmin || hasPermission('Reports', $privileges, $roleData['0']['role_name'])): ?>
                <li class="submenu-open">
                    <h6 class="submenu-hdr">Reports</h6>
                    <ul>
                        <li class="submenu">
                            <a href="javascript:void(0);"><i data-feather="file-text"></i><span>Reports</span><span
                                    class="menu-arrow"></span></a>
                            <ul>
                                <li><a href="reports.php">Invoice Report</a></li>
                            </ul>
                        </li>
                    </ul>
                </li>
            <?php endif; ?>

            <?php if ($isAdmin || hasPermission('User Management', $privileges, $roleData['0']['role_name'])): ?>
                <li class="submenu-open">
                    <h6 class="submenu-hdr">User Management</h6>
                    <ul>
                        <li class="submenu">
                            <a href="javascript:void(0);"><i data-feather="user-check"></i><span>User Management</span><span
                                    class="menu-arrow"></span></a>
                            <ul>

                                <li>
                                    <a href="admin.php">Users</a>
                                </li>
                                <li>
                                    <a href="permissions.php">Permission</a>
                                </li>
                                <li>
                                    <a href="roles.php">Role</a>
                                </li>
                            </ul>
                        </li>


                    </ul>
                </li>
            <?php endif; ?>


            <?php if ($isAdmin || hasPermission('Settings', $privileges, $roleData['0']['role_name'])): ?>
                <li class="submenu-open">
                    <h6 class="submenu-hdr">Settings</h6>
                    <ul>
                        <li class="submenu">
                            <a href="javascript:void(0);"><i data-feather="settings"></i><span>Settings</span><span
                                    class="menu-arrow"></span></a>
                            <ul>
                                <li>
                                    <a href="system-settings.php">System Settings</a>
                                </li>
                            </ul>
                        </li>


                    </ul>
                </li>
            <?php endif; ?>

            <li class="submenu-open">
                <ul>

                    <li>
                        <a href="logout.php"><i data-feather="log-out"></i><span>Logout</span>
                        </a>
                    </li>
                    <li>
                        <a href="javascript:void(0);"><i data-feather="lock"></i><span> v1.0.7</span></a>
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</div>
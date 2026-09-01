<?php
session_start();
require_once 'db.php';

// Authentication & Authorization Check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if ($user_role !== 'admin') {
    session_destroy();
    header("Location: login.php?error=unauthorized");
    exit();
}

$admin_id = intval($_SESSION['user_id']);
$message = "";
$error = "";

// -------------------------------------------------------------
// 1. BACKEND ACTIONS
// -------------------------------------------------------------

// A. Soft Delete Action (Files to Trash Bin)
if (isset($_GET['action']) && $_GET['action'] === 'soft_delete' && isset($_GET['file_id'])) {
    $file_id = intval($_GET['file_id']);
    $stmt = $conn->prepare("UPDATE files_registry SET is_deleted = 1 WHERE id = ?");
    $stmt->bind_param("i", $file_id);

    if ($stmt->execute()) {
        $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, 'SOFT_DELETE', ?)");
        if ($log_stmt) {
            $details = "Admin moved File ID: $file_id to Trash Bin";
            $log_stmt->bind_param("is", $admin_id, $details);
            $log_stmt->execute();
        }
        header("Location: admin_dashboard.php?msg=trashed&tab=files_tab");
        exit();
    } else {
        $error = "Error soft deleting file: " . $conn->error;
    }
}

// B. Archive File Action
if (isset($_GET['action']) && $_GET['action'] === 'archive_file' && isset($_GET['file_id'])) {
    $file_id = intval($_GET['file_id']);
    $stmt = $conn->prepare("UPDATE files_registry SET is_archived = 1 WHERE id = ?");
    $stmt->bind_param("i", $file_id);

    if ($stmt->execute()) {
        $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, 'ARCHIVE_FILE', ?)");
        if ($log_stmt) {
            $details = "Admin archived File ID: $file_id";
            $log_stmt->bind_param("is", $admin_id, $details);
            $log_stmt->execute();
        }
        header("Location: admin_dashboard.php?msg=archived&tab=archived_tab");
        exit();
    } else {
        $error = "Error archiving file: " . $conn->error;
    }
}

// C. Restore Archived File Action
if (isset($_GET['action']) && $_GET['action'] === 'unarchive_file' && isset($_GET['file_id'])) {
    $file_id = intval($_GET['file_id']);
    $stmt = $conn->prepare("UPDATE files_registry SET is_archived = 0 WHERE id = ?");
    $stmt->bind_param("i", $file_id);

    if ($stmt->execute()) {
        $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, 'UNARCHIVE_FILE', ?)");
        if ($log_stmt) {
            $details = "Admin restored archived File ID: $file_id back to active list";
            $log_stmt->bind_param("is", $admin_id, $details);
            $log_stmt->execute();
        }
        header("Location: admin_dashboard.php?msg=unarchived&tab=files_tab");
        exit();
    } else {
        $error = "Error unarchiving file: " . $conn->error;
    }
}

// D. Delete Audit Log Action
if (isset($_GET['action']) && $_GET['action'] === 'delete_log' && isset($_GET['log_id'])) {
    $log_id = intval($_GET['log_id']);
    $stmt = $conn->prepare("DELETE FROM audit_logs WHERE id = ?");
    $stmt->bind_param("i", $log_id);

    if ($stmt->execute()) {
        header("Location: admin_dashboard.php?msg=log_deleted&tab=audit_tab");
        exit();
    } else {
        $error = "Failed to delete log: " . $conn->error;
    }
}

// E. User Registration Logic with Updated Validations & Admin Limits
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    $first_name  = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    
    $new_user  = trim($_POST['username'] ?? '');
    $new_pass  = trim($_POST['password'] ?? '');
    $new_phone = trim($_POST['phone_number'] ?? '');
    $new_email = trim($_POST['email'] ?? '');
    $new_dept  = trim($_POST['department'] ?? '');
    $new_role  = strtolower(trim($_POST['role'] ?? ''));

    // Server-Side Strict Validation Checks
    if (empty($first_name) || empty($last_name) || empty($new_user) || empty($new_pass) || empty($new_role)) {
        $error = "Please fill out all required fields!";
    // 1. Full Name: Alphabet Only
    } elseif (!preg_match("/^[a-zA-Z\s]+$/", $first_name) || !preg_match("/^[a-zA-Z\s]+$/", $last_name) || (!empty($middle_name) && !preg_match("/^[a-zA-Z\s]+$/", $middle_name))) {
        $error = "First Name, Middle Name, and Last Name must contain alphabetic characters (letters) only!";
    // 2. Username: Alphanumeric OR Alphabet only
    } elseif (!preg_match("/^[a-zA-Z0-9]+$/", $new_user)) {
        $error = "Username must contain letters or alphanumeric characters only without special characters!";
    // 3. Password: 8+ length, Letter + Number + Special Char
    } elseif (strlen($new_pass) < 8 || !preg_match("/[a-zA-Z]/", $new_pass) || !preg_match("/\d/", $new_pass) || !preg_match("/[^a-zA-Z0-9]/", $new_pass)) {
        $error = "Password must be at least 8 characters long and contain letters, numbers, and at least one special character!";
    // 4. Email: Must have valid email structure with @ and domain
    } elseif (!empty($new_email) && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid Email Address containing '@' and a domain (e.g., user@example.com)!";
    // 5. Phone: Starts with 09 or 07 and exactly 10 digits
    } elseif (!empty($new_phone) && !preg_match("/^(09|07)\d{8}$/", $new_phone)) {
        $error = "Phone number must be exactly 10 digits starting with 09 or 07!";
    } elseif (!in_array($new_role, ['admin', 'staff', 'manager'])) {
        $error = "Invalid Role selected! Must be Admin, Staff, or Manager.";
    } else {
        // 🛑 SECURITY CHECK: አዲስ Admin እንዳይመዘገብ መከላከል
        if ($new_role === 'admin') {
            $check_admin = $conn->query("SELECT COUNT(*) AS total FROM users WHERE LOWER(TRIM(role)) = 'admin'");
            if ($check_admin && $row = $check_admin->fetch_assoc()) {
                if ($row['total'] >= 1) {
                    $error = "Error: A system Admin already exists! Only one Admin is allowed.";
                }
            }
        }

        if (empty($error)) {
            $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);
            
            $stmt = $conn->prepare("INSERT INTO users (first_name, middle_name, last_name, username, password, role, department, email, phone_number, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $stmt->bind_param("sssssssss", $first_name, $middle_name, $last_name, $new_user, $hashed_pass, $new_role, $new_dept, $new_email, $new_phone);

            if ($stmt->execute()) {
                $created_user_id = $conn->insert_id;
                $message = "User registered successfully!";

                $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, 'USER_REGISTER', ?)");
                if ($log_stmt) {
                    $details = "Registered new user: $new_user (ID: $created_user_id, Role: $new_role)";
                    $log_stmt->bind_param("is", $admin_id, $details);
                    $log_stmt->execute();
                }
            } else {
                $error = "User not registered (Username might already exist): " . $conn->error;
            }
        }
    }
}

// F. Edit User Logic with Role Protection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_user') {
    $target_id   = intval($_POST['user_id']);
    $first_name  = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $edit_user   = trim($_POST['username'] ?? '');
    $edit_role   = strtolower(trim($_POST['role'] ?? ''));
    $edit_dept   = trim($_POST['department'] ?? '');

    if (!empty($target_id) && !empty($first_name) && !empty($last_name) && !empty($edit_user)) {
        if ($target_id === $admin_id && $edit_role !== 'admin') {
            $error = "You cannot change your own admin role!";
        } else {
            // 🛑 SECURITY CHECK: Role ወደ Admin እንዳይቀየር መከላከል
            if ($edit_role === 'admin') {
                $check_admin = $conn->prepare("SELECT COUNT(*) AS total FROM users WHERE LOWER(TRIM(role)) = 'admin' AND id != ?");
                $check_admin->bind_param("i", $target_id);
                $check_admin->execute();
                $admin_count = $check_admin->get_result()->fetch_assoc()['total'];
                $check_admin->close();

                if ($admin_count >= 1) {
                    $error = "Error: Another Admin already exists! You cannot assign the Admin role to this user.";
                }
            }

            if (empty($error)) {
                $stmt = $conn->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, username = ?, role = ?, department = ? WHERE id = ?");
                $stmt->bind_param("ssssssi", $first_name, $middle_name, $last_name, $edit_user, $edit_role, $edit_dept, $target_id);

                if ($stmt->execute()) {
                    $message = "User details updated successfully!";
                    $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, 'USER_UPDATE', ?)");
                    if ($log_stmt) {
                        $details = "Updated user details for ID: $target_id ($edit_user)";
                        $log_stmt->bind_param("is", $admin_id, $details);
                        $log_stmt->execute();
                    }
                } else {
                    $error = "Error updating user: " . $conn->error;
                }
            }
        }
    }
}

// G. Toggle User Status (Activate / Deactivate) with Admin Protection
if (isset($_GET['action']) && $_GET['action'] === 'toggle_user_status' && isset($_GET['user_id'])) {
    $target_id = intval($_GET['user_id']);
    
    // 🛑 SECURITY CHECK: የየትኛውም Admin አካውንት Deactivate እንዳይሆን መከላከል
    $check_user = $conn->prepare("SELECT role, status FROM users WHERE id = ?");
    $check_user->bind_param("i", $target_id);
    $check_user->execute();
    $u_row = $check_user->get_result()->fetch_assoc();
    $check_user->close();

    if ($u_row) {
        $target_role = strtolower(trim($u_row['role']));
        $current_st  = strtolower(trim($u_row['status']));

        if ($target_role === 'admin' && $current_st === 'active') {
            $error = "Error: Admin account cannot be deactivated!";
        } else {
            $new_st = ($current_st === 'active') ? 'deactive' : 'active';

            $up_stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
            $up_stmt->bind_param("si", $new_st, $target_id);

            if ($up_stmt->execute()) {
                $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, 'ACCOUNT_STATUS_TOGGLE', ?)");
                if ($log_stmt) {
                    $details = "Changed account status of User ID: $target_id to $new_st";
                    $log_stmt->bind_param("is", $admin_id, $details);
                    $log_stmt->execute();
                }
                header("Location: admin_dashboard.php?msg=status_updated&tab=users_tab");
                exit();
            } else {
                $error = "Could not update status: " . $conn->error;
            }
        }
    } else {
        $error = "User record not found!";
    }
}

// H. Password Reset Logic
if (isset($_POST['btn_reset_password'])) {
    $target_user_id = intval($_POST['user_id']);
    $new_password   = $_POST['new_password'];

    if (!empty($target_user_id) && !empty($new_password)) {
        if (strlen($new_password) < 8 || !preg_match("/[a-zA-Z]/", $new_password) || !preg_match("/\d/", $new_password) || !preg_match("/[^a-zA-Z0-9]/", $new_password)) {
            $error = "New Password must be at least 8 characters long and contain letters, numbers, and at least one special character!";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed_password, $target_user_id);

            if ($stmt->execute()) {
                $message = "User password changed successfully!";

                $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, 'PASSWORD_RESET', ?)");
                if ($log_stmt) {
                    $details = "Admin reset password for User ID: " . $target_user_id;
                    $log_stmt->bind_param("is", $admin_id, $details);
                    $log_stmt->execute();
                }
            } else {
                $error = "Could not reset password: " . $conn->error;
            }
        }
    }
}

// I. Approve Incoming Transfer Action (UPDATED: Also updates file registry status to active)
if (isset($_GET['action']) && $_GET['action'] === 'approve_transfer' && isset($_GET['transfer_id'])) {
    $transfer_id = intval($_GET['transfer_id']);

    $tr_stmt = $conn->prepare("SELECT ft.*, f.file_title, f.ref_number, f.file_path FROM file_transfers ft LEFT JOIN files_registry f ON ft.file_id = f.id WHERE ft.id = ? AND ft.receiver_id = ?");
    $tr_stmt->bind_param("ii", $transfer_id, $admin_id);
    $tr_stmt->execute();
    $tr_res = $tr_stmt->get_result();

    if ($tr_data = $tr_res->fetch_assoc()) {
        $file_id = $tr_data['file_id'];
        
        // 1. Update transfer status
        $up_tr = $conn->prepare("UPDATE file_transfers SET status = 'received' WHERE id = ?");
        $up_tr->bind_param("i", $transfer_id);
        
        if ($up_tr->execute()) {
            // 2. Update file registry status to active
            $up_file = $conn->prepare("UPDATE files_registry SET status = 'active' WHERE id = ?");
            if ($up_file) {
                $up_file->bind_param("i", $file_id);
                $up_file->execute();
            }

            // 3. Write Audit Log
            $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, 'APPROVE_TRANSFER', ?)");
            if ($log_stmt) {
                $details = "Approved and received incoming transfer ID: $transfer_id for File ID: $file_id";
                $log_stmt->bind_param("is", $admin_id, $details);
                $log_stmt->execute();
            }
            header("Location: admin_dashboard.php?msg=approved&tab=incoming_tab");
            exit();
        } else {
            $error = "Could not approve transfer: " . $conn->error;
        }
    } else {
        $error = "Transfer record not found or access denied.";
    }
}

// J. Trash Bin Actions (Restore / Permanent Delete)
if (isset($_GET['action']) && isset($_GET['file_id'])) {
    $action  = $_GET['action'];
    $file_id = intval($_GET['file_id']);

    if ($action === 'restore') {
        $stmt = $conn->prepare("UPDATE files_registry SET is_deleted = 0 WHERE id = ?");
        $stmt->bind_param("i", $file_id);

        if ($stmt->execute()) {
            $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, 'RESTORE_FILE', ?)");
            if ($log_stmt) {
                $details = "Restored File ID: $file_id from Trash Bin";
                $log_stmt->bind_param("is", $admin_id, $details);
                $log_stmt->execute();
            }

            header("Location: admin_dashboard.php?msg=restored&tab=trash_tab");
            exit();
        }
    }

    if ($action === 'permanent_delete') {
        $get_file = $conn->prepare("SELECT file_path FROM files_registry WHERE id = ?");
        $get_file->bind_param("i", $file_id);
        $get_file->execute();
        $res = $get_file->get_result();

        if ($row = $res->fetch_assoc()) {
            if (!empty($row['file_path']) && file_exists($row['file_path'])) {
                unlink($row['file_path']);
            }
        }

        $stmt = $conn->prepare("DELETE FROM files_registry WHERE id = ?");
        $stmt->bind_param("i", $file_id);

        if ($stmt->execute()) {
            $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, 'PERMANENT_DELETE', ?)");
            if ($log_stmt) {
                $details = "Permanently deleted File ID: $file_id";
                $log_stmt->bind_param("is", $admin_id, $details);
                $log_stmt->execute();
            }

            header("Location: admin_dashboard.php?msg=permanently_deleted&tab=trash_tab");
            exit();
        }
    }
}

// -------------------------------------------------------------
// 2. STATS & DATA FETCHING
// -------------------------------------------------------------
$total_files = 0; $total_users = 0; $total_transfers = 0; $total_deleted = 0; $total_sent = 0; $total_archived = 0;

$res1 = $conn->query("SELECT COUNT(DISTINCT f.id) as count FROM files_registry f LEFT JOIN file_transfers ft ON f.id = ft.file_id WHERE f.is_deleted = 0 AND f.is_archived = 0 AND (f.uploaded_by = $admin_id OR (ft.receiver_id = $admin_id AND ft.status = 'received'))");
if ($res1 && $row = $res1->fetch_assoc()) { $total_files = $row['count']; }

$res2 = $conn->query("SELECT COUNT(*) as count FROM users");
if ($res2 && $row = $res2->fetch_assoc()) { $total_users = $row['count']; }

$res3 = $conn->query("SELECT COUNT(*) as count FROM file_transfers ft LEFT JOIN files_registry f ON ft.file_id = f.id WHERE ft.receiver_id = $admin_id AND f.is_deleted = 0");
if ($res3 && $row = $res3->fetch_assoc()) { $total_transfers = $row['count']; }

$res4 = $conn->query("SELECT COUNT(*) as count FROM files_registry WHERE is_deleted = 1");
if ($res4 && $row = $res4->fetch_assoc()) { $total_deleted = $row['count']; }

$res5 = $conn->query("SELECT COUNT(*) as count FROM files_registry WHERE is_archived = 1 AND is_deleted = 0");
if ($res5 && $row = $res5->fetch_assoc()) { $total_archived = $row['count']; }

$sent_count_query = "SELECT COUNT(*) as total FROM file_transfers WHERE sender_id = '$admin_id'";
$sent_count_result = $conn->query($sent_count_query);
if ($sent_count_result && $row = $sent_count_result->fetch_assoc()) { $total_sent = $row['total']; }

// Search & Filter Logic for Active Files
$search      = isset($_GET['search']) ? trim($_GET['search']) : '';
$cat_filter  = isset($_GET['category']) ? $_GET['category'] : '';
$year_filter = isset($_GET['year']) ? $_GET['year'] : '';

$where_clauses = ["f.is_deleted = 0", "f.is_archived = 0", "(f.uploaded_by = $admin_id OR (ft.receiver_id = $admin_id AND ft.status = 'received'))"];
if (!empty($search)) {
    $safe_search = $conn->real_escape_string($search);
    $where_clauses[] = "(f.file_title LIKE '%$safe_search%' OR f.ref_number LIKE '%$safe_search%')";
}
if (!empty($cat_filter)) { $where_clauses[] = "c.category_name LIKE '%" . $conn->real_escape_string($cat_filter) . "%'"; }
if (!empty($year_filter)) { $where_clauses[] = "f.eth_year = " . intval($year_filter); }

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// Database Queries (UPDATED with Safe Name Concatenation to Prevent Missing Column Errors)
$files_result     = $conn->query("SELECT DISTINCT f.*, c.category_name FROM files_registry f LEFT JOIN file_categories c ON f.category_id = c.id LEFT JOIN file_transfers ft ON f.id = ft.file_id $where_sql ORDER BY f.id DESC");
$archived_result  = $conn->query("SELECT f.*, c.category_name FROM files_registry f LEFT JOIN file_categories c ON f.category_id = c.id WHERE f.is_archived = 1 AND f.is_deleted = 0 ORDER BY f.id DESC");
$users_result     = $conn->query("SELECT *, COALESCE(TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(middle_name, ''), ' ', COALESCE(last_name, ''))), username) AS full_name FROM users ORDER BY id DESC");
$trash_result     = $conn->query("SELECT f.*, c.category_name FROM files_registry f LEFT JOIN file_categories c ON f.category_id = c.id WHERE f.is_deleted = 1 ORDER BY f.id DESC");
$transfers_result = $conn->query("SELECT ft.*, ft.id AS transfer_id, ft.status AS transfer_status, ft.created_at AS transfer_created_at, f.id AS file_id, f.file_title, f.ref_number, f.file_path, COALESCE(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, ''))), u.username, 'Unknown') as sender_name FROM file_transfers ft LEFT JOIN files_registry f ON ft.file_id = f.id LEFT JOIN users u ON ft.sender_id = u.id WHERE ft.receiver_id = $admin_id AND f.is_deleted = 0 ORDER BY ft.id DESC");
$sent_result      = $conn->query("SELECT ft.*, ft.status AS transfer_status, ft.created_at AS transfer_created_at, f.id AS file_id, f.file_title, f.ref_number, f.file_path, COALESCE(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, ''))), u.username, 'Unknown') as receiver_name FROM file_transfers ft LEFT JOIN files_registry f ON ft.file_id = f.id LEFT JOIN users u ON ft.receiver_id = u.id WHERE ft.sender_id = $admin_id AND f.is_deleted = 0 ORDER BY ft.id DESC");
$logsResult       = $conn->query("SELECT al.*, u.username, COALESCE(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, ''))), u.username) as full_name, u.role, u.department FROM audit_logs al LEFT JOIN users u ON al.user_id = u.id ORDER BY al.id DESC LIMIT 100");
$incoming_files_res = $transfers_result;
$sent_files_res     = $sent_result;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Office Management System</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.min.css">  
    <style>
        body { background-color: #f4f6f9; }
        .stat-card { border: none; border-radius: 10px; transition: transform 0.2s; background-color: #6c757d !important; }
        .stat-card:hover { transform: translateY(-3px); }
        .table-card { border: none; border-radius: 10px; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); }
        .badge-ref { font-family: monospace; font-size: 0.9em; }
        .nav-pills .nav-link.active { font-weight: bold; background-color: #0d6efd; color: white; }
        .btn-icon-only { padding: 0.25rem 0.5rem; font-size: 0.875rem; border-radius: 6px; }

        .register-form-container {
            font-family: system-ui, -apple-system, sans-serif;
            color: #495057;
        }
        .register-form-container label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.4rem;
        }
        .btn-register-submit {
            background-color: #198754;
            color: white;
            font-weight: 600;
            padding: 0.6rem;
        }
        .btn-register-submit:hover {
            background-color: #146c43;
            color: white;
        }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold text-warning" href="admin_dashboard.php">
            <i class="bi bi-shield-lock-fill me-2"></i>System Admin Console
        </a>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item me-3 text-white">
                    <i class="bi bi-person-circle me-1"></i>
                    <strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin'); ?></strong> 
                    <span class="badge bg-warning text-dark ms-1">Admin</span>
                </li>
                <li class="nav-item">
                    <a class="btn btn-outline-light btn-sm" href="logout.php"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-4">

    <!-- System Notifications -->
    <?php if(!empty($message) || isset($_GET['msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php 
                if (!empty($message)) {
                    echo htmlspecialchars($message);
                } else {
                    $msg_type = $_GET['msg'] ?? '';
                    if ($msg_type === 'trashed') echo "File successfully moved to Trash Bin!";
                    elseif ($msg_type === 'archived') echo "File successfully moved to Archive Registry!";
                    elseif ($msg_type === 'unarchived') echo "File restored back to Active Files list!";
                    elseif ($msg_type === 'restored') echo "File restored successfully from Trash Bin!";
                    elseif ($msg_type === 'permanently_deleted') echo "File permanently deleted!";
                    elseif ($msg_type === 'approved') echo "Incoming file approved and received!";
                    elseif ($msg_type === 'status_updated') echo "User account status updated!";
                    elseif ($msg_type === 'log_deleted') echo "Audit log deleted!";
                    else echo "The operation finished successfully!";
                }
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if(!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Top Action Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0">SYSTEM ADMIN DASHBOARD</h3>
            <p class="text-muted small mb-0">User Management, Archive Control, and System Administration</p>
        </div>
        <div>
            <button class="btn btn-success me-2 fw-semibold px-3 py-2" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="bi bi-person-plus-fill me-1"></i>Add New User</button>
            <a href="upload.php" class="btn btn-primary me-2 fw-semibold px-3 py-2"><i class="bi bi-cloud-arrow-up-fill me-1"></i>Upload New File</a>
            <a href="transfer.php" class="btn btn-outline-secondary fw-semibold px-3 py-2"><i class="bi bi-arrow-left-right me-1"></i>File Transfer</a>
        </div>
    </div>

    <!-- Main Layout -->
    <div class="row g-4">
        <!-- Left Sidebar Navigation -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 bg-white rounded-3">
                <div class="nav flex-column nav-pills gap-1" id="adminTab" role="tablist">
                    <button class="nav-link active text-start" id="dashboard-tab" data-bs-toggle="pill" data-bs-target="#dashboard-sec" type="button" role="tab">
                        <i class="bi bi-speedometer2 me-2"></i>Dashboard
                    </button>
                    <button class="nav-link text-start" id="files-tab" data-bs-toggle="pill" data-bs-target="#files-sec" type="button" role="tab">
                        <i class="bi bi-folder2-open me-2"></i>Files List
                    </button>
                    <button class="nav-link text-start" id="archive-tab" data-bs-toggle="pill" data-bs-target="#archive-sec" type="button" role="tab">
                        <i class="bi bi-archive-fill me-2"></i>Archived Files
                    </button>
                    <button class="nav-link text-start" id="transfers-tab" data-bs-toggle="pill" data-bs-target="#transfers-sec" type="button" role="tab">
                        <i class="bi bi-inbox-fill me-2"></i>Incoming Files
                    </button>
                    <button class="nav-link text-start" id="sent-tab" data-bs-toggle="pill" data-bs-target="#sent-sec" type="button" role="tab">
                        <i class="bi bi-send-fill me-2"></i>Sent Files
                    </button>
                    <button class="nav-link text-start" id="users-tab" data-bs-toggle="pill" data-bs-target="#users-sec" type="button" role="tab">
                        <i class="bi bi-people me-2"></i>User Management
                    </button>
                    <button class="nav-link text-start" id="trash-tab" data-bs-toggle="pill" data-bs-target="#trash-sec" type="button" role="tab">
                        <i class="bi bi-trash me-2"></i>Trash Bin
                    </button>
                    <button class="nav-link text-start" id="logs-tab" data-bs-toggle="pill" data-bs-target="#logs-sec" type="button" role="tab">
                        <i class="bi bi-journal-text me-2"></i>Audit Logs
                    </button>
                </div>
            </div>
        </div>

<!-- Right Content Section -->
<div class="col-md-9">
    <div class="tab-content" id="adminTabContent">

        <!-- Tab 0: Dashboard Summary Cards -->
        <div class="tab-pane fade show active" id="dashboard-sec" role="tabpanel">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="card text-white p-3 shadow-sm border-0 rounded-3 stat-card" style="cursor: pointer;" onclick="switchTab('files-tab')">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-uppercase small fw-bold opacity-75">Active Files</span>
                                <h2 class="fw-bold mb-0 mt-1"><?php echo $total_files; ?></h2>
                            </div>
                            <i class="bi bi-folder2-open fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card text-white p-3 shadow-sm border-0 rounded-3 stat-card" style="cursor: pointer;" onclick="switchTab('archive-tab')">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-uppercase small fw-bold opacity-75">Archived Files</span>
                                <h2 class="fw-bold mb-0 mt-1"><?php echo $total_archived; ?></h2>
                            </div>
                            <i class="bi bi-archive-fill fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card text-white p-3 shadow-sm border-0 rounded-3 stat-card" style="cursor: pointer;" onclick="switchTab('users-tab')">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-uppercase small fw-bold opacity-75">Users</span>
                                <h2 class="fw-bold mb-0 mt-1"><?php echo $total_users; ?></h2>
                            </div>
                            <i class="bi bi-people-fill fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card text-white p-3 shadow-sm border-0 rounded-3 stat-card" style="cursor: pointer;" onclick="switchTab('transfers-tab')">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-uppercase small fw-bold opacity-75">Incoming Files</span>
                                <h2 class="fw-bold mb-0 mt-1"><?php echo $total_transfers; ?></h2>
                            </div>
                            <i class="bi bi-inbox-fill fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card text-white p-3 shadow-sm border-0 rounded-3 stat-card" style="cursor: pointer;" onclick="switchTab('sent-tab')">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-uppercase small fw-bold opacity-75">Sent Files</span>
                                <h2 class="fw-bold mb-0 mt-1"><?php echo $total_sent; ?></h2>
                            </div>
                            <i class="bi bi-send-fill fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card text-white p-3 shadow-sm border-0 rounded-3 stat-card" style="cursor: pointer;" onclick="switchTab('trash-tab')">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-uppercase small fw-bold opacity-75">Trash Bin</span>
                                <h2 class="fw-bold mb-0 mt-1"><?php echo $total_deleted; ?></h2>
                            </div>
                            <i class="bi bi-trash-fill fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

 <!-- Tab 1: Active Files Section -->
<div class="tab-pane fade" id="files-sec" role="tabpanel">
    <div class="card table-card bg-white p-3 mb-3">
        <form method="GET" action="admin_dashboard.php" class="row g-2 align-items-center">
            <div class="col-md-5">
                <input type="text" name="search" class="form-control" placeholder="Search by title or reference number..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
                <input type="text" name="category" class="form-control" placeholder="Category" value="<?php echo htmlspecialchars($cat_filter); ?>">
            </div>
            <div class="col-md-2">
            
                <input type="number" name="year" class="form-control" placeholder="Year (EC)" min="2000" step="1" value="<?php echo htmlspecialchars($year_filter); ?>">
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
                <a href="admin_dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </form>
    </div>

    <div class="card table-card bg-white p-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Ref No/ቁጥር</th>
                        <th>File Title/ጉዳዩ</th>
                        <th>Category</th>
                        <th>Year</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($files_result && $files_result->num_rows > 0): ?>
                        <?php while($file = $files_result->fetch_assoc()): ?>
                            <tr>
                                <td><span class="badge bg-secondary badge-ref"><?php echo htmlspecialchars($file['ref_number']); ?></span></td>
                                <td><strong><?php echo htmlspecialchars($file['file_title']); ?></strong></td>
                                <td><?php echo htmlspecialchars($file['category_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($file['eth_year'] ?? 'N/A'); ?></td>
                                <td>
                                    <!-- View Action -->
                                    <a href="<?php echo htmlspecialchars($file['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary btn-icon-only" title="View"><i class="bi bi-eye"></i></a>
                                    
                                    <!-- Download Action -->
                                    <a href="<?php echo htmlspecialchars($file['file_path']); ?>" download class="btn btn-sm btn-outline-info btn-icon-only" title="Download"><i class="bi bi-download"></i></a>
                                    
                                    <!-- Archive Action -->
                                    <a href="admin_dashboard.php?action=archive_file&file_id=<?php echo $file['id']; ?>" class="btn btn-sm btn-outline-warning btn-icon-only" title="Archive"><i class="bi bi-archive"></i></a>
                                    
                                    <!-- Move to Trash Action -->
                                    <a href="admin_dashboard.php?action=soft_delete&file_id=<?php echo $file['id']; ?>" class="btn btn-sm btn-outline-danger btn-icon-only" title="Move to Trash" onclick="return confirm('Move file to trash bin?');"><i class="bi bi-trash"></i></a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-muted">No active files found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

       <!-- Tab 2: Archived Files Section -->
<div class="tab-pane fade" id="archive-sec" role="tabpanel">
    <div class="card table-card bg-white p-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Ref No</th>
                        <th>File Title</th>
                        <th>Category</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($archived_result && $archived_result->num_rows > 0): ?>
                        <?php while($arc = $archived_result->fetch_assoc()): ?>
                            <tr>
                                <td><span class="badge bg-secondary badge-ref"><?php echo htmlspecialchars($arc['ref_number']); ?></span></td>
                                <td><?php echo htmlspecialchars($arc['file_title']); ?></td>
                                <td><?php echo htmlspecialchars($arc['category_name'] ?? 'N/A'); ?></td>
                                <td class="d-flex gap-1 align-items-center">
                                    <!-- View File Action -->
                                    <?php if (!empty($arc['file_path'])): ?>
                                        <a href="<?php echo htmlspecialchars($arc['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary btn-icon-only" title="View File">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    <?php endif; ?>

                                    <!-- Unarchive / Restore Action -->
                                    <a href="admin_dashboard.php?action=unarchive_file&file_id=<?php echo $arc['id']; ?>" class="btn btn-sm btn-outline-success" title="Restore to Active">
                                        <i class="bi bi-arrow-counterclockwise"></i> Unarchive
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="text-center text-muted">No archived files.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
 <!-- Tab 3: Incoming Files Section -->
<div class="tab-pane fade" id="transfers-sec" role="tabpanel">
    <div class="card p-3 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">
                Incoming Files
                <?php 
                // ያልታዩ / Approve ያልተደረጉ (Pending) ፋይሎችን ቁጥር መቁጠሪያ
                $pending_count = 0;
                if ($transfers_result && $transfers_result->num_rows > 0) {
                    $transfers_result->data_seek(0); // pointer reset
                    while ($count_row = $transfers_result->fetch_assoc()) {
                        if (strtolower($count_row['transfer_status'] ?? '') === 'pending') {
                            $pending_count++;
                        }
                    }
                    $transfers_result->data_seek(0); // pointer reset back
                }
                ?>
                <?php if ($pending_count > 0): ?>
                    <span class="badge bg-danger rounded-pill ms-2" title="New Pending Files">
                        <?php echo $pending_count; ?> New
                    </span>
                <?php endif; ?>
            </h5>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>File Title</th>
                        <th>Sender</th>
                        <th>Date Sent</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($transfers_result && $transfers_result->num_rows > 0): ?>
                        <?php while ($row = $transfers_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['file_title']); ?></td>
                                <td><?php echo htmlspecialchars(!empty($row['sender_name']) ? $row['sender_name'] : 'Unknown'); ?></td>
                                <td><?php echo htmlspecialchars($row['transfer_created_at'] ?? ''); ?></td>
                                <td>
                                    <?php if (strtolower($row['transfer_status'] ?? '') === 'received'): ?>
                                        <span class="badge bg-success">Received</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td class="d-flex gap-1 align-items-center">
                                    <!-- Approve / Receive Button -->
                                    <?php if (strtolower($row['transfer_status'] ?? '') === 'pending'): ?>
                                        <a href="admin_dashboard.php?action=approve_transfer&transfer_id=<?php echo $row['transfer_id']; ?>" class="btn btn-sm btn-secondary">Approve / Receive</a>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-success" disabled>Completed</button>
                                    <?php endif; ?>

                                    <!-- View File Action -->
                                    <?php if (!empty($row['file_path'])): ?>
                                        <a href="<?php echo htmlspecialchars($row['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary btn-icon-only" title="View File">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    <?php endif; ?>

                                    <!-- Move to Trash Action -->
                                    <a href="admin_dashboard.php?action=soft_delete&file_id=<?php echo $row['file_id'] ?? $row['id']; ?>" class="btn btn-sm btn-outline-danger btn-icon-only" title="Move to Trash" onclick="return confirm('Move file to trash bin?');">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-muted">No incoming files found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<!-- Tab 4: Sent Files Section -->
<div class="tab-pane fade" id="sent-sec" role="tabpanel">
    <div class="card p-3">
        <h5>Sent Files</h5>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>File Title</th>
                        <th>Receiver</th>
                        <th>Date Sent</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sent_result && $sent_result->num_rows > 0): ?>
                        <?php while ($s = $sent_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($s['file_title']); ?></td>
                                <td><?php echo htmlspecialchars(!empty(trim($s['receiver_name'])) ? $s['receiver_name'] : 'N/A / Deleted User'); ?></td>
                                <td><?php echo htmlspecialchars($s['transfer_created_at'] ?? $s['created_at'] ?? ''); ?></td>
                                <td>
                                    <?php if (strtolower($s['transfer_status'] ?? '') === 'received'): ?>
                                        <span class="badge bg-info text-dark">Received</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($s['file_path'])): ?>
                                        <a href="<?php echo htmlspecialchars($s['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary btn-icon-only" title="View File">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    <?php endif; ?>
                                    <a href="admin_dashboard.php?action=soft_delete&file_id=<?php echo $s['file_id'] ?? $s['id']; ?>" class="btn btn-sm btn-outline-danger btn-icon-only" title="Move to Trash" onclick="return confirm('Move file to trash bin?');">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-muted">No sent files found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<!-- Tab 5: User Management Section -->
<div class="tab-pane fade" id="users-sec" role="tabpanel">
    <div class="card table-card bg-white p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0"><i class="bi bi-people-fill me-2 text-dark"></i>Registered Accounts</h5>
            <button class="btn btn-sm btn-success fw-semibold" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="bi bi-person-plus-fill me-1"></i>Add User</button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>First Name</th>
                        <th>Middle Name</th>
                        <th>Last Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Department</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($users_result && $users_result->num_rows > 0): ?>
                        <?php while($usr = $users_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($usr['first_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($usr['middle_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($usr['last_name'] ?? ''); ?></td>
                                <td><code><?php echo htmlspecialchars($usr['username'] ?? ''); ?></code></td>
                                <td><span class="badge bg-info text-dark"><?php echo ucfirst(htmlspecialchars($usr['role'] ?? '')); ?></span></td>
                                <td><?php echo htmlspecialchars($usr['department'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if (isset($usr['status']) && strtolower($usr['status']) === 'active'): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Deactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <!-- Edit User Action -->
                                    <button class="btn btn-sm btn-outline-primary btn-icon-only" data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo $usr['id'] ?? ''; ?>" title="Edit User">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>

                                    <!-- Reset Password Action -->
                                    <button class="btn btn-sm btn-outline-warning btn-icon-only" data-bs-toggle="modal" data-bs-target="#resetPassModal<?php echo $usr['id'] ?? ''; ?>" title="Reset Password">
                                        <i class="bi bi-key"></i>
                                    </button>

                                    <!-- Toggle Status Action -->
                                    <?php if (isset($usr['id']) && $usr['id'] !== $admin_id): ?>
                                        <a href="admin_dashboard.php?action=toggle_user_status&user_id=<?php echo $usr['id']; ?>" class="btn btn-sm <?php echo (strtolower($usr['status'] ?? '') === 'active') ? 'btn-outline-danger' : 'btn-outline-success'; ?> btn-icon-only" title="Toggle Status">
                                            <i class="bi bi-power"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <!-- Edit User Modal -->
                            <div class="modal fade" id="editUserModal<?php echo $usr['id'] ?? ''; ?>" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <form method="POST" action="admin_dashboard.php">
                                            <div class="modal-header">
                                                <h5 class="modal-header-title fw-bold">Edit User Details</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="user_id" value="<?php echo $usr['id'] ?? ''; ?>">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">First Name</label>
                                                    <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($usr['first_name'] ?? ''); ?>" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Middle Name</label>
                                                    <input type="text" name="middle_name" class="form-control" value="<?php echo htmlspecialchars($usr['middle_name'] ?? ''); ?>">
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Last Name</label>
                                                    <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($usr['last_name'] ?? ''); ?>" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Role</label>
                                                    <select name="role" class="form-select" required>
                                                        <option value="user" <?php echo (strtolower($usr['role'] ?? '') === 'user') ? 'selected' : ''; ?>>User</option>
                                                        <option value="admin" <?php echo (strtolower($usr['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Admin</option>
                                                    </select>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Department</label>
                                                    <input type="text" name="department" class="form-control" value="<?php echo htmlspecialchars($usr['department'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" name="btn_edit_user" class="btn btn-primary btn-sm">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Password Reset Modal -->
                            <div class="modal fade" id="resetPassModal<?php echo $usr['id'] ?? ''; ?>" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <form method="POST" action="admin_dashboard.php">
                                            <div class="modal-header">
                                                <h5 class="modal-header-title fw-bold">Reset Password</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="user_id" value="<?php echo $usr['id'] ?? ''; ?>">
                                                <div class="mb-3">
                                                    <label class="form-label">New Password for <strong><?php echo htmlspecialchars($usr['username'] ?? ''); ?></strong></label>
                                                    <input type="password" name="new_password" class="form-control" required minlength="8" pattern="^(?=.*[a-zA-Z])(?=.*\d)(?=.*[^a-zA-Z0-9]).{8,}$" title="Min 8 chars, including letters, numbers, and special chars.">
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" name="btn_reset_password" class="btn btn-primary btn-sm">Save Password</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">No users found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

        <!-- Tab 6: Trash Bin Section -->
        <div class="tab-pane fade" id="trash-sec" role="tabpanel">
            <div class="card table-card bg-white p-3">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Ref No</th>
                                <th>File Title</th>
                                <th>Category</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($trash_result && $trash_result->num_rows > 0): ?>
                                <?php while($tr_item = $trash_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><span class="badge bg-secondary badge-ref"><?php echo htmlspecialchars($tr_item['ref_number']); ?></span></td>
                                        <td><?php echo htmlspecialchars($tr_item['file_title']); ?></td>
                                        <td><?php echo htmlspecialchars($tr_item['category_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <a href="admin_dashboard.php?action=restore&file_id=<?php echo $tr_item['id']; ?>" class="btn btn-sm btn-outline-success btn-icon-only"><i class="bi bi-arrow-counterclockwise"></i> Restore</a>
                                            <a href="admin_dashboard.php?action=permanent_delete&file_id=<?php echo $tr_item['id']; ?>" class="btn btn-sm btn-outline-danger btn-icon-only" onclick="return confirm('Permanently delete this file? This cannot be undone.');"><i class="bi bi-x-circle"></i> Delete Permanently</a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center text-muted">Trash bin is empty.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab 7: Audit Logs Section -->
        <div class="tab-pane fade" id="logs-sec" role="tabpanel">
            <div class="card table-card bg-white p-3">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Log ID</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>Timestamp</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($logsResult && $logsResult->num_rows > 0): ?>
                                <?php while($log = $logsResult->fetch_assoc()): ?>
                                    <tr>
                                        <td>#<?php echo $log['id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($log['username'] ?? 'System'); ?></strong></td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($log['action']); ?></span></td>
                                        <td><?php echo htmlspecialchars($log['details']); ?></td>
                                        <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                                        <td>
                                            <a href="admin_dashboard.php?action=delete_log&log_id=<?php echo $log['id']; ?>" class="btn btn-sm btn-outline-danger btn-icon-only" onclick="return confirm('Delete log entry?');"><i class="bi bi-trash"></i></a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted">No audit logs found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Modal: Add New User -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content register-form-container">
            <form method="POST" action="admin_dashboard.php" id="addUserForm" onsubmit="return validateForm(event)">
                <input type="hidden" name="action" value="add_user">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2"></i>Register New Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- Error Message Alert Box -->
                    <div id="validationErrorAlert" class="alert alert-danger d-none" role="alert"></div>

                    <div class="row g-3">
                        <!-- First Name: Letters Only -->
                        <div class="col-md-4">
                            <label>First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" id="first_name" class="form-control" required pattern="[a-zA-Z\s]+" title="Alphabet characters only" placeholder="First Name">
                        </div>
                        <!-- Middle Name: Letters Only -->
                        <div class="col-md-4">
                            <label>Middle Name</label>
                            <input type="text" name="middle_name" id="middle_name" class="form-control" pattern="[a-zA-Z\s]+" title="Alphabet characters only" placeholder="Middle Name">
                        </div>
                        <!-- Last Name: Letters Only -->
                        <div class="col-md-4">
                            <label>Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" id="last_name" class="form-control" required pattern="[a-zA-Z\s]+" title="Alphabet characters only" placeholder="Last Name">
                        </div>

                        <!-- Username: Alphanumeric or Letters only -->
                        <div class="col-md-6">
                            <label>Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" id="username" class="form-control" required pattern="[a-zA-Z0-9]+" title="Letters or Alphanumeric characters only" placeholder="Username (Letters/Alphanumeric)">
                        </div>

                        <!-- Password: 8+ Min Length, Letters + Numbers + Special Char -->
                        <div class="col-md-6">
                            <label>Password <span class="text-danger">*</span></label>
                            <input type="password" 
                                   id="user_password" 
                                   name="password" 
                                   class="form-control" 
                                   required 
                                   minlength="8" 
                                   pattern="^(?=.*[a-zA-Z])(?=.*\d)(?=.*[^a-zA-Z0-9]).{8,}$"
                                   title="Min 8 characters, including letters, numbers, and special characters."
                                   placeholder="Min 8 chars (letters, numbers & special char)">
                        </div>

                        <div class="col-md-6">
                            <label>Role <span class="text-danger">*</span></label>
                            <select name="role" class="form-select" required>
                                <option value="" selected disabled>Select Role</option>
                                <option value="admin">Admin</option>
                                <option value="manager">Manager</option>
                                <option value="staff">Staff</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label>Department</label>
                            <input type="text" name="department" class="form-control" placeholder="Department">
                        </div>

                        <!-- Email Address: Valid Email Format -->
                        <div class="col-md-6">
                            <label>Email Address</label>
                            <input type="email" name="email" id="email" class="form-control" placeholder="user@gmail.com">
                        </div>

                        <!-- Phone Number: Starts with 09 or 07, Exactly 10 digits -->
                        <div class="col-md-6">
                            <label>Phone Number</label>
                            <input type="text" name="phone_number" id="phone_number" class="form-control" pattern="^(09|07)\d{8}$" maxlength="10" title="Must start with 09 or 07 and be exactly 10 digits" placeholder="09... / 07...">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-register-submit px-4"><i class="bi bi-check-circle me-1"></i>Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    function switchTab(tabId) {
        var tabElement = document.getElementById(tabId);
        if (tabElement) {
            var tab = new bootstrap.Tab(tabElement);
            tab.show();
        }
    }

    // Client-Side Validation Function for All Fields
    function validateForm(event) {
        const fname = document.getElementById('first_name').value.trim();
        const mname = document.getElementById('middle_name').value.trim();
        const lname = document.getElementById('last_name').value.trim();
        const uname = document.getElementById('username').value.trim();
        const pass = document.getElementById('user_password').value;
        const email = document.getElementById('email').value.trim();
        const phone = document.getElementById('phone_number').value.trim();

        const errorAlert = document.getElementById('validationErrorAlert');
        let errors = [];

        // 1. Full Name Validation (Letters and Spaces only)
        const alphaOnly = /^[a-zA-Z\s]+$/;
        if (!alphaOnly.test(fname)) errors.push("First Name ፊደላት (Alphabetic characters) ብቻ መያዝ አለበት።");
        if (mname !== "" && !alphaOnly.test(mname)) errors.push("Middle Name ፊደላት (Alphabetic characters) ብቻ መያዝ አለበት።");
        if (!alphaOnly.test(lname)) errors.push("Last Name ፊደላት (Alphabetic characters) ብቻ መያዝ አለበት።");

        // 2. Username Validation (Alphanumeric or Alphabet only)
        const alphaNumeric = /^[a-zA-Z0-9]+$/;
        if (!alphaNumeric.test(uname)) errors.push("Username ፊደል ወይም ፊደልና ቁጥር (Alphanumeric) ብቻ መሆን አለበት።");

        // 3. Password Validation (8+ Chars, Letter, Number & Special Character)
        const hasLetter = /[a-zA-Z]/.test(pass);
        const hasNumber = /\d/.test(pass);
        const hasSpecialChar = /[^a-zA-Z0-9]/.test(pass);
        if (pass.length < 8 || !hasLetter || !hasNumber || !hasSpecialChar) {
            errors.push("Password ቢያንስ 8 character ሆኖ ፊደላት፣ ቁጥሮች እና ልዩ ምልክት (!@#$%^&*...) ማካተት አለበት።");
        }

        // 4. Email Validation (Includes @ and domain)
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (email !== "" && !emailRegex.test(email)) {
            errors.push("Email ትክክለኛ የኢሜይል አድራሻ አፃፃፍ ('@' እና domain) መከተል አለበት።");
        }

        // 5. Phone Validation (Starts with 09 or 07 and 10 digits)
        const phoneRegex = /^(09|07)\d{8}$/;
        if (phone !== "" && !phoneRegex.test(phone)) {
            errors.push("Phone Number በ 09 ወይም በ 07 የሚጀምር ልክ 10 ዲጂት መሆን አለበት።");
        }

        // Display Errors if any condition fails
        if (errors.length > 0) {
            event.preventDefault(); // Stop Form Submission
            let errorHtml = "<strong>Validation Failed (እባክዎ እነዚህን ያስተካክሉ):</strong><br><ul class='mb-0 ps-3'>";
            errors.forEach(err => { errorHtml += `<li>${err}</li>`; });
            errorHtml += "</ul>";

            errorAlert.innerHTML = errorHtml;
            errorAlert.classList.remove('d-none');
            return false;
        }

        errorAlert.classList.add('d-none');
        return true;
    }
</script>
</body>
</html>
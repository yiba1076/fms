<?php
session_start();
require_once 'db.php';

// Auth Check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if ($user_role !== 'manager') {
    session_destroy();
    header("Location: login.php?error=unauthorized");
    exit();
}

$manager_id = intval($_SESSION['user_id']);
$manager_dept = $_SESSION['department'] ?? '';
$message = $_SESSION['success_msg'] ?? "";
$error = $_SESSION['error_msg'] ?? "";
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Dynamic Column Check
$has_created_by = false;
$col_chk = $conn->query("SHOW COLUMNS FROM files_registry LIKE 'created_by'");
if ($col_chk && $col_chk->num_rows > 0) {
    $has_created_by = true;
}

// -------------------------------------------------------------
// ACTIONS (Approve, Delete, Archive, Restore/Unarchive, Upload, Transfer)
// -------------------------------------------------------------

// 1. APPROVE ACTION (Approve transfer -> File status = Active, Transfer status = Received)
if (isset($_GET['action']) && $_GET['action'] === 'approve' && isset($_GET['id'])) {
    $approve_id = intval($_GET['id']);
    
    $stmt = $conn->prepare("UPDATE files_registry SET status = 'Active' WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $approve_id);
        if ($stmt->execute()) {
            
            $tr_stmt = $conn->prepare("UPDATE file_transfers SET status = 'Received' WHERE file_id = ? AND receiver_id = ?");
            if ($tr_stmt) {
                $tr_stmt->bind_param("ii", $approve_id, $manager_id);
                $tr_stmt->execute();
                $tr_stmt->close();
            }

            $_SESSION['success_msg'] = "File approved! Marked as Received in Incoming & Active in File List.";
        } else {
            $_SESSION['error_msg'] = "Failed to approve file.";
        }
        $stmt->close();
    }
    header("Location: manager_dashboard.php");
    exit();
}

// 2. DELETE ACTION
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $del_id = intval($_GET['id']);
    $stmt = $conn->prepare("UPDATE files_registry SET is_deleted = 1 WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $del_id);
        if ($stmt->execute()) {
            $_SESSION['success_msg'] = "File moved to trash successfully!";
        } else {
            $_SESSION['error_msg'] = "Failed to delete file.";
        }
        $stmt->close();
    }
    header("Location: manager_dashboard.php");
    exit();
}

// 3. ARCHIVE ACTION
if (isset($_GET['action']) && $_GET['action'] === 'archive' && isset($_GET['id'])) {
    $arch_id = intval($_GET['id']);
    $stmt = $conn->prepare("UPDATE files_registry SET status = 'Archived' WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $arch_id);
        if ($stmt->execute()) {
            $_SESSION['success_msg'] = "File archived successfully!";
        } else {
            $_SESSION['error_msg'] = "Failed to archive file.";
        }
        $stmt->close();
    }
    header("Location: manager_dashboard.php");
    exit();
}

// 4. UNARCHIVE (RESTORE TO ACTIVE) ACTION
if (isset($_GET['action']) && $_GET['action'] === 'unarchive' && isset($_GET['id'])) {
    $unarch_id = intval($_GET['id']);
    $stmt = $conn->prepare("UPDATE files_registry SET status = 'Active' WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $unarch_id);
        if ($stmt->execute()) {
            $_SESSION['success_msg'] = "File restored to Active Files successfully!";
        } else {
            $_SESSION['error_msg'] = "Failed to restore file.";
        }
        $stmt->close();
    }
    header("Location: manager_dashboard.php");
    exit();
}

// 5. UPLOAD FILE ACTION (Uploaded files go directly to File List with status 'Active')
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_upload_file'])) {
    
    $ref_number     = trim($_POST['ref_number'] ?? '');
    $file_title     = trim($_POST['file_title'] ?? '');
    $category_input = trim($_POST['category_name'] ?? 'General');
    $eth_year       = intval($_POST['eth_year'] ?? 2018);
    $eth_month      = intval($_POST['eth_month'] ?? 1);
    $eth_day        = intval($_POST['eth_day'] ?? 1);

    // Category Handling
    $category_id = 1;
    if (!empty($category_input)) {
        $chk_stmt = $conn->prepare("SELECT id FROM file_categories WHERE LOWER(category_name) = LOWER(?)");
        if ($chk_stmt) {
            $chk_stmt->bind_param("s", $category_input);
            $chk_stmt->execute();
            $res = $chk_stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $category_id = $row['id'];
            } else {
                $ins_cat = $conn->prepare("INSERT INTO file_categories (category_name) VALUES (?)");
                if ($ins_cat) {
                    $ins_cat->bind_param("s", $category_input);
                    if ($ins_cat->execute()) {
                        $category_id = $ins_cat->insert_id;
                    }
                    $ins_cat->close();
                }
            }
            $chk_stmt->close();
        }
    }

    // File Upload
    if (!empty($file_title) && isset($_FILES['file_upload']) && $_FILES['file_upload']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_tmp      = $_FILES['file_upload']['tmp_name'];
        $original_name = basename($_FILES['file_upload']['name']);
        $ext           = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $allowed_exts  = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'];

        if (in_array($ext, $allowed_exts)) {
            $file_name   = time() . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "_", $original_name);
            $target_file = $upload_dir . $file_name;

            if (move_uploaded_file($file_tmp, $target_file)) {
                $active_status = 'Active';
                
                if ($has_created_by) {
                    $stmt = $conn->prepare("INSERT INTO files_registry (ref_number, file_title, category_id, file_path, eth_year, eth_month, eth_day, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param("ssisiiisi", $ref_number, $file_title, $category_id, $target_file, $eth_year, $eth_month, $eth_day, $active_status, $manager_id);
                    }
                } else {
                    $stmt = $conn->prepare("INSERT INTO files_registry (ref_number, file_title, category_id, file_path, eth_year, eth_month, eth_day, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param("ssisiiis", $ref_number, $file_title, $category_id, $target_file, $eth_year, $eth_month, $eth_day, $active_status);
                    }
                }

                if ($stmt) {
                    if ($stmt->execute()) {
                        $_SESSION['success_msg'] = "File uploaded successfully and added to Active Files List!";
                    } else {
                        $_SESSION['error_msg'] = "Database insertion failed: " . $stmt->error;
                    }
                    $stmt->close();
                }
            } else {
                $_SESSION['error_msg'] = "Failed to move uploaded file.";
            }
        } else {
            $_SESSION['error_msg'] = "Invalid file type.";
        }
    } else {
        $_SESSION['error_msg'] = "Upload failed.";
    }

    header("Location: manager_dashboard.php");
    exit();
}

// 6. TRANSFER FILE ACTION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_transfer'])) {
    $file_id     = intval($_POST['file_id'] ?? 0);
    $receiver_id = intval($_POST['receiver_id'] ?? 0);
    $remarks     = trim($_POST['remarks'] ?? '');

    if ($file_id > 0 && $receiver_id > 0) {
        $u_stmt = $conn->prepare("UPDATE files_registry SET status = 'Pending' WHERE id = ?");
        if ($u_stmt) {
            $u_stmt->bind_param("i", $file_id);
            $u_stmt->execute();
            $u_stmt->close();
        }

        $stmt = $conn->prepare("INSERT INTO file_transfers (file_id, sender_id, receiver_id, notes, status) VALUES (?, ?, ?, ?, 'Pending')");
        if ($stmt) {
            $stmt->bind_param("iiis", $file_id, $manager_id, $receiver_id, $remarks);

            if ($stmt->execute()) {
                $_SESSION['success_msg'] = "File transferred! Sent to Receiver's Incoming Files as Pending.";
            } else {
                $_SESSION['error_msg'] = "Transfer record failed: " . $stmt->error;
            }
            $stmt->close();
        }
    } else {
        $_SESSION['error_msg'] = "Please select a valid file and receiver!";
    }

    header("Location: manager_dashboard.php");
    exit();
}

// 7. TRASH/DELETE TRANSFER LOG ACTION
if (isset($_GET['action']) && $_GET['action'] === 'delete_transfer' && isset($_GET['id'])) {
    $transfer_id = intval($_GET['id']);

    if ($transfer_id > 0) {
        $stmt = $conn->prepare("DELETE FROM file_transfers WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $transfer_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    header("Location: manager_dashboard.php#transfers-sec");
    exit();
}


// -------------------------------------------------------------
// -------------------------------------------------------------
// 1. FILTERED QUERIES (በቅድሚያ ኩዌሪዎቹ በሙሉ ይሰራሉ)
// -------------------------------------------------------------

// Incoming Files Query
$incoming_files_query = "SELECT DISTINCT f.*, ft.status AS transfer_status,
                                COALESCE(c.category_name, 'General') AS category_name, 
                                COALESCE(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, ''))), 'Unknown Sender') AS sender_name 
                         FROM files_registry f 
                         INNER JOIN file_transfers ft ON f.id = ft.file_id 
                         LEFT JOIN file_categories c ON f.category_id = c.id 
                         LEFT JOIN users u ON ft.sender_id = u.id 
                         WHERE f.is_deleted = 0 
                           AND ft.receiver_id = $manager_id
                           AND ft.sender_id != $manager_id
                         ORDER BY f.id DESC";
$incoming_files_res = $conn->query($incoming_files_query);

// Active File List Query
$created_by_condition = $has_created_by ? " OR f.created_by = $manager_id" : "";
$all_files_query = "SELECT DISTINCT f.*, 
                           COALESCE(c.category_name, 'General') AS category_name 
                    FROM files_registry f 
                    LEFT JOIN file_categories c ON f.category_id = c.id 
                    WHERE f.is_deleted = 0 
                      AND LOWER(f.status) = 'active'
                      AND (
                          f.id IN (
                              SELECT file_id FROM file_transfers 
                              WHERE receiver_id = $manager_id OR sender_id = $manager_id
                          )
                          $created_by_condition
                      )
                    ORDER BY f.id DESC";
$all_files_res = $conn->query($all_files_query);

// Archived Files Query
$archived_files_query = "SELECT DISTINCT f.*, COALESCE(c.category_name, 'General') AS category_name 
                         FROM files_registry f 
                         LEFT JOIN file_categories c ON f.category_id = c.id 
                         WHERE f.is_deleted = 0 AND LOWER(f.status) = 'archived'
                         ORDER BY f.id DESC";
$archived_files_res = $conn->query($archived_files_query);

// Sent File Transfers Log Query
$transfers_query = "SELECT ft.*, f.ref_number, f.file_title, 
                    CONCAT(COALESCE(u1.first_name, ''), ' ', COALESCE(u1.middle_name, ''), ' ', COALESCE(u1.last_name, '')) AS sender_name,
                    CONCAT(COALESCE(u2.first_name, ''), ' ', COALESCE(u2.middle_name, ''), ' ', COALESCE(u2.last_name, '')) AS receiver_name
                    FROM file_transfers ft 
                    JOIN files_registry f ON ft.file_id = f.id 
                    LEFT JOIN users u1 ON ft.sender_id = u1.id 
                    LEFT JOIN users u2 ON ft.receiver_id = u2.id 
                    WHERE ft.sender_id = $manager_id
                    ORDER BY ft.id DESC";
$all_transfers_result = $conn->query($transfers_query);


// -------------------------------------------------------------
// 2. COUNTERS (ኩዌሪዎቹ ከላይ ከተከናወኑ በኋላ የሚሰሩ)
// -------------------------------------------------------------
$total_files     = $all_files_res ? $all_files_res->num_rows : 0;
$archived_files  = $archived_files_res ? $archived_files_res->num_rows : 0;
$total_incoming  = $incoming_files_res ? $incoming_files_res->num_rows : 0;

$pending_count_res = $conn->query("SELECT COUNT(DISTINCT f.id) as pending_total 
                                    FROM files_registry f 
                                    INNER JOIN file_transfers ft ON f.id = ft.file_id 
                                    WHERE f.is_deleted = 0 
                                      AND ft.receiver_id = $manager_id 
                                      AND ft.sender_id != $manager_id
                                      AND LOWER(ft.status) = 'pending'");
$pending_reviews = ($pending_count_res && $p_row = $pending_count_res->fetch_assoc()) ? intval($p_row['pending_total']) : 0;

$tr_count_res = $conn->query("SELECT COUNT(*) as total FROM file_transfers WHERE sender_id = $manager_id");
$total_transfers = ($tr_count_res && $tr_row = $tr_count_res->fetch_assoc()) ? $tr_row['total'] : 0;

$pending_count = $pending_reviews;


// -------------------------------------------------------------
// 3. FETCH USERS & CATEGORIES
// -------------------------------------------------------------
$users_array = [];
$u_stmt = $conn->prepare("SELECT id, CONCAT(COALESCE(first_name, ''), ' ', COALESCE(middle_name, ''), ' ', COALESCE(last_name, '')) AS full_name, username, department, role FROM users WHERE id != ? AND (status = 'active' OR status IS NULL) ORDER BY first_name ASC");
if ($u_stmt) {
    $u_stmt->bind_param("i", $manager_id);
    $u_stmt->execute();
    $u_res = $u_stmt->get_result();
    while ($u = $u_res->fetch_assoc()) { $users_array[] = $u; }
    $u_stmt->close();
}

$categories_array = [];
$cat_res = $conn->query("SELECT id, category_name FROM file_categories ORDER BY category_name ASC");
if ($cat_res) {
    while ($c = $cat_res->fetch_assoc()) { $categories_array[] = $c; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Console - Document Management System</title>
     <link rel="stylesheet" href="assets/css/bootstrap.min.css">
     <link rel="stylesheet" href="assets/icons/bootstrap-icons.min.css"> 
    <style>
        body { background-color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .wrapper { display: flex; width: 100%; min-height: 100vh; }
        .sidebar { min-width: 260px; max-width: 260px; background: #ffffff; border-right: 1px solid #e2e8f0; min-height: 100vh; }
        .content-area { flex-grow: 1; background: #f8fafc; }
        .sidebar-header { padding: 20px; border-bottom: 1px solid #e2e8f0; background: #1e293b; color: #fff; }
        .sidebar-menu { padding: 15px 10px; list-style: none; margin: 0; }
        .sidebar-menu li { margin-bottom: 5px; }
        .sidebar-menu a {
            display: flex; align-items: center; padding: 12px 15px; color: #64748b;
            text-decoration: none; border-radius: 8px; font-weight: 600; transition: all 0.2s;
            cursor: pointer;
        }
        .sidebar-menu a:hover, .sidebar-menu a.active { background-color: #0d6efd; color: #ffffff; }
        .sidebar-menu a i { margin-right: 12px; font-size: 1.1rem; }
        .top-navbar { background: #1e293b; color: white; padding: 12px 30px; }
        .stat-card { border: none; border-radius: 12px; color: white; transition: transform 0.2s; cursor: pointer; }
        .stat-card:hover { transform: translateY(-3px); }
        .table-card { border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .content-section { display: none; }
        .content-section.active-section { display: block; }
        
        #context-menu {
            position: absolute;
            display: none;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            box-shadow: 0px 4px 12px rgba(0,0,0,0.15);
            border-radius: 8px;
            z-index: 1050;
            width: 190px;
            padding: 5px 0;
        }
        #context-menu ul { list-style: none; padding: 0; margin: 0; }
        #context-menu li a {
            display: flex; align-items: center; padding: 9px 15px; color: #334155;
            text-decoration: none; font-size: 0.9rem; font-weight: 500;
        }
        #context-menu li a:hover { background-color: #f1f5f9; color: #0d6efd; }
        #context-menu li a.text-danger:hover { background-color: #fef2f2; color: #dc3545; }
        tr.file-row { cursor: pointer; user-select: none; }

        @media print {
            .no-print { display: none !important; }
            .sidebar { display: none; }
            .content-area { width: 100%; }
        }
    </style>
</head>
<body>

<div class="wrapper">
    <!-- Sidebar -->
    <nav class="sidebar no-print">
        <div class="sidebar-header d-flex align-items-center">
            <i class="bi bi-briefcase-fill text-warning me-2 fs-4"></i>
            <h5 class="fw-bold mb-0">Manager Console</h5>
        </div>
        <ul class="sidebar-menu">
            <li>
                <a class="nav-link-custom active" id="nav-dash" onclick="switchSection(event, 'dash-sec', 'nav-dash')">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
            </li>
            <li>
                <a class="nav-link-custom" id="nav-incoming" onclick="switchSection(event, 'incoming-sec', 'nav-incoming')">
                    <i class="bi bi-inbox-fill"></i> Incoming Files
                </a>
            </li>
            <li>
                <a class="nav-link-custom" id="nav-files" onclick="switchSection(event, 'files-sec', 'nav-files')">
                    <i class="bi bi-folder2-open"></i> Files List
                </a>
            </li>
            <li>
                <a class="nav-link-custom" id="nav-transfers" onclick="switchSection(event, 'transfers-sec', 'nav-transfers')">
                    <i class="bi bi-arrow-left-right"></i> sent file
                </a>
            </li>
            <li>
                <a class="nav-link-custom" id="nav-archived" onclick="switchSection(event, 'archived-sec', 'nav-archived')">
                    <i class="bi bi-archive-fill"></i> Archived Files
                </a>
            </li>
        </ul>
    </nav>

    <div class="content-area">
        <!-- Top Navigation -->
        <div class="top-navbar d-flex justify-content-between align-items-center no-print">
            <span class="fs-5 fw-semibold"><i class="bi bi-shield-check me-2"></i>Office Manager Control</span>
            <div class="d-flex align-items-center gap-3">
               
                <span class="text-white">
                    <i class="bi bi-person-circle me-1"></i>
                    <strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Manager'); ?></strong> 
                    <span class="badge bg-warning text-dark ms-1"><?php echo htmlspecialchars($manager_dept ?: 'Manager'); ?></span>
                </span>
                <a class="btn btn-danger btn-sm" href="logout.php">
                    <i class="bi bi-box-arrow-right me-1"></i> Logout
                </a>
            </div>
        </div>

        <div class="container-fluid p-4">
            <?php if(!empty($message)): ?>
                <div class="alert alert-success alert-dismissible fade show no-print" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if(!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show no-print" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mb-4 no-print">
                <div>
                    <h2 class="fw-bold text-dark mb-0">MANAGER DASHBOARD</h2>
                    <small class="text-muted">Manage department files, assignments, and movement logs</small>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadFileModal">
                        <i class="bi bi-cloud-upload-fill me-1"></i> Upload New Files
                    </button>
                    <button class="btn btn-warning text-dark" data-bs-toggle="modal" data-bs-target="#transferFileModal">
                        <i class="bi bi-send-fill me-1"></i> Transfer File
                    </button>
                  
                </div>
            </div>
<!-- DASHBOARD SECTION -->
<div id="dash-sec" class="content-section active-section">
    <div class="row g-4 mb-4 no-print">
        <!-- All Files -->
        <div class="col-md-6">
            <div class="card stat-card bg-secondary text-white p-4 shadow-sm" onclick="switchSection(event, 'files-sec', 'nav-files')">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="small text-uppercase fw-bold opacity-75">All Files</div>
                        <div class="fs-1 fw-bold"><?php echo $total_files; ?></div>
                    </div>
                    <i class="bi bi-file-earmark-text fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
        
       <!-- Incoming Files -->
<div class="col-md-6">
    <div class="card stat-card bg-secondary text-white p-4 shadow-sm" onclick="switchSection(event, 'incoming-sec', 'nav-incoming')">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <div class="small text-uppercase fw-bold opacity-75">Incoming Files</div>
                <!-- veiw all file -->
                <div class="fs-1 fw-bold"><?php echo $total_incoming; ?></div>
                <!-- Pending -->
                <?php if ($pending_reviews > 0): ?>
                    <span class="badge bg-danger rounded-pill mt-1">
                        <i class="bi bi-bell-fill me-1"></i><?php echo $pending_reviews; ?> Pending
                    </span>
                <?php endif; ?>
            </div>
            <i class="bi bi-inbox-fill fs-1 opacity-50"></i>
        </div>
    </div>
</div>

        <!-- Transferred Files -->
        <div class="col-md-6">
            <div class="card stat-card bg-secondary text-white p-4 shadow-sm" onclick="switchSection(event, 'transfers-sec', 'nav-transfers')">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="small text-uppercase fw-bold opacity-75">sent file</div>
                        <div class="fs-1 fw-bold"><?php echo $total_transfers; ?></div>
                    </div>
                    <i class="bi bi-arrow-repeat fs-1 opacity-50"></i>
                </div>
            </div>
        </div>

        <!-- Archived Files -->
        <div class="col-md-6">
            <div class="card stat-card bg-secondary text-white p-4 shadow-sm" onclick="switchSection(event, 'archived-sec', 'nav-archived')">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="small text-uppercase fw-bold opacity-75">Archived Files</div>
                        <div class="fs-1 fw-bold"><?php echo $archived_files; ?></div>
                    </div>
                    <i class="bi bi-archive-fill fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
</div>

 <!-- INCOMING FILES SECTION -->
<div id="incoming-sec" class="content-section">
    <div class="card table-card bg-white p-3 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="bi bi-inbox-fill me-2 text-warning"></i>Incoming Transfers
            </h5>

            <div>
                <!-- Total Incoming Count -->
                <span class="badge bg-primary rounded-pill px-3 py-2 me-1">
                    Total: <?php echo $total_incoming; ?>
                </span>

                <!-- Pending Count -->
                <?php if ($pending_count > 0): ?>
                    <span class="badge bg-danger rounded-pill px-3 py-2">
                        <i class="bi bi-bell-fill me-1"></i> <?php echo $pending_count; ?> Pending
                    </span>
                <?php else: ?>
                    <span class="badge bg-secondary rounded-pill px-3 py-2">0 Pending</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Ref No</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Sender</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($incoming_files_res && $incoming_files_res->num_rows > 0): ?>
                        <?php 
                        $incoming_files_res->data_seek(0);
                        while($row = $incoming_files_res->fetch_assoc()): 
                            
                            $tr_status = strtolower(trim($row['transfer_status'] ?? 'pending'));
                            $is_approved = in_array($tr_status, ['received', 'accepted', 'approved', 'active']);
                        ?>
                        <tr class="file-row" 
                            data-id="<?php echo $row['id']; ?>" 
                            data-title="<?php echo htmlspecialchars($row['file_title'] ?? ''); ?>" 
                            data-path="<?php echo htmlspecialchars($row['file_path'] ?? ''); ?>"
                            onclick="handleLeftClick(event, '<?php echo htmlspecialchars($row['file_path'] ?? ''); ?>')"
                            oncontextmenu="handleRightClick(event, '<?php echo $row['id']; ?>', '<?php echo htmlspecialchars($row['file_path'] ?? ''); ?>', '<?php echo htmlspecialchars($row['file_title'] ?? ''); ?>')">
                            
                            <td><code><?php echo htmlspecialchars($row['ref_number'] ?? ''); ?></code></td>
                            <td class="fw-bold"><?php echo htmlspecialchars($row['file_title'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['category_name'] ?? 'General'); ?></td>
                            <td><?php echo htmlspecialchars($row['sender_name'] ?? 'Unknown'); ?></td>
                            
                            <!-- STATUS DISPLAY LOGIC -->
                            <td>
                                <?php if($is_approved): ?>
                                    <span class="badge bg-success">
                                        <i class="bi bi-check-circle-fill me-1"></i> Received
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">
                                        <i class="bi bi-clock-history me-1"></i> Pending
                                    </span>
                                <?php endif; ?>
                            </td>
                            
                            <td><?php echo "{$row['eth_day']}/{$row['eth_month']}/{$row['eth_year']}"; ?></td>
                            
                            <!-- ACTION BUTTON LOGIC -->
                            <td onclick="event.stopPropagation();">
                                <div class="d-flex align-items-center gap-1">
                                    <!-- VIEW FILE BUTTON -->
                                    <?php if(!empty($row['file_path'])): ?>
                                        <a href="<?php echo htmlspecialchars($row['file_path']); ?>" 
                                           target="_blank" 
                                           class="btn btn-sm btn-outline-primary fw-bold" 
                                           title="View / Download File">
                                            <i class="bi bi-eye me-1"></i> View
                                        </a>
                                    <?php endif; ?>

                                    <!-- APPROVE BUTTON -->
                                    <?php if(!$is_approved): ?>
                                        <a href="manager_dashboard.php?action=approve&id=<?php echo $row['id']; ?>" 
                                           class="btn btn-sm btn-success fw-bold"
                                           onclick="return confirm('Approve this transfer? It will be marked as Received here and added to Active Files List.');">
                                            <i class="bi bi-check-circle me-1"></i> Approve
                                        </a>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border py-2 px-2">Completed</span>
                                    <?php endif; ?>

                                    <!-- TRASH BUTTON -->
                                    <a href="manager_dashboard.php?action=delete_incoming&id=<?php echo $row['id']; ?>" 
                                       class="btn btn-sm btn-outline-danger"
                                       title="Move to Trash"
                                       onclick="return confirm('Are you sure you want to move this incoming record to trash?');">
                                        <i class="bi bi-trash"></i> Trash
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No incoming transfers found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<!-- FILES LIST SECTION -->
<div id="files-sec" class="content-section">
    <div class="card table-card bg-white p-3 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-folder2-open me-2 text-primary"></i>All Files Registry</h5>
            <div class="input-group" style="width: 300px;">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input type="text" id="fileSearchInput" onkeyup="filterFilesTable()" class="form-control" placeholder="Search files...">
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="filesTable">
                <thead class="table-light">
                    <tr>
                        <th>Ref No</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($all_files_res && $all_files_res->num_rows > 0): ?>
                        <?php while($row = $all_files_res->fetch_assoc()): ?>
                        <tr class="file-row" 
                            data-id="<?php echo $row['id']; ?>" 
                            data-title="<?php echo htmlspecialchars($row['file_title'] ?? ''); ?>" 
                            data-path="<?php echo htmlspecialchars($row['file_path'] ?? ''); ?>"
                            oncontextmenu="handleRightClick(event, '<?php echo $row['id']; ?>', '<?php echo htmlspecialchars($row['file_path'] ?? ''); ?>', '<?php echo htmlspecialchars($row['file_title'] ?? ''); ?>')">
                            
                            <td onclick="handleLeftClick(event, '<?php echo htmlspecialchars($row['file_path'] ?? ''); ?>')"><code><?php echo htmlspecialchars($row['ref_number'] ?? ''); ?></code></td>
                            <td onclick="handleLeftClick(event, '<?php echo htmlspecialchars($row['file_path'] ?? ''); ?>')" class="fw-bold"><?php echo htmlspecialchars($row['file_title'] ?? ''); ?></td>
                            <td onclick="handleLeftClick(event, '<?php echo htmlspecialchars($row['file_path'] ?? ''); ?>')"><?php echo htmlspecialchars($row['category_name'] ?? 'Uncategorized'); ?></td>
                            <td onclick="handleLeftClick(event, '<?php echo htmlspecialchars($row['file_path'] ?? ''); ?>')"><span class="badge bg-primary"><?php echo htmlspecialchars($row['status'] ?? 'Active'); ?></span></td>
                            
                            <!-- Action Buttons -->
                            <td onclick="event.stopPropagation();">
                                <div class="d-flex align-items-center gap-1">
                                    <!-- View Button -->
                                    <?php if(!empty($row['file_path'])): ?>
                                        <a href="<?php echo htmlspecialchars($row['file_path']); ?>" 
                                           target="_blank" 
                                           class="btn btn-sm btn-outline-primary" 
                                           title="View File">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <!-- Download Button -->
                                        <a href="<?php echo htmlspecialchars($row['file_path']); ?>" 
                                           download 
                                           class="btn btn-sm btn-outline-success" 
                                           title="Download File">
                                            <i class="bi bi-download"></i>
                                        </a>
                                    <?php endif; ?>

                                    <!-- Archive Button -->
                                    <button type="button" 
                                            class="btn btn-sm btn-outline-secondary" 
                                            title="Archive File"
                                            onclick="archiveFileDirect(<?php echo $row['id']; ?>)">
                                        <i class="bi bi-archive"></i>
                                    </button>

                                    <!-- Move to Trash Button -->
                                    <a href="manager_dashboard.php?action=delete&id=<?php echo $row['id']; ?>" 
                                       class="btn btn-sm btn-outline-danger" 
                                       title="Move to Trash"
                                       onclick="return confirm('Are you sure you want to move this file to trash?');">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-muted">No files registered.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<!-- TRACK SENT TRANSFERS SECTION -->
<div id="transfers-sec" class="content-section">
    <div class="card table-card bg-white p-3 mb-4">
        <h5 class="fw-bold mb-3 text-dark"><i class="bi bi-send me-2 text-info"></i>Sent File Transfers</h5>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Ref No</th>
                        <th>Title</th>
                        <th>Receiver</th>
                        <th>Status</th>
                        <th>Transfer Date</th>
                        <th>Notes</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(isset($all_transfers_result) && $all_transfers_result && $all_transfers_result->num_rows > 0): ?>
                        <?php while($tr = $all_transfers_result->fetch_assoc()): ?>
                        <?php 
                            $raw_status = strtolower(trim($tr['status'] ?? ''));
                            $is_approved = in_array($raw_status, ['approved', 'accepted', 'received', '1']);
                            
                            $display_status = $is_approved ? 'Received' : 'Pending';
                            $badge_class    = $is_approved ? 'bg-success' : 'bg-warning text-dark';
                        ?>
                        <tr class="file-row" 
                            data-id="<?php echo $tr['id']; ?>" 
                            data-title="<?php echo htmlspecialchars($tr['file_title'] ?? ''); ?>" 
                            data-path="<?php echo htmlspecialchars($tr['file_path'] ?? ''); ?>"
                            onclick="handleLeftClick(event, '<?php echo htmlspecialchars($tr['file_path'] ?? ''); ?>')"
                            oncontextmenu="handleRightClick(event, '<?php echo $tr['id']; ?>', '<?php echo htmlspecialchars($tr['file_path'] ?? ''); ?>', '<?php echo htmlspecialchars($tr['file_title'] ?? ''); ?>')">
                            
                            <td><code><?php echo htmlspecialchars($tr['ref_number'] ?? 'N/A'); ?></code></td>
                            <td class="fw-bold"><?php echo htmlspecialchars($tr['file_title'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($tr['receiver_name'] ?? 'Unknown'); ?></td>
                            <td>
                                <span class="badge <?php echo $badge_class; ?>">
                                    <?php echo $display_status; ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($tr['created_at'] ?? $tr['transfer_date'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($tr['notes'] ?? '-'); ?></td>
                            <td onclick="event.stopPropagation();">
                                <div class="d-flex align-items-center gap-1">
                                    <!-- VIEW FILE BUTTON -->
                                    <?php if(!empty($tr['file_path'])): ?>
                                        <a href="<?php echo htmlspecialchars($tr['file_path']); ?>" 
                                           target="_blank" 
                                           class="btn btn-sm btn-outline-primary fw-bold" 
                                           title="View File">
                                            <i class="bi bi-eye me-1"></i> View
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-secondary disabled" title="No file attached">
                                            <i class="bi bi-eye-slash me-1"></i> View
                                        </button>
                                    <?php endif; ?>

                                    <!-- TRASH BUTTON -->
                                    <a href="manager_dashboard.php?action=delete_transfer&id=<?php echo $tr['id']; ?>" 
                                       class="btn btn-sm btn-outline-danger"
                                       title="Move to Trash"
                                       onclick="return confirm('Are you sure you want to move this transfer log to trash?');">
                                        <i class="bi bi-trash"></i> Trash
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No sent file transfers recorded.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<!-- ARCHIVED SECTION -->
<div id="archived-sec" class="content-section">
    <div class="card table-card bg-white p-3 mb-4 shadow-sm border-0">
        <h5 class="fw-bold mb-3 text-dark">
            <i class="bi bi-archive-fill me-2 text-secondary"></i>Archived Files
        </h5>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Ref No</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if (isset($archived_files_res) && $archived_files_res->num_rows > 0): 
                        $archived_files_res->data_seek(0);
                        while ($row = $archived_files_res->fetch_assoc()): 
                    ?>
                    <tr class="file-row" 
                        data-id="<?php echo $row['id']; ?>" 
                        data-title="<?php echo htmlspecialchars($row['file_title'] ?? ''); ?>" 
                        data-path="<?php echo htmlspecialchars($row['file_path'] ?? ''); ?>"
                        onclick="handleLeftClick(event, '<?php echo htmlspecialchars($row['file_path'] ?? ''); ?>')"
                        oncontextmenu="handleRightClick(event, '<?php echo $row['id']; ?>', '<?php echo htmlspecialchars($row['file_path'] ?? ''); ?>', '<?php echo htmlspecialchars($row['file_title'] ?? ''); ?>')">
                        <td><code><?php echo htmlspecialchars($row['ref_number'] ?? ''); ?></code></td>
                        <td class="fw-bold"><?php echo htmlspecialchars($row['file_title'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['category_name']); ?></td>
                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($row['status'] ?? 'archived'); ?></span></td>
                        <td class="text-center" onclick="event.stopPropagation();">
                            <div class="d-flex justify-content-center align-items-center gap-1">
                                <!-- View Button -->
                                <?php if(!empty($row['file_path'])): ?>
                                    <a href="<?php echo htmlspecialchars($row['file_path']); ?>" 
                                       target="_blank" 
                                       class="btn btn-sm btn-outline-primary" 
                                       title="View File">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                <?php endif; ?>

                                <!-- Restore Button -->
                                <a href="manager_dashboard.php?action=unarchive&id=<?php echo $row['id']; ?>" 
                                   class="btn btn-sm btn-outline-success" 
                                   onclick="return confirm('Are you sure you want to restore this file to Active Files?');"
                                   title="Restore to Active Files">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i> Restore
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php 
                        endwhile; 
                    else: 
                    ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">No archived files found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<!-- Notification Messages -->
<?php if (!empty($message)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>


<!-- Upload File Modal -->
<div class="modal fade" id="uploadFileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="manager_dashboard.php" method="POST" enctype="multipart/form-data">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold"><i class="bi bi-cloud-upload me-2 text-primary"></i>Upload New File</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reference Number/ቁጥር</label>
                        <input type="text" name="ref_number" class="form-control" required placeholder="REF/2018/001">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">File Title/ጉዳዩ</label>
                        <input type="text" name="file_title" class="form-control" required placeholder="Enter Title">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Category/የፋይሉ ክፍል</label>
                        <input type="text" name="category_name" class="form-control" list="category_list" placeholder="Select or type category..." required autocomplete="off">
                        
                        <datalist id="category_list">
                            <?php if (!empty($categories_array)): ?>
                                <?php foreach($categories_array as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat['category_name']); ?>">
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </datalist>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-4">
                            <label class="form-label fw-semibold">Eth Day</label>
                            <input type="number" name="eth_day" class="form-control" value="1" min="1" max="30" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-semibold">Eth Month</label>
                            <input type="number" name="eth_month" class="form-control" value="1" min="1" max="13" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-semibold">Eth Year</label>
                            <input type="number" name="eth_year" class="form-control" value="2018" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select File Document</label>
                        <input type="file" name="file_upload" class="form-control" accept="*/*" required>
                        <div class="form-text small text-muted">You can select PDF, Word, Excel, Images, ZIP, or any other file type.</div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="btn_upload_file" class="btn btn-primary fw-bold">Upload Document</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Transfer File Modal -->
<div class="modal fade" id="transferFileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="manager_dashboard.php" method="POST">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold"><i class="bi bi-send me-2 text-warning"></i>Transfer Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    
                    <!-- Dynamic File Search Field (Bootstrap Native Datalist) -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select File To Transfer</label>
                        <input class="form-control" list="filesOptions" id="file_search_input" placeholder="Type to search file..." required onchange="updateHiddenFileId(this)">
                        <input type="hidden" name="file_id" id="hidden_file_id" required>
                        <datalist id="filesOptions">
                            <?php 
                            if ($all_files_res && $all_files_res->num_rows > 0) {
                                mysqli_data_seek($all_files_res, 0);
                                while ($f = $all_files_res->fetch_assoc()) {
                                    $label = htmlspecialchars($f['ref_number'] . ' - ' . $f['file_title']);
                                    echo '<option data-id="' . $f['id'] . '" value="' . $label . '">';
                                }
                            }
                            ?>
                        </datalist>
                    </div>

                    <!-- Dynamic User Search Field (Bootstrap Native Datalist) -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Transfer To User</label>
                        <input class="form-control" list="usersOptions" id="user_search_input" placeholder="Type to search user..." required onchange="updateHiddenUserId(this)">
                        <input type="hidden" name="receiver_id" id="hidden_receiver_id" required>
                        <datalist id="usersOptions">
                            <?php foreach($users_array as $usr): 
                                $usr_label = htmlspecialchars($usr['full_name'] . ' (' . ($usr['department'] ?? 'Staff') . ')');
                            ?>
                                <option data-id="<?php echo $usr['id']; ?>" value="<?php echo $usr_label; ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Transfer Note / Instructions</label>
                        <textarea name="remarks" class="form-control" rows="3" placeholder="Additional details..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="btn_transfer" class="btn btn-warning text-dark fw-bold">Confirm Transfer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Vanilla JS (jQuery / Select2 ጨርሶ አያስፈልግም) -->
<script>
function updateHiddenFileId(input) {
    const list = document.getElementById('filesOptions');
    const options = list.querySelectorAll('option');
    const hiddenInput = document.getElementById('hidden_file_id');
    hiddenInput.value = '';

    for (let opt of options) {
        if (opt.value === input.value) {
            hiddenInput.value = opt.getAttribute('data-id');
            if (typeof syncTransferTitle === 'function') {
                syncTransferTitle(input);
            }
            break;
        }
    }
}

function updateHiddenUserId(input) {
    const list = document.getElementById('usersOptions');
    const options = list.querySelectorAll('option');
    const hiddenInput = document.getElementById('hidden_receiver_id');
    hiddenInput.value = '';

    for (let opt of options) {
        if (opt.value === input.value) {
            hiddenInput.value = opt.getAttribute('data-id');
            break;
        }
    }
}
</script>
<!-- Context Menu Structure -->
<div id="context-menu">
    <ul>
        <li><a href="#" id="ctx-view" target="_blank"><i class="bi bi-eye me-2 text-primary"></i> View Document</a></li>
        <li><a href="#" id="ctx-download" download><i class="bi bi-download me-2 text-success"></i> Download Document</a></li>

        <li><a href="#" id="ctx-delete" class="text-danger"><i class="bi bi-trash me-2"></i> Move to Trash</a></li>
    </ul>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    const contextMenu = document.getElementById("context-menu");

    function switchSection(event, sectionId, navId) {
        if(event) event.preventDefault();
        document.querySelectorAll('.content-section').forEach(sec => sec.classList.remove('active-section'));
        document.querySelectorAll('.nav-link-custom').forEach(nav => nav.classList.remove('active'));
        
        document.getElementById(sectionId).classList.add('active-section');
        const activeNav = document.getElementById(navId);
        if(activeNav) activeNav.classList.add('active');
    }

    function filterFilesTable() {
        let input = document.getElementById("fileSearchInput");
        let filter = input.value.toLowerCase();
        let rows = document.querySelectorAll("#filesTable tbody tr");

        rows.forEach(row => {
            let text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? "" : "none";
        });
    }

    function handleLeftClick(event, filePath) {
        if (filePath && filePath !== '') {
            window.open(filePath, '_blank');
        }
    }

    // DIRECT ACTION: 
    function archiveFileDirect(fileId) {
        if (!confirm("Are you sure you want to archive this file?")) return;

        // 1. Form Data
        let formData = new FormData();
        formData.append('id', fileId);

        // 2. Fetch Request 
        fetch('archive_action.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            // HTTP Status Code 200 
            if (!response.ok) {
                throw new Error(`Server returned HTTP ${response.status} (${response.statusText})`);
            }
            return response.text();
        })
        .then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                // PHP Syntax Error 
                throw new Error("Invalid JSON response from server:\n" + text);
            }
        })
        .then(data => {
            if (data.success) {
                let targetRow = document.querySelector(`tr[data-id="${fileId}"]`);
                if (targetRow) targetRow.remove();
                alert("File archived successfully!");
            } else {
                alert("Server Error: " + (data.message || "Failed to archive file."));
            }
        })
        .catch(error => {
            console.error('Archive Error:', error);
            alert(error.message); 
        });
    }

    // RIGHT CLICK CONTEXT MENU HANDLER
    function handleRightClick(event, id, filePath, fileTitle) {
        event.preventDefault();

        contextMenu.style.display = "block";
        contextMenu.style.left = `${event.pageX}px`;
        contextMenu.style.top = `${event.pageY}px`;

        document.getElementById("ctx-view").href = filePath || "#";
        document.getElementById("ctx-download").href = filePath || "#";
        document.getElementById("ctx-delete").href = `manager_dashboard.php?action=delete&id=${id}`;

        // Context Menu Archive Click (AJAX)
        const ctxArchiveBtn = document.getElementById("ctx-archive");
        ctxArchiveBtn.href = "#";
        ctxArchiveBtn.onclick = function(e) {
            e.preventDefault();
            contextMenu.style.display = "none";
            archiveFileDirect(id);
        };
        
        // Context Menu Transfer Click
        document.getElementById("ctx-transfer").onclick = function(e) {
            e.preventDefault();
            document.getElementById("modal_transfer_file_id").value = id;
            document.getElementById("modal_transfer_file_title").value = fileTitle;
            
            let transferModal = new bootstrap.Modal(document.getElementById('transferFileModal'));
            transferModal.show();
            contextMenu.style.display = "none";
        };
    }

    // CLOSE CONTEXT MENU ON CLICK OUTSIDE
    document.addEventListener("click", function(e) {
        if (contextMenu && !contextMenu.contains(e.target)) {
            contextMenu.style.display = "none";
        }
    });
</script>
</body>
</html>
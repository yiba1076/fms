<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if ($user_role !== 'staff') {
    session_destroy();
    header("Location: login.php?error=unauthorized");
    exit();
}

$user_id = $_SESSION['user_id'];

// Flash messages
$success = $_SESSION['success'] ?? "";
$error = $_SESSION['error'] ?? "";
unset($_SESSION['success'], $_SESSION['error']);

// ==========================================
// A. RECEIVE FILE, MOVE TO TRASH & DELETE SENT LOGIC
// ==========================================
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    // Move File to Trash Logic
    if ($action === 'trash' && isset($_GET['file_id'])) {
        $file_id = intval($_GET['file_id']);
        $stmt_trash = $conn->prepare("UPDATE files_registry SET is_deleted = 1 WHERE id = ?");
        if ($stmt_trash) {
            $stmt_trash->bind_param("i", $file_id);
            if ($stmt_trash->execute()) {
                $_SESSION['success'] = "File moved to trash successfully!";
            } else {
                $_SESSION['error'] = "Error moving file to trash: " . $stmt_trash->error;
            }
            $stmt_trash->close();
        }
        header("Location: staff_dashboard.php");
        exit();
    }

    // Receive / Approve File Logic
    if ($action === 'receive' && isset($_GET['transfer_id'])) {
        $transfer_id = intval($_GET['transfer_id']);
        $stmt_rec = $conn->prepare("UPDATE file_transfers SET status = 'Received' WHERE id = ? AND receiver_id = ?");
        if ($stmt_rec) {
            $stmt_rec->bind_param("ii", $transfer_id, $user_id);
            if ($stmt_rec->execute()) {
                $_SESSION['success'] = "File approved and received successfully!";
            } else {
                $_SESSION['error'] = "Unable to approve file: " . $stmt_rec->error;
            }
            $stmt_rec->close();
        } else {
            $_SESSION['error'] = "SQL Error: " . $conn->error;
        }
        header("Location: staff_dashboard.php");
        exit();
    }

    // Delete Sent Transfer Record Logic (ለ Sent Files ማጥፊያ የታከለ)
    if ($action === 'delete_transfer' && isset($_GET['id'])) {
        $delete_id = intval($_GET['id']);
        $stmt_del = $conn->prepare("DELETE FROM file_transfers WHERE id = ? AND sender_id = ?");
        if ($stmt_del) {
            $stmt_del->bind_param("ii", $delete_id, $user_id);
            if ($stmt_del->execute()) {
                $_SESSION['success'] = "Sent transfer record deleted successfully!";
            } else {
                $_SESSION['error'] = "Error deleting record: " . $stmt_del->error;
            }
            $stmt_del->close();
        }
        header("Location: staff_dashboard.php");
        exit();
    }
}

// ==========================================
// B. SEARCH & FETCH ALL ACTIVE FILES (WITH CATEGORY JOIN)
// ==========================================
$search = "";
if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $search = trim($_GET['search']);
    $query = "SELECT f.*, c.category_name 
              FROM files_registry f 
              LEFT JOIN file_categories c ON f.category_id = c.id 
              WHERE f.is_deleted = 0 AND f.file_title LIKE ? 
              ORDER BY f.id DESC";
    $stmt = $conn->prepare($query);
    $searchTerm = "%" . $search . "%";
    $stmt->bind_param("s", $searchTerm);
    $stmt->execute();
    $filesResult = $stmt->get_result();
} else {
    $filesResult = $conn->query("SELECT f.*, c.category_name 
                                 FROM files_registry f 
                                 LEFT JOIN file_categories c ON f.category_id = c.id 
                                 WHERE f.is_deleted = 0 
                                 ORDER BY f.id DESC");
}

// ==========================================
// C. FETCH INCOMING TRANSFERS & COUNTS
// ==========================================
$incomingQuery = "SELECT ft.id as transfer_id, ft.notes, ft.status, ft.created_at as transfer_date,
                         f.id as file_id, f.file_title, f.ref_number, f.file_path,
                         CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as sender_name, 
                         u.department as sender_dept
                  FROM file_transfers ft
                  JOIN files_registry f ON ft.file_id = f.id
                  JOIN users u ON ft.sender_id = u.id
                  WHERE ft.receiver_id = ?
                  ORDER BY ft.id DESC";

$stmt_inc = $conn->prepare($incomingQuery);
$stmt_inc->bind_param("i", $user_id);
$stmt_inc->execute();
$incomingResult = $stmt_inc->get_result();

$unreadStmt = $conn->prepare("SELECT COUNT(*) as count FROM file_transfers WHERE receiver_id = ? AND status = 'Pending'");
$unreadStmt->bind_param("i", $user_id);
$unreadStmt->execute();
$unreadResult = $unreadStmt->get_result()->fetch_assoc();
$unreadCount = $unreadResult['count'] ?? 0;
$unreadStmt->close();

$totalFiles = ($filesResult) ? $filesResult->num_rows : 0;

// ==========================================
// D. FETCH SENT TRANSFERS LOGIC (የታከለው ዋና ክፍል)
// ==========================================
$sentQuery = "SELECT ft.id, ft.notes, ft.status, ft.created_at,
                     f.file_title, f.ref_number, f.file_path,
                     CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as receiver_name
              FROM file_transfers ft
              JOIN files_registry f ON ft.file_id = f.id
              JOIN users u ON ft.receiver_id = u.id
              WHERE ft.sender_id = ?
              ORDER BY ft.id DESC";

$stmt_sent = $conn->prepare($sentQuery);
$stmt_sent->bind_param("i", $user_id);
$stmt_sent->execute();
$sent_files_result = $stmt_sent->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Portal - Office File Management</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.min.css">   
    <style>
        :root {
            --sidebar-width: 250px;
            --topbar-height: 60px;
            --bg-light: #f4f6f9;
        }

        body {
            background-color: var(--bg-light);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }

        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            background-color: #1a233a;
            color: #ffffff;
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .sidebar .brand-section {
            height: var(--topbar-height);
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            background-color: #121829;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .sidebar .nav-link {
            color: #a6b0cf;
            padding: 0.8rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            border-left: 4px solid transparent;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            color: #ffffff;
            background-color: #232d47;
            border-left-color: #0d6efd;
        }

        .main-wrapper {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .top-navbar {
            height: var(--topbar-height);
            background-color: #1e2738;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 2rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .content-body {
            padding: 2rem;
            flex: 1;
        }

        .metric-card {
            border-radius: 10px;
            color: #ffffff;
            padding: 1.5rem;
            position: relative;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: transform 0.2s ease;
        }

        .metric-card:hover {
            transform: translateY(-2px);
        }

        .metric-card.bg-gray-dark { 
            background-color: #4a5568; 
            color: #ffffff;
        }
        .metric-card.bg-gray-light { 
            background-color: #718096; 
            color: #ffffff;
        }

        .metric-card .icon {
            font-size: 2.5rem;
            opacity: 0.8;
        }

        .metric-card .number {
            font-size: 2.2rem;
            font-weight: bold;
            line-height: 1;
        }

        .metric-card .label {
            font-size: 0.85rem;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .card-custom {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            background: #ffffff;
        }

        .content-section {
            display: none;
        }

        .content-section.active {
            display: block;
        }

        .context-menu {
            position: absolute;
            display: none;
            background-color: #ffffff;
            border: 1px solid #ddd;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-radius: 6px;
            z-index: 1050;
            width: 180px;
            padding: 5px 0;
        }

        .context-menu ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .context-menu ul li a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 15px;
            color: #333;
            text-decoration: none;
            font-size: 0.9rem;
            transition: background 0.2s;
        }

        .context-menu ul li a:hover {
            background-color: #f1f3f5;
        }

        .clickable-row {
            cursor: pointer;
        }

        @media (max-width: 991px) {
            .sidebar { margin-left: calc(-1 * var(--sidebar-width)); }
            .main-wrapper { margin-left: 0; }
            .sidebar.show { margin-left: 0; }
        }
    </style>
</head>
<body>

 <!-- Sidebar Navbar -->
<div class="sidebar">
    <div class="brand-section">
        <i class="bi bi-briefcase-fill text-primary me-2"></i>
        <span>Staff Console</span>
    </div>
    <div class="nav flex-column mt-3">
        <a class="nav-link active" onclick="switchTab('dashboard-section', this)">
            <i class="bi bi-grid-1x2-fill"></i> Dashboard Overview
        </a>
        <a class="nav-link" onclick="switchTab('incoming-section', this)">
            <i class="bi bi-inbox-fill"></i> Incoming Files 
            <?php if ($unreadCount > 0): ?>
                <span class="badge bg-danger ms-auto px-2"><?php echo $unreadCount; ?></span>
            <?php endif; ?>
        <!-- 1. Sidebar Link -->
<a class="nav-link" onclick="switchTab('sent-section', this)">
    <i class="bi bi-send-fill"></i> Sent Files
</a>
        <a class="nav-link" onclick="switchTab('registered-section', this)">
            <i class="bi bi-folder-fill"></i> Files Registry
        </a>
    </div>
</div>

    <!-- Main Wrapper -->
    <div class="main-wrapper">
        
        <!-- Top Navbar -->
        <header class="top-navbar">
            <div class="d-flex align-items-center">
                <i class="bi bi-shield-check text-info me-2"></i>
                <span class="fw-semibold">Staff Control Panel</span>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="badge bg-secondary px-3 py-2">
                    <i class="bi bi-person-fill me-1"></i> <?php echo htmlspecialchars($_SESSION['username'] ?? 'Staff'); ?>
                </span>
                <a href="logout.php" class="btn btn-sm btn-danger px-3">
                    <i class="bi bi-box-arrow-right me-1"></i> Logout
                </a>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="content-body">

            <!-- Title & Action Buttons Row -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
                <div>
                    <h3 class="fw-bold text-dark mb-1">STAFF DASHBOARD</h3>
                    <p class="text-muted small mb-0">Manage incoming transfers and file registry archives</p>
                </div>
                <div class="mt-3 mt-md-0 d-flex gap-2">
                    <a href="upload.php" class="btn btn-primary">
                        <i class="bi bi-cloud-upload me-1"></i> Upload New File
                    </a>
                    <a href="transfer.php" class="btn btn-warning text-dark fw-semibold">
                        <i class="bi bi-send-fill me-1"></i> File Transfer
                    </a>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (!empty($success)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- TAB 1: DASHBOARD OVERVIEW -->
            <div id="dashboard-section" class="content-section active">
                <div class="row g-3 mb-4">
                    <div class="col-12" style="cursor: pointer;" onclick="switchToSection('registered-section')">
                        <div class="metric-card bg-gray-dark">
                            <div>
                                <div class="label">ALL FILES</div>
                                <div class="number mt-2"><?php echo $totalFiles; ?></div>
                            </div>
                            <div class="icon"><i class="bi bi-file-earmark-text"></i></div>
                        </div>
                    </div>
                    <div class="col-12" style="cursor: pointer;" onclick="switchToSection('incoming-section')">
                        <div class="metric-card bg-gray-light">
                            <div>
                                <div class="label">PENDING TRANSFERS</div>
                                <div class="number mt-2"><?php echo $unreadCount; ?></div>
                            </div>
                            <div class="icon"><i class="bi bi-inbox"></i></div>
                        </div>
                    </div>
                </div>
            </div>
<!-- TAB 2: INCOMING TRANSFERS TABLE -->
<div id="incoming-section" class="content-section" style="display: none;">
    <div class="card card-custom mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-inbox-fill text-warning me-2"></i>Incoming File Transfers</h5>
            <?php if (!empty($unreadCount) && $unreadCount > 0): ?>
                <span class="badge bg-danger rounded-pill"><?php echo $unreadCount; ?> Pending</span>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Ref No.</th>
                            <th>File Title</th>
                            <th>Sender</th>
                            <th>Notes</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (isset($incomingResult) && $incomingResult && $incomingResult->num_rows > 0): ?>
                            <?php while($inc = $incomingResult->fetch_assoc()): ?>
                                <?php $file_path = trim($inc['file_path'] ?? ''); ?>
                                <tr class="clickable-row" 
                                    data-filepath="<?php echo htmlspecialchars($file_path); ?>" 
                                    data-fileid="<?php echo $inc['file_id']; ?>"
                                    data-transferid="<?php echo $inc['transfer_id']; ?>"
                                    data-status="<?php echo $inc['status']; ?>"
                                    onclick="openFile(this)" 
                                    oncontextmenu="showContextMenu(event, this)">
                                    <td><code class="text-dark"><?php echo htmlspecialchars($inc['ref_number']); ?></code></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($inc['file_title']); ?></td>
                                    <td>
                                        <div><?php echo htmlspecialchars($inc['sender_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($inc['sender_dept'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td><small class="text-muted"><?php echo htmlspecialchars($inc['notes'] ?? '-'); ?></small></td>
                                    <td><small class="text-muted"><?php echo date('Y-m-d H:i', strtotime($inc['transfer_date'])); ?></small></td>
                                    <td>
                                        <?php if ($inc['status'] === 'Pending'): ?>
                                            <span class="badge bg-warning text-dark">Pending</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Received</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center" onclick="event.stopPropagation();">
                                        <div class="d-flex align-items-center justify-content-center gap-1">
                                            <!-- View Action -->
                                            <?php if (!empty($file_path)): ?>
                                                <a href="<?php echo htmlspecialchars($file_path); ?>" 
                                                   target="_blank" 
                                                   class="btn btn-sm btn-outline-primary" 
                                                   title="View File">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-secondary" disabled title="No File">
                                                    <i class="bi bi-eye-slash"></i>
                                                </button>
                                            <?php endif; ?>

                                            <!-- Approve / Receive Action -->
                                            <?php if ($inc['status'] === 'Pending'): ?>
                                                <a href="staff_dashboard.php?action=receive&transfer_id=<?php echo $inc['transfer_id']; ?>" 
                                                   class="btn btn-sm btn-success px-2 fw-semibold"
                                                   title="Approve File"
                                                   onclick="return confirm('Do you want to approve and mark this file as received?');">
                                                    <i class="bi bi-check-circle me-1"></i> Approve
                                                </a>
                                            <?php else: ?>
                                                <span class="badge bg-light text-success border me-1 py-2">
                                                    <i class="bi bi-check2-all me-1"></i>Approved
                                                </span>
                                            <?php endif; ?>

                                          
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No incoming transfers found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
            <!-- 2. Sent Files Section Table -->
<div id="sent-section" class="content-section" style="display: none;">
    <div class="card card-custom mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold text-dark mb-0">
                <i class="bi bi-send-fill me-2 text-primary"></i>Sent Files Log
            </h5>
            <div class="input-group" style="width: 250px;">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input type="text" id="sentFileSearchInput" onkeyup="filterSentFilesTable()" class="form-control form-control-sm" placeholder="Search sent files...">
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="sentFilesTable">
                    <thead class="table-light">
                        <tr>
                            <th>Ref No</th>
                            <th>File Title</th>
                            <th>Receiver</th>
                            <th>Status</th>
                            <th>Transfer Date</th>
                            <th>Notes</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (isset($sent_files_result) && $sent_files_result && $sent_files_result->num_rows > 0): ?>
                            <?php while ($st = $sent_files_result->fetch_assoc()): ?>
                                <?php 
                                    $raw_status = strtolower(trim($st['status'] ?? ''));
                                    $is_approved = in_array($raw_status, ['approved', 'accepted', 'received', '1']);
                                    $display_status = $is_approved ? 'Received' : 'Pending';
                                    $badge_class    = $is_approved ? 'bg-success' : 'bg-warning text-dark';
                                    $file_path      = trim($st['file_path'] ?? '');
                                ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($st['ref_number'] ?? 'N/A'); ?></code></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($st['file_title'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($st['receiver_name'] ?? 'Unknown'); ?></td>
                                    <td>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo $display_status; ?>
                                        </span>
                                    </td>
                                    <td><small class="text-muted"><?php echo htmlspecialchars($st['created_at'] ?? $st['transfer_date'] ?? 'N/A'); ?></small></td>
                                    <td><small class="text-muted"><?php echo htmlspecialchars($st['remarks'] ?? $st['notes'] ?? '-'); ?></small></td>
                                    <td class="text-center">
                                        <div class="d-flex align-items-center justify-content-center gap-1">
                                            <?php if (!empty($file_path)): ?>
                                                <a href="<?php echo htmlspecialchars($file_path); ?>" 
                                                   target="_blank" 
                                                   class="btn btn-sm btn-outline-primary" 
                                                   title="View File">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
                                                <a href="<?php echo htmlspecialchars($file_path); ?>" 
                                                   download 
                                                   class="btn btn-sm btn-outline-success" 
                                                   title="Download File">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-secondary" disabled title="No File Path">
                                                    <i class="bi bi-eye-slash"></i>
                                                </button>
                                            <?php endif; ?>
                                            <a href="staff_dashboard.php?action=delete_transfer&id=<?php echo $st['id']; ?>" 
                                               class="btn btn-sm btn-outline-danger" 
                                               title="Move to Trash"
                                               onclick="return confirm('Are you sure you want to move this log to trash?');">
                                                <i class="bi bi-trash"></i>
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
</div>
        <!-- TAB 3: REGISTERED FILES TABLE -->
<div id="registered-section" class="content-section" style="display: none;">
    <div class="card card-custom mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-files me-2 text-primary"></i>All Registered Files</h5>
            <form action="staff_dashboard.php" method="GET" class="d-flex" style="max-width: 250px;">
                <input type="text" name="search" class="form-control form-control-sm me-2" placeholder="Search title..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Ref No.</th>
                            <th>Category</th>
                            <th>Created Date</th>
                            <th>File Title</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($filesResult && $filesResult->num_rows > 0): ?>
                            <?php $count = 1; while($row = $filesResult->fetch_assoc()): ?>
                                <?php $filePath = trim($row['file_path'] ?? ''); ?>
                                <tr class="clickable-row" 
                                    data-filepath="<?php echo htmlspecialchars($filePath); ?>" 
                                    data-fileid="<?php echo $row['id']; ?>"
                                    onclick="openFile(this)" 
                                    oncontextmenu="showContextMenu(event, this)">
                                    <td><?php echo $count++; ?></td>
                                    <td><code><?php echo htmlspecialchars($row['ref_number'] ?? 'N/A'); ?></code></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($row['category_name'] ?? 'General'); ?></span></td>
                                    <td><small class="text-muted"><?php echo isset($row['created_at']) ? date('Y-m-d', strtotime($row['created_at'])) : '-'; ?></small></td>
                                    <td class="fw-semibold text-dark"><?php echo htmlspecialchars($row['file_title']); ?></td>
                                    <td class="text-center" onclick="event.stopPropagation();">
                                        <div class="d-flex align-items-center justify-content-center gap-1">
                                            <!-- 1. View File Button -->
                                            <?php if (!empty($filePath)): ?>
                                                <a href="<?php echo htmlspecialchars($filePath); ?>" 
                                                   target="_blank" 
                                                   class="btn btn-sm btn-outline-primary" 
                                                   title="View File">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-secondary" disabled title="No File Path">
                                                    <i class="bi bi-eye-slash"></i>
                                                </button>
                                            <?php endif; ?>

                                            <!-- 2. Download File Button -->
                                            <?php if (!empty($filePath)): ?>
                                                <a href="<?php echo htmlspecialchars($filePath); ?>" 
                                                   download 
                                                   class="btn btn-sm btn-outline-success" 
                                                   title="Download File">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            <?php endif; ?>

                                            <!-- 3. Move to Trash Button -->
                                            <a href="staff_dashboard.php?action=trash&file_id=<?php echo $row['id']; ?>" 
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
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No registered files found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</main>
</div>

<!-- Custom Right Click Context Menu (እንደነበረ ይቆያል) -->
<div id="contextMenu" class="context-menu">
    <ul>
        <li><a href="#" id="menuOpen" target="_blank"><i class="bi bi-box-arrow-up-right"></i> Open File</a></li>
        <li><a href="#" id="menuDownload" download><i class="bi bi-download"></i> Download</a></li>
        <li id="receiveMenuItem" style="display: none;"><a href="#" id="menuReceive" class="text-success"><i class="bi bi-check-circle"></i> Approve / Receive</a></li>
        <li><a href="#" id="menuTrash" class="text-danger" onclick="return confirm('Move this file to trash?');"><i class="bi bi-trash"></i> Move to Trash</a></li>
    </ul>
</div>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    // 1. Tab Switching Function ()
    function switchTab(sectionId, element) {
        // content sections 
        const sections = document.querySelectorAll('.content-section');
        sections.forEach(sec => sec.style.display = 'none');

        // section 
        const targetSection = document.getElementById(sectionId);
        if (targetSection) {
            targetSection.style.display = 'block';
        }

        // Sidebar link active class 
        const navLinks = document.querySelectorAll('.sidebar .nav-link');
        navLinks.forEach(link => link.classList.remove('active'));

        if (element) {
            element.classList.add('active');
        }
    }

    // 2. Overview cards Shortcut links function
    function switchToSection(sectionId) {
        const navLinks = document.querySelectorAll('.sidebar .nav-link');
        let targetLink = null;

        if (sectionId === 'incoming-section') {
            targetLink = navLinks[1]; // Incoming Files tab
        } else if (sectionId === 'sent-section') {
            targetLink = navLinks[2]; // Sent Files tab
        } else if (sectionId === 'registered-section') {
            targetLink = navLinks[3]; // Files Registry tab
        }

        switchTab(sectionId, targetLink);
    }

    // 3. Open File Function
    function openFile(row) {
        const filePath = row.getAttribute('data-filepath');
        if (filePath) {
            window.open(filePath, '_blank');
        }
    }

    // 4. Context Menu Handlers
    const contextMenu = document.getElementById('contextMenu');

    function showContextMenu(e, row) {
        e.preventDefault();

        const filePath = row.getAttribute('data-filepath');
        const fileId = row.getAttribute('data-fileid');
        const transferId = row.getAttribute('data-transferid');
        const status = row.getAttribute('data-status');

        document.getElementById('menuOpen').href = filePath;
        document.getElementById('menuDownload').href = filePath;
        document.getElementById('menuTrash').href = 'staff_dashboard.php?action=trash&file_id=' + fileId;

        const receiveMenuItem = document.getElementById('receiveMenuItem');
        if (receiveMenuItem) {
            if (transferId && status === 'Pending') {
                receiveMenuItem.style.display = 'block';
                document.getElementById('menuReceive').href = 'staff_dashboard.php?action=receive&transfer_id=' + transferId;
            } else {
                receiveMenuItem.style.display = 'none';
            }
        }

        contextMenu.style.left = e.pageX + 'px';
        contextMenu.style.top = e.pageY + 'px';
        contextMenu.style.display = 'block';
    }

    // context menu 
    document.addEventListener('click', function() {
        if (contextMenu) {
            contextMenu.style.display = 'none';
        }
    });

    // Page Load auto-tab check )
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('search')) {
            const navLinks = document.querySelectorAll('.sidebar .nav-link');
            switchTab('registered-section', navLinks[3]);
        }
    });
</script>
</body>
</html>
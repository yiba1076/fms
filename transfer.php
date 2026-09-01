<?php
session_start();
require_once 'db.php';

// 1. Security Check
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$current_user_id = $_SESSION['user_id'];
$message = "";
$error = "";

// 2. Check if file_id exists in URL
$file_id = isset($_GET['file_id']) ? intval($_GET['file_id']) : 0;
$selected_file = null;
$all_files = null;

if ($file_id > 0) {
    // Fetch selected file if ID is provided via URL
    $stmt = $conn->prepare("SELECT * FROM files_registry WHERE id = ? AND is_deleted = 0");
    if ($stmt) {
        $stmt->bind_param("i", $file_id);
        $stmt->execute();
        $selected_file = $stmt->get_result()->fetch_assoc();
    }
} else {
    // Fetch all non-deleted files for Dropdown if accessed directly
    $all_files = $conn->query("SELECT id, ref_number, file_title FROM files_registry WHERE is_deleted = 0 ORDER BY id DESC");
}

// 3. Process File Transfer (On Form POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_transfer'])) {
    $target_file_id = intval($_POST['file_id']);
    $receiver_id = intval($_POST['receiver_id']);
    $remarks = trim($_POST['remarks']);

    if ($target_file_id > 0 && $receiver_id > 0) {
        // Insert record into file_transfers table
        $stmt = $conn->prepare("INSERT INTO file_transfers (file_id, sender_id, receiver_id, notes) VALUES (?, ?, ?, ?)");
        
        if ($stmt) {
            $stmt->bind_param("iiis", $target_file_id, $current_user_id, $receiver_id, $remarks);

            if ($stmt->execute()) {
                // Determine user role dynamically for complete audit tracking
                $user_role_str = strtoupper($_SESSION['role'] ?? 'USER');

                // Activity Log Registration for all user roles (Admin, Manager, Staff)
                $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, 'FILE_TRANSFER', ?)");
                if ($log_stmt) {
                    $details = "Transferred File ID: {$target_file_id} to User ID: {$receiver_id} (Role: {$user_role_str})";
                    $log_stmt->bind_param("is", $current_user_id, $details);
                    $log_stmt->execute();
                }

                $message = "File transferred successfully!";
            } else {
                $error = "Failed to transfer file: " . $conn->error;
            }
        } else {
            $error = "Database Error: " . $conn->error;
        }
    } else {
        $error = "Please select a valid file and recipient!";
    }
}

// 4. Fetch Active Users (Excluding Current User)
$users_result = $conn->query("SELECT id, CONCAT(COALESCE(first_name, ''), ' ', COALESCE(middle_name, ''), ' ', COALESCE(last_name, '')) AS full_name, username, role, department FROM users WHERE id != $current_user_id AND status = 'active' ORDER BY first_name ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Transfer - Office Management System</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.min.css">  
    
    <!-- Select2 CSS for Searchable Dropdowns -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">

    <style>
        body { background-color: #f4f6f9; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        /* Fix Select2 Height to match Bootstrap Inputs */
        .select2-container--bootstrap-5 .select2-selection {
            min-height: 38px;
        }
    </style>
</head>
<body>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7">
            
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </a>
            </div>

            <!-- System Notifications -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle-fill me-2"></i><?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card card-custom bg-white p-4">
                <div class="border-bottom pb-3 mb-4 d-flex align-items-center">
                    <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-circle me-3">
                        <i class="bi bi-send-fill fs-3"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0">File Transfer Form</h4>
                        <small class="text-muted">Select a file and transfer it to another staff member or manager</small>
                    </div>
                </div>

                <form method="POST" action="transfer.php">
                    
                    <!-- 1. File Selection Section -->
                    <?php if ($selected_file): ?>
                        <!-- Single File Display (Passed via URL) -->
                        <input type="hidden" name="file_id" value="<?php echo $selected_file['id']; ?>">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Selected File</label>
                            <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($selected_file['ref_number'] . ' - ' . $selected_file['file_title']); ?>" readonly>
                        </div>
                    <?php else: ?>
                        <!-- Searchable File Dropdown Selection -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">1. Select File to Transfer <span class="text-danger">*</span></label>
                            <select name="file_id" id="select_file" class="form-select searchable-select" required>
                                <option value="">-- Choose a file --</option>
                                <?php if ($all_files && $all_files->num_rows > 0): ?>
                                    <?php while ($f = $all_files->fetch_assoc()): ?>
                                        <option value="<?php echo $f['id']; ?>">
                                            [Ref: <?php echo htmlspecialchars($f['ref_number']); ?>] - <?php echo htmlspecialchars($f['file_title']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <!-- 2. Searchable Recipient Selection Section -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">2. Select Recipient <span class="text-danger">*</span></label>
                        <select name="receiver_id" id="select_receiver" class="form-select searchable-select" required>
                            <option value="">-- Choose recipient --</option>
                            <?php if ($users_result && $users_result->num_rows > 0): ?>
                                <?php while ($u = $users_result->fetch_assoc()): ?>
                                    <option value="<?php echo $u['id']; ?>">
                                        <?php echo htmlspecialchars(trim($u['full_name']) !== '' ? $u['full_name'] : $u['username']); ?> (<?php echo htmlspecialchars($u['department'] ?? $u['role']); ?>)
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <!-- 3. Remarks / Notes Section -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">3. Remarks / Notes</label>
                        <textarea name="remarks" class="form-control" rows="3" placeholder="Example: Please review this file and provide approval..."></textarea>
                    </div>

                    <!-- Submit Button -->
                    <div class="d-grid gap-2">
                        <button type="submit" name="btn_transfer" class="btn btn-primary btn-lg fw-bold">
                            <i class="bi bi-send me-1"></i> Transfer File
                        </button>
                    </div>

                </form>
            </div>

        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>

<!-- jQuery and Select2 JS Libraries -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    $(document).ready(function() {
        // Activate Search feature for both dropdowns
        $('.searchable-select').select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: $(this).data('placeholder')
        });
    });
</script>
</body>
</html>
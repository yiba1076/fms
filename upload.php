<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'staff';

$message = "";
$error = "";

// Sequential Ref Number 
$latest_id_query = $conn->query("SELECT MAX(id) as max_id FROM files_registry");
$max_id = ($latest_id_query && $row = $latest_id_query->fetch_assoc()) ? $row['max_id'] : 0;
$generated_ref_num = "REF-" . date('Y') . "-" . str_pad($max_id + 1, 4, '0', STR_PAD_LEFT);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $file_title  = trim($_POST['file_title']);
    $category_id = intval($_POST['category_id'] ?? 0);
    $new_category= trim($_POST['new_category'] ?? '');
    $ref_number  = trim($_POST['ref_number'] ?? '');
    $eth_day     = intval($_POST['eth_day']);
    $eth_month   = intval($_POST['eth_month']);
    $eth_year    = intval($_POST['eth_year']);

    // 1. Ref Number IS NULL FULL BY SELF
    if (empty($ref_number)) {
        $ref_number = $generated_ref_num;
    }
    if (!empty($new_category)) {
        $check_cat = $conn->prepare("SELECT id FROM file_categories WHERE category_name = ?");
        $check_cat->bind_param("s", $new_category);
        $check_cat->execute();
        $res_cat = $check_cat->get_result();

        if ($res_cat && $res_cat->num_rows > 0) {
            $category_id = $res_cat->fetch_assoc()['id'];
        } else {
            $ins_cat = $conn->prepare("INSERT INTO file_categories (category_name) VALUES (?)");
            $ins_cat->bind_param("s", $new_category);
            if ($ins_cat->execute()) {
                $category_id = $ins_cat->insert_id;
            }
            $ins_cat->close();
        }
        $check_cat->close();
    }

    // File Upload Handling
    if (isset($_FILES['file_doc']) && $_FILES['file_doc']['error'] === 0) {
        $file_name = $_FILES['file_doc']['name'];
        $file_tmp  = $_FILES['file_doc']['tmp_name'];
        $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        $executable_exts = ['php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'cgi', 'pl', 'exe'];
        if (in_array($file_ext, $executable_exts)) {
            $file_name .= ".txt";
        }

        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $new_file_name = time() . "_" . preg_replace("/[^a-zA-Z0-9\.]/", "_", $file_name);
        $file_path = $upload_dir . $new_file_name;

        if (move_uploaded_file($file_tmp, $file_path)) {
            
            $stmt = $conn->prepare("INSERT INTO files_registry (ref_number, file_title, category_id, file_path, eth_day, eth_month, eth_year, uploaded_by, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)");
            
            if ($stmt === false) {
                $error = "Database error: " . $conn->error;
            } else {
                $stmt->bind_param("ssisiiii", $ref_number, $file_title, $category_id, $file_path, $eth_day, $eth_month, $eth_year, $user_id);
                
                if ($stmt->execute()) {
                    $message = "File successfully registered! Ref Number: " . htmlspecialchars($ref_number);

                    // Audit Logging
                    $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, 'FILE_UPLOAD', ?)");
                    if ($log_stmt) {
                        $log_details = "Uploaded file: '$file_title' [Ref: '$ref_number'] (Role: " . strtoupper($user_role) . ")";
                        $log_stmt->bind_param("is", $user_id, $log_details);
                        $log_stmt->execute();
                        $log_stmt->close();
                    }

                    $max_id++;
                    $generated_ref_num = "REF-" . date('Y') . "-" . str_pad($max_id + 1, 4, '0', STR_PAD_LEFT);
                } else {
                    $error = "Registration failed: " . $stmt->error;
                }
                $stmt->close();
            }
        } else {
            $error = "Failed to upload file to target directory!";
        }
    } else {
        $error = "Please select a valid file!";
    }
}

$back_url = "dashboard.php";
if ($user_role === 'admin') {
    $back_url = "admin_dashboard.php";
} elseif ($user_role === 'manager') {
    $back_url = "manager_dashboard.php";
} else {
    $back_url = "staff_dashboard.php";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register New File - Office FMS</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.min.css">   
    <style>
        body { background-color: #f4f6f9; }
        .upload-card { max-width: 650px; margin: 40px auto; border-radius: 12px; border: none; }
    </style>
</head>
<body>

<div class="container px-3">
    <div class="card upload-card shadow-sm p-4 bg-white">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="fw-bold mb-0 text-primary"><i class="bi bi-cloud-arrow-up-fill me-2"></i>Register New File</h4>
            <a href="<?php echo $back_url; ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>
        </div>

        <?php if(!empty($message)): ?>
            <div class="alert alert-success py-2 text-center small"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if(!empty($error)): ?>
            <div class="alert alert-danger py-2 text-center small"><?php echo $error; ?></div>
        <?php endif; ?>

        <form action="" method="POST" enctype="multipart/form-data">
            
            <div class="mb-3">
                <label class="form-label small fw-bold">Ref Number/ቁጥር</label>
                <input type="text" name="ref_number" class="form-control fw-bold" placeholder="<?php echo $generated_ref_num; ?>">
                <small class="text-muted">if null full  <code><?php echo $generated_ref_num; ?></code> </small>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-bold">File Title/ጉዳዩ</label>
                <input type="text" name="file_title" class="form-control" placeholder="Example: Annual Budget Report 2016" required>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-bold">File Category/የፋይሉ ክፍል</label>
                <div class="row g-2">
                    <div class="col-md-6">
                        <select name="category_id" class="form-select">
                            <option value="">-- Select Existing --</option>
                            <?php
                            $cat_res = $conn->query("SELECT * FROM file_categories");
                            if ($cat_res && $cat_res->num_rows > 0) {
                                while ($cat = $cat_res->fetch_assoc()) {
                                    echo "<option value='".$cat['id']."'>".htmlspecialchars($cat['category_name'])."</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <input type="text" name="new_category" class="form-control" placeholder="Or type new category name...">
                    </div>
                </div>
            </div>

            <div class="row g-2 mb-3">
                <label class="form-label small fw-bold mb-0">Registration Date/dd/mm/yy</label>
                <div class="col-md-4">
                    <input type="number" name="eth_day" class="form-control" placeholder="Day (1-30)" min="1" max="30" value="1" required>
                </div>
                <div class="col-md-4">
                    <input type="number" name="eth_month" class="form-control" placeholder="Month (1-13)" min="1" max="13" value="1" required>
                </div>
                <div class="col-md-4">
                    <input type="number" name="eth_year" class="form-control" placeholder="Year (2016)" value="2016" required>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label small fw-bold">Select Document </label>
                <input type="file" name="file_doc" class="form-control" required>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold"><i class="bi bi-save me-1"></i> Save File</button>
        </form>
    </div>
</div>

</body>
</html>
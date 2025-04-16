<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth_helper.php';
require_once __DIR__ . '/../../helpers/validation_helper.php';
require_once __DIR__ . '/../../helpers/file_helper.php';

$auth = new AuthHelper();
$validation = new ValidationHelper();
$fileHelper = new FileHelper();

// Check if user is logged in
if (!$auth->isLoggedIn()) {
    redirect('/web/modules/auth/login.php');
}

// Get current user data
$user = $auth->getCurrentUser();
$page_title = "My Profile";

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        // Validate input
        $nama = $validation->sanitizeInput($_POST['nama'] ?? '');
        $email = $validation->sanitizeInput($_POST['email'] ?? '');
        $telepon = $validation->sanitizeInput($_POST['telepon'] ?? '');
        $alamat = $validation->sanitizeInput($_POST['alamat'] ?? '');

        $errors = [];
        if (empty($nama)) $errors[] = "Name is required";
        if (empty($email)) $errors[] = "Email is required";
        if (!empty($email) && !$validation->validateEmail($email)) $errors[] = "Invalid email format";
        if (!empty($telepon) && !$validation->validatePhone($telepon)) $errors[] = "Invalid phone number format";

        if (empty($errors)) {
            try {
                $db = (new Database())->getConnection();
                
                // Start transaction
                $db->begin_transaction();

                // Update pengguna table
                $stmt = $db->prepare("UPDATE pengguna SET nama = ?, email = ? WHERE id = ?");
                $stmt->bind_param("ssi", $nama, $email, $user['id']);
                $stmt->execute();

                // Update role-specific table (guru or siswa)
                if ($user['peran'] === 'guru') {
                    $stmt = $db->prepare("UPDATE guru SET telepon = ?, alamat = ? WHERE pengguna_id = ?");
                    $stmt->bind_param("ssi", $telepon, $alamat, $user['id']);
                    $stmt->execute();
                } elseif ($user['peran'] === 'siswa') {
                    $stmt = $db->prepare("UPDATE siswa SET telepon = ?, alamat = ? WHERE pengguna_id = ?");
                    $stmt->bind_param("ssi", $telepon, $alamat, $user['id']);
                    $stmt->execute();
                }

                // Handle file upload if provided
                if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                    $uploadResult = $fileHelper->uploadFile($_FILES['foto'], 'profile_photos');
                    
                    if ($uploadResult['success']) {
                        $foto = $uploadResult['file_path'];
                        
                        // Update photo in appropriate table
                        if ($user['peran'] === 'guru') {
                            $stmt = $db->prepare("UPDATE guru SET foto = ? WHERE pengguna_id = ?");
                        } else {
                            $stmt = $db->prepare("UPDATE siswa SET foto = ? WHERE pengguna_id = ?");
                        }
                        $stmt->bind_param("si", $foto, $user['id']);
                        $stmt->execute();
                    } else {
                        throw new Exception($uploadResult['message']);
                    }
                }

                $db->commit();
                $success = "Profile updated successfully";
                
                // Refresh user data
                $user = $auth->getCurrentUser();
                
            } catch (Exception $e) {
                $db->rollback();
                $error = "Failed to update profile: " . $e->getMessage();
            }
        } else {
            $error = implode("<br>", $errors);
        }
    } elseif ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        $errors = [];
        if (empty($current_password)) $errors[] = "Current password is required";
        if (empty($new_password)) $errors[] = "New password is required";
        if ($new_password !== $confirm_password) $errors[] = "New passwords do not match";
        if (!empty($new_password) && strlen($new_password) < 8) $errors[] = "Password must be at least 8 characters";

        if (empty($errors)) {
            $result = $auth->updatePassword($user['id'], $current_password, $new_password);
            if ($result['success']) {
                $success = $result['message'];
            } else {
                $error = $result['message'];
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container py-4">
    <div class="row">
        <div class="col-md-4 mb-4">
            <div class="card">
                <div class="card-body text-center">
                    <div class="mb-3">
                        <?php if (!empty($user['foto'])): ?>
                            <img src="<?php echo '/web/assets/uploads/' . htmlspecialchars($user['foto']); ?>" 
                                 alt="Profile Photo" class="rounded-circle img-thumbnail" style="width: 150px; height: 150px; object-fit: cover;">
                        <?php else: ?>
                            <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center" 
                                 style="width: 150px; height: 150px;">
                                <i class="fas fa-user fa-4x text-secondary"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <h5 class="mb-1"><?php echo htmlspecialchars($user['nama']); ?></h5>
                    <p class="text-muted mb-3">
                        <?php echo ucfirst(htmlspecialchars($user['peran'])); ?>
                        <?php if (isset($user['identifier'])): ?>
                            <br>
                            <small><?php echo htmlspecialchars($user['identifier']); ?></small>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>

            <!-- Profile Information -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Profile Information</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="mb-3">
                            <label for="nama" class="form-label">Name</label>
                            <input type="text" class="form-control" id="nama" name="nama" 
                                   value="<?php echo htmlspecialchars($user['nama']); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="telepon" class="form-label">Phone</label>
                            <input type="text" class="form-control" id="telepon" name="telepon" 
                                   value="<?php echo htmlspecialchars($user['telepon'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label for="alamat" class="form-label">Address</label>
                            <textarea class="form-control" id="alamat" name="alamat" rows="3"
                                    ><?php echo htmlspecialchars($user['alamat'] ?? ''); ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="foto" class="form-label">Profile Photo</label>
                            <input type="file" class="form-control" id="foto" name="foto" accept="image/*">
                            <small class="text-muted">Max file size: 5MB. Allowed formats: JPG, JPEG, PNG</small>
                        </div>

                        <button type="submit" class="btn btn-primary">Update Profile</button>
                    </form>
                </div>
            </div>

            <!-- Change Password -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Change Password</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <input type="password" class="form-control" id="current_password" 
                                   name="current_password" required>
                        </div>

                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="new_password" 
                                   name="new_password" required>
                        </div>

                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" id="confirm_password" 
                                   name="confirm_password" required>
                        </div>

                        <button type="submit" class="btn btn-warning">Change Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

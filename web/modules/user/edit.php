<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth_helper.php';
require_once __DIR__ . '/../../helpers/validation_helper.php';
require_once __DIR__ . '/../../helpers/file_helper.php';

$auth = new AuthHelper();
$validation = new ValidationHelper();
$fileHelper = new FileHelper();

// Check if user is logged in and is admin
if (!$auth->isLoggedIn() || !$auth->hasRole('admin')) {
    redirect('/web/index.php', 'Access denied. Admin privileges required.', 'danger');
}

$db = (new Database())->getConnection();

// Get user ID from URL
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($user_id <= 0) {
    redirect('/web/modules/user/index.php', 'Invalid user ID.', 'danger');
}

// Get user data
$query = "
    SELECT 
        p.*,
        CASE 
            WHEN p.peran = 'guru' THEN g.nip
            WHEN p.peran = 'siswa' THEN s.nis
            ELSE NULL
        END as identifier,
        CASE 
            WHEN p.peran = 'guru' THEN g.telepon
            WHEN p.peran = 'siswa' THEN s.telepon
            ELSE NULL
        END as telepon,
        CASE 
            WHEN p.peran = 'guru' THEN g.alamat
            WHEN p.peran = 'siswa' THEN s.alamat
            ELSE NULL
        END as alamat,
        CASE 
            WHEN p.peran = 'guru' THEN g.foto
            WHEN p.peran = 'siswa' THEN s.foto
            ELSE NULL
        END as foto,
        CASE 
            WHEN p.peran = 'siswa' THEN s.kelas_id
            ELSE NULL
        END as kelas_id
    FROM pengguna p
    LEFT JOIN guru g ON p.id = g.pengguna_id AND p.peran = 'guru'
    LEFT JOIN siswa s ON p.id = s.pengguna_id AND p.peran = 'siswa'
    WHERE p.id = ?";

$stmt = $db->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    redirect('/web/modules/user/index.php', 'User not found.', 'danger');
}

// Get classes for student
$classes = [];
if ($user['peran'] === 'siswa') {
    $result = $db->query("SELECT id, nama, tingkat FROM kelas ORDER BY tingkat, nama");
    $classes = $result->fetch_all(MYSQLI_ASSOC);
}

$page_title = "Edit " . ucfirst($user['peran']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate common fields
    $nama = $validation->sanitizeInput($_POST['nama'] ?? '');
    $email = $validation->sanitizeInput($_POST['email'] ?? '');
    $telepon = $validation->sanitizeInput($_POST['telepon'] ?? '');
    $alamat = $validation->sanitizeInput($_POST['alamat'] ?? '');
    $new_password = $_POST['new_password'] ?? '';

    // Validate role-specific fields
    if ($user['peran'] === 'guru') {
        $nip = $validation->sanitizeInput($_POST['nip'] ?? '');
    } else {
        $nis = $validation->sanitizeInput($_POST['nis'] ?? '');
        $kelas_id = (int)($_POST['kelas_id'] ?? 0);
    }

    // Validation
    $errors = [];
    if (empty($nama)) $errors[] = "Name is required";
    if (empty($email)) $errors[] = "Email is required";
    if (!$validation->validateEmail($email)) $errors[] = "Invalid email format";
    if (!empty($new_password) && strlen($new_password) < 8) $errors[] = "Password must be at least 8 characters";
    if (!empty($telepon) && !$validation->validatePhone($telepon)) $errors[] = "Invalid phone number format";

    if ($user['peran'] === 'guru') {
        if (empty($nip)) $errors[] = "NIP is required";
        if (!$validation->validateNIP($nip)) $errors[] = "Invalid NIP format (must be 18 digits)";
    } else {
        if (empty($nis)) $errors[] = "NIS is required";
        if (!$validation->validateNIS($nis)) $errors[] = "Invalid NIS format (must be 10 digits)";
        if ($kelas_id <= 0) $errors[] = "Class selection is required";
    }

    // Check if email exists (excluding current user)
    $stmt = $db->prepare("SELECT id FROM pengguna WHERE email = ? AND id != ?");
    $stmt->bind_param("si", $email, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $errors[] = "Email already exists";
    }

    // Check if NIP/NIS exists (excluding current user)
    if ($user['peran'] === 'guru') {
        $stmt = $db->prepare("SELECT g.id FROM guru g JOIN pengguna p ON g.pengguna_id = p.id WHERE g.nip = ? AND p.id != ?");
        $stmt->bind_param("si", $nip, $user_id);
    } else {
        $stmt = $db->prepare("SELECT s.id FROM siswa s JOIN pengguna p ON s.pengguna_id = p.id WHERE s.nis = ? AND p.id != ?");
        $stmt->bind_param("si", $nis, $user_id);
    }
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $errors[] = ($user['peran'] === 'guru' ? "NIP" : "NIS") . " already exists";
    }

    if (empty($errors)) {
        try {
            $db->begin_transaction();

            // Update pengguna table
            if (!empty($new_password)) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE pengguna SET nama = ?, email = ?, kata_sandi = ?, diperbarui_pada = NOW() WHERE id = ?");
                $stmt->bind_param("sssi", $nama, $email, $hashed_password, $user_id);
            } else {
                $stmt = $db->prepare("UPDATE pengguna SET nama = ?, email = ?, diperbarui_pada = NOW() WHERE id = ?");
                $stmt->bind_param("ssi", $nama, $email, $user_id);
            }
            $stmt->execute();

            // Handle file upload if provided
            $foto = $user['foto'];
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = $fileHelper->uploadFile($_FILES['foto'], $user['peran'] . '_photos');
                if ($uploadResult['success']) {
                    // Delete old photo if exists
                    if (!empty($foto)) {
                        $fileHelper->deleteFile($foto);
                    }
                    $foto = $uploadResult['file_path'];
                } else {
                    throw new Exception($uploadResult['message']);
                }
            }

            // Update role-specific table
            if ($user['peran'] === 'guru') {
                $stmt = $db->prepare("UPDATE guru SET nip = ?, telepon = ?, alamat = ?, foto = ?, diperbarui_pada = NOW() WHERE pengguna_id = ?");
                $stmt->bind_param("ssssi", $nip, $telepon, $alamat, $foto, $user_id);
            } else {
                $stmt = $db->prepare("UPDATE siswa SET nis = ?, kelas_id = ?, telepon = ?, alamat = ?, foto = ?, diperbarui_pada = NOW() WHERE pengguna_id = ?");
                $stmt->bind_param("sisssi", $nis, $kelas_id, $telepon, $alamat, $foto, $user_id);
            }
            $stmt->execute();

            $db->commit();
            redirect('/web/modules/user/index.php', ucfirst($user['peran']) . ' updated successfully.', 'success');

        } catch (Exception $e) {
            $db->rollback();
            $error = "Failed to update " . $user['peran'] . ": " . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0">Edit <?php echo ucfirst($user['peran']); ?></h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo $error; ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" enctype="multipart/form-data" class="needs-validation" novalidate>
                        <!-- Common Fields -->
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
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password">
                            <div class="form-text">Leave blank to keep current password. New password must be at least 8 characters.</div>
                        </div>

                        <!-- Role-specific Fields -->
                        <?php if ($user['peran'] === 'guru'): ?>
                            <div class="mb-3">
                                <label for="nip" class="form-label">NIP</label>
                                <input type="text" class="form-control" id="nip" name="nip" 
                                       value="<?php echo htmlspecialchars($user['identifier']); ?>" 
                                       required pattern="\d{18}" maxlength="18">
                                <div class="form-text">NIP must be exactly 18 digits.</div>
                            </div>
                        <?php else: ?>
                            <div class="mb-3">
                                <label for="nis" class="form-label">NIS</label>
                                <input type="text" class="form-control" id="nis" name="nis" 
                                       value="<?php echo htmlspecialchars($user['identifier']); ?>" 
                                       required pattern="\d{10}" maxlength="10">
                                <div class="form-text">NIS must be exactly 10 digits.</div>
                            </div>

                            <div class="mb-3">
                                <label for="kelas_id" class="form-label">Class</label>
                                <select class="form-select" id="kelas_id" name="kelas_id" required>
                                    <option value="">Select Class</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>" 
                                            <?php echo $user['kelas_id'] == $class['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($class['tingkat'] . ' - ' . $class['nama']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <!-- Additional Fields -->
                        <div class="mb-3">
                            <label for="telepon" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="telepon" name="telepon" 
                                   value="<?php echo htmlspecialchars($user['telepon'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label for="alamat" class="form-label">Address</label>
                            <textarea class="form-control" id="alamat" name="alamat" rows="3"
                                    ><?php echo htmlspecialchars($user['alamat'] ?? ''); ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="foto" class="form-label">Photo</label>
                            <?php if (!empty($user['foto'])): ?>
                                <div class="mb-2">
                                    <img src="<?php echo '/web/assets/uploads/' . htmlspecialchars($user['foto']); ?>" 
                                         alt="Current Photo" class="img-thumbnail" style="max-height: 100px;">
                                </div>
                            <?php endif; ?>
                            <input type="file" class="form-control" id="foto" name="foto" accept="image/*">
                            <div class="form-text">Maximum file size: 5MB. Allowed formats: JPG, JPEG, PNG</div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="/web/modules/user/index.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Update <?php echo ucfirst($user['peran']); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Form validation
(function () {
    'use strict'
    var forms = document.querySelectorAll('.needs-validation')
    Array.prototype.slice.call(forms).forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                event.preventDefault()
                event.stopPropagation()
            }
            form.classList.add('was-validated')
        }, false)
    })
})()
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

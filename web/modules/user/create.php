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

// Get user type from URL
$type = $_GET['type'] ?? '';
if (!in_array($type, ['guru', 'siswa'])) {
    redirect('/web/modules/user/index.php', 'Invalid user type.', 'danger');
}

$page_title = "Create " . ($type === 'guru' ? 'Teacher' : 'Student');
$db = (new Database())->getConnection();

// Get classes for student registration
$classes = [];
if ($type === 'siswa') {
    $result = $db->query("SELECT id, nama, tingkat FROM kelas ORDER BY tingkat, nama");
    $classes = $result->fetch_all(MYSQLI_ASSOC);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate common fields
    $nama = $validation->sanitizeInput($_POST['nama'] ?? '');
    $email = $validation->sanitizeInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $telepon = $validation->sanitizeInput($_POST['telepon'] ?? '');
    $alamat = $validation->sanitizeInput($_POST['alamat'] ?? '');

    // Validate role-specific fields
    if ($type === 'guru') {
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
    if (empty($password)) $errors[] = "Password is required";
    if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters";
    if (!empty($telepon) && !$validation->validatePhone($telepon)) $errors[] = "Invalid phone number format";

    if ($type === 'guru') {
        if (empty($nip)) $errors[] = "NIP is required";
        if (!$validation->validateNIP($nip)) $errors[] = "Invalid NIP format (must be 18 digits)";
    } else {
        if (empty($nis)) $errors[] = "NIS is required";
        if (!$validation->validateNIS($nis)) $errors[] = "Invalid NIS format (must be 10 digits)";
        if ($kelas_id <= 0) $errors[] = "Class selection is required";
    }

    // Check if email already exists
    $stmt = $db->prepare("SELECT id FROM pengguna WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $errors[] = "Email already exists";
    }

    // Check if NIP/NIS already exists
    if ($type === 'guru') {
        $stmt = $db->prepare("SELECT id FROM guru WHERE nip = ?");
        $stmt->bind_param("s", $nip);
    } else {
        $stmt = $db->prepare("SELECT id FROM siswa WHERE nis = ?");
        $stmt->bind_param("s", $nis);
    }
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $errors[] = ($type === 'guru' ? "NIP" : "NIS") . " already exists";
    }

    if (empty($errors)) {
        try {
            $db->begin_transaction();

            // Insert into pengguna table
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO pengguna (nama, email, kata_sandi, peran, dibuat_pada) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssss", $nama, $email, $hashed_password, $type);
            $stmt->execute();
            $pengguna_id = $db->insert_id;

            // Handle file upload if provided
            $foto = null;
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = $fileHelper->uploadFile($_FILES['foto'], $type . '_photos');
                if ($uploadResult['success']) {
                    $foto = $uploadResult['file_path'];
                } else {
                    throw new Exception($uploadResult['message']);
                }
            }

            // Insert into role-specific table
            if ($type === 'guru') {
                $stmt = $db->prepare("INSERT INTO guru (pengguna_id, nip, telepon, alamat, foto, dibuat_pada) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("issss", $pengguna_id, $nip, $telepon, $alamat, $foto);
            } else {
                $stmt = $db->prepare("INSERT INTO siswa (pengguna_id, nis, kelas_id, telepon, alamat, foto, dibuat_pada) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("isisss", $pengguna_id, $nis, $kelas_id, $telepon, $alamat, $foto);
            }
            $stmt->execute();

            $db->commit();
            redirect('/web/modules/user/index.php', ucfirst($type) . ' created successfully.', 'success');

        } catch (Exception $e) {
            $db->rollback();
            $error = "Failed to create " . $type . ": " . $e->getMessage();
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
                    <h4 class="mb-0">Create New <?php echo ucfirst($type); ?></h4>
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
                                   value="<?php echo isset($_POST['nama']) ? htmlspecialchars($_POST['nama']) : ''; ?>" 
                                   required>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                                   required>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                            <div class="form-text">Password must be at least 8 characters long.</div>
                        </div>

                        <!-- Role-specific Fields -->
                        <?php if ($type === 'guru'): ?>
                            <div class="mb-3">
                                <label for="nip" class="form-label">NIP</label>
                                <input type="text" class="form-control" id="nip" name="nip" 
                                       value="<?php echo isset($_POST['nip']) ? htmlspecialchars($_POST['nip']) : ''; ?>" 
                                       required pattern="\d{18}" maxlength="18">
                                <div class="form-text">NIP must be exactly 18 digits.</div>
                            </div>
                        <?php else: ?>
                            <div class="mb-3">
                                <label for="nis" class="form-label">NIS</label>
                                <input type="text" class="form-control" id="nis" name="nis" 
                                       value="<?php echo isset($_POST['nis']) ? htmlspecialchars($_POST['nis']) : ''; ?>" 
                                       required pattern="\d{10}" maxlength="10">
                                <div class="form-text">NIS must be exactly 10 digits.</div>
                            </div>

                            <div class="mb-3">
                                <label for="kelas_id" class="form-label">Class</label>
                                <select class="form-select" id="kelas_id" name="kelas_id" required>
                                    <option value="">Select Class</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>" 
                                            <?php echo (isset($_POST['kelas_id']) && $_POST['kelas_id'] == $class['id']) ? 'selected' : ''; ?>>
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
                                   value="<?php echo isset($_POST['telepon']) ? htmlspecialchars($_POST['telepon']) : ''; ?>">
                        </div>

                        <div class="mb-3">
                            <label for="alamat" class="form-label">Address</label>
                            <textarea class="form-control" id="alamat" name="alamat" rows="3"
                                    ><?php echo isset($_POST['alamat']) ? htmlspecialchars($_POST['alamat']) : ''; ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="foto" class="form-label">Photo</label>
                            <input type="file" class="form-control" id="foto" name="foto" accept="image/*">
                            <div class="form-text">Maximum file size: 5MB. Allowed formats: JPG, JPEG, PNG</div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="/web/modules/user/index.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Create <?php echo ucfirst($type); ?></button>
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

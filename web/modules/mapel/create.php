<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth_helper.php';
require_once __DIR__ . '/../../helpers/validation_helper.php';

$auth = new AuthHelper();
$validation = new ValidationHelper();

// Check if user is logged in and has appropriate role
if (!$auth->isLoggedIn() || !$auth->hasRole(['admin', 'guru'])) {
    redirect('/web/index.php', 'Access denied. Insufficient privileges.', 'danger');
}

$page_title = "Create New Subject";
$db = (new Database())->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kode = $validation->sanitizeInput($_POST['kode'] ?? '');
    $nama = $validation->sanitizeInput($_POST['nama'] ?? '');

    $errors = [];

    // Validate input
    if (empty($kode)) $errors[] = "Subject code is required";
    if (empty($nama)) $errors[] = "Subject name is required";

    // Check if subject code already exists
    $stmt = $db->prepare("SELECT id FROM mata_pelajaran WHERE kode = ?");
    $stmt->bind_param("s", $kode);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $errors[] = "Subject code already exists";
    }

    if (empty($errors)) {
        try {
            $stmt = $db->prepare("INSERT INTO mata_pelajaran (kode, nama, dibuat_pada) VALUES (?, ?, NOW())");
            $stmt->bind_param("ss", $kode, $nama);
            $stmt->execute();

            redirect('/web/modules/mapel/index.php', 'Subject created successfully.', 'success');
        } catch (Exception $e) {
            $error = "Failed to create subject: " . $e->getMessage();
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
                    <h4 class="mb-0">Create New Subject</h4>
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

                    <form method="POST" action="" class="needs-validation" novalidate>
                        <div class="mb-3">
                            <label for="kode" class="form-label">Subject Code</label>
                            <input type="text" class="form-control" id="kode" name="kode" 
                                   value="<?php echo isset($_POST['kode']) ? htmlspecialchars($_POST['kode']) : ''; ?>" 
                                   required>
                        </div>

                        <div class="mb-3">
                            <label for="nama" class="form-label">Subject Name</label>
                            <input type="text" class="form-control" id="nama" name="nama" 
                                   value="<?php echo isset($_POST['nama']) ? htmlspecialchars($_POST['nama']) : ''; ?>" 
                                   required>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="/web/modules/mapel/index.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Create Subject</button>
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

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

$db = (new Database())->getConnection();

// Get subject ID from URL
$mapel_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($mapel_id <= 0) {
    redirect('/web/modules/mapel/index.php', 'Invalid subject ID.', 'danger');
}

// Get subject data
$stmt = $db->prepare("SELECT * FROM mata_pelajaran WHERE id = ?");
$stmt->bind_param("i", $mapel_id);
$stmt->execute();
$subject = $stmt->get_result()->fetch_assoc();

if (!$subject) {
    redirect('/web/modules/mapel/index.php', 'Subject not found.', 'danger');
}

$page_title = "Edit Subject";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kode = $validation->sanitizeInput($_POST['kode'] ?? '');
    $nama = $validation->sanitizeInput($_POST['nama'] ?? '');

    $errors = [];

    // Validate input
    if (empty($kode)) $errors[] = "Subject code is required";
    if (empty($nama)) $errors[] = "Subject name is required";

    // Check if subject code already exists (excluding current subject)
    $stmt = $db->prepare("SELECT id FROM mata_pelajaran WHERE kode = ? AND id != ?");
    $stmt->bind_param("si", $kode, $mapel_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $errors[] = "Subject code already exists";
    }

    if (empty($errors)) {
        try {
            $stmt = $db->prepare("UPDATE mata_pelajaran SET kode = ?, nama = ? WHERE id = ?");
            $stmt->bind_param("ssi", $kode, $nama, $mapel_id);
            $stmt->execute();

            redirect('/web/modules/mapel/index.php', 'Subject updated successfully.', 'success');
        } catch (Exception $e) {
            $error = "Failed to update subject: " . $e->getMessage();
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
                    <h4 class="mb-0">Edit Subject</h4>
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
                                   value="<?php echo htmlspecialchars($subject['kode']); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="nama" class="form-label">Subject Name</label>
                            <input type="text" class="form-control" id="nama" name="nama" 
                                   value="<?php echo htmlspecialchars($subject['nama']); ?>" required>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="/web/modules/mapel/index.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Update Subject</button>
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

<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth_helper.php';
require_once __DIR__ . '/../../helpers/validation_helper.php';
require_once __DIR__ . '/../../helpers/file_helper.php';

$auth = new AuthHelper();
$validation = new ValidationHelper();
$fileHelper = new FileHelper();

// Check if user is logged in and has appropriate role
if (!$auth->isLoggedIn() || !$auth->hasRole(['admin', 'guru'])) {
    redirect('/web/index.php', 'Access denied. Insufficient privileges.', 'danger');
}

$db = (new Database())->getConnection();
$user = $auth->getCurrentUser();

// Get material ID from URL
$materi_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($materi_id <= 0) {
    redirect('/web/modules/materi/index.php', 'Invalid material ID.', 'danger');
}

// Get material data
$stmt = $db->prepare("
    SELECT m.*, k.nama as kelas_nama, mp.nama as mata_pelajaran_nama, 
           g.nama as guru_nama, g.nip as guru_nip
    FROM materi m
    JOIN kelas k ON m.kelas_id = k.id
    JOIN mata_pelajaran mp ON m.mata_pelajaran_id = mp.id
    JOIN guru g ON m.guru_id = g.id
    WHERE m.id = ?");
$stmt->bind_param("i", $materi_id);
$stmt->execute();
$material = $stmt->get_result()->fetch_assoc();

if (!$material) {
    redirect('/web/modules/materi/index.php', 'Material not found.', 'danger');
}

// Check if user has permission to edit this material
if ($user['peran'] === 'guru') {
    $stmt = $db->prepare("SELECT id FROM guru WHERE pengguna_id = ? AND id = ?");
    $stmt->bind_param("ii", $user['id'], $material['guru_id']);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        redirect('/web/modules/materi/index.php', 'You can only edit your own materials.', 'danger');
    }
}

$page_title = "Edit Material";

// Get available classes and subjects
$classes_query = "SELECT id, nama FROM kelas ORDER BY nama";
$classes_result = $db->query($classes_query);
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);

$subjects_query = "SELECT id, kode, nama FROM mata_pelajaran ORDER BY nama";
$subjects_result = $db->query($subjects_query);
$subjects = $subjects_result->fetch_all(MYSQLI_ASSOC);

// If user is admin, get all teachers
if ($user['peran'] === 'admin') {
    $teachers_query = "SELECT g.id, g.nip, p.nama FROM guru g JOIN pengguna p ON g.pengguna_id = p.id ORDER BY p.nama";
    $teachers_result = $db->query($teachers_query);
    $teachers = $teachers_result->fetch_all(MYSQLI_ASSOC);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $judul = $validation->sanitizeInput($_POST['judul'] ?? '');
    $deskripsi = $validation->sanitizeInput($_POST['deskripsi'] ?? '');
    $kelas_id = (int)$_POST['kelas_id'] ?? 0;
    $mata_pelajaran_id = (int)$_POST['mata_pelajaran_id'] ?? 0;
    $selected_guru_id = ($user['peran'] === 'admin') ? ((int)$_POST['guru_id'] ?? 0) : $material['guru_id'];

    $errors = [];

    // Validate input
    if (empty($judul)) $errors[] = "Title is required";
    if (empty($deskripsi)) $errors[] = "Description is required";
    if ($kelas_id <= 0) $errors[] = "Class is required";
    if ($mata_pelajaran_id <= 0) $errors[] = "Subject is required";
    if ($selected_guru_id <= 0) $errors[] = "Teacher is required";

    // Handle file upload if new file is provided
    $file_path = $material['file_path'];
    if (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_result = $fileHelper->uploadFile($_FILES['file'], 'materials');
        if (!$upload_result['success']) {
            $errors[] = $upload_result['message'];
        } else {
            // Delete old file if exists
            if (!empty($file_path)) {
                $fileHelper->deleteFile($file_path);
            }
            $file_path = $upload_result['file_path'];
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $db->prepare("
                UPDATE materi 
                SET judul = ?, deskripsi = ?, file_path = ?, kelas_id = ?, 
                    mata_pelajaran_id = ?, guru_id = ?, diperbarui_pada = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param("sssiisi", $judul, $deskripsi, $file_path, 
                            $kelas_id, $mata_pelajaran_id, $selected_guru_id, $materi_id);
            $stmt->execute();

            redirect('/web/modules/materi/index.php', 'Material updated successfully.', 'success');
        } catch (Exception $e) {
            $error = "Failed to update material: " . $e->getMessage();
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
                    <h4 class="mb-0">Edit Material</h4>
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
                        <div class="mb-3">
                            <label for="judul" class="form-label">Title</label>
                            <input type="text" class="form-control" id="judul" name="judul" 
                                   value="<?php echo htmlspecialchars($material['judul']); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="deskripsi" class="form-label">Description</label>
                            <textarea class="form-control" id="deskripsi" name="deskripsi" rows="4" required
                                    ><?php echo htmlspecialchars($material['deskripsi']); ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="kelas_id" class="form-label">Class</label>
                            <select class="form-select" id="kelas_id" name="kelas_id" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" 
                                        <?php echo $material['kelas_id'] == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['nama']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="mata_pelajaran_id" class="form-label">Subject</label>
                            <select class="form-select" id="mata_pelajaran_id" name="mata_pelajaran_id" required>
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>" 
                                        <?php echo $material['mata_pelajaran_id'] == $subject['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['kode'] . ' - ' . $subject['nama']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($user['peran'] === 'admin'): ?>
                            <div class="mb-3">
                                <label for="guru_id" class="form-label">Teacher</label>
                                <select class="form-select" id="guru_id" name="guru_id" required>
                                    <option value="">Select Teacher</option>
                                    <?php foreach ($teachers as $teacher): ?>
                                        <option value="<?php echo $teacher['id']; ?>" 
                                            <?php echo $material['guru_id'] == $teacher['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($teacher['nama'] . ' (' . $teacher['nip'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="file" class="form-label">Material File</label>
                            <?php if (!empty($material['file_path'])): ?>
                                <div class="mb-2">
                                    <strong>Current file:</strong>
                                    <a href="/web/assets/uploads/<?php echo htmlspecialchars($material['file_path']); ?>" 
                                       target="_blank">
                                        Download
                                    </a>
                                </div>
                            <?php endif; ?>
                            <input type="file" class="form-control" id="file" name="file">
                            <div class="form-text">
                                Maximum file size: 5MB. Allowed formats: PDF, DOC, DOCX, JPG, JPEG, PNG<br>
                                Leave empty to keep the current file.
                            </div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="/web/modules/materi/index.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Update Material</button>
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

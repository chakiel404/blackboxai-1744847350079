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

// Get assignment ID from URL
$tugas_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($tugas_id <= 0) {
    redirect('/web/modules/tugas/index.php', 'Invalid assignment ID.', 'danger');
}

// Get assignment data
$stmt = $db->prepare("
    SELECT t.*, k.nama as kelas_nama, mp.nama as mata_pelajaran_nama, 
           g.nama as guru_nama, g.nip as guru_nip
    FROM tugas t
    JOIN kelas k ON t.kelas_id = k.id
    JOIN mata_pelajaran mp ON t.mata_pelajaran_id = mp.id
    JOIN guru g ON t.guru_id = g.id
    WHERE t.id = ?");
$stmt->bind_param("i", $tugas_id);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc();

if (!$assignment) {
    redirect('/web/modules/tugas/index.php', 'Assignment not found.', 'danger');
}

// Check if user has permission to edit this assignment
if ($user['peran'] === 'guru') {
    $stmt = $db->prepare("SELECT id FROM guru WHERE pengguna_id = ? AND id = ?");
    $stmt->bind_param("ii", $user['id'], $assignment['guru_id']);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        redirect('/web/modules/tugas/index.php', 'You can only edit your own assignments.', 'danger');
    }
}

$page_title = "Edit Assignment";

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
    $selected_guru_id = ($user['peran'] === 'admin') ? ((int)$_POST['guru_id'] ?? 0) : $assignment['guru_id'];
    $tenggat_waktu = $validation->sanitizeInput($_POST['tenggat_waktu'] ?? '');

    $errors = [];

    // Validate input
    if (empty($judul)) $errors[] = "Title is required";
    if (empty($deskripsi)) $errors[] = "Description is required";
    if ($kelas_id <= 0) $errors[] = "Class is required";
    if ($mata_pelajaran_id <= 0) $errors[] = "Subject is required";
    if ($selected_guru_id <= 0) $errors[] = "Teacher is required";
    if (empty($tenggat_waktu)) $errors[] = "Due date is required";

    // Validate due date is in the future if not already past
    if (!empty($tenggat_waktu)) {
        $due_date = new DateTime($tenggat_waktu);
        $now = new DateTime();
        $original_due_date = new DateTime($assignment['tenggat_waktu']);
        
        if ($due_date < $original_due_date && $original_due_date > $now) {
            $errors[] = "New due date cannot be earlier than the original due date";
        }
    }

    // Handle file upload if new file is provided
    $file_path = $assignment['file_path'];
    if (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_result = $fileHelper->uploadFile($_FILES['file'], 'assignments');
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
                UPDATE tugas 
                SET judul = ?, deskripsi = ?, file_path = ?, kelas_id = ?, 
                    mata_pelajaran_id = ?, guru_id = ?, tenggat_waktu = ?, diperbarui_pada = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param("sssiissi", $judul, $deskripsi, $file_path, 
                            $kelas_id, $mata_pelajaran_id, $selected_guru_id, $tenggat_waktu, $tugas_id);
            $stmt->execute();

            redirect('/web/modules/tugas/index.php', 'Assignment updated successfully.', 'success');
        } catch (Exception $e) {
            $error = "Failed to update assignment: " . $e->getMessage();
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
                    <h4 class="mb-0">Edit Assignment</h4>
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
                                   value="<?php echo htmlspecialchars($assignment['judul']); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="deskripsi" class="form-label">Description</label>
                            <textarea class="form-control" id="deskripsi" name="deskripsi" rows="4" required
                                    ><?php echo htmlspecialchars($assignment['deskripsi']); ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="kelas_id" class="form-label">Class</label>
                            <select class="form-select" id="kelas_id" name="kelas_id" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" 
                                        <?php echo $assignment['kelas_id'] == $class['id'] ? 'selected' : ''; ?>>
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
                                        <?php echo $assignment['mata_pelajaran_id'] == $subject['id'] ? 'selected' : ''; ?>>
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
                                            <?php echo $assignment['guru_id'] == $teacher['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($teacher['nama'] . ' (' . $teacher['nip'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="tenggat_waktu" class="form-label">Due Date</label>
                            <input type="datetime-local" class="form-control" id="tenggat_waktu" name="tenggat_waktu" 
                                   value="<?php echo date('Y-m-d\TH:i', strtotime($assignment['tenggat_waktu'])); ?>" 
                                   required>
                        </div>

                        <div class="mb-3">
                            <label for="file" class="form-label">Assignment File</label>
                            <?php if (!empty($assignment['file_path'])): ?>
                                <div class="mb-2">
                                    <strong>Current file:</strong>
                                    <a href="/web/assets/uploads/<?php echo htmlspecialchars($assignment['file_path']); ?>" 
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
                            <a href="/web/modules/tugas/index.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Update Assignment</button>
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

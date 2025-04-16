<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth_helper.php';
require_once __DIR__ . '/../../helpers/validation_helper.php';
require_once __DIR__ . '/../../helpers/file_helper.php';

$auth = new AuthHelper();
$validation = new ValidationHelper();
$fileHelper = new FileHelper();

// Check if user is logged in and is a student
if (!$auth->isLoggedIn() || !$auth->hasRole('siswa')) {
    redirect('/web/index.php', 'Access denied. Student privileges required.', 'danger');
}

$db = (new Database())->getConnection();
$user = $auth->getCurrentUser();

// Get assignment ID from URL
$tugas_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($tugas_id <= 0) {
    redirect('/web/modules/tugas/index.php', 'Invalid assignment ID.', 'danger');
}

// Get student ID
$stmt = $db->prepare("SELECT id, kelas_id FROM siswa WHERE pengguna_id = ?");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    redirect('/web/modules/tugas/index.php', 'Student record not found.', 'danger');
}

// Get assignment data
$stmt = $db->prepare("
    SELECT t.*, k.nama as kelas_nama, mp.nama as mata_pelajaran_nama, 
           g.nama as guru_nama
    FROM tugas t
    JOIN kelas k ON t.kelas_id = k.id
    JOIN mata_pelajaran mp ON t.mata_pelajaran_id = mp.id
    JOIN guru g ON t.guru_id = g.id
    WHERE t.id = ? AND t.kelas_id = ?");
$stmt->bind_param("ii", $tugas_id, $student['kelas_id']);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc();

if (!$assignment) {
    redirect('/web/modules/tugas/index.php', 'Assignment not found or not accessible.', 'danger');
}

// Get current submission if exists
$stmt = $db->prepare("
    SELECT * FROM pengumpulan_tugas 
    WHERE tugas_id = ? AND siswa_id = ?");
$stmt->bind_param("ii", $tugas_id, $student['id']);
$stmt->execute();
$submission = $stmt->get_result()->fetch_assoc();

$page_title = "Submit Assignment";

// Check if assignment is past due
$now = new DateTime();
$due_date = new DateTime($assignment['tenggat_waktu']);
$is_overdue = $due_date < $now;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_overdue) {
    $catatan = $validation->sanitizeInput($_POST['catatan'] ?? '');
    $errors = [];

    // Handle file upload
    $file_path = $submission['file_path'] ?? null;
    if (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_result = $fileHelper->uploadFile($_FILES['file'], 'submissions');
        if (!$upload_result['success']) {
            $errors[] = $upload_result['message'];
        } else {
            // Delete old file if exists
            if (!empty($file_path)) {
                $fileHelper->deleteFile($file_path);
            }
            $file_path = $upload_result['file_path'];
        }
    } elseif (!$submission && empty($file_path)) {
        $errors[] = "File submission is required";
    }

    if (empty($errors)) {
        try {
            if ($submission) {
                // Update existing submission
                $stmt = $db->prepare("
                    UPDATE pengumpulan_tugas 
                    SET file_path = ?, catatan = ?, status = 'submitted', diperbarui_pada = NOW()
                    WHERE id = ?");
                $stmt->bind_param("ssi", $file_path, $catatan, $submission['id']);
            } else {
                // Create new submission
                $stmt = $db->prepare("
                    INSERT INTO pengumpulan_tugas (tugas_id, siswa_id, file_path, catatan, status, dibuat_pada) 
                    VALUES (?, ?, ?, ?, 'submitted', NOW())");
                $stmt->bind_param("iiss", $tugas_id, $student['id'], $file_path, $catatan);
            }
            $stmt->execute();

            redirect('/web/modules/tugas/index.php', 'Assignment submitted successfully.', 'success');
        } catch (Exception $e) {
            // Delete uploaded file if database insertion fails
            if ($file_path && $file_path !== ($submission['file_path'] ?? null)) {
                $fileHelper->deleteFile($file_path);
            }
            $error = "Failed to submit assignment: " . $e->getMessage();
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
                    <h4 class="mb-0">Submit Assignment</h4>
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

                    <!-- Assignment Details -->
                    <div class="mb-4">
                        <h5><?php echo htmlspecialchars($assignment['judul']); ?></h5>
                        <div class="text-muted mb-2">
                            <small>
                                <?php echo htmlspecialchars($assignment['mata_pelajaran_nama']); ?> | 
                                <?php echo htmlspecialchars($assignment['kelas_nama']); ?> | 
                                Teacher: <?php echo htmlspecialchars($assignment['guru_nama']); ?>
                            </small>
                        </div>
                        <div class="mb-3">
                            <?php echo nl2br(htmlspecialchars($assignment['deskripsi'])); ?>
                        </div>
                        <?php if (!empty($assignment['file_path'])): ?>
                            <div class="mb-3">
                                <strong>Assignment File:</strong>
                                <a href="/web/assets/uploads/<?php echo htmlspecialchars($assignment['file_path']); ?>" 
                                   target="_blank" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-download me-1"></i>Download
                                </a>
                            </div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <strong>Due Date:</strong>
                            <span class="<?php echo $is_overdue ? 'text-danger' : ''; ?>">
                                <?php echo $due_date->format('d/m/Y H:i'); ?>
                                <?php if ($is_overdue): ?>
                                    (Overdue)
                                <?php else: ?>
                                    (<?php
                                    $interval = $now->diff($due_date);
                                    if ($interval->days > 0) {
                                        echo $interval->format('%d days %h hours remaining');
                                    } else {
                                        echo $interval->format('%h hours %i minutes remaining');
                                    }
                                    ?>)
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>

                    <?php if ($is_overdue): ?>
                        <div class="alert alert-danger">
                            This assignment is past due and no longer accepts submissions.
                        </div>
                    <?php else: ?>
                        <form method="POST" action="" enctype="multipart/form-data" class="needs-validation" novalidate>
                            <div class="mb-3">
                                <label for="file" class="form-label">Submission File</label>
                                <?php if ($submission && !empty($submission['file_path'])): ?>
                                    <div class="mb-2">
                                        <strong>Current submission:</strong>
                                        <a href="/web/assets/uploads/<?php echo htmlspecialchars($submission['file_path']); ?>" 
                                           target="_blank">
                                            Download
                                        </a>
                                        <br>
                                        <small class="text-muted">
                                            Submitted: <?php echo date('d/m/Y H:i', strtotime($submission['dibuat_pada'])); ?>
                                            <?php if ($submission['diperbarui_pada']): ?>
                                                <br>Last updated: <?php echo date('d/m/Y H:i', strtotime($submission['diperbarui_pada'])); ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                                <input type="file" class="form-control" id="file" name="file" 
                                       <?php echo !$submission ? 'required' : ''; ?>>
                                <div class="form-text">
                                    Maximum file size: 5MB. Allowed formats: PDF, DOC, DOCX, JPG, JPEG, PNG<br>
                                    <?php if ($submission): ?>
                                        Leave empty to keep the current file.
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="catatan" class="form-label">Notes (Optional)</label>
                                <textarea class="form-control" id="catatan" name="catatan" rows="3"
                                        ><?php echo htmlspecialchars($submission['catatan'] ?? ''); ?></textarea>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="/web/modules/tugas/index.php" class="btn btn-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary">
                                    <?php echo $submission ? 'Update Submission' : 'Submit Assignment'; ?>
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
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

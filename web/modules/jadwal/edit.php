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

// Get schedule ID from URL
$jadwal_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($jadwal_id <= 0) {
    redirect('/web/modules/jadwal/index.php', 'Invalid schedule ID.', 'danger');
}

// Get schedule data
$stmt = $db->prepare("
    SELECT j.*, k.nama as kelas_nama, mp.nama as mata_pelajaran_nama, 
           g.nama as guru_nama, g.nip as guru_nip
    FROM jadwal j
    JOIN kelas k ON j.kelas_id = k.id
    JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id
    JOIN guru g ON j.guru_id = g.id
    WHERE j.id = ?");
$stmt->bind_param("i", $jadwal_id);
$stmt->execute();
$schedule = $stmt->get_result()->fetch_assoc();

if (!$schedule) {
    redirect('/web/modules/jadwal/index.php', 'Schedule not found.', 'danger');
}

$page_title = "Edit Schedule";

// Get available classes, subjects, and teachers
$classes_query = "SELECT id, nama FROM kelas ORDER BY nama";
$classes_result = $db->query($classes_query);
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);

$subjects_query = "SELECT id, kode, nama FROM mata_pelajaran ORDER BY nama";
$subjects_result = $db->query($subjects_query);
$subjects = $subjects_result->fetch_all(MYSQLI_ASSOC);

$teachers_query = "SELECT g.id, g.nip, p.nama FROM guru g JOIN pengguna p ON g.pengguna_id = p.id ORDER BY p.nama";
$teachers_result = $db->query($teachers_query);
$teachers = $teachers_result->fetch_all(MYSQLI_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kelas_id = (int)$_POST['kelas_id'] ?? 0;
    $mata_pelajaran_id = (int)$_POST['mata_pelajaran_id'] ?? 0;
    $guru_id = (int)$_POST['guru_id'] ?? 0;
    $hari = $validation->sanitizeInput($_POST['hari'] ?? '');
    $waktu_mulai = $validation->sanitizeInput($_POST['waktu_mulai'] ?? '');
    $waktu_selesai = $validation->sanitizeInput($_POST['waktu_selesai'] ?? '');

    $errors = [];

    // Validate input
    if ($kelas_id <= 0) $errors[] = "Class is required";
    if ($mata_pelajaran_id <= 0) $errors[] = "Subject is required";
    if ($guru_id <= 0) $errors[] = "Teacher is required";
    if (empty($hari)) $errors[] = "Day is required";
    if (empty($waktu_mulai)) $errors[] = "Start time is required";
    if (empty($waktu_selesai)) $errors[] = "End time is required";

    if (!empty($waktu_mulai) && !empty($waktu_selesai)) {
        $start = strtotime($waktu_mulai);
        $end = strtotime($waktu_selesai);
        if ($start >= $end) {
            $errors[] = "End time must be after start time";
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $db->prepare("
                UPDATE jadwal 
                SET kelas_id = ?, mata_pelajaran_id = ?, guru_id = ?, hari = ?, 
                    waktu_mulai = ?, waktu_selesai = ?, diperbarui_pada = NOW() 
                WHERE id = ?");
            $stmt->bind_param("iiissssi", $kelas_id, $mata_pelajaran_id, $guru_id, $hari, $waktu_mulai, $waktu_selesai, $jadwal_id);
            $stmt->execute();

            redirect('/web/modules/jadwal/index.php', 'Schedule updated successfully.', 'success');
        } catch (Exception $e) {
            $error = "Failed to update schedule: " . $e->getMessage();
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
                    <h4 class="mb-0">Edit Schedule</h4>
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
                            <label for="kelas_id" class="form-label">Class</label>
                            <select class="form-select" id="kelas_id" name="kelas_id" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" 
                                        <?php echo $schedule['kelas_id'] == $class['id'] ? 'selected' : ''; ?>>
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
                                        <?php echo $schedule['mata_pelajaran_id'] == $subject['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['kode'] . ' - ' . $subject['nama']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="guru_id" class="form-label">Teacher</label>
                            <select class="form-select" id="guru_id" name="guru_id" required>
                                <option value="">Select Teacher</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?php echo $teacher['id']; ?>" 
                                        <?php echo $schedule['guru_id'] == $teacher['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($teacher['nama'] . ' (' . $teacher['nip'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="hari" class="form-label">Day</label>
                            <select class="form-select" id="hari" name="hari" required>
                                <option value="">Select Day</option>
                                <option value="Senin" <?php echo $schedule['hari'] === 'Senin' ? 'selected' : ''; ?>>Monday</option>
                                <option value="Selasa" <?php echo $schedule['hari'] === 'Selasa' ? 'selected' : ''; ?>>Tuesday</option>
                                <option value="Rabu" <?php echo $schedule['hari'] === 'Rabu' ? 'selected' : ''; ?>>Wednesday</option>
                                <option value="Kamis" <?php echo $schedule['hari'] === 'Kamis' ? 'selected' : ''; ?>>Thursday</option>
                                <option value="Jumat" <?php echo $schedule['hari'] === 'Jumat' ? 'selected' : ''; ?>>Friday</option>
                                <option value="Sabtu" <?php echo $schedule['hari'] === 'Sabtu' ? 'selected' : ''; ?>>Saturday</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="waktu_mulai" class="form-label">Start Time</label>
                            <input type="time" class="form-control" id="waktu_mulai" name="waktu_mulai" 
                                   value="<?php echo htmlspecialchars($schedule['waktu_mulai']); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="waktu_selesai" class="form-label">End Time</label>
                            <input type="time" class="form-control" id="waktu_selesai" name="waktu_selesai" 
                                   value="<?php echo htmlspecialchars($schedule['waktu_selesai']); ?>" required>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="/web/modules/jadwal/index.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Update Schedule</button>
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

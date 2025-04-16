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

// Get class ID from URL
$kelas_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($kelas_id <= 0) {
    redirect('/web/modules/kelas/index.php', 'Invalid class ID.', 'danger');
}

// Get class data
$stmt = $db->prepare("SELECT * FROM kelas WHERE id = ?");
$stmt->bind_param("i", $kelas_id);
$stmt->execute();
$class = $stmt->get_result()->fetch_assoc();

if (!$class) {
    redirect('/web/modules/kelas/index.php', 'Class not found.', 'danger');
}

// Get current schedules
$stmt = $db->prepare("
    SELECT j.*, mp.nama as mata_pelajaran_nama, mp.kode as mata_pelajaran_kode, 
           g.id as guru_id, p.nama as guru_nama, g.nip as guru_nip
    FROM jadwal j
    JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id
    JOIN guru g ON j.guru_id = g.id
    JOIN pengguna p ON g.pengguna_id = p.id
    WHERE j.kelas_id = ?
    ORDER BY FIELD(j.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'),
             j.waktu_mulai");
$stmt->bind_param("i", $kelas_id);
$stmt->execute();
$schedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get available teachers and subjects
$teachers_query = "SELECT g.id, g.nip, p.nama FROM guru g JOIN pengguna p ON g.pengguna_id = p.id ORDER BY p.nama";
$teachers_result = $db->query($teachers_query);
$teachers = $teachers_result->fetch_all(MYSQLI_ASSOC);

$subjects_query = "SELECT id, kode, nama FROM mata_pelajaran ORDER BY nama";
$subjects_result = $db->query($subjects_query);
$subjects = $subjects_result->fetch_all(MYSQLI_ASSOC);

$page_title = "Edit Class: " . $class['tingkat'] . ' ' . $class['nama'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama = $validation->sanitizeInput($_POST['nama'] ?? '');
    $tingkat = $validation->sanitizeInput($_POST['tingkat'] ?? '');
    $jadwal = $_POST['jadwal'] ?? [];
    $delete_schedule = $_POST['delete_schedule'] ?? [];

    $errors = [];

    // Validate basic class info
    if (empty($nama)) $errors[] = "Class name is required";
    if (empty($tingkat)) $errors[] = "Grade level is required";

    // Validate schedule entries
    foreach ($jadwal as $idx => $entry) {
        if (empty($entry['guru_id'])) $errors[] = "Teacher is required for schedule entry #" . ($idx + 1);
        if (empty($entry['mata_pelajaran_id'])) $errors[] = "Subject is required for schedule entry #" . ($idx + 1);
        if (empty($entry['hari'])) $errors[] = "Day is required for schedule entry #" . ($idx + 1);
        if (empty($entry['waktu_mulai'])) $errors[] = "Start time is required for schedule entry #" . ($idx + 1);
        if (empty($entry['waktu_selesai'])) $errors[] = "End time is required for schedule entry #" . ($idx + 1);
        
        if (!empty($entry['waktu_mulai']) && !empty($entry['waktu_selesai'])) {
            $start = strtotime($entry['waktu_mulai']);
            $end = strtotime($entry['waktu_selesai']);
            if ($start >= $end) {
                $errors[] = "End time must be after start time for schedule entry #" . ($idx + 1);
            }
        }
    }

    // Check if class name already exists in the same grade (excluding current class)
    $stmt = $db->prepare("SELECT id FROM kelas WHERE nama = ? AND tingkat = ? AND id != ?");
    $stmt->bind_param("ssi", $nama, $tingkat, $kelas_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $errors[] = "A class with this name already exists in the selected grade";
    }

    if (empty($errors)) {
        try {
            $db->begin_transaction();

            // Update class
            $stmt = $db->prepare("UPDATE kelas SET nama = ?, tingkat = ?, diperbarui_pada = NOW() WHERE id = ?");
            $stmt->bind_param("ssi", $nama, $tingkat, $kelas_id);
            $stmt->execute();

            // Delete removed schedules
            if (!empty($delete_schedule)) {
                $delete_ids = implode(',', array_map('intval', $delete_schedule));
                $db->query("DELETE FROM jadwal WHERE id IN ($delete_ids) AND kelas_id = $kelas_id");
            }

            // Update existing and add new schedules
            $stmt = $db->prepare("
                INSERT INTO jadwal (
                    id, kelas_id, mata_pelajaran_id, guru_id, hari, 
                    waktu_mulai, waktu_selesai, semester, dibuat_pada, diperbarui_pada
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    mata_pelajaran_id = VALUES(mata_pelajaran_id),
                    guru_id = VALUES(guru_id),
                    hari = VALUES(hari),
                    waktu_mulai = VALUES(waktu_mulai),
                    waktu_selesai = VALUES(waktu_selesai),
                    diperbarui_pada = NOW()
            ");

            foreach ($jadwal as $entry) {
                $id = !empty($entry['id']) ? $entry['id'] : null;
                $semester = 'Ganjil ' . date('Y') . '/' . (date('Y') + 1); // Current academic year
                $stmt->bind_param(
                    "iiisssss",
                    $id,
                    $kelas_id,
                    $entry['mata_pelajaran_id'],
                    $entry['guru_id'],
                    $entry['hari'],
                    $entry['waktu_mulai'],
                    $entry['waktu_selesai'],
                    $semester
                );
                $stmt->execute();
            }

            $db->commit();
            redirect('/web/modules/kelas/index.php', 'Class updated successfully.', 'success');

        } catch (Exception $e) {
            $db->rollback();
            $error = "Failed to update class: " . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0">Edit Class</h4>
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
                        <!-- Basic Class Information -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="tingkat" class="form-label">Grade Level</label>
                                    <select class="form-select" id="tingkat" name="tingkat" required>
                                        <option value="">Select Grade Level</option>
                                        <option value="X" <?php echo $class['tingkat'] === 'X' ? 'selected' : ''; ?>>X (10th Grade)</option>
                                        <option value="XI" <?php echo $class['tingkat'] === 'XI' ? 'selected' : ''; ?>>XI (11th Grade)</option>
                                        <option value="XII" <?php echo $class['tingkat'] === 'XII' ? 'selected' : ''; ?>>XII (12th Grade)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="nama" class="form-label">Class Name</label>
                                    <input type="text" class="form-control" id="nama" name="nama" 
                                           value="<?php echo htmlspecialchars($class['nama']); ?>" required>
                                    <div class="form-text">Example: IPA-1, IPS-2, etc.</div>
                                </div>
                            </div>
                        </div>

                        <!-- Class Schedule -->
                        <h5 class="mb-3">Class Schedule</h5>
                        <div id="scheduleEntries">
                            <?php foreach ($schedules as $index => $schedule): ?>
                                <div class="schedule-entry card mb-3">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between mb-3">
                                            <h6 class="card-title">Schedule Entry #<?php echo $index + 1; ?></h6>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="removeScheduleEntry(this)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                        <input type="hidden" name="jadwal[<?php echo $index; ?>][id]" value="<?php echo $schedule['id']; ?>">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Subject</label>
                                                    <select class="form-select" name="jadwal[<?php echo $index; ?>][mata_pelajaran_id]" required>
                                                        <option value="">Select Subject</option>
                                                        <?php foreach ($subjects as $subject): ?>
                                                            <option value="<?php echo $subject['id']; ?>" 
                                                                <?php echo $schedule['mata_pelajaran_id'] == $subject['id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($subject['kode'] . ' - ' . $subject['nama']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Teacher</label>
                                                    <select class="form-select" name="jadwal[<?php echo $index; ?>][guru_id]" required>
                                                        <option value="">Select Teacher</option>
                                                        <?php foreach ($teachers as $teacher): ?>
                                                            <option value="<?php echo $teacher['id']; ?>" 
                                                                <?php echo $schedule['guru_id'] == $teacher['id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($teacher['nama'] . ' (' . $teacher['nip'] . ')'); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Day</label>
                                                    <select class="form-select" name="jadwal[<?php echo $index; ?>][hari]" required>
                                                        <option value="">Select Day</option>
                                                        <?php
                                                        $days = ['Senin' => 'Monday', 'Selasa' => 'Tuesday', 'Rabu' => 'Wednesday',
                                                                'Kamis' => 'Thursday', 'Jumat' => 'Friday', 'Sabtu' => 'Saturday'];
                                                        foreach ($days as $value => $label):
                                                        ?>
                                                            <option value="<?php echo $value; ?>" 
                                                                <?php echo $schedule['hari'] === $value ? 'selected' : ''; ?>>
                                                                <?php echo $label; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Start Time</label>
                                                    <input type="time" class="form-control" 
                                                           name="jadwal[<?php echo $index; ?>][waktu_mulai]" 
                                                           value="<?php echo $schedule['waktu_mulai']; ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">End Time</label>
                                                    <input type="time" class="form-control" 
                                                           name="jadwal[<?php echo $index; ?>][waktu_selesai]" 
                                                           value="<?php echo $schedule['waktu_selesai']; ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                        <input type="hidden" name="delete_schedule[]" value="<?php echo $schedule['id']; ?>" disabled>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <button type="button" class="btn btn-secondary mb-4" onclick="addScheduleEntry()">
                            <i class="fas fa-plus me-2"></i>Add Schedule Entry
                        </button>

                        <div class="d-flex justify-content-between">
                            <a href="/web/modules/kelas/index.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Update Class</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Schedule Entry Template -->
<template id="scheduleEntryTemplate">
    <div class="schedule-entry card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between mb-3">
                <h6 class="card-title">Schedule Entry #<span class="entry-number"></span></h6>
                <button type="button" class="btn btn-danger btn-sm" onclick="removeScheduleEntry(this)">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Subject</label>
                        <select class="form-select" name="jadwal[idx][mata_pelajaran_id]" required>
                            <option value="">Select Subject</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>">
                                    <?php echo htmlspecialchars($subject['kode'] . ' - ' . $subject['nama']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Teacher</label>
                        <select class="form-select" name="jadwal[idx][guru_id]" required>
                            <option value="">Select Teacher</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?php echo $teacher['id']; ?>">
                                    <?php echo htmlspecialchars($teacher['nama'] . ' (' . $teacher['nip'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="mb-3">
                        <label class="form-label">Day</label>
                        <select class="form-select" name="jadwal[idx][hari]" required>
                            <option value="">Select Day</option>
                            <option value="Senin">Monday</option>
                            <option value="Selasa">Tuesday</option>
                            <option value="Rabu">Wednesday</option>
                            <option value="Kamis">Thursday</option>
                            <option value="Jumat">Friday</option>
                            <option value="Sabtu">Saturday</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="mb-3">
                        <label class="form-label">Start Time</label>
                        <input type="time" class="form-control" name="jadwal[idx][waktu_mulai]" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="mb-3">
                        <label class="form-label">End Time</label>
                        <input type="time" class="form-control" name="jadwal[idx][waktu_selesai]" required>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
let scheduleEntryCount = <?php echo count($schedules); ?>;

function addScheduleEntry() {
    const template = document.getElementById('scheduleEntryTemplate');
    const container = document.getElementById('scheduleEntries');
    const clone = template.content.cloneNode(true);
    
    // Update entry number and names
    scheduleEntryCount++;
    clone.querySelector('.entry-number').textContent = scheduleEntryCount;
    
    // Update form field names
    clone.querySelectorAll('[name*="jadwal[idx]"]').forEach(field => {
        field.name = field.name.replace('idx', scheduleEntryCount - 1);
    });
    
    container.appendChild(clone);
}

function removeScheduleEntry(button) {
    const entry = button.closest('.schedule-entry');
    const deleteInput = entry.querySelector('input[name^="delete_schedule"]');
    
    if (deleteInput) {
        // Existing schedule - mark for deletion
        deleteInput.disabled = false;
        entry.style.display = 'none';
    } else {
        // New schedule - remove from DOM
        entry.remove();
        updateEntryNumbers();
    }
}

function updateEntryNumbers() {
    document.querySelectorAll('.schedule-entry:not([style*="display: none"])').forEach((entry, index) => {
        entry.querySelector('.entry-number').textContent = index + 1;
        entry.querySelectorAll('[name*="jadwal["]').forEach(field => {
            field.name = field.name.replace(/jadwal\[\d+\]/, `jadwal[${index}]`);
        });
    });
}

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

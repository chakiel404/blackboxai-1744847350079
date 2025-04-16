<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth_helper.php';
require_once __DIR__ . '/../../helpers/file_helper.php';

$auth = new AuthHelper();
$fileHelper = new FileHelper();

// Check if user is logged in
if (!$auth->isLoggedIn()) {
    redirect('/web/index.php', 'Access denied. Please login first.', 'danger');
}

$page_title = "Assignments";
$db = (new Database())->getConnection();
$user = $auth->getCurrentUser();

// Handle assignment deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_assignment'])) {
    if (!$auth->hasRole(['admin', 'guru'])) {
        redirect('/web/modules/tugas/index.php', 'Access denied. Insufficient privileges.', 'danger');
    }

    $tugas_id = (int)$_POST['tugas_id'];
    try {
        $db->begin_transaction();

        // Get file paths before deletion
        $stmt = $db->prepare("SELECT file_path FROM tugas WHERE id = ?");
        $stmt->bind_param("i", $tugas_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $assignment = $result->fetch_assoc();

        // Delete files from submissions
        $stmt = $db->prepare("SELECT file_path FROM pengumpulan_tugas WHERE tugas_id = ?");
        $stmt->bind_param("i", $tugas_id);
        $stmt->execute();
        $submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Delete assignment file if exists
        if ($assignment && !empty($assignment['file_path'])) {
            $fileHelper->deleteFile($assignment['file_path']);
        }

        // Delete submission files
        foreach ($submissions as $submission) {
            if (!empty($submission['file_path'])) {
                $fileHelper->deleteFile($submission['file_path']);
            }
        }

        // Delete assignment and related submissions (cascade)
        $stmt = $db->prepare("DELETE FROM tugas WHERE id = ?");
        $stmt->bind_param("i", $tugas_id);
        $stmt->execute();

        $db->commit();
        redirect('/web/modules/tugas/index.php', 'Assignment deleted successfully.', 'success');
    } catch (Exception $e) {
        $db->rollback();
        $error = "Failed to delete assignment: " . $e->getMessage();
    }
}

// Get assignments based on user role
$query = "
    SELECT t.*, mp.nama as mata_pelajaran_nama, k.nama as kelas_nama, 
           p.nama as guru_nama,
           (SELECT COUNT(*) FROM pengumpulan_tugas pt WHERE pt.tugas_id = t.id) as submission_count
    FROM tugas t
    JOIN mata_pelajaran mp ON t.mata_pelajaran_id = mp.id
    JOIN kelas k ON t.kelas_id = k.id
    JOIN guru g ON t.guru_id = g.id
    JOIN pengguna p ON g.pengguna_id = p.id
    WHERE 1=1";

if ($user['peran'] === 'guru') {
    $query .= " AND t.guru_id = (SELECT id FROM guru WHERE pengguna_id = ?)";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $user['id']);
} elseif ($user['peran'] === 'siswa') {
    $query .= " AND t.kelas_id = (SELECT kelas_id FROM siswa WHERE pengguna_id = ?)";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $user['id']);
} else {
    $stmt = $db->prepare($query);
}

$stmt->execute();
$assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// For students, get their submission status for each assignment
if ($user['peran'] === 'siswa') {
    $stmt = $db->prepare("SELECT id FROM siswa WHERE pengguna_id = ?");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $siswa = $stmt->get_result()->fetch_assoc();
    $siswa_id = $siswa['id'];

    foreach ($assignments as &$assignment) {
        $stmt = $db->prepare("
            SELECT id, status, nilai, diperbarui_pada 
            FROM pengumpulan_tugas 
            WHERE tugas_id = ? AND siswa_id = ?");
        $stmt->bind_param("ii", $assignment['id'], $siswa_id);
        $stmt->execute();
        $submission = $stmt->get_result()->fetch_assoc();
        $assignment['submission'] = $submission;
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Assignments</h2>
        <?php if ($auth->hasRole(['admin', 'guru'])): ?>
            <a href="create.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Add New Assignment
            </a>
        <?php endif; ?>
    </div>

    <?php echo display_flash_message(); ?>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="assignmentsTable">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Subject</th>
                            <th>Class</th>
                            <th>Teacher</th>
                            <th>Due Date</th>
                            <?php if ($user['peran'] === 'siswa'): ?>
                                <th>Status</th>
                                <th>Grade</th>
                            <?php else: ?>
                                <th>Submissions</th>
                            <?php endif; ?>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $assignment): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($assignment['judul']); ?></td>
                                <td><?php echo htmlspecialchars($assignment['mata_pelajaran_nama']); ?></td>
                                <td><?php echo htmlspecialchars($assignment['kelas_nama']); ?></td>
                                <td><?php echo htmlspecialchars($assignment['guru_nama']); ?></td>
                                <td>
                                    <?php 
                                    $due_date = new DateTime($assignment['tenggat_waktu']);
                                    $now = new DateTime();
                                    $is_overdue = $due_date < $now;
                                    ?>
                                    <span class="<?php echo $is_overdue ? 'text-danger' : ''; ?>">
                                        <?php echo $due_date->format('d/m/Y H:i'); ?>
                                    </span>
                                </td>
                                <?php if ($user['peran'] === 'siswa'): ?>
                                    <td>
                                        <?php if (isset($assignment['submission'])): ?>
                                            <span class="badge bg-<?php 
                                                echo $assignment['submission']['status'] === 'submitted' ? 'success' : 
                                                    ($assignment['submission']['status'] === 'graded' ? 'info' : 'warning'); 
                                            ?>">
                                                <?php echo ucfirst($assignment['submission']['status']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Not Submitted</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if (isset($assignment['submission']) && 
                                            $assignment['submission']['status'] === 'graded') {
                                            echo $assignment['submission']['nilai'];
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                <?php else: ?>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo $assignment['submission_count']; ?> submissions
                                        </span>
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <div class="btn-group">
                                        <?php if ($user['peran'] === 'siswa'): ?>
                                            <a href="submit.php?id=<?php echo $assignment['id']; ?>" 
                                               class="btn btn-sm btn-primary" 
                                               title="Submit Assignment">
                                                <i class="fas fa-upload"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="view.php?id=<?php echo $assignment['id']; ?>" 
                                               class="btn btn-sm btn-info" 
                                               title="View Submissions">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($auth->hasRole(['admin', 'guru'])): ?>
                                                <a href="edit.php?id=<?php echo $assignment['id']; ?>" 
                                                   class="btn btn-sm btn-warning" 
                                                   title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button type="button" 
                                                        class="btn btn-sm btn-danger" 
                                                        onclick="confirmDelete(<?php echo $assignment['id']; ?>, '<?php echo htmlspecialchars($assignment['judul']); ?>')"
                                                        title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete assignment <span id="deleteAssignmentName"></span>?
                This action cannot be undone and will delete all submissions.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="tugas_id" id="deleteAssignmentId">
                    <button type="submit" name="delete_assignment" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(assignmentId, assignmentName) {
    document.getElementById('deleteAssignmentId').value = assignmentId;
    document.getElementById('deleteAssignmentName').textContent = assignmentName;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// Initialize DataTable
$(document).ready(function() {
    $('#assignmentsTable').DataTable({
        "order": [[4, "desc"]], // Sort by due date by default
        "pageLength": 25,
        "language": {
            "search": "Search assignments:"
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

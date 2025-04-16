<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth_helper.php';

$auth = new AuthHelper();

// Check if user is logged in and has appropriate role
if (!$auth->isLoggedIn() || !$auth->hasRole(['admin', 'guru'])) {
    redirect('/web/index.php', 'Access denied. Insufficient privileges.', 'danger');
}

$page_title = "Class Management";
$db = (new Database())->getConnection();

// Handle class deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_class'])) {
    $kelas_id = (int)$_POST['kelas_id'];
    try {
        $db->begin_transaction();

        // Check if class has any associated students
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM siswa WHERE kelas_id = ?");
        $stmt->bind_param("i", $kelas_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $student_count = $result->fetch_assoc()['count'];

        if ($student_count > 0) {
            throw new Exception("Cannot delete class with active students");
        }

        // Delete class
        $stmt = $db->prepare("DELETE FROM kelas WHERE id = ?");
        $stmt->bind_param("i", $kelas_id);
        $stmt->execute();

        $db->commit();
        redirect('/web/modules/kelas/index.php', 'Class deleted successfully.', 'success');
    } catch (Exception $e) {
        $db->rollback();
        $error = "Failed to delete class: " . $e->getMessage();
    }
}

// Get all classes with student count and assigned subjects
$query = "
    SELECT 
        k.*,
        COUNT(DISTINCT s.id) as jumlah_siswa,
        COUNT(DISTINCT j.mata_pelajaran_id) as jumlah_mapel,
        GROUP_CONCAT(DISTINCT CONCAT(g.nama, ' (', mp.nama, ')') SEPARATOR ', ') as guru_mapel
    FROM kelas k
    LEFT JOIN siswa s ON k.id = s.kelas_id
    LEFT JOIN jadwal j ON k.id = j.kelas_id
    LEFT JOIN guru g ON j.guru_id = g.id
    LEFT JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id
    GROUP BY k.id
    ORDER BY k.tingkat ASC, k.nama ASC";

$result = $db->query($query);
$classes = $result->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Class Management</h2>
        <a href="create.php" class="btn btn-primary">
            <i class="fas fa-plus me-2"></i>Add New Class
        </a>
    </div>

    <?php echo display_flash_message(); ?>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="classesTable">
                    <thead>
                        <tr>
                            <th>Grade</th>
                            <th>Class Name</th>
                            <th>Students</th>
                            <th>Subjects</th>
                            <th>Teachers & Subjects</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $class): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($class['tingkat']); ?></td>
                                <td><?php echo htmlspecialchars($class['nama']); ?></td>
                                <td>
                                    <span class="badge bg-info">
                                        <?php echo $class['jumlah_siswa']; ?> students
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-success">
                                        <?php echo $class['jumlah_mapel']; ?> subjects
                                    </span>
                                </td>
                                <td>
                                    <small>
                                        <?php echo $class['guru_mapel'] ? htmlspecialchars($class['guru_mapel']) : '-'; ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="view.php?id=<?php echo $class['id']; ?>" 
                                           class="btn btn-sm btn-info" 
                                           title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="edit.php?id=<?php echo $class['id']; ?>" 
                                           class="btn btn-sm btn-primary" 
                                           title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if ($class['jumlah_siswa'] == 0): ?>
                                            <button type="button" 
                                                    class="btn btn-sm btn-danger" 
                                                    onclick="confirmDelete(<?php echo $class['id']; ?>, '<?php echo htmlspecialchars($class['nama']); ?>')"
                                                    title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
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
                Are you sure you want to delete class <span id="deleteClassName"></span>?
                This action cannot be undone.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="kelas_id" id="deleteClassId">
                    <button type="submit" name="delete_class" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(classId, className) {
    document.getElementById('deleteClassId').value = classId;
    document.getElementById('deleteClassName').textContent = className;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// Initialize DataTable
$(document).ready(function() {
    $('#classesTable').DataTable({
        "order": [[0, "asc"], [1, "asc"]], // Sort by grade then name
        "pageLength": 25,
        "language": {
            "search": "Search classes:"
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

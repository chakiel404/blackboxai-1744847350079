<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth_helper.php';

$auth = new AuthHelper();

// Check if user is logged in and has appropriate role
if (!$auth->isLoggedIn() || !$auth->hasRole(['admin', 'guru'])) {
    redirect('/web/index.php', 'Access denied. Insufficient privileges.', 'danger');
}

$page_title = "Subject Management";
$db = (new Database())->getConnection();

// Handle subject deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_subject'])) {
    $mapel_id = (int)$_POST['mapel_id'];
    try {
        $db->begin_transaction();

        // Delete subject
        $stmt = $db->prepare("DELETE FROM mata_pelajaran WHERE id = ?");
        $stmt->bind_param("i", $mapel_id);
        $stmt->execute();

        $db->commit();
        redirect('/web/modules/mapel/index.php', 'Subject deleted successfully.', 'success');
    } catch (Exception $e) {
        $db->rollback();
        $error = "Failed to delete subject: " . $e->getMessage();
    }
}

// Get all subjects
$query = "SELECT * FROM mata_pelajaran ORDER BY nama";
$result = $db->query($query);
$subjects = $result->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Subject Management</h2>
        <a href="create.php" class="btn btn-primary">
            <i class="fas fa-plus me-2"></i>Add New Subject
        </a>
    </div>

    <?php echo display_flash_message(); ?>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="subjectsTable">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjects as $subject): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($subject['kode']); ?></td>
                                <td><?php echo htmlspecialchars($subject['nama']); ?></td>
                                <td>
                                    <div class="btn-group">
                                        <a href="edit.php?id=<?php echo $subject['id']; ?>" 
                                           class="btn btn-sm btn-info" 
                                           title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button type="button" 
                                                class="btn btn-sm btn-danger" 
                                                onclick="confirmDelete(<?php echo $subject['id']; ?>, '<?php echo htmlspecialchars($subject['nama']); ?>')"
                                                title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
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
                Are you sure you want to delete subject <span id="deleteSubjectName"></span>?
                This action cannot be undone.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="mapel_id" id="deleteSubjectId">
                    <button type="submit" name="delete_subject" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(subjectId, subjectName) {
    document.getElementById('deleteSubjectId').value = subjectId;
    document.getElementById('deleteSubjectName').textContent = subjectName;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// Initialize DataTable
$(document).ready(function() {
    $('#subjectsTable').DataTable({
        "order": [[1, "asc"]], // Sort by name
        "pageLength": 25,
        "language": {
            "search": "Search subjects:"
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

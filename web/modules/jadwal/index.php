<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth_helper.php';

$auth = new AuthHelper();

// Check if user is logged in and has appropriate role
if (!$auth->isLoggedIn() || !$auth->hasRole(['admin', 'guru'])) {
    redirect('/web/index.php', 'Access denied. Insufficient privileges.', 'danger');
}

$page_title = "Schedule Management";
$db = (new Database())->getConnection();

// Handle schedule deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_schedule'])) {
    $jadwal_id = (int)$_POST['jadwal_id'];
    try {
        $db->begin_transaction();

        // Delete schedule
        $stmt = $db->prepare("DELETE FROM jadwal WHERE id = ?");
        $stmt->bind_param("i", $jadwal_id);
        $stmt->execute();

        $db->commit();
        redirect('/web/modules/jadwal/index.php', 'Schedule deleted successfully.', 'success');
    } catch (Exception $e) {
        $db->rollback();
        $error = "Failed to delete schedule: " . $e->getMessage();
    }
}

// Get all schedules with class and subject details
$query = "
    SELECT j.*, k.nama as kelas_nama, mp.nama as mata_pelajaran_nama, 
           g.nama as guru_nama, g.nip as guru_nip
    FROM jadwal j
    JOIN kelas k ON j.kelas_id = k.id
    JOIN mata_pelajaran mp ON j.mata_pelajaran_id = mp.id
    JOIN guru g ON j.guru_id = g.id
    ORDER BY k.tingkat, k.nama, j.hari, j.waktu_mulai";

$result = $db->query($query);
$schedules = $result->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Schedule Management</h2>
        <a href="create.php" class="btn btn-primary">
            <i class="fas fa-plus me-2"></i>Add New Schedule
        </a>
    </div>

    <?php echo display_flash_message(); ?>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="schedulesTable">
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Subject</th>
                            <th>Teacher</th>
                            <th>Day</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schedules as $schedule): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($schedule['kelas_nama']); ?></td>
                                <td><?php echo htmlspecialchars($schedule['mata_pelajaran_nama']); ?></td>
                                <td><?php echo htmlspecialchars($schedule['guru_nama'] . ' (' . $schedule['guru_nip'] . ')'); ?></td>
                                <td><?php echo htmlspecialchars($schedule['hari']); ?></td>
                                <td><?php echo htmlspecialchars($schedule['waktu_mulai']); ?></td>
                                <td><?php echo htmlspecialchars($schedule['waktu_selesai']); ?></td>
                                <td>
                                    <div class="btn-group">
                                        <a href="edit.php?id=<?php echo $schedule['id']; ?>" 
                                           class="btn btn-sm btn-info" 
                                           title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button type="button" 
                                                class="btn btn-sm btn-danger" 
                                                onclick="confirmDelete(<?php echo $schedule['id']; ?>, '<?php echo htmlspecialchars($schedule['mata_pelajaran_nama']); ?>')"
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
                Are you sure you want to delete schedule for <span id="deleteScheduleName"></span>?
                This action cannot be undone.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="jadwal_id" id="deleteScheduleId">
                    <button type="submit" name="delete_schedule" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(scheduleId, scheduleName) {
    document.getElementById('deleteScheduleId').value = scheduleId;
    document.getElementById('deleteScheduleName').textContent = scheduleName;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// Initialize DataTable
$(document).ready(function() {
    $('#schedulesTable').DataTable({
        "order": [[0, "asc"], [1, "asc"]], // Sort by class then subject
        "pageLength": 25,
        "language": {
            "search": "Search schedules:"
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

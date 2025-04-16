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

$page_title = "Learning Materials";
$db = (new Database())->getConnection();
$user = $auth->getCurrentUser();

// Handle material deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_material'])) {
    if (!$auth->hasRole(['admin', 'guru'])) {
        redirect('/web/modules/materi/index.php', 'Access denied. Insufficient privileges.', 'danger');
    }

    $materi_id = (int)$_POST['materi_id'];
    try {
        $db->begin_transaction();

        // Get file path before deletion
        $stmt = $db->prepare("SELECT file_path FROM materi WHERE id = ?");
        $stmt->bind_param("i", $materi_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $material = $result->fetch_assoc();

        // Delete file if exists
        if ($material && !empty($material['file_path'])) {
            $fileHelper->deleteFile($material['file_path']);
        }

        // Delete material record
        $stmt = $db->prepare("DELETE FROM materi WHERE id = ?");
        $stmt->bind_param("i", $materi_id);
        $stmt->execute();

        $db->commit();
        redirect('/web/modules/materi/index.php', 'Material deleted successfully.', 'success');
    } catch (Exception $e) {
        $db->rollback();
        $error = "Failed to delete material: " . $e->getMessage();
    }
}

// Get materials based on user role
$query = "
    SELECT m.*, mp.nama as mata_pelajaran_nama, k.nama as kelas_nama, 
           p.nama as guru_nama
    FROM materi m
    JOIN mata_pelajaran mp ON m.mata_pelajaran_id = mp.id
    JOIN kelas k ON m.kelas_id = k.id
    JOIN guru g ON m.guru_id = g.id
    JOIN pengguna p ON g.pengguna_id = p.id
    WHERE 1=1";

if ($user['peran'] === 'guru') {
    $query .= " AND m.guru_id = (SELECT id FROM guru WHERE pengguna_id = ?)";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $user['id']);
} elseif ($user['peran'] === 'siswa') {
    $query .= " AND m.kelas_id = (SELECT kelas_id FROM siswa WHERE pengguna_id = ?)";
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $user['id']);
} else {
    $stmt = $db->prepare($query);
}

$stmt->execute();
$materials = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Learning Materials</h2>
        <?php if ($auth->hasRole(['admin', 'guru'])): ?>
            <a href="create.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Add New Material
            </a>
        <?php endif; ?>
    </div>

    <?php echo display_flash_message(); ?>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="materialsTable">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Subject</th>
                            <th>Class</th>
                            <th>Teacher</th>
                            <th>Description</th>
                            <th>File</th>
                            <th>Date</th>
                            <?php if ($auth->hasRole(['admin', 'guru'])): ?>
                                <th>Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($materials as $material): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($material['judul']); ?></td>
                                <td><?php echo htmlspecialchars($material['mata_pelajaran_nama']); ?></td>
                                <td><?php echo htmlspecialchars($material['kelas_nama']); ?></td>
                                <td><?php echo htmlspecialchars($material['guru_nama']); ?></td>
                                <td>
                                    <?php 
                                    $description = htmlspecialchars($material['deskripsi']);
                                    echo strlen($description) > 50 ? substr($description, 0, 50) . '...' : $description;
                                    ?>
                                </td>
                                <td>
                                    <?php if (!empty($material['file_path'])): ?>
                                        <a href="/web/assets/uploads/<?php echo htmlspecialchars($material['file_path']); ?>" 
                                           class="btn btn-sm btn-outline-primary" 
                                           target="_blank">
                                            <i class="fas fa-download me-1"></i>Download
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">No file</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d/m/Y', strtotime($material['dibuat_pada'])); ?></td>
                                <?php if ($auth->hasRole(['admin', 'guru'])): ?>
                                    <td>
                                        <div class="btn-group">
                                            <a href="edit.php?id=<?php echo $material['id']; ?>" 
                                               class="btn btn-sm btn-info" 
                                               title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button type="button" 
                                                    class="btn btn-sm btn-danger" 
                                                    onclick="confirmDelete(<?php echo $material['id']; ?>, '<?php echo htmlspecialchars($material['judul']); ?>')"
                                                    title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                <?php endif; ?>
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
                Are you sure you want to delete material <span id="deleteMaterialName"></span>?
                This action cannot be undone.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="materi_id" id="deleteMaterialId">
                    <button type="submit" name="delete_material" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(materialId, materialName) {
    document.getElementById('deleteMaterialId').value = materialId;
    document.getElementById('deleteMaterialName').textContent = materialName;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// Initialize DataTable
$(document).ready(function() {
    $('#materialsTable').DataTable({
        "order": [[6, "desc"]], // Sort by date by default
        "pageLength": 25,
        "language": {
            "search": "Search materials:"
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

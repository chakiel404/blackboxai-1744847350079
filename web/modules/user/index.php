<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth_helper.php';

$auth = new AuthHelper();

// Check if user is logged in and is admin
if (!$auth->isLoggedIn() || !$auth->hasRole('admin')) {
    redirect('/web/index.php', 'Access denied. Admin privileges required.', 'danger');
}

$page_title = "User Management";

// Get database connection
$db = (new Database())->getConnection();

// Handle user deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $user_id = (int)$_POST['user_id'];
    try {
        $db->begin_transaction();

        // Delete user from pengguna table (cascading will handle related records)
        $stmt = $db->prepare("DELETE FROM pengguna WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();

        $db->commit();
        redirect('/web/modules/user/index.php', 'User deleted successfully.', 'success');
    } catch (Exception $e) {
        $db->rollback();
        $error = "Failed to delete user: " . $e->getMessage();
    }
}

// Get all users with their role-specific details
$query = "
    SELECT 
        p.*,
        CASE 
            WHEN p.peran = 'guru' THEN g.nip
            WHEN p.peran = 'siswa' THEN s.nis
            ELSE NULL
        END as identifier,
        CASE 
            WHEN p.peran = 'guru' THEN g.telepon
            WHEN p.peran = 'siswa' THEN s.telepon
            ELSE NULL
        END as telepon,
        CASE 
            WHEN p.peran = 'siswa' THEN k.nama
            ELSE NULL
        END as kelas_nama
    FROM pengguna p
    LEFT JOIN guru g ON p.id = g.pengguna_id AND p.peran = 'guru'
    LEFT JOIN siswa s ON p.id = s.pengguna_id AND p.peran = 'siswa'
    LEFT JOIN kelas k ON s.kelas_id = k.id
    ORDER BY p.peran, p.nama";

$result = $db->query($query);
$users = $result->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>User Management</h2>
        <div>
            <a href="create.php?type=guru" class="btn btn-success me-2">
                <i class="fas fa-chalkboard-teacher me-2"></i>Add Teacher
            </a>
            <a href="create.php?type=siswa" class="btn btn-primary">
                <i class="fas fa-user-graduate me-2"></i>Add Student
            </a>
        </div>
    </div>

    <?php echo display_flash_message(); ?>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="usersTable">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Email</th>
                            <th>ID Number</th>
                            <th>Phone</th>
                            <th>Class</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['nama']); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $user['peran'] === 'admin' ? 'danger' : 
                                            ($user['peran'] === 'guru' ? 'success' : 'primary'); 
                                    ?>">
                                        <?php echo ucfirst(htmlspecialchars($user['peran'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo htmlspecialchars($user['identifier'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($user['telepon'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($user['kelas_nama'] ?? '-'); ?></td>
                                <td>
                                    <div class="btn-group">
                                        <a href="edit.php?id=<?php echo $user['id']; ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if ($user['peran'] !== 'admin'): ?>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-danger" 
                                                    onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['nama']); ?>')">
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
                Are you sure you want to delete user <span id="deleteUserName"></span>?
                This action cannot be undone.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="user_id" id="deleteUserId">
                    <button type="submit" name="delete_user" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(userId, userName) {
    document.getElementById('deleteUserId').value = userId;
    document.getElementById('deleteUserName').textContent = userName;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// Initialize DataTable
$(document).ready(function() {
    $('#usersTable').DataTable({
        "pageLength": 25,
        "order": [[1, "asc"], [0, "asc"]], // Sort by role then name
        "language": {
            "search": "Search users:"
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

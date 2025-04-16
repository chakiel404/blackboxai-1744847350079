<?php 
require_once __DIR__ . '/../config/config.php';
if (!isset($page_title)) {
    $page_title = 'E-Learning System';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Custom CSS -->
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f8f9fa;
        }
        .navbar {
            background-color: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
        }
        .navbar-brand {
            font-weight: 600;
            color: #2c3e50;
        }
        .nav-link {
            color: #34495e;
            font-weight: 500;
        }
        .nav-link:hover {
            color: #3498db;
        }
        .main-content {
            padding: 2rem 0;
            min-height: calc(100vh - 160px);
        }
        .card {
            border: none;
            box-shadow: 0 0 15px rgba(0,0,0,.05);
            border-radius: 10px;
        }
        .card-header {
            background-color: #fff;
            border-bottom: 1px solid #eee;
            padding: 1rem 1.5rem;
        }
        .btn-primary {
            background-color: #3498db;
            border-color: #3498db;
        }
        .btn-primary:hover {
            background-color: #2980b9;
            border-color: #2980b9;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light">
        <div class="container">
            <a class="navbar-brand" href="/web/index.php">
                <i class="fas fa-graduation-cap me-2"></i>E-Learning
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <?php if (is_logged_in()): ?>
                    <ul class="navbar-nav me-auto">
                        <?php if (has_role(['admin'])): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="/web/modules/user/index.php">
                                    <i class="fas fa-users me-1"></i>Users
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php if (has_role(['admin', 'guru'])): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="/web/modules/kelas/index.php">
                                    <i class="fas fa-chalkboard me-1"></i>Kelas
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="/web/modules/mapel/index.php">
                                    <i class="fas fa-book me-1"></i>Mata Pelajaran
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <li class="nav-item">
                            <a class="nav-link" href="/web/modules/jadwal/index.php">
                                <i class="fas fa-calendar-alt me-1"></i>Jadwal
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/web/modules/materi/index.php">
                                <i class="fas fa-file-alt me-1"></i>Materi
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/web/modules/tugas/index.php">
                                <i class="fas fa-tasks me-1"></i>Tugas
                            </a>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="nilaiDropdown" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-star me-1"></i>Nilai
                            </a>
                            <ul class="dropdown-menu">
                                <?php if (has_role(['admin'])): ?>
                                    <li>
                                        <a class="dropdown-item" href="/web/modules/nilai/jenis_penilaian.php">
                                            <i class="fas fa-list-alt me-2"></i>Jenis Penilaian
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                <?php endif; ?>
                                
                                <?php if (has_role(['guru'])): ?>
                                    <li>
                                        <a class="dropdown-item" href="/web/modules/nilai/index.php">
                                            <i class="fas fa-tasks me-2"></i>Penilaian Tugas
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php if (has_role(['guru', 'siswa'])): ?>
                                    <li>
                                        <a class="dropdown-item" href="/web/modules/nilai/report.php">
                                            <i class="fas fa-file-alt me-2"></i>Laporan Nilai
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </li>
                    </ul>
                    <ul class="navbar-nav">
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user-circle me-1"></i><?php echo $_SESSION['user_name']; ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a class="dropdown-item" href="/web/modules/auth/profile.php">
                                        <i class="fas fa-id-card me-2"></i>Profile
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item text-danger" href="/web/modules/auth/logout.php">
                                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                                    </a>
                                </li>
                            </ul>
                        </li>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="main-content">
        <div class="container">
            <?php echo display_flash_message(); ?>

<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth_helper.php';
require_once __DIR__ . '/../../helpers/validation_helper.php';

$auth = new AuthHelper();

// If already logged in, redirect to dashboard
if ($auth->isLoggedIn()) {
    redirect('/web/index.php');
}

$error = '';
$success = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = $_POST['identifier'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($identifier) || empty($password)) {
        $error = 'Please enter both identifier and password.';
    } else {
        $result = $auth->login($identifier, $password);
        
        if ($result['success']) {
            redirect('/web/index.php', 'Login successful!');
        } else {
            $error = $result['message'];
        }
    }
}

$page_title = "Login";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - E-Learning System</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-container {
            max-width: 400px;
            margin: 0 auto;
            padding: 2rem;
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-header i {
            font-size: 3rem;
            color: #3498db;
            margin-bottom: 1rem;
        }
        .login-header h1 {
            font-size: 1.8rem;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        .login-header p {
            color: #7f8c8d;
            margin-bottom: 0;
        }
        .card {
            border: none;
            box-shadow: 0 0 20px rgba(0,0,0,.08);
            border-radius: 15px;
        }
        .card-body {
            padding: 2rem;
        }
        .form-floating {
            margin-bottom: 1rem;
        }
        .form-floating label {
            color: #95a5a6;
        }
        .form-control {
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            padding: 0.75rem 1rem;
        }
        .form-control:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.2rem rgba(52,152,219,.25);
        }
        .btn-login {
            background-color: #3498db;
            border-color: #3498db;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-weight: 500;
            width: 100%;
            margin-top: 1rem;
        }
        .btn-login:hover {
            background-color: #2980b9;
            border-color: #2980b9;
        }
        .login-footer {
            text-align: center;
            margin-top: 2rem;
            color: #7f8c8d;
        }
        .login-footer a {
            color: #3498db;
            text-decoration: none;
        }
        .login-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="login-header">
                <i class="fas fa-graduation-cap"></i>
                <h1>E-Learning System</h1>
                <p>Sign in to continue</p>
            </div>

            <div class="card">
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo $error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo $success; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" class="needs-validation" novalidate>
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="identifier" name="identifier" 
                                   placeholder="Email/NIP/NIS" required 
                                   value="<?php echo isset($_POST['identifier']) ? htmlspecialchars($_POST['identifier']) : ''; ?>">
                            <label for="identifier">Email/NIP/NIS</label>
                            <div class="invalid-feedback">
                                Please enter your email, NIP, or NIS.
                            </div>
                        </div>

                        <div class="form-floating mb-3">
                            <input type="password" class="form-control" id="password" name="password" 
                                   placeholder="Password" required>
                            <label for="password">Password</label>
                            <div class="invalid-feedback">
                                Please enter your password.
                            </div>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="remember" name="remember">
                            <label class="form-check-label text-muted" for="remember">
                                Remember me
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary btn-login">
                            <i class="fas fa-sign-in-alt me-2"></i>Sign In
                        </button>
                    </form>
                </div>
            </div>

            <div class="login-footer">
                <p>Forgot your password? <a href="forgot-password.php">Reset it here</a></p>
                <p class="mb-0">Need help? <a href="#">Contact support</a></p>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Form validation
    (function () {
        'use strict'

        var forms = document.querySelectorAll('.needs-validation')

        Array.prototype.slice.call(forms)
            .forEach(function (form) {
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
</body>
</html>

<?php
require_once 'config.php';

$error = '';
$success = '';

// LOGOUT (আগের logout.php এখন এখানে ও dashboard.php তে)
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    doUserLogout();
    header("Location: index.php?logged_out=1");
    exit();
}

if (isset($_GET['logged_out'])) {
    $success = "You have been logged out successfully.";
}

if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = cleanInput($_POST['action'] ?? '');

    // Login Handler
    if ($action === 'login') {
        $username = cleanInput($_POST['username'] ?? '');
        $password = cleanInput($_POST['password'] ?? '');

        if (!empty($username) && !empty($password)) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] === 'banned') {
                    $error = "Your account has been suspended!";
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    header("Location: dashboard.php");
                    exit();
                }
            } else {
                $error = "Invalid username or password!";
            }
        } else {
            $error = "Please fill in all fields!";
        }
    }

    // Registration Handler
    if ($action === 'register') {
        $username = cleanInput($_POST['username'] ?? '');
        $email    = cleanInput($_POST['email'] ?? '');
        $password = cleanInput($_POST['password'] ?? '');

        if (!empty($username) && !empty($email) && !empty($password) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address!";
        } elseif (!empty($username) && !empty($email) && !empty($password) && strlen($password) < 6) {
            $error = "Password must be at least 6 characters!";
        } elseif (!empty($username) && !empty($email) && !empty($password)) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->rowCount() > 0) {
                $error = "Username or Email already registered!";
            } else {
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'user')");
                if ($stmt->execute([$username, $email, $hashedPassword])) {
                    $success = "Registration successful! You can now login.";
                } else {
                    $error = "Registration failed! Please try again.";
                }
            }
        } else {
            $error = "Please complete all registration fields!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRACKMASTER | Authentication Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { background: #080a10; color: #e2e8f0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .bg-glow { position: fixed; width: 350px; height: 350px; border-radius: 50%; background: radial-gradient(circle, rgba(0, 240, 255, 0.15) 0%, rgba(0,0,0,0) 70%); filter: blur(50px); pointer-events: none; z-index: 0; }
        .glow-1 { top: -50px; left: -50px; }
        .glow-2 { bottom: -50px; right: -50px; background: radial-gradient(circle, rgba(168, 85, 247, 0.15) 0%, rgba(0,0,0,0) 70%); }
        .auth-container { width: 100%; max-width: 420px; z-index: 10; position: relative; }
        .brand { text-align: center; margin-bottom: 25px; }
        .brand-icon { display: inline-flex; padding: 14px; background: rgba(0, 240, 255, 0.08); border: 1px solid rgba(0, 240, 255, 0.3); border-radius: 18px; color: #00f0ff; font-size: 28px; margin-bottom: 12px; box-shadow: 0 0 20px rgba(0, 240, 255, 0.15); }
        .brand h1 { font-size: 26px; font-weight: 800; letter-spacing: 2px; color: #fff; text-transform: uppercase; }
        .brand h1 span { color: #00f0ff; text-shadow: 0 0 15px rgba(0, 240, 255, 0.5); }
        .brand p { font-size: 11px; text-transform: uppercase; letter-spacing: 3px; color: #64748b; margin-top: 4px; }
        .card { background: rgba(15, 23, 42, 0.85); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 20px; padding: 28px; backdrop-filter: blur(16px); box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5); }
        .tabs { display: flex; background: #0b0f19; padding: 4px; border-radius: 12px; margin-bottom: 22px; border: 1px solid rgba(255, 255, 255, 0.05); }
        .tab-btn { flex: 1; padding: 10px; background: none; border: none; color: #64748b; font-size: 13px; font-weight: 700; border-radius: 9px; cursor: pointer; transition: 0.2s; }
        .tab-btn.active { background: #00f0ff; color: #080a10; box-shadow: 0 0 15px rgba(0, 240, 255, 0.4); }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; margin-bottom: 6px; font-weight: 600; }
        .input-box { position: relative; }
        .input-box i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #475569; font-size: 14px; }
        .input-box input { width: 100%; background: #0b0f19; border: 1px solid #1e293b; border-radius: 12px; padding: 12px 14px 12px 42px; color: #fff; font-size: 14px; outline: none; transition: 0.2s; }
        .input-box input:focus { border-color: #00f0ff; box-shadow: 0 0 10px rgba(0, 240, 255, 0.2); }
        .btn-submit { width: 100%; background: linear-gradient(135deg, #00f0ff, #3b82f6); border: none; border-radius: 12px; padding: 13px; color: #080a10; font-size: 14px; font-weight: 800; letter-spacing: 1px; cursor: pointer; text-transform: uppercase; transition: 0.2s; margin-top: 8px; }
        .btn-submit:hover { opacity: 0.95; transform: translateY(-1px); box-shadow: 0 0 20px rgba(0, 240, 255, 0.4); }
        .alert { padding: 12px 15px; border-radius: 10px; font-size: 13px; margin-bottom: 18px; display: flex; align-items: center; gap: 10px; }
        .alert-error { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5; }
        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #86efac; }
        .hidden { display: none; }
        footer { text-align: center; margin-top: 25px; font-size: 11px; color: #475569; letter-spacing: 1px; }
    </style>
</head>
<body>
    <div class="bg-glow glow-1"></div>
    <div class="bg-glow glow-2"></div>

    <div class="auth-container">
        <div class="brand">
            <div class="brand-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <h1>CRACK<span>MASTER</span></h1>
            <p>Access Management Portal</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= $error; ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= $success; ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="tabs">
                <button type="button" id="tab-login" class="tab-btn active" onclick="switchAuth('login')">SIGN IN</button>
                <button type="button" id="tab-register" class="tab-btn" onclick="switchAuth('register')">REGISTER</button>
            </div>

            <!-- Login Form -->
            <form id="form-login" method="POST">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <label>Username / Email</label>
                    <div class="input-box">
                        <i class="fa-solid fa-user"></i>
                        <input type="text" name="username" required placeholder="admin">
                    </div>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <div class="input-box">
                        <i class="fa-solid fa-lock"></i>
                        <input type="password" name="password" required placeholder="••••••••">
                    </div>
                </div>
                <button type="submit" class="btn-submit">Authenticate</button>
            </form>

            <!-- Register Form -->
            <form id="form-register" method="POST" class="hidden">
                <input type="hidden" name="action" value="register">
                <div class="form-group">
                    <label>Username</label>
                    <div class="input-box">
                        <i class="fa-solid fa-user-plus"></i>
                        <input type="text" name="username" required placeholder="username">
                    </div>
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <div class="input-box">
                        <i class="fa-solid fa-envelope"></i>
                        <input type="email" name="email" required placeholder="name@domain.com">
                    </div>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <div class="input-box">
                        <i class="fa-solid fa-key"></i>
                        <input type="password" name="password" required placeholder="••••••••">
                    </div>
                </div>
                <button type="submit" class="btn-submit">Create Account</button>
            </form>
        </div>

        <footer>&copy; <?= date('Y'); ?> CRACKMASTER ENGINE. ALL RIGHTS RESERVED.</footer>
    </div>

    <script>
        function switchAuth(type) {
            const loginForm = document.getElementById('form-login');
            const registerForm = document.getElementById('form-register');
            const loginTab = document.getElementById('tab-login');
            const registerTab = document.getElementById('tab-register');

            if (type === 'login') {
                loginForm.classList.remove('hidden');
                registerForm.classList.add('hidden');
                loginTab.classList.add('active');
                registerTab.classList.remove('active');
            } else {
                loginForm.classList.add('hidden');
                registerForm.classList.remove('hidden');
                registerTab.classList.add('active');
                loginTab.classList.remove('active');
            }
        }
    </script>
</body>
</html>
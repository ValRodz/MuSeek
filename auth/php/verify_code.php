<?php
session_start();

function redirect($url) {
    echo "<script>window.location.href = '" . $url . "';</script>";
    exit;
}

// Support both pending login and pending registration
$mode = null;
if (isset($_SESSION['pending_login'])) {
    $pending = $_SESSION['pending_login'];
    $mode = 'login';
} elseif (isset($_SESSION['pending_registration'])) {
    $pending = $_SESSION['pending_registration'];
    $mode = 'registration';
} else {
    echo "<script>
        alert('No pending verification found. Please log in or sign up again.');
        window.location.href = 'login.php';
    </script>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = isset($_POST['code']) ? trim($_POST['code']) : '';

    if ($code === '') {
        echo "<script>
            alert('Please enter the verification code.');
            window.location.href = 'verify_code.php';
        </script>";
        exit;
    }

    // Expired?
    if (time() > (int)$pending['expires_at']) {
        if ($mode === 'login') {
            unset($_SESSION['pending_login']);
            echo "<script>
                alert('Verification code expired. Please log in again.');
                window.location.href = 'login.php';
            </script>";
        } else {
            unset($_SESSION['pending_registration']);
            echo "<script>
                alert('Verification code expired. Please sign up again.');
                window.location.href = 'signin.php';
            </script>";
        }
        exit;
    }

    // Match?
    if (!hash_equals((string)$pending['otp'], (string)$code)) {
        echo "<script>
            alert('Invalid code. Please try again.');
            window.location.href = 'verify_code.php';
        </script>";
        exit;
    }

    if ($mode === 'registration') {
        // Finalize registration: insert client into DB, then log in
        require_once __DIR__ . '/../../../db.php';
        $name  = $pending['name'];
        $phone = $pending['phone'];
        $email = $pending['email'];
        $pass  = $pending['password'];

        $sql = "INSERT INTO clients (Name, Phone, Email, Password) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssss', $name, $phone, $email, $pass);
        if ($stmt->execute()) {
            $_SESSION['user_id']   = $conn->insert_id;
            $_SESSION['user_type'] = 'client';
            unset($_SESSION['pending_registration']);
            $stmt->close();
            $conn->close();
            echo "<script>window.location.href = '../../';</script>";
            exit;
        } else {
            unset($_SESSION['pending_registration']);
            if (isset($stmt)) { $stmt->close(); }
            if (isset($conn)) { $conn->close(); }
            echo "<script>
                alert('Registration failed during verification. Please sign up again.');
                window.location.href = 'signin.php';
            </script>";
            exit;
        }
    }

    // Success: finalize login
    $_SESSION['user_id']   = $pending['user_id'];
    $_SESSION['user_type'] = $pending['user_type'];
    unset($_SESSION['pending_login']);

    // Show a loading UI that says "Logging In" before redirect
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Logging In...</title>
    <style>
        :root {
            --primary-color: #e50914;
            --primary-hover: #f40612;
            --background-dark: #0f0f0f;
            --text-primary: #ffffff;
            --text-secondary: #b3b3b3;
        }
        body { margin:0; background: var(--background-dark); color: var(--text-primary); font-family: 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .loading-container { min-height: 100vh; display:flex; align-items:center; justify-content:center; }
        .loading-card { display:flex; align-items:center; gap:12px; background: rgba(20,20,20,0.95); border:1px solid #333; padding:18px 20px; border-radius:12px; box-shadow: 0 4px 16px rgba(0,0,0,0.4); }
        .spinner { width: 28px; height: 28px; border: 3px solid rgba(255,255,255,0.25); border-top-color: #fff; border-radius: 50%; animation: spin 0.9s linear infinite; }
        .loading-text { font-size: 1.05rem; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="loading-container">
        <div class="loading-card">
            <div class="spinner"></div>
            <div class="loading-text">Logging In</div>
        </div>
    </div>
    <script>
        alert('Login Successful! Welcome to Museek');
        window.location.replace('../../');
    </script>
</body>
</html>
HTML;
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Verify Code</title>
    <style>
        :root {
            --primary-color: #e50914;
            --primary-hover: #f40612;
            --background-dark: #0f0f0f;
            --background-card: rgba(20,20,20,0.95);
            --text-primary: #ffffff;
            --text-secondary: #b3b3b3;
            --border-color: #333333;
            --border-radius: 12px;
            --shadow-medium: 0 4px 16px rgba(0,0,0,0.4);
        }
        body { font-family: 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--background-dark); color: var(--text-primary); margin:0; }
        .container { max-width: 420px; margin: 10vh auto; background: var(--background-card); padding:24px; border-radius: var(--border-radius); box-shadow: var(--shadow-medium); border: 1px solid var(--border-color); }
        h1 { font-size: 20px; margin: 0 0 16px; }
        p { color: var(--text-secondary); margin: 0 0 20px; }
        input[type="text"] { width: 94%; padding: 12px; font-size: 18px; letter-spacing: 4px; text-align:center; border-radius:8px; border:1px solid var(--border-color); background: rgba(45,45,45,0.95); color: var(--text-primary); }
        button { margin-top: 16px; width: 100%; padding: 12px; background: var(--primary-color); color:#fff; border: none; border-radius:8px; font-size: 16px; cursor: pointer; }
        button:hover { background: var(--primary-hover); }
        .meta { font-size: 13px; color: var(--text-secondary); margin-top: 8px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Enter Verification Code</h1>
        <p>We sent a 6-digit code to <strong><?php echo htmlspecialchars($pending['email']); ?></strong>. Enter it below to continue.</p>
        <form method="POST" action="verify_code.php">
            <input type="text" inputmode="numeric" name="code" maxlength="6" minlength="6" placeholder="••••••" required />
            <button type="submit">Verify</button>
        </form>
        <p class="meta">Code expires in 10 minutes.</p>
    </div>
</body>
</html>
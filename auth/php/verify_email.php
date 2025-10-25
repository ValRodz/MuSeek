<?php
session_start();

$mode  = isset($_GET['mode']) ? trim($_GET['mode']) : '';
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

function fail_and_redirect($message, $redirect) {
    echo "<script>alert('" . addslashes($message) . "'); window.location.href='" . $redirect . "';</script>";
    exit;
}

if ($mode === '' || $token === '') {
    fail_and_redirect('Invalid verification link.', 'login.php');
}

$now = time();

if ($mode === 'registration') {
    if (!isset($_SESSION['pending_registration'])) {
        fail_and_redirect('No pending registration found. Please sign up again.', 'signin.php');
    }

    $data = $_SESSION['pending_registration'];

    if (!isset($data['token'], $data['expires_at']) || $token !== $data['token']) {
        fail_and_redirect('Invalid or mismatched verification token.', 'signin.php');
    }
    if ($now > (int)$data['expires_at']) {
        unset($_SESSION['pending_registration']);
        fail_and_redirect('Verification link expired. Please sign up again.', '../../../signin.html');
    }

    // Insert the client into the database (mysqli)
    include_once __DIR__ . '/../../shared/config/db.php';

    $name  = $data['name'];
    $phone = $data['phone'];
    $email = $data['email'];
    $pass  = $data['password'];

    $sql = "INSERT INTO clients (Phone, Email, Password, Name, V_StatsID) VALUES (?, ?, ?, ?, 2)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        fail_and_redirect('Database error: unable to prepare statement.', 'signin.html');
    }
    $stmt->bind_param('ssss', $phone, $email, $pass, $name);

    if (!$stmt->execute()) {
        fail_and_redirect('Database error: unable to create account.', 'signin.html');
    }

    $newUserId = $conn->insert_id;
    $stmt->close();
    $conn->close();

    unset($_SESSION['pending_registration']);

    $_SESSION['user_id'] = $newUserId;
    $_SESSION['user_type'] = 'client';

    echo "<script>alert('Your account has been registered. Welcome to Museek. Enjoy!'); window.location.href='../../';</script>";
    exit;
}

if ($mode === 'owner_registration') {
    if (!isset($_SESSION['pending_owner_registration'])) {
        fail_and_redirect('No pending owner registration found.', 'owner_register.php');
    }

    $data = $_SESSION['pending_owner_registration'];
    if (!isset($data['token'], $data['expires_at']) || $token !== $data['token']) {
        fail_and_redirect('Invalid or mismatched verification token.', 'owner_register.php');
    }
    if ($now > (int)$data['expires_at']) {
        unset($_SESSION['pending_owner_registration']);
        fail_and_redirect('Verification link expired. Please register again.', 'owner_register.php');
    }

    require_once __DIR__ . '/../../shared/config/db pdo.php';

    $name        = $data['name'];
    $phone       = $data['phone'];
    $email       = $data['email'];
    $password    = $data['password'];
    $studio_name = $data['studio_name'];
    $latitude    = $data['latitude'];
    $longitude   = $data['longitude'];
    $location    = $data['location'];
    $time_in     = $data['time_in'];
    $time_out    = $data['time_out'];

    try {
        $pdo->beginTransaction();

        $v_status = 1; // Verified

        // Insert new studio owner
        $stmt = $pdo->prepare("INSERT INTO studio_owners (Name, Email, Phone, Password, V_StatsID) VALUES (?, ?, ?, ?, 2)");
        $stmt->execute([$name, $email, $phone, $password, $v_status]);

        $owner_id = $pdo->lastInsertId();

        // Insert new studio
        $stmt = $pdo->prepare("INSERT INTO studios (OwnerID, StudioName, Latitude, Longitude, Loc_Desc, Time_IN, Time_OUT) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$owner_id, $studio_name, $latitude, $longitude, $location, $time_in, $time_out]);

        $pdo->commit();

        unset($_SESSION['pending_owner_registration']);

        $_SESSION['user_id'] = (int)$owner_id;
        $_SESSION['user_type'] = 'owner';

        echo "<script>alert('Your account has been registered. Welcome to Museek. Enjoy!'); window.location.href='/index.html';</script>";
    exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fail_and_redirect('Registration failed: ' . $e->getMessage(), 'owner_register.php');
    }
}

// Fallback for unknown mode
fail_and_redirect('Unknown verification mode.', 'login.php');

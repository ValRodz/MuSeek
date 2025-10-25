<?php
session_start(); // Start the session

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    include '../../shared/config/db.php'; // Include the database connection file
    require_once '../../shared/config/mail_config.php';

    $email = $_POST['email'];
    $pass = $_POST['password'];

    if (empty($email) || empty($pass)) {
        echo "<script>
            alert('Please fill in all fields.');
            window.location.href = 'login.php';
        </script>";
        exit;
    }

    // Check Clients table
    $sql = "SELECT ClientID, Email, Password, Name FROM clients WHERE Email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $client = $result->fetch_assoc();

    // Check Studio_Owners table if no client found
    if (!$client) {
        $sql = "SELECT OwnerID, Email, Password, Name FROM studio_owners WHERE Email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $owner = $result->fetch_assoc();
    } else {
        $owner = null;
    }

    // Compare passwords directly (plaintext comparison)
    if ($client && $pass === $client['Password']) {
        // Generate OTP and send email for client login
        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = time() + (60 * 10); // 10 minutes
        $_SESSION['pending_login'] = [
            'user_id'   => $client['ClientID'],
            'user_type' => 'client',
            'email'     => $email,
            'otp'       => $otp,
            'expires_at'=> $expiresAt,
        ];
        $recipientName = isset($client['Name']) ? $client['Name'] : $email;
        if (!sendVerificationEmail($email, $recipientName, $otp, $expiresAt)) {
            unset($_SESSION['pending_login']);
            echo "<script>
                alert('Unable to send verification email. Please try again.');
                window.location.href = 'login.php';
            </script>";
            exit;
        }
        echo "<script>
            alert('Check your email for a verification code to complete login.');
            window.location.href = 'verify_code.php';
        </script>";
        exit;
    } elseif ($owner && $pass === $owner['Password']) {
        // Generate OTP and send email for owner login
        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = time() + (60 * 10); // 10 minutes
        $_SESSION['pending_login'] = [
            'user_id'   => $owner['OwnerID'],
            'user_type' => 'owner',
            'email'     => $email,
            'otp'       => $otp,
            'expires_at'=> $expiresAt,
        ];
        $recipientName = isset($owner['Name']) ? $owner['Name'] : $email;
        if (!sendVerificationEmail($email, $recipientName, $otp, $expiresAt)) {
            unset($_SESSION['pending_login']);
            echo "<script>
                alert('Unable to send verification email. Please try again.');
                window.location.href = 'login.php';
            </script>";
            exit;
        }
        echo "<script>
            alert('Check your email for a verification code to complete login.');
            window.location.href = 'verify_code.php';
        </script>";
        exit;
    } else {
        // Invalid email or password
        echo "<script>
            alert('Invalid email or password. Please try again.');
            window.location.href = 'login.php';
        </script>";
    }

    $stmt->close();
    $conn->close();
} else {
    // If the page is accessed directly without form submission
    echo "<script>
        alert('Invalid access method.');
        window.location.href = 'login.php';
    </script>";
}
?>

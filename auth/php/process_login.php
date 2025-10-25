<?php
session_start(); // Start the session

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    include '../../shared/config/db.php'; // Include the database connection file

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
    $sql = "SELECT ClientID, Email, Password FROM clients WHERE Email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $client = $result->fetch_assoc();

    // Check Studio_Owners table if no client found
    if (!$client) {
        $sql = "SELECT OwnerID, Email, Password FROM studio_owners WHERE Email = ?";
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
        // Successful login as client
        $_SESSION['user_id'] = $client['ClientID'];
        $_SESSION['user_type'] = 'client';
        echo "<script>
            alert('Welcome, $email! You are logged in successfully.');
            window.location.href = '../../'; // Redirect to client dashboard
        </script>";
    } elseif ($owner && $pass === $owner['Password']) {
        // Successful login as owner
        $_SESSION['user_id'] = $owner['OwnerID'];
        $_SESSION['user_type'] = 'owner';
        echo "<script>
            alert('Welcome, $email! You are logged in successfully.');
            window.location.href = '../../owners/php/dashboard.php'; // Redirect to owner dashboard
        </script>";
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

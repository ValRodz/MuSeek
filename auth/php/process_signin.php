<?php
session_start(); // Start the session

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    include '../../shared/config/db.php'; // Include the database connection file

    // Retrieve form data
    $name = $_POST['name'];
    $phone = $_POST['phone'];
    $email = $_POST['email'];
    $pass = $_POST['password'];
    $confirm_pass = $_POST['confirm_password'];
    $terms = isset($_POST['terms']) ? true : false;

    // Basic validation
    if (empty($name) || empty($phone) || empty($email) || empty($pass) || empty($confirm_pass)) {
        echo "<script>
            alert('Please fill in all fields.');
            window.location.href = 'signin.php';
        </script>";
        exit;
    }

    // Validate terms
    if (!$terms) {
        echo "<script>
            alert('You must agree to the Terms of Service.');
            window.location.href = 'signin.php';
        </script>";
        exit;
    }

    // Validate phone (basic check for numbers)
    if (!preg_match('/^[0-9]{11}$/', $phone)) {
        echo "<script>
            alert('A valid phone number is required (10-15 digits).');
            window.location.href = 'signin.php';
        </script>";
        exit;
    }

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "<script>
            alert('A valid email is required.');
            window.location.href = 'signin.php';
        </script>";
        exit;
    }

    // Validate password (minimum length)
    if (strlen($pass) < 8) {
        echo "<script>
            alert('Password must be at least 8 characters long.');
            window.location.href = 'signin.php';
        </script>";
        exit;
    }

    // Check if passwords match
    if ($pass !== $confirm_pass) {
        echo "<script>
            alert('Passwords do not match.');
            window.location.href = 'signin.php';
        </script>";
        exit;
    }

    // Check if email already exists in clients table
    $sql = "SELECT COUNT(*) FROM clients WHERE Email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_row()[0];

    if ($count > 0) {
        echo "<script>
            alert('This email is already registered.');
            window.location.href = 'signin.php';
        </script>";
        exit;
    }

    // Insert the new client into the database (plaintext password to match the login script)
    $sql = "INSERT INTO clients (Name, Phone, Email, Password) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssss", $name, $phone, $email, $pass);
    if ($stmt->execute()) {
        // Successful registration
        echo "<script>
            alert('Registration successful! Please log in.');
            window.location.href = 'login.php';
        </script>";
    } else {
        // Registration failed
        echo "<script>
            alert('Registration failed. Please try again.');
            window.location.href = 'signin.php';
        </script>";
    }

    $stmt->close();
    $conn->close();
} else {
    // If the page is accessed directly without form submission
    echo "<script>
        alert('Invalid access method.');
        window.location.href = 'signin.php';
    </script>";
}
?>

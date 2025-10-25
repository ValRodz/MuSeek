<?php
session_start();
require_once __DIR__ . '/db.php'; // Make sure this path is correct

// Initialize variables
$email = $password = "";
$error = "";

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    // Validate input
    if (empty($email) || empty($password)) {
        $error = "Email and password are required.";
    } else {
        // First, check if email exists in clients table
        $stmt = $pdo->prepare("SELECT ClientID, Name, Email, Password FROM clients WHERE Email = ?");
        $stmt->execute([$email]);
        $client = $stmt->fetch();
        
        if ($client && $password === $client['Password']) { // Note: You should use password_verify() in production
            // Login successful - client
            $_SESSION["user_id"] = $client['ClientID'];
            $_SESSION["user_name"] = $client['Name'];
            $_SESSION["user_email"] = $client['Email'];
            $_SESSION["user_type"] = "client";
            
            // Redirect to client dashboard
            header("Location: client_dashboard.php");
            exit();
        } else {
            // Check if email exists in studio_owners table
            $stmt = $pdo->prepare("SELECT OwnerID, Name, Email, Password FROM studio_owners WHERE Email = ?");
            $stmt->execute([$email]);
            $owner = $stmt->fetch();
            
            if ($owner && $password === $owner['Password']) { // Note: You should use password_verify() in production
                // Login successful - studio owner
                $_SESSION["user_id"] = $owner['OwnerID'];
                $_SESSION["user_name"] = $owner['Name'];
                $_SESSION["user_email"] = $owner['Email'];
                $_SESSION["user_type"] = "owner";
                
                // Redirect to owner dashboard
                header("Location: dashboard.php");
                exit();
            } else {
                $error = "Invalid email or password.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>Login - MuSeek</title>
    <!-- Loading third party fonts -->
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="fonts/font-awesome.min.css" rel="stylesheet" type="text/css">

    <style>
        body {
            background: url('dummy/slide-2.jpg') no-repeat center center fixed;
            background-size: cover;
            position: relative;
            font-family: 'Source Sans Pro', sans-serif;
            color: #fff;
            margin: 0;
            padding: 0;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.10);
            z-index: -1;
        }

        #site-content {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }

        .fullwidth-block {
            text-align: center;
            padding: 20px 0;
            width: 100%;
            max-width: 600px;
        }

        #branding {
            margin: 0 0 40px;
            display: block;
        }

        #branding img {
            padding-top: 5%;
            padding-left: 0;
            width: 300px;
            margin: 0 auto;
            display: block;
        }

        .contact-form {
            max-width: 500px;
            margin: 0 auto;
            padding: 40px;
            background: rgba(0, 0, 0, 0.8);
            border-radius: 50px;
            text-align: center;
            position: relative;
        }

        .contact-form h2 {
            font-size: 32px;
            margin-top: 0;
            margin-bottom: 20px;
            margin-left: auto;
            margin-right: auto;
            color: #fff;
        }

        .form-group {
            position: relative;
            margin-bottom: 25px;
            text-align: left;
        }

        .form-group label {
            position: absolute;
            top: 15px;
            left: 15px;
            font-size: 16px;
            color: #ccc;
            transition: all 0.3s ease;
            pointer-events: none;
            z-index: 1;
        }

        .form-group input {
            width: 100%;
            padding: 15px;
            padding-right: 40px;
            font-size: 16px;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #fff;
            border-radius: 4px;
            box-sizing: border-box;
            text-align: left;
            position: relative;
            z-index: 0;
        }

        .form-group input::placeholder {
            color: transparent;
        }

        .form-group input:focus+label,
        .form-group input:not(:placeholder-shown)+label {
            top: -8px;
            left: 10px;
            font-size: 13px;
            color: #fff;
            background: rgba(0, 0, 0, 0.9);
            border-radius: 4px;
            padding: 0 5px;
        }

        .form-group .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #ccc;
            cursor: pointer;
            font-size: 16px;
            z-index: 2;
        }

        .contact-form input[type="submit"] {
            width: 100%;
            padding: 15px;
            font-size: 16px;
            background-color: #e50914;
            border: none;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 10px;
        }

        .contact-form input[type="submit"]:hover {
            background-color: #f40612;
        }

        .contact-form .additional-options {
            text-align: center;
            margin-top: 15px;
            color: #999;
        }

        .contact-form .additional-options a,
        .contact-form .additional-options p {
            color: #999;
            text-decoration: none;
            font-size: 14px;
        }

        .contact-form .additional-options a:hover {
            text-decoration: underline;
        }

        .contact-form .remember-me {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .contact-form .remember-me input[type="checkbox"] {
            margin-right: 10px;
            width: auto;
        }

        .contact-form .error {
            color: #e87c03;
            margin-bottom: 15px;
            font-size: 14px;
            text-align: left;
        }
        
        /* Debug info */
        .debug-info {
            margin-top: 20px;
            padding: 10px;
            background: rgba(0, 0, 0, 0.7);
            border-radius: 5px;
            color: #ddd;
            text-align: left;
            font-family: monospace;
            font-size: 12px;
            display: none;
        }
    </style>
</head>

<body class="header-collapse">
    <div id="site-content">
        <main class="main-content">
            <div class="fullwidth-block">
                <a id="branding">
                    <img src="images/logo4.png" alt="MuSeek">
                </a>
                <div class="contact-form">
                    <h2>Sign In</h2>
                    <?php if (!empty($error)): ?>
                        <div class="error"><?php echo $error; ?></div>
                    <?php endif; ?>
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
                        <div class="form-group">
                            <input type="email" name="email" id="email" placeholder=" " value="<?php echo htmlspecialchars($email); ?>" required>
                            <label for="email">Email Address</label>
                        </div>
                        <div class="form-group">
                            <input type="password" name="password" id="password" placeholder=" " required>
                            <label for="password">Password</label>
                            <i class="fa fa-eye toggle-password" onclick="togglePassword('password')"></i>
                        </div>
                        <div class="remember-me">
                            <input type="checkbox" name="remember" id="remember">
                            <label for="remember">Remember me</label>
                        </div>
                        <input type="submit" value="Sign In">
                    </form>
                    <div class="additional-options">
                        <p>New to MuSeek? <a href="register.php">Sign up now</a></p>
                        <p>Are you a studio owner? <a href="owner_register.php">Register your studio</a></p>
                        <p><a href="forgot-password.php">Forgot Password?</a></p>
                    </div>
                    
                    <!-- Debug information - uncomment to troubleshoot -->
                    <!--
                    <div class="debug-info">
                        <h4>Debug Info:</h4>
                        <p>POST data: <?php echo !empty($_POST) ? 'Yes' : 'No'; ?></p>
                        <p>Email: <?php echo htmlspecialchars($email); ?></p>
                        <p>DB Connection: <?php echo isset($pdo) ? 'Yes' : 'No'; ?></p>
                    </div>
                    -->
                </div>
            </div>
        </main>
    </div>

    <script>
        function togglePassword(fieldId) {
            const passwordField = document.getElementById(fieldId);
            const toggleIcon = passwordField.nextElementSibling.nextElementSibling;
            if (passwordField.type === "password") {
                passwordField.type = "text";
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = "password";
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
        
        // Add form submission debugging
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            form.addEventListener('submit', function(e) {
                // Uncomment to debug form submission
                // console.log('Form submitted');
            });
        });
    </script>
</body>

</html>
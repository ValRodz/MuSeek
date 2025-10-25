<?php
session_start();
require_once __DIR__ . '/db.php';

// Initialize variables
$name = $phone = $email = $password = $confirm_password = "";
$studio_name = $latitude = $longitude = $location = $time_in = $time_out = "";
$error = "";

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $name = trim($_POST["name"]);
    $phone = trim($_POST["phone"]);
    $email = trim($_POST["email"]);
    $password = $_POST["password"];
    $confirm_password = $_POST["confirm_password"];
    $studio_name = trim($_POST["studio_name"]);
    $latitude = trim($_POST["latitude"]);
    $longitude = trim($_POST["longitude"]);
    $location = trim($_POST["location"]);
    $time_in = $_POST["time_in"];
    $time_out = $_POST["time_out"];
    
    // Validate input
    if (empty($name) || empty($phone) || empty($email) || empty($password) || empty($confirm_password) || 
        empty($studio_name) || empty($latitude) || empty($longitude) || empty($location) || 
        empty($time_in) || empty($time_out)) {
        $error = "All fields are required.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        // Check if email already exists in studio_owners table
        $stmt = $pdo->prepare("SELECT OwnerID FROM studio_owners WHERE Email = ?");
        $stmt->execute([$email]);
        $owner = $stmt->fetch();
        
        if ($owner) {
            $error = "Email already exists. Please use a different email.";
        } else {
            // Check if email exists in clients table
            $stmt = $pdo->prepare("SELECT ClientID FROM clients WHERE Email = ?");
            $stmt->execute([$email]);
            $client = $stmt->fetch();
            
            if ($client) {
                $error = "Email already exists. Please use a different email.";
            } else {
                // Start transaction
                $pdo->beginTransaction();
                
                try {
                    // Set verification status to 1 (Verified) as per your database
                    $v_status = 1;
                    
                    // Insert new studio owner
                    $stmt = $pdo->prepare("INSERT INTO studio_owners (Name, Email, Phone, Password, V_StatsID) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $email, $phone, $password, $v_status]);
                    
                    // Get the new owner ID
                    $owner_id = $pdo->lastInsertId();
                    
                    // Insert new studio
                    $stmt = $pdo->prepare("INSERT INTO studios (OwnerID, StudioName, Latitude, Longitude, Loc_Desc, Time_IN, Time_OUT) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$owner_id, $studio_name, $latitude, $longitude, $location, $time_in, $time_out]);
                    
                    // Commit transaction
                    $pdo->commit();
                    
                    // Registration successful
                    $_SESSION["registration_success"] = true;
                    
                    // Redirect to login page
                    header("Location: login.php");
                    exit();
                } catch (Exception $e) {
                    // Rollback transaction on error
                    $pdo->rollBack();
                    $error = "Registration failed: " . $e->getMessage();
                }
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
    <title>Studio Owner Registration - MuSeek</title>
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
            background-color: rgba(0, 0, 0, 0.5);
            z-index: -1;
        }

        #site-content {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            padding: 40px 0;
        }

        .fullwidth-block {
            text-align: center;
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
        }

        #branding {
            margin: 0 0 30px;
            display: block;
        }

        #branding img {
            width: 250px;
            margin: 0 auto;
            display: block;
        }

        .contact-form {
            max-width: 700px;
            margin: 0 auto;
            padding: 40px;
            background: rgba(0, 0, 0, 0.85);
            border-radius: 20px;
            text-align: center;
            position: relative;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .contact-form h2 {
            font-size: 32px;
            margin-top: 0;
            margin-bottom: 30px;
            color: #fff;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .form-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            justify-content: space-between;
        }

        .form-section {
            flex: 1 1 45%;
            min-width: 280px;
            margin-bottom: 20px;
            padding: 20px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .form-section h3 {
            text-align: left;
            margin-top: 0;
            margin-bottom: 20px;
            color: #e50914;
            font-size: 20px;
            border-bottom: 2px solid rgba(229, 9, 20, 0.5);
            padding-bottom: 10px;
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
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            border-radius: 8px;
            box-sizing: border-box;
            text-align: left;
            position: relative;
            z-index: 0;
            transition: all 0.3s ease;
        }

        .form-group input:focus {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(229, 9, 20, 0.5);
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.25);
        }

        .form-group input::placeholder {
            color: transparent;
        }

        .form-group input:focus+label,
        .form-group input:not(:placeholder-shown)+label {
            top: -10px;
            left: 10px;
            font-size: 12px;
            color: #e50914;
            background: rgba(0, 0, 0, 0.8);
            border-radius: 4px;
            padding: 0 8px;
            font-weight: 600;
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
            transition: color 0.3s ease;
        }

        .form-group .toggle-password:hover {
            color: #e50914;
        }

        .contact-form input[type="submit"] {
            width: 100%;
            padding: 16px;
            font-size: 18px;
            background-color: #e50914;
            border: none;
            color: #fff;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 20px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(229, 9, 20, 0.3);
        }

        .contact-form input[type="submit"]:hover {
            background-color: #f40612;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(229, 9, 20, 0.4);
        }

        .contact-form .additional-options {
            text-align: center;
            margin-top: 25px;
            color: #999;
        }

        .contact-form .additional-options a,
        .contact-form .additional-options p {
            color: #999;
            text-decoration: none;
            font-size: 15px;
            transition: color 0.3s ease;
        }

        .contact-form .additional-options a {
            color: #e50914;
            font-weight: 600;
        }

        .contact-form .additional-options a:hover {
            text-decoration: underline;
            color: #f40612;
        }

        .contact-form .terms {
            text-align: left;
            margin: 20px 0;
            color: #ccc;
            display: flex;
            align-items: center;
        }

        .contact-form .terms input[type="checkbox"] {
            margin-right: 10px;
            width: 18px;
            height: 18px;
            accent-color: #e50914;
        }

        .contact-form .error {
            color: #e87c03;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: left;
            background: rgba(232, 124, 3, 0.1);
            padding: 10px 15px;
            border-radius: 8px;
            border-left: 4px solid #e87c03;
        }

        .form-divider {
            width: 100%;
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
            margin: 20px 0;
        }

        .form-note {
            font-size: 13px;
            color: #999;
            margin-top: 5px;
            text-align: left;
        }

        .form-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .form-header p {
            color: #ccc;
            font-size: 16px;
            max-width: 600px;
            margin: 0 auto;
        }

        @media (max-width: 768px) {
            .contact-form {
                padding: 30px 20px;
            }
            
            .form-section {
                flex: 1 1 100%;
            }
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
                    <div class="form-header">
                        <h2>Register Your Studio</h2>
                        <p>Join the MuSeek community and showcase your studio to potential clients. Fill out the form below to get started.</p>
                    </div>
                    
                    <?php if (!empty($error)): ?>
                        <div class="error"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
                        <div class="form-container">
                            <div class="form-section">
                                <h3><i class="fa fa-user"></i> Owner Information</h3>
                                <div class="form-group">
                                    <input type="text" name="name" id="name" placeholder=" " value="<?php echo htmlspecialchars($name); ?>" required>
                                    <label for="name">Owner Name</label>
                                </div>
                                <div class="form-group">
                                    <input type="text" name="phone" id="phone" placeholder=" " value="<?php echo htmlspecialchars($phone); ?>" required>
                                    <label for="phone">Phone Number</label>
                                </div>
                                <div class="form-group">
                                    <input type="email" name="email" id="email" placeholder=" " value="<?php echo htmlspecialchars($email); ?>" required>
                                    <label for="email">Email Address</label>
                                </div>
                                <div class="form-group">
                                    <input type="password" name="password" id="password" placeholder=" " required>
                                    <label for="password">Password</label>
                                    <i class="fa fa-eye toggle-password" onclick="togglePassword('password')"></i>
                                </div>
                                <div class="form-group">
                                    <input type="password" name="confirm_password" id="confirm_password" placeholder=" " required>
                                    <label for="confirm_password">Re-enter your password</label>
                                    <i class="fa fa-eye toggle-password" onclick="togglePassword('confirm_password')"></i>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <h3><i class="fa fa-music"></i> Studio Information</h3>
                                <div class="form-group">
                                    <input type="text" name="studio_name" id="studio_name" placeholder=" " value="<?php echo htmlspecialchars($studio_name); ?>" required>
                                    <label for="studio_name">Studio Name</label>
                                </div>
                                <div class="form-group">
                                    <input type="text" name="location" id="location" placeholder=" " value="<?php echo htmlspecialchars($location); ?>" required>
                                    <label for="location">Location Description</label>
                                    <div class="form-note">E.g., "Main St., Bacolod City, Negros Occidental"</div>
                                </div>
                                <div class="form-group">
                                    <input type="text" name="latitude" id="latitude" placeholder=" " value="<?php echo htmlspecialchars($latitude); ?>" required>
                                    <label for="latitude">Latitude</label>
                                    <div class="form-note">E.g., "10.630673"</div>
                                </div>
                                <div class="form-group">
                                    <input type="text" name="longitude" id="longitude" placeholder=" " value="<?php echo htmlspecialchars($longitude); ?>" required>
                                    <label for="longitude">Longitude</label>
                                    <div class="form-note">E.g., "122.9786412"</div>
                                </div>
                                <div class="form-group">
                                    <input type="time" name="time_in" id="time_in" placeholder=" " value="<?php echo htmlspecialchars($time_in); ?>" required>
                                    <label for="time_in">Opening Time</label>
                                </div>
                                <div class="form-group">
                                    <input type="time" name="time_out" id="time_out" placeholder=" " value="<?php echo htmlspecialchars($time_out); ?>" required>
                                    <label for="time_out">Closing Time</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-divider"></div>
                        
                        <div class="terms">
                            <input type="checkbox" name="terms" id="terms" required>
                            <label for="terms">I agree to all statements in the Terms of Service and Privacy Policy</label>
                        </div>
                        
                        <input type="submit" value="Register Studio">
                    </form>
                    
                    <div class="additional-options">
                        <p>Already have an account? <a href="login.php">Login here</a></p>
                        <p>Looking to book a studio? <a href="register.php">Register as client</a></p>
                    </div>
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
    </script>
</body>

</html>
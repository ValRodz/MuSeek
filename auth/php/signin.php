<?php require_once '../../shared/config/path_config.php'; ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>Register - MuSeek</title>
    <!-- Loading third party fonts -->
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" rel="stylesheet" type="text/css">

    <style>
        body {
            background: url('../../shared/assets/images/dummy/slide-2.jpg') no-repeat center center fixed;
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
            /* Increased spacing for clarity */
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
            /* Ensure label is above input */
        }

        .form-group input {
            width: 100%;
            padding: 15px;
            padding-right: 40px;
            /* Add padding to accommodate eye icon */
            font-size: 16px;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #fff;
            border-radius: 4px;
            box-sizing: border-box;
            text-align: left;
            position: relative;
            z-index: 0;
            /* Ensure input is below label */
        }

        .form-group input::placeholder {
            color: transparent;
        }

        /* Floating label behavior */
        .form-group input:focus+label,
        .form-group input:not(:placeholder-shown)+label {
            top: -8px;
            /* Adjusted for better alignment */
            left: 10px;
            /* Align with input border */
            font-size: 13px;
            color: #fff;
            background: rgba(0, 0, 0, 0.9);
            border-radius: 4px;
            /* Background to prevent overlap with input border */
            padding: 0 5px;
            /* Small padding for better appearance */
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
            /* Ensure icon is above input and label */
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

        .contact-form .terms {
            text-align: left;
            margin-bottom: 15px;
            color: #ccc;
        }

        .contact-form .terms input[type="checkbox"] {
            margin-right: 10px;
        }

        .contact-form .error {
            color: #e87c03;
            margin-bottom: 15px;
            font-size: 14px;
            display: none;
        }
    </style>
</head>

<body class="header-collapse">
    <div id="site-content">
        <main class="main-content">
            <div class="fullwidth-block">
                <a id="branding">
                    <img src="<?php echo getImagePath('images/logo4.png'); ?>" alt="MuSeek">
                </a>
                <div class="contact-form">
                    <h2>Create Account</h2>
                    <form action="process_signin.php" method="POST">
                        <div class="form-group">
                            <input type="text" name="name" id="name" placeholder=" " required>
                            <label for="name">Name</label>
                        </div>
                        <div class="form-group">
                            <input type="text" name="phone" id="phone" placeholder=" " required>
                            <label for="phone">Phone Number</label>
                        </div>
                        <div class="form-group">
                            <input type="email" name="email" id="email" placeholder=" " required>
                            <label for="email">Email Address</label>
                        </div>
                        <div class="form-group">
                            <input type="password" name="password" id="password" placeholder=" " required>
                            <label for="password">Password</label>
                            <i class="fa fa-eye toggle-password" onclick="togglePassword('password')"></i>
                        </div>
                        <div class="form-group">
                            <input type="password" name="confirm_password" id="confirm_password" placeholder=" "
                                required>
                            <label for="confirm_password">Re-enter your password</label>
                            <i class="fa fa-eye toggle-password" onclick="togglePassword('confirm_password')"></i>
                        </div>
                        <div class="terms">
                            <input type="checkbox" name="terms" id="terms" required>
                            <label for="terms">I agree all statements in Terms of Service</label>
                        </div>
                        <input type="submit" value="Sign Up">
                    </form>
                    <div class="additional-options">
                        <p>Have already an account? <a href="login.php">Login here</a></p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="loading-overlay" class="loading-overlay" aria-hidden="true">
        <div class="loading-content">
            <div class="spinner" aria-hidden="true"></div>
            <div class="loading-message">Please wait a little while we process your registration</div>
        </div>
    </div>

    <style>
        .loading-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; z-index: 9999; }
        .loading-overlay.show { display: flex; }
        .loading-content { text-align: center; color: #fff; }
        .spinner { width: 48px; height: 48px; border: 4px solid rgba(255,255,255,0.25); border-top-color: #fff; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 16px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .loading-message { font-size: 16px; }
    </style>

    <script>
        function togglePassword(fieldId) {
            const passwordField = document.getElementById(fieldId);
            const toggleIcon = passwordField.nextElementSibling.nextElementSibling; // Adjusted to skip the label
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

        // Show loading overlay on form submit
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('.contact-form form');
            const overlay = document.getElementById('loading-overlay');
            if (form && overlay) {
                form.addEventListener('submit', function() {
                    overlay.classList.add('show');
                    const submit = form.querySelector('input[type="submit"]');
                    if (submit) { submit.disabled = true; submit.value = 'Processing...'; }
                });
            }
        });
    </script>
</body>

</html>
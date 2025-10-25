<?php
session_start();
include '../../shared/config/db.php';
include '../../shared/config/path_config.php';

$is_authenticated = isset($_SESSION['user_id']) && isset($_SESSION['user_type']);

if ($is_authenticated) {
    $client_query = "SELECT ClientID, Name, Email, Phone FROM clients WHERE ClientID = ?";
    $stmt = mysqli_prepare($conn, $client_query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $client_result = mysqli_stmt_get_result($stmt);
    $client = mysqli_fetch_assoc($client_result) ?: [
        'ClientID' => 0,
        'Name' => 'Unknown',
        'Email' => 'N/A',
        'Phone' => 'N/A'
    ];
    mysqli_stmt_close($stmt);
    error_log("Client data: " . print_r($client, true));
} else {
    $client = [
        'ClientID' => 0,
        'Name' => 'Guest',
        'Email' => 'N/A',
        'Phone' => 'N/A'
    ];
    error_log("Guest user accessing profile.php");
}

$studio_id = isset($_GET['studio_id']) ? (int)$_GET['studio_id'] : 0;
$studio_query = "
    SELECT s.StudioName, s.Loc_Desc, s.StudioImg, s.Time_IN, s.Time_OUT, s.OwnerID, so.Name AS OwnerName, so.Email AS OwnerEmail, so.Phone AS OwnerPhone
    FROM studios s
    LEFT JOIN studio_owners so ON s.OwnerID = so.OwnerID
    WHERE s.StudioID = ?";
$stmt = mysqli_prepare($conn, $studio_query);
mysqli_stmt_bind_param($stmt, "i", $studio_id);
mysqli_stmt_execute($stmt);
$studio_result = mysqli_stmt_get_result($stmt);
$studio = mysqli_fetch_assoc($studio_result);

if ($studio && $studio['StudioImg']) {
    $studio['StudioImgBase64'] = 'data:image/jpeg;base64,' . base64_encode($studio['StudioImg']);
} else {
    $studio['StudioImgBase64'] = '../../shared/assets/images/default_studio.jpg';
}

if (!$studio) {
    $studio = [
        'StudioID' => 1,
        'StudioName' => 'Test Studio',
        'Loc_Desc' => 'Test Location',
        'Time_IN' => '09:00:00',
        'Time_OUT' => '22:00:00',
        'StudioImgBase64' => '../../shared/assets/images/default_studio.jpg',
        'OwnerID' => 1,
        'OwnerName' => 'Test Owner',
        'OwnerEmail' => 'test@example.com',
        'OwnerPhone' => '+639501234567'
    ];
    error_log("No studio found for StudioID $studio_id, using test data");
}

$services_query = "
    SELECT se.ServiceType, se.Description, se.Price
    FROM studio_services ss
    LEFT JOIN services se ON ss.ServiceID = se.ServiceID
    WHERE ss.StudioID = ?";
$stmt = mysqli_prepare($conn, $services_query);
mysqli_stmt_bind_param($stmt, "i", $studio_id);
mysqli_stmt_execute($stmt);
$services_result = mysqli_stmt_get_result($stmt);

$services = [];
while ($service_row = mysqli_fetch_assoc($services_result)) {
    $services[] = $service_row;
}
$studio['services'] = $services;
mysqli_stmt_close($stmt);

error_log("Studio data: " . print_r($studio, true));

// Handle chat submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chat_message'])) {
    $message = trim($_POST['chat_message']);
    $response = '';
    $is_user_message = true;

    // Simple chatbot logic based on keywords
    if (stripos($message, 'services') !== false) {
        if (!empty($services)) {
            $response = "Available services:\n";
            foreach ($services as $service) {
                $response .= "- {$service['ServiceType']}: {$service['Description']} (₱" . number_format($service['Price'], 2) . ")\n";
            }
        } else {
            $response = "No services available for this studio.";
        }
    } elseif (stripos($message, 'hours') !== false || stripos($message, 'time') !== false) {
        $response = "Studio hours: {$studio['Time_IN']} to {$studio['Time_OUT']}";
    } elseif (stripos($message, 'contact') !== false) {
        $response = "Contact the studio at {$studio['OwnerEmail']} or {$studio['OwnerPhone']}.";
    } else {
        $response = "Sorry, I didn't understand that. Try asking about 'services', 'hours', or 'contact'.";
    }

    // Log user message
    if ($message) {
        $chat_query = "INSERT INTO chatlog (ChatID, OwnerID, ClientID, Timestamp, Content) VALUES (?, ?, ?, NOW(), ?)";
        $chat_id = rand(1, 1000000); // Temporary unique ID; consider auto-increment
        $client_id = $is_authenticated ? $client['ClientID'] : NULL;
        $stmt = mysqli_prepare($conn, $chat_query);
        mysqli_stmt_bind_param($stmt, "iiis", $chat_id, $studio['OwnerID'], $client_id, $message);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    // Log bot response
    if ($response) {
        $chat_id = rand(1, 1000000); // New unique ID for response
        $chat_query = "INSERT INTO chatlog (ChatID, OwnerID, ClientID, Timestamp, Content) VALUES (?, ?, ?, NOW(), ?)";
        $stmt = mysqli_prepare($conn, $chat_query);
        mysqli_stmt_bind_param($stmt, "iiis", $chat_id, $studio['OwnerID'], $client_id, $response);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    // Return JSON response for AJAX
    header('Content-Type: application/json');
    echo json_encode(['response' => $response]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title><?php echo htmlspecialchars($studio['StudioName']); ?> - MuSeek</title>
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="<?php echo getCSSPath('style.css'); ?>">
    <style>
        :root {
            --primary-color: #e50914;
            --primary-hover: #f40612;
            --bg-dark: #141414;
            --bg-card: rgba(30, 30, 30, 0.8);
            --bg-card-hover: rgba(40, 40, 40, 0.9);
            --text-primary: #ffffff;
            --text-secondary: #cccccc;
            --border-color: #333333;
            --shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
            --radius: 8px;
            --transition: all 0.3s ease;
        }
        
        #branding img {
            width: 180px;
            display: block;
        }

        .hero {
            position: relative;
            width: 100%;
            height: 60vh;
            background: linear-gradient(rgba(0, 0, 0, 0.6), rgba(0, 0, 0, 0.8)), url('<?php echo $studio['StudioImgBase64']; ?>') no-repeat center center;
            background-size: cover;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1;
        }
        
        .hero::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 100px;
            background: linear-gradient(to top, var(--bg-dark), transparent);
        }

        .hero-content {
            text-align: center;
            color: #fff;
            z-index: 2;
            padding: 20px;
        }

        .hero-content h1 {
            font-size: 48px;
            margin: 0 0 15px;
            font-weight: 700;
            letter-spacing: 1px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.7);
        }

        .hero-content p {
            font-size: 20px;
            margin: 0;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.7);
            opacity: 0.9;
        }

        .profile-section {
            padding: 60px 20px;
            background: #1a1a1a;
            color: #fff;
            min-height: 50vh;
        }

        .profile-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            flex-wrap: wrap;
            gap: 40px;
        }

        .profile-details {
            flex: 1;
            min-width: 300px;
            background: var(--bg-card);
            padding: 30px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            transition: var(--transition);
            border: 1px solid var(--border-color);
        }
        
        .profile-details:hover {
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.3);
            background: var(--bg-card-hover);
        }

        .profile-details h2 {
            font-size: 28px;
            margin: 0 0 20px;
            color: var(--primary-color);
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 15px;
        }

        .profile-details p {
            font-size: 16px;
            color: var(--text-secondary);
            margin: 0 0 10px;
            line-height: 1.8;
        }

        .services-list ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .services-list li {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 10px;
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: var(--transition);
        }
        
        .services-list li:hover {
            background: rgba(50, 50, 50, 0.3);
            transform: translateX(5px);
        }

        .services-list li span {
            color: var(--primary-color);
            font-weight: bold;
        }

        .contact-info {
            flex: 1;
            min-width: 300px;
            background: var(--bg-card);
            padding: 30px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            transition: var(--transition);
            border: 1px solid var(--border-color);
            height: fit-content;
        }
        
        .contact-info:hover {
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.3);
            background: var(--bg-card-hover);
        }

        .contact-info h2 {
            font-size: 28px;
            margin: 0 0 20px;
            color: var(--primary-color);
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 15px;
        }

        .contact-info p {
            font-size: 16px;
            color: var(--text-secondary);
            margin: 0 0 10px;
            display: flex;
            align-items: center;
        }

        .contact-info p i {
            margin-right: 15px;
            color: var(--primary-color);
            font-size: 18px;
            width: 20px;
            text-align: center;
        }

        .book-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 14px 28px;
            background-color: var(--primary-color);
            color: #fff;
            border: none;
            border-radius: var(--radius);
            font-size: 16px;
            font-weight: 600;
            letter-spacing: 0.5px;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            margin-top: 25px;
            transition: var(--transition);
        }
        
        .book-button i {
            margin-right: 8px;
        }

        .book-button:hover {
            background-color: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(229, 9, 20, 0.3);
        }
        
        /* Messenger Button */
        .messenger-button {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow);
            cursor: pointer;
            z-index: 100;
            transition: var(--transition);
        }
        
        .messenger-button i {
            font-size: 24px;
        }
        
        .messenger-button:hover {
            background-color: var(--primary-hover);
            transform: scale(1.1);
        }
        
        .messenger-popup {
            position: fixed;
            bottom: 100px;
            right: 30px;
            width: 320px;
            height: 400px;
            background-color: var(--bg-card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            z-index: 99;
            display: none;
            flex-direction: column;
            border: 1px solid var(--border-color);
            overflow: hidden;
        }
        
        .messenger-header {
            padding: 15px;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .messenger-header h3 {
            margin: 0;
            font-size: 16px;
        }
        
        .messenger-close {
            cursor: pointer;
            font-size: 18px;
        }
        
        .messenger-body {
            flex: 1;
            padding: 15px;
            overflow-y: auto;
            background: #1a1a1a;
        }
        
        .messenger-footer {
            padding: 10px;
            background: #2a2a2a;
            display: flex;
        }
        
        .messenger-footer input {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 4px 0 0 4px;
            background: #333;
            color: white;
        }
        
        .messenger-footer button {
            padding: 10px 15px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 0 4px 4px 0;
            cursor: pointer;
        }
        
        /* Gallery Section */
        .gallery-section {
            padding: 40px 20px;
            background: #1a1a1a;
        }
        
        .gallery-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .gallery-heading {
            color: var(--primary-color);
            text-align: center;
            margin-bottom: 30px;
            font-size: 32px;
            font-weight: 600;
        }
        
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .gallery-item {
            position: relative;
            overflow: hidden;
            border-radius: var(--radius);
            height: 200px;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }
        
        .gallery-item:hover img {
            transform: scale(1.05);
        }
        
        .gallery-item::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 50%;
            background: linear-gradient(to top, rgba(0,0,0,0.7), transparent);
            opacity: 0;
            transition: var(--transition);
        }
        
        .gallery-item:hover::after {
            opacity: 1;
        }

        .profile-item {
            position: relative;
        }

        .profile-link {
            display: flex;
            align-items: center;
            color: #fff;
            text-decoration: none;
            transition: color 0.3s;
        }

        .profile-link i {
            margin-right: 5px;
            font-size: 18px;
        }

        .profile-link:hover {
            color: #e50914;
        }

        .profile-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background: rgba(0, 0, 0, 0.9);
            border-radius: 5px;
            padding: 15px;
            min-width: 200px;
            z-index: 100;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.5);
        }

        .profile-dropdown.show {
            display: block;
        }

        .profile-info p {
            margin: 0 0 10px;
            color: #ccc;
            font-size: 14px;
        }

        .profile-info p strong {
            color: #fff;
        }

        .logout-button {
            display: block;
            width: 100%;
            padding: 8px;
            background-color: #e50914;
            border: none;
            color: #fff;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            text-align: center;
        }

        .logout-button:hover {
            background-color: #f40612;
        }

        /* Chat Styles */
        .chat-button {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background-color: #e50914;
            color: #fff;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .chat-button:hover {
            background-color: #f40612;
        }

        .chat-window {
            display: none;
            position: fixed;
            bottom: 80px;
            right: 20px;
            width: 300px;
            height: 400px;
            background: #2a2a2a;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
            z-index: 1000;
            flex-direction: column;
        }

        .chat-window.show {
            display: flex;
        }

        .chat-header {
            background: #e50914;
            color: #fff;
            padding: 10px;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chat-header h3 {
            margin: 0;
            font-size: 16px;
        }

        .chat-close {
            cursor: pointer;
            font-size: 16px;
        }

        .chat-body {
            flex: 1;
            padding: 10px;
            overflow-y: auto;
            background: #1a1a1a;
            color: #ccc;
        }

        .chat-message {
            margin-bottom: 10px;
        }

        .chat-message.user {
            text-align: right;
        }

        .chat-message.user span {
            background: #e50914;
            padding: 8px;
            border-radius: 8px;
            display: inline-block;
            max-width: 80%;
        }

        .chat-message.bot span {
            background: #333;
            padding: 8px;
            border-radius: 8px;
            display: inline-block;
            max-width: 80%;
        }

        .chat-footer {
            padding: 10px;
            background: #2a2a2a;
            border-bottom-left-radius: 8px;
            border-bottom-right-radius: 8px;
        }

        .chat-footer form {
            display: flex;
        }

        .chat-footer input {
            flex: 1;
            padding: 8px;
            border: none;
            border-radius: 4px 0 0 4px;
            background: #333;
            color: #fff;
        }

        .chat-footer button {
            padding: 8px 12px;
            background: #e50914;
            color: #fff;
            border: none;
            border-radius: 0 4px 4px 0;
            cursor: pointer;
        }

        .chat-footer button:hover {
            background: #f40612;
        }

        @media (max-width: 768px) {
            .hero {
                height: 40vh;
            }

            .hero-content h1 {
                font-size: 24px;
            }

            .hero-content p {
                font-size: 14px;
            }

            .profile-section {
                padding: 20px 10px;
            }

            .profile-container {
                flex-direction: column;
                gap: 20px;
            }

            .profile-details h2,
            .contact-info h2 {
                font-size: 24px;
            }

            .profile-details p,
            .contact-info p {
                font-size: 14px;
            }

            .services-list li {
                font-size: 12px;
            }

            .book-button {
                padding: 8px 16px;
                font-size: 14px;
            }

            .profile-link i {
                font-size: 16px;
            }

            .profile-dropdown {
                min-width: 180px;
                padding: 10px;
            }

            .profile-info p {
                font-size: 12px;
            }

            .logout-button {
                padding: 6px;
                font-size: 12px;
            }

            .chat-window {
                width: 90%;
                height: 300px;
                right: 5%;
                bottom: 60px;
            }

            .chat-button {
                bottom: 10px;
                right: 10px;
            }
        }
    </style>
</head>

<body class="header-collapse">
    <div id="site-content">
        <header class="site-header">
            <?php include '../../shared/components/navbar.php'; ?>
        </header>
        <div class="hero">
            <div class="hero-content">
                <h1><?php echo htmlspecialchars($studio['StudioName']); ?></h1>
                <p><?php echo htmlspecialchars($studio['Loc_Desc']); ?></p>
            </div>
        </div>
        <main class="main-content">
            <div class="profile-section">
                <div class="profile-container">
                    <div class="profile-details">
                        <h2>About the Studio</h2>
                        <p><?php echo htmlspecialchars($studio['Loc_Desc']); ?></p>
                        <h2>Services Offered</h2>
                        <div class="services-list">
                            <?php if (!empty($studio['services'])): ?>
                                <ul>
                                    <?php foreach ($studio['services'] as $service): ?>
                                        <li>
                                            <?php echo htmlspecialchars($service['ServiceType']); ?>:
                                            <?php echo htmlspecialchars($service['Description']); ?>
                                            (<span>₱<?php echo number_format($service['Price'], 2); ?></span>)
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p>No services available.</p>
                            <?php endif; ?>
                        </div>
                        <button class="book-button" onclick="handleBookClick(<?php echo $studio_id; ?>, <?php echo $is_authenticated ? 'true' : 'false'; ?>)">Book Now</button>
                    </div>
                    <div class="contact-info">
                        <h2>Contact Information</h2>
                        <p><i class="fa fa-user"></i> <?php echo htmlspecialchars($studio['OwnerName']); ?></p>
                        <p><i class="fa fa-envelope"></i> <?php echo htmlspecialchars($studio['OwnerEmail']); ?></p>
                        <p><i class="fa fa-phone"></i> <?php echo htmlspecialchars($studio['OwnerPhone']); ?></p>
                        <p><i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($studio['Loc_Desc']); ?></p>
                        <?php if ($studio_id): ?>
                            <p style="margin-top:12px;">
                                <a href="../../messaging/php/chat.php?studio_id=<?php echo $studio_id; ?>" class="book-button" style="background:#2b8cff;"> 
                                    <i class="fa fa-comment"></i>&nbsp; Chat with Owner
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Gallery Section -->
            <div class="gallery-section">
                <div class="gallery-container">
                    <h2 class="gallery-heading">Studio Gallery</h2>
                    <div class="gallery-grid">
                        <!-- Sample gallery images - in a real implementation, these would come from the database -->
                        <div class="gallery-item">
                            <img src="../../shared/assets/images/studio-gallery-1.jpg" alt="Studio Image 1" onerror="this.src='../../shared/assets/images/default_studio.jpg'">
                        </div>
                        <div class="gallery-item">
                            <img src="../../shared/assets/images/studio-gallery-2.jpg" alt="Studio Image 2" onerror="this.src='../../shared/assets/images/default_studio.jpg'">
                        </div>
                        <div class="gallery-item">
                            <img src="../../shared/assets/images/studio-gallery-3.jpg" alt="Studio Image 3" onerror="this.src='../../shared/assets/images/default_studio.jpg'">
                        </div>
                        <div class="gallery-item">
                            <img src="../../shared/assets/images/studio-gallery-4.jpg" alt="Studio Image 4" onerror="this.src='../../shared/assets/images/default_studio.jpg'">
                        </div>
                    </div>
                </div>
            </div>
        </main>
        <?php include '../../shared/components/footer.php'; ?>

        <!-- Messenger Button -->
        <div class="messenger-button" onclick="toggleMessenger()">
            <i class="fa fa-comment"></i>
        </div>

        <!-- Messenger Popup -->
        <div class="messenger-popup" id="messenger-popup">
            <div class="messenger-header">
                <h3>Chat with <?php echo htmlspecialchars($studio['OwnerName']); ?></h3>
                <span class="messenger-close" onclick="toggleMessenger()">&times;</span>
            </div>
            <div class="messenger-body" id="messenger-body">
                <!-- Messages will appear here -->
                <div class="chat-message bot">
                    <span>Hi there! How can I help you with <?php echo htmlspecialchars($studio['StudioName']); ?>?</span>
                </div>
            </div>
            <div class="messenger-footer">
                <input type="text" id="messenger-input" placeholder="Type your message...">
                <button onclick="sendMessage()"><i class="fa fa-paper-plane"></i></button>
            </div>
        </div>

        <script>
            function toggleMessenger() {
                const popup = document.getElementById('messenger-popup');
                if (popup.style.display === 'flex') {
                    popup.style.display = 'none';
                } else {
                    popup.style.display = 'flex';
                    document.getElementById('messenger-input').focus();
                }
            }

            function sendMessage() {
                const input = document.getElementById('messenger-input');
                const message = input.value.trim();
                
                if (message) {
                    const messagesContainer = document.getElementById('messenger-body');
                    
                    // Add user message
                    const userMessageDiv = document.createElement('div');
                    userMessageDiv.className = 'chat-message user';
                    userMessageDiv.innerHTML = `<span>${message}</span>`;
                    messagesContainer.appendChild(userMessageDiv);
                    
                    // Clear input
                    input.value = '';
                    
                    // Scroll to bottom
                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    
                    // In a real app, you would send this message to the server
                    // For now, just simulate a response after a short delay
                    setTimeout(() => {
                        const botMessageDiv = document.createElement('div');
                        botMessageDiv.className = 'chat-message bot';
                        botMessageDiv.innerHTML = `<span>Thanks for your message! The studio owner will get back to you soon.</span>`;
                        messagesContainer.appendChild(botMessageDiv);
                        messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    }, 1000);
                }
            }
            
            // Allow pressing Enter to send message
            document.getElementById('messenger-input').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    sendMessage();
                }
            });
        </script>

        <!-- Chat Interface -->
        <div class="chat-button" onclick="toggleChat()">
            <i class="fa fa-comments"></i>
        </div>
        <div class="chat-window" id="chatWindow">
            <div class="chat-header">
                <h3>Chat with Us</h3>
                <span class="chat-close" onclick="toggleChat()">×</span>
            </div>
            <div class="chat-body" id="chatBody">
                <div class="chat-message bot">
                    <span>Welcome! Ask about services, hours, or contact details.</span>
                </div>
            </div>
            <div class="chat-footer">
                <form id="chatForm" onsubmit="sendMessage(event)">
                    <input type="text" id="chatInput" placeholder="Type your message..." autocomplete="off">
                    <button type="submit"><i class="fa fa-paper-plane"></i></button>
                </form>
            </div>
        </div>
    </div>
    <script src="../../shared/assets/js/jquery-1.11.1.min.js"></script>
    <script src="../../shared/assets/js/plugins.js"></script>
    <script src="../../shared/assets/js/app.js"></script>
    <script>
        document.querySelector('.profile-link')?.addEventListener('click', function(e) {
            e.preventDefault();
            const dropdown = document.querySelector('.profile-dropdown');
            dropdown.classList.toggle('show');
        });

        document.addEventListener('click', function(event) {
            const profileItem = document.querySelector('.profile-item');
            if (profileItem && !profileItem.contains(event.target)) {
                document.querySelector('.profile-dropdown').classList.remove('show');
            }
        });

        function confirmLogout() {
            if (window.confirm("Are you sure you want to log out?")) {
                window.location.href = "logout.php";
            }
        }

        function handleBookClick(studioId, isAuthenticated) {
            if (!isAuthenticated) {
                alert("Please log in or register to book a studio.");
                return;
            }
            window.location.href = `../../booking/php/booking.php?studio_id=${studioId}`;
        }

        function toggleChat() {
            const chatWindow = document.getElementById('chatWindow');
            chatWindow.classList.toggle('show');
        }

        function sendMessage(event) {
            event.preventDefault();
            const input = document.getElementById('chatInput');
            const message = input.value.trim();
            if (!message) return;

            const chatBody = document.getElementById('chatBody');
            const userMessage = document.createElement('div');
            userMessage.className = 'chat-message user';
            userMessage.innerHTML = `<span>${message}</span>`;
            chatBody.appendChild(userMessage);
            chatBody.scrollTop = chatBody.scrollHeight;
            input.value = '';

            // Send message to server
            fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `chat_message=${encodeURIComponent(message)}`
                })
                .then(response => response.json())
                .then(data => {
                    const botMessage = document.createElement('div');
                    botMessage.className = 'chat-message bot';
                    botMessage.innerHTML = `<span>${data.response}</span>`;
                    chatBody.appendChild(botMessage);
                    chatBody.scrollTop = chatBody.scrollHeight;
                })
                .catch(error => {
                    console.error('Chat error:', error);
                    const errorMessage = document.createElement('div');
                    errorMessage.className = 'chat-message bot';
                    errorMessage.innerHTML = `<span>Sorry, something went wrong. Try again later.</span>`;
                    chatBody.appendChild(errorMessage);
                    chatBody.scrollTop = chatBody.scrollHeight;
                });
        }
    </script>
</body>

</html>
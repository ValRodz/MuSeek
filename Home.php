<?php
session_start();
include 'shared/config/db.php';
include 'shared/config/path_config.php';

$is_authenticated = isset($_SESSION['user_id']) && isset($_SESSION['user_type']);

if ($is_authenticated) {
    $client_query = "SELECT Name, Email, Phone FROM clients WHERE ClientID = ?";
    $stmt = mysqli_prepare($conn, $client_query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $client_result = mysqli_stmt_get_result($stmt);
    $client = mysqli_fetch_assoc($client_result) ?: [
        'Name' => 'Unknown',
        'Email' => 'N/A',
        'ContactNumber' => 'N/A'
    ];
    mysqli_stmt_close($stmt);
    error_log("Client data: " . print_r($client, true));
} else {
    $client = [
        'Name' => 'Guest',
        'Email' => 'N/A',
        'ContactNumber' => 'N/A'
    ];
    error_log("Guest user accessing browse.php");
}

$studios_query = "SELECT StudioID, StudioName, Loc_Desc, StudioImg, OwnerID FROM studios";
error_log("Executing query: $studios_query");
$studios_result = mysqli_query($conn, $studios_query);

if (!$studios_result) {
    error_log("Query failed: " . mysqli_error($conn));
    die("Query failed: " . mysqli_error($conn));
}

$row_count = mysqli_num_rows($studios_result);
error_log("Studios fetched: $row_count");

$studios = [];
while ($row = mysqli_fetch_assoc($studios_result)) {
    if ($row['StudioImg']) {
        $row['StudioImgBase64'] = 'data:image/jpeg;base64,' . base64_encode($row['StudioImg']);
    } else {
        $row['StudioImgBase64'] = 'shared/assets/images/default_studio.jpg';
    }

    $studio_id = $row['StudioID'];
    $services_query = "SELECT se.ServiceType, se.Description, se.Price FROM studio_services ss LEFT JOIN services se ON ss.ServiceID = se.ServiceID WHERE ss.StudioID = ?";
    $stmt = mysqli_prepare($conn, $services_query);
    mysqli_stmt_bind_param($stmt, "i", $studio_id);
    mysqli_stmt_execute($stmt);
    $services_result = mysqli_stmt_get_result($stmt);

    $services_row_count = mysqli_num_rows($services_result);
    error_log("Services fetched for StudioID $studio_id: $services_row_count");

    $services = [];
    while ($service_row = mysqli_fetch_assoc($services_result)) {
        $services[] = $service_row;
    }
    $row['services'] = $services;

    $studios[] = $row;
    mysqli_stmt_close($stmt);
}

mysqli_free_result($studios_result);

if (empty($studios)) {
    $studios = [[
        'StudioID' => 1,
        'StudioName' => 'Test Studio',
        'Loc_Desc' => 'Test Location',
        'StudioImgBase64' => 'shared/assets/images/default_studio.jpg',
        'services' => [
            ['ServiceType' => 'Recording', 'Description' => 'High-quality audio recording', 'Price' => 500],
            ['ServiceType' => 'Mixing', 'Description' => 'Professional mixing services', 'Price' => 300],
            ['ServiceType' => 'Mastering', 'Description' => 'Final track polishing', 'Price' => 200],
            ['ServiceType' => 'Rehearsal', 'Description' => 'Studio space for practice', 'Price' => 100]
        ]
    ]];
    error_log("No studios in DB, using test data");
}

error_log("Studios before carousel: " . print_r($studios, true));
$studios_per_slide = 2;
$studio_slides = array_chunk($studios, $studios_per_slide);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>Browse Studios - MuSeek</title>
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="<?php echo getCSSPath('style.css'); ?>">
    <style>
        /* Netflix-style Global Styles */
        body {
            background: #141414;
            color: #fff;
            font-family: 'Source Sans Pro', Arial, sans-serif;
            margin: 0;
            padding: 0;
        }
        
        #branding img { width: 180px; display: block; }
        
        /* Hero Section with Search */
        .hero {
            position: relative;
            width: 100%;
            height: 70vh;
            background: linear-gradient(rgba(0,0,0,0.4), rgba(0,0,0,0.7)), url('<?php echo getDummyPath('slide-1.jpg'); ?>');
            background-size: cover;
            background-position: center;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0;
        }
        
        .hero-content {
            text-align: center;
            max-width: 800px;
            padding: 0 20px;
            z-index: 2;
        }
        
        .hero-title {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.8);
        }
        
        .hero-subtitle {
            font-size: 1.5rem;
            margin-bottom: 2rem;
            opacity: 0.9;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.8);
        }
        
        /* Search Section */
        .search-section {
            background: rgba(255,255,255,0.95);
            border-radius: 16px;
            padding: 30px;
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.2);
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            margin-top: 2rem;
        }
        
        .search-form {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 20px;
            align-items: end;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            color: #333333;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .form-group input, .form-group select {
            padding: 12px 16px;
            border: 1px solid rgba(0,0,0,0.1);
            border-radius: 8px;
            background: #ffffff;
            color: #333333;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #e50914;
            box-shadow: 0 0 0 3px rgba(229, 9, 20, 0.1), 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .form-group input::placeholder {
            color: rgba(0,0,0,0.5);
            font-weight: 400;
        }
        
        .search-btn {
            padding: 12px 24px;
            background: #e50914;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            box-shadow: 0 2px 8px rgba(229, 9, 20, 0.3);
            height: fit-content;
        }
        
        .search-btn:hover {
            background: #f40612;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(229, 9, 20, 0.4);
        }
        
        .search-btn i {
            margin-right: 6px;
        }
        
        /* Studios Grid Section */
        .studios-section {
            background: #141414;
            padding: 60px 0;
            min-height: 100vh;
        }

        .section-title {
            color: #ffffff;
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 40px;
            text-align: center;
            position: relative;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: linear-gradient(90deg, #e50914, #f40612);
            border-radius: 2px;
        }

        .studios-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 30px;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .studio-card {
            background: linear-gradient(145deg, #1f1f1f 0%, #2a2a2a 100%);
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            border: 1px solid rgba(255,255,255,0.1);
            position: relative;
            cursor: pointer;
        }

        .studio-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(229, 9, 20, 0.1) 0%, transparent 50%);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }

        .studio-card:hover::before {
            opacity: 1;
        }

        .studio-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px rgba(0,0,0,0.5), 0 0 0 1px rgba(229, 9, 20, 0.3);
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
        }
        
        .view-toggle {
            display: flex;
            gap: 10px;
        }
        
        .toggle-btn {
            padding: 8px 16px;
            background: #333;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .toggle-btn.active, .toggle-btn:hover {
            background: #e50914;
        }
        
        .studio-image {
            position: relative;
            height: 250px;
            overflow: hidden;
        }
        
        .studio-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s ease;
        }
        
        .studio-card:hover .studio-image img {
            transform: scale(1.05);
        }
        
        .studio-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(transparent, rgba(0,0,0,0.8));
            display: flex;
            align-items: flex-end;
            padding: 20px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .studio-card:hover .studio-overlay {
            opacity: 1;
        }

        /* Favorites toggle button */
        .favorite-btn {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 1px solid rgba(255,255,255,0.2);
            background: rgba(0,0,0,0.5);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 3;
        }

        .favorite-btn.active {
            background: rgba(229, 9, 20, 0.9);
            border-color: rgba(229, 9, 20, 0.7);
        }

        .favorite-btn i {
            font-size: 18px;
        }

        .favorite-btn:hover {
            transform: scale(1.05);
        }
        
        .quick-actions {
            display: flex;
            gap: 10px;
        }
        
        .quick-btn {
            padding: 8px 12px;
            background: rgba(229, 9, 20, 0.9);
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .quick-btn:hover {
            background: #e50914;
            transform: scale(1.05);
        }
        
        .studio-info {
            padding: 25px;
        }
        
        .studio-name {
            color: #ffffff;
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }
        
        .studio-location {
            color: #b3b3b3;
            font-size: 1rem;
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .studio-location i {
            color: #e50914;
        }
        
        .studio-services {
            margin-bottom: 20px;
        }
        
        .services-title {
            font-size: 0.9rem;
            color: #ccc;
            margin: 0 0 10px 0;
            font-weight: 500;
        }
        
        .services-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .service-tag {
            background: rgba(229, 9, 20, 0.2);
            color: #ffffff;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            border: 1px solid rgba(229, 9, 20, 0.3);
            transition: all 0.3s ease;
        }
        
        .service-tag:hover {
            background: rgba(229, 9, 20, 0.3);
            border-color: rgba(229, 9, 20, 0.5);
        }
        
        .service-tag.featured {
            background: #e50914;
            border-color: #e50914;
        }
        
        .studio-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }
        
        .action-btn {
            flex: 1;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: relative;
            overflow: hidden;
        }
        
        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .action-btn:hover::before {
            left: 100%;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #e50914 0%, #b8070f 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(229, 9, 20, 0.3);
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #f40612 0%, #d1080e 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(229, 9, 20, 0.4);
        }
        
        .btn-secondary {
            background: rgba(255,255,255,0.1);
            color: #ffffff;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .btn-secondary:hover {
            background: rgba(255,255,255,0.2);
            border-color: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        .btn-message {
            background: #0066cc;
            color: #fff;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-message:hover {
            background: #0052a3;
        }
        
        .btn-tertiary {
            background: rgba(229, 9, 20, 0.1);
            color: #e50914;
            border: 1px solid rgba(229, 9, 20, 0.3);
        }
        
        .btn-tertiary:hover {
            background: rgba(229, 9, 20, 0.2);
            border-color: rgba(229, 9, 20, 0.5);
            transform: translateY(-2px);
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
        /* Responsive Design */
        @media (max-width: 1200px) {
            .studios-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 25px;
            }
            
            .search-form {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .hero-title {
                font-size: 3.5rem;
            }
        }

        @media (max-width: 768px) {
            .hero {
                padding: 60px 0 40px;
            }
            
            .hero-title {
                font-size: 2.8rem;
            }
            
            .hero-subtitle {
                font-size: 1.2rem;
            }
            
            .search-section {
                padding: 30px 20px;
            }
            
            .studios-grid {
                grid-template-columns: 1fr;
                gap: 20px;
                padding: 0 15px;
            }
            
            .section-title {
                font-size: 2rem;
            }
            
            .studio-image {
                height: 200px;
            }
            
            .studio-actions {
                flex-direction: column;
                gap: 10px;
            }
            
            .action-btn {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .hero-title {
                font-size: 2.2rem;
            }
            
            .hero-subtitle {
                font-size: 1rem;
            }
            
            .search-section {
                padding: 20px 15px;
            }
            
            .section-title {
                font-size: 1.8rem;
            }
            
            .studio-info {
                padding: 20px;
            }
            
            .studio-name {
                font-size: 1.3rem;
            }
        }
        
        /* Loading and Animation Styles */
        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 200px;
            color: #ffffff;
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* No Studios Message */
        .no-studios {
            text-align: center;
            padding: 60px 20px;
            color: #b3b3b3;
            grid-column: 1 / -1;
        }

        .no-studios i {
            font-size: 4rem;
            color: #e50914;
            margin-bottom: 20px;
        }

        .no-studios h3 {
            font-size: 1.8rem;
            color: #ffffff;
            margin-bottom: 10px;
        }

        .no-studios p {
            font-size: 1.1rem;
            opacity: 0.8;
        }

        /* Service Tags Enhanced */
        .services-title {
            color: #ffffff;
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .service-tag.featured {
            background: linear-gradient(135deg, #e50914 0%, #b8070f 100%);
            color: white;
            border-color: #e50914;
        }

        .service-tag small {
            display: block;
            font-size: 0.7rem;
            opacity: 0.8;
            margin-top: 2px;
        }

        /* Message Button Specific */
        .btn-message {
            background: rgba(0, 123, 255, 0.2);
            color: #007bff;
            border: 1px solid rgba(0, 123, 255, 0.3);
        }

        .btn-message:hover {
            background: rgba(0, 123, 255, 0.3);
            border-color: rgba(0, 123, 255, 0.5);
            transform: translateY(-2px);
        }
        
        /* Filter and Sort Styles */
        .filters-section {
            background: #1f1f1f;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
        }
        
        .filters-title {
            font-size: 1.2rem;
            margin-bottom: 15px;
            color: #fff;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        #clientChatModalOverlay {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0; top: 0; width: 100vw; height: 100vh;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
        }
        #clientChatModalOverlay.active { display: flex; }
        #clientChatModal {
            background: #222;
            color: #fff;
            border-radius: 12px;
            width: 350px;
            max-width: 95vw;
            box-shadow: 0 2px 16px rgba(0,0,0,0.4);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
            animation: modalIn 0.2s;
        }
        @keyframes modalIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        #clientChatModalHeader {
            background: #e50914;
            padding: 14px 16px;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        #clientChatModalClose {
            cursor: pointer;
            font-size: 20px;
            color: #fff;
            background: none;
            border: none;
        }
        #clientChatModalBody {
            flex: 1;
            padding: 14px 16px;
            overflow-y: auto;
            background: #181818;
        }
        #clientChatModalInputArea {
            display: flex;
            border-top: 1px solid #333;
        }
        #clientChatModalInput {
            flex: 1;
            padding: 10px;
            border: none;
            background: #222;
            color: #fff;
        }
        #clientChatModalSend {
            background: #e50914;
            color: #fff;
            border: none;
            padding: 0 18px;
            cursor: pointer;
        }
        .client-chat-message { margin-bottom: 10px; }
        .client-chat-message.client { text-align: right; }
        .client-chat-message.owner { text-align: left; }
        .client-chat-message .bubble { display: inline-block; padding: 8px 12px; border-radius: 16px; max-width: 80%; }
        .client-chat-message.client .bubble { background: #e50914; color: #fff; }
        .client-chat-message.owner .bubble { background: #2196f3; color: #fff; }
    </style>
    <!--[if lt IE 9]>
    <![endif]-->

    <!-- Messaging Modal HTML -->
</head>
<body class="header-collapse">
<div id="clientChatModalOverlay" class="chat-modal-overlay">
    <div id="clientChatModal" class="chat-modal">
        <div id="clientChatModalHeader" class="chat-modal-header">
            <span id="clientChatModalStudioName">Message</span>
            <button id="clientChatModalClose" class="chat-modal-close">&times;</button>
        </div>
        <div id="clientChatModalBody" class="chat-modal-body">
            <div style="color:#aaa; text-align:center;">Loading...</div>
        </div>
        <form id="clientChatModalInputArea" class="chat-modal-input-area">
            <input type="text" id="clientChatModalInput" placeholder="Type your message..." required />
            <button type="submit" id="clientChatModalSend">Send</button>
        </form>
    </div>
</div>
    <div id="site-content">
        <?php include 'shared/components/navbar.php'; ?>
        <div class="hero">
            <div class="hero-content">
                <h1 class="hero-title">MuSeek</h1>
                <p class="hero-subtitle">Find and book the perfect recording studio for your next project</p>
                
                <div class="search-section">
                    <form class="search-form" id="studioSearchForm">
                        <div class="form-group">
                            <label for="studioSearch">Search Studios</label>
                            <input type="text" id="studioSearch" name="search" placeholder="Studio name, location, or service...">
                        </div>
                        
                        <div class="form-group">
                            <label for="startDate">Start Date</label>
                            <input type="date" id="startDate" name="start_date" min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="startTime">Start Time</label>
                            <input type="time" id="startTime" name="start_time">
                        </div>
                        
                        <div class="form-group">
                            <label for="endTime">End Time</label>
                            <input type="time" id="endTime" name="end_time">
                        </div>
                        
                        <button type="submit" class="search-btn">
                            <i class="fa fa-search"></i> Search
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <main class="main-content">
            <div class="studios-section">
                <div class="container">
                    <div class="section-header">
                        <h2 class="section-title">Available Studios</h2>
                        <div class="view-toggle">
                            <button class="toggle-btn active" data-filter="all"><i class="fa fa-layer-group"></i> All</button>
                            <button class="toggle-btn" data-filter="favorites"><i class="fa fa-heart"></i> Favorites</button>
                        </div>
                    </div>
                    
                    <div class="studios-grid" id="studiosGrid">
                        <?php if (!empty($studios)): ?>
                            <?php foreach ($studios as $studio): ?>
                                <div class="studio-card" data-studio-id="<?php echo $studio['StudioID']; ?>">
                                    <div class="studio-image">
                                        <button class="favorite-btn" title="Add to Favorites" aria-label="Add to Favorites" aria-pressed="false"><i class="fa-regular fa-heart"></i></button>
                                        <img src="<?php echo $studio['StudioImgBase64']; ?>" 
                                             alt="<?php echo htmlspecialchars($studio['StudioName']); ?>" 
                                             loading="lazy">
                                    </div>
                                    
                                    <div class="studio-info">
                                        <h3 class="studio-name"><?php echo htmlspecialchars($studio['StudioName']); ?></h3>
                                        <p class="studio-location">
                                            <i class="fa fa-map-marker-alt"></i>
                                            <?php echo htmlspecialchars($studio['Loc_Desc']); ?>
                                        </p>
                                        
                                        <div class="studio-services">
                                            <h4 class="services-title">Services</h4>
                                            <div class="services-list">
                                                <?php if (!empty($studio['services'])): ?>
                                                    <?php 
                                                    $service_count = count($studio['services']);
                                                    $display_services = array_slice($studio['services'], 0, 4);
                                                    foreach ($display_services as $index => $service): 
                                                    ?>
                                                        <span class="service-tag <?php echo $index === 0 ? 'featured' : ''; ?>">
                                                            <?php echo htmlspecialchars($service['ServiceType']); ?>
                                                            <small>₱<?php echo number_format($service['Price'], 0); ?></small>
                                                        </span>
                                                    <?php endforeach; ?>
                                                    <?php if ($service_count > 4): ?>
                                                        <span class="service-tag">+<?php echo $service_count - 4; ?> more</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="service-tag">No services listed</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="studio-actions">
                                            <button class="action-btn btn-primary" 
                                                    onclick="handleBookClick(<?php echo $studio['StudioID']; ?>, <?php echo $is_authenticated ? 'true' : 'false'; ?>)">
                                                <i class="fa fa-calendar"></i> Book Now
                                            </button>
                                            <button class="action-btn btn-secondary" 
                                                    onclick="window.location.href='client/php/profile.php?studio_id=<?php echo $studio['StudioID']; ?>'">
                                                <i class="fa fa-eye"></i> View Profile
                                            </button>
                                            <button class="action-btn btn-message" 
                                                    onclick="openStudioChat(<?php echo $studio['StudioID']; ?>)">
                                                <i class="fa fa-comment"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-studios">
                                <i class="fa fa-music"></i>
                                <h3>No Studios Available</h3>
                                <p>We're working on adding more studios to our platform. Check back soon!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
        <?php include 'shared/components/footer.php'; ?>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="<?php echo getJSPath('plugins.js'); ?>"></script>
    <script src="<?php echo getJSPath('app.js'); ?>"></script>
    <script>
        let studioCurrentSlide = 0;
        const studioSlides = document.querySelectorAll('#studio-carousel .slide');
        const totalStudioSlides = studioSlides.length;

        function moveSlide(direction) {
            if (totalStudioSlides <= 1) return;
            studioCurrentSlide += direction;
            if (studioCurrentSlide < 0) studioCurrentSlide = totalStudioSlides - 1;
            if (studioCurrentSlide >= totalStudioSlides) studioCurrentSlide = 0;
            updateStudioCarousel();
        }

        function updateStudioCarousel() {
            const studioCarousel = document.getElementById('studio-carousel');
            studioCarousel.style.transform = `translateX(-${studioCurrentSlide * 100}%)`;
        }

        if (totalStudioSlides > 1) {
            setInterval(() => moveSlide(1), 10000);
        }

        function handleBookClick(studioId, isAuthenticated) {
            if (!isAuthenticated) {
                alert("Please log in or register to book a studio.");
                window.location.href = "auth/php/login.php";
                return;
            }
            window.location.href = `booking/php/booking.php?studio_id=${studioId}`;
        }
    </script>
   <script>
const isAuthenticated = <?php echo json_encode($is_authenticated); ?>;
const userType = <?php echo json_encode($_SESSION['user_type'] ?? null); ?>;
const clientId = <?php echo json_encode($_SESSION['user_id'] ?? null); ?>;

const studios = <?php
    $studio_js_data = [];
    foreach ($studio_slides as $slide) {
        foreach ($slide as $studio) {
            $studio_js_data[] = [
                'StudioID' => $studio['StudioID'],
                'StudioName' => $studio['StudioName'],
                'OwnerID' => isset($studio['OwnerID']) ? (int)$studio['OwnerID'] : 0
            ];
        }
    }
    echo json_encode($studio_js_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>;

let selectedStudioId = '';
let selectedOwnerId = '';
let selectedStudioName = '';

const chatModalOverlay = document.getElementById('clientChatModalOverlay');
const chatModal = document.getElementById('clientChatModal');
const chatHeaderStudio = document.getElementById('clientChatModalStudioName');
const closeBtn = document.getElementById('clientChatModalClose');
const chatBody = document.getElementById('clientChatModalBody');
const chatForm = document.getElementById('clientChatModalInputArea');
const chatInput = document.getElementById('clientChatModalInput');

let chatInterval = null;

function startChatPolling() {
    if (chatInterval) clearInterval(chatInterval);
    chatInterval = setInterval(fetchChat, 2000); // every 2 seconds
}
function stopChatPolling() {
    if (chatInterval) clearInterval(chatInterval);
}

window.openStudioChat = function(studioId) {
    if (!isAuthenticated || userType !== 'client') {
        alert("Please log in as a client to message studios.");
        window.location.href = "auth/php/login.php";
        return;
    }

    const studio = studios.find(s => s.StudioID == studioId);
    if (!studio || !studio.OwnerID) {
        alert("Studio or owner not found.");
        return;
    }

    // Redirect to unified chat page with owner as partner and studio context
    window.location.href = `messaging/php/chat.php?partner_id=${encodeURIComponent(studio.OwnerID)}&studio_id=${encodeURIComponent(studio.StudioID)}`;
};

// Redirecting to chat.php; removed legacy modal activation code

function fetchChat() {
    if (!selectedStudioId || !selectedOwnerId) return;
    fetch(`messaging/php/fetch_chat.php?owner_id=${selectedOwnerId}&client_id=${clientId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderMessages(data.messages);
            } else {
                chatBody.innerHTML = `<div style='color:#f99;text-align:center;'>${data.error}</div>`;
            }
        });
}

function renderMessages(messages) {
    chatBody.innerHTML = '';
    if (!messages.length) {
        chatBody.innerHTML = "<div style='color:#aaa;text-align:center;'>No messages yet.</div>";
        return;
    }
    messages.forEach(msg => {
        const who = (msg.Sender_Type && msg.Sender_Type.toLowerCase() === 'client') ? 'client' : 'owner';
        const div = document.createElement('div');
        div.className = 'client-chat-message ' + who;
        div.innerHTML = `<span class='bubble'>${msg.Content.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span><div style='font-size:10px;color:#888;'>${msg.Timestamp}</div>`;
        chatBody.appendChild(div);
    });
    scrollChatToBottom();
}

function scrollChatToBottom() {
    chatBody.scrollTop = chatBody.scrollHeight;
}

chatForm.onsubmit = function(e) {
    e.preventDefault();
    const content = chatInput.value.trim();
    console.log("Submit clicked. Content:", content);
    console.log("Selected Studio ID:", selectedStudioId);
    console.log("Selected Owner ID:", selectedOwnerId);
    console.log("Client ID:", clientId);

    if (!content || !selectedStudioId || !selectedOwnerId) {
        console.warn("Missing data - not sending.");
        return;
    }

    chatInput.value = '';

    fetch('messaging/php/send_message.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: `content=${encodeURIComponent(messageText)}&owner_id=${ownerId}&client_id=${clientId}&studio_id=${selectedStudioId}&sender_type=Client`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            messageInput.value = '';
            console.log('Message sent');
        } else {
            console.error('Failed to send message', data);
        }
    })
    .catch(err => console.error('Error sending message', err));
};

if (closeBtn) {
    closeBtn.onclick = function() {
        chatModalOverlay.classList.remove('active');
        stopChatPolling();
    };
}

chatModalOverlay.addEventListener('click', function (e) {
    if (e.target === chatModalOverlay) {
        chatModalOverlay.classList.remove('active');
        stopChatPolling();
    }
});

setInterval(() => {
    if (chatModalOverlay.classList.contains('active') && selectedStudioId && selectedOwnerId) {
        fetchChat();
    }
}, 10000);
</script>
<script>
(function(){
    const searchInput = document.getElementById('studioSearch');
    const searchForm = document.getElementById('studioSearchForm');
    const cards = Array.from(document.querySelectorAll('.studio-card'));

    // Favorites persistence (per user if logged in)
    const userId = <?php echo json_encode($_SESSION['user_id'] ?? 'guest'); ?>;
    const favoritesKey = `museek:favorites:${userId}`;
    let favorites = new Set();
    try {
        favorites = new Set(JSON.parse(localStorage.getItem(favoritesKey) || '[]').map(String));
    } catch (e) { favorites = new Set(); }

    // Initialize favorite buttons
    cards.forEach(card => {
        const id = String(card.dataset.studioId);
        const favBtn = card.querySelector('.favorite-btn');
        if (!favBtn) return;
        const icon = favBtn.querySelector('i');
        const setFavState = (isFav) => {
            favBtn.classList.toggle('active', isFav);
            favBtn.setAttribute('aria-pressed', isFav ? 'true' : 'false');
            if (isFav) {
                icon.classList.remove('fa-regular');
                icon.classList.add('fa-solid');
            } else {
                icon.classList.remove('fa-solid');
                icon.classList.add('fa-regular');
            }
            favBtn.title = isFav ? 'Remove from Favorites' : 'Add to Favorites';
        };
        setFavState(favorites.has(id));
        favBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (favorites.has(id)) {
                favorites.delete(id);
                setFavState(false);
            } else {
                favorites.add(id);
                setFavState(true);
            }
            localStorage.setItem(favoritesKey, JSON.stringify(Array.from(favorites)));
            applyFilters();
        });
    });

    // View toggle (All vs Favorites)
    let currentFilter = 'all';
    const viewToggleContainer = document.querySelector('.view-toggle');
    if (viewToggleContainer) {
        viewToggleContainer.addEventListener('click', (e) => {
            const btn = e.target.closest('.toggle-btn');
            if (!btn) return;
            viewToggleContainer.querySelectorAll('.toggle-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentFilter = btn.dataset.filter || 'all';
            applyFilters();
        });
    }

    // Build cache of text for search filtering
    const getCardText = (card) => {
        const name = card.querySelector('.studio-name')?.textContent || '';
        const loc = card.querySelector('.studio-location')?.textContent || '';
        const services = Array.from(card.querySelectorAll('.service-tag')).map(s => s.textContent).join(' ');
        return `${name} ${loc} ${services}`.toLowerCase();
    };
    const cardTextCache = new Map();
    cards.forEach(card => { cardTextCache.set(card, getCardText(card)); });

    function applyFilters() {
        const query = (searchInput?.value || '').trim().toLowerCase();
        cards.forEach(card => {
            const id = String(card.dataset.studioId);
            const matchesSearch = !query || (cardTextCache.get(card) || '').includes(query);
            const matchesFav = currentFilter === 'all' || favorites.has(id);
            card.style.display = (matchesSearch && matchesFav) ? '' : 'none';
        });
    }

    if (searchForm) {
        searchForm.addEventListener('submit', (e) => { e.preventDefault(); applyFilters(); });
    }
    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }

    // Initial filter
    applyFilters();
})();
</script>

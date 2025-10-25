<?php
session_start(); // Start the session
include '../../shared/config/db.php';
require_once '../../shared/config/path_config.php';

// Check if we're coming from booking2.php or adding another slot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_another_slot'])) {
    // Redirect back to booking2.php with current parameters
    $params = [
        'studio_id' => $_POST['studio_id'],
        'service_id' => $_POST['service_id'],
        'from_confirm' => '1'
    ];
    header('Location: booking2.php?' . http_build_query($params));
    exit;
}

// Retrieve POST data from Step 2
$studio_id = isset($_POST['studio_id']) ? (int)$_POST['studio_id'] : (isset($_GET['studio_id']) ? (int)$_GET['studio_id'] : 0);
$service_id = isset($_POST['service_id']) ? (int)$_POST['service_id'] : (isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0);
$service_price = isset($_POST['service_price']) ? (float)$_POST['service_price'] : 0;
$service_name = isset($_POST['service_name']) ? htmlspecialchars($_POST['service_name']) : '';
$date = isset($_POST['date']) ? $_POST['date'] : '';
$time_start = isset($_POST['timeStart']) ? $_POST['timeStart'] : '';
$time_end = isset($_POST['timeEnd']) ? $_POST['timeEnd'] : '';
$instructor_id = isset($_POST['instructor_id']) ? (int)$_POST['instructor_id'] : 0;
// Instrument selections from Step 2 (per-slot)
$instrumentSelections = [];
if (isset($_POST['instrumentSelections'])) {
    $decoded = json_decode($_POST['instrumentSelections'], true);
    if (is_array($decoded)) { $instrumentSelections = $decoded; }
}

// Initialize session variables if they don't exist
if (!isset($_SESSION['selected_slots'])) {
    $_SESSION['selected_slots'] = [];
}
if (!isset($_SESSION['current_booking'])) {
    $_SESSION['current_booking'] = [];
}

// Add current slot to selected slots if we have a complete time slot
if (!empty($date) && !empty($time_start) && !empty($time_end)) {
    $new_slot = [
        'date' => $date,
        'start' => $time_start,
        'end' => $time_end,
        'studio_id' => $studio_id,
        'service_id' => $service_id,
        'price_per_hour' => $service_price,
        'instructor_id' => $instructor_id,
        'instruments' => $instrumentSelections
    ];

    // Check if this exact slot already exists
    $slot_exists = false;
    foreach ($_SESSION['selected_slots'] as $slot) {
        if ($slot['date'] === $new_slot['date'] && 
            $slot['start'] === $new_slot['start'] && 
            $slot['end'] === $new_slot['end']) {
            $slot_exists = true;
            break;
        }
    }

    if (!$slot_exists) {
        $_SESSION['selected_slots'][] = $new_slot;
    }
}

// Update current booking details
if ($studio_id > 0 && $service_id > 0) {
    $_SESSION['current_booking'] = [
        'studio_id' => $studio_id,
        'service_id' => $service_id,
        'service_name' => $service_name,
        'service_price' => $service_price,
        'instructor_id' => $instructor_id
    ];
}

// If no slots selected and not coming from booking2.php, redirect back
if (empty($_SESSION['selected_slots']) && empty($_POST['from_confirm'])) {
    header("Location: booking2.php?studio_id=$studio_id&service_id=$service_id");
    exit;
}

// Calculate total price for all selected slots (filtered to current studio/service)
$total_price = 0;
$total_hours = 0;

foreach ($_SESSION['selected_slots'] as $slot) {
    if ((int)($slot['studio_id'] ?? 0) !== (int)$studio_id || (int)($slot['service_id'] ?? 0) !== (int)$service_id) {
        continue;
    }
    $start = new DateTime($slot['date'] . ' ' . $slot['start']);
    $end = new DateTime($slot['date'] . ' ' . $slot['end']);
    $interval = $start->diff($end);
    $hours = $interval->h + ($interval->days * 24);
    $total_hours += $hours;
    // Base service price
    $total_price += $hours * $slot['price_per_hour'];
    // Add instruments price lines
    if (!empty($slot['instruments']) && is_array($slot['instruments'])) {
        foreach ($slot['instruments'] as $inst) {
            $rate = isset($inst['hourly_rate']) ? (float)$inst['hourly_rate'] : 0;
            $qty = isset($inst['quantity']) ? (int)$inst['quantity'] : 0;
            $total_price += $hours * $rate * $qty;
        }
    }
}

$initial_payment = $total_price * 0.25; // 25% initial payment

// Fetch studio details
$studio_query = "SELECT StudioID, StudioName, Loc_Desc, StudioImg FROM studios WHERE StudioID = ?";
$stmt = mysqli_prepare($conn, $studio_query);
mysqli_stmt_bind_param($stmt, "i", $studio_id);
mysqli_stmt_execute($stmt);
$studio_result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($studio_result)) {
    if ($row['StudioImg']) {
        $row['StudioImgBase64'] = 'data:image/jpeg;base64,' . base64_encode($row['StudioImg']);
    } else {
        $row['StudioImgBase64'] = '../../shared/assets/images/default_studio.jpg';
    }
    $studio = $row;
} else {
    error_log("No studio found for StudioID: $studio_id");
    header("Location: Home.php?error=" . urlencode("Invalid studio ID. Please select a valid studio."));
    exit;
}
mysqli_stmt_close($stmt);

// Fetch instructor details if selected
$instructor = null;
if ($instructor_id > 0) {
    $instructor_query = "SELECT InstructorID, Name FROM instructors WHERE InstructorID = ?";
    $stmt = mysqli_prepare($conn, $instructor_query);
    mysqli_stmt_bind_param($stmt, "i", $instructor_id);
    mysqli_stmt_execute($stmt);
    $instructor_result = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($instructor_result)) {
        $instructor = $row;
    }
    mysqli_stmt_close($stmt);
}

$services_data = [
    $service_id => [
        'name' => $service_name,
        'price' => $service_price,
        'instructor_id' => $instructor_id
    ]
];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>Confirm Booking - MuSeek</title>
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="<?php echo getCSSPath('style.css'); ?>">
    <style>
        /* Loading spinner animation */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .fa-spin {
            animation: spin 1s linear infinite;
        }
        
        /* Button states */
        .button.danger:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        .button .button-loader {
            display: none;
        }
        
        .button.loading .button-text {
            display: none;
        }
        
        .button.loading .button-loader {
            display: inline-block;
        }
        
        /* Message styles */
        .message {
            margin-top: 10px;
            padding: 10px 15px;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .button-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        
        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
            border-color: #bd2130;
        }
        
        #branding img {
            width: 180px;
            display: block;
        }

        .section-title {
            margin-left: 20px;
        }

        .booking-progress {
            display: flex;
            justify-content: space-between;
            max-width: 800px;
            margin: 0 auto 40px;
            position: relative;
            z-index: 5;
        }

        .booking-progress::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: rgba(255, 255, 255, 0.3);
            z-index: -1;
        }

        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: rgba(255, 255, 255, 0.6);
            width: 25%;
        }

        .progress-step.active {
            color: #fff;
        }

        .progress-step.completed {
            color: #e50914;
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.5);
            border: 2px solid rgba(255, 255, 255, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .progress-step.active .step-number {
            background: #e50914;
            border-color: #fff;
        }

        .progress-step.completed .step-number {
            background: #333;
            border-color: #e50914;
        }

        .step-label {
            font-size: 14px;
            text-align: center;
        }

        .studio-header {
            display: flex;
            align-items: center;
            margin-bottom: 16px;
        }

        .studio-header img {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 10px;
            margin-right: 20px;
            border: 2px solid #eee;
            background: #fff;
        }

        .studio-header h3 {
            color: white;
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .studio-location {
            color: #ccc;
            margin-top: 5px;
            font-size: 14px;
        }

        .studio-location i {
            margin-right: 5px;
        }

        .fullwidth-block.booking-section {
            background: linear-gradient(135deg, #222 60%, #e50914 200%);
            padding: 40px 0 60px 0;
        }

        .booking-container {
            display: flex;
            justify-content: center;
            margin-top: 40px;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        .booking-card {
            width: 60%;
            min-width: 320px;
            background: rgba(0, 0, 0, 0.8);
            border-radius: 12px;
            padding: 26px 28px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.10);
            margin-bottom: 24px;
        }

        .booking-step-title {
            color: #fff;
            margin: 20px 0;
            font-size: 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding-bottom: 10px;
        }

        .booking-details {
            margin-bottom: 20px;
        }

        .booking-details p {
            color: #ccc;
            font-size: 16px;
            margin: 10px 0;
        }

        .booking-details strong {
            color: #fff;
            margin-right: 5px;
        }

        .error-message {
            color: #ff5555;
            background: rgba(255, 0, 0, 0.1);
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
            text-align: center;
        }

        .form-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
        }

        .button {
            padding: 10px 20px;
            font-size: 14px;
            background-color: #e50914;
            border: none;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
            text-align: center;
            display: inline-block;
            text-decoration: none;
        }

        .button:hover {
            background-color: #f40612;
        }

        .button.secondary {
            background-color: #4a6baf;
            border-color: #3a5a9f;
            transition: all 0.2s ease;
        }
        
        .button.secondary:hover {
            background-color: #3a5a9f;
            border-color: #2a4a8f;
            transform: translateY(-1px);
        }

        #confirmBtn:disabled {
            background-color: #888;
            cursor: not-allowed;
        }

        /* New styles for time slots */
        .selected-slots {
            margin: 15px 0;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .slot-item {
            display: flex;
            justify-content: space-between;
            padding: 15px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            margin-bottom: 12px;
            border-radius: 6px;
            background-color: rgba(255, 255, 255, 0.03);
            transition: all 0.2s ease;
        }
        
        .slot-item:hover {
            background-color: rgba(255, 255, 255, 0.07);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .slot-details {
            flex-grow: 1;
        }
        
        .slot-actions {
            display: flex;
            align-items: center;
        }
        
        .slot-service {
            font-weight: 600;
            color: #fff;
            margin-bottom: 8px;
            font-size: 1.05em;
            padding-bottom: 6px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .remove-slot {
            background: rgba(220, 53, 69, 0.8);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s ease;
            margin-left: 10px;
            transition: background 0.2s;
        }
        
        .remove-slot:hover {
            background: #c82333;
            transform: translateY(-1px);
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: #1a1a1a;
            padding: 25px;
            border-radius: 8px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
            border: 1px solid #333;
        }

        .modal-title {
            color: #fff;
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 20px;
        }

        .modal-message {
            color: #ddd;
            margin-bottom: 25px;
            line-height: 1.5;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .modal-btn {
            padding: 8px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }

        .modal-btn-cancel {
            background-color: #444;
            color: #fff;
        }

        .modal-btn-cancel:hover {
            background-color: #555;
        }

        .modal-btn-confirm {
            background-color: #e50914;
            color: white;
        }

        .modal-btn-confirm:hover {
            background-color: #f40612;
        }

        @media (max-width: 768px) {
            .booking-container {
                flex-direction: column;
            }

            .booking-card {
                width: 100%;
                margin-bottom: 20px;
            }

            .booking-progress {
                padding: 0 15px;
            }

            .step-label {
                font-size: 12px;
            }

            .studio-header img {
                width: 80px;
                height: 80px;
            }
        }
        @keyframes fadeOut {
    0% { opacity: 1; }
    70% { opacity: 1; }
    100% { opacity: 0; }
}
    </style>
</head>
<body>
<?php include '../../shared/components/navbar.php'; ?>

    <!-- Confirmation Modal -->
    <div id="confirmationModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">Confirm Removal</h3>
            <p class="modal-message">Are you sure you want to remove this time slot?</p>
            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-cancel" id="cancelRemove">Cancel</button>
                <button type="button" class="modal-btn modal-btn-confirm" id="confirmRemove">Remove</button>
            </div>
        </div>
    </div>

    <main class="main-content">
        <div class="fullwidth-block booking-section">
            <div class="booking-progress">
                <div class="progress-step completed">
                    <div class="step-number">1</div>
                    <div class="step-label">Select Service</div>
                </div>
                <div class="progress-step completed">
                    <div class="step-number">2</div>
                    <div class="step-label">Choose Date & Time</div>
                </div>
                <div class="progress-step active">
                    <div class="step-number">3</div>
                    <div class="step-label">Confirm Booking</div>
                </div>
                <div class="progress-step">
                    <div class="step-number">4</div>
                    <div class="step-label">Payment</div>
                </div>
            </div>

            <h2 class="section-title">Confirm Your Booking</h2>
            <div class="booking-container">
                <div class="booking-card">
                    <div class="studio-header">
                        <?php if (!empty($studio['StudioImgBase64'])): ?>
                            <img src="<?php echo $studio['StudioImgBase64']; ?>" alt="<?php echo htmlspecialchars($studio['StudioName']); ?>">
                        <?php else: ?>
                            <img src="../../shared/assets/images/default_studio.jpg" alt="Studio Image">
                        <?php endif; ?>
                        <div>
                            <h3><?php echo htmlspecialchars($studio['StudioName']); ?></h3>
                            <p class="studio-location"><i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($studio['Loc_Desc']); ?></p>
                        </div>
                    </div>

                    <h4 class="booking-step-title">Step 3: Confirm Booking Details</h4>

                    <?php if (isset($_SESSION['booking_error'])): ?>
                        <div class="error-message"><?php echo htmlspecialchars($_SESSION['booking_error']); ?></div>
                        <?php unset($_SESSION['booking_error']); ?>
                    <?php endif; ?>

                    <form id="confirmForm" action="booking4.php" method="post">
                        <input type="hidden" name="studio_id" value="<?php echo $studio_id; ?>">
                        <input type="hidden" name="service_id" value="<?php echo $service_id; ?>">
                        <input type="hidden" name="service_name" value="<?php echo htmlspecialchars($service_name); ?>">
                        <input type="hidden" name="instructor_id" value="<?php echo $instructor_id; ?>">
                        <input type="hidden" name="total_price" value="<?php echo $total_price; ?>">
                        <input type="hidden" name="initial_payment" value="<?php echo $initial_payment; ?>">
                        <input type="hidden" name="from_confirm" value="1">
                        <!-- Store services data as JSON (filtered to current studio/service) -->
                        <input type="hidden" name="services_data" value='<?php 
                            $services_data = [];
                            foreach ($_SESSION['selected_slots'] as $slot) {
                                if ((int)($slot['studio_id'] ?? 0) !== (int)$studio_id || (int)($slot['service_id'] ?? 0) !== (int)$service_id) {
                                    continue;
                                }
                                if (!isset($services_data[$slot['service_id']])) {
                                    $service_query = "SELECT ServiceType, Price FROM services WHERE ServiceID = ?";
                                    $stmt = mysqli_prepare($conn, $service_query);
                                    mysqli_stmt_bind_param($stmt, "i", $slot['service_id']);
                                    mysqli_stmt_execute($stmt);
                                    $service_result = mysqli_stmt_get_result($stmt);
                                    if ($service_row = mysqli_fetch_assoc($service_result)) {
                                        $services_data[$slot['service_id']] = [
                                            'name' => $service_row['ServiceType'],
                                            'price' => $service_row['Price'],
                                            'instructor_id' => $slot['instructor_id'] ?? 0
                                        ];
                                    }
                                    mysqli_stmt_close($stmt);
                                }
                            }
                            $json_services = json_encode($services_data);
                            error_log("Booking3 - Sending services data: " . $json_services);
                            echo htmlspecialchars($json_services, ENT_QUOTES, 'UTF-8');
                        ?>'>
                        <!-- Store slots data as JSON (filtered to current studio/service) -->
                        <input type="hidden" name="selected_slots" value='<?php 
                            $slots_data = [];
                            foreach ($_SESSION['selected_slots'] as $slot) {
                                if ((int)($slot['studio_id'] ?? 0) !== (int)$studio_id || (int)($slot['service_id'] ?? 0) !== (int)$service_id) {
                                    continue;
                                }
                                $slotEntry = [
                                    'date' => $slot['date'],
                                    'start' => $slot['start'],
                                    'end' => $slot['end'],
                                    'service_id' => $slot['service_id'],
                                    'instructor_id' => $slot['instructor_id'] ?? 0,
                                    'studio_id' => $slot['studio_id']
                                ];
                                if (!empty($slot['instruments'])) {
                                    $slotEntry['instruments'] = [];
                                    foreach ($slot['instruments'] as $inst) {
                                        $slotEntry['instruments'][] = [
                                            'instrument_id' => (int)($inst['instrument_id'] ?? 0),
                                            'quantity' => (int)($inst['quantity'] ?? 0),
                                            'hourly_rate' => (float)($inst['hourly_rate'] ?? 0),
                                            'name' => htmlspecialchars($inst['name'] ?? '')
                                        ];
                                    }
                                }
                                $slots_data[] = $slotEntry;
                            }
                            $json_slots = json_encode($slots_data);
                            error_log("Booking3 - Sending slots data: " . $json_slots);
                            echo htmlspecialchars($json_slots, ENT_QUOTES, 'UTF-8');
                        ?>'>
                        <!-- Keep these for backward compatibility -->
                        <input type="hidden" name="service_id" value="<?php echo $service_id; ?>">
                        <input type="hidden" name="service_name" value="<?php echo htmlspecialchars($service_name); ?>">
                        <input type="hidden" name="instructor_id" value="<?php echo $instructor_id; ?>">
                        <input type="hidden" name="total_price" value="<?php echo $total_price; ?>">
                        <input type="hidden" name="initial_payment" value="<?php echo $initial_payment; ?>">

                        <div class="booking-details">
                            <h3>Selected Time Slots</h3>
                            <div class="selected-slots" id="selectedSlotsList">
                                <?php 
                                foreach ($_SESSION['selected_slots'] as $index => $slot): if ((int)($slot['studio_id'] ?? 0) !== (int)$studio_id || (int)($slot['service_id'] ?? 0) !== (int)$service_id) continue; 
                                    $start = new DateTime($slot['date'] . ' ' . $slot['start']);
                                    $end = new DateTime($slot['date'] . ' ' . $slot['end']);
                                    $interval = $start->diff($end);
                                    $hours = $interval->h + ($interval->days * 24);
                                    
                                    // Get service name
                                    $service_name = 'Unknown Service';
                                    $service_query = "SELECT ServiceType FROM services WHERE ServiceID = ?";
                                    $stmt = mysqli_prepare($conn, $service_query);
                                    mysqli_stmt_bind_param($stmt, "i", $slot['service_id']);
                                    mysqli_stmt_execute($stmt);
                                    $service_result = mysqli_stmt_get_result($stmt);
                                    if ($service_row = mysqli_fetch_assoc($service_result)) {
                                        $service_name = htmlspecialchars($service_row['ServiceType']);
                                    }
                                    mysqli_stmt_close($stmt);
                                    
                                    // Get instructor name if instructor_id exists
                                    $instructor_name = 'No Instructor';
                                    if (!empty($slot['instructor_id']) && $slot['instructor_id'] != 0) {
                                        $instructor_query = "SELECT Name FROM instructors WHERE InstructorID = ?";
                                        $stmt = mysqli_prepare($conn, $instructor_query);
                                        mysqli_stmt_bind_param($stmt, "i", $slot['instructor_id']);
                                        mysqli_stmt_execute($stmt);
                                        $instructor_result = mysqli_stmt_get_result($stmt);
                                        if ($instructor_row = mysqli_fetch_assoc($instructor_result)) {
                                            $instructor_name = htmlspecialchars($instructor_row['Name']);
                                        } else {
                                            $instructor_name = 'Instructor Not Found';
                                        }
                                        mysqli_stmt_close($stmt);
                                    }
                                ?>
                                    <div class="slot-item" data-index="<?php echo $index; ?>">
                                        <div class="slot-details">
                                            <div class="slot-service">
                                                <strong>Service:</strong> <?php echo $service_name; ?>
                                            </div>
                                            <?php if (!empty($instructor_name)): ?>
                                            <div><strong>Instructor:</strong> <?php echo $instructor_name; ?></div>
                                            <?php endif; ?>
                                            <div><strong>Date:</strong> <?php echo $start->format('F j, Y'); ?></div>
                                            <div><strong>Time:</strong> <?php echo $start->format('g:i A') . ' - ' . $end->format('g:i A'); ?></div>
                                            <div>
                                                <strong>Duration:</strong> <?php echo $hours; ?> hour<?php echo $hours != 1 ? 's' : ''; ?> 
                                                (₱<?php echo number_format($hours * $slot['price_per_hour'], 2); ?>)
                                            </div>
                                            <?php if (!empty($slot['instruments'])): ?>
                                            <div>
                                                <strong>Instruments:</strong>
                                                <ul style="margin:4px 0 0 0; padding-left:16px;">
                                                    <?php foreach ($slot['instruments'] as $inst): ?>
                                                    <li><?php echo htmlspecialchars($inst['name']); ?> × <?php echo (int)$inst['quantity']; ?> (₱<?php echo number_format((float)$inst['hourly_rate'], 2); ?>/hr)</li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="slot-actions">
                                            <button type="button" class="remove-slot" data-index="<?php echo $index; ?>">
                                                <i class="fas fa-times"></i> Remove
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-actions">
                                <div class="button-group">
                                    <a href="booking.php?studio_id=<?php echo $studio_id; ?>" class="button secondary">
                                        <i class="fas fa-plus"></i> Add Booking
                                    </a>
                                    <button type="button" id="clearAllBtn" class="button danger" 
                                        data-studio-id="<?php echo $studio_id; ?>"
                                        data-service-id="<?php echo $service_id; ?>">
                                        <span class="button-text">
                                            <i class="fas fa-trash-alt"></i> Clear All
                                        </span>
                                        <span class="button-loader" style="display: none;">
                                            <i class="fas fa-spinner fa-spin"></i> Clearing...
                                        </span>
                                    </button>
                                </div>
                                <div id="clearMessage" class="message" style="display: none; margin-top: 10px; padding: 10px; border-radius: 4px;"></div>
                            </div>
                        </div>

                        <div class="booking-details">
                            <h3>Pricing Summary</h3>
                            <p>Total Hours: <?php echo $total_hours; ?> hours × ₱<?php echo number_format($service_price, 2); ?> per hour</p>
                            <p><strong>Total Amount:</strong> ₱<?php echo number_format($total_price, 2); ?></p>
                            <p><strong>Initial Payment (25%):</strong> ₱<?php echo number_format($initial_payment, 2); ?></p>
                        </div>

                        <div class="form-actions">
                            <a href="booking2.php?studio_id=<?php echo $studio_id; ?>&service_id=<?php echo $service_id; ?>" class="button secondary">Back</a>
                            <button type="submit" class="button" id="confirmBtn" <?php echo empty($_SESSION['selected_slots']) ? 'disabled' : ''; ?>>
                                <span class="button-text"><i class="fas fa-check"></i> Confirm Booking</span>
                                <span class="button-loader"><i class="fas fa-spinner fa-spin"></i> Processing...</span>
                            </button>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </main>

    <?php include '../../shared/components/footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // DOM Elements
        const confirmForm = document.getElementById('confirmForm');
        const clearAllBtn = document.getElementById('clearAllBtn');
        const selectedSlotsList = document.getElementById('selectedSlotsList');
        const clearMessage = document.getElementById('clearMessage');
        
        // Handle Clear All button click
        if (clearAllBtn) {
            clearAllBtn.addEventListener('click', function() {
                if (!confirm('Are you sure you want to clear all bookings? This cannot be undone.')) {
                    return;
                }
                
                // Show loading state
                clearAllBtn.classList.add('loading');
                clearAllBtn.disabled = true;
                clearMessage.style.display = 'none';
                
                // Get data attributes
                const studioId = clearAllBtn.dataset.studioId || 0;
                const serviceId = clearAllBtn.dataset.serviceId || 0;
                
                // Send AJAX request
                fetch('clear_slots.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `studio_id=${encodeURIComponent(studioId)}&service_id=${encodeURIComponent(serviceId)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Show success message and redirect
                        alert('All bookings have been cleared. You will be redirected to select a new booking.');
                        window.location.href = 'booking.php?studio_id=' + studioId + (serviceId ? '&service_id=' + serviceId : '');
                    } else {
                        clearMessage.textContent = data.message || 'Failed to clear time slots';
                        clearMessage.className = 'message error';
                        clearMessage.style.display = 'block';
                        clearAllBtn.classList.remove('loading');
                        clearAllBtn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    clearMessage.textContent = 'An error occurred while clearing time slots';
                    clearMessage.className = 'message error';
                    clearMessage.style.display = 'block';
                    clearAllBtn.classList.remove('loading');
                    clearAllBtn.disabled = false;
                });
            });
        }
        
        // Helper function to show messages
        function showMessage(message, type = 'success') {
            if (!clearMessage) return;
            
            clearMessage.textContent = message;
            clearMessage.className = `message ${type}`;
            clearMessage.style.display = 'block';
            
            // Auto-hide after 5 seconds
            if (type === 'success') {
                setTimeout(() => {
                    clearMessage.style.display = 'none';
                }, 5000);
            }
        }
        const confirmBtn = document.getElementById('confirmBtn');
        
        // Handle remove slot button clicks
        selectedSlotsList.addEventListener('click', function(e) {
            const removeBtn = e.target.closest('.remove-slot');
            if (!removeBtn) return;
            
            e.preventDefault();
            const index = removeBtn.dataset.index;
            const slotItem = removeBtn.closest('.slot-item');
            
            if (confirm('Are you sure you want to remove this time slot?')) {
                // Visual feedback
                slotItem.style.opacity = '0.6';
                slotItem.style.pointerEvents = 'none';
                
                // Remove from session via AJAX
                fetch('remove_time_slot.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `index=${encodeURIComponent(index)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Fade out animation
                        slotItem.style.transition = 'opacity 0.3s';
                        slotItem.style.opacity = '0';
                        
                        // After animation, remove from DOM
                        setTimeout(() => {
                            slotItem.remove();
                            
                            // If no slots left, show alert and redirect to booking.php
                            if (document.querySelectorAll('.slot-item').length === 0) {
                                alert('All bookings have been removed. You will be redirected to select a new booking.');
                                window.location.href = 'booking.php?studio_id=<?php echo $studio_id; ?>';
                            } else if (typeof updateBookingSummary === 'function') {
                                updateBookingSummary();
                            }
                        }, 300);
                    } else {
                        // Reset UI on error
                        slotItem.style.opacity = '1';
                        slotItem.style.pointerEvents = 'auto';
                        alert('Failed to remove time slot: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    slotItem.style.opacity = '1';
                    slotItem.style.pointerEvents = 'auto';
                    alert('An error occurred while removing the time slot');
                });
            }
        });

        // Handle form submission
        confirmForm.addEventListener('submit', function(e) {
            if (!confirmBtn.disabled) {
                // Show loading state on confirm button
                confirmBtn.classList.add('loading');
                
                // Add hidden fields for each selected slot
                const slots = <?php echo json_encode($_SESSION['selected_slots']); ?>;
                slots.forEach((slot, index) => {
                    const slotInput = document.createElement('input');
                    slotInput.type = 'hidden';
                    slotInput.name = `slots[${index}][date]`;
                    slotInput.value = slot.date;
                    confirmForm.appendChild(slotInput);

                    const startInput = document.createElement('input');
                    startInput.type = 'hidden';
                    startInput.name = `slots[${index}][start]`;
                    startInput.value = slot.start;
                    confirmForm.appendChild(startInput);

                    const endInput = document.createElement('input');
                    endInput.type = 'hidden';
                    endInput.name = `slots[${index}][end]`;
                    endInput.value = slot.end;
                    confirmForm.appendChild(endInput);
                });
                
                // Disable button to prevent double-submit
                confirmBtn.disabled = true;
                
                return true;
            }
            e.preventDefault();
            return false;
        });
    });
    </script>
</body>
</html>

<?php
session_start(); // Start the session
include '../../shared/config/db.php';
require_once '../../shared/config/path_config.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo "<script>
        alert('Please log in to continue.');
        window.location.href = '../../auth/php/login.html';
    </script>";
    exit;
}

// Get parameters from previous step
$studio_id = isset($_GET['studio_id']) ? (int)$_GET['studio_id'] : 0;
$service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;
$instructor_id = isset($_GET['instructor_id']) ? (int)$_GET['instructor_id'] : 0;
$from_confirm = isset($_GET['from_confirm']) ? (bool)$_GET['from_confirm'] : false;

// When not resuming from confirmation, ensure session slots only reflect current studio/service
if (!$from_confirm) {
    if (!isset($_SESSION['current_booking']) || (int)($_SESSION['current_booking']['studio_id'] ?? 0) !== $studio_id || (int)($_SESSION['current_booking']['service_id'] ?? 0) !== $service_id) {
        $_SESSION['selected_slots'] = [];
    }
}

// If coming from confirmation page, restore the booking from session
if ($from_confirm && isset($_SESSION['current_booking'])) {
    $studio_id = $_SESSION['current_booking']['studio_id'];
    $service_id = $_SESSION['current_booking']['service_id'];
    $instructor_id = $_SESSION['current_booking']['instructor_id'];
}

// Validate parameters
if ($studio_id <= 0 || $service_id <= 0) {
    header("Location: ../../client/php/browse.php");
    exit;
}

// Fetch studio details
$studio_query = "SELECT StudioID, StudioName, Loc_Desc, StudioImg, Time_IN, Time_OUT FROM studios WHERE StudioID = ?";
$stmt = mysqli_prepare($conn, $studio_query);
mysqli_stmt_bind_param($stmt, "i", $studio_id);
mysqli_stmt_execute($stmt);
$studio_result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($studio_result)) {
    if ($row['StudioImg']) {
        $row['StudioImgBase64'] = 'data:image/jpeg;base64,' . base64_encode($row['StudioImg']);
    } else {
        $row['StudioImgBase64'] = 'images/default_studio.jpg';
    }
    $studio = $row;
} else {
    header("Location: browse.php");
    exit;
}
mysqli_stmt_close($stmt);

// Fetch service details
$service_query = "SELECT ServiceID, ServiceType, `Description`, Price FROM services WHERE ServiceID = ?";
$stmt = mysqli_prepare($conn, $service_query);
mysqli_stmt_bind_param($stmt, "i", $service_id);
mysqli_stmt_execute($stmt);
$service_result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($service_result)) {
    $service = $row;
} else {
    header("Location: booking.php?studio_id=" . $studio_id);
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

// Fetch existing bookings for this studio
$bookings_query = "SELECT 
    s.ScheduleID,
    DATE_FORMAT(s.Sched_Date, '%Y-%m-%d') AS Sched_Date, 
    DATE_FORMAT(s.Time_Start, '%H:%i:00') AS Time_Start, 
    DATE_FORMAT(s.Time_End, '%H:%i:00') AS Time_End
FROM 
    schedules s
WHERE s.StudioID = ?";
$stmt = mysqli_prepare($conn, $bookings_query);
mysqli_stmt_bind_param($stmt, "i", $studio_id);
mysqli_stmt_execute($stmt);
$bookings_result = mysqli_stmt_get_result($stmt);

$bookings = [];
while ($row = mysqli_fetch_assoc($bookings_result)) {
    $bookings[] = $row;
}
mysqli_stmt_close($stmt);

// Send bookings data to the client-side
$bookings_json = json_encode($bookings);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>Browse Studios - MuSeek</title>
    <!-- Loading third party fonts -->
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" type="text/css">
    <!-- Loading main css file -->
    <link rel="stylesheet" href="<?php echo getCSSPath('style.css'); ?>">
    <style>
        #branding img {
            width: 180px;
            display: block; 
        }
    
        .section-title {
            margin-left: 20px;
        }
        /* Progress Bar Styles */
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

        /* Studio Header Styles */
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

        /* Booking Section Styles */
        .fullwidth-block.booking-section {
            background: linear-gradient(135deg, #222 60%, #e50914 200%);
            padding: 40px 0 60px 0;
        }

        .booking-container {
            display: flex;
            gap: 48px;
            justify-content: center;
            align-items: flex-start;
            margin-top: 40px;
            flex-wrap: nowrap;
            max-width: 1400px;
            margin: 40px auto 0;
        }

        .booking-card {
            width: 56%;
            min-width: 320px;
            background: linear-gradient(180deg, rgba(25,25,25,0.95), rgba(15,15,15,0.9));
            backdrop-filter: blur(6px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 24px 26px;
            box-shadow: 0 10px 28px rgba(0,0,0,0.35);
            margin-bottom: 24px;
            box-sizing: border-box;
            max-height: 92vh;
            overflow-y: auto;
            transition: box-shadow 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
        }
        .booking-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 36px rgba(0,0,0,0.45);
            border-color: rgba(255, 255, 255, 0.12);
        }

        .booking-step-title {
            color: #fff;
            margin: 20px 0;
            font-size: 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding-bottom: 10px;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 15px;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .input-group {
            margin-bottom: 15px;
        }

        .time-slots {
            display: flex;
            gap: 15px;
            align-items: center;
            justify-content: space-between;
        }

        .time-slots label {
            flex: 0 0 80px; /* Label width */
            color: #fff;
        }

        .time-slots input {
            flex: 1;
            padding: 10px;
            background: #333;
            border: 1px solid #444;
            color: #ccc;
            border-radius: 4px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .time-slots input:hover {
            background: #444;
            border-color: #e50914;
        }

        /* Instrument UI Styles */
        .instrument-section label { color: #fff; font-weight: 600; }
        .instrument-item { padding: 8px 10px; border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; background: rgba(255,255,255,0.04); }
        .instrument-item:hover { background: rgba(255,255,255,0.08); }
        .instrument-checkbox { accent-color: #e50914; width: 18px; height: 18px; }
        .instrument-qty { background: #333; border: 1px solid #444; color: #fff; border-radius: 6px; padding: 6px 10px; }
        .instrument-availability-note { color: #ccc; font-size: 12px; }
        .instrument-notice {
            display: none;
            background: rgba(229, 9, 20, 0.12);
            color: #ffb3b8;
            border: 1px solid rgba(229, 9, 20, 0.35);
            border-radius: 8px;
            padding: 8px 12px;
            margin-bottom: 10px;
            font-size: 13px;
        }

        /* Calendar-side instrument theming */
        .calendar-container .instrument-section label { color: #333; }
        .calendar-container .instrument-item { border: 1px solid #ddd; background: #fafafa; }
        .calendar-container .instrument-item:hover { background: #f0f0f0; }
        .calendar-container .instrument-qty { background: #fff; border: 1px solid #ccc; color: #333; }
        .calendar-container .instrument-availability-note { color: #555; }
        .calendar-container .instrument-notice { color: #e50914; background: rgba(229, 9, 20, 0.08); border-color: rgba(229, 9, 20, 0.25); }

        label {
            color: #fff;
            font-size: 14px;
            display: block;
            margin-bottom: 5px;
        }

        input[type="text"] {
            width: 100%;
            padding: 10px;
            background: #333;
            border: 1px solid #444;
            color: #ccc;
            border-radius: 4px;
            font-size: 14px;
        }

        .form-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .booking-instructions {
            margin: 20px 0 30px;
        }
        
        .instructions-box {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .instructions-title {
            color: #fff;
            margin: 0 0 8px 0;
            font-size: 14px;
            font-weight: 500;
        }
        
        .instructions-list {
            color: #ddd;
            margin: 0;
            padding-left: 20px;
            font-size: 13px;
            line-height: 1.5;
        }
        
        .studio-image {
            width: 120px;
            height: 120px;
            border-radius: 8px;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .studio-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .studio-info {
            flex: 1;
            padding-left: 20px;
        }
        
        .studio-header {
            display: flex;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        
        .studio-header h3 {
            margin: 0 0 5px 0;
            color: #fff;
            font-size: 22px;
        }
        
        .studio-location {
            color: #aaa;
            margin: 0;
            font-size: 14px;
        }
        
        .booking-step-title {
            color: #fff;
            font-size: 18px;
            margin: 0 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
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
            background-color: #666;
        }

        .button.secondary:hover {
            background-color: #777;
        }

        #nextStepBtn {
            padding: 10px 20px;
            font-size: 14px;
            background-color: #e50914;
            border: none;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
        }

        #nextStepBtn:hover {
            background-color: #f40612;
        }

        #nextStepBtn:disabled {
            background-color: #888;
            cursor: not-allowed;
        }

        /* Calendar Styles */
        .calendar-container {
            height: auto;
            min-height: clamp(720px, 78vh, 900px);
            width: 44%;
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
            color: #333;
            overflow-y: visible;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        /* Custom Calendar Styles */
        .calendar-view {
            height: clamp(600px, 64vh, 760px);
            max-width: 100%;
            margin: 0;
            border: 1px solid #ccc;
            border-radius: 5px;
            padding: 10px;
            background: #f8f8f8;
            overflow: visible;
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .calendar-header h4 {
            margin: 0;
            font-size: 16px;
        }

        .calendar-header button {
            padding: 5px 10px;
            background-color: #e50914;
            border: none;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
        }

        .calendar-header button:hover {
            background-color: #f40612;
        }

        /* Month View Styles */
        .month-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .month-header button {
            background: none;
            border: none;
            color: #e50914;
            cursor: pointer;
            font-size: 14px;
        }

        .month-header button:hover {
            color: #f40612;
        }

        .month-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
        }

        .weekday-header {
            text-align: center;
            font-weight: 600;
            padding: 8px 0;
            font-size: 14px;
        }

        .calendar-day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            position: relative;
        }

        .calendar-day:hover {
            background-color: #f0f0f0;
        }

        .calendar-day.selected {
            background-color: #e50914;
            color: white;
        }

        .calendar-day.disabled {
            color: #ccc;
            cursor: not-allowed;
        }

        .calendar-day.has-events::after {
            content: '';
            position: absolute;
            bottom: 4px;
            left: 50%;
            transform: translateX(-50%);
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background-color: #e50914;
        }

        .calendar-day.empty {
            visibility: hidden;
        }

        /* Day View Styles */
        .day-header {
            margin-bottom: 15px;
            font-weight: 600;
            position: relative;
        }
        
        .selection-actions {
            margin-top: 10px;
            display: flex;
            justify-content: flex-end;
        }
        
        .clear-btn {
            background: #e50914;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: background 0.2s;
        }
        
        .clear-btn:hover {
            background: #f40612;
        }

        .time-slots-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 10px;
            margin: 15px 0;
            max-height: 350px;
            overflow-y: auto;
            padding: 15px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: background 0.2s;
        }
        
        .clear-btn:hover {
            background: #f40612;
        }

        .time-slots-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 10px;
            margin: 15px 0;
            max-height: 350px;
            overflow-y: auto;
            padding: 15px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            scrollbar-width: thin;
            scrollbar-color: #ccc #f5f5f5;
            position: relative;
            box-sizing: border-box;
        }
        
        /* Custom scrollbar for Webkit browsers */
        .time-slots-container::-webkit-scrollbar {
            width: 8px;
        }
        
        .time-slots-container::-webkit-scrollbar-track {
            background: #f5f5f5;
            border-radius: 4px;
        }
        
        .time-slots-container::-webkit-scrollbar-thumb {
            background-color: #ccc;
            border-radius: 4px;
        }
        
        .time-slots-container::-webkit-scrollbar-thumb:hover {
            background-color: #aaa;
        }

        .time-slot {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 15px 10px;
            border-radius: 6px;
            border: 2px solid #e0e0e0;
            background-color: #ffffff;
            cursor: pointer;
            transition: all 0.2s ease;
            min-height: 50px;
            text-align: center;
            font-size: 15px;
            font-weight: 500;
            color: #333;
        }

        .time-slot:hover:not(.disabled) {
            background-color: #e9ecef;
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-color: #2196F3;
        }

        .time-slot.selected-start {
            border: 2px solid #4CAF50;
            background-color: rgba(76, 175, 80, 0.1);
            font-weight: 600;
            color: #2E7D32;
        }

        .time-slot.selected-end {
            border: 2px solid #2196F3;
            background-color: rgba(33, 150, 243, 0.1);
            font-weight: 600;
            color: #1565C0;
        }
        
        .time-slot.available-end {
            border: 2px dashed #2196F3;
            background-color: rgba(33, 150, 243, 0.05);
        }
        
        .time-slot.available-end:hover {
            background-color: rgba(33, 150, 243, 0.1);
        }

        .time-slot.disabled {
            background-color: #f8f9fa;
            color: #adb5bd;
            cursor: not-allowed;
            opacity: 0.7;
        }

        .time-slot-time {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            width: 100%;
        }

        .time-slot-time i {
            font-size: 16px;
        }

        .time-slot-time span {
            font-size: 14px;
            white-space: nowrap;
        }

        /* Responsive Styles */
        @media (max-width: 768px) {
            .booking-container {
                flex-direction: column;
            }
            
            .booking-card, .calendar-container {
                width: 100%;
                margin-bottom: 20px;
            }

            /* Mobile overrides for calendar heights */
            .calendar-container { height: auto; }
            .calendar-view { height: clamp(420px, 60vh, 620px); }
            
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
    </style>
</head>
<body>
    <?php include '../../shared/components/navbar.php'; ?>

    <main class="main-content">
        <div class="fullwidth-block booking-section">
            <div class="booking-progress">
                <div class="progress-step completed">
                    <div class="step-number">1</div>
                    <div class="step-label">Select Service</div>
                </div>
                <div class="progress-step active">
                    <div class="step-number">2</div>
                    <div class="step-label">Choose Date & Time</div>
                </div>
                <div class="progress-step">
                    <div class="step-number">3</div>
                    <div class="step-label">Confirm Booking</div>
                </div>
                <div class="progress-step">
                    <div class="step-number">4</div>
                    <div class="step-label">Payment</div>
                </div>
            </div>

            <h2 class="section-title">Book Your Studio</h2>
            <div class="booking-container">
                <div class="booking-card">
                    <div class="studio-header">
                        <img src="<?php echo $studio['StudioImgBase64']; ?>" alt="<?php echo htmlspecialchars($studio['StudioName']); ?>">
                        <div>
                            <h3><?php echo htmlspecialchars($studio['StudioName']); ?></h3>
                            <p class="studio-location"><i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($studio['Loc_Desc']); ?></p>
                        </div>
                    </div>
                    
                    <div class="booking-instructions">
                        <h4 class="booking-step-title">Step 2: Choose Date & Time</h4>
                            <div class="instructions-box">
                                <p class="instructions-title">📅 <strong>How to book:</strong></p>
                                <ol class="instructions-list">
                                    <li>Click on an available date in the calendar</li>
                                    <li>Select your preferred time slot from the available options</li>
                                    <li>Available slots will be highlighted in blue</li>
                                    <li>Click "Continue to Confirmation" when done</li>
                                </ol>
                            </div>
                    </div>
                    
                    <form id="dateTimeForm" action="booking3.php" method="POST">
                        <input type="hidden" name="studio_id" value="<?php echo $studio_id; ?>">
                        <input type="hidden" name="service_id" value="<?php echo $service_id; ?>">
                        <input type="hidden" name="service_price" value="<?php echo $service['Price']; ?>">
                        <input type="hidden" name="service_name" value="<?php echo htmlspecialchars($service['ServiceType']); ?>">
                        <input type="hidden" name="instructor_id" value="<?php echo $instructor_id; ?>"> <!-- Pass instructor ID -->
                        <?php if ($instructor): ?>
                            <span><p style="color: #fff;">Selected Instructor: <?php echo htmlspecialchars($instructor['Name']); ?></p></span>
                            <span><p style="color: #fff;">Selected Service: <?php echo htmlspecialchars($service['ServiceType']); ?></p></span>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <div class="input-group">
                                <label for="date">Selected Date:</label>
                                <input type="text" id="date" name="date" readonly required>
                            </div>
                            <div class="time-slots" id="timeSlots">
                                <label for="timeStart">Start Time:</label>
                                <input type="text" id="timeStart" name="timeStart" readonly required>
                                <label for="timeEnd">End Time:</label>
                                <input type="text" id="timeEnd" name="timeEnd" readonly required>
                            </div>
                            <div id="availabilityMessage" style="margin-top: 10px; color: #fff;"></div>

                            <?php
                            // Fetch active instruments for this studio
                            $inst_options = [];
                            if (isset($studio_id) && $studio_id) {
                                $inst_q = $conn->prepare('SELECT InstrumentID, Name, HourlyRate FROM instruments WHERE StudioID = ? AND IsActive = 1 ORDER BY Name');
                                if ($inst_q) {
                                    $inst_q->bind_param('i', $studio_id);
                                    $inst_q->execute();
                                    $inst_rs = $inst_q->get_result();
                                    while ($row = $inst_rs->fetch_assoc()) { $inst_options[] = $row; }
                                    $inst_q->close();
                                }
                            }
                            ?>

                            <input type="hidden" name="instrumentSelections" id="instrumentSelections" value="[]" />
                        </div>
                        
                        <div class="form-actions">
                            <a href="booking.php?studio_id=<?php echo $studio_id; ?>" class="button secondary">Back</a>
                            <button type="submit" id="nextStepBtn" disabled>Continue to Confirmation</button>
                        </div>
                    </form>
                </div>
                
                <div class="calendar-container" id="calendar">
                    <div class="calendar-header">
                        <h4>Select Date and Time</h4>
                        <button id="changeDateBtn" style="display: none;" onclick="changeView('month')">Change Date</button>
                    </div>
                    <div id="calendarView" class="calendar-view">
                        <!-- Calendar will be rendered here -->
                    </div>

                    <div class="instrument-section calendar-instruments" style="margin-top: 8px;">
                        <label class="instrument-section-title" style="display:block; margin-bottom:8px; color:#333;">Optional: Rent Instruments per hour</label>
                        <div id="instrumentNotice" class="instrument-notice">Please choose a date and time slot first.</div>

                        <?php if (!empty($inst_options)): ?>
                            <div id="instrumentListUI" style="display:flex; flex-direction:column; gap:10px;">
                                <?php foreach ($inst_options as $inst): ?>
                                    <div class="instrument-item" data-id="<?php echo (int)$inst['InstrumentID']; ?>" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                        <label style="display:flex; align-items:center; gap:8px; margin:0; color:#333;">
                                            <input type="checkbox"
                                                   class="instrument-checkbox"
                                                   data-id="<?php echo (int)$inst['InstrumentID']; ?>"
                                                   data-name="<?php echo htmlspecialchars($inst['Name']); ?>"
                                                   data-rate="<?php echo htmlspecialchars($inst['HourlyRate']); ?>">
                                            <?php echo htmlspecialchars($inst['Name']); ?> (₱<?php echo number_format($inst['HourlyRate'], 2); ?>/hr)
                                        </label>
                                        <input type="number" class="instrument-qty form-control" data-id="<?php echo (int)$inst['InstrumentID']; ?>" min="1" value="1" style="width:100px; display:none;" />
                                        <span class="instrument-availability-note" id="instrumentAvail_<?php echo (int)$inst['InstrumentID']; ?>" style="display:none; margin-left:8px;"></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-instruments" style="color:#555;">No instruments available for this studio.</div>
                        <?php endif; ?>

                        <div id="instrumentList" style="margin-top:10px; color:#333;"></div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include '../../shared/components/footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    // Get DOM elements
    const calendarView = document.getElementById('calendarView');
    const dateInput = document.getElementById('date');
    const timeStartInput = document.getElementById('timeStart');
    const timeEndInput = document.getElementById('timeEnd');
    const changeDateBtn = document.getElementById('changeDateBtn');
    const nextStepBtn = document.getElementById('nextStepBtn');
    const availabilityMessage = document.getElementById('availabilityMessage');

    // Studio operating hours (from PHP)
    const timeIn = "<?php echo $studio['Time_IN']; ?>";
    const timeOut = "<?php echo $studio['Time_OUT']; ?>";

    // Parse existing bookings from PHP
    const existingBookings = <?php echo json_encode($bookings); ?>;

    // Current date and view state
    const currentDate = new Date();
    let selectedDate = new Date();
    let currentView = 'month';
    let selectedStartTime = null;
    let selectedEndTime = null;
    
    // Store the user's selected time slots from PHP session (filtered to current studio/service)
    const selectedSlots = <?php
        $rawSlots = isset($_SESSION['selected_slots']) && is_array($_SESSION['selected_slots']) ? $_SESSION['selected_slots'] : [];
        $filtered = array_values(array_filter($rawSlots, function($slot) use ($studio_id, $service_id) {
            return isset($slot['studio_id']) && (int)$slot['studio_id'] === (int)$studio_id
                && isset($slot['service_id']) && (int)$slot['service_id'] === (int)$service_id;
        }));
        echo json_encode($filtered);
    ?>;

    // Initialize calendar
    initializeCalendar();

    // Initialize calendar with the current month
    function initializeCalendar() {
        if (currentView === 'month') {
            renderMonthView(selectedDate);
        } else {
            renderDayView(selectedDate);
        }
    }

    // Change view between month and day
    window.changeView = function(view) {
        currentView = view;
        if (view === 'month') {
            changeDateBtn.style.display = 'none';
            dateInput.value = '';
            timeStartInput.value = '';
            timeEndInput.value = '';
            nextStepBtn.disabled = true;
            selectedStartTime = null;
            selectedEndTime = null;
            availabilityMessage.textContent = '';
        }
        initializeCalendar();
    };

    // Render month view
    function renderMonthView(date) {
        const year = date.getFullYear();
        const month = date.getMonth();
        
        const firstDayOfMonth = new Date(year, month, 1);
        const lastDayOfMonth = new Date(year, month + 1, 0);
        
        const daysInMonth = lastDayOfMonth.getDate();
        const firstDayOfWeek = firstDayOfMonth.getDay(); // 0 = Sunday, 1 = Monday, etc.
        
        const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
        
        let html = `
            <div class="month-header">
                <button onclick="navigateMonth(-1)">< Prev</button>
                <h3>${monthNames[month]} ${year}</h3>
                <button onclick="navigateMonth(1)">Next ></button>
            </div>
            <div class="month-grid">
        `;
        
        // Add weekday headers
        const weekdays = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
        weekdays.forEach(day => {
            html += `<div class="weekday-header">${day}</div>`;
        });
        
        // Add empty cells for days before the first day of the month
        for (let i = 0; i < firstDayOfWeek; i++) {
            html += `<div class="calendar-day empty"></div>`;
        }
        
        // Add days of the month
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        // Helper function to check if current time is past studio closing time
        function isPastClosingTime() {
            const now = new Date();
            const [closingHour, closingMinute] = timeOut.split(':').map(Number);
            const closingTime = new Date(
                now.getFullYear(),
                now.getMonth(),
                now.getDate(),
                closingHour,
                closingMinute
            );
            return now >= closingTime;
        }
        
        // Helper function to check if a date is today
        function isToday(date) {
            const today = new Date();
            return date.getDate() === today.getDate() &&
                   date.getMonth() === today.getMonth() &&
                   date.getFullYear() === today.getFullYear();
        }
        
        for (let i = 1; i <= daysInMonth; i++) {
            const dayDate = new Date(year, month, i);
            const isPast = dayDate < today || (isToday(dayDate) && isPastClosingTime());
            const isSelected = dayDate.getDate() === selectedDate.getDate() && 
                              dayDate.getMonth() === selectedDate.getMonth() && 
                              dayDate.getFullYear() === selectedDate.getFullYear();
            
            // Check if this day has any bookings
            const hasEvents = checkDayHasBookings(dayDate);
            
            html += `
                <div class="calendar-day ${isPast ? 'disabled' : ''} ${isSelected ? 'selected' : ''} ${hasEvents ? 'has-events' : ''}"
                     onclick="${isPast ? '' : `selectDay(${i})`}">
                    ${i}
                </div>
            `;
        }
        
        html += `</div>`;
        calendarView.innerHTML = html;
    }

    // Check if a day has any bookings
    function checkDayHasBookings(date) {
        const dateString = formatDate(date);
        return existingBookings.some(booking => {
            return booking.Sched_Date === dateString;
        });
    }

    // Format date as YYYY-MM-DD
    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    // Format time as HH:MM
    function formatTime(date) {
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        return `${hours}:${minutes}`;
    }

    // Format 24-hour 'HH:MM' to 12-hour 'h:mm AM/PM'
    function formatTimeDisplay(time24) {
        const [hStr, mStr] = time24.split(':');
        const h = parseInt(hStr, 10);
        const ampm = h >= 12 ? 'PM' : 'AM';
        const h12 = h === 0 ? 12 : h > 12 ? h - 12 : h;
        return `${h12}:${mStr} ${ampm}`;
    }

    // Navigate to previous or next month
    window.navigateMonth = function(direction) {
        selectedDate = new Date(selectedDate.getFullYear(), selectedDate.getMonth() + direction, 1);
        renderMonthView(selectedDate);
    };

    // Select a day and switch to day view
    window.selectDay = function(day) {
        selectedDate = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), day);
        dateInput.value = formatDate(selectedDate);
        currentView = 'day';
        changeDateBtn.style.display = 'inline-block';
        timeStartInput.value = '';
        timeEndInput.value = '';
        nextStepBtn.disabled = true;
        selectedStartTime = null;
        selectedEndTime = null;
        availabilityMessage.textContent = '';
        renderDayView(selectedDate);
    };

    // Render day view with time slots
    function renderDayView(date) {
        const dateString = formatDate(date);
        const dayOfWeek = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
        const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
        
        let html = `
            <div class="day-header">
                <div>${dayOfWeek[date.getDay()]}, ${monthNames[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()}</div>
                <div class="studio-hours">Studio Hours: ${formatTimeDisplay(timeIn)} - ${formatTimeDisplay(timeOut)}</div>
                ${selectedStartTime ? `
                    <div class="selection-actions">
                        <button onclick="clearSelection(event)" class="clear-btn">
                            <i class="fa fa-times"></i> Clear Selection
                        </button>
                    </div>
                ` : ''}
            </div>
            <div class="time-slots-container">
        `;
        
        // Generate time slots based on studio hours with 30-minute intervals
        const [startHour, startMinute] = timeIn.split(':').map(Number);
        const [endHour, endMinute] = timeOut.split(':').map(Number);
        
        const startTime = new Date(date);
        startTime.setHours(startHour, startMinute, 0, 0);
        
        const endTime = new Date(date);
        endTime.setHours(endHour, endMinute, 0, 0);
        
        let currentSlot = new Date(startTime);
        const now = new Date();
        const isToday = now.toDateString() === date.toDateString();
        
        // Generate time slots in 1-hour intervals
        while (currentSlot < endTime) {
            const slotTime = formatTime(currentSlot);
            const slotDateTime = new Date(currentSlot);
            
            // Determine if slot is available
            const isAvailable = checkSlotAvailability(dateString, slotTime, formatTime(new Date(currentSlot.getTime() + 3600000)));
            const isPast = isToday && currentSlot < now;
            let isSelectable = isAvailable && !isPast;
            
            // Determine if this slot is selected as start or end time
            let slotClass = '';
            if (slotTime === selectedStartTime) {
                slotClass = 'selected-start';
            } else if (slotTime === selectedEndTime) {
                slotClass = 'selected-end';
            } else if (!isSelectable) {
                slotClass = 'disabled';
            } else if (selectedStartTime && !selectedEndTime) {
                const startDateTime = new Date(`${dateString} ${selectedStartTime}:00`);
                const currentDateTime = new Date(`${dateString} ${slotTime}:00`);
                if (currentDateTime > startDateTime) {
                    // Check if this time slot is available for the entire duration from start time
                    const isDurationAvailable = checkEndTimeValidity(dateString, selectedStartTime, slotTime);
                    if (isDurationAvailable) {
                        slotClass = 'available-end';
                        isSelectable = true; // Make sure it's selectable even if it was previously disabled
                    }
                }
            }
            
            // Add tooltip for disabled slots
            let tooltip = '';
            if (!isAvailable) {
                tooltip = 'This time slot is already booked';
            } else if (isPast) {
                tooltip = 'This time slot has already passed';
            }
            
            html += `
                <div class="time-slot ${slotClass}" 
                     onclick="${isSelectable ? `selectTimeSlot('${slotTime}')` : ''}"
                     ${tooltip ? `title="${tooltip}"` : ''}>
                    <div class="time-slot-time">
                        <i class="fa fa-clock-o"></i>
                        <span>${formatTimeDisplay(slotTime)}</span>
                    </div>
                </div>
            `;
            
            // Move to next hour
            currentSlot.setHours(currentSlot.getHours() + 1);
        }
        
        html += `</div>`;
        calendarView.innerHTML = html;
    }

    // Check slot availability
    function checkSlotAvailability(dateString, startTime, endTime) {
        const startDateTime = new Date(`${dateString} ${startTime}:00`);
        const endDateTime = new Date(`${dateString} ${endTime}:00`);
        
        // Check against existing bookings
        for (const booking of existingBookings) {
            const bookingStart = new Date(`${booking.Sched_Date} ${booking.Time_Start}`);
            const bookingEnd = new Date(`${booking.Sched_Date} ${booking.Time_End}`);
            
            // Check for overlap
            if (startDateTime < bookingEnd && endDateTime > bookingStart) {
                return false; // Slot is booked
            }
        }
        
        // Check against already selected slots in the session
        if (typeof selectedSlots !== 'undefined' && Array.isArray(selectedSlots)) {
            for (const slot of selectedSlots) {
                // Skip if the slot is for a different date
                if (slot.date !== dateString) continue;
                
                const slotStart = new Date(`${slot.date} ${slot.start}:00`);
                const slotEnd = new Date(`${slot.date} ${slot.end}:00`);
                
                // Check for any overlap with existing selected slots
                if (startDateTime < slotEnd && endDateTime > slotStart) {
                    return false; // Slot overlaps with a selected slot
                }
            }
        }
        
        // Check if the slot falls within studio hours
        const studioStart = new Date(`${dateString} ${timeIn}`);
        const studioEnd = new Date(`${dateString} ${timeOut}`);
        if (startDateTime < studioStart || endDateTime > studioEnd) {
            return false; // Outside operating hours
        }
        
        return true; // Slot is available
    }

    // Clear time slot selection
    window.clearSelection = function(e) {
        if (e) e.stopPropagation();
        selectedStartTime = null;
        selectedEndTime = null;
        timeStartInput.value = '';
        timeEndInput.value = '';
        availabilityMessage.textContent = '';
        nextStepBtn.disabled = true;
        renderDayView(selectedDate);
    };

    // Select a time slot
    window.selectTimeSlot = function(time) {
        const dateString = formatDate(selectedDate);
        const selectedDateTime = new Date(`${dateString} ${time}:00`);
        const studioEnd = new Date(`${dateString} ${timeOut}`);
        const studioStart = new Date(`${dateString} ${timeIn}`);
        
        // Check if the selected time is within studio operating hours
        if (selectedDateTime < studioStart || selectedDateTime >= studioEnd) {
            availabilityMessage.style.color = '#f00';
            availabilityMessage.textContent = 'Selected time is outside studio operating hours.';
            return;
        }
        
        // If no start time selected or both times are selected, set as new start time
        if (!selectedStartTime || (selectedStartTime && selectedEndTime)) {
            selectedStartTime = time;
            selectedEndTime = null;
            timeStartInput.value = time;
            timeEndInput.value = '';
            availabilityMessage.textContent = 'Now select an end time';
            availabilityMessage.style.color = '#4CAF50';
            nextStepBtn.disabled = true;
        } 
        // If start time is selected but no end time, set as end time if valid
        else if (selectedStartTime && !selectedEndTime) {
            const startDateTime = new Date(`${dateString} ${selectedStartTime}:00`);
            
            // Validate end time is after start time
            if (selectedDateTime <= startDateTime) {
                availabilityMessage.style.color = '#f00';
                availabilityMessage.textContent = 'End time must be after start time.';
                return;
            }
            
            // Check if the selected time slot is available
            if (checkEndTimeValidity(dateString, selectedStartTime, time)) {
                selectedEndTime = time;
                timeEndInput.value = time;
                // Trigger AJAX to check final availability
                checkAvailability();
            } else {
                // Check why it's not available
                const startDateTime = new Date(`${dateString} ${selectedStartTime}:00`);
                const endDateTime = new Date(`${dateString} ${time}:00`);
                
                // Check if the selected time is in the past
                if (endDateTime <= new Date()) {
                    availabilityMessage.style.color = '#f00';
                    availabilityMessage.textContent = 'Cannot select a time in the past.';
                } 
                // Check if it's within operating hours
                else if (endDateTime < new Date(`${dateString} ${timeIn}:00`) || 
                         endDateTime > new Date(`${dateString} ${timeOut}:00`)) {
                    availabilityMessage.style.color = '#f00';
                    availabilityMessage.textContent = 'Selected time is outside studio operating hours.';
                }
                // Check for minimum duration
                else if ((endDateTime - startDateTime) < (60 * 60 * 1000)) {
                    availabilityMessage.style.color = '#f00';
                    availabilityMessage.textContent = 'Minimum booking duration is 1 hour.';
                }
                // Must be an overlap with existing booking
                else {
                    availabilityMessage.style.color = '#f00';
                    availabilityMessage.textContent = 'This time slot is not available due to an existing booking.';
                }
            }
        }
        
        // Re-render to update slot styles
        renderDayView(selectedDate);
    };

    // Check end time validity
    function checkEndTimeValidity(dateString, startTime, endTime) {
        const startDateTime = new Date(`${dateString} ${startTime}:00`);
        const endDateTime = new Date(`${dateString} ${endTime}:00`);
        
        // Ensure the end time is after the start time
        if (endDateTime <= startDateTime) {
            return false;
        }
        
        // Check minimum booking duration (e.g., at least 1 hour)
        const minDuration = 60 * 60 * 1000; // 1 hour in milliseconds
        if ((endDateTime - startDateTime) < minDuration) {
            return false;
        }
        
        // Check against existing bookings for overlaps
        for (const booking of existingBookings) {
            // Skip if booking is on a different date
            if (booking.Sched_Date !== dateString) continue;
            
            const bookingStart = new Date(`${booking.Sched_Date} ${booking.Time_Start}`);
            const bookingEnd = new Date(`${booking.Sched_Date} ${booking.Time_End}`);
            
            // Check for any overlap
            if (startDateTime < bookingEnd && endDateTime > bookingStart) {
                return false;
            }
        }
        
        // Check against already selected slots in the session
        if (typeof selectedSlots !== 'undefined' && Array.isArray(selectedSlots)) {
            for (const slot of selectedSlots) {
                // Skip if the slot is for a different date
                if (slot.date !== dateString) continue;
                
                const slotStart = new Date(`${slot.date} ${slot.start}:00`);
                const slotEnd = new Date(`${slot.date} ${slot.end}:00`);
                
                // Check for any overlap with existing selected slots
                if (startDateTime < slotEnd && endDateTime > slotStart) {
                    return false; // Slot overlaps with a selected slot
                }
            }
        }
        
        // Check if within studio hours
        const studioStart = new Date(`${dateString} ${timeIn}`);
        const studioEnd = new Date(`${dateString} ${timeOut}`);
        
        if (startDateTime < studioStart || endDateTime > studioEnd) {
            return false;
        }
        
        return true;
    }

    // Check availability via AJAX
    function checkAvailability() {
        availabilityMessage.textContent = '';
        nextStepBtn.disabled = true;
        
        // First, check locally for any obvious issues before making the AJAX call
        const dateString = dateInput.value;
        const startTime = timeStartInput.value;
        const endTime = timeEndInput.value;
        
        if (!dateString || !startTime || !endTime) {
            availabilityMessage.style.color = '#f00';
            availabilityMessage.textContent = 'Please select both start and end times.';
            return;
        }
        
        // Check if the end time is after start time
        const startDateTime = new Date(`${dateString} ${startTime}:00`);
        const endDateTime = new Date(`${dateString} ${endTime}:00`);
        
        if (endDateTime <= startDateTime) {
            availabilityMessage.style.color = '#f00';
            availabilityMessage.textContent = 'End time must be after start time.';
            return;
        }
        
        // Check minimum booking duration (1 hour)
        const minDuration = 60 * 60 * 1000; // 1 hour in milliseconds
        if ((endDateTime - startDateTime) < minDuration) {
            availabilityMessage.style.color = '#f00';
            availabilityMessage.textContent = 'Minimum booking duration is 1 hour.';
            return;
        }

        // If local checks pass, proceed with server-side validation
        $.ajax({
            url: 'check_availability.php',
            method: 'POST',
            data: {
                studio_id: <?php echo $studio_id; ?>,
                date: dateString,
                timeStart: startTime,
                timeEnd: endTime
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    availabilityMessage.style.color = '#0f0';
                    availabilityMessage.textContent = response.message;
                    nextStepBtn.disabled = false;
                } else {
                    availabilityMessage.style.color = '#f00';
                    availabilityMessage.textContent = response.message;
                    nextStepBtn.disabled = true;
                }
            },
            error: function() {
                availabilityMessage.style.color = '#f00';
                availabilityMessage.textContent = 'Error checking availability. Please try again.';
                nextStepBtn.disabled = true;
            }
        });
    }

    // Form validation
    document.getElementById('dateTimeForm').addEventListener('submit', function(e) {
        if (!dateInput.value || !timeStartInput.value || !timeEndInput.value) {
            e.preventDefault();
            alert('Please select a date and both start and end times to continue.');
        } else if (nextStepBtn.disabled) {
            e.preventDefault();
            alert('Please select an available time slot.');
        }
    });

    // Add this to the time slot click handler
    const timeSlots = document.querySelectorAll('.time-slot');
    timeSlots.forEach(slot => {
        slot.addEventListener('click', function() {
            // Get the time slot details
            const timeRange = this.getAttribute('data-time').split(' - ');
            const startTime = timeRange[0];
            const endTime = timeRange[1];
            
            // Get the URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            
            // If coming from confirmation page, save the time slot
            if (urlParams.get('from_confirm') === '1') {
                // Get additional data from URL
                const studioId = urlParams.get('studio_id') || '';
const serviceId = urlParams.get('service_id') || '';
const instructorId = urlParams.get('instructor_id') || document.querySelector('input[name="instructor_id"]')?.value || '';
const pricePerHour = this.getAttribute('data-price') || '0';

                
                // Create form data
                const formData = new URLSearchParams();
                formData.append('date', selectedDateStr);
                formData.append('start', startTime);
                formData.append('end', endTime);
                formData.append('studio_id', studioId);
                formData.append('service_id', serviceId);
                formData.append('instructor_id', instructorId);
                formData.append('price_per_hour', pricePerHour);
                
                // Store the selected time in session
                fetch('save_time_slot.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = 'booking3.php';
                    } else {
                        alert(data.message || 'Failed to save time slot. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while saving the time slot.');
                });
            } else {
                // Original behavior for new bookings
                document.getElementById('timeStart').value = startTime;
                document.getElementById('timeEnd').value = endTime;
                checkAvailability();
            }
        });
    });
});
    </script>
</body>
</html>
    
    <script>
    // Add this to the time slot click handler
    const timeSlots = document.querySelectorAll('.time-slot');
    timeSlots.forEach(slot => {
        slot.addEventListener('click', function() {
            // Get the time slot details
            const timeRange = this.getAttribute('data-time').split(' - ');
            const startTime = timeRange[0];
            const endTime = timeRange[1];
            
            // Get the URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            
            // If coming from confirmation page, save the time slot
            if (urlParams.get('from_confirm') === '1') {
                // Get additional data from URL
                const studioId = urlParams.get('studio_id') || '';
                const serviceId = urlParams.get('service_id') || '';
                const pricePerHour = this.getAttribute('data-price') || '0';
                
                // Create form data
                const formData = new URLSearchParams();
                formData.append('date', selectedDateStr);
                formData.append('start', startTime);
                formData.append('end', endTime);
                formData.append('studio_id', studioId);
                formData.append('service_id', serviceId);
                formData.append('price_per_hour', pricePerHour);
                
                // Store the selected time in session
                fetch('save_time_slot.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = 'booking3.php';
                    } else {
                        alert(data.message || 'Failed to save time slot. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while saving the time slot.');
                });
            } else {
                // Original behavior for new bookings
                document.getElementById('timeStart').value = startTime;
                document.getElementById('timeEnd').value = endTime;
                checkAvailability();
            }
        });
    });

    // Instrument add-ons logic
    (function(){
       const instrumentSelect = document.getElementById('instrumentSelect');
       const instrumentQty = document.getElementById('instrumentQty');
       const instrumentAddBtn = document.getElementById('addInstrumentBtn');
       const instrumentList = document.getElementById('instrumentList');
       const instrumentHidden = document.getElementById('instrumentSelections');
       const slotInstruments = [];

       function renderInstrumentList() {
         if (!instrumentList) return;
         if (slotInstruments.length === 0) {
            instrumentList.innerHTML = 'No instruments added.';
            instrumentHidden.value = '[]';
            return;
         }
         const html = slotInstruments.map((it, idx) => 
            `${idx+1}. ${it.name} × ${it.quantity} (₱${it.hourly_rate.toFixed(2)}/hr) <button type="button" class="button secondary remove-instrument" data-index="${idx}">Remove</button>`
          ).join('<br/>');
         instrumentList.innerHTML = html;
         instrumentHidden.value = JSON.stringify(slotInstruments);
       }

       async function checkInstrument(instrumentId, qty) {
         const studioId = document.querySelector('input[name="studio_id"]').value || new URLSearchParams(window.location.search).get('studio_id');
         const date = document.getElementById('date').value;
         const start = document.getElementById('timeStart').value;
         const end = document.getElementById('timeEnd').value;
         if (!studioId || !date || !start || !end) {
           alert('Select date and time before adding instruments.');
           return { ok: false };
         }
         const params = new URLSearchParams({ instrument_id: instrumentId, studio_id: studioId, date, start_time: start, end_time: end, quantity: qty });
         try {
           const res = await fetch('check_instrument.php?' + params.toString());
           const json = await res.json();
           if (!json.success) {
             alert(json.error || 'Instrument check failed.');
             return { ok: false, data: json };
           }
           if (!json.can_rent) {
             alert(`Only ${json.available} available during this slot.`);
             return { ok: false, data: json };
           }
           return { ok: true, data: json };
         } catch (e) {
           console.error(e);
           alert('Unable to check instrument availability right now.');
           return { ok: false };
         }
       }

       instrumentAddBtn?.addEventListener('click', async function(e){
         e.preventDefault();
         const id = instrumentSelect?.value;
         const qty = parseInt(instrumentQty?.value || '1', 10);
         if (!id) { alert('Choose an instrument'); return; }
         if (!qty || qty <= 0) { alert('Quantity must be positive'); return; }
         const name = instrumentSelect.options[instrumentSelect.selectedIndex].text.replace(/\s*\(₱.*\)$/, '');
         const rateStr = instrumentSelect.options[instrumentSelect.selectedIndex].getAttribute('data-rate') || '0';
         const rate = parseFloat(rateStr);
         const check = await checkInstrument(id, qty);
         if (!check.ok) return;
         const existingIdx = slotInstruments.findIndex(it => it.instrument_id === parseInt(id, 10));
         if (existingIdx >= 0) {
            slotInstruments[existingIdx].quantity += qty;
         } else {
            slotInstruments.push({ instrument_id: parseInt(id, 10), name, hourly_rate: rate, quantity: qty });
         }
         renderInstrumentList();
       });

       instrumentList?.addEventListener('click', function(e){
         if (e.target.matches('.remove-instrument')) {
           const idx = parseInt(e.target.getAttribute('data-index'), 10);
           if (!Number.isNaN(idx)) {
             slotInstruments.splice(idx, 1);
             renderInstrumentList();
           }
         }
       });

       document.getElementById('dateTimeForm')?.addEventListener('submit', function(){
         instrumentHidden.value = JSON.stringify(slotInstruments);
       });
     })();

    // Instrument add-ons logic (checkbox-based)
    (function(){
       const instrumentList = document.getElementById('instrumentList');
       const instrumentHidden = document.getElementById('instrumentSelections');
       const slotInstruments = [];

       function renderInstrumentSummary() {
         if (!instrumentList) return;
         if (slotInstruments.length === 0) {
            instrumentList.innerHTML = 'No instruments selected.';
            instrumentHidden.value = '[]';
            return;
         }
         const html = slotInstruments.map((it, idx) => 
            `${idx+1}. ${it.name} × ${it.quantity} (₱${it.hourly_rate.toFixed(2)}/hr)`
          ).join('<br/>');
         instrumentList.innerHTML = html;
         instrumentHidden.value = JSON.stringify(slotInstruments);
       }

       async function checkInstrument(instrumentId, qty) {
         const studioId = document.querySelector('input[name="studio_id"]').value || new URLSearchParams(window.location.search).get('studio_id');
         const date = document.getElementById('date').value;
         const start = document.getElementById('timeStart').value;
         const end = document.getElementById('timeEnd').value;
         if (!studioId || !date || !start || !end) {
           return { ok: false };
         }
         const params = new URLSearchParams({ instrument_id: instrumentId, studio_id: studioId, date, start_time: start, end_time: end, quantity: qty });
         try {
           const res = await fetch('check_instrument.php?' + params.toString());
           const json = await res.json();
           if (!json.success) {
             return { ok: false, data: json };
           }
           return { ok: true, data: json };
         } catch (e) {
           console.error(e);
           return { ok: false };
         }
       }

       function upsertInstrument(id, name, rate, quantity) {
         const idx = slotInstruments.findIndex(it => it.instrument_id === id);
         if (quantity <= 0) {
           if (idx >= 0) slotInstruments.splice(idx, 1);
         } else if (idx >= 0) {
           slotInstruments[idx].quantity = quantity;
         } else {
           slotInstruments.push({ instrument_id: id, name, hourly_rate: rate, quantity });
         }
         renderInstrumentSummary();
       }

       document.querySelectorAll('.instrument-checkbox').forEach(cb => {
         const id = parseInt(cb.getAttribute('data-id'), 10);
         const name = cb.getAttribute('data-name');
         const rate = parseFloat(cb.getAttribute('data-rate') || '0');
         const qtyInput = document.querySelector(`.instrument-qty[data-id="${id}"]`);
         const note = document.getElementById(`instrumentAvail_${id}`);

         cb.addEventListener('change', async function() {
           if (cb.checked) {
             const dateVal = document.getElementById('date')?.value;
             const startVal = document.getElementById('timeStart')?.value;
             const endVal = document.getElementById('timeEnd')?.value;
             if (!dateVal || !startVal || !endVal) {
               const n = document.getElementById('instrumentNotice');
               if (n) { n.style.display = 'block'; n.textContent = 'Please choose a date and time slot first.'; }
               cb.checked = false;
               qtyInput.style.display = 'none';
               note.style.display = 'none';
               return;
             }
             qtyInput.style.display = 'inline-block';
             note.style.display = 'inline';
             qtyInput.step = '1';
             qtyInput.min = '1';
             let qty = parseInt(qtyInput.value || '1', 10);
             if (!qty || qty < 1) qty = 1;
             qtyInput.value = qty;
             const check = await checkInstrument(id, qty);
             if (!check.ok || !check.data) {
               cb.checked = false;
               qtyInput.style.display = 'none';
               note.style.display = 'none';
               return;
             }
             const avail = parseInt(check.data.available, 10);
             qtyInput.max = isNaN(avail) ? '' : String(avail);
             note.textContent = `Available: ${avail}`;
             if (avail <= 0) {
               cb.checked = false;
               qtyInput.style.display = 'none';
               note.style.display = 'none';
               return;
             }
             if (qty > avail) {
               qty = avail;
               qtyInput.value = qty;
             }
             upsertInstrument(id, name, rate, qty);
           } else {
             qtyInput.style.display = 'none';
             note.style.display = 'none';
             upsertInstrument(id, name, rate, 0);
           }
         });

         qtyInput.addEventListener('input', async function() {
           let qty = parseInt(qtyInput.value || '1', 10);
           if (!qty || qty < 1) qty = 1;
           const check = await checkInstrument(id, qty);
           if (!check.ok || !check.data) {
             qtyInput.value = 1;
             upsertInstrument(id, name, rate, 1);
             return;
           }
           const avail = parseInt(check.data.available, 10);
           qtyInput.max = isNaN(avail) ? '' : String(avail);
           note.textContent = `Available: ${avail}`;
           if (avail <= 0) {
             cb.checked = false;
             qtyInput.style.display = 'none';
             note.style.display = 'none';
             upsertInstrument(id, name, rate, 0);
             return;
           }
           if (qty > avail) qty = avail;
           qtyInput.value = qty;
           upsertInstrument(id, name, rate, qty);
         });
       });

       ['date','timeStart','timeEnd'].forEach(id => {
         const el = document.getElementById(id);
         el?.addEventListener('change', async function(){
           const n = document.getElementById('instrumentNotice');
           const dateVal = document.getElementById('date')?.value;
           const startVal = document.getElementById('timeStart')?.value;
           const endVal = document.getElementById('timeEnd')?.value;
           if (n && dateVal && startVal && endVal) { n.style.display = 'none'; }
           for (const it of [...slotInstruments]) {
             const cbSel = document.querySelector(`.instrument-checkbox[data-id="${it.instrument_id}"]`);
             const qtySel = document.querySelector(`.instrument-qty[data-id="${it.instrument_id}"]`);
             const noteSel = document.getElementById(`instrumentAvail_${it.instrument_id}`);
             const check = await checkInstrument(it.instrument_id, it.quantity);
             if (!check.ok || !check.data) continue;
             const avail = parseInt(check.data.available, 10);
             qtySel.max = isNaN(avail) ? '' : String(avail);
             noteSel.textContent = `Available: ${avail}`;
             if (avail <= 0) {
               cbSel.checked = false;
               qtySel.style.display = 'none';
               noteSel.style.display = 'none';
               upsertInstrument(it.instrument_id, it.name, it.hourly_rate, 0);
             } else if (it.quantity > avail) {
               qtySel.value = avail;
               upsertInstrument(it.instrument_id, it.name, it.hourly_rate, avail);
             }
           }
         });
       });

       document.getElementById('dateTimeForm')?.addEventListener('submit', function(){
         instrumentHidden.value = JSON.stringify(slotInstruments);
       });
     })();
    </script>
</body>
</html>

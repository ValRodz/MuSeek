<?php
session_start();
include '../../shared/config/db.php';
require_once '../../shared/config/path_config.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo "<script>
        alert('Please log in to view this page.');
        window.location.href = '../../auth/php/login.php';
    </script>";
    exit;
}

// Check if booking_id is provided
if (!isset($_GET['booking_id'])) {
    header("Location: ../../client/php/browse.php");
    exit;
}

$booking_id = (int)$_GET['booking_id'];
$is_multi = false; // will be determined automatically based on booking_date
$booking_count = 1; // kept for backward compatibility but not used

// Fetch booking details
$query = "SELECT b.*, s.StudioName, s.Loc_Desc, s.StudioImg, 
                 sv.ServiceType, sv.Price as ServicePrice, sch.Sched_Date, sch.Time_Start, sch.Time_End,
                 i.Name AS InstructorName, COALESCE(p.Amount, sv.Price, 0) as TotalAmount
          FROM bookings b
          JOIN studios s ON b.StudioID = s.StudioID
          JOIN services sv ON b.ServiceID = sv.ServiceID
          JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
          LEFT JOIN instructors i ON b.InstructorID = i.InstructorID
          LEFT JOIN payment p ON b.BookingID = p.BookingID
          WHERE b.BookingID = ? AND b.ClientID = ?";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "ii", $booking_id, $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($result)) {
    if ($row['StudioImg']) {
        $row['StudioImgBase64'] = 'data:image/jpeg;base64,' . base64_encode($row['StudioImg']);
    } else {
        $row['StudioImgBase64'] = '../../shared/assets/images/default_studio.jpg';
    }
    $booking = $row;
} else {
    header("Location: ../../client/php/browse.php");
    exit;
}

// Fetch instrument add-ons for this booking (single)
$booking_addons = [];
$addon_query = "SELECT ba.Quantity, ba.Price, i.Name FROM booking_addons ba JOIN instruments i ON i.InstrumentID = ba.InstrumentID WHERE ba.BookingID = ?";
$addon_stmt = mysqli_prepare($conn, $addon_query);
if ($addon_stmt) {
    mysqli_stmt_bind_param($addon_stmt, "i", $booking_id);
    mysqli_stmt_execute($addon_stmt);
    $addon_result = mysqli_stmt_get_result($addon_stmt);
    while ($addon_row = mysqli_fetch_assoc($addon_result)) {
        $booking_addons[] = $addon_row;
    }
    mysqli_stmt_close($addon_stmt);
}

// Fetch related bookings created at the same time (grouped by booking_date)
$bookings = [];
$multi_query = "SELECT b.*, sv.ServiceType, sch.Sched_Date, sch.Time_Start, sch.Time_End,
                       i.Name AS InstructorName
                FROM bookings b
                JOIN services sv ON b.ServiceID = sv.ServiceID
                JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
                LEFT JOIN instructors i ON b.InstructorID = i.InstructorID
                WHERE b.ClientID = ? AND b.booking_date = ?
                ORDER BY sch.Sched_Date, sch.Time_Start";

$stmt = mysqli_prepare($conn, $multi_query);
if ($stmt) {
    $booking_datetime = $booking['booking_date'];
    mysqli_stmt_bind_param($stmt, "is", $_SESSION['user_id'], $booking_datetime);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $bookings[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// Determine if this is a multi-booking automatically
$is_multi = count($bookings) > 1;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>Booking Confirmation - MuSeek</title>
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

        .confirmation-container {
            max-width: 800px;
            margin: 0 auto;
            background: rgba(0, 0, 0, 0.8);
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        .confirmation-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .confirmation-icon {
            font-size: 64px;
            color: #4CAF50;
            margin-bottom: 20px;
        }

        .confirmation-title {
            color: #fff;
            font-size: 32px;
            margin-bottom: 10px;
        }

        .confirmation-subtitle {
            color: #ccc;
            font-size: 18px;
            margin-bottom: 30px;
        }

        .booking-summary {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .booking-details {
            display: flex;
            margin-bottom: 20px;
        }

        .studio-image {
            width: 120px;
            height: 120px;
            border-radius: 8px;
            object-fit: cover;
            margin-right: 20px;
            border: 2px solid rgba(255, 255, 255, 0.1);
        }

        .booking-info {
            flex: 1;
        }

        .booking-info h3 {
            color: #fff;
            margin-top: 0;
            margin-bottom: 10px;
        }

        .booking-info p {
            color: #ccc;
            margin: 5px 0;
        }

        .time-slots {
            margin: 30px 0;
            padding: 25px;
            background: linear-gradient(135deg, #1a1a1a 0%, #0d0d0d 100%);
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5);
            border: 1px solid #333;
        }

        .time-slots h4 {
            color: #fff;
            margin-bottom: 20px;
            font-size: 1.3em;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .time-slots h4 i {
            color: #dc143c;
            font-size: 1.2em;
        }

        .slots-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .slot-card {
            background: linear-gradient(135deg, #1a1a1a, #0d0d0d);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5);
            border: 1px solid #333;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .slot-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(220, 20, 60, 0.3);
            border-color: #dc143c;
            background: linear-gradient(135deg, #2a1a1a, #1d0d0d);
        }

        .slot-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #dc143c, #ff1744);
        }

        .slot-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 12px;
            border-bottom: 2px solid #333;
        }

        .slot-number {
            display: flex;
            align-items: center;
        }

        .slot-badge {
            background: linear-gradient(135deg, #dc143c, #ff1744);
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9em;
            box-shadow: 0 2px 8px rgba(220, 20, 60, 0.4);
        }

        .slot-date {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #fff;
            font-weight: 500;
        }

        .slot-date i {
            color: #dc143c;
            font-size: 1.1em;
        }

        .slot-time {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding: 12px 15px;
            background: rgba(220, 20, 60, 0.15);
            border-radius: 8px;
            border-left: 4px solid #dc143c;
        }

        .slot-time i {
            color: #dc143c;
            font-size: 1.2em;
        }

        .time-range {
            font-weight: 600;
            color: #fff;
            font-size: 1.05em;
        }

        .slot-details {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .slot-instructor,
        .slot-service {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid #333;
            border-radius: 6px;
            color: #ccc;
            transition: all 0.2s ease;
        }

        .slot-instructor:hover,
        .slot-service:hover {
            background: rgba(220, 20, 60, 0.1);
            border-color: #dc143c;
            color: #fff;
        }

        .slot-instructor i {
            color: #dc143c;
            width: 16px;
        }

        .slot-service i {
            color: #ff1744;
            width: 16px;
        }

        .slot-instructor span,
        .slot-service span {
            font-weight: 500;
        }

        .time-slot,
        .instructor-service {
            display: flex;
            justify-content: space-between;
            padding: 12px 15px;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid #333;
            border-radius: 6px;
            margin-bottom: 8px;
            color: #fff;
        }

        .time-slot:last-child,
        .instructor-service:last-child {
            margin-bottom: 0;
        }

        .next-steps {
            background: rgba(233, 30, 99, 0.1);
            border-left: 4px solid #e91e63;
            padding: 15px 20px;
            margin: 30px 0;
            border-radius: 0 4px 4px 0;
        }

        .next-steps h3 {
            color: #e91e63;
            margin-top: 0;
            margin-bottom: 10px;
        }

        .next-steps ol {
            padding-left: 20px;
            margin: 0;
            color: #ccc;
        }

        .next-steps li {
            margin-bottom: 8px;
        }

        .payment-section {
            background: rgba(76, 175, 80, 0.1);
            border-left: 4px solid #4CAF50;
            padding: 20px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }

        .payment-section h3 {
            color: #4CAF50;
            margin-top: 0;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .payment-content {
            display: flex;
            gap: 30px;
            align-items: flex-start;
        }

        .payment-text {
            flex: 1;
            color: #ccc;
        }

        .payment-text p {
            margin-bottom: 12px;
            line-height: 1.6;
        }

        .payment-amount {
            background: rgba(76, 175, 80, 0.15);
            padding: 15px;
            border-radius: 6px;
            margin-top: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .amount-label {
            color: #4CAF50;
            font-weight: 600;
        }

        .amount-value {
            color: #fff;
            font-size: 20px;
            font-weight: 700;
        }

        .qr-code-container {
            text-align: center;
            background: rgba(255, 255, 255, 0.95);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            min-width: 200px;
        }

        .gcash-qr {
            width: 150px;
            height: 150px;
            border-radius: 8px;
            margin-bottom: 10px;
            display: block;
            margin-left: auto;
            margin-right: auto;
        }

        .qr-instruction {
            color: #333;
            font-weight: 600;
            margin: 0;
            font-size: 14px;
        }

        .action-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 30px;
        }

        .button {
            padding: 12px 24px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            text-align: center;
        }

        .button.primary {
            background: #e50914;
            color: #fff;
        }

        .button.primary:hover {
            background: #f40612;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(229, 9, 20, 0.3);
        }

        .button.secondary {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .button.secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            .confirmation-container {
                padding: 20px;
                margin: 0 15px;
            }

            .booking-details {
                flex-direction: column;
            }

            .studio-image {
                width: 100%;
                height: 200px;
                margin-right: 0;
                margin-bottom: 15px;
            }

            .payment-content {
                flex-direction: column;
                gap: 20px;
            }

            .qr-code-container {
                align-self: center;
                min-width: auto;
                width: 100%;
                max-width: 250px;
            }

            .action-buttons {
                flex-direction: column;
                gap: 10px;
            }

            .button {
                width: 100%;
            }

            .slots-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .slot-card {
                padding: 15px;
            }

            .slot-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .slot-date {
                font-size: 0.9em;
            }

            .slot-time {
                padding: 10px 12px;
            }

            .time-range {
                font-size: 1em;
            }
        }
    </style>
</head>

<body>
    <?php include '../../shared/components/navbar.php'; ?>

    <main class="main-content">
        <div class="fullwidth-block" style="padding: 60px 20px;">
            <div class="confirmation-container">
                <div class="confirmation-header">
                    <div class="confirmation-icon">
                        <i class="fa fa-check-circle"></i>
                    </div>
                    <h1 class="confirmation-title">Booking Confirmed!</h1>
                    <p class="confirmation-subtitle">Your booking has been successfully placed.</p>
                </div>

                <div class="booking-summary">
                    <div class="booking-details">
                        <img src="<?php echo $booking['StudioImgBase64']; ?>" alt="<?php echo htmlspecialchars($booking['StudioName']); ?>" class="studio-image">
                        <div class="booking-info">
                            <h3><?php echo htmlspecialchars($booking['StudioName']); ?></h3>
                            <p><i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($booking['Loc_Desc']); ?></p>
                            <p><i class="fa fa-credit-card"></i> Booking ID: #<?php echo $booking['BookingID']; ?></p>
                        </div>
                    </div>



                    <?php if (!$is_multi): ?>
                        <?php
                            $single_price = 0;
                            $single_initial = 0;
                            $price_query = "SELECT Amount, Init_Amount FROM payment WHERE BookingID = ?";
                            $price_stmt = mysqli_prepare($conn, $price_query);
                            if ($price_stmt) {
                                mysqli_stmt_bind_param($price_stmt, "i", $booking['BookingID']);
                                mysqli_stmt_execute($price_stmt);
                                $price_result = mysqli_stmt_get_result($price_stmt);
                                $price_row = mysqli_fetch_assoc($price_result);
                                $single_price = $price_row ? (float)$price_row['Amount'] : (float)$booking['ServicePrice'];
                                $single_initial = $price_row ? (float)$price_row['Init_Amount'] : 0.0;
                                mysqli_stmt_close($price_stmt);
                            } else {
                                $single_price = (float)$booking['ServicePrice'];
                                $single_initial = 0.0;
                            }
                        ?>
                        <div class="time-slots">
                            <h4><i class="fas fa-calendar-alt"></i> Booked Time Slot</h4>
                            <div class="slots-grid" style="display:block;">
                                <div class="slot-card">
                                    <div class="slot-header">
                                        <div class="slot-number">
                                            <span class="slot-badge">1</span>
                                        </div>
                                        <div class="slot-date">
                                            <i class="fas fa-calendar"></i>
                                            <span><?php echo date('M j, Y', strtotime($booking['Sched_Date'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="slot-time">
                                        <i class="fas fa-clock"></i>
                                        <span class="time-range">
                                            <?php echo date('g:i A', strtotime($booking['Time_Start'])); ?> - 
                                            <?php echo date('g:i A', strtotime($booking['Time_End'])); ?>
                                        </span>
                                    </div>
                                    <div class="slot-details">
                                        <?php if (!empty($booking['InstructorName'])): ?>
                                            <div class="slot-instructor">
                                                <i class="fas fa-user-tie"></i>
                                                <span><?php echo htmlspecialchars($booking['InstructorName']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="slot-service">
                                            <i class="fas fa-music"></i>
                                            <span><?php echo htmlspecialchars($booking['ServiceType']); ?></span>
                                        </div>
                                        <div class="slot-price">
                                            <i class="fas fa-tag"></i>
                                            <span>Price: ₱<?php echo number_format($single_price, 2); ?></span>
                                        </div>
                                        <div class="slot-initial">
                                            <i class="fas fa-money-bill"></i>
                                            <span>Initial Payment: ₱<?php echo number_format($single_initial, 2); ?></span>
                                        </div>

                                        <?php if (!empty($booking_addons)): ?>
                                            <div class="slot-addons" style="margin-top:8px;">
                                                <div style="color:#fff; font-weight:600;"><i class="fas fa-guitar"></i> Instruments Added</div>
                                                <?php foreach ($booking_addons as $addon): ?>
                                                    <div class="slot-addon-line" style="display:flex; justify-content:space-between;">
                                                        <span style="color:#fff;"><?php echo htmlspecialchars($addon['Name']); ?></span>
                                                        <span style="color:#fff;">× <?php echo (int)$addon['Quantity']; ?> | ₱<?php echo number_format($addon['Price'], 2); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($is_multi && count($bookings) > 1): ?>
                        <div class="time-slots">
                            <h4><i class="fas fa-calendar-alt"></i> All Booked Time Slots</h4>
                            <div class="slots-grid">
                                <?php 
                                $total_initial_amount = 0;
                                foreach ($bookings as $index => $slot): 
                                    // Calculate hours and price
                                    $start_time = new DateTime($slot['Time_Start']);
                                    $end_time = new DateTime($slot['Time_End']);
                                    $hours = ($end_time->getTimestamp() - $start_time->getTimestamp()) / 3600;
                                    
                                    // Fetch price from payment table
                                    $price_query = "SELECT Amount, Init_Amount FROM payment WHERE BookingID = ?";
                                    $price_stmt = mysqli_prepare($conn, $price_query);
                                    mysqli_stmt_bind_param($price_stmt, "i", $slot['BookingID']);
                                    mysqli_stmt_execute($price_stmt);
                                    $price_result = mysqli_stmt_get_result($price_stmt);
                                    $price_row = mysqli_fetch_assoc($price_result);
                                    
                                    $slot_price = $price_row ? $price_row['Amount'] : 0;
                                    $slot_initial = $price_row ? $price_row['Init_Amount'] : 0;
                                    $total_initial_amount += $slot_initial;
                                ?>
                                    <div class="slot-card">
                                        <div class="slot-header">
                                            <div class="slot-number">
                                                <span class="slot-badge"><?php echo $index + 1; ?></span>
                                            </div>
                                            <div class="slot-date">
                                                <i class="fas fa-calendar"></i>
                                                <span><?php echo date('M j, Y', strtotime($slot['Sched_Date'])); ?></span>
                                            </div>
                                        </div>
                                        <div class="slot-time">
                                            <i class="fas fa-clock"></i>
                                            <span class="time-range">
                                                <?php echo date('g:i A', strtotime($slot['Time_Start'])); ?> - 
                                                <?php echo date('g:i A', strtotime($slot['Time_End'])); ?>
                                            </span>
                                        </div>
                                        <div class="slot-details">
                                            <?php if (!empty($slot['InstructorName'])): ?>
                                                <div class="slot-instructor">
                                                    <i class="fas fa-user-tie"></i>
                                                    <span><?php echo htmlspecialchars($slot['InstructorName']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="slot-service">
                                                <i class="fas fa-music"></i>
                                                <span><?php echo htmlspecialchars($slot['ServiceType']); ?></span>
                                            </div>
                                            <div class="slot-price">
                                                <i class="fas fa-tag"></i>
                                                <span>Price: ₱<?php echo number_format($slot_price, 2); ?></span>
                                            </div>
                                            <div class="slot-initial">
                                                <i class="fas fa-money-bill"></i>
                                                <span>Initial Payment: ₱<?php echo number_format($slot_initial, 2); ?></span>
                                            </div>

                                            <?php
                                            // Fetch add-ons for this slot
                                            $slot_addons = [];
                                            $addon_query = "SELECT ba.Quantity, ba.Price, i.Name FROM booking_addons ba JOIN instruments i ON i.InstrumentID = ba.InstrumentID WHERE ba.BookingID = ?";
                                            $addon_stmt = mysqli_prepare($conn, $addon_query);
                                            if ($addon_stmt) {
                                                mysqli_stmt_bind_param($addon_stmt, "i", $slot['BookingID']);
                                                mysqli_stmt_execute($addon_stmt);
                                                $addon_result = mysqli_stmt_get_result($addon_stmt);
                                                while ($addon_row = mysqli_fetch_assoc($addon_result)) {
                                                    $slot_addons[] = $addon_row;
                                                }
                                                mysqli_stmt_close($addon_stmt);
                                            }
                                            if (!empty($slot_addons)):
                                            ?>
                                                <div class="slot-addons" style="margin-top:8px;">
                                                    <div style="color:#fff; font-weight:600;"><i class="fas fa-guitar"></i> Instruments Added</div>
                                                    <?php foreach ($slot_addons as $addon): ?>
                                                        <div class="slot-addon-line" style="display:flex; justify-content:space-between;">
                                                            <span style="color:#fff;"><?php echo htmlspecialchars($addon['Name']); ?></span>
                                                            <span style="color:#fff;">× <?php echo (int)$addon['Quantity']; ?> | ₱<?php echo number_format($addon['Price'], 2); ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="next-steps">
                    <h3>What's Next?</h3>
                    <ol>
                        <li>You'll receive a confirmation email with your booking details shortly.</li>
                        <li>Please arrive at least 15 minutes before your scheduled time.</li>
                        <li>Bring a valid ID for verification.</li>
                        <li>Check your email for any updates or changes to your booking.</li>
                    </ol>
                </div>

                <div class="payment-section">
                    <h3><i class="fas fa-credit-card"></i> Payment Information</h3>
                    <div class="payment-content">
                        <div class="payment-text">
                            <p>Complete your payment using GCash for a seamless booking experience.</p>
                            <p>Scan the QR code below with your GCash app to proceed with payment.</p>
                            <?php if ($is_multi && count($bookings) > 1): ?>
                            <div class="payment-amount">
                                <span class="amount-label">Total Initial Amount:</span>
                                <span class="amount-value">₱<?php echo number_format($total_initial_amount, 2); ?></span>
                            </div>
                            <?php else: ?>
                            <div class="payment-amount">
                                <span class="amount-label">Total Initial Amount:</span>
                                <span class="amount-value">₱<?php echo number_format($single_initial, 2); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="qr-code-container">
                            <img src="../../shared/assets/images/images/GCash.webp" alt="GCash QR Code" class="gcash-qr">
                            <p class="qr-instruction">Scan with GCash App</p>
                        </div>
                    </div>
                </div>

                <div class="action-buttons">
                    <a href="../../client/php/client_bookings.php" class="button primary">View My Bookings</a>
                    <a href="../../client/php/browse.php" class="button secondary">Book Another Session</a>
                </div>
            </div>
        </div>
    </main>

    <?php include '../../shared/components/footer.php'; ?>
</body>

</html>

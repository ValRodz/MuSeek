<?php
// Set session cookie parameters before starting the session
session_set_cookie_params([
    'lifetime' => 1440, // 24 minutes
    'path' => '/',
    'secure' => false, // Set to true if using HTTPS, false for localhost
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
include '../../shared/config/db.php';
include '../../shared/config/path_config.php';

// Check if user is authenticated and is a client
$is_authenticated = isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'client';

if (!$is_authenticated) {
    header('Location: ../../auth/php/login.php');
    exit();
}

// Fetch client details
$client_query = "SELECT Name, Email, Phone FROM clients WHERE ClientID = ?";
$stmt = mysqli_prepare($conn, $client_query);
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$client_result = mysqli_stmt_get_result($stmt);
$client = mysqli_fetch_assoc($client_result) ?: [
    'Name' => 'Unknown',
    'Email' => 'N/A',
    'Phone' => 'N/A'
];
mysqli_stmt_close($stmt);

// Fetch bookings for the client with service options and payment group information
// Include all bookings (archived and non-archived) - filtering will be done client-side
$bookings_query = "
    SELECT 
        b.BookingID, 
        b.booking_date AS creation_date, 
        s.StudioID,
        s.OwnerID,
        s.StudioName, 
        sv.ServiceType, 
        sv.ServiceID AS current_service_id,
        sv.Price,
        b.InstructorID,
        sch.Sched_Date, 
        sch.Time_Start, 
        sch.Time_End, 
        bs.Book_Stats AS status,
        b.Book_StatsID,
        p.PaymentGroupID,
        p.Pay_Stats
    FROM bookings b
    JOIN studios s ON b.StudioID = s.StudioID
    JOIN services sv ON b.ServiceID = sv.ServiceID
    JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
    JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
    JOIN payment p ON b.BookingID = p.BookingID
    WHERE b.ClientID = ?
    ORDER BY sch.Sched_Date DESC, sch.Time_Start DESC
";
$stmt = mysqli_prepare($conn, $bookings_query);
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$bookings_result = mysqli_stmt_get_result($stmt);

$bookings = [];
while ($row = mysqli_fetch_assoc($bookings_result)) {
    $bookings[] = $row;
}
$booking_count = count($bookings);

// Fetch all available services for the dropdown (will be filtered by studio in modal)
$services_query = "SELECT ServiceID, ServiceType FROM services";
$services_result = mysqli_query($conn, $services_query);
$services = mysqli_fetch_all($services_result, MYSQLI_ASSOC);

// Fetch studio information including TIME_OUT for each booking
$studio_info = [];
foreach ($bookings as &$booking) {
    if (!isset($studio_info[$booking['StudioName']])) {
        $studio_query = "SELECT StudioID, Time_IN, Time_OUT FROM studios WHERE StudioName = ?";
        $stmt = mysqli_prepare($conn, $studio_query);
        mysqli_stmt_bind_param($stmt, "s", $booking['StudioName']);
        mysqli_stmt_execute($stmt);
        $studio_result = mysqli_stmt_get_result($stmt);
        $studio_data = mysqli_fetch_assoc($studio_result);
        if ($studio_data) {
            $studio_info[$booking['StudioName']] = $studio_data;
            $booking['StudioID'] = $studio_data['StudioID'];
            $booking['Time_OUT'] = $studio_data['Time_OUT'];
        }
        mysqli_stmt_close($stmt);
    } else {
        $booking['StudioID'] = $studio_info[$booking['StudioName']]['StudioID'];
        $booking['Time_OUT'] = $studio_info[$booking['StudioName']]['Time_OUT'];
    }
}

// Fetch unique time slots from schedules for the dropdown
$time_slots_query = "SELECT DISTINCT Time_Start, Time_End FROM schedules ORDER BY Time_Start";
$time_slots_result = mysqli_query($conn, $time_slots_query);
$time_slots = [];
while ($row = mysqli_fetch_assoc($time_slots_result)) {
    $time_slots[] = $row;
}
mysqli_free_result($time_slots_result);
mysqli_free_result($services_result);

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>My Bookings - MuSeek</title>
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="<?php echo getCSSPath('style.css'); ?>">
    <style>
        /* Modern CSS Variables for consistent theming */
        :root {
            --primary-color: #e50914;
            --primary-hover: #f40612;
            --secondary-color: #3b82f6;
            --background-dark: #0f0f0f;
            --background-card: rgba(20, 20, 20, 0.95);
            --text-primary: #ffffff;
            --text-secondary: #b3b3b3;
            --border-color: #333333;
            --shadow-medium: 0 4px 16px rgba(0, 0, 0, 0.4);
            --border-radius: 12px;
            --border-radius-small: 8px;
        }
        
        #branding img { width: 180px; display: block; }
        
        body, main {
            background: linear-gradient(135deg, rgba(15, 15, 15, 0.9), rgba(30, 30, 30, 0.8)), 
                        url('../../shared/assets/images/dummy/slide-1.jpg') no-repeat center center fixed;
            background-size: cover;
            position: relative;
            font-family: 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--text-primary);
            margin: 0;
            padding: 0;
        }
        
        main { margin-top: 5%; }
        
        .bookings-section {
            padding: 40px 0;
            margin: 0;
            width: 100%;
            min-height: 60vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .bookings-section h2.section-title {
            margin: 40px 0 30px;
            color: #fff;
            font-size: 50px;
            text-align: center;
        }
        .bookings-container {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
            box-sizing: border-box;
        }
        .bookings-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--background-card);
            color: var(--text-primary);
            font-size: 14px;
            table-layout: auto;
            overflow-x: auto;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-medium);
            backdrop-filter: blur(10px);
        }
        .bookings-table th, .bookings-table td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }
        .bookings-table th {
            background: var(--primary-color);
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .bookings-table tr:hover {
            background: rgba(255, 255, 255, 0.05);
            transition: all 0.2s ease;
        }
        .status {
            font-weight: 600;
            text-transform: capitalize;
            padding: 4px 8px;
            border-radius: var(--border-radius-small);
            display: inline-block;
        }
        .status.confirmed { 
            color: #28a745; 
            background: rgba(40, 167, 69, 0.1);
        }
        .status.pending { 
            color: #ffb400; 
            background: rgba(255, 180, 0, 0.1);
        }
        .status.cancelled { 
            color: #dc3545; 
            background: rgba(220, 53, 69, 0.1);
        }
        .status.archived { 
            color: #17a2b8; 
            background: rgba(23, 162, 184, 0.1);
        }
        .status.finished { 
            color: #6f42c1; 
            background: rgba(111, 66, 193, 0.1);
        }
        .no-bookings {
            text-align: center;
            color: var(--text-secondary);
            font-size: 16px;
            padding: 30px;
            background: var(--background-card);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-medium);
        }
        .action-buttons .cancel-button,
        .action-buttons .update-button,
        .action-buttons .finish-button {
            padding: 8px 14px;
            font-size: 13px;
            border: none;
            border-radius: var(--border-radius-small);
            cursor: pointer;
            color: #fff;
            margin-right: 5px;
            font-weight: 600;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            text-decoration: none;
            display: inline-block;
        }
        .action-buttons .cancel-button {
            background: #dc3545;
        }
        .action-buttons .cancel-button:hover {
            background: #c82333;
            transform: translateY(-2px);
        }
        .action-buttons .update-button {
            background: var(--secondary-color);
        }
        .action-buttons .update-button:hover {
            background: #2563eb;
            transform: translateY(-2px);
        }
        .action-buttons .finish-button {
            background: #28a745;
        }
        .action-buttons .finish-button:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        /* Group Indicator Styles */
        .group-indicator {
            font-size: 16px;
            color: #28a745;
            cursor: help;
            transition: all 0.2s ease;
        }
        .group-indicator:hover {
            color: #218838;
            transform: scale(1.1);
        }
        .single-booking {
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        /* Search and Filter Styles */
        .search-filter-container {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto 30px;
            padding: 24px;
            box-sizing: border-box;
            background: var(--background-card);
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow-medium);
            backdrop-filter: blur(10px);
        }
        
        .search-input-group {
            position: relative;
            flex: 1;
            min-width: 280px;
            max-width: 450px;
            transition: transform 0.2s ease;
        }
        
        .search-input-group:hover {
            transform: translateY(-2px);
        }
        
        .search-input-group i {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 16px;
            transition: color 0.3s ease;
        }
        
        .search-input-group input {
            width: 100%;
            padding: 14px 18px;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            background: rgba(25, 25, 25, 0.7);
            color: var(--text-primary);
            font-size: 16px;
            transition: all 0.3s ease;
            box-sizing: border-box;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }
        
        .search-input-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.2);
            background: rgba(30, 30, 30, 0.9);
        }
        
        .search-input-group input:focus + i {
            color: var(--primary-color);
        }
        
        .search-input-group input::placeholder {
            color: rgba(255, 255, 255, 0.5);
            font-weight: 300;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 150px;
        }
        
        .filter-group label {
            color: var(--text-primary);
            font-size: 14px;
            font-weight: 500;
            margin-left: 5px;
        }
        
        .filter-group select,
        .filter-group input[type="date"] {
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            background: rgba(25, 25, 25, 0.7);
            color: var(--text-primary);
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }
        
        .filter-group select:focus,
        .filter-group input[type="date"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.2);
        }
        
        .filter-group select:hover,
        .filter-group input[type="date"]:hover {
            transform: translateY(-2px);
        }
        
        .filter-group select option {
            background: #222;
            color: #fff;
        }
        
        .date-picker-group {
            min-width: 140px;
        }
        
        .clear-filters-btn {
            padding: 8px 16px;
            background: #dc3545;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
            height: fit-content;
            align-self: flex-end;
        }
        
        .clear-filters-btn:hover {
            background: #c82333;
            transform: translateY(-1px);
        }
        
        .clear-filters-btn i {
            font-size: 12px;
        }
        
        /* Responsive Design for Search/Filter */
        @media (max-width: 1024px) {
            .search-filter-container {
                flex-direction: column;
                align-items: stretch;
                gap: 20px;
            }
            
            .search-input-group {
                max-width: 100%;
            }
            
            .filter-row {
                display: flex;
                flex-wrap: wrap;
                gap: 15px;
                align-items: flex-end;
                justify-content: space-between;
            }
        }
        
        @media (max-width: 768px) {
            .search-filter-container {
                padding: 15px;
            }
            
            .filter-group {
                min-width: 100px;
            }
            
            .clear-filters-btn {
                width: 100%;
                justify-content: center;
                margin-top: 10px;
            }
        }
        
        @media (max-width: 480px) {
            .search-filter-container {
                padding: 10px;
                gap: 10px;
            }
            
            .filter-group {
                width: 100%;
                min-width: auto;
            }
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            overflow-y: auto;
            padding: 20px;
            box-sizing: border-box;
        }
        .modal-content {
            background: #222;
            color: #fff;
            padding: 20px;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            box-sizing: border-box;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        /* Enhanced Update Modal */
        .update-modal-content {
            max-width: 900px;
            width: 95%;
            max-height: 95vh;
            overflow-y: auto;
        }
        
        .date-time-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .update-top-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-input-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }
        .form-input-group select {
            width: 100%;
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #ccc;
            background: #fff;
            color: #333;
            box-sizing: border-box;
        }
        

        
        /* Calendar Styles */
        .calendar-container {
            height: 400px;
            width: 100%;
            background: #fff;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
            color: #333;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-sizing: border-box;
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .calendar-header h4 {
            margin: 0;
            color: #333;
            font-size: 16px;
        }
        
        .nav-btn {
            background: #e50914;
            border: none;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .nav-btn:hover {
            background: #f40612;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
            flex-grow: 1;
        }
        
        .calendar-day {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            min-height: 35px;
            background: transparent;
            color: #333;
            transition: all 0.2s;
            box-sizing: border-box;
        }
        
        .calendar-day:hover:not(.disabled) {
            background: #f0f0f0;
        }
        
        .calendar-day.selected {
            background: #e50914;
            color: white;
        }
        
        .calendar-day.disabled {
            color: #ccc;
            cursor: not-allowed;
        }
        
        .calendar-day.today {
            background: #e50914;
            color: white;
            font-weight: bold;
        }
        
        .calendar-day.studio-closed {
            background: #6c757d !important;
            color: white !important;
            opacity: 0.7;
            position: relative;
        }
        
        .calendar-day.studio-closed::before {
            content: '🔒';
            position: absolute;
            top: 2px;
            right: 2px;
            font-size: 8px;
        }
        
        .calendar-day.has-bookings::after {
            content: '';
            position: absolute;
            bottom: 4px;
            left: 50%;
            transform: translateX(-50%);
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: #e50914;
        }
        
        .weekday-header {
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #666;
            padding: 8px;
            font-size: 12px;
        }
        
        /* Time Slots Styles */
        .time-section {
            background: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            color: #333;
        }
        
        .time-inputs-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .time-input-group {
            flex: 1;
        }
        
        .time-input-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        
        .time-input-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #f8f8f8;
            color: #333;
            font-size: 14px;
            cursor: pointer;
        }
        
        .time-input-group input:focus {
            border-color: #e50914;
            outline: none;
        }
        
        .time-slots-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 8px;
            max-height: 300px;
            overflow-y: auto;
            margin-top: 15px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #f8f8f8;
        }
        
        .time-slot {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            cursor: pointer;
            text-align: center;
            font-size: 13px;
            transition: all 0.2s;
            background: #fff;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
        }
        
        .time-slot:hover:not(.disabled):not(.booked) {
            background: #f0f0f0;
            border-color: #e50914;
        }
        
        .time-slot.selected-start {
            background: linear-gradient(135deg, #e50914, #b8070f);
            color: white;
            border: 2px solid #e50914;
            transform: scale(1.05);
        }

        .time-slot.selected-end {
            background: linear-gradient(135deg, #28a745, #1e7e34);
            color: white;
            border: 2px solid #28a745;
            transform: scale(1.05);
        }

        .time-slot.available-end {
            background: rgba(40, 167, 69, 0.1);
            border: 1px solid #28a745;
            color: #28a745;
        }

        .time-slot.available-end:hover {
            background: rgba(40, 167, 69, 0.2);
        }

        .time-slot.disabled {
            background: #f8f9fa;
            color: #6c757d;
            cursor: not-allowed;
            opacity: 0.6;
        }

        .time-slot.disabled:hover {
            background: #f8f9fa;
            transform: none;
        }
        
        .time-slot.past-time {
            background: #ffeaa7 !important;
            color: #636e72 !important;
            border-color: #fdcb6e !important;
        }
        
        .time-slot.studio-closed-slot {
            background: #fab1a0 !important;
            color: #2d3436 !important;
            border-color: #e17055 !important;
        }
        
        .time-slot.outside-hours {
            background: #fd79a8 !important;
            color: #2d3436 !important;
            border-color: #e84393 !important;
        }
        
        .time-slot.booked {
            background: #a29bfe !important;
            color: #2d3436 !important;
            border-color: #6c5ce7 !important;
        }

        #availabilityMessage {
            margin-top: 15px;
            padding: 10px;
            border-radius: 5px;
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            text-align: center;
            font-weight: 500;
        }

        .loading-message, .no-slots-message, .error-message {
            grid-column: 1 / -1;
            text-align: center;
            padding: 20px;
            color: #666;
            font-style: italic;
        }
        
        .error-message {
            color: #dc3545;
        }
        
        .no-slots-message {
            color: #ffc107;
        }
        .modal-content h3 {
            margin: 0 0 15px;
            color: #e50914;
            font-size: 20px;
        }
        .modal-content p {
            margin: 5px 0;
            color: #ccc;
            font-size: 14px;
        }
        .modal-content form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        /* Form Elements Responsive Design */
        .update-modal-content form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .update-modal-content label {
            font-weight: bold;
            margin-bottom: 5px;
            display: block;
        }
        
        .update-modal-content select,
        .update-modal-content input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            box-sizing: border-box;
            background: #fff;
            color: #333;
        }
        .modal-content label {
            color: #ccc;
            font-size: 14px;
        }
        .modal-content input, .modal-content select {
            padding: 8px;
            font-size: 14px;
            border: 1px solid #444;
            border-radius: 4px;
            background: #333;
            color: #fff;
        }
        .modal-content .error {
            color: #dc3545;
            font-size: 12px;
            margin-top: 5px;
            display: none;
        }
        .modal-content .buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        .modal-content button {
            padding: 8px 16px;
            font-size: 14px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            color: #fff;
        }
        .modal-content .cancel-button {
            background: #dc3545;
        }
        .modal-content .cancel-button:hover {
            background: #c82333;
        }
        .modal-content .update-button {
            background: #007bff;
        }
        .modal-content .update-button:hover {
            background: #0056b3;
        }
        .modal-content .finish-button {
            background: #28a745;
        }
        .modal-content .finish-button:hover {
            background: #218838;
        }
        .modal-content .close-button {
            background: #333;
        }
        .modal-content .close-button:hover {
            background-color: #444;
        }
        .confirm-button {
            background-color: #e50914;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin-right: 10px;
        }
        .confirm-button:hover {
            background-color: #b8070f;
        }
        
        /* Enhanced Modal Styles */
        .success-icon, .error-icon, .warning-icon {
            font-size: 48px;
            text-align: center;
            margin-bottom: 15px;
        }
        
        .success-icon {
            color: #28a745;
        }
        
        .error-icon {
            color: #dc3545;
        }
        
        .warning-icon {
            color: #ffc107;
        }
        
        /* Loading spinner */
        .loading-spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #e50914;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 10px auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .modal-loading {
            text-align: center;
            padding: 20px;
        }
        
        @media (max-width: 768px) {
            .date-time-section {
                grid-template-columns: 1fr;
            }
            .update-top-section {
                grid-template-columns: 1fr;
            }
            
            .bookings-section {
                padding: 20px 0;
                min-height: 50vh;
            }
            .bookings-section h2.section-title {
                font-size: 24px;
                margin-bottom: 20px;
            }
            .bookings-container {
                padding: 0 10px;
            }
            .bookings-table {
                font-size: 12px;
            }
            .bookings-table th, .bookings-table td {
                padding: 8px;
            }
            .bookings-table th {
                position: sticky;
                top: 0;
                z-index: 10;
            }
            .action-buttons button {
                padding: 4px 8px;
                font-size: 10px;
                margin-right: 3px;
            }
            .modal {
                padding: 10px;
            }
            .modal-content {
                padding: 15px;
                width: 95%;
            }
            .modal-content h3 {
                font-size: 18px;
            }
            .modal-content p {
                font-size: 12px;
            }
            .modal-content form {
                gap: 8px;
            }
            .modal-content input, .modal-content select {
                font-size: 12px;
                padding: 6px;
            }
            .modal-content .buttons {
                flex-direction: column;
                gap: 8px;
            }
            .modal-content button {
                padding: 6px 12px;
                font-size: 12px;
                width: 100%;
            }
            
            .update-modal-content {
                width: 98%;
                max-height: 98vh;
                padding: 15px;
            }
            
            .calendar-container {
                height: 400px;
            }
        }
        
        @media (max-width: 480px) {
            .modal {
                padding: 5px;
            }
            
            .update-modal-content {
                width: 100%;
                max-height: 100vh;
                padding: 10px;
                border-radius: 0;
            }
            
            .calendar-container {
                height: 350px;
                padding: 15px;
            }
            
            .date-time-section {
                gap: 15px;
            }
            
            .calendar-day {
                padding: 6px;
                font-size: 12px;
                min-height: 30px;
            }
            
            .weekday-header {
                padding: 6px;
                font-size: 11px;
            }
            
            .time-inputs-container {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .buttons {
                flex-direction: column;
                gap: 10px;
            }
            
            .buttons button {
                width: 100%;
            }
        }

        /* Pagination styles */
        .table-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-top: 12px;
            padding: 12px 14px;
            background: linear-gradient(180deg, rgba(30,30,30,0.6), rgba(24,24,24,0.6));
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 8px;
            backdrop-filter: blur(4px);
        }
        .rows-per-page {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #ddd;
            font-size: 14px;
        }
        .rows-per-page label {
            color: #bbb;
        }
        #rowsPerPage {
            appearance: none;
            background-color: #141414;
            color: #fff;
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 6px;
            padding: 6px 28px 6px 10px;
            font-size: 14px;
            outline: none;
            position: relative;
        }
        #rowsPerPage:hover { border-color: rgba(255,255,255,0.24); }
        #rowsPerPage:focus { box-shadow: 0 0 0 3px rgba(229, 9, 20, 0.25); }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .paginate-button {
            background: #e50914;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 8px 14px;
            font-weight: 600;
            letter-spacing: 0.2px;
            cursor: pointer;
            transition: background 120ms ease, transform 120ms ease, box-shadow 120ms ease;
        }
        .paginate-button:hover { background: #f6121d; transform: translateY(-1px); }
        .paginate-button:active { transform: translateY(0); }
        .paginate-button:focus { outline: none; box-shadow: 0 0 0 3px rgba(229,9,20,0.35); }
        .paginate-button:disabled { background: #444; color: #bbb; cursor: not-allowed; box-shadow: none; }

        #paginationInfo {
            color: #aaa;
            font-size: 13px;
        }

        @media (max-width: 768px) {
            .table-pagination { flex-direction: column; align-items: stretch; }
            .pagination-controls { justify-content: space-between; }
        }
    </style>
    <!--[if lt IE 9]>
    <script src="../../shared/assets/js/ie-support/html5.js"></script>
    <script src="../../shared/assets/js/ie-support/respond.js"></script>
    <![endif]-->
</head>
<body class="header-collapse">
    <div id="site-content">
        <?php include '../../shared/components/navbar.php'; ?>
        <main class="main-content">
            <div class="fullwidth-block bookings-section">
                <h2 class="section-title">My Bookings</h2>
                
                <!-- Search and Filter Bar -->
                <div class="search-filter-container">
                    <div class="search-input-group">
                        <input type="text" id="searchInput" placeholder="Search by Studio, Service, or Booking ID..." />
                        <i class="fas fa-search"></i>
                    </div>
                    
                    <div class="filter-group">
                        <label for="dateFilter">Date:</label>
                        <select id="dateFilter">
                            <option value="all">All Dates</option>
                            <option value="today">Today</option>
                            <option value="week">This Week</option>
                            <option value="month">This Month</option>
                            <option value="past">Past</option>
                            <option value="upcoming">Upcoming</option>
                            <option value="custom">Custom Date</option>
                        </select>
                    </div>
                    
                    <div class="filter-group date-picker-group" id="datePickerGroup" style="display: none;">
                        <label for="customDate">Select Date:</label>
                        <input type="date" id="customDate" />
                    </div>
                    
                    <div class="filter-group">
                        <label for="statusFilter">Status:</label>
                        <select id="statusFilter">
                            <option value="all">All Status</option>
                            <option value="pending">Pending</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="groupFilter">Group:</label>
                        <select id="groupFilter">
                            <option value="all">All Bookings</option>
                            <option value="group">Group</option>
                            <option value="individual">Individual</option>
                        </select>
                    </div>
                    
                    <button id="clearFilters" class="clear-filters-btn">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
                
                <div class="bookings-container">
                    <?php if (empty($bookings)): ?>
                        <div class="no-bookings">
                            <p>You have no bookings. <a href="browse.php" style="color: #e50914;">Browse studios</a> to book now!</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrapper" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                        <table class="bookings-table">
                            <thead>
                                <tr>
                                    <th>Booking ID</th>
                                    <th>Studio</th>
                                    <th>Service</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Status</th>
                                    <th>Group</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bookings as $booking): ?>
                                    <tr class="booking-row" 
                                        data-date="<?php echo $booking['Sched_Date']; ?>" 
                                        data-status="<?php echo strtolower($booking['status']); ?>" 
                                        data-group="<?php echo !empty($booking['PaymentGroupID']) ? 'group' : 'individual'; ?>"
                                        onclick="showBookingModal(<?php echo htmlspecialchars(json_encode($booking)); ?>)" 
                                        style="cursor: pointer;">
                                        <td><?php echo htmlspecialchars($booking['BookingID']); ?></td>
                                        <td><?php echo htmlspecialchars($booking['StudioName']); ?></td>
                                        <td><?php echo htmlspecialchars($booking['ServiceType']); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($booking['Sched_Date'])); ?></td>
                                        <td><?php echo date('h:i A', strtotime($booking['Time_Start'])); ?></td>
                                        <td><span class="status <?php echo strtolower(htmlspecialchars($booking['status'])); ?>"><?php echo htmlspecialchars($booking['status']); ?></span></td>
                                        <td>
                                            <?php if (!empty($booking['PaymentGroupID'])): ?>
                                                <span class="group-indicator" title="Part of group booking">🔗</span>
                                            <?php else: ?>
                                                <span class="single-booking" title="Individual booking">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="action-buttons">
                                            <?php if ($booking['status'] === 'Pending'): ?>
                                                <button class="cancel-button" onclick="event.stopPropagation(); showCancellationReasonModal(<?php echo $booking['BookingID']; ?>);">Cancel</button>
                                                <button class="update-button" onclick="event.stopPropagation(); showUpdateModal(<?php echo $booking['BookingID']; ?>, <?php echo htmlspecialchars(json_encode($booking)); ?>);">Update</button>
                                            <?php elseif (!empty($booking['Book_StatsID']) && (int)$booking['Book_StatsID'] === 1): ?>
                                                <?php if (!empty($booking['Pay_Stats']) && (strtolower($booking['Pay_Stats']) === 'confirmed' || strtolower($booking['Pay_Stats']) === 'completed')): ?>
                                                    <button class="finish-button" onclick="event.stopPropagation(); finishBooking(<?php echo $booking['BookingID']; ?>);">Finish Booking</button>
                                                <?php elseif (!empty($booking['Pay_Stats']) && strtolower($booking['Pay_Stats']) === 'pending'): ?>
                                                    <a class="finish-button" href="view_status.php?booking_id=<?php echo $booking['BookingID']; ?>&studio_id=<?php echo $booking['StudioID']; ?>&owner_id=<?php echo $booking['OwnerID']; ?>" onclick="event.stopPropagation();">View Location</a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <div class="table-pagination">
                            <div class="rows-per-page">
                                <label for="rowsPerPage">Rows per page:</label>
                                <select id="rowsPerPage">
                                    <option value="5">5</option>
                                    <option value="10" selected>10</option>
                                    <option value="20">20</option>
                                    <option value="50">50</option>
                                </select>
                            </div>
                            <div class="pagination-controls">
                                <button id="prevPage" class="paginate-button">Prev</button>
                                <span id="paginationInfo"></span>
                                <button id="nextPage" class="paginate-button">Next</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
        <!-- Booking Modal -->
        <div id="bookingModal" class="modal">
            <div class="modal-content">
                <h3 id="modalBookingId"></h3>
                <p><strong>Studio:</strong> <span id="modalStudio"></span></p>
                <p><strong>Service:</strong> <span id="modalService"></span></p>
                <p><strong>Price:</strong> <span id="modalPrice"></span></p>
                <p><strong>Date:</strong> <span id="modalDate"></span></p>
                <p><strong>Time:</strong> <span id="modalTime"></span></p>
                <p><strong>Status:</strong> <span id="modalStatus"></span></p>
                <div class="buttons" id="modalButtons">
                    <!-- Buttons will be dynamically added here -->
                </div>
            </div>
        </div>

        <!-- Update Modal -->
        <div id="updateModal" class="modal">
            <div class="modal-content update-modal-content">
                <h3>Update Booking</h3>
                <form id="updateForm" action="update_booking.php" method="POST">
                    <input type="hidden" id="booking_id" name="booking_id" value="">
                    
                    <!-- Selection Section: Service, Instructor, Studio Hours -->
                    <div class="update-top-section">
                        <div class="form-input-group">
                            <label for="service_id">Service:</label>
                            <select id="service_id" name="service_id" required>
                                <option value="">Select a service</option>
                                <!-- Services will be populated based on studio -->
                            </select>
                            <div class="error" id="serviceError">Invalid service.</div>
                        </div>
                        <div class="form-input-group">
                            <label for="instructor_id">Instructor:</label>
                            <select id="instructor_id" name="instructor_id" required>
                                <option value="">Select an instructor</option>
                                <!-- Instructors will be populated based on studio and service -->
                            </select>
                            <div class="error" id="instructorError">Please select an instructor.</div>
                        </div>
                    </div>

                    <div class="studio-info" id="studioInfo" style="display: none;">
                        <p><strong>Studio Hours:</strong> <span id="studioHours"></span></p>
                        <p><em>Please select times within studio operating hours.</em></p>
                    </div>

                    <!-- Date Selection Section -->
                    <div class="date-time-section">
                        <div class="calendar-section">
                            <label>Select New Date:</label>
                            <div class="calendar-container">
                                <div class="calendar-header">
                                    <button type="button" id="prevMonth" class="nav-btn">
                                        <i class="fas fa-chevron-left"></i>
                                    </button>
                                    <h4 id="currentMonth"></h4>
                                    <button type="button" id="nextMonth" class="nav-btn">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                </div>
                                <div class="calendar-grid" id="calendarGrid">
                                    <!-- Calendar will be generated here -->
                                </div>
                            </div>
                            <input type="hidden" id="new_date" name="new_date" required>
                            <div class="error" id="dateError">Please select a valid date.</div>
                        </div>
                        
                        <!-- Time Selection Section -->
                        <div class="time-section">
                            <label>Select Time:</label>
                            <div class="time-inputs-container">
                                <div class="time-input-group">
                                    <label for="timeStart">Start Time:</label>
                                    <input type="text" id="timeStart" name="timeStart" readonly required>
                                </div>
                                <div class="time-input-group">
                                    <label for="timeEnd">End Time:</label>
                                    <input type="text" id="timeEnd" name="timeEnd" readonly required>
                                </div>
                            </div>
                            <div id="availabilityMessage" style="margin-top: 10px; color: #333;"></div>
                            <div id="timeSlotsContainer" class="time-slots-container" style="display: none;">
                                <!-- Time slots will be generated here -->
                            </div>
                            <input type="hidden" id="time_slot" name="time_slot" required>
                            <div class="error" id="timeError">Please select valid start and end times.</div>
                        </div>
                    </div>
                    
                    <div class="buttons">
                        <button type="submit" class="update-button">Update Booking</button>
                        <button type="button" class="close-button" onclick="closeUpdateModal()">Close</button>
                    </div>
                </form>
            </div>
        </div>
        <?php include '../../shared/components/footer.php'; ?>

        <!-- Success Modal -->
        <div id="successModal" class="modal">
            <div class="modal-content">
                <div class="success-icon">✓</div>
                <h3 id="successTitle">Success!</h3>
                <p id="successMessage">Operation completed successfully.</p>
                <div class="buttons">
                    <button type="button" class="close-button" onclick="closeSuccessModal()">OK</button>
                </div>
            </div>
        </div>

        <!-- Error Modal -->
        <div id="errorModal" class="modal">
            <div class="modal-content">
                <div class="error-icon">✕</div>
                <h3 id="errorTitle">Error</h3>
                <p id="errorMessage">An error occurred. Please try again.</p>
                <div class="buttons">
                    <button type="button" class="close-button" onclick="closeErrorModal()">OK</button>
                </div>
            </div>
        </div>

        <!-- Confirmation Modal -->
        <div id="confirmModal" class="modal">
            <div class="modal-content">
                <div class="warning-icon">⚠</div>
                <h3 id="confirmTitle">Confirm Action</h3>
                <p id="confirmMessage">Are you sure you want to proceed?</p>
                <div class="buttons">
                    <button type="button" class="confirm-button" id="confirmYes">Yes</button>
                    <button type="button" class="close-button" onclick="closeConfirmModal()">Cancel</button>
                </div>
            </div>
        </div>

    <!-- Success Modal -->
    <div id="successModal" class="modal">
        <div class="modal-content">
            <div class="success-icon">✓</div>
            <h3 id="successTitle">Success</h3>
            <p id="successMessage">Operation completed successfully.</p>
            <div class="buttons">
                <button class="finish-button" onclick="closeSuccessModal()">OK</button>
            </div>
        </div>
    </div>

    <!-- Error Modal -->
    <div id="errorModal" class="modal">
        <div class="modal-content">
            <div class="error-icon">✗</div>
            <h3 id="errorTitle">Error</h3>
            <p id="errorMessage">An error occurred.</p>
            <div class="buttons">
                <button class="cancel-button" onclick="closeErrorModal()">OK</button>
            </div>
        </div>
    </div>

    <!-- Cancellation Reason Modal -->
    <div id="cancellationReasonModal" class="modal">
        <div class="modal-content">
            <div class="warning-icon">⚠</div>
            <h3>Cancel Booking</h3>
            <p>Please select a reason for cancelling this booking:</p>
            
            <div class="cancellation-reasons">
                <div class="reason-option">
                    <input type="radio" id="reason-schedule" name="cancellation_reason" value="Schedule conflict">
                    <label for="reason-schedule">Schedule conflict</label>
                </div>
                <div class="reason-option">
                    <input type="radio" id="reason-emergency" name="cancellation_reason" value="Emergency">
                    <label for="reason-emergency">Emergency</label>
                </div>
                <div class="reason-option">
                    <input type="radio" id="reason-personal" name="cancellation_reason" value="Personal reasons">
                    <label for="reason-personal">Personal reasons</label>
                </div>
                <div class="reason-option">
                    <input type="radio" id="reason-financial" name="cancellation_reason" value="Financial constraints">
                    <label for="reason-financial">Financial constraints</label>
                </div>
                <div class="reason-option">
                    <input type="radio" id="reason-other" name="cancellation_reason" value="Other">
                    <label for="reason-other">Other (please specify)</label>
                </div>
                
                <div id="otherReasonContainer" class="other-reason-container" style="display: none;">
                    <textarea id="otherReasonText" placeholder="Please specify your reason..." maxlength="500"></textarea>
                    <div class="char-count">
                        <span id="charCount">0</span>/500 characters
                    </div>
                </div>
            </div>
            
            <div class="refund-warning" style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e50914;border-radius:6px;background:rgba(229, 9, 20, 0.12);color:#e50914;margin-top:10px;">
                <i class="fas fa-info-circle"></i>
                Initial payments are non-refundable for client-initiated cancellations.
            </div>
            <div class="buttons">
                <button type="button" class="cancel-button" id="confirmCancellation">Cancel Booking</button>
                <button type="button" class="close-button" onclick="closeCancellationReasonModal()">Back</button>
            </div>
        </div>
    </div>



    <script>
        let currentBookingId = null;

        function showBookingModal(booking) {
            // Check if all required modal elements exist
            const modalElements = {
                modalBookingId: document.getElementById('modalBookingId'),
                modalStudio: document.getElementById('modalStudio'),
                modalService: document.getElementById('modalService'),
                modalPrice: document.getElementById('modalPrice'),
                modalDate: document.getElementById('modalDate'),
                modalTime: document.getElementById('modalTime'),
                modalStatus: document.getElementById('modalStatus'),
                modalButtons: document.getElementById('modalButtons'),
                bookingModal: document.getElementById('bookingModal')
            };
            
            // Check if any elements are missing
            const missingElements = Object.keys(modalElements).filter(key => !modalElements[key]);
            if (missingElements.length > 0) {
                console.error('Missing modal elements:', missingElements);
                return;
            }
            
            modalElements.modalBookingId.textContent = 'Booking #' + booking.BookingID;
            modalElements.modalStudio.textContent = booking.StudioName;
            modalElements.modalService.textContent = booking.ServiceType;
            modalElements.modalPrice.textContent = '₱' + parseFloat(booking.Price).toFixed(2);
            modalElements.modalDate.textContent = new Date(booking.Sched_Date).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            modalElements.modalTime.textContent = formatTime(booking.Time_Start) + ' - ' + formatTime(booking.Time_End);
            modalElements.modalStatus.textContent = booking.status;
            modalElements.modalStatus.className = 'status ' + booking.status.toLowerCase();

            // Clear previous buttons
            modalElements.modalButtons.innerHTML = '';

            // Add appropriate buttons based on status
            if (booking.status === 'Pending') {
                modalElements.modalButtons.innerHTML = `
                    <button class="cancel-button" onclick="showCancellationReasonModal(${booking.BookingID});">Cancel</button>
                    <button class="update-button" onclick="showUpdateModal(${booking.BookingID}, ${JSON.stringify(booking).replace(/"/g, '&quot;')});">Update</button>
                `;
            } else if (booking.Book_StatsID && Number(booking.Book_StatsID) === 1) {
                if (booking.Pay_Stats && (booking.Pay_Stats === 'Confirmed' || booking.Pay_Stats === 'Completed')) {
                    modalElements.modalButtons.innerHTML = `
                        <button class="finish-button" onclick="showConfirmModal('Finish Booking', 'Mark this booking as finished?', function() { finishBooking(${booking.BookingID}); });">Finish Booking</button>
                    `;
                } else if (booking.Pay_Stats && booking.Pay_Stats === 'Pending') {
                    modalElements.modalButtons.innerHTML = `
                        <button class="finish-button" onclick="goToViewStatus(${booking.BookingID}, ${booking.StudioID}, ${booking.OwnerID});">View Location</button>
                        <button class="update-button" onclick="goToStudioMap(${booking.StudioID});">Show Map</button>
                    `;
                }
            }
            
            modalElements.modalButtons.innerHTML += '<button class="close-button" onclick="closeModal()">Close</button>';
            
            modalElements.bookingModal.style.display = 'flex';
        }

        function goToStudioMap(studioId) {
            window.location.href = 'browse.php#map';
        }

        function goToViewStatus(bookingId, studioId, ownerId) {
            window.location.href = `view_status.php?booking_id=${bookingId}&studio_id=${studioId}&owner_id=${ownerId}`;
        }

        // Global variable to store current studio ID
        let currentStudioId = null;

        function showUpdateModal(bookingId, booking) {
            currentBookingId = bookingId;
            
            const updateForm = document.getElementById('updateForm');
            if (!updateForm) {
                console.error('Update form not found');
                return;
            }
            updateForm.dataset.bookingId = bookingId;
            
            // Set the hidden booking_id field
            const bookingIdField = document.getElementById('booking_id');
            if (bookingIdField) {
                bookingIdField.value = bookingId;
            }
            
            // Store current studio ID for validation
            currentStudioId = booking.StudioID || booking.studio_id;
            
            // Populate studio information in modal
            const modalStudio = document.getElementById('modalStudio');
            if (modalStudio) {
                modalStudio.textContent = booking.StudioName;
            }
            
            // Set current values with null checks
            const newDateElement = document.getElementById('new_date');
            const timeSlotElement = document.getElementById('time_slot');
            const serviceIdElement = document.getElementById('service_id');
            
            if (newDateElement) newDateElement.value = booking.Sched_Date;
            if (timeSlotElement) timeSlotElement.value = booking.Time_Start.substring(0, 5) + '-' + booking.Time_End.substring(0, 5);
            if (serviceIdElement) serviceIdElement.value = booking.current_service_id || booking.ServiceID;
            
            // Clear errors with null checks
            const errorElements = ['dateError', 'timeError', 'serviceError', 'instructorError'];
            errorElements.forEach(errorId => {
                const element = document.getElementById(errorId);
                if (element) element.style.display = 'none';
            });
            
            // Load studio-specific services and instructors
            loadStudioData(booking.StudioID || booking.studio_id, booking);
            
            // Initialize calendar
            initializeCalendar();
            
            // Set up navigation buttons with null checks
            const prevMonth = document.getElementById('prevMonth');
            const nextMonth = document.getElementById('nextMonth');
            if (prevMonth) prevMonth.onclick = () => navigateMonth(-1);
            if (nextMonth) nextMonth.onclick = () => navigateMonth(1);
            
            closeModal();
            const updateModal = document.getElementById('updateModal');
            if (updateModal) {
                updateModal.style.display = 'flex';
            }
        }

        function loadStudioData(studioId, booking) {
            // Show loading state
            const serviceSelect = document.getElementById('service_id');
            const instructorSelect = document.getElementById('instructor_id');
            const studioInfo = document.getElementById('studioInfo');
            
            serviceSelect.innerHTML = '<option value="">Loading services...</option>';
            instructorSelect.innerHTML = '<option value="">Loading instructors...</option>';
            studioInfo.style.display = 'none';
            
            fetch('get_studio_services.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ studio_id: studioId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Populate services
                    serviceSelect.innerHTML = '<option value="">Select a service</option>';
                    data.services.forEach(service => {
                        const option = document.createElement('option');
                        option.value = service.ServiceID;
                        option.textContent = service.ServiceType;
                        if (service.ServiceID == booking.current_service_id || service.ServiceID == booking.ServiceID) {
                            option.selected = true;
                        }
                        serviceSelect.appendChild(option);
                    });
                    
                    // Populate instructors
                    instructorSelect.innerHTML = '<option value="">Select an instructor</option>';
                    data.instructors.forEach(instructor => {
                        const option = document.createElement('option');
                        option.value = instructor.InstructorID;
                        option.textContent = `${instructor.Name} (${instructor.Profession})`;
                        if (instructor.InstructorID == booking.InstructorID) {
                            option.selected = true;
                        }
                        instructorSelect.appendChild(option);
                    });
                    
                    // Store all instructors data for service-based filtering
                    window.allInstructors = data.instructors;
                    
                    // Add service change event listener for instructor filtering
                    serviceSelect.addEventListener('change', function() {
                        filterInstructorsByService(this.value, booking.InstructorID);
                    });
                    
                    // Show studio hours information
                    const studioHours = document.getElementById('studioHours');
                    const timeIn = formatTime(data.studio.Time_IN);
                    const timeOut = formatTime(data.studio.Time_OUT);
                    studioHours.textContent = `${timeIn} - ${timeOut}`;
                    studioInfo.style.display = 'block';
                    
                    // Store studio data globally for calendar validation
                    currentStudioData = data.studio;
                    
                    // Re-render calendar with studio data for proper validation
                    renderCalendar();
                    
                    // Update time range validation with studio closing time
                    updateTimeRangeOptions(data.studio.Time_OUT);
                    
                } else {
                    serviceSelect.innerHTML = '<option value="">Error loading services</option>';
                    instructorSelect.innerHTML = '<option value="">Error loading instructors</option>';
                    console.error('Error loading studio data:', data.error);
                }
            })
            .catch(error => {
                serviceSelect.innerHTML = '<option value="">Error loading services</option>';
                instructorSelect.innerHTML = '<option value="">Error loading instructors</option>';
                console.error('Network error loading studio data:', error);
            });
        }

        function updateTimeRangeOptions(studioTimeOut) {
            const endTimeSelect = document.getElementById('end_time');
            
            // Check if end_time element exists before proceeding
            if (!endTimeSelect) {
                console.warn('end_time element not found, skipping time range update');
                return;
            }
            
            const studioClosingHour = parseInt(studioTimeOut.split(':')[0]);
            
            // Clear existing options
            endTimeSelect.innerHTML = '<option value="">Select end time</option>';
            
            // Add time options up to studio closing time
            for (let hour = 10; hour <= studioClosingHour; hour++) {
                const timeValue = hour.toString().padStart(2, '0') + ':00';
                const displayTime = hour <= 12 ? 
                    (hour === 12 ? '12:00 PM' : `${hour}:00 AM`) :
                    `${hour - 12}:00 PM`;
                
                const option = document.createElement('option');
                option.value = timeValue;
                option.textContent = displayTime;
                endTimeSelect.appendChild(option);
            }
        }

        function closeModal() {
            const bookingModal = document.getElementById('bookingModal');
            if (bookingModal) {
                bookingModal.style.display = 'none';
            }
        }

        function closeUpdateModal() {
            const updateModal = document.getElementById('updateModal');
            if (updateModal) {
                updateModal.style.display = 'none';
            }
        }

        function filterInstructorsByService(serviceId, currentInstructorId = null) {
            const instructorSelect = document.getElementById('instructor_id');
            
            if (!instructorSelect || !window.allInstructors) {
                return;
            }
            
            // Clear current options
            instructorSelect.innerHTML = '<option value="">Select an instructor</option>';
            
            if (!serviceId) {
                // If no service selected, show all instructors
                window.allInstructors.forEach(instructor => {
                    const option = document.createElement('option');
                    option.value = instructor.InstructorID;
                    option.textContent = `${instructor.Name} (${instructor.Profession})`;
                    if (instructor.InstructorID == currentInstructorId) {
                        option.selected = true;
                    }
                    instructorSelect.appendChild(option);
                });
                return;
            }
            
            // Filter instructors by service
            const filteredInstructors = window.allInstructors.filter(instructor => {
                return instructor.services && instructor.services.includes(parseInt(serviceId));
            });
            
            if (filteredInstructors.length === 0) {
                instructorSelect.innerHTML = '<option value="">No instructors available for this service</option>';
                return;
            }
            
            // Populate filtered instructors
            filteredInstructors.forEach(instructor => {
                const option = document.createElement('option');
                option.value = instructor.InstructorID;
                option.textContent = `${instructor.Name} (${instructor.Profession})`;
                if (instructor.InstructorID == currentInstructorId) {
                    option.selected = true;
                }
                instructorSelect.appendChild(option);
            });
        }

        function showSuccessModal(title, message, callback) {
            const successTitle = document.getElementById('successTitle');
            const successMessage = document.getElementById('successMessage');
            const successModal = document.getElementById('successModal');
            
            if (successTitle) successTitle.textContent = title;
            if (successMessage) successMessage.textContent = message;
            if (successModal) {
                successModal.style.display = 'flex';
                
                if (callback) {
                    successModal.dataset.callback = 'true';
                    window.successCallback = callback;
                }
            }
        }

        function closeSuccessModal() {
            const successModal = document.getElementById('successModal');
            if (successModal) {
                successModal.style.display = 'none';
                if (successModal.dataset.callback === 'true') {
                    successModal.dataset.callback = 'false';
                    if (window.successCallback) {
                        window.successCallback();
                        window.successCallback = null;
                    }
                }
            }
        }

        function showErrorModal(title, message) {
            const errorTitle = document.getElementById('errorTitle');
            const errorMessage = document.getElementById('errorMessage');
            const errorModal = document.getElementById('errorModal');
            
            if (errorTitle) errorTitle.textContent = title;
            if (errorMessage) errorMessage.textContent = message;
            if (errorModal) errorModal.style.display = 'flex';
        }

        function closeErrorModal() {
            const errorModal = document.getElementById('errorModal');
            if (errorModal) {
                errorModal.style.display = 'none';
            }
        }

        function showConfirmModal(title, message, callback) {
            const confirmTitle = document.getElementById('confirmTitle');
            const confirmMessage = document.getElementById('confirmMessage');
            const confirmModal = document.getElementById('confirmModal');
            const confirmYes = document.getElementById('confirmYes');
            
            if (confirmTitle) confirmTitle.textContent = title;
            if (confirmMessage) confirmMessage.textContent = message;
            if (confirmModal) confirmModal.style.display = 'flex';
            
            if (confirmYes) {
                confirmYes.onclick = function() {
                    closeConfirmModal();
                    if (callback) callback();
                };
            }
        }

        function closeConfirmModal() {
            const confirmModal = document.getElementById('confirmModal');
            if (confirmModal) {
                confirmModal.style.display = 'none';
            }
        }

        function showLoadingInModal(modalId, message) {
            const modal = document.getElementById(modalId);
            if (!modal) {
                console.error(`Modal with ID '${modalId}' not found`);
                return;
            }
            
            const modalContent = modal.querySelector('.modal-content');
            if (!modalContent) {
                console.error(`Modal content not found in modal '${modalId}'`);
                return;
            }
            
            modalContent.innerHTML = `
                <div class="modal-loading">
                    <div class="loading-spinner"></div>
                    <p>${message}</p>
                </div>
            `;
        }

        function cancelBooking(bookingId, cancellationReason) {
            showLoadingInModal('cancellationReasonModal', 'Cancelling booking...');
            
            const formData = new FormData();
            formData.append('booking_id', bookingId);
            formData.append('cancellation_reason', cancellationReason);
            
            fetch('cancel_booking.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                closeCancellationReasonModal();
                if (data.success) {
                    showSuccessModal(
                        'Booking Cancelled',
                        'Your booking has been successfully cancelled.',
                        function() {
                            window.location.reload();
                        }
                    );
                } else {
                    showErrorModal(
                        'Cancellation Failed',
                        data.error || 'Unable to cancel booking. Please try again.'
                    );
                }
            })
            .catch(error => {
                closeCancellationReasonModal();
                showErrorModal(
                    'Network Error',
                    'Unable to connect to the server. Please check your internet connection and try again.'
                );
            });
        }

        function finishBooking(bookingId) {
            showLoadingInModal('bookingModal', 'Checking payment status...');
            
            fetch('../../payment/php/check_payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'booking_id=' + encodeURIComponent(bookingId)
            })
            .then(response => response.json())
            .then(data => {
                closeModal();
                console.log('Response from check_payment.php:', data);
                if (data.success) {
                    if (data.redirect) {
                        window.location.href = data.redirect;
                    } else {
                        showSuccessModal(
                            'Booking Finished',
                            'Your booking has been marked as finished.',
                            function() {
                                window.location.reload();
                            }
                        );
                    }
                } else {
                    showErrorModal(
                        'Update Failed',
                        data.error || 'Unable to finish booking. Please try again.'
                    );
                }
            })
            .catch(error => {
                closeModal();
                showErrorModal(
                    'Network Error',
                    'Unable to connect to the server. Please check your internet connection and try again.'
                );
            });
        }

        function formatTime(timeString) {
            if (!timeString || typeof timeString !== 'string') return '';
            const parts = timeString.split(':');
            let hour = parseInt(parts[0], 10);
            const minute = parts.length > 1 ? parts[1] : '00';
            const period = hour >= 12 ? 'PM' : 'AM';
            hour = hour % 12;
            if (hour === 0) hour = 12; // 00:xx -> 12 AM
            return `${hour}:${String(minute).padStart(2, '0')} ${period}`;
        }

        // Calendar functionality
        let currentDate = new Date();
        let selectedDate = null;
        let selectedTimeSlot = null;
        let availableSlots = [];
        let currentStudioData = null;

        function initializeCalendar() {
            const today = new Date();
            currentDate = new Date(today.getFullYear(), today.getMonth(), 1);
            renderCalendar();
            
            // Auto-select today's date if current time is before 6 PM (18:00)
            const currentHour = today.getHours();
            if (currentHour < 18) { // Before 6 PM
                selectDate(today.getFullYear(), today.getMonth(), today.getDate());
            }
        }

        function renderCalendar() {
            const calendarGrid = document.getElementById('calendarGrid');
            const monthYearDisplay = document.getElementById('currentMonth');
            
            if (!calendarGrid || !monthYearDisplay) return;

            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();
            
            monthYearDisplay.textContent = new Date(year, month).toLocaleDateString('en-US', { 
                month: 'long', 
                year: 'numeric' 
            });

            // Clear previous calendar
            calendarGrid.innerHTML = '';

            // Add weekday headers
            const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            weekdays.forEach(day => {
                const dayHeader = document.createElement('div');
                dayHeader.className = 'weekday-header';
                dayHeader.textContent = day;
                calendarGrid.appendChild(dayHeader);
            });

            // Get first day of month and number of days
            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const today = new Date();

            // Add empty cells for days before month starts
            for (let i = 0; i < firstDay; i++) {
                const emptyDay = document.createElement('div');
                emptyDay.className = 'calendar-day disabled';
                calendarGrid.appendChild(emptyDay);
            }

            // Add days of the month
            for (let day = 1; day <= daysInMonth; day++) {
                const dayElement = document.createElement('div');
                dayElement.className = 'calendar-day';
                dayElement.textContent = day;
                
                const dayDate = new Date(year, month, day);
                const isToday = dayDate.toDateString() === today.toDateString();
                const isPast = dayDate < today && !isToday;
                let isStudioClosed = false;
                
                // Check if studio is closed for today
                if (isToday && currentStudioData && currentStudioData.Time_OUT) {
                    const currentTime = new Date().toTimeString().slice(0, 8); // HH:MM:SS format
                    if (currentTime >= currentStudioData.Time_OUT) {
                        isStudioClosed = true;
                    }
                }
                
                if (isToday) {
                    dayElement.classList.add('today');
                    if (isStudioClosed) {
                        dayElement.classList.add('studio-closed');
                        dayElement.title = 'Studio is closed for today';
                    }
                }
                
                if (isPast || isStudioClosed) {
                    dayElement.classList.add('disabled');
                } else {
                    dayElement.addEventListener('click', () => selectDate(year, month, day));
                }

                if (selectedDate && 
                    selectedDate.getFullYear() === year && 
                    selectedDate.getMonth() === month && 
                    selectedDate.getDate() === day) {
                    dayElement.classList.add('selected');
                }

                calendarGrid.appendChild(dayElement);
            }
        }

        function selectDate(year, month, day) {
            selectedDate = new Date(year, month, day);
            selectedTimeSlot = null; // Reset time slot selection
            selectedStartTime = null; // Reset start time
            selectedEndTime = null; // Reset end time
            
            // Clear time inputs
            const timeStartInput = document.getElementById('timeStart');
            const timeEndInput = document.getElementById('timeEnd');
            const timeSlotInput = document.getElementById('time_slot');
            
            if (timeStartInput) timeStartInput.value = '';
            if (timeEndInput) timeEndInput.value = '';
            if (timeSlotInput) timeSlotInput.value = '';
            
            // Update hidden input for form submission
            const newDateInput = document.getElementById('new_date');
            if (newDateInput) {
                // Use local date string to avoid timezone issues
                const localDateString = selectedDate.getFullYear() + '-' + 
                    String(selectedDate.getMonth() + 1).padStart(2, '0') + '-' + 
                    String(selectedDate.getDate()).padStart(2, '0');
                newDateInput.value = localDateString;
            }
            
            renderCalendar();
            renderDayView();
            showValidationFeedback();
        }

        function navigateMonth(direction) {
            currentDate.setMonth(currentDate.getMonth() + direction);
            renderCalendar();
        }

        // Time range selection functionality
        let selectedStartTime = null;
        let selectedEndTime = null;

        function initializeTimeRangeSelection() {
            const startTimeSelect = document.getElementById('start_time');
            const endTimeSelect = document.getElementById('end_time');
            
            if (startTimeSelect && endTimeSelect) {
                startTimeSelect.addEventListener('change', updateTimeRange);
                endTimeSelect.addEventListener('change', updateTimeRange);
            } else {
                console.warn('Time range selection elements not found:', {
                    startTimeSelect: !!startTimeSelect,
                    endTimeSelect: !!endTimeSelect
                });
            }
        }

        function updateTimeRange() {
            const startTimeSelect = document.getElementById('start_time');
            const endTimeSelect = document.getElementById('end_time');
            const selectedTimeRange = document.getElementById('selectedTimeRange');
            const timeSlotInput = document.getElementById('time_slot');
            
            // Check if required elements exist
            if (!startTimeSelect || !endTimeSelect) {
                console.warn('Time range elements not found, skipping update');
                return;
            }
            
            if (!startTimeSelect || !endTimeSelect || !selectedTimeRange || !timeSlotInput) return;
            
            const startTime = startTimeSelect.value;
            const endTime = endTimeSelect.value;
            
            if (startTime && endTime) {
                // Validate that end time is after start time
                const startHour = parseInt(startTime.split(':')[0]);
                const endHour = parseInt(endTime.split(':')[0]);
                
                if (endHour <= startHour) {
                    selectedTimeRange.textContent = 'End time must be after start time';
                    selectedTimeRange.style.color = '#dc3545';
                    timeSlotInput.value = '';
                    selectedStartTime = null;
                    selectedEndTime = null;
                } else {
                    // Format display times
                    const startDisplay = formatTimeDisplay(startTime);
                    const endDisplay = formatTimeDisplay(endTime);
                    
                    selectedTimeRange.textContent = `${startDisplay} to ${endDisplay}`;
                    selectedTimeRange.style.color = '#e50914';
                    
                    // Set the hidden input value
                    timeSlotInput.value = `${startTime}:00-${endTime}:00`;
                    selectedStartTime = startTime;
                    selectedEndTime = endTime;
                }
            } else {
                selectedTimeRange.textContent = 'Please select start and end times';
                selectedTimeRange.style.color = '#fff';
                timeSlotInput.value = '';
                selectedStartTime = null;
                selectedEndTime = null;
            }
            
            // Update validation feedback
            showValidationFeedback();
        }

        function formatTimeDisplay(time24) {
            const hour = parseInt(time24.split(':')[0]);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            const hour12 = hour === 0 ? 12 : hour > 12 ? hour - 12 : hour;
            return `${hour12}:00 ${ampm}`;
        }

        function renderDayView() {
            return loadTimeSlots();
        }

        function loadTimeSlots() {
            const timeSlotsContainer = document.getElementById('timeSlotsContainer');
            const timeStartInput = document.getElementById('timeStart');
            const timeEndInput = document.getElementById('timeEnd');
            const availabilityMessage = document.getElementById('availabilityMessage');
            
            if (!timeSlotsContainer || !selectedDate) {
                return Promise.resolve();
            }

            // Clear previous selections
            timeStartInput.value = '';
            timeEndInput.value = '';
            selectedStartTime = null;
            selectedEndTime = null;
            availabilityMessage.textContent = 'Loading available time slots...';
            availabilityMessage.style.color = '#666';
            
            // Show time slots container
            timeSlotsContainer.style.display = 'grid';
            timeSlotsContainer.innerHTML = '<div class="loading-message">Loading time slots...</div>';
            
            const dateString = selectedDate.getFullYear() + '-' + 
                String(selectedDate.getMonth() + 1).padStart(2, '0') + '-' + 
                String(selectedDate.getDate()).padStart(2, '0');
            
            // Get studio name from the current booking being updated
            const studioNameElement = document.getElementById('modalStudio');
            const studioName = studioNameElement ? studioNameElement.textContent.trim() : '';
            
            if (!studioName) {
                availabilityMessage.textContent = 'Studio information not available';
                availabilityMessage.style.color = '#dc3545';
                return Promise.resolve();
            }
            
            // Fetch real-time availability
            return fetch('get_realtime_slots.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    date: dateString,
                    studio_name: studioName,
                    service_id: document.getElementById('service_id').value
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let html = '';
                    
                    if (data.slots.length === 0) {
                        const message = data.today_disabled ? 
                            data.message : 
                            'No available time slots for this date';
                        html = `<div class="no-slots-message">${message}</div>`;
                        availabilityMessage.textContent = data.today_disabled ? 
                            data.message : 
                            (data.is_today ? 'No more slots available today after current time' : 'No available slots for this date');
                        availabilityMessage.style.color = '#dc3545';
                    } else {
                        data.slots.forEach(slot => {
                            const isAvailable = slot.available;
                            let slotClass = isAvailable ? '' : 'disabled';
                            let tooltipText = '';
                            let iconClass = 'fa fa-clock-o';
                            
                            // Add specific styling and tooltips based on unavailability reason
                            if (!isAvailable) {
                                if (slot.reason === 'Past time') {
                                    slotClass += ' past-time';
                                    tooltipText = 'This time has already passed';
                                    iconClass = 'fa fa-history';
                                } else if (slot.reason === 'Studio closed') {
                                    slotClass += ' studio-closed-slot';
                                    tooltipText = 'Studio is closed at this time';
                                    iconClass = 'fa fa-lock';
                                } else if (slot.reason === 'Outside studio hours') {
                                    slotClass += ' outside-hours';
                                    tooltipText = 'Outside studio operating hours';
                                    iconClass = 'fa fa-ban';
                                } else if (slot.reason === 'Booked') {
                                    slotClass += ' booked';
                                    tooltipText = 'This time slot is already booked';
                                    iconClass = 'fa fa-user';
                                } else {
                                    tooltipText = 'This time slot is not available';
                                }
                            }
                            
                            html += `
                                <div class="time-slot ${slotClass}" 
                                     onclick="${isAvailable ? `selectTimeSlot('${slot.time}')` : ''}"
                                     ${tooltipText ? `title="${tooltipText}"` : ''}>
                                    <div class="time-slot-time">
                                        <i class="${iconClass}"></i>
                                        <span>${formatTimeDisplay(slot.time)}</span>
                                    </div>
                                </div>
                            `;
                        });
                        
                        availabilityMessage.textContent = 'Please select a time slot below';
                        availabilityMessage.style.color = '#666';
                    }
                    
                    timeSlotsContainer.innerHTML = html;
                    
                    // Load session slots after time slots are rendered
                    loadSelectedSlots();
                } else {
                    timeSlotsContainer.innerHTML = '<div class="error-message">Error loading time slots</div>';
                    availabilityMessage.textContent = data.message || 'Error loading availability';
                    availabilityMessage.style.color = '#dc3545';
                }
            })
            .catch(error => {
                console.error('Error fetching time slots:', error);
                timeSlotsContainer.innerHTML = '<div class="error-message">Error loading time slots</div>';
                availabilityMessage.textContent = 'Unable to load time slots. Please try again.';
                availabilityMessage.style.color = '#dc3545';
            });
        }

        // Select a time slot (booking2.php style)
        window.selectTimeSlot = function(time) {
            const timeStartInput = document.getElementById('timeStart');
            const timeEndInput = document.getElementById('timeEnd');
            const availabilityMessage = document.getElementById('availabilityMessage');
            const timeSlotInput = document.getElementById('time_slot');
            
            // If no start time selected or both times are selected, set as new start time
            if (!selectedStartTime || (selectedStartTime && selectedEndTime)) {
                selectedStartTime = time;
                selectedEndTime = null;
                timeStartInput.value = formatTimeDisplay(time);
                timeEndInput.value = '';
                availabilityMessage.textContent = 'Now select an end time';
                availabilityMessage.style.color = '#28a745';
                timeSlotInput.value = '';
            } 
            // If start time is selected but no end time, set as end time if valid
            else if (selectedStartTime && !selectedEndTime) {
                const startHour = parseInt(selectedStartTime.split(':')[0]);
                const endHour = parseInt(time.split(':')[0]);
                
                // Validate end time is after start time
                if (endHour <= startHour) {
                    availabilityMessage.style.color = '#dc3545';
                    availabilityMessage.textContent = 'End time must be after start time.';
                    return;
                }
                
                selectedEndTime = time;
                timeEndInput.value = formatTimeDisplay(time);
                timeSlotInput.value = `${selectedStartTime}:00-${selectedEndTime}:00`;
                
                // Set selectedTimeSlot for compatibility with form submission
                selectedTimeSlot = {
                    time_start: selectedStartTime,
                    time_end: selectedEndTime
                };
                
                // Perform dual validation when both start and end times are selected
                performDualValidation(selectedStartTime, selectedEndTime);
            }
            
            // Update time slot visual states
            updateTimeSlotVisuals();
            showValidationFeedback();
        };
        
        // Get current studio ID for validation
         function getCurrentStudioId() {
             return currentStudioId;
         }
         
         // Get selected date string for validation
         function getSelectedDateString() {
             const dateInput = document.getElementById('new_date');
             return dateInput ? dateInput.value : '';
         }
         
         // Perform dual validation (client-side + server-side)
         function performDualValidation(startTime, endTime) {
            const availabilityMessage = document.getElementById('availabilityMessage');
            const studioNameElement = document.getElementById('modalStudio');
            const studioName = studioNameElement ? studioNameElement.textContent.trim() : '';
            
            if (!studioName) {
                availabilityMessage.textContent = 'Studio information not available';
                availabilityMessage.style.color = '#dc3545';
                return;
            }
            
            // Get studio ID from the current booking
            const studioId = getCurrentStudioId(); // We'll need to implement this
            const dateString = getSelectedDateString();
            
            availabilityMessage.textContent = 'Validating booking availability...';
            availabilityMessage.style.color = '#ffc107';
            
            fetch('validate_booking_availability.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    studio_id: studioId,
                    date: dateString,
                    start_time: startTime + ':00',
                    end_time: endTime + ':00'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.valid) {
                        availabilityMessage.textContent = `Selected: ${formatTimeDisplay(startTime)} to ${formatTimeDisplay(endTime)} - Available!`;
                        availabilityMessage.style.color = '#28a745';
                    } else {
                        // Find the first invalid result
                        const invalidResult = data.validation_results.find(result => !result.valid);
                        availabilityMessage.textContent = invalidResult ? invalidResult.message : 'Booking validation failed';
                        availabilityMessage.style.color = '#dc3545';
                        
                        // Clear the selection if validation fails
                        selectedStartTime = null;
                        selectedEndTime = null;
                        document.getElementById('timeStart').value = '';
                        document.getElementById('timeEnd').value = '';
                        document.getElementById('time_slot').value = '';
                    }
                } else {
                    availabilityMessage.textContent = data.error || 'Validation error occurred';
                    availabilityMessage.style.color = '#dc3545';
                }
            })
            .catch(error => {
                console.error('Validation error:', error);
                availabilityMessage.textContent = 'Unable to validate booking. Please try again.';
                availabilityMessage.style.color = '#dc3545';
            });
        }

        function updateTimeSlotVisuals() {
            const timeSlots = document.querySelectorAll('.time-slot');
            timeSlots.forEach(slot => {
                slot.classList.remove('selected-start', 'selected-end', 'available-end');
                
                let slotTime = null;
                
                // Safely extract slot time from onclick attribute
                if (slot.onclick) {
                    try {
                        const onclickStr = slot.onclick.toString();
                        const match = onclickStr.match(/'([^']+)'/);
                        slotTime = match ? match[1] : null;
                    } catch (error) {
                        console.warn('Error extracting slot time from onclick:', error);
                    }
                }
                
                if (!slotTime) return;
                
                if (slotTime === selectedStartTime) {
                    slot.classList.add('selected-start');
                } else if (slotTime === selectedEndTime) {
                    slot.classList.add('selected-end');
                } else if (selectedStartTime && !selectedEndTime) {
                    const startHour = parseInt(selectedStartTime.split(':')[0]);
                    const currentHour = parseInt(slotTime.split(':')[0]);
                    if (currentHour > startHour) {
                        slot.classList.add('available-end');
                    }
                }
            });
        }

        // Real-time availability checking functions
        let selectedSlots = []; // Track selected slots in session
        
        // Check slot availability in real-time
        function checkSlotAvailability(studioId, date, startTime, endTime) {
            // Check against already selected slots in session
            return !selectedSlots.some(slot => 
                slot.studio_id == studioId && 
                slot.date == date && 
                slot.start_time == startTime
            );
        }
        
        // Add slot to session tracking
        function addSlotToSession(studioId, studioName, date, startTime, endTime) {
            return fetch('manage_session_slots.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'add',
                    studio_id: studioId,
                    studio_name: studioName,
                    date: date,
                    start_time: startTime,
                    end_time: endTime
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    selectedSlots = data.selected_slots;
                    updateSessionSlotVisuals();
                }
                return data;
            });
        }
        
        // Remove slot from session tracking
        function removeSlotFromSession(studioId, date, startTime) {
            return fetch('manage_session_slots.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'remove',
                    studio_id: studioId,
                    date: date,
                    start_time: startTime
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    selectedSlots = data.selected_slots;
                    updateSessionSlotVisuals();
                }
                return data;
            });
        }
        
        // Load selected slots from session
        function loadSelectedSlots() {
            return fetch('manage_session_slots.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'get'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    selectedSlots = data.selected_slots;
                    updateSessionSlotVisuals();
                }
                return data;
            });
        }
        
        // Update slot visuals based on session state
        function updateSessionSlotVisuals() {
            const timeSlots = document.querySelectorAll('.time-slot');
            timeSlots.forEach(slot => {
                let slotTime = null;
                
                // Safely extract slot time from onclick attribute
                if (slot.onclick) {
                    try {
                        const onclickStr = slot.onclick.toString();
                        const match = onclickStr.match(/'([^']+)'/);
                        slotTime = match ? match[1] : null;
                    } catch (error) {
                        console.warn('Error extracting slot time from onclick:', error);
                    }
                }
                
                if (!slotTime) return;
                
                // Check if this slot is in session
                const isInSession = selectedSlots.some(sessionSlot => 
                    sessionSlot.date == getSelectedDateString() && 
                    sessionSlot.start_time == slotTime + ':00'
                );
                
                if (isInSession) {
                    slot.classList.add('session-selected');
                    slot.style.backgroundColor = '#ffc107';
                    slot.style.color = '#000';
                    slot.title = 'Already selected in session';
                } else {
                    slot.classList.remove('session-selected');
                    slot.style.backgroundColor = '';
                    slot.style.color = '';
                    slot.title = '';
                }
            });
        }

        function showValidationFeedback() {
            const dateValid = selectedDate !== null;
            const timeValid = selectedStartTime !== null && selectedEndTime !== null;
            const serviceValid = document.getElementById('service_id').value !== '';
            
            // Update visual indicators
            const calendarContainer = document.querySelector('.calendar-container');
            const timeSection = document.querySelector('.time-section');
            const serviceSelect = document.getElementById('service_id');
            
            if (calendarContainer) {
                calendarContainer.style.borderColor = dateValid ? '#28a745' : '#444';
            }
            
            if (timeSection) {
                timeSection.style.borderColor = timeValid ? '#28a745' : '#444';
            }
            
            if (serviceSelect) {
                serviceSelect.style.borderColor = serviceValid ? '#28a745' : '#444';
            }
            
            // Show/hide submit button based on validation
            const submitButton = document.querySelector('#updateForm button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = !(dateValid && timeValid && serviceValid);
                submitButton.style.opacity = (dateValid && timeValid && serviceValid) ? '1' : '0.6';
                
                // Add visual feedback for debugging
                console.log('Validation Status:', {
                    dateValid,
                    timeValid,
                    serviceValid,
                    selectedDate,
                    selectedStartTime,
                    selectedEndTime,
                    serviceValue: document.getElementById('service_id').value
                });
            }
        }

        // Add service selection validation
        document.addEventListener('change', function(e) {
            if (e.target.id === 'service_id') {
                showValidationFeedback();
                // Reload time slots when service changes
                if (selectedDate) {
                    loadTimeSlots().then(() => {
                        // Load session slots after time slots are loaded
                        loadSelectedSlots();
                    });
                }
            }
            
            // Also trigger validation when instructor is selected
            if (e.target.id === 'instructor_id') {
                showValidationFeedback();
            }
        });

        // Initialize calendar when update modal is shown


        // Set minimum date to today
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date();
            const todayString = today.getFullYear() + '-' + 
                String(today.getMonth() + 1).padStart(2, '0') + '-' + 
                String(today.getDate()).padStart(2, '0');
            const newDateInput = document.getElementById('new_date');
            if (newDateInput) {
                newDateInput.setAttribute('min', todayString);
            }
            
            // Initialize time range selection
            initializeTimeRangeSelection();
        });

        document.getElementById('updateForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const selectedDateTime = getSelectedDateTime();
            if (!selectedDateTime) {
                showErrorModal('Validation Error', 'Please select both a date and time slot.');
                return;
            }
            
            const serviceId = document.getElementById('service_id').value;
            if (!serviceId) {
                showErrorModal('Validation Error', 'Please select a service.');
                return;
            }
            
            const instructorId = document.getElementById('instructor_id').value;
            if (!instructorId) {
                showErrorModal('Validation Error', 'Please select an instructor.');
                return;
            }

            // Set the selected date and time slot in hidden fields for form submission
            const newDateField = document.getElementById('new_date');
            const timeSlotField = document.getElementById('time_slot');
            
            if (newDateField) newDateField.value = selectedDateTime.date;
            if (timeSlotField) timeSlotField.value = selectedDateTime.time_slot;

            // Clear previous errors
            document.getElementById('dateError').style.display = 'none';
            document.getElementById('timeError').style.display = 'none';
            document.getElementById('serviceError').style.display = 'none';
            document.getElementById('instructorError').style.display = 'none';

            // Show loading state
            const submitButton = document.querySelector('#updateForm button[type="submit"]');
            const originalText = submitButton.textContent;
            submitButton.disabled = true;
            submitButton.textContent = 'Updating...';

            // Submit form via AJAX for better user experience
            const formData = new FormData(this);
            
            fetch('update_booking.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('Response status:', response.status);
                console.log('Response headers:', response.headers);
                return response.text(); // Get as text first to debug
            })
            .then(responseText => {
                console.log('Raw response:', responseText);
                
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (e) {
                    console.error('JSON parse error:', e);
                    throw new Error('Invalid JSON response from server: ' + responseText);
                }
                
                // Reset button state
                submitButton.disabled = false;
                submitButton.textContent = originalText;
                
                console.log('Parsed data:', data);
                
                if (data.success) {
                    // Show success message
                    showSuccessModal('Booking Updated Successfully', 
                        `Your booking has been updated successfully!\n\n` +
                        `New Date: ${data.new_date}\n` +
                        `New Time: ${data.new_time}\n` +
                        `Service: ${data.new_service}\n` +
                        `Price: $${data.new_price}`
                    );
                    
                    // Close update modal
                    closeUpdateModal();
                    
                    // Refresh the bookings list
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    // Log detailed error info to console but show user-friendly message
                    console.error('Update failed:', data);
                    if (data.debug_info) {
                        console.error('Debug info:', data.debug_info);
                    }
                    
                    // Show user-friendly error message
                    let userMessage = 'Unable to update booking. Please try again.';
                    
                    // Customize message based on error code
                    if (data.error_code === 'UNAUTHORIZED') {
                        userMessage = 'Session expired. Please log in again.';
                    } else if (data.error_code === 'TIME_CONFLICT') {
                        userMessage = 'The selected time slot is no longer available. Please choose a different time.';
                    } else if (data.error_code === 'BOOKING_NOT_FOUND') {
                        userMessage = 'This booking cannot be updated. Only pending bookings can be modified.';
                    } else if (data.error_code === 'INVALID_SERVICE') {
                        userMessage = 'The selected service is not available for this studio.';
                    }
                    
                    showErrorModal('Update Failed', userMessage);
                }
            })
            .catch(error => {
                console.error('Error updating booking:', error);
                
                // Reset button state
                submitButton.disabled = false;
                submitButton.textContent = originalText;
                
                showErrorModal('Network Error', 'Failed to connect to server. Please check your connection and try again.');
            });
        });

        function getSelectedDateTime() {
            if (!selectedDate || !selectedTimeSlot) {
                return null;
            }

            // Use local date string to avoid timezone issues
            const localDateString = selectedDate.getFullYear() + '-' + 
                String(selectedDate.getMonth() + 1).padStart(2, '0') + '-' + 
                String(selectedDate.getDate()).padStart(2, '0');

            return {
                date: localDateString,
                time_slot: selectedTimeSlot.time_start + '-' + selectedTimeSlot.time_end,
                time_start: selectedTimeSlot.time_start,
                time_end: selectedTimeSlot.time_end
            };
        }

        // Modal click outside to close - consolidated event listeners
        function setupModalEventListeners() {
            const modalConfigs = [
                { id: 'bookingModal', closeFunction: closeModal },
                { id: 'updateModal', closeFunction: closeUpdateModal },
                { id: 'successModal', closeFunction: closeSuccessModal },
                { id: 'errorModal', closeFunction: closeErrorModal },
                { id: 'confirmModal', closeFunction: closeConfirmModal },
                { id: 'cancellationReasonModal', closeFunction: closeCancellationReasonModal }
            ];
            
            modalConfigs.forEach(config => {
                const modal = document.getElementById(config.id);
                if (modal) {
                    modal.addEventListener('click', function(e) {
                        if (e.target === this) config.closeFunction();
                    });
                }
            });
        }

        // Cancellation reason modal functions
        let currentBookingIdForCancellation = null;

        function showCancellationReasonModal(bookingId) {
            currentBookingIdForCancellation = bookingId;
            const modal = document.getElementById('cancellationReasonModal');
            if (modal) {
                // Reset form
                const radioButtons = modal.querySelectorAll('input[name="cancellation_reason"]');
                radioButtons.forEach(radio => radio.checked = false);
                document.getElementById('otherReasonContainer').style.display = 'none';
                document.getElementById('otherReasonText').value = '';
                document.getElementById('charCount').textContent = '0';
                
                modal.style.display = 'flex';
            }
        }

        function closeCancellationReasonModal() {
            const modal = document.getElementById('cancellationReasonModal');
            if (modal) {
                modal.style.display = 'none';
            }
            currentBookingIdForCancellation = null;
        }

        // Handle radio button changes
        document.addEventListener('DOMContentLoaded', function() {
            const radioButtons = document.querySelectorAll('input[name="cancellation_reason"]');
            const otherReasonContainer = document.getElementById('otherReasonContainer');
            const otherReasonText = document.getElementById('otherReasonText');
            const charCount = document.getElementById('charCount');
            const confirmButton = document.getElementById('confirmCancellation');

            radioButtons.forEach(radio => {
                radio.addEventListener('change', function() {
                    if (this.value === 'Other') {
                        otherReasonContainer.style.display = 'block';
                        otherReasonText.focus();
                    } else {
                        otherReasonContainer.style.display = 'none';
                        otherReasonText.value = '';
                        charCount.textContent = '0';
                    }
                });
            });

            // Character count for textarea
            if (otherReasonText) {
                otherReasonText.addEventListener('input', function() {
                    charCount.textContent = this.value.length;
                });
            }

            // Confirm cancellation button
            if (confirmButton) {
                confirmButton.addEventListener('click', function() {
                    const selectedReason = document.querySelector('input[name="cancellation_reason"]:checked');
                    
                    if (!selectedReason) {
                        alert('Please select a reason for cancellation.');
                        return;
                    }

                    let reason = selectedReason.value;
                    if (reason === 'Other') {
                        const customReason = otherReasonText.value.trim();
                        if (!customReason) {
                            alert('Please specify your reason for cancellation.');
                            otherReasonText.focus();
                            return;
                        }
                        reason = customReason;
                    }

                    if (currentBookingIdForCancellation) {
                        cancelBooking(currentBookingIdForCancellation, reason);
                    }
                });
            }
        });
        
        // Initialize modal event listeners
        setupModalEventListeners();

        // Search and Filter Functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const dateFilter = document.getElementById('dateFilter');
            const statusFilter = document.getElementById('statusFilter');
            const groupFilter = document.getElementById('groupFilter');
            const clearFiltersBtn = document.getElementById('clearFilters');
            const customDate = document.getElementById('customDate');
            const datePickerGroup = document.getElementById('datePickerGroup');
            const bookingRows = document.querySelectorAll('.booking-row');

            // Show/hide custom date picker
            dateFilter.addEventListener('change', function() {
                if (this.value === 'custom') {
                    datePickerGroup.style.display = 'block';
                } else {
                    datePickerGroup.style.display = 'none';
                    customDate.value = '';
                }
                filterBookings();
            });

            // Custom date change
            customDate.addEventListener('change', filterBookings);

            // Function to filter bookings
            function filterBookings() {
                const searchTerm = searchInput.value.toLowerCase();
                const selectedDate = dateFilter.value;
                const selectedStatus = statusFilter.value;
                const selectedGroup = groupFilter.value;
                const customDateValue = customDate.value;

                let visibleCount = 0;

                bookingRows.forEach(row => {
                    let showRow = true;
                    const rowStatus = row.dataset.status.toLowerCase();

                    // Handle archived bookings exclusively
                    if (selectedStatus === 'archived') {
                        // When "Archived" is selected, only show archived bookings
                        if (rowStatus !== 'archived') {
                            showRow = false;
                        }
                    } else {
                        // When any other status is selected (including "All"), hide archived bookings
                        if (rowStatus === 'archived') {
                            showRow = false;
                        }
                    }

                    // Search filter (studio, service, booking ID)
                    if (showRow && searchTerm) {
                        const studioName = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
                        const serviceName = row.querySelector('td:nth-child(3)').textContent.toLowerCase();
                        const bookingId = row.querySelector('td:nth-child(1)').textContent.toLowerCase();
                        
                        if (!studioName.includes(searchTerm) && 
                            !serviceName.includes(searchTerm) && 
                            !bookingId.includes(searchTerm)) {
                            showRow = false;
                        }
                    }

                    // Date filter
                    if (showRow && selectedDate && selectedDate !== 'all') {
                        const bookingDateStr = row.dataset.date;
                        const bookingDate = new Date(bookingDateStr + 'T00:00:00'); // Ensure proper date parsing
                        const today = new Date();
                        today.setHours(0, 0, 0, 0); // Reset time to start of day
                        
                        switch (selectedDate) {
                            case 'today':
                                // Get today's date in local timezone (YYYY-MM-DD format)
                                const year = today.getFullYear();
                                const month = String(today.getMonth() + 1).padStart(2, '0');
                                const day = String(today.getDate()).padStart(2, '0');
                                const todayStr = `${year}-${month}-${day}`;
                                if (bookingDateStr !== todayStr) {
                                    showRow = false;
                                }
                                break;
                            case 'week':
                                const weekStart = new Date(today);
                                weekStart.setDate(today.getDate() - today.getDay()); // Start of week (Sunday)
                                const weekEnd = new Date(weekStart);
                                weekEnd.setDate(weekStart.getDate() + 6); // End of week (Saturday)
                                weekEnd.setHours(23, 59, 59, 999); // End of day
                                if (bookingDate < weekStart || bookingDate > weekEnd) {
                                    showRow = false;
                                }
                                break;
                            case 'month':
                                const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
                                const monthEnd = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                                monthEnd.setHours(23, 59, 59, 999);
                                if (bookingDate < monthStart || bookingDate > monthEnd) {
                                    showRow = false;
                                }
                                break;
                            case 'past':
                                if (bookingDate >= today) {
                                    showRow = false;
                                }
                                break;
                            case 'upcoming':
                                if (bookingDate < today) {
                                    showRow = false;
                                }
                                break;
                            case 'custom':
                                if (customDateValue) {
                                    const selectedCustomDateStr = customDateValue; // Already in YYYY-MM-DD format
                                    if (bookingDateStr !== selectedCustomDateStr) {
                                        showRow = false;
                                    }
                                }
                                break;
                        }
                    }

                    // Status filter (only apply if not archived, as archived is handled above)
                    if (showRow && selectedStatus && selectedStatus !== 'all' && selectedStatus !== 'archived') {
                        const status = row.dataset.status.toLowerCase();
                        if (status !== selectedStatus.toLowerCase()) {
                            showRow = false;
                        }
                    }

                    // Group filter
                    if (showRow && selectedGroup && selectedGroup !== 'all') {
                        const group = row.dataset.group.toLowerCase();
                        if (selectedGroup === 'group' && group !== 'group') {
                            showRow = false;
                        } else if (selectedGroup === 'individual' && group !== 'individual') {
                            showRow = false;
                        }
                    }

                    // Show/hide row
                    if (showRow) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                // Apply pagination to visible rows
                const rowsPerPageValue = document.getElementById('rowsPerPage') ? parseInt(document.getElementById('rowsPerPage').value, 10) : 10;
                const visibleRows = Array.from(document.querySelectorAll('.booking-row')).filter(r => r.style.display === '');
                const totalPages = Math.max(1, Math.ceil(visibleRows.length / rowsPerPageValue));
                window.currentPage = window.currentPage || 1;
                if (window.currentPage > totalPages) window.currentPage = totalPages;
                const startIdx = (window.currentPage - 1) * rowsPerPageValue;
                const endIdx = startIdx + rowsPerPageValue;
                visibleRows.forEach((row, idx) => {
                    row.style.display = (idx >= startIdx && idx < endIdx) ? '' : 'none';
                });
                const paginationInfoEl = document.getElementById('paginationInfo');
                const prevBtn = document.getElementById('prevPage');
                const nextBtn = document.getElementById('nextPage');
                if (paginationInfoEl) paginationInfoEl.textContent = `Page ${window.currentPage} of ${totalPages} (${visibleRows.length} results)`;
                if (prevBtn) prevBtn.disabled = window.currentPage <= 1;
                if (nextBtn) nextBtn.disabled = window.currentPage >= totalPages;

                // Update empty message
                const emptyMessage = document.querySelector('.empty-bookings');
                const bookingsTable = document.querySelector('.bookings-table');
                
                if (visibleCount === 0) {
                    if (!document.querySelector('.no-results-message')) {
                        const noResultsMsg = document.createElement('div');
                        noResultsMsg.className = 'no-results-message';
                        noResultsMsg.innerHTML = `
                            <div style="text-align: center; padding: 40px; color: #888;">
                                <i class="fas fa-search" style="font-size: 48px; margin-bottom: 20px; opacity: 0.5;"></i>
                                <h3 style="margin-bottom: 10px;">No bookings found</h3>
                                <p>Try adjusting your search criteria or filters.</p>
                            </div>
                        `;
                        bookingsTable.parentNode.insertBefore(noResultsMsg, bookingsTable.nextSibling);
                    }
                    document.querySelector('.no-results-message').style.display = 'block';
                    bookingsTable.style.display = 'none';
                } else {
                    const noResultsMsg = document.querySelector('.no-results-message');
                    if (noResultsMsg) {
                        noResultsMsg.style.display = 'none';
                    }
                    bookingsTable.style.display = '';
                }
            }

            // Initialize filters on page load
            filterBookings();

            // Pagination controls
            const rowsPerPageSelect = document.getElementById('rowsPerPage');
            const prevPageBtn = document.getElementById('prevPage');
            const nextPageBtn = document.getElementById('nextPage');
            window.currentPage = 1;

            if (rowsPerPageSelect) {
                rowsPerPageSelect.addEventListener('change', function() {
                    window.currentPage = 1;
                    filterBookings();
                });
            }
            if (prevPageBtn) {
                prevPageBtn.addEventListener('click', function() {
                    if (window.currentPage > 1) {
                        window.currentPage -= 1;
                        filterBookings();
                    }
                });
            }
            if (nextPageBtn) {
                nextPageBtn.addEventListener('click', function() {
                    window.currentPage += 1;
                    filterBookings();
                });
            }

            // Clear filters function
            function clearFilters() {
                searchInput.value = '';
                dateFilter.value = 'all';
                statusFilter.value = 'all';
                groupFilter.value = 'all';
                customDate.value = '';
                datePickerGroup.style.display = 'none';
                
                // Remove archived message if present
                const archivedMsg = document.querySelector('.archived-message');
                if (archivedMsg) {
                    archivedMsg.remove();
                }
                
                filterBookings();
            }

            // Event listeners
            searchInput.addEventListener('input', filterBookings);
            dateFilter.addEventListener('change', filterBookings);
            statusFilter.addEventListener('change', filterBookings);
            groupFilter.addEventListener('change', filterBookings);
            clearFiltersBtn.addEventListener('click', clearFilters);

            // Add data attributes to booking rows for filtering
            bookingRows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length >= 7) {
                    // Extract date from the date cell (assuming format: "Dec 15, 2024")
                    const dateText = cells[4].textContent.trim();
                    const dateObj = new Date(dateText);
                    row.dataset.date = dateObj.toISOString().split('T')[0];
                    
                    // Extract status
                    const statusElement = cells[5].querySelector('.status');
                    if (statusElement) {
                        row.dataset.status = statusElement.textContent.trim();
                    }
                    
                    // Extract group type
                    const groupText = cells[6].textContent.trim().toLowerCase();
                    row.dataset.group = groupText;
                }
            });
        });
    </script>
</body>
</html>

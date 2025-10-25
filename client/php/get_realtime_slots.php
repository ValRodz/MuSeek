<?php
// get_realtime_slots.php - Fetch real-time available time slots for a specific studio and date
session_start();

// Set content type to JSON
header('Content-Type: application/json');

// Enhanced error handling for database connection
$db_path = __DIR__ . '/../../shared/config/db.php';

// Check if database config file exists
if (!file_exists($db_path)) {
    error_log("Database config file not found at: " . $db_path);
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Database configuration file not found',
        'debug_info' => [
            'expected_path' => $db_path,
            'current_dir' => __DIR__,
            'file_exists' => false
        ]
    ]);
    exit();
}

// Include database connection
include $db_path;

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    $error_msg = isset($conn) ? $conn->connect_error : 'Database connection variable not set';
    error_log("Database connection failed: " . $error_msg);
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Database connection failed',
        'debug_info' => [
            'connection_error' => $error_msg,
            'db_path' => $db_path
        ]
    ]);
    exit();
}

// Check if user is authenticated
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'client') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get input data
$input = json_decode(file_get_contents('php://input'), true);
$date = isset($input['date']) ? $input['date'] : '';
$studio_name = isset($input['studio_name']) ? trim($input['studio_name']) : '';
$service_id = isset($input['service_id']) ? intval($input['service_id']) : 0;

// Validate input
if (empty($date) || empty($studio_name)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Date and studio name are required']);
    exit();
}

// Validate date format
if (!DateTime::createFromFormat('Y-m-d', $date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit();
}

try {
    // Get studio information including operating hours
    $studio_query = "SELECT StudioID, StudioName, Time_IN, Time_OUT FROM studios WHERE StudioName = ?";
    $stmt = mysqli_prepare($conn, $studio_query);
    mysqli_stmt_bind_param($stmt, "s", $studio_name);
    mysqli_stmt_execute($stmt);
    $studio_result = mysqli_stmt_get_result($stmt);
    $studio = mysqli_fetch_assoc($studio_result);
    
    if (!$studio) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Studio not found']);
        exit();
    }
    mysqli_stmt_close($stmt);
    
    $studio_id = $studio['StudioID'];
    $time_in = $studio['Time_IN'];
    $time_out = $studio['Time_OUT'];
    
    // Check if the selected date is today
    $today = date('Y-m-d');
    $is_today = ($date === $today);
    $current_time = date('H:i:s');
    $current_datetime = new DateTime();
    
    // Check if studio is closed for today (current time past Time_OUT)
    $today_disabled = false;
    if ($is_today && $current_time >= $time_out) {
        $today_disabled = true;
        echo json_encode([
            'success' => true,
            'slots' => [],
            'date' => $date,
            'studio' => [
                'id' => $studio_id,
                'name' => $studio['StudioName'],
                'time_in' => $time_in,
                'time_out' => $time_out
            ],
            'is_today' => $is_today,
            'current_time' => $current_time,
            'today_disabled' => $today_disabled,
            'message' => 'Studio is closed for today'
        ]);
        exit();
    }
    
    // Also check if current time is before studio opening time for today
    if ($is_today && $current_time < $time_in) {
        // Studio hasn't opened yet today, but we can still show future slots
        // No need to exit, just mark past slots as unavailable
    }
    
    // Generate time slots within studio operating hours
    $slots = [];
    $start_hour = intval(substr($time_in, 0, 2));
    $end_hour = intval(substr($time_out, 0, 2));
    
    for ($hour = $start_hour; $hour < $end_hour; $hour++) {
        $slot_start = sprintf('%02d:00:00', $hour);
        $slot_end = sprintf('%02d:00:00', $hour + 1);
        $slot_display = sprintf('%02d:00', $hour);
        
        // Check availability based on various conditions
        $available = true;
        $reason = '';
        
        // Check if slot is in the past (for today) or before studio opening
        if ($is_today) {
            // Check if slot has already passed
            if ($slot_start <= $current_time) {
                $available = false;
                $reason = 'Past time';
            }
            // Check if slot is before studio opening time
            else if ($slot_start < $time_in) {
                $available = false;
                $reason = 'Studio not open yet';
            }
            // Check if slot would end after studio closing time
            else if ($slot_end > $time_out) {
                $available = false;
                $reason = 'Studio closed';
            }
        }
        // For future dates, check if slot is within studio operating hours
        else {
            if ($slot_start < $time_in || $slot_end > $time_out) {
                $available = false;
                $reason = 'Outside studio hours';
            }
        }
        
        // Check if this time slot is already booked
        if ($available) {
            $booking_query = "
                SELECT COUNT(*) as booking_count 
                FROM bookings b 
                JOIN schedules s ON b.ScheduleID = s.ScheduleID 
                WHERE b.StudioID = ? 
                AND s.Sched_Date = ? 
                AND s.Time_Start = ? 
                AND s.Time_End = ? 
                AND b.Book_StatsID IN (1, 2)
            ";
            
            $stmt = mysqli_prepare($conn, $booking_query);
            mysqli_stmt_bind_param($stmt, "isss", $studio_id, $date, $slot_start, $slot_end);
            mysqli_stmt_execute($stmt);
            $booking_result = mysqli_stmt_get_result($stmt);
            $booking_row = mysqli_fetch_assoc($booking_result);
            mysqli_stmt_close($stmt);
            
            if ($booking_row['booking_count'] > 0) {
                $available = false;
                $reason = 'Already booked';
            }
        }
        
        // Check if this slot is already selected in the current session
        if ($available && isset($_SESSION['selected_slots'])) {
            foreach ($_SESSION['selected_slots'] as $selected_slot) {
                // Handle both possible key formats for backward compatibility
                $session_start_time = isset($selected_slot['start_time']) ? $selected_slot['start_time'] : 
                                     (isset($selected_slot['start']) ? $selected_slot['start'] . ':00' : '');
                
                if (isset($selected_slot['studio_id']) && $selected_slot['studio_id'] == $studio_id && 
                    isset($selected_slot['date']) && $selected_slot['date'] == $date && 
                    $session_start_time == $slot_start) {
                    $available = false;
                    $reason = 'Already selected in session';
                    break;
                }
            }
        }
        
        // Add all slots (both available and unavailable) to the array
        $slots[] = [
            'time' => $slot_display,
            'start_time' => substr($slot_start, 0, 5), // Format as HH:MM
            'end_time' => substr($slot_end, 0, 5),     // Format as HH:MM
            'display' => $slot_display,
            'available' => $available,
            'reason' => $reason
        ];
    }
    
    // Return successful response
    echo json_encode([
        'success' => true,
        'slots' => $slots,
        'date' => $date,
        'studio' => [
            'id' => $studio_id,
            'name' => $studio['StudioName'],
            'time_in' => $time_in,
            'time_out' => $time_out
        ],
        'is_today' => $is_today,
        'current_time' => $current_time,
        'today_disabled' => $today_disabled
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_realtime_slots.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

// Close database connection
mysqli_close($conn);
?>
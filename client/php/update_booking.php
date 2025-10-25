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
include __DIR__ . '/../../shared/config/db.php';

// Set content type to JSON
header('Content-Type: application/json');

// Debug logging
error_log("=== UPDATE BOOKING DEBUG START ===");
error_log("Session data: " . json_encode($_SESSION));
error_log("POST data: " . json_encode($_POST));
error_log("Request method: " . $_SERVER['REQUEST_METHOD']);

// Check if user is authenticated and is a client
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'client') {
    echo json_encode([
        'success' => false, 
        'error' => 'Unauthorized access. Please log in as a client.',
        'error_code' => 'UNAUTHORIZED'
    ]);
    exit();
}

// Validate required fields
$required_fields = ['booking_id', 'new_date', 'time_slot', 'service_id', 'instructor_id'];
foreach ($required_fields as $field) {
    if (!isset($_POST[$field]) || empty($_POST[$field])) {
        echo json_encode([
            'success' => false, 
            'error' => "Missing required field: $field",
            'error_code' => 'MISSING_FIELD'
        ]);
        exit();
    }
}

$booking_id = (int)$_POST['booking_id'];
$new_date = $_POST['new_date'];
$time_slot = $_POST['time_slot'];
$service_id = (int)$_POST['service_id'];
$instructor_id = (int)$_POST['instructor_id'];
$client_id = $_SESSION['user_id'];

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
    echo json_encode([
        'success' => false, 
        'error' => 'Invalid date format. Please use YYYY-MM-DD format.',
        'error_code' => 'INVALID_DATE_FORMAT'
    ]);
    exit();
}

// Validate time slot format
if (!preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $time_slot)) {
    echo json_encode([
        'success' => false, 
        'error' => 'Invalid time slot format. Please use HH:MM-HH:MM format.',
        'error_code' => 'INVALID_TIME_FORMAT'
    ]);
    exit();
}

// Parse time slot
$time_parts = explode('-', $time_slot);
$start_time = $time_parts[0] . ':00';
$end_time = $time_parts[1] . ':00';

try {
    // Get the old schedule ID before updating
    $old_schedule_query = "SELECT ScheduleID FROM bookings WHERE BookingID = ? AND ClientID = ?";
    $stmt = mysqli_prepare($conn, $old_schedule_query);
    
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "ii", $booking_id, $client_id);
    mysqli_stmt_execute($stmt);
    $old_schedule_result = mysqli_stmt_get_result($stmt);
    
    $old_schedule_id = null;
    if (mysqli_num_rows($old_schedule_result) > 0) {
        $old_schedule_row = mysqli_fetch_assoc($old_schedule_result);
        $old_schedule_id = $old_schedule_row['ScheduleID'];
    }
    mysqli_stmt_close($stmt);

    // Verify the booking belongs to the client and is pending
    $verify_query = "SELECT b.BookingID, b.StudioID, s.ServiceType, s.Price 
                     FROM bookings b 
                     JOIN services s ON b.ServiceID = s.ServiceID 
                     WHERE b.BookingID = ? AND b.ClientID = ? 
                     AND b.Book_StatsID = (SELECT Book_StatsID FROM book_stats WHERE Book_Stats = 'Pending')";
    
    $stmt = mysqli_prepare($conn, $verify_query);
    
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "ii", $booking_id, $client_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) === 0) {
        mysqli_stmt_close($stmt);
        echo json_encode([
            'success' => false, 
            'error' => 'Booking not found or cannot be updated. Only pending bookings can be modified.',
            'error_code' => 'BOOKING_NOT_FOUND'
        ]);
        exit();
    }

    $booking_data = mysqli_fetch_assoc($result);
    $studio_id = $booking_data['StudioID'];
    mysqli_stmt_close($stmt);

    // Verify the service belongs to the same studio using studio_services junction table
    $service_query = "SELECT s.ServiceID, s.ServiceType, s.Price 
                      FROM services s 
                      JOIN studio_services ss ON s.ServiceID = ss.ServiceID 
                      WHERE s.ServiceID = ? AND ss.StudioID = ?";
    $stmt = mysqli_prepare($conn, $service_query);
    
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "ii", $service_id, $studio_id);
    mysqli_stmt_execute($stmt);
    $service_result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($service_result) === 0) {
        mysqli_stmt_close($stmt);
        echo json_encode([
            'success' => false, 
            'error' => 'Invalid service selected for this studio.',
            'error_code' => 'INVALID_SERVICE'
        ]);
        exit();
    }

    $service_data = mysqli_fetch_assoc($service_result);
    mysqli_stmt_close($stmt);

    // Check for schedule conflicts
    $conflict_query = "SELECT ScheduleID FROM schedules 
                       WHERE StudioID = ? AND Sched_Date = ? 
                       AND ((Time_Start < ? AND Time_End > ?) OR (Time_Start < ? AND Time_End > ?))
                       AND ScheduleID NOT IN (SELECT ScheduleID FROM bookings WHERE BookingID = ?)
                       AND Avail_StatsID = 2";
    
    $stmt = mysqli_prepare($conn, $conflict_query);
    
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "isssssi", $studio_id, $new_date, $end_time, $start_time, $start_time, $end_time, $booking_id);
    mysqli_stmt_execute($stmt);
    $conflict_result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($conflict_result) > 0) {
        mysqli_stmt_close($stmt);
        echo json_encode([
            'success' => false, 
            'error' => 'Time slot conflict detected. Please choose a different time.',
            'error_code' => 'TIME_CONFLICT'
        ]);
        exit();
    }
    mysqli_stmt_close($stmt);

    // Find or create schedule
    $schedule_query = "SELECT ScheduleID FROM schedules WHERE StudioID = ? AND Sched_Date = ? AND Time_Start = ? AND Time_End = ?";
    $stmt = mysqli_prepare($conn, $schedule_query);
    
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "isss", $studio_id, $new_date, $start_time, $end_time);
    mysqli_stmt_execute($stmt);
    $schedule_result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($schedule_result) > 0) {
        $schedule_row = mysqli_fetch_assoc($schedule_result);
        $schedule_id = $schedule_row['ScheduleID'];
    } else {
        // Create new schedule with Avail_StatsID = 2 (Booked)
        mysqli_stmt_close($stmt);
        $create_schedule_query = "INSERT INTO schedules (StudioID, Sched_Date, Time_Start, Time_End, Avail_StatsID) VALUES (?, ?, ?, ?, 2)";
        $stmt = mysqli_prepare($conn, $create_schedule_query);
        
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($stmt, "isss", $studio_id, $new_date, $start_time, $end_time);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Failed to create schedule: ' . mysqli_error($conn));
        }
        
        $schedule_id = mysqli_insert_id($conn);
    }
    mysqli_stmt_close($stmt);

    // Start transaction for data consistency
    mysqli_autocommit($conn, false);
    
    try {
        // Add detailed logging for debugging
        error_log("=== BOOKING UPDATE TRANSACTION START ===");
        error_log("BookingID: $booking_id, Old ScheduleID: $old_schedule_id, New ScheduleID: $schedule_id");
        
        // First, verify the booking exists and get current data
        $verify_query = "SELECT ScheduleID, ServiceID, InstructorID FROM bookings WHERE BookingID = ?";
        $stmt_verify = mysqli_prepare($conn, $verify_query);
        
        if (!$stmt_verify) {
            throw new Exception('Database prepare error for booking verification: ' . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($stmt_verify, "i", $booking_id);
        
        if (!mysqli_stmt_execute($stmt_verify)) {
            throw new Exception('Failed to verify booking existence: ' . mysqli_error($conn));
        }
        
        $verify_result = mysqli_stmt_get_result($stmt_verify);
        
        if (mysqli_num_rows($verify_result) === 0) {
            mysqli_stmt_close($stmt_verify);
            throw new Exception("Booking ID $booking_id not found");
        }
        
        $current_booking = mysqli_fetch_assoc($verify_result);
        mysqli_stmt_close($stmt_verify);
        error_log("Current booking data: " . json_encode($current_booking));
        
        // Check if old schedule will have any remaining bookings BEFORE updating
        // Note: We'll check this again AFTER the booking update for accuracy
        $has_old_schedule = ($old_schedule_id && $old_schedule_id != $schedule_id);
        
        // Update the booking with explicit type casting
        error_log("Updating booking $booking_id: ScheduleID=$schedule_id, ServiceID=$service_id, InstructorID=$instructor_id");
        $update_query = "UPDATE bookings SET ScheduleID = ?, ServiceID = ?, InstructorID = ? WHERE BookingID = ?";
        $stmt = mysqli_prepare($conn, $update_query);
        
        if (!$stmt) {
            throw new Exception('Database prepare error for booking update: ' . mysqli_error($conn));
        }
        
        // Ensure all parameters are integers
        $schedule_id = (int)$schedule_id;
        $service_id = (int)$service_id;
        $instructor_id = (int)$instructor_id;
        $booking_id = (int)$booking_id;
        
        mysqli_stmt_bind_param($stmt, "iiii", $schedule_id, $service_id, $instructor_id, $booking_id);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Failed to update booking: ' . mysqli_error($conn));
        }
        
        $affected_rows = mysqli_stmt_affected_rows($stmt);
        error_log("Booking update affected rows: $affected_rows");
        mysqli_stmt_close($stmt);
        
        if ($affected_rows === 0) {
            throw new Exception('No booking was updated - booking may not exist or no changes were made');
        }
        
        // NOW check if old schedule has any remaining bookings AFTER the booking update
        if ($has_old_schedule) {
            $check_remaining_query = "SELECT COUNT(*) as booking_count FROM bookings WHERE ScheduleID = ?";
            $stmt_check_remaining = mysqli_prepare($conn, $check_remaining_query);
            
            if ($stmt_check_remaining) {
                mysqli_stmt_bind_param($stmt_check_remaining, "i", $old_schedule_id);
                if (!mysqli_stmt_execute($stmt_check_remaining)) {
                    throw new Exception('Failed to check remaining bookings after update: ' . mysqli_error($conn));
                }
                $remaining_result = mysqli_stmt_get_result($stmt_check_remaining);
                $remaining_row = mysqli_fetch_assoc($remaining_result);
                $remaining_bookings_count = $remaining_row['booking_count'];
                mysqli_stmt_close($stmt_check_remaining);
                error_log("Remaining bookings for old schedule $old_schedule_id AFTER update: $remaining_bookings_count");
                
                // If old schedule has no remaining bookings, set it to Available
                if ($remaining_bookings_count == 0) {
                    error_log("Setting old schedule $old_schedule_id to Available (no remaining bookings)");
                    $update_old_schedule_query = "UPDATE schedules SET Avail_StatsID = 1 WHERE ScheduleID = ?";
                    $stmt_update_old = mysqli_prepare($conn, $update_old_schedule_query);
                    
                    if ($stmt_update_old) {
                        $old_schedule_id = (int)$old_schedule_id;
                        mysqli_stmt_bind_param($stmt_update_old, "i", $old_schedule_id);
                        if (!mysqli_stmt_execute($stmt_update_old)) {
                            throw new Exception('Failed to update old schedule availability: ' . mysqli_error($conn));
                        }
                        $old_affected_rows = mysqli_stmt_affected_rows($stmt_update_old);
                        error_log("Old schedule update affected rows: $old_affected_rows");
                        mysqli_stmt_close($stmt_update_old);
                        
                        if ($old_affected_rows === 0) {
                            error_log("WARNING: Old schedule $old_schedule_id was not updated - may not exist or already Available");
                        } else {
                            error_log("SUCCESS: Old schedule $old_schedule_id set to Available");
                        }
                    }
                } else {
                    error_log("Old schedule $old_schedule_id still has $remaining_bookings_count bookings - keeping as Booked");
                }
            }
        }
        
        // Set the new schedule to Booked (Avail_StatsID = 2) - only if it's not already booked
        error_log("Setting new schedule $schedule_id to Booked");
        $update_new_schedule_query = "UPDATE schedules SET Avail_StatsID = 2 WHERE ScheduleID = ? AND Avail_StatsID != 2";
        $stmt_update_new = mysqli_prepare($conn, $update_new_schedule_query);
        
        if ($stmt_update_new) {
            mysqli_stmt_bind_param($stmt_update_new, "i", $schedule_id);
            if (!mysqli_stmt_execute($stmt_update_new)) {
                throw new Exception('Failed to update new schedule availability: ' . mysqli_error($conn));
            }
            $new_affected_rows = mysqli_stmt_affected_rows($stmt_update_new);
            error_log("New schedule update affected rows: $new_affected_rows");
            mysqli_stmt_close($stmt_update_new);
        }
        
        // Commit transaction
        mysqli_commit($conn);
        error_log("=== BOOKING UPDATE TRANSACTION COMMITTED ===");

        error_log("Booking $booking_id updated by ClientID $client_id");
        echo json_encode([
            'success' => true,
            'message' => 'Booking has been successfully updated.',
            'booking_id' => $booking_id,
            'new_service' => $service_data['ServiceType'],
            'new_price' => $service_data['Price'],
            'new_date' => $new_date,
            'new_time' => $time_slot,
            'debug_info' => [
                'old_schedule_id' => $old_schedule_id,
                'new_schedule_id' => $schedule_id,
                'remaining_bookings_count' => $remaining_bookings_count
            ]
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        mysqli_rollback($conn);
        error_log("=== BOOKING UPDATE TRANSACTION ROLLED BACK ===");
        error_log("Transaction error: " . $e->getMessage());
        throw $e;
    } finally {
        // Restore autocommit
        mysqli_autocommit($conn, true);
    }
    
} catch (Exception $e) {
    error_log("Error updating booking $booking_id: " . $e->getMessage());
    error_log("Exception details: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'error' => 'Database error: ' . $e->getMessage(),
        'error_code' => 'DATABASE_ERROR',
        'debug_info' => [
            'booking_id' => isset($booking_id) ? $booking_id : 'not set',
            'client_id' => isset($client_id) ? $client_id : 'not set',
            'error_message' => $e->getMessage()
        ]
    ]);
}

error_log("=== UPDATE BOOKING DEBUG END ===");
mysqli_close($conn);
?>


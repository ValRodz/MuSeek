<?php
ob_start(); // Still useful for any stray output
ini_set('display_errors', 0); // Suppress on-screen errors
error_reporting(E_ALL);
ini_set('log_errors', 1);

session_start();
include '../../shared/config/db.php'; // Now PDO $conn

// Check login (unchanged)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo "<script>alert('Please log in to continue.'); window.location.href = '../../auth/php/login.html';</script>";
    exit;
}

try {
    //$conn->beginTransaction(); // Start PDO transaction

    $conn->begin_transaction(); // Start PDO transaction

    // Get studio_id, services_data, selected_slots (unchanged)
    $studio_id = isset($_POST['studio_id']) ? (int)$_POST['studio_id'] : 
                 (isset($_SESSION['current_booking']['studio_id']) ? (int)$_SESSION['current_booking']['studio_id'] : 0);
    
    $services_data = [];
    if (isset($_POST['services_data'])) {
        $services_data = is_array($_POST['services_data']) ? $_POST['services_data'] : json_decode($_POST['services_data'], true);
    } elseif (isset($_SESSION['services_data']) && is_array($_SESSION['services_data'])) {
        $services_data = $_SESSION['services_data'];
    }

    $selected_slots = [];
    if (isset($_POST['selected_slots'])) {
        $selected_slots = is_array($_POST['selected_slots']) ? $_POST['selected_slots'] : json_decode($_POST['selected_slots'], true);
    } elseif (isset($_SESSION['selected_slots']) && is_array($_SESSION['selected_slots'])) {
        $selected_slots = $_SESSION['selected_slots'];
    }

    // Early validation (unchanged, but throw PDO-style)
    if ($studio_id <= 0 || empty($selected_slots)) {
        throw new Exception("Invalid booking details.");
    }

    if (!is_array($services_data) || empty($services_data)) {
        throw new Exception("Invalid or empty services_data.");
    }

    // Process slots and calculate total (unchanged, but add datetime validation)
    $total_price = 0;
    $initial_payment = 0;
    foreach ($selected_slots as &$slot) {
        if (!isset($slot['service_id']) || $slot['service_id'] <= 0 || !isset($services_data[$slot['service_id']])) {
            throw new Exception("Invalid slot data: " . json_encode($slot));
        }

        // Datetime validation
        $start_str = $slot['date'] . ' ' . $slot['start'];
        $end_str = $slot['date'] . ' ' . $slot['end'];
        $start = DateTime::createFromFormat('Y-m-d H:i', $start_str);
        $end = DateTime::createFromFormat('Y-m-d H:i', $end_str);
        if (!$start || !$end || $start >= $end) {
            throw new Exception("Invalid datetime in slot: " . json_encode($slot));
        }

        $service = $services_data[$slot['service_id']];
        $slot['service_name'] = $service['name'];
        $slot['service_price'] = (float)$service['price'];
        $slot['instructor_id'] = $service['instructor_id'] ?? 0;

        $interval = $start->diff($end);
        $hours = $interval->h + ($interval->days * 24) + ($interval->i / 60);
        $total_price += $slot['service_price'] * $hours;
    }
    unset($slot);
    $initial_payment = $total_price * 0.25;

    // Get client_id (unchanged)
    $client_id = $_SESSION['user_id'];

    // Get owner_id (refactor to PDO)
    $owner_query = "SELECT OwnerID FROM studios WHERE StudioID = ?";
    $stmt = $conn->prepare($owner_query);
    $stmt->bind_param('i', $studio_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $owner_row = $res->fetch_assoc();
    if (!$owner_row) {
        throw new Exception("No owner found for StudioID: $studio_id");
    }
    $owner_id = $owner_row['OwnerID'];

    // Generate unique PaymentGroupID for multiple bookings
    $payment_group_id = uniqid('PG_' . date('Ymd_His_'), true);

    // Insert schedules and bookings in loop
    $schedule_ids = [];
    $booking_ids = [];
    $notification_messages = [];
    $slot_prices = []; // Array to store individual slot prices
    $slot_initial_payments = []; // Array to store individual initial payments

    $schedule_query = "INSERT INTO schedules (StudioID, OwnerID, Sched_Date, Time_Start, Time_End, Avail_StatsID) VALUES (?, ?, ?, ?, ?, 2)";
    $schedule_stmt = $conn->prepare($schedule_query);

    foreach ($selected_slots as $slot) {
        // Insert schedule
        $schedule_stmt->bind_param('iisss', $studio_id, $owner_id, $slot['date'], $slot['start'], $slot['end']);
        $schedule_stmt->execute();
        if ($schedule_stmt->affected_rows == 0) {
            throw new Exception("Failed to insert schedule for slot: " . json_encode($slot));
        }
        $schedule_id = $conn->insert_id;
        $schedule_ids[] = $schedule_id;

        // Prepare booking data
        $slot_service_id = $slot['service_id'];
        $slot_instructor_id = !empty($slot['instructor_id']) ? (int)$slot['instructor_id'] : null;
        
        // Calculate individual slot price (service + instruments)
        $start_dt = DateTime::createFromFormat('Y-m-d H:i', $slot['date'] . ' ' . $slot['start']);
        $end_dt   = DateTime::createFromFormat('Y-m-d H:i', $slot['date'] . ' ' . $slot['end']);
        $hours = ($end_dt->getTimestamp() - $start_dt->getTimestamp()) / 3600;
        if ($hours <= 0) {
            throw new Exception('Invalid hours computed for slot: ' . json_encode($slot));
        }
        $slot_total_price = (float)$slot['service_price'] * $hours;
        $addons_total = 0.0;

        // Re-check instrument add-ons and compute add-on totals; defer insert until booking_id exists
        $slot_addons = [];
        if (!empty($slot['instruments']) && is_array($slot['instruments'])) {
            foreach ($slot['instruments'] as $instSel) {
                $instrument_id = (int)($instSel['instrument_id'] ?? 0);
                $req_qty = (int)($instSel['quantity'] ?? 0);
                if ($instrument_id <= 0 || $req_qty <= 0) {
                    throw new Exception('Invalid instrument selection: ' . json_encode($instSel));
                }
                // Fetch instrument
                $stmtInst = $conn->prepare('SELECT InstrumentID, StudioID, HourlyRate, Quantity, IsActive FROM instruments WHERE InstrumentID = ?');
                if (!$stmtInst) throw new Exception('DB error: prepare failed (instrument)');
                $stmtInst->bind_param('i', $instrument_id);
                $stmtInst->execute();
                $resInst = $stmtInst->get_result();
                $instRow = $resInst->fetch_assoc();
                $stmtInst->close();
                if (!$instRow) throw new Exception('Instrument not found: ' . $instrument_id);
                if ((int)$instRow['StudioID'] !== (int)$studio_id) throw new Exception('Instrument belongs to a different studio');
                if ((int)$instRow['IsActive'] !== 1) throw new Exception('Instrument inactive');

                $total_qty = (int)$instRow['Quantity'];
                // Overlap check
                $overlap_sql = "SELECT COALESCE(SUM(ba.Quantity), 0) AS reserved_qty
                                FROM booking_addons ba
                                JOIN bookings b ON ba.BookingID = b.BookingID
                                JOIN schedules s ON b.ScheduleID = s.ScheduleID
                                WHERE ba.InstrumentID = ?
                                  AND s.Sched_Date = ?
                                  AND s.Time_Start < ?
                                  AND s.Time_End > ?
                                  AND b.Book_StatsID IN (1,2)";
                $stmtOv = $conn->prepare($overlap_sql);
                if (!$stmtOv) throw new Exception('DB error: prepare failed (overlap)');
                $end_param = $end_dt->format('H:i:00');
                $start_param = $start_dt->format('H:i:00');
                $stmtOv->bind_param('isss', $instrument_id, $slot['date'], $end_param, $start_param);
                $stmtOv->execute();
                $resOv = $stmtOv->get_result();
                $rowOv = $resOv->fetch_assoc();
                $stmtOv->close();
                $reserved = (int)($rowOv ? $rowOv['reserved_qty'] : 0);
                $available = max(0, $total_qty - $reserved);
                if ($available < $req_qty) {
                    throw new Exception('Instrument unavailable for requested quantity during this slot. Available: ' . $available);
                }
                // Price line; defer DB insert until booking_id is known
                $hourly_rate = (float)$instRow['HourlyRate'];
                $line_price = $hours * $hourly_rate * $req_qty;
                $slot_addons[] = [
                    'instrument_id' => $instrument_id,
                    'quantity' => $req_qty,
                    'price' => $line_price
                ];
                $addons_total += $line_price;
            }
        }
        // Finalize slot totals
        $slot_total_price += $addons_total;
        $slot_initial_payment = $slot_total_price * 0.25; // 25% initial payment for this slot
        
        // Store individual prices for payment insertion
        $slot_prices[] = $slot_total_price;
        $slot_initial_payments[] = $slot_initial_payment;
        
        // Debug log
        error_log("Inserting booking with instructor_id: " . ($slot_instructor_id ?? 'NULL') . ", price: " . $slot_total_price);
        
        // Build and execute the query directly with proper NULL handling
        $instructor_value = $slot_instructor_id !== null ? $slot_instructor_id : 'NULL';
        $booking_query = "INSERT INTO bookings (ClientID, StudioID, ScheduleID, ServiceID, InstructorID, Book_StatsID) 
                         VALUES ($client_id, $studio_id, $schedule_id, $slot_service_id, $instructor_value, 2)";
        
        if (!$conn->query($booking_query)) {
            throw new Exception("Failed to insert booking for schedule ID: $schedule_id - " . $conn->error);
        }
        
        $booking_id = $conn->insert_id;
        $booking_ids[] = $booking_id;

        // Insert slot add-ons now that we have booking_id
        if (!empty($slot_addons)) {
            foreach ($slot_addons as $addon) {
                $stmtAdd = $conn->prepare('INSERT INTO booking_addons (BookingID, InstrumentID, Quantity, Price) VALUES (?, ?, ?, ?)');
                if (!$stmtAdd) throw new Exception('DB error: prepare failed (add-on insert)');
                $stmtAdd->bind_param('iiid', $booking_id, $addon['instrument_id'], $addon['quantity'], $addon['price']);
                $stmtAdd->execute();
                if ($stmtAdd->affected_rows === 0) {
                    throw new Exception('Failed to insert booking addon');
                }
                $stmtAdd->close();
            }
        }

        // Notification prep (unchanged)
        // ... (keep your code for formatting and instructor fetch, but refactor instructor query to PDO if needed)
    }

    // Insert payment for each booking with PaymentGroupID, using individual prices
    foreach ($booking_ids as $index => $booking_id) {
        $payment_query = "INSERT INTO payment (PaymentGroupID, BookingID, OwnerID, Init_Amount, Amount, Pay_Date, Pay_Stats) VALUES (?, ?, ?, ?, ?, NOW(), 'Pending')";
        $payment_stmt = $conn->prepare($payment_query);
        $payment_stmt->bind_param('siidd', $payment_group_id, $booking_id, $owner_id, $slot_initial_payments[$index], $slot_prices[$index]);
        $payment_stmt->execute();
        if ($payment_stmt->affected_rows == 0) {
            throw new Exception("Failed to insert payment for booking ID: $booking_id");
        }
        $payment_stmt->close();
    }

    // Insert notification (similar refactor)
    if (!empty($notification_messages)) {
        $notification_message = "New booking request for: " . implode(", ", $notification_messages);
        $notification_query = "INSERT INTO notifications (OwnerID, ClientID, Type, Message, RelatedID, IsRead, Created_At) VALUES (?, ?, 'Booking', ?, ?, 0, NOW())";
        $notification_stmt = $conn->prepare($notification_query);
        $notification_stmt->bind_param('iisi', $owner_id, $client_id, $notification_message, $first_booking_id);
        $notification_stmt->execute();
        if ($notification_stmt->affected_rows == 0) {
            throw new Exception("Failed to insert notification");
        }
        $notification_stmt->close();
    }

    $conn->commit(); // Commit if all succeeds

    // Clear session (unchanged)
    unset($_SESSION['current_booking']);
    unset($_SESSION['selected_slots']);

    if (empty($booking_ids)) {
        throw new Exception("No bookings created");
    }

    $first_booking_id = $booking_ids[0];
    ob_end_clean();
    header("Location: booking_confirmation.php?booking_id=" . $first_booking_id . "&multi=1&count=" . count($booking_ids));
    exit;

} catch (Exception $e) {
    $conn->rollback(); // mysqli rollback
    error_log("Booking error: " . $e->getMessage());
    $_SESSION['booking_error'] = "An error occurred while processing your booking: " . $e->getMessage(); // More detailed for debugging
    ob_end_clean();
    header("Location: booking3.php?studio_id=" . $studio_id);
    exit;
}
?>
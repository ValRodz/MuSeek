<?php
// Start the session to access session variables
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
require_once __DIR__ . '/db.php';

// Check if the request is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get the logged-in owner's ID from session
$ownerId = $_SESSION['user_id'];

// Get form data
$scheduleId = $_POST['schedule_id'] ?? '';

// Validate form data
if (empty($scheduleId)) {
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Schedule ID is required']);
    exit();
}

// Check if the schedule belongs to the owner and get the date for redirection
$scheduleStmt = $pdo->prepare("
    SELECT s.ScheduleID, s.Sched_Date, s.Avail_StatsID 
    FROM schedules s
    JOIN studios st ON s.StudioID = st.StudioID
    WHERE s.ScheduleID = ? AND st.OwnerID = ?
");
$scheduleStmt->execute([$scheduleId, $ownerId]);
$schedule = $scheduleStmt->fetch();

if (!$schedule) {
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid schedule selected']);
    exit();
}

// Check if the schedule is booked (cannot delete booked schedules)
if ($schedule['Avail_StatsID'] == 2) { // 2 = Booked
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Cannot delete a booked schedule']);
    exit();
}

// Store the date for redirection
$scheduleDate = $schedule['Sched_Date'];

// Delete the schedule
$deleteStmt = $pdo->prepare("DELETE FROM schedules WHERE ScheduleID = ?");

try {
    $deleteStmt->execute([$scheduleId]);
    
    // Redirect back to schedule page
    header("Location: schedule.php?view=monthly&month=" . date('m', strtotime($scheduleDate)) . "&year=" . date('Y', strtotime($scheduleDate)));
    exit();
} catch (PDOException $e) {
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit();
}

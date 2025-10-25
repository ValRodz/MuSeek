<?php
// Return schedule details as JSON for the owner schedule page
session_start();

header('Content-Type: application/json');

// Ensure user is logged in as owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include DB connection
include '../../shared/config/db pdo.php';

$ownerId = $_SESSION['user_id'];

// Validate schedule id
$scheduleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($scheduleId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid schedule id']);
    exit();
}

try {
    $stmt = $pdo->prepare("\n        SELECT sch.ScheduleID, sch.Sched_Date, sch.Time_Start, sch.Time_End,\n               s.StudioName, a.Avail_Name AS availability, c.Name AS client_name\n        FROM schedules sch\n        JOIN studios s ON sch.StudioID = s.StudioID\n        JOIN avail_stats a ON sch.Avail_StatsID = a.Avail_StatsID\n        LEFT JOIN bookings b ON sch.ScheduleID = b.ScheduleID\n        LEFT JOIN clients c ON b.ClientID = c.ClientID\n        WHERE sch.ScheduleID = :id AND s.OwnerID = :ownerId\n    ");
    $stmt->execute([':id' => $scheduleId, ':ownerId' => $ownerId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found']);
        exit();
    }

    // Normalize fields for frontend
    echo json_encode([
        'success' => true,
        'ScheduleID' => (int)$data['ScheduleID'],
        'StudioName' => $data['StudioName'],
        'Sched_Date' => $data['Sched_Date'],
        'Time_Start' => $data['Time_Start'],
        'Time_End' => $data['Time_End'],
        'availability' => $data['availability'],
        'client_name' => $data['client_name'] ?? null
    ]);
    exit();
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit();
}
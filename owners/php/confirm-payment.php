<?php
session_start();
require_once '../../shared/config/db pdo.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header("Location: ../../auth/php/login.php");
    exit();
}

$ownerId = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['bookingId']) && isset($data['clientId'])) {
    $bookingId = $data['bookingId'];
    $clientId = $data['clientId'];
    $message = $data['message'];

    try {
        // Check if payment record exists, if not, create one
        $checkStmt = $pdo->prepare("SELECT * FROM payment WHERE BookingID = ?");
        $checkStmt->execute([$bookingId]);
        $payment = $checkStmt->fetch();

        if ($payment) {
            // Update existing payment to Completed
            $updateStmt = $pdo->prepare("UPDATE payment SET Pay_Stats = 'Completed', Pay_Date = NOW() WHERE BookingID = ?");
            $updateStmt->execute([$bookingId]);
        } else {
            // Insert new payment record as Completed
            $insertStmt = $pdo->prepare("INSERT INTO payment (BookingID, Amount, Pay_Stats, Pay_Date) VALUES (?, 0, 'Completed', NOW())");
            $insertStmt->execute([$bookingId]);
        }

        // Insert notification for the client
        $notifyStmt = $pdo->prepare("INSERT INTO notifications (OwnerID, ClientID, Type, Message, Created_At) VALUES (?, ?, 'payment_confirmation', ?, NOW())");
        $notifyStmt->execute([$ownerId, $clientId, $message]);

        echo json_encode(['success' => true, 'message' => 'Payment confirmed']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
}
?>

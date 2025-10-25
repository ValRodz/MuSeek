<?php
// chat_list.php (smart redirect)
include '../../shared/config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/php/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Check for the most recent conversation partner and studio
$stmt = $conn->prepare("SELECT OwnerID, ClientID, StudioID FROM chatlog
                        WHERE ? IN (OwnerID, ClientID)
                        ORDER BY ChatID DESC
                        LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $partner_id = ($row['OwnerID'] == $user_id) ? $row['ClientID'] : $row['OwnerID'];
    $studio_id = (int)($row['StudioID'] ?? 0);
    $redirect = 'chat.php?partner_id=' . (int)$partner_id;
    if ($studio_id) { $redirect .= '&studio_id=' . $studio_id; }
    header('Location: ' . $redirect);
} else {
    // If no conversations, go to the unified chat page to start a new one
    header('Location: chat.php');
}

exit();
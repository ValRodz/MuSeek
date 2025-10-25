<?php
// fetch_conversations.php - JSON API endpoint for fetching conversations
include '../../shared/config/db.php';

header('Content-Type: application/json');

// Check if owner_id is provided
if (!isset($_GET['owner_id']) || empty($_GET['owner_id'])) {
    echo json_encode(['success' => false, 'error' => 'Owner ID is required']);
    exit();
}

$user_id = (int)$_GET['owner_id'];

// Get all users the current user has chatted with
$stmt = $conn->prepare("SELECT u.ID, u.Name, u.UserType,
                        (SELECT Message FROM chat_messages
                         WHERE (SenderID = u.ID AND ReceiverID = ?) OR (SenderID = ? AND ReceiverID = u.ID)
                         ORDER BY CreatedAt DESC LIMIT 1) as last_message,
                        (SELECT CreatedAt FROM chat_messages
                         WHERE (SenderID = u.ID AND ReceiverID = ?) OR (SenderID = ? AND ReceiverID = u.ID)
                         ORDER BY CreatedAt DESC LIMIT 1) as last_message_time,
                        (SELECT COUNT(*) FROM chat_messages
                         WHERE SenderID = u.ID AND ReceiverID = ? AND IsRead = 0) as unread_count
                        FROM users u
                        WHERE u.ID IN (
                            SELECT DISTINCT
                            CASE
                                WHEN SenderID = ? THEN ReceiverID
                                ELSE SenderID
                            END as contact_id
                            FROM chat_messages
                            WHERE SenderID = ? OR ReceiverID = ?
                        )
                        ORDER BY last_message_time DESC");
$stmt->bind_param("iiiiiiii", $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
$stmt->execute();
$conversations_result = $stmt->get_result();
$conversations = [];
while ($row = $conversations_result->fetch_assoc()) {
    // Sanitize data for JSON output
    $conversations[] = [
        'ID' => $row['ID'],
        'Name' => htmlspecialchars($row['Name']),
        'UserType' => $row['UserType'],
        'last_message' => htmlspecialchars($row['last_message'] ?? ''),
        'last_message_time' => $row['last_message_time'] ?? date('Y-m-d H:i:s'),
        'unread_count' => (int)$row['unread_count']
    ];
}

// Return JSON response
echo json_encode(['success' => true, 'conversations' => $conversations]);
$conn->close();
?>
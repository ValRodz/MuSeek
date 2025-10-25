<?php
// Include the database connection (PDO)
require_once __DIR__ . '/../../shared/config/db pdo.php';

// Set headers for JSON response
header('Content-Type: application/json');

// Get owner ID
$ownerId = isset($_GET['owner_id']) ? intval($_GET['owner_id']) : 0;

// Validate inputs
if ($ownerId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid owner ID']);
    exit;
}

try {
    // Count unread messages for the owner (messages from clients)
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM chatlog WHERE OwnerID = ? AND Sender_Type = 'Client'");
    $stmt->execute([$ownerId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'count' => intval($result['count'])
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
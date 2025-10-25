<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'owner') {
    header('Location: ../../auth/php/login.php');
    exit;
}
$ownerId = (int)$_SESSION['user_id'];

include '../../shared/config/db pdo.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$return = isset($_GET['return']) ? $_GET['return'] : 'settings_netflix.php#chatbot';
if ($id <= 0) {
    $_SESSION['chatbot_error_message'] = 'Invalid FAQ ID.';
    header('Location: ' . $return);
    exit;
}

try {
    $stmt = $pdo->prepare('DELETE FROM studio_chatbot_faq WHERE id = ? AND owner_id = ?');
    $stmt->execute([$id, $ownerId]);
    if ($stmt->rowCount() === 0) {
        $_SESSION['chatbot_error_message'] = 'FAQ not found or you do not have permission.';
    } else {
        $_SESSION['chatbot_success_message'] = 'FAQ removed.';
    }
} catch (PDOException $e) {
    $_SESSION['chatbot_error_message'] = 'Failed to remove FAQ: ' . $e->getMessage();
}

header('Location: ' . $return);
exit;
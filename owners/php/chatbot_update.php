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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $q = isset($_POST['question']) ? trim($_POST['question']) : '';
    $a = isset($_POST['answer']) ? trim($_POST['answer']) : '';
    if ($id <= 0) {
        $_SESSION['chatbot_error_message'] = 'Invalid FAQ ID.';
        header('Location: ' . $return);
        exit;
    }
    if ($q === '' && $a === '') {
        $_SESSION['chatbot_error_message'] = 'Provide a Question and/or Answer to update.';
        header('Location: ' . $return);
        exit;
    }
    try {
        if ($q !== '' && $a !== '') {
            $stmt = $pdo->prepare('UPDATE studio_chatbot_faq SET question = ?, answer = ? WHERE id = ? AND owner_id = ?');
            $stmt->execute([$q, $a, $id, $ownerId]);
        } elseif ($q !== '') {
            $stmt = $pdo->prepare('UPDATE studio_chatbot_faq SET question = ? WHERE id = ? AND owner_id = ?');
            $stmt->execute([$q, $id, $ownerId]);
        } elseif ($a !== '') {
            $stmt = $pdo->prepare('UPDATE studio_chatbot_faq SET answer = ? WHERE id = ? AND owner_id = ?');
            $stmt->execute([$a, $id, $ownerId]);
        }
        $_SESSION['chatbot_success_message'] = 'FAQ updated successfully.';
    } catch (PDOException $e) {
        $_SESSION['chatbot_error_message'] = 'Failed to update FAQ: ' . $e->getMessage();
    }
    header('Location: ' . $return);
    exit;
}

// GET: render a minimal edit form
if ($id <= 0) {
    $_SESSION['chatbot_error_message'] = 'Invalid FAQ ID.';
    header('Location: ' . $return);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT question, answer FROM studio_chatbot_faq WHERE id = ? AND owner_id = ?');
    $stmt->execute([$id, $ownerId]);
    $faq = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$faq) {
        $_SESSION['chatbot_error_message'] = 'FAQ not found or you do not have permission.';
        header('Location: ' . $return);
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['chatbot_error_message'] = 'Failed to load FAQ: ' . $e->getMessage();
    header('Location: ' . $return);
    exit;
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Edit FAQ</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/style.css">
  <style>
    body { background:#141414; color:#e5e5e5; font-family: Arial, sans-serif; max-width: 720px; margin: 24px auto; }
    .card { background:#121212; border:1px solid #333; border-radius:12px; padding:20px; }
    .form-group { margin-bottom: 14px; }
    .form-label { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color:#9aa0a6; }
    .form-control { background:#1b1b1b; color:#fff; border:1px solid #333; border-radius:8px; padding:10px; width:100%; }
    .btn { border-radius:8px; padding:10px 14px; }
    .btn-primary { background:#e50914; color:#fff; border: none; }
    .btn-secondary { background:#303030; color:#fff; border: 1px solid #333; }
    .header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
  </style>
</head>
<body>
  <div class="card">
    <div class="header">
      <h3><i class="fas fa-robot"></i> Edit Chatbot FAQ</h3>
      <a class="btn btn-secondary" href="<?= htmlspecialchars($return, ENT_QUOTES) ?>">Back</a>
    </div>
    <form method="post">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <div class="form-group">
        <label class="form-label">Question</label>
        <input class="form-control" name="question" value="<?= htmlspecialchars($faq['question']) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Answer</label>
        <textarea class="form-control" name="answer" rows="4" required><?= htmlspecialchars($faq['answer']) ?></textarea>
      </div>
      <button type="submit" class="btn btn-primary">Save Changes</button>
    </form>
  </div>
</body>
</html>
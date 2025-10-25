<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
// If not owner, avoid redirecting away when embedded; show inline message
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'owner') {
    if (defined('CHATBOT_EMBED')) {
        echo '<div class="alert alert-danger fade-in"><i class="fas fa-exclamation-triangle"></i> You must be logged in as an owner to access Chatbot settings. <a href="../../auth/php/login.php">Login</a></div>';
        return;
    }
    header('Location: ../../auth/php/login.php');
    exit;
}
$ownerId = (int)$_SESSION['user_id'];

include '../../shared/config/db pdo.php';

$embed = defined('CHATBOT_EMBED');

// Chatbot messages (read & clear from session only in standalone)
$chatbot_error = '';
$chatbot_success = '';
if (!$embed) {
    if (isset($_SESSION['chatbot_error_message'])) {
        $chatbot_error = $_SESSION['chatbot_error_message'];
        unset($_SESSION['chatbot_error_message']);
    }
    if (isset($_SESSION['chatbot_success_message'])) {
        $chatbot_success = $_SESSION['chatbot_success_message'];
        unset($_SESSION['chatbot_success_message']);
    }
}

$handled = false;

// Auto-create table when missing
function ensureChatbotFaqTable(PDO $pdo) {
    global $chatbot_error;
    $sql = "CREATE TABLE IF NOT EXISTS studio_chatbot_faq (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        owner_id INT UNSIGNED NOT NULL,
        studio_id INT UNSIGNED NOT NULL,
        question VARCHAR(255) NOT NULL,
        answer TEXT NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_owner_studio (owner_id, studio_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    try {
        $pdo->exec($sql);
        // Ensure studio_id exists on pre-existing tables
        $colCheck = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'studio_chatbot_faq' AND COLUMN_NAME = 'studio_id'");
        $colCheck->execute();
        if ($colCheck->rowCount() === 0) {
            $pdo->exec("ALTER TABLE studio_chatbot_faq ADD COLUMN studio_id INT UNSIGNED NOT NULL AFTER owner_id");
        }
        // Ensure composite index exists
        try { $pdo->exec("ALTER TABLE studio_chatbot_faq ADD INDEX idx_owner_studio (owner_id, studio_id)"); } catch (PDOException $e) {}
        return true;
    } catch (PDOException $e) {
        $chatbot_error = "Failed ensuring chatbot table: " . $e->getMessage();
        return false;
    }
}
ensureChatbotFaqTable($pdo);

// Studios for current owner (used for filter and create form)
$studios = [];
$studioNames = [];
try {
    $sStmt = $pdo->prepare("SELECT StudioID, StudioName FROM studios WHERE OwnerID = ? ORDER BY StudioName ASC");
    $sStmt->execute([$ownerId]);
    $studios = $sStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($studios as $s) { $studioNames[(int)$s['StudioID']] = $s['StudioName']; }
} catch (PDOException $e) { /* ignore */ }
$selectedStudioId = isset($_GET['studio']) ? (int)$_GET['studio'] : 0;
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$viewAll = isset($_GET['view']) && $_GET['view'] === 'all';
$basePage = $embed ? 'settings_netflix.php' : 'manage_chatbot.php';
$qsBase = [];
if ($searchTerm !== '') { $qsBase['search'] = $searchTerm; }
if ($selectedStudioId > 0) { $qsBase['studio'] = $selectedStudioId; }
$qsViewMore = $qsBase;
if (!$viewAll) { $qsViewMore['view'] = 'all'; }
$viewToggleUrl = $basePage . (count($qsViewMore) ? ('?' . http_build_query($qsViewMore)) : '') . ($embed ? '#chatbot' : '');
$showFiveUrl = $basePage . (count($qsBase) ? ('?' . http_build_query($qsBase)) : '') . ($embed ? '#chatbot' : '');

// Helper: redirect with JS alert + sessionStorage in embed, else alert + script redirect
function chatbotRedirectMsg($type, $text, $target, $embed) {
    $type = ($type === 'error') ? 'error' : 'success';
    $prefix = ($type === 'error') ? 'Error: ' : 'Success: ';
    if ($embed) {
        $payload = json_encode(['type' => $type, 'text' => $text]);
        $alertMsg = json_encode($prefix . $text);
        echo "<script>(function(){try{sessionStorage.setItem('chatbot_flash', $payload);}catch(e){} try{alert(" . $alertMsg . ");}catch(e){} window.location.replace('" . htmlspecialchars($target, ENT_QUOTES) . "');})();</script>";
        exit;
    } else {
        if ($type === 'error') {
            $_SESSION['chatbot_error_message'] = $text;
        } else {
            $_SESSION['chatbot_success_message'] = $text;
        }
        $alertMsg = json_encode($prefix . $text);
        echo "<script>(function(){try{alert(" . $alertMsg . ");}catch(e){} window.location.replace('" . htmlspecialchars($target, ENT_QUOTES) . "');})();</script>";
        exit;
    }
}

// Handle create/update/toggle actions with error messaging
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $target = $embed ? 'settings_netflix.php#chatbot' : 'manage_chatbot.php';

    if ($action === 'create') {
        $q = trim($_POST['question'] ?? '');
        $a = trim($_POST['answer'] ?? '');
        $studioId = (int)($_POST['studio_id'] ?? 0);
        if ($q === '' || $a === '') {
            chatbotRedirectMsg('error', 'Question and Answer are required to add a FAQ.', $target, $embed);
        }
        if ($studioId <= 0) {
            chatbotRedirectMsg('error', 'Please select a Studio for this FAQ.', $target, $embed);
        }
        // Validate studio belongs to owner
        try {
            $chk = $pdo->prepare("SELECT 1 FROM studios WHERE OwnerID = ? AND StudioID = ?");
            $chk->execute([$ownerId, $studioId]);
            if ($chk->rowCount() === 0) {
                chatbotRedirectMsg('error', 'Invalid studio selection.', $target, $embed);
            }
        } catch (PDOException $e) {
            chatbotRedirectMsg('error', 'Studio validation failed: ' . $e->getMessage(), $target, $embed);
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO studio_chatbot_faq (owner_id, studio_id, question, answer) VALUES (?, ?, ?, ?)");
            $stmt->execute([$ownerId, $studioId, $q, $a]);
            chatbotRedirectMsg('success', 'FAQ added successfully.', $target, $embed);
        } catch (PDOException $e) {
            chatbotRedirectMsg('error', 'Failed to add FAQ: ' . $e->getMessage(), $target, $embed);
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            chatbotRedirectMsg('error', 'Invalid FAQ ID for toggle.', $target, $embed);
        }
        try {
            $stmt = $pdo->prepare("UPDATE studio_chatbot_faq SET is_active = 1 - is_active WHERE id = ? AND owner_id = ?");
            $stmt->execute([$id, $ownerId]);
            chatbotRedirectMsg('success', 'FAQ status updated.', $target, $embed);
        } catch (PDOException $e) {
            chatbotRedirectMsg('error', 'Failed to toggle FAQ: ' . $e->getMessage(), $target, $embed);
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $q = trim($_POST['question'] ?? '');
        $a = trim($_POST['answer'] ?? '');
        if ($id <= 0) {
            chatbotRedirectMsg('error', 'Invalid FAQ ID for update.', $target, $embed);
        }
        if ($q === '' && $a === '') {
            chatbotRedirectMsg('error', 'Provide a Question and/or Answer to update.', $target, $embed);
        }
        try {
            if ($q !== '' && $a !== '') {
                $stmt = $pdo->prepare("UPDATE studio_chatbot_faq SET question = ?, answer = ? WHERE id = ? AND owner_id = ?");
                $stmt->execute([$q, $a, $id, $ownerId]);
            } elseif ($q !== '') {
                $stmt = $pdo->prepare("UPDATE studio_chatbot_faq SET question = ? WHERE id = ? AND owner_id = ?");
                $stmt->execute([$q, $id, $ownerId]);
            } elseif ($a !== '') {
                $stmt = $pdo->prepare("UPDATE studio_chatbot_faq SET answer = ? WHERE id = ? AND owner_id = ?");
                $stmt->execute([$a, $id, $ownerId]);
            }
            chatbotRedirectMsg('success', 'FAQ updated successfully.', $target, $embed);
        } catch (PDOException $e) {
            chatbotRedirectMsg('error', 'Failed to update FAQ: ' . $e->getMessage(), $target, $embed);
        }
    }
}

try {
    if ($selectedStudioId > 0) {
        $faqsStmt = $pdo->prepare("SELECT id, studio_id, question, is_active, sort_order FROM studio_chatbot_faq WHERE owner_id = ? AND studio_id = ? ORDER BY sort_order ASC, id ASC");
        $faqsStmt->execute([$ownerId, $selectedStudioId]);
    } else {
        $faqsStmt = $pdo->prepare("SELECT id, studio_id, question, is_active, sort_order FROM studio_chatbot_faq WHERE owner_id = ? ORDER BY sort_order ASC, id ASC");
        $faqsStmt->execute([$ownerId]);
    }
    $faqs = $faqsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $faqs = [];
    $chatbot_error = 'Failed to load FAQs: ' . $e->getMessage();
}

if (!$embed): ?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Manage Chatbot FAQ</title>
  <style>
    :root { --bg:#0f0f0f; --card:#121212; --head:#181818; --border:#333; --text:#e5e5e5; --muted:#9aa0a6; }
    body { font-family: Arial, sans-serif; max-width: 980px; margin: 24px auto; background: var(--bg); color: var(--text); }
    h2 { margin-bottom: 6px; }
    p { color: var(--muted); margin-top: 0; }
    form { margin: 12px 0; }
    .form-group { margin-bottom: 10px; }
    .form-label { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
    .form-control { background: var(--card); color: var(--text); border: 1px solid var(--border); border-radius: 6px; padding: 8px 10px; }
    .btn { border-radius: 6px; padding: 8px 12px; }
    .btn-primary { background:#e50914; color:#fff; border: none; }
    .btn-secondary { background:#303030; color:#fff; border: 1px solid var(--border); }
    .btn-danger { background:#b00020; color:#fff; border: none; }
    table { width: 100%; border-collapse: separate; border-spacing: 0; background: var(--card); border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
    thead th { background: var(--head); text-transform: uppercase; font-size: 12px; letter-spacing: .06em; padding: 12px; border-bottom: 1px solid var(--border); }
    tbody td { padding: 12px; border-top: 1px solid var(--border); }
    tbody tr:hover { background: rgba(255,255,255,.04); }
    .row-actions form { display: inline-block; margin-right: 8px; }
    .inline { display: inline-block; }
    .small { width: 320px; }
    .status-pill { display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; }
    .status-pill.is-active { background: rgba(46,204,113,.15); color:#2ecc71; border:1px solid rgba(46,204,113,.35); }
    .status-pill.is-inactive { background: rgba(231,76,60,.15); color:#e74c3c; border:1px solid rgba(231,76,60,.35); }
    .toolbar { display:flex; align-items:center; gap:10px; margin: 12px 0; }
  </style>
</head>
<body>
  <h2>Chatbot FAQ</h2>
  <p>Manage guided chatbot questions, answers, and studio association.</p>

  <div class="toolbar">
    <form id="searchFilterForm" method="get" class="inline">
      <input class="form-control small" type="text" name="search" value="<?= htmlspecialchars($searchTerm) ?>" placeholder="Search FAQs">
      <select class="form-control" name="studio">
        <option value="0" <?= $selectedStudioId === 0 ? 'selected' : '' ?>>All Studios</option>
        <?php foreach ($studios as $s): $sid=(int)$s['StudioID']; ?>
          <option value="<?= $sid ?>" <?= ($selectedStudioId === $sid) ? 'selected' : '' ?>><?= htmlspecialchars($s['StudioName']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-secondary">Apply</button>
    </form>
    <a class="btn btn-secondary" href="<?= $viewAll ? $showFiveUrl : $viewToggleUrl ?>"><?= $viewAll ? 'Show 5' : 'View more' ?></a>
  </div>

  <?php if ($chatbot_error): ?>
    <div class="alert alert-danger fade-in">
      <i class="fas fa-exclamation-triangle"></i>
      <?php echo htmlspecialchars($chatbot_error); ?>
    </div>
  <?php endif; ?>

  <?php if ($chatbot_success): ?>
    <div class="alert alert-success fade-in">
      <i class="fas fa-check-circle"></i>
      <?php echo htmlspecialchars($chatbot_success); ?>
    </div>
  <?php endif; ?>

  <form method="post" onsubmit="return window.chatbotConfirm(this)">
    <input type="hidden" name="action" value="create">
    <div class="form-group">
      <label class="form-label">Studio *</label>
      <select name="studio_id" class="form-control" required>
        <?php foreach ($studios as $s): $sid=(int)$s['StudioID']; ?>
          <option value="<?= $sid ?>" <?= ($selectedStudioId>0 && $selectedStudioId===$sid) ? 'selected' : '' ?>><?= htmlspecialchars($s['StudioName']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Question *</label>
      <input class="form-control small" name="question" placeholder="e.g., What are your operating hours?" required>
    </div>
    <div class="form-group">
      <label class="form-label">Answer *</label>
      <textarea class="form-control" name="answer" placeholder="Provide the answer clients should see" required rows="3"></textarea>
    </div>
    <button type="submit" class="btn btn-primary">
      <i class="fas fa-plus-circle"></i>
      Add FAQ
    </button>
  </form>

  <hr>

  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Studio</th>
        <th>Question</th>
        <th>Status</th>
        <th>Sort</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($faqs as $f): ?>
      <tr>
        <td><?= (int)$f['id'] ?></td>
        <td><?= htmlspecialchars($studioNames[(int)($f['studio_id'] ?? 0)] ?? 'Unknown') ?></td>
        <td><?= htmlspecialchars($f['question']) ?></td>
        <td><span class="status-pill <?= $f['is_active'] ? 'is-active' : 'is-inactive' ?>"><?= $f['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td><?= (int)$f['sort_order'] ?></td>
        <td class="row-actions">
          <?php $returnTarget = $embed ? 'settings_netflix.php#chatbot' : 'manage_chatbot.php'; ?>
          <a class="btn btn-secondary" href="chatbot_activate.php?id=<?= (int)$f['id'] ?>&return=<?= urlencode($returnTarget) ?>">
            <?= $f['is_active'] ? 'Deactivate' : 'Activate' ?>
          </a>
          <a class="btn btn-primary inline" href="chatbot_update.php?id=<?= (int)$f['id'] ?>&return=<?= urlencode($returnTarget) ?>">Update</a>
          <a class="btn btn-danger inline" href="chatbot_remove.php?id=<?= (int)$f['id'] ?>&return=<?= urlencode($returnTarget) ?>" onclick="return confirm('Remove this FAQ?');">Remove</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<script>
  // Confirm helper available globally for inline handlers
  window.chatbotConfirm = function(form){
    var actionInput = form.querySelector('input[name="action"]');
    var action = actionInput ? String(actionInput.value || '').toLowerCase() : '';
    var submitBtn = form.querySelector('button[type="submit"]');
    var btnText = submitBtn ? (submitBtn.textContent || '').trim() : '';
    var msg = 'Are you sure you want to proceed?';
    if (action === 'create') {
      msg = 'Add this FAQ to your chatbot?';
    } else if (action === 'toggle') {
      msg = 'Confirm ' + (btnText ? btnText.toLowerCase() : 'toggle') + ' this FAQ?';
    } else if (action === 'update') {
      msg = 'Update this FAQ with the provided changes?';
    }
    return confirm(msg);
  };
  // Studio filter navigation
  (function(){
    var filterEl = document.getElementById('studioFilter');
    if (!filterEl) return;
    var embedded = <?= $embed ? 'true' : 'false' ?>;
    filterEl.addEventListener('change', function(){
      var base = embedded ? 'settings_netflix.php' : 'manage_chatbot.php';
      var value = this.value;
      var qs = (value && value !== '0') ? ('?studio=' + encodeURIComponent(value)) : '';
      var hash = embedded ? '#chatbot' : '';
      window.location.href = base + qs + hash;
    });
  })();
</script>
</body>
</html>
<?php else: ?>
    <div class="settings-card" id="chatbot">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-robot"></i> Chatbot FAQ</h3>
            <p class="card-subtitle">Define questions and answers clients can browse.</p>
        </div>
        <style>
            #chatbot .small { width: 320px; }
            #chatbot .inline { display: inline-block; }
            #chatbot .row-actions form { display: inline-block; margin-right: 8px; }
            #chatbot table { width: 100%; border-collapse: separate; border-spacing:0; background: var(--card-bg, #121212); color: var(--text-primary, #e5e5e5); border: 1px solid var(--border, #333); border-radius: 8px; overflow: hidden; }
            #chatbot thead th { background: var(--table-head-bg, #181818); text-transform: uppercase; font-size: 12px; letter-spacing: .06em; padding: 12px; border-bottom: 1px solid var(--border, #333); }
            #chatbot tbody td { padding: 12px; border-top: 1px solid var(--border, #333); }
            #chatbot tbody tr:hover { background: rgba(255,255,255,.04); }
            #chatbot .status-pill { display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; }
            #chatbot .status-pill.is-active { background: rgba(46,204,113,.15); color:#2ecc71; border:1px solid rgba(46,204,113,.35); }
            #chatbot .status-pill.is-inactive { background: rgba(231,76,60,.15); color:#e74c3c; border:1px solid rgba(231,76,60,.35); }
            #chatbot .btn { border-radius:6px; }
            #chatbot .toolbar { display:flex; align-items:center; gap:10px; margin: 12px 0; }
        </style>

        <div class="toolbar">
            <label class="form-label" for="studioFilter">Filter by Studio</label>
            <select id="studioFilter" class="form-control">
                <option value="0" <?= $selectedStudioId === 0 ? 'selected' : '' ?>>All Studios</option>
                <?php foreach ($studios as $s): $sid=(int)$s['StudioID']; ?>
                    <option value="<?= $sid ?>" <?= ($selectedStudioId === $sid) ? 'selected' : '' ?>><?= htmlspecialchars($s['StudioName']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($chatbot_error): ?>
            <div class="alert alert-danger fade-in">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($chatbot_error); ?>
            </div>
        <?php endif; ?>

        <?php if ($chatbot_success): ?>
            <div class="alert alert-success fade-in">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($chatbot_success); ?>
            </div>
        <?php endif; ?>

        <form method="post" onsubmit="return window.chatbotConfirm(this)">
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label class="form-label">Studio *</label>
                <select class="form-control" name="studio_id" required>
                    <?php foreach ($studios as $s): $sid=(int)$s['StudioID']; ?>
                        <option value="<?= $sid ?>" <?= ($selectedStudioId>0 && $selectedStudioId===$sid) ? 'selected' : '' ?>><?= htmlspecialchars($s['StudioName']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Question *</label>
                <input class="form-control small" name="question" placeholder="e.g., What are your operating hours?" required>
            </div>
            <div class="form-group">
                <label class="form-label">Answer *</label>
                <textarea class="form-control" name="answer" placeholder="Provide the answer clients should see" required rows="3"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i>
                Add FAQ
            </button>
        </form>

        <hr style="border-color:#333;">

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Studio</th>
                    <th>Question</th>
                    <th>Status</th>
                    <th>Sort</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($faqs as $f): ?>
                <tr>
                    <td><?= (int)$f['id'] ?></td>
                    <td><?= htmlspecialchars($studioNames[(int)($f['studio_id'] ?? 0)] ?? 'Unknown') ?></td>
                    <td><?= htmlspecialchars($f['question']) ?></td>
                    <td><span class="status-pill <?= $f['is_active'] ? 'is-active' : 'is-inactive' ?>"><?= $f['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td><?= (int)$f['sort_order'] ?></td>
                    <td class="row-actions">
                        <?php $returnTarget = $embed ? 'settings_netflix.php#chatbot' : 'manage_chatbot.php'; ?>
                        <a class="btn btn-secondary" href="chatbot_activate.php?id=<?= (int)$f['id'] ?>&return=<?= urlencode($returnTarget) ?>">
                            <?= $f['is_active'] ? 'Deactivate' : 'Activate' ?>
                        </a>
                        <a class="btn btn-primary inline" href="chatbot_update.php?id=<?= (int)$f['id'] ?>&return=<?= urlencode($returnTarget) ?>">Update</a>
                        <a class="btn btn-danger inline" href="chatbot_remove.php?id=<?= (int)$f['id'] ?>&return=<?= urlencode($returnTarget) ?>" onclick="return confirm('Remove this FAQ?');">Remove</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <script>
            // Confirm helper available globally for inline handlers
            window.chatbotConfirm = function(form){
                var actionInput = form.querySelector('input[name="action"]');
                var action = actionInput ? String(actionInput.value || '').toLowerCase() : '';
                var submitBtn = form.querySelector('button[type="submit"]');
                var btnText = submitBtn ? (submitBtn.textContent || '').trim() : '';
                var msg = 'Are you sure you want to proceed?';
                if (action === 'create') {
                    msg = 'Add this FAQ to your chatbot?';
                } else if (action === 'toggle') {
                    msg = 'Confirm ' + (btnText ? btnText.toLowerCase() : 'toggle') + ' this FAQ?';
                } else if (action === 'update') {
                    msg = 'Update this FAQ with the provided changes?';
                }
                return confirm(msg);
            };
            // Studio filter navigation
            (function(){
                var filterEl = document.getElementById('studioFilter');
                if (!filterEl) return;
                var embedded = <?= $embed ? 'true' : 'false' ?>;
                filterEl.addEventListener('change', function(){
                    var base = embedded ? 'settings_netflix.php' : 'manage_chatbot.php';
                    var value = this.value;
                    var qs = (value && value !== '0') ? ('?studio=' + encodeURIComponent(value)) : '';
                    var hash = embedded ? '#chatbot' : '';
                    window.location.href = base + qs + hash;
                });
            })();
        </script>
    </div>
<?php endif; ?>
<?php
session_start();
include '../../shared/config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

$content = isset($_POST['content']) ? trim($_POST['content']) : '';
$owner_id = isset($_POST['owner_id']) ? (int)$_POST['owner_id'] : 0;
$client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
$sender_type = isset($_POST['sender_type']) ? $_POST['sender_type'] : '';
$content = isset($_POST['content']) ? trim($_POST['content']) : '';
$studio_id = isset($_POST['studio_id']) ? (int)$_POST['studio_id'] : 0;

if (!$content || !$owner_id || !$client_id) {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit;
}

if (($user_type === 'client' && $user_id !== $client_id) ||
    ($user_type === 'owner' && $user_id !== $owner_id)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$sender_type = ($user_type === 'owner') ? 'Owner' : 'Client';

// Detect if this is the client's first message (owner-client pair)
$isClientFirst = false;
if ($sender_type === 'Client') {
    if ($studio_id > 0) {
        $stmt0 = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM chatlog WHERE OwnerID = ? AND ClientID = ? AND StudioID = ? AND Sender_Type = 'Client' AND DATE(Timestamp) = CURDATE()");
        mysqli_stmt_bind_param($stmt0, 'iii', $owner_id, $client_id, $studio_id);
    } else {
        $stmt0 = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM chatlog WHERE OwnerID = ? AND ClientID = ? AND Sender_Type = 'Client' AND DATE(Timestamp) = CURDATE()");
        mysqli_stmt_bind_param($stmt0, 'ii', $owner_id, $client_id);
    }
    mysqli_stmt_execute($stmt0);
    $res0 = mysqli_stmt_get_result($stmt0);
    if ($res0 && ($row0 = mysqli_fetch_assoc($res0))) {
        $isClientFirst = ((int)$row0['cnt'] === 0);
    }
    mysqli_stmt_close($stmt0);
}

$query = $studio_id > 0
    ? "INSERT INTO chatlog (OwnerID, ClientID, StudioID, Timestamp, Content, Sender_Type) VALUES (?, ?, ?, NOW(), ?, ?)"
    : "INSERT INTO chatlog (OwnerID, ClientID, Timestamp, Content, Sender_Type) VALUES (?, ?, NOW(), ?, ?)";
$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . mysqli_error($conn)]);
    exit;
}

if ($studio_id > 0) {
    mysqli_stmt_bind_param($stmt, 'iiiss', $owner_id, $client_id, $studio_id, $content, $sender_type);
} else {
    mysqli_stmt_bind_param($stmt, 'iiss', $owner_id, $client_id, $content, $sender_type);
}
$success = mysqli_stmt_execute($stmt);

if ($success) {
    // Auto-reply: send Good Day only on the client's first message of the day
    if ($sender_type === 'Client' && $isClientFirst) {
        $sender_type_sys = 'System';
        $sysText = 'Good Day! How may I be of service today?';
        $stmtSys = mysqli_prepare($conn, $studio_id > 0
            ? "INSERT INTO chatlog (OwnerID, ClientID, StudioID, Timestamp, Content, Sender_Type) VALUES (?, ?, ?, NOW(), ?, ?)"
            : "INSERT INTO chatlog (OwnerID, ClientID, Timestamp, Content, Sender_Type) VALUES (?, ?, NOW(), ?, ?)"
        );
        if ($stmtSys) {
            if ($studio_id > 0) {
                mysqli_stmt_bind_param($stmtSys, 'iiiss', $owner_id, $client_id, $studio_id, $sysText, $sender_type_sys);
            } else {
                mysqli_stmt_bind_param($stmtSys, 'iiss', $owner_id, $client_id, $sysText, $sender_type_sys);
            }
            mysqli_stmt_execute($stmtSys);
            mysqli_stmt_close($stmtSys);
        }
    }

    // If client replies to the chatbot, send a follow-up wait message
    if ($sender_type === 'Client') {
        $stmtPrev = mysqli_prepare($conn, $studio_id > 0
            ? "SELECT ChatID, Sender_Type, Content, Timestamp FROM chatlog WHERE OwnerID = ? AND ClientID = ? AND StudioID = ? ORDER BY ChatID DESC LIMIT 2"
            : "SELECT ChatID, Sender_Type, Content, Timestamp FROM chatlog WHERE OwnerID = ? AND ClientID = ? ORDER BY ChatID DESC LIMIT 2"
        );
        if ($stmtPrev) {
            if ($studio_id > 0) {
                mysqli_stmt_bind_param($stmtPrev, 'iii', $owner_id, $client_id, $studio_id);
            } else {
                mysqli_stmt_bind_param($stmtPrev, 'ii', $owner_id, $client_id);
            }
            mysqli_stmt_execute($stmtPrev);
            $resPrev = mysqli_stmt_get_result($stmtPrev);
            $rowsPrev = [];
            while ($r = mysqli_fetch_assoc($resPrev)) { $rowsPrev[] = $r; }
            mysqli_stmt_close($stmtPrev);
            if (count($rowsPrev) >= 2) {
                $prev = $rowsPrev[1];
                if (strtolower(trim($prev['Sender_Type'])) === 'system' && stripos($prev['Content'], 'Good Day') !== false) {
                    // Guard: avoid duplicates for this Good Day
                    $stmtChk = mysqli_prepare($conn, $studio_id > 0
                        ? "SELECT COUNT(*) AS cnt FROM chatlog WHERE OwnerID = ? AND ClientID = ? AND StudioID = ? AND Sender_Type = 'System' AND Content LIKE 'Please wait for a while,%' AND Timestamp > ?"
                        : "SELECT COUNT(*) AS cnt FROM chatlog WHERE OwnerID = ? AND ClientID = ? AND Sender_Type = 'System' AND Content LIKE 'Please wait for a while,%' AND Timestamp > ?"
                    );
                    if ($stmtChk) {
                        if ($studio_id > 0) {
                            mysqli_stmt_bind_param($stmtChk, 'iiis', $owner_id, $client_id, $studio_id, $prev['Timestamp']);
                        } else {
                            mysqli_stmt_bind_param($stmtChk, 'iis', $owner_id, $client_id, $prev['Timestamp']);
                        }
                        mysqli_stmt_execute($stmtChk);
                        $resChk = mysqli_stmt_get_result($stmtChk);
                        $rowChk = $resChk ? mysqli_fetch_assoc($resChk) : null;
                        mysqli_stmt_close($stmtChk);
                        $alreadyWait = $rowChk && ((int)$rowChk['cnt'] > 0);
                    } else {
                        $alreadyWait = false;
                    }

                    if (!$alreadyWait) {
                        $waitText = 'Please wait for a while, the studio owner will message you shortly.';
                        $stmtWait = mysqli_prepare($conn, $studio_id > 0
                            ? "INSERT INTO chatlog (OwnerID, ClientID, StudioID, Timestamp, Content, Sender_Type) VALUES (?, ?, ?, NOW(), ?, 'System')"
                            : "INSERT INTO chatlog (OwnerID, ClientID, Timestamp, Content, Sender_Type) VALUES (?, ?, NOW(), ?, 'System')"
                        );
                        if ($stmtWait) {
                            if ($studio_id > 0) {
                                mysqli_stmt_bind_param($stmtWait, 'iiis', $owner_id, $client_id, $studio_id, $waitText);
                            } else {
                                mysqli_stmt_bind_param($stmtWait, 'iis', $owner_id, $client_id, $waitText);
                            }
                            mysqli_stmt_execute($stmtWait);
                            mysqli_stmt_close($stmtWait);
                        }
                    }
                }
            }
        }
    }

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Execute failed: ' . mysqli_error($conn)]);
}

mysqli_stmt_close($stmt);

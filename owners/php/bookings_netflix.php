<?php
// Start the session to access session variables
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    // Redirect to login page if not logged in as owner
    header('Location: ../../auth/php/login.php');
    exit();
}

// Include database connection
include '../../shared/config/db pdo.php';

// Set timezone to PST
date_default_timezone_set('America/Los_Angeles');

// Get the logged-in owner's ID from session
$ownerId = $_SESSION['user_id'];

// Fetch owner information
$ownerStmt = $pdo->prepare("SELECT Name, Email FROM studio_owners WHERE OwnerID = ?");
$ownerStmt->execute([$ownerId]);
$owner = $ownerStmt->fetch();

if (!$owner) {
    session_destroy();
    header("Location: login.php");
    exit();
}

// Archive past bookings that are not confirmed (run in a transaction to avoid race conditions)
try {
    $pdo->beginTransaction();
    $archiveStmt = $pdo->prepare("
        UPDATE bookings b
        JOIN schedules s ON b.ScheduleID = s.ScheduleID
        JOIN studios st ON b.StudioID = st.StudioID
        SET b.Book_StatsID = 4 -- Archived status
        WHERE st.OwnerID = ?
        AND s.Sched_Date < CURDATE()
        AND b.Book_StatsID = 2 -- Pending status
    ");
    $archiveStmt->execute([$ownerId]);
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Archive Bookings Error: " . $e->getMessage());
}

// Get active tab (pending, completed, all, archived)
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'pending';

// Pagination settings
$itemsPerPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($currentPage - 1) * $itemsPerPage;

// Get filters
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$dateFilter = isset($_GET['date']) ? $_GET['date'] : '';

// Build the base query
$baseQuery = "
    SELECT b.BookingID, b.booking_date, c.Name as client_name, c.ClientID, c.Phone as client_phone, c.Email as client_email,
           s.StudioName, s.StudioID, sch.Time_Start, sch.Time_End, sch.Sched_Date,
           bs.Book_Stats as status, bs.Book_StatsID,
           COALESCE(p.Amount, 0) as amount, 
           COALESCE(p.Pay_Stats, 'N/A') as payment_status,
           srv.ServiceType as service_name, srv.Description as service_description,
           srv.Price as service_price,
           i.Name as instructor_name, i.InstructorID
    FROM bookings b
    JOIN clients c ON b.ClientID = c.ClientID
    JOIN studios s ON b.StudioID = s.StudioID
    JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
    JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
    LEFT JOIN payment p ON b.BookingID = p.BookingID
    LEFT JOIN services srv ON b.ServiceID = srv.ServiceID
    LEFT JOIN instructors i ON b.InstructorID = i.InstructorID
    WHERE s.OwnerID = :ownerId
";

$countBaseQuery = "
    SELECT COUNT(*) 
    FROM bookings b
    JOIN studios s ON b.StudioID = s.StudioID
    JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
    WHERE s.OwnerID = :ownerId
";

$params = [':ownerId' => $ownerId];

// Add tab-specific filters
if ($activeTab === 'pending') {
    $baseQuery .= " AND (bs.Book_Stats = 'Pending' OR bs.Book_Stats = 'Confirmed') AND sch.Sched_Date >= CURDATE()";
    $countBaseQuery .= " AND (bs.Book_Stats = 'Pending' OR bs.Book_Stats = 'Confirmed') AND b.ScheduleID IN (SELECT ScheduleID FROM schedules WHERE Sched_Date >= CURDATE())";
} elseif ($activeTab === 'completed') {
    $baseQuery .= " AND (bs.Book_Stats = 'Finished' OR (bs.Book_Stats = 'Confirmed' AND sch.Sched_Date < CURDATE()) OR p.Pay_Stats = 'Completed')";
    $countBaseQuery .= " AND (bs.Book_Stats = 'Finished' OR (bs.Book_Stats = 'Confirmed' AND b.ScheduleID IN (SELECT ScheduleID FROM schedules WHERE Sched_Date < CURDATE())) OR b.BookingID IN (SELECT BookingID FROM payment WHERE Pay_Stats = 'Completed'))";
} elseif ($activeTab === 'archived') {
    $baseQuery .= " AND bs.Book_Stats = 'Archived'";
    $countBaseQuery .= " AND bs.Book_Stats = 'Archived'";
}

// Add status filter if provided
if (!empty($statusFilter)) {
    $baseQuery .= " AND bs.Book_Stats = :status";
    $countBaseQuery .= " AND bs.Book_Stats = :status";
    $params[':status'] = $statusFilter;
}

// Add date filter if provided
if (!empty($dateFilter)) {
    $baseQuery .= " AND sch.Sched_Date = :date";
    $countBaseQuery .= " AND b.ScheduleID IN (SELECT ScheduleID FROM schedules WHERE Sched_Date = :date)";
    $params[':date'] = $dateFilter;
}

// Add order and limit
$query = $baseQuery . " ORDER BY sch.Sched_Date DESC, sch.Time_Start DESC LIMIT :offset, :limit";

// Execute the queries
$stmt = $pdo->prepare($query);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
$stmt->bindParam(':limit', $itemsPerPage, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$countStmt = $pdo->prepare($countBaseQuery);
foreach ($params as $key => $value) {
    if ($key !== ':offset' && $key !== ':limit') {
        $countStmt->bindValue($key, $value);
    }
}
$countStmt->execute();
$totalBookings = $countStmt->fetchColumn();
$totalPages = ceil($totalBookings / $itemsPerPage);

// Check for unread notifications
$notificationsStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM notifications 
    WHERE OwnerID = ? 
    AND IsRead = 0
");
$notificationsStmt->execute([$ownerId]);
$unreadNotifications = $notificationsStmt->fetchColumn();

// Fetch studios for this owner (for new booking form)
$studiosStmt = $pdo->prepare("
    SELECT StudioID, StudioName
    FROM studios
    WHERE OwnerID = ?
");
$studiosStmt->execute([$ownerId]);
$studios = $studiosStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch available instructors for the edit dropdown
$instructorsStmt = $pdo->prepare("
    SELECT InstructorID, Name 
    FROM instructors 
    WHERE OwnerID = ?
");
$instructorsStmt->execute([$ownerId]);
$instructors = $instructorsStmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function to get status badge color
function getStatusBadge($status)
{
    switch (strtolower($status)) {
        case 'confirmed':
            return '<span class="badge bg-red-600">confirmed</span>';
        case 'pending':
            return '<span class="badge bg-transparent border border-gray-500 text-gray-300">pending</span>';
        case 'cancelled':
            return '<span class="badge bg-gray-700">cancelled</span>';
        case 'archived':
            return '<span class="badge bg-blue-700">archived</span>';
        case 'finished':
            return '<span class="badge bg-purple-600">finished</span>';
        default:
            return '<span class="badge bg-gray-600">' . htmlspecialchars($status) . '</span>';
    }
}

// Helper function to get payment status badge
function getPaymentStatusBadge($status)
{
    switch (strtolower($status)) {
        case 'completed':
            return '<span class="badge bg-green-600">completed</span>';
        case 'pending':
            return '<span class="badge bg-yellow-600">pending</span>';
        case 'failed':
            return '<span class="badge bg-red-600">failed</span>';
        case 'cancelled':
            return '<span class="badge bg-gray-700">cancelled</span>';
        case 'n/a':
            return '<span class="badge bg-gray-600">N/A</span>';
        default:
            return '<span class="badge bg-gray-600">' . htmlspecialchars($status) . '</span>';
    }
}

// Helper function to get customer initials
function getInitials($name)
{
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

// Helper function to check if payment request is allowed
function canRequestPayment($bookingStatus, $paymentStatus)
{
    return (strtolower($bookingStatus) === 'pending' &&
        (strtolower($paymentStatus) === 'pending' || strtolower($paymentStatus) === 'n/a'));
}

// Helper function to check if booking confirmation is allowed
function canConfirmBooking($bookingStatus)
{
    return strtolower($bookingStatus) === 'pending';
}

// Helper function to check if payment confirmation is allowed
function canConfirmPayment($bookingStatus, $paymentStatus)
{
    return (strtolower($bookingStatus) === 'confirmed' &&
        (strtolower($paymentStatus) !== 'completed'));
}

// Helper function to check if finishing a booking is allowed
function canFinishBooking($bookingStatus, $paymentStatus)
{
    return (strtolower($bookingStatus) === 'confirmed' &&
        strtolower($paymentStatus) === 'completed');
}

// Helper function to check if cancelling a booking is allowed
function canCancelBooking($bookingStatus)
{
    return (strtolower($bookingStatus) === 'pending' || strtolower($bookingStatus) === 'confirmed');
}

// Helper function to check if archiving a booking is allowed
function canArchiveBooking($bookingStatus)
{
    return strtolower($bookingStatus) !== 'archived';
}

// Helper function to check if a date is in the past
function isDatePast($date)
{
    $currentDate = new DateTime('now', new DateTimeZone('America/Los_Angeles'));
    $schedDate = new DateTime($date, new DateTimeZone('America/Los_Angeles'));
    return $schedDate < $currentDate->setTime(0, 0, 0);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1" name="viewport" />
    <title>MuSeek - Bookings</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        body {
            font-family: "Inter", sans-serif;
        }

        .sidebar {
            display: none;
            height: 100vh;
            width: 250px;
            position: fixed;
            z-index: 40;
            top: 0;
            left: 0;
            transition: transform 0.3s ease;
        }

        .sidebar.active {
            display: block;
        }

        .main-content {
            margin-left: var(--sidebar-collapsed-width);
            min-height: 100vh;
            background: var(--netflix-black);
            transition: all 0.3s ease;
            box-sizing: border-box;
        }

        .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-block;
        }

        .avatar {
            width: 2rem;
            height: 2rem;
            border-radius: 9999px;
            background-color: #374151;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.75rem;
        }

        .booking-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .booking-table th {
            text-align: left;
            padding: 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #9ca3af;
            background-color: #0a0a0a;
            border-bottom: 1px solid #222222;
        }

        .booking-table td {
            padding: 0.75rem;
            font-size: 0.875rem;
            border-bottom: 1px solid #222222;
        }

        .booking-table tr {
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .booking-table tr:hover {
            background-color: #111111;
        }

        .date-picker-container {
            position: relative;
        }

        .date-picker-icon {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .pagination-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2.5rem;
            height: 2 .5rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            transition: background-color 0.2s;
            padding: 0 0.75rem;
            border: 1px solid #222222;
            margin: 0 0.25rem;
        }

        .pagination-button.active {
            background-color: #dc2626;
            color: white;
            border-color: #dc2626;
        }

        .pagination-button:hover:not(.active):not(:disabled) {
            background-color: #222222;
        }

        .pagination-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 50;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.7);
        }

        .modal-content {
            background-color: #0a0a0a;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #222222;
            border-radius: 0.5rem;
            width: 80%;
            max-width: 600px;
        }

        .dropdown {
            position: relative;
            display: inline-block;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            bottom: 100%;
            margin-bottom: 5px;
            background-color: #0a0a0a;
            min-width: 160px;
            box-shadow: 0px 8px 16px 0px rgba(0, 0, 0, 0.2);
            z-index: 1;
            border-radius: 0.375rem;
            border: 1px solid #222222;
        }

        .dropdown-content a {
            color: white;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            font-size: 0.875rem;
        }

        .dropdown-content a:hover {
            background-color: #222222;
        }

        .dropdown:hover .dropdown-content {
            display: block;
        }

        .past-date {
            color: #6b7280;
            background-color: rgba(75, 85, 99, 0.1);
        }

        .tab-button {
            padding: 0.75rem 1.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: 0.375rem 0.375rem 0 0;
            border: 1px solid #222222;
            border-bottom: none;
            background-color: #0a0a0a;
            color: #9ca3af;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .tab-button.active {
            background-color: #dc2626;
            color: white;
            border-color: #dc2626;
        }

        .tab-button:hover:not(.active) {
            background-color: #161616;
            color: white;
        }

        .booking-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 9999px;
            background-color: #374151;
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .tab-button.active .booking-count {
            background-color: white;
            color: #dc2626;
        }

        .edit-instructor-btn {
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 0.25rem;
            transition: color 0.2s;
        }

        .edit-instructor-btn:hover {
            color: #dc2626;
        }
    </style>
</head>

<body class="bg-[#161616] text-white">

<?php include __DIR__ . '/sidebar_netflix.php'; ?>
    <!-- Main Content -->
    <main class="main-content min-h-screen" id="mainContent">
        <header class="flex items-center h-14 px-6 border-b border-[#222222]">
            <h1 class="text-xl font-bold ml-1">BOOKINGS</h1>
        </header>

        <div class="p-6">
            <!-- Filters and Actions -->
            <div class="flex flex-wrap justify-between items-center mb-6 gap-4">
                <div class="flex items-center gap-4 flex-wrap">
                    <div>
                        <label for="status-filter" class="block text-xs text-gray-400 mb-1">Status</label>
                        <select id="status-filter" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-36">
                            <option value="">All Statuses</option>
                            <option value="confirmed" <?php echo $statusFilter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                            <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            <option value="archived" <?php echo $statusFilter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                            <option value="finished" <?php echo $statusFilter === 'finished' ? 'selected' : ''; ?>>Finished</option>
                        </select>
                    </div>
                    <div class="date-picker-container">
                        <label for="date-filter" class="block text-xs text-gray-400 mb-1">Date</label>
                        <input type="date" id="date-filter" value="<?php echo $dateFilter; ?>" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-36 pr-8">
                        <div class="date-picker-icon text-gray-400">
                            <i class="far fa-calendar-alt"></i>
                        </div>
                    </div>
                    <div>
                        <label for="per-page" class="block text-xs text-gray-400 mb-1">Show</label>
                        <select id="per-page" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-24">
                            <option value="10" <?php echo $itemsPerPage === 10 ? 'selected' : ''; ?>>10</option>
                            <option value="25" <?php echo $itemsPerPage === 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $itemsPerPage === 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $itemsPerPage === 100 ? 'selected' : ''; ?>>100</option>
                        </select>
                    </div>
                </div>
                <a href="booking.php" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium flex items-center gap-2">
                    <i class="fas fa-plus"></i>
                    <span>New Booking</span>
                </a>
            </div>

            <!-- Booking Tabs -->
            <div class="flex mb-0 border-b border-[#222222]">
                <a href="?tab=pending<?php echo !empty($dateFilter) ? '&date=' . $dateFilter : ''; ?><?php echo !empty($statusFilter) ? '&status=' . $statusFilter : ''; ?>"
                    class="tab-button <?php echo $activeTab === 'pending' ? 'active' : ''; ?>">
                    Pending & Upcoming
                    <?php
                    $pendingStmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM bookings b
                        JOIN studios s ON b.StudioID = s.StudioID
                        JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
                        JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
                        WHERE s.OwnerID = ? 
                        AND (bs.Book_Stats = 'Pending' OR bs.Book_Stats = 'Confirmed')
                        AND sch.Sched_Date >= CURDATE()
                    ");
                    $pendingStmt->execute([$ownerId]);
                    $pendingCount = $pendingStmt->fetchColumn();
                    ?>
                    <span class="booking-count"><?php echo $pendingCount; ?></span>
                </a>
                <a href="?tab=completed<?php echo !empty($dateFilter) ? '&date=' . $dateFilter : ''; ?><?php echo !empty($statusFilter) ? '&status=' . $statusFilter : ''; ?>"
                    class="tab-button <?php echo $activeTab === 'completed' ? 'active' : ''; ?>">
                    Completed
                    <?php
                    $completedStmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM bookings b
                        JOIN studios s ON b.StudioID = s.StudioID
                        JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
                        JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
                        LEFT JOIN payment p ON b.BookingID = p.BookingID
                        WHERE s.OwnerID = ? 
                        AND (bs.Book_Stats = 'Finished' OR (bs.Book_Stats = 'Confirmed' AND sch.Sched_Date < CURDATE()) OR p.Pay_Stats = 'Completed')
                    ");
                    $completedStmt->execute([$ownerId]);
                    $completedCount = $completedStmt->fetchColumn();
                    ?>
                    <span class="booking-count"><?php echo $completedCount; ?></span>
                </a>
                <a href="?tab=archived<?php echo !empty($dateFilter) ? '&date=' . $dateFilter : ''; ?><?php echo !empty($statusFilter) ? '&status=' . $statusFilter : ''; ?>"
                    class="tab-button <?php echo $activeTab === 'archived' ? 'active' : ''; ?>">
                    Archived
                    <?php
                    $archivedStmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM bookings b
                        JOIN studios s ON b.StudioID = s.StudioID
                        JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
                        WHERE s.OwnerID = ? 
                        AND bs.Book_Stats = 'Archived'
                    ");
                    $archivedStmt->execute([$ownerId]);
                    $archivedCount = $archivedStmt->fetchColumn();
                    ?>
                    <span class="booking-count"><?php echo $archivedCount; ?></span>
                </a>
                <a href="?tab=all<?php echo !empty($dateFilter) ? '&date=' . $dateFilter : ''; ?><?php echo !empty($statusFilter) ? '&status=' . $statusFilter : ''; ?>"
                    class="tab-button <?php echo $activeTab === 'all' ? 'active' : ''; ?>">
                    All Bookings
                    <?php
                    $allStmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM bookings b
                        JOIN studios s ON b.StudioID = s.StudioID
                        WHERE s.OwnerID = ?
                    ");
                    $allStmt->execute([$ownerId]);
                    $allCount = $allStmt->fetchColumn();
                    ?>
                    <span class="booking-count"><?php echo $allCount; ?></span>
                </a>
            </div>

            <!-- Bookings Table -->
            <div class="bg-[#0a0a0a] rounded-b-lg border-x border-b border-[#222222] overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="booking-table w-full">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Studio</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Instructor</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th>Amount</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bookings)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-gray-400">No bookings found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($bookings as $index => $booking): ?>
                                    <?php $isPastDate = isDatePast($booking['Sched_Date']); ?>
                                    <tr class="<?php echo $isPastDate ? 'past-date' : ''; ?>" onclick="viewBookingDetails(<?php echo $index; ?>)">
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <div class="avatar">
                                                    <?php echo getInitials($booking['client_name']); ?>
                                                </div>
                                                <span><?php echo htmlspecialchars($booking['client_name']); ?></span>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($booking['StudioName']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($booking['Sched_Date'])); ?></td>
                                        <td><?php echo date('g:i A', strtotime($booking['Time_Start'])) . ' - ' . date('g:i A', strtotime($booking['Time_End'])); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($booking['instructor_name'] ?? 'Not assigned'); ?>
                                            <?php if (!empty($instructors) && !$isPastDate): ?>
                                                <button class="edit-instructor-btn" onclick="event.stopPropagation(); showEditInstructorModal(<?php echo $index; ?>, <?php echo $booking['BookingID']; ?>, <?php echo $booking['InstructorID'] ?: 'null'; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo getStatusBadge($booking['status']); ?></td>
                                        <td><?php echo isset($booking['payment_status']) ? getPaymentStatusBadge($booking['payment_status']) : '<span class="badge bg-gray-600">N/A</span>'; ?></td>
                                        <td>₱<?php echo number_format($booking['amount'], 2); ?></td>
                                        <td class="text-right">
                                            <div class="flex items-center justify-end gap-2" onclick="event.stopPropagation();">
                                                <?php if (canConfirmBooking($booking['status']) && !$isPastDate): ?>
                                                    <button
                                                        class="bg-green-600 hover:bg-green-700 text-white rounded px-3 py-1 text-xs font-medium"
                                                        onclick="confirmBooking(<?php echo $booking['BookingID']; ?>, <?php echo $booking['ClientID']; ?>, '<?php echo addslashes($booking['StudioName']); ?>', '<?php echo $booking['Sched_Date']; ?>', '<?php echo $booking['Time_Start']; ?>', '<?php echo $booking['Time_End']; ?>')">
                                                        Confirm Booking
                                                    </button>
                                                <?php elseif (canConfirmPayment($booking['status'], $booking['payment_status']) && !$isPastDate): ?>
                                                    <button
                                                        class="bg-blue-600 hover:bg-blue-700 text-white rounded px-3 py-1 text-xs font-medium"
                                                        onclick="confirmPayment(<?php echo $booking['BookingID']; ?>, <?php echo $booking['ClientID']; ?>)">
                                                        Confirm Payment
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (canFinishBooking($booking['status'], $booking['payment_status']) && !$isPastDate): ?>
                                                    <button
                                                        class="bg-purple-600 hover:bg-purple-700 text-white rounded px-3 py-1 text-xs font-medium"
                                                        onclick="finishBooking(<?php echo $booking['BookingID']; ?>, <?php echo $booking['ClientID']; ?>)">
                                                        Finish Booking
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (canRequestPayment($booking['status'], $booking['payment_status']) && !$isPastDate): ?>
                                                    <button
                                                        class="bg-yellow-600 hover:bg-yellow-700 text-white rounded px-3 py-1 text-xs font-medium"
                                                        onclick="requestPayment(<?php echo $booking['BookingID']; ?>, <?php echo $booking['ClientID']; ?>)">
                                                        Request Payment
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (canCancelBooking($booking['status']) && !$isPastDate): ?>
                                                    <button
                                                        class="bg-red-600 hover:bg-red-700 text-white rounded px-3 py-1 text-xs font-medium"
                                                        onclick="cancelBooking(<?php echo $booking['BookingID']; ?>, <?php echo $booking['ClientID']; ?>)">
                                                        Cancel
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (canArchiveBooking($booking['status'])): ?>
                                                    <button
                                                        class="bg-gray-600 hover:bg-gray-700 text-white rounded px-3 py-1 text-xs font-medium"
                                                        onclick="archiveBooking(<?php echo $booking['BookingID']; ?>, <?php echo $booking['ClientID']; ?>)">
                                                        Archive
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <div class="flex flex-col md:flex-row justify-between items-center mt-6 gap-4">
                <div class="text-sm text-gray-400">
                    Showing <span class="font-medium text-white"><?php echo count($bookings); ?></span> of
                    <span class="font-medium text-white"><?php echo $totalBookings; ?></span> bookings
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="flex items-center justify-center flex-wrap">
                        <button
                            class="pagination-button"
                            <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>
                            onclick="changePage(<?php echo $currentPage - 1; ?>)">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <?php
                        $startPage = max(1, $currentPage - 2);
                        $endPage = min($totalPages, $startPage + 4);
                        if ($endPage - $startPage < 4) {
                            $startPage = max(1, $endPage - 4);
                        }
                        if ($startPage > 1) {
                            echo '<button class="pagination-button" onclick="changePage(1)">1</button>';
                            if ($startPage > 2) {
                                echo '<span class="px-1">...</span>';
                            }
                        }
                        for ($i = $startPage; $i <= $endPage; $i++) {
                            $activeClass = $i === $currentPage ? 'active' : '';
                            echo "<button class=\"pagination-button $activeClass\" onclick=\"changePage($i)\">$i</button>";
                        }
                        if ($endPage < $totalPages) {
                            if ($endPage < $totalPages - 1) {
                                echo '<span class="px-1">...</span>';
                            }
                            echo "<button class=\"pagination-button\" onclick=\"changePage($totalPages)\">$totalPages</button>";
                        }
                        ?>
                        <button
                            class="pagination-button"
                            <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>
                            onclick="changePage(<?php echo $currentPage + 1; ?>)">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Booking Details Modal -->
    <div id="bookingDetailsModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Booking Details</h3>
                <button class="text-gray-400 hover:text-white" onclick="closeBookingModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="bookingDetailsContent" class="space-y-4">
                <!-- Content will be populated dynamically -->
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button id="confirmBookingBtn" class="hidden bg-green-600 hover:bg-green-700 text-white rounded px-4 py-2 text-sm font-medium">
                    Confirm Booking
                </button>
                <button id="confirmPaymentBtn" class="hidden bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2 text-sm font-medium">
                    Confirm Payment
                </button>
                <button id="finishBookingBtn" class="hidden bg-purple-600 hover:bg-purple-700 text-white rounded px-4 py-2 text-sm font-medium">
                    Finish Booking
                </button>
                <button id="requestPaymentBtn" class="hidden bg-yellow-600 hover:bg-yellow-700 text-white rounded px-4 py-2 text-sm font-medium">
                    Request Payment
                </button>
                <button id="cancelBookingBtn" class="hidden bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium">
                    Cancel Booking
                </button>
                <button id="archiveBookingBtn" class="hidden bg-gray-600 hover:bg-gray-700 text-white rounded px-4 py-2 text-sm font-medium">
                    Archive
                </button>
                <button class="bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium" onclick="closeBookingModal()">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- Payment Request Modal -->
    <div id="paymentRequestModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Payment Request</h3>
                <button class="text-gray-400 hover:text-white" onclick="closePaymentModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="mb-4">Payment request has been sent to the client. They will be notified to complete their payment.</p>
            <div class="flex justify-end">
                <button class="bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium" onclick="closePaymentModal()">
                    OK
                </button>
            </div>
        </div>
    </div>

    <!-- Booking Confirmation Modal -->
    <div id="bookingConfirmationModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Booking Confirmation</h3>
                <button class="text-gray-400 hover:text-white" onclick="closeConfirmationModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="mb-4">Booking has been confirmed. A notification has been sent to the client.</p>
            <div class="flex justify-end">
                <button class="bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium" onclick="closeConfirmationModal()">
                    OK
                </button>
            </div>
        </div>
    </div>

    <!-- Payment Confirmation Modal -->
    <div id="paymentConfirmationModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Payment Confirmation</h3>
                <button class="text-gray-400 hover:text-white" onclick="closePaymentConfirmationModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="mb-4">Payment has been confirmed. A notification has been sent to the client.</p>
            <div class="flex justify-end">
                <button class="bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium" onclick="closePaymentConfirmationModal()">
                    OK
                </button>
            </div>
        </div>
    </div>

    <!-- Booking Finished Modal -->
    <div id="bookingFinishedModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Booking Finished</h3>
                <button class="text-gray-400 hover:text-white" onclick="closeBookingFinishedModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="mb-4">Booking has been marked as finished. A notification has been sent to the client.</p>
            <div class="flex justify-end">
                <button class="bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium" onclick="closeBookingFinishedModal()">
                    OK
                </button>
            </div>
        </div>
    </div>

    <!-- Booking Cancelled Modal -->
    <div id="bookingCancelledModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Booking Cancelled</h3>
                <button class="text-gray-400 hover:text-white" onclick="closeBookingCancelledModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="mb-4">Booking has been cancelled. A notification has been sent to the client.</p>
            <div class="flex justify-end">
                <button class="bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium" onclick="closeBookingCancelledModal()">
                    OK
                </button>
            </div>
        </div>
    </div>

    <!-- Booking Archived Modal -->
    <div id="bookingArchivedModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Booking Archived</h3>
                <button class="text-gray-400 hover:text-white" onclick="closeBookingArchivedModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="mb-4">Booking has been archived.</p>
            <div class="flex justify-end">
                <button class="bg-red-600 hover:bg-red-700 text-white rounded px-4 py-2 text-sm font-medium" onclick="closeBookingArchivedModal()">
                    OK
                </button>
            </div>
        </div>
    </div>

    <script>
        // Store bookings data for modal display
        const bookingsData = <?php echo json_encode($bookings); ?>;

        // Sidebar toggling is handled within sidebar_netflix; legacy handlers removed to avoid layout conflicts.
        const mainContent = document.getElementById('mainContent');

        // Filter functionality
        const statusFilter = document.getElementById('status-filter');
        const dateFilter = document.getElementById('date-filter');
        const perPageFilter = document.getElementById('per-page');

        function applyFilters() {
            const status = statusFilter.value;
            const date = dateFilter.value;
            const perPage = perPageFilter.value;
            const tab = new URLSearchParams(window.location.search).get('tab') || 'pending';

            const params = new URLSearchParams();
            params.set('tab', tab);

            if (status) params.set('status', status);
            if (date) params.set('date', date);
            if (perPage) params.set('per_page', perPage);

            params.set('page', '1');

            const queryString = params.toString();
            window.location.href = 'bookings.php' + (queryString ? '?' + queryString : '');
        }

        statusFilter.addEventListener('change', applyFilters);
        dateFilter.addEventListener('change', applyFilters);
        perPageFilter.addEventListener('change', applyFilters);

        document.querySelector('.date-picker-container').addEventListener('click', function() {
            dateFilter.showPicker();
        });

        function changePage(page) {
            const params = new URLSearchParams(window.location.search);
            params.set('page', page);
            window.location.href = 'bookings.php?' + params.toString();
        }

        function viewBookingDetails(index) {
            const booking = bookingsData[index];
            const modal = document.getElementById('bookingDetailsModal');
            const content = document.getElementById('bookingDetailsContent');
            const confirmBookingBtn = document.getElementById('confirmBookingBtn');
            const confirmPaymentBtn = document.getElementById('confirmPaymentBtn');
            const finishBookingBtn = document.getElementById('finishBookingBtn');
            const requestPaymentBtn = document.getElementById('requestPaymentBtn');
            const cancelBookingBtn = document.getElementById('cancelBookingBtn');
            const archiveBookingBtn = document.getElementById('archiveBookingBtn');

            let html = `
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <h4 class="text-sm font-medium text-gray-400">Client Information</h4>
                        <div class="mt-2 p-3 bg-[#161616] rounded-md">
                            <div class="flex items-center gap-2 mb-2">
                                <div class="avatar">
                                    ${getInitials(booking.client_name)}
                                </div>
                                <span class="font-medium">${booking.client_name}</span>
                            </div>
                            <p class="text-sm text-gray-400">Client ID: #${booking.ClientID}</p>
                            <p class="text-sm text-gray-400">Phone: ${booking.client_phone || 'N/A'}</p>
                            <p class="text-sm text-gray-400">Email: ${booking.client_email || 'N/A'}</p>
                        </div>
                    </div>
                    
                    <div>
                        <h4 class="text-sm font-medium text-gray-400">Booking Information</h4>
                        <div class="mt-2 p-3 bg-[#161616] rounded-md">
                            <p class="text-sm"><span class="text-gray-400">Booking ID:</span> #${booking.BookingID}</p>
                            <p class="text-sm"><span class="text-gray-400">Studio:</span> ${booking.StudioName}</p>
                            <p class="text-sm"><span class="text-gray-400">Date:</span> ${formatDate(booking.Sched_Date)}</p>
                            <p class="text-sm"><span class="text-gray-400">Time:</span> ${formatTime(booking.Time_Start)} - ${formatTime(booking.Time_End)}</p>
                            <p class="text-sm"><span class="text-gray-400">Booking Date:</span> ${formatDate(booking.booking_date)}</p>
                        </div>
                    </div>
                </div>
                
                <div>
                    <h4 class="text-sm font-medium text-gray-400">Service Details</h4>
                    <div class="mt-2 p-3 bg-[#161616] rounded-md">
                        <p class="text-sm font-medium">${booking.service_name || 'No service specified'}</p>
                        <p class="text-sm text-gray-400 mt-1">${booking.service_description || 'No description available'}</p>
                        <p class="text-sm mt-2"><span class="text-gray-400">Price:</span> ₱${parseFloat(booking.service_price || 0).toFixed(2)}</p>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <h4 class="text-sm font-medium text-gray-400">Status</h4>
                        <div class="mt-2 p-3 bg-[#161616] rounded-md">
                            ${getStatusBadgeHTML(booking.status)}
                        </div>
                    </div>
                    
                    <div>
                        <h4 class="text-sm font-medium text-gray-400">Payment Status</h4>
                        <div class="mt-2 p-3 bg-[#161616] rounded-md">
                            ${getPaymentStatusBadgeHTML(booking.payment_status)}
                        </div>
                    </div>
                    
                    <div>
                        <h4 class="text-sm font-medium text-gray-400">Amount</h4>
                        <div class="mt-2 p-3 bg-[#161616] rounded-md">
                            <p class="text-lg font-bold">₱${parseFloat(booking.amount).toFixed(2)}</p>
                        </div>
                    </div>
                </div>
            `;

            content.innerHTML = html;

            const isPastDate = new Date(booking.Sched_Date) < new Date().setHours(0, 0, 0, 0);

            confirmBookingBtn.classList.add('hidden');
            confirmPaymentBtn.classList.add('hidden');
            finishBookingBtn.classList.add('hidden');
            requestPaymentBtn.classList.add('hidden');
            cancelBookingBtn.classList.add('hidden');
            archiveBookingBtn.classList.add('hidden');

            if (canConfirmBooking(booking.status) && !isPastDate) {
                confirmBookingBtn.classList.remove('hidden');
                confirmBookingBtn.onclick = function() {
                    confirmBooking(booking.BookingID, booking.ClientID, booking.StudioName, booking.Sched_Date, booking.Time_Start, booking.Time_End);
                };
            }

            if (canConfirmPayment(booking.status, booking.payment_status) && !isPastDate) {
                confirmPaymentBtn.classList.remove('hidden');
                confirmPaymentBtn.onclick = function() {
                    confirmPayment(booking.BookingID, booking.ClientID);
                };
            }

            if (canFinishBooking(booking.status, booking.payment_status) && !isPastDate) {
                finishBookingBtn.classList.remove('hidden');
                finishBookingBtn.onclick = function() {
                    finishBooking(booking.BookingID, booking.ClientID);
                };
            }

            if (canRequestPayment(booking.status, booking.payment_status) && !isPastDate) {
                requestPaymentBtn.classList.remove('hidden');
                requestPaymentBtn.onclick = function() {
                    requestPayment(booking.BookingID, booking.ClientID);
                };
            }

            if (canCancelBooking(booking.status) && !isPastDate) {
                cancelBookingBtn.classList.remove('hidden');
                cancelBookingBtn.onclick = function() {
                    cancelBooking(booking.BookingID, booking.ClientID);
                };
            }

            if (canArchiveBooking(booking.status)) {
                archiveBookingBtn.classList.remove('hidden');
                archiveBookingBtn.onclick = function() {
                    archiveBooking(booking.BookingID, booking.ClientID);
                };
            }

            modal.style.display = 'block';
        }

        function closeBookingModal() {
            document.getElementById('bookingDetailsModal').style.display = 'none';
        }

        function confirmBooking(bookingId, clientId, studioName, bookDate, timeStart, timeEnd) {
            const formattedDate = formatDate(bookDate);
            const formattedTimeStart = formatTime(timeStart);
            const formattedTimeEnd = formatTime(timeEnd);
            const message = `Your booking for ${studioName} on ${formattedDate} from ${formattedTimeStart} to ${formattedTimeEnd} has been confirmed`;

            fetch('confirm-booking.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        bookingId: bookingId,
                        clientId: clientId,
                        ownerId: <?php echo json_encode($ownerId); ?>,
                        message: message
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('bookingConfirmationModal').style.display = 'block';
                        document.getElementById('bookingDetailsModal').style.display = 'none';
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        alert(data.error || 'Failed to confirm booking. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error confirming booking:', error);
                    alert('Failed to confirm booking. Please try again.');
                });
        }

        function confirmPayment(bookingId, clientId) {
            fetch('confirm-payment.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        bookingId: bookingId,
                        clientId: clientId,
                        ownerId: <?php echo json_encode($ownerId); ?>,
                        message: 'Payment for your booking #' + bookingId + ' has been confirmed'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('paymentConfirmationModal').style.display = 'block';
                        document.getElementById('bookingDetailsModal').style.display = 'none';
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        alert(data.error || 'Failed to confirm payment. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error confirming payment:', error);
                    alert('Failed to confirm payment. Please try again.');
                });
        }

        function finishBooking(bookingId, clientId) {
            fetch('finish-booking.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        bookingId: bookingId,
                        clientId: clientId,
                        ownerId: <?php echo json_encode($ownerId); ?>,
                        message: 'Your booking #' + bookingId + ' has been marked as finished'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('bookingFinishedModal').style.display = 'block';
                        document.getElementById('bookingDetailsModal').style.display = 'none';
                        const target = data.redirect || 'bookings_netflix.php?status=completed';
                        setTimeout(() => window.location.href = target, 1500);
                    } else {
                        alert(data.error || 'Failed to finish booking. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error finishing booking:', error);
                    alert('Failed to finish booking. Please try again.');
                });
        }

        function requestPayment(bookingId, clientId) {
            fetch('create-notification.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        clientId: clientId,
                        bookingId: bookingId,
                        type: 'payment_request',
                        message: 'Please complete your payment for booking #' + bookingId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('paymentRequestModal').style.display = 'block';
                        document.getElementById('bookingDetailsModal').style.display = 'none';
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        alert(data.error || 'Failed to send payment request. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error creating notification:', error);
                    document.getElementById('paymentRequestModal').style.display = 'block';
                    document.getElementById('bookingDetailsModal').style.display = 'none';
                });
        }

        function cancelBooking(bookingId, clientId) {
            fetch('cancel-booking.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        bookingId: bookingId,
                        clientId: clientId,
                        message: 'Your booking #' + bookingId + ' has been cancelled'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('bookingCancelledModal').style.display = 'block';
                        document.getElementById('bookingDetailsModal').style.display = 'none';
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        alert(data.error || 'Failed to cancel booking. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error cancelling booking:', error);
                    alert('Failed to cancel booking. Please try again.');
                });
        }

        function archiveBooking(bookingId, clientId) {
            fetch('archive-booking.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        bookingId: bookingId,
                        ownerId: <?php echo json_encode($ownerId); ?>
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('bookingArchivedModal').style.display = 'block';
                        document.getElementById('bookingDetailsModal').style.display = 'none';
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        alert(data.error || 'Failed to archive booking. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error archiving booking:', error);
                    alert('Failed to archive booking. Please try again.');
                });
        }

        function closePaymentModal() {
            document.getElementById('paymentRequestModal').style.display = 'none';
        }

        function closeConfirmationModal() {
            document.getElementById('bookingConfirmationModal').style.display = 'none';
        }

        function closePaymentConfirmationModal() {
            document.getElementById('paymentConfirmationModal').style.display = 'none';
        }

        function closeBookingFinishedModal() {
            document.getElementById('bookingFinishedModal').style.display = 'none';
        }

        function closeBookingCancelledModal() {
            document.getElementById('bookingCancelledModal').style.display = 'none';
        }

        function closeBookingArchivedModal() {
            document.getElementById('bookingArchivedModal').style.display = 'none';
        }

        function getInitials(name) {
            const words = name.split(' ');
            let initials = '';
            for (const word of words) {
                if (word.length > 0) {
                    initials += word[0].toUpperCase();
                }
            }
            return initials.substring(0, 2);
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        }

        function formatTime(timeString) {
            const [hours, minutes] = timeString.split(':');
            const hour = parseInt(hours, 10);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            const hour12 = hour % 12 || 12;
            return `${hour12}:${minutes} ${ampm}`;
        }

        function getStatusBadgeHTML(status) {
            const statusLower = status.toLowerCase();
            if (statusLower === 'confirmed') {
                return '<span class="badge bg-red-600">confirmed</span>';
            } else if (statusLower === 'pending') {
                return '<span class="badge bg-transparent border border-gray-500 text-gray-300">pending</span>';
            } else if (statusLower === 'cancelled') {
                return '<span class="badge bg-gray-700">cancelled</span>';
            } else if (statusLower === 'archived') {
                return '<span class="badge bg-blue-700">archived</span>';
            } else if (statusLower === 'finished') {
                return '<span class="badge bg-purple-600">finished</span>';
            } else {
                return `<span class="badge bg-gray-600">${status}</span>`;
            }
        }

        function getPaymentStatusBadgeHTML(status) {
            const statusLower = status.toLowerCase();
            if (statusLower === 'completed') {
                return '<span class="badge bg-green-600">completed</span>';
            } else if (statusLower === 'pending') {
                return '<span class="badge bg-yellow-600">pending</span>';
            } else if (statusLower === 'failed') {
                return '<span class="badge bg-red-600">failed</span>';
            } else if (statusLower === 'cancelled') {
                return '<span class="badge bg-gray-700">cancelled</span>';
            } else if (statusLower === 'n/a') {
                return '<span class="badge bg-gray-600">N/A</span>';
            } else {
                return `<span class="badge bg-gray-600">${status}</span>`;
            }
        }

        function canConfirmBooking(status) {
            return status.toLowerCase() === 'pending';
        }

        function canConfirmPayment(status, paymentStatus) {
            return (status.toLowerCase() === 'confirmed' && paymentStatus.toLowerCase() !== 'completed');
        }

        function canFinishBooking(status, paymentStatus) {
            return (status.toLowerCase() === 'confirmed' && paymentStatus.toLowerCase() === 'completed');
        }

        function canRequestPayment(status, paymentStatus) {
            return (status.toLowerCase() === 'pending' && (paymentStatus.toLowerCase() === 'pending' || paymentStatus.toLowerCase() === 'n/a'));
        }

        function canCancelBooking(status) {
            return (status.toLowerCase() === 'pending' || status.toLowerCase() === 'confirmed');
        }

        function canArchiveBooking(status) {
            return status.toLowerCase() !== 'archived';
        }

        window.onclick = function(event) {
            const bookingModal = document.getElementById('bookingDetailsModal');
            const paymentModal = document.getElementById('paymentRequestModal');
            const confirmationModal = document.getElementById('bookingConfirmationModal');
            const paymentConfirmationModal = document.getElementById('paymentConfirmationModal');
            const bookingFinishedModal = document.getElementById('bookingFinishedModal');
            const bookingCancelledModal = document.getElementById('bookingCancelledModal');
            const bookingArchivedModal = document.getElementById('bookingArchivedModal');

            if (event.target === bookingModal) {
                bookingModal.style.display = 'none';
            }
            if (event.target === paymentModal) {
                paymentModal.style.display = 'none';
            }
            if (event.target === confirmationModal) {
                confirmationModal.style.display = 'none';
            }
            if (event.target === paymentConfirmationModal) {
                paymentConfirmationModal.style.display = 'none';
            }
            if (event.target === bookingFinishedModal) {
                bookingFinishedModal.style.display = 'none';
            }
            if (event.target === bookingCancelledModal) {
                bookingCancelledModal.style.display = 'none';
            }
            if (event.target === bookingArchivedModal) {
                bookingArchivedModal.style.display = 'none';
            }
        };
    </script>
</body>

</html>
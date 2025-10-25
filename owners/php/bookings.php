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

// Get the logged-in owner's ID from session
$ownerId = $_SESSION['user_id'];

// Archive past bookings that are not confirmed
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

// Get active tab (pending, completed, all)
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
         srv.Price as service_price
  FROM bookings b
  JOIN clients c ON b.ClientID = c.ClientID
  JOIN studios s ON b.StudioID = s.StudioID
  JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
  JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
  LEFT JOIN payment p ON b.BookingID = p.BookingID
  LEFT JOIN services srv ON b.ServiceID = srv.ServiceID
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
  $baseQuery .= " AND (bs.Book_Stats = 'Confirmed' AND sch.Sched_Date < CURDATE() OR p.Pay_Stats = 'Completed')";
  $countBaseQuery .= " AND (bs.Book_Stats = 'Confirmed' AND b.ScheduleID IN (SELECT ScheduleID FROM schedules WHERE Sched_Date < CURDATE()) OR b.BookingID IN (SELECT BookingID FROM payment WHERE Pay_Stats = 'Completed'))";
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

// Get total count for pagination
$countStmt = $pdo->prepare($countBaseQuery);
foreach ($params as $key => $value) {
  $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalItems = $countStmt->fetchColumn();
$totalPages = ceil($totalItems / $itemsPerPage);

// Add pagination to main query
$baseQuery .= " ORDER BY b.booking_date DESC, sch.Time_Start DESC LIMIT :limit OFFSET :offset";

// Execute main query
$stmt = $pdo->prepare($baseQuery);
foreach ($params as $key => $value) {
  $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get status counts for tabs
$statusCounts = [];
$statusCounts['pending'] = $pdo->prepare("
  SELECT COUNT(*) FROM bookings b
  JOIN studios s ON b.StudioID = s.StudioID
  JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
  WHERE s.OwnerID = ? AND (bs.Book_Stats = 'Pending' OR bs.Book_Stats = 'Confirmed') 
  AND b.ScheduleID IN (SELECT ScheduleID FROM schedules WHERE Sched_Date >= CURDATE())
");
$statusCounts['pending']->execute([$ownerId]);
$pendingCount = $statusCounts['pending']->fetchColumn();

$statusCounts['completed'] = $pdo->prepare("
  SELECT COUNT(*) FROM bookings b
  JOIN studios s ON b.StudioID = s.StudioID
  JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
  WHERE s.OwnerID = ? AND (bs.Book_Stats = 'Confirmed' AND b.ScheduleID IN (SELECT ScheduleID FROM schedules WHERE Sched_Date < CURDATE()) OR b.BookingID IN (SELECT BookingID FROM payment WHERE Pay_Stats = 'Completed'))
");
$statusCounts['completed']->execute([$ownerId]);
$completedCount = $statusCounts['completed']->fetchColumn();

$statusCounts['archived'] = $pdo->prepare("
  SELECT COUNT(*) FROM bookings b
  JOIN studios s ON b.StudioID = s.StudioID
  JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
  WHERE s.OwnerID = ? AND bs.Book_Stats = 'Archived'
");
$statusCounts['archived']->execute([$ownerId]);
$archivedCount = $statusCounts['archived']->fetchColumn();

// Get all unique statuses for filter dropdown
$statusStmt = $pdo->prepare("
  SELECT DISTINCT bs.Book_Stats 
  FROM book_stats bs
  ORDER BY bs.Book_Stats
");
$statusStmt->execute();
$allStatuses = $statusStmt->fetchAll(PDO::FETCH_COLUMN);

// Helper function to get status badge class
function getStatusBadgeClass($status) {
    switch (strtolower($status)) {
        case 'pending':
            return 'status-pending';
        case 'confirmed':
            return 'status-confirmed';
        case 'cancelled':
            return 'status-cancelled';
        case 'completed':
            return 'status-completed';
        case 'archived':
            return 'status-archived';
        default:
            return 'status-unknown';
    }
}

// Helper function to get payment status badge class
function getPaymentStatusBadgeClass($status) {
    switch (strtolower($status)) {
        case 'completed':
            return 'payment-completed';
        case 'pending':
            return 'payment-pending';
        case 'failed':
            return 'payment-failed';
        default:
            return 'payment-unknown';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1" name="viewport"/>
    <title>MuSeek - Bookings</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="style.css"/>
    
    <style>
        :root {
            --netflix-red: #e50914;
            --netflix-black: #141414;
            --netflix-dark-gray: #2f2f2f;
            --netflix-gray: #666666;
            --netflix-light-gray: #b3b3b3;
            --netflix-white: #ffffff;
            --success-green: #46d369;
            --warning-orange: #ffa500;
            --info-blue: #0071eb;
        }

        body {
            font-family: 'Netflix Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--netflix-black);
            color: var(--netflix-white);
            margin: 0;
            padding: 0;
        }

        .main-content {
            margin-left: 280px;
            min-height: 100vh;
            background: var(--netflix-black);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-netflix.collapsed + .main-content {
            margin-left: 70px;
        }

        .bookings-container {
            padding: 40px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-header {
            margin-bottom: 40px;
        }

        .page-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--netflix-white);
            margin-bottom: 10px;
            background: linear-gradient(45deg, var(--netflix-white), var(--netflix-light-gray));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-subtitle {
            font-size: 1.1rem;
            color: var(--netflix-light-gray);
            margin-bottom: 30px;
        }

        .tabs-container {
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid #333;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .tab {
            padding: 12px 24px;
            background: transparent;
            border: 1px solid #333;
            border-radius: 8px;
            color: var(--netflix-light-gray);
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tab:hover {
            background: var(--netflix-black);
            color: var(--netflix-white);
        }

        .tab.active {
            background: var(--netflix-red);
            color: var(--netflix-white);
            border-color: var(--netflix-red);
        }

        .tab-count {
            background: rgba(255, 255, 255, 0.2);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .filters {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .filter-label {
            font-size: 0.9rem;
            color: var(--netflix-light-gray);
            font-weight: 500;
        }

        .filter-select {
            padding: 8px 12px;
            border: 1px solid #333;
            border-radius: 6px;
            background: var(--netflix-black);
            color: var(--netflix-white);
            font-size: 14px;
            min-width: 150px;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--netflix-red);
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.2);
        }

        .bookings-table {
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #333;
        }

        .table-header {
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
            padding: 20px;
            color: var(--netflix-white);
        }

        .table-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
        }

        .table-title i {
            margin-right: 10px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: var(--netflix-black);
            color: var(--netflix-white);
            padding: 16px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            border-bottom: 1px solid #333;
        }

        .table td {
            padding: 16px;
            border-bottom: 1px solid #333;
            color: var(--netflix-light-gray);
            font-size: 14px;
        }

        .table tbody tr:hover {
            background: rgba(229, 9, 20, 0.05);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-pending {
            background: rgba(255, 165, 0, 0.1);
            color: var(--warning-orange);
            border: 1px solid rgba(255, 165, 0, 0.3);
        }

        .status-confirmed {
            background: rgba(70, 211, 105, 0.1);
            color: var(--success-green);
            border: 1px solid rgba(70, 211, 105, 0.3);
        }

        .status-cancelled {
            background: rgba(255, 107, 107, 0.1);
            color: #ff6b6b;
            border: 1px solid rgba(255, 107, 107, 0.3);
        }

        .status-completed {
            background: rgba(0, 113, 235, 0.1);
            color: var(--info-blue);
            border: 1px solid rgba(0, 113, 235, 0.3);
        }

        .status-archived {
            background: rgba(102, 102, 102, 0.1);
            color: var(--netflix-gray);
            border: 1px solid rgba(102, 102, 102, 0.3);
        }

        .payment-completed {
            background: rgba(70, 211, 105, 0.1);
            color: var(--success-green);
            border: 1px solid rgba(70, 211, 105, 0.3);
        }

        .payment-pending {
            background: rgba(255, 165, 0, 0.1);
            color: var(--warning-orange);
            border: 1px solid rgba(255, 165, 0, 0.3);
        }

        .payment-failed {
            background: rgba(255, 107, 107, 0.1);
            color: #ff6b6b;
            border: 1px solid rgba(255, 107, 107, 0.3);
        }

        .payment-unknown {
            background: rgba(102, 102, 102, 0.1);
            color: var(--netflix-gray);
            border: 1px solid rgba(102, 102, 102, 0.3);
        }

        .client-info {
            display: flex;
            flex-direction: column;
        }

        .client-name {
            font-weight: 600;
            color: var(--netflix-white);
            margin-bottom: 4px;
        }

        .client-contact {
            font-size: 0.8rem;
            color: var(--netflix-light-gray);
        }

        .booking-actions {
            display: flex;
            gap: 8px;
        }

        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-primary {
            background: var(--netflix-red);
            color: var(--netflix-white);
        }

        .btn-primary:hover {
            background: #d40813;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--netflix-dark-gray);
            color: var(--netflix-white);
            border: 1px solid #333;
        }

        .btn-secondary:hover {
            background: var(--netflix-gray);
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
        }

        .pagination-btn {
            padding: 8px 12px;
            border: 1px solid #333;
            border-radius: 6px;
            background: var(--netflix-dark-gray);
            color: var(--netflix-white);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .pagination-btn:hover:not(:disabled) {
            background: var(--netflix-red);
            border-color: var(--netflix-red);
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-btn.active {
            background: var(--netflix-red);
            border-color: var(--netflix-red);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--netflix-light-gray);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: var(--netflix-gray);
        }

        .empty-state h3 {
            color: var(--netflix-white);
            margin-bottom: 10px;
            font-size: 1.5rem;
        }

        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            
            .bookings-container {
                padding: 20px;
            }
            
            .page-title {
                font-size: 2rem;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            
            .table {
                font-size: 12px;
            }
            
            .table th,
            .table td {
                padding: 12px 8px;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar_netflix.php'; ?>

    <div class="main-content">
        <div class="bookings-container">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Bookings Management</h1>
                <p class="page-subtitle">View and manage all studio bookings</p>
            </div>

            <!-- Tabs and Filters -->
            <div class="tabs-container fade-in">
                <div class="tabs">
                    <button class="tab <?php echo $activeTab === 'pending' ? 'active' : ''; ?>" 
                            onclick="changeTab('pending')">
                        <i class="fas fa-clock"></i>
                        Pending
                        <span class="tab-count"><?php echo $pendingCount; ?></span>
                    </button>
                    <button class="tab <?php echo $activeTab === 'completed' ? 'active' : ''; ?>" 
                            onclick="changeTab('completed')">
                        <i class="fas fa-check-circle"></i>
                        Completed
                        <span class="tab-count"><?php echo $completedCount; ?></span>
                    </button>
                    <button class="tab <?php echo $activeTab === 'archived' ? 'active' : ''; ?>" 
                            onclick="changeTab('archived')">
                        <i class="fas fa-archive"></i>
                        Archived
                        <span class="tab-count"><?php echo $archivedCount; ?></span>
                    </button>
                </div>

                <div class="filters">
                    <div class="filter-group">
                        <label class="filter-label">Status Filter</label>
                        <select class="filter-select" onchange="changeStatus(this.value)">
                            <option value="">All Statuses</option>
                            <?php foreach ($allStatuses as $status): ?>
                                <option value="<?php echo htmlspecialchars($status); ?>" 
                                        <?php echo $statusFilter === $status ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">Date Filter</label>
                        <input type="date" class="filter-select" value="<?php echo $dateFilter; ?>" 
                               onchange="changeDate(this.value)">
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">Items per page</label>
                        <select class="filter-select" onchange="changePerPage(this.value)">
                            <option value="10" <?php echo $itemsPerPage === 10 ? 'selected' : ''; ?>>10</option>
                            <option value="25" <?php echo $itemsPerPage === 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $itemsPerPage === 50 ? 'selected' : ''; ?>>50</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Bookings Table -->
            <div class="bookings-table fade-in">
                <div class="table-header">
                    <h3 class="table-title">
                        <i class="fas fa-calendar-check"></i>
                        Bookings (<?php echo $totalItems; ?> total)
                    </h3>
                </div>

                <?php if (empty($bookings)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Bookings Found</h3>
                        <p>No bookings found for the selected filters.</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Studio</th>
                                <th>Service</th>
                                <th>Date & Time</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th>Amount</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $booking): ?>
                                <tr>
                                    <td>
                                        <div class="client-info">
                                            <div class="client-name"><?php echo htmlspecialchars($booking['client_name']); ?></div>
                                            <div class="client-contact">
                                                <i class="fas fa-phone"></i> <?php echo htmlspecialchars($booking['client_phone']); ?><br>
                                                <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($booking['client_email']); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($booking['StudioName']); ?></strong>
                                    </td>
                                    <td>
                                        <?php if ($booking['service_name']): ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($booking['service_name']); ?></strong>
                                                <?php if ($booking['service_description']): ?>
                                                    <br><small><?php echo htmlspecialchars(substr($booking['service_description'], 0, 50)) . (strlen($booking['service_description']) > 50 ? '...' : ''); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--netflix-gray);">No service</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo date('M d, Y', strtotime($booking['Sched_Date'])); ?></strong><br>
                                            <small><?php echo date('g:i A', strtotime($booking['Time_Start'])); ?> - <?php echo date('g:i A', strtotime($booking['Time_End'])); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo getStatusBadgeClass($booking['status']); ?>">
                                            <?php echo htmlspecialchars($booking['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo getPaymentStatusBadgeClass($booking['payment_status']); ?>">
                                            <?php echo htmlspecialchars($booking['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong style="color: var(--success-green);">
                                            â‚±<?php echo number_format($booking['amount'], 2); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <div class="booking-actions">
                                            <a href="booking_details.php?id=<?php echo $booking['BookingID']; ?>" 
                                               class="btn btn-primary">
                                                <i class="fas fa-eye"></i>
                                                View
                                            </a>
                                            <?php if ($booking['status'] === 'Pending'): ?>
                                                <button class="btn btn-secondary" 
                                                        onclick="updateBookingStatus(<?php echo $booking['BookingID']; ?>, 'Confirmed')">
                                                    <i class="fas fa-check"></i>
                                                    Confirm
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination fade-in">
                    <button class="pagination-btn" 
                            onclick="changePage(<?php echo max(1, $currentPage - 1); ?>)"
                            <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>>
                        <i class="fas fa-chevron-left"></i>
                        Previous
                    </button>

                    <?php for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++): ?>
                        <button class="pagination-btn <?php echo $i === $currentPage ? 'active' : ''; ?>" 
                                onclick="changePage(<?php echo $i; ?>)">
                            <?php echo $i; ?>
                        </button>
                    <?php endfor; ?>

                    <button class="pagination-btn" 
                            onclick="changePage(<?php echo min($totalPages, $currentPage + 1); ?>)"
                            <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>>
                        Next
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function changeTab(tab) {
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            window.location.href = url.toString();
        }

        function changeStatus(status) {
            const url = new URL(window.location);
            if (status === '') {
                url.searchParams.delete('status');
            } else {
                url.searchParams.set('status', status);
            }
            window.location.href = url.toString();
        }

        function changeDate(date) {
            const url = new URL(window.location);
            if (date === '') {
                url.searchParams.delete('date');
            } else {
                url.searchParams.set('date', date);
            }
            window.location.href = url.toString();
        }

        function changePerPage(perPage) {
            const url = new URL(window.location);
            url.searchParams.set('per_page', perPage);
            url.searchParams.set('page', 1); // Reset to first page
            window.location.href = url.toString();
        }

        function changePage(page) {
            const url = new URL(window.location);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }

        function updateBookingStatus(bookingId, status) {
            if (confirm('Are you sure you want to update this booking status?')) {
                // This would typically make an AJAX request
                // For now, we'll redirect to a status update page
                window.location.href = `update_booking_status.php?id=${bookingId}&status=${status}`;
            }
        }

        // Add smooth animations
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.fade-in');
            elements.forEach((element, index) => {
                element.style.opacity = '0';
                element.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    element.style.transition = 'all 0.6s ease-out';
                    element.style.opacity = '1';
                    element.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>

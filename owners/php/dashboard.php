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

// Fetch owner information
$ownerStmt = $pdo->prepare("SELECT Name, Email FROM studio_owners WHERE OwnerID = ?");
$ownerStmt->execute([$ownerId]);
$owner = $ownerStmt->fetch();

if (!$owner) {
    session_destroy();
    header("Location: login.php");
    exit();
}

// Fetch studios owned by this owner
$studioStmt = $pdo->prepare("
    SELECT StudioID, StudioName, Loc_Desc, Time_IN, Time_OUT 
    FROM studios 
    WHERE OwnerID = ?
");
$studioStmt->execute([$ownerId]);
$studios = $studioStmt->fetchAll();

// Get studio IDs for this owner
$studioIds = array_column($studios, 'StudioID');

// Handle month and year parameters
$currentMonth = isset($_GET['month']) && is_numeric($_GET['month']) && $_GET['month'] >= 1 && $_GET['month'] <= 12
    ? (int)$_GET['month']
    : 5; // May 2025
$currentYear = isset($_GET['year']) && is_numeric($_GET['year']) && $_GET['year'] >= 2000 && $_GET['year'] <= 2100
    ? (int)$_GET['year']
    : 2025; // 2025

// If no studios found, set defaults
if (empty($studioIds)) {
    $totalBookings = 0;
    $activeClients = 0;
    $recentBookings = [];
    $todayBookings = 0;
    $bookingGrowth = 0;
    $clientGrowth = 0;
    $calendarBookings = [];
    $pendingNotifications = [];
} else {
    // Fetch total bookings count (last 30 days: April 20, 2025 - May 20, 2025)
    $totalBookingsStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM bookings b
        JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
        WHERE b.StudioID IN (" . str_repeat('?,', count($studioIds) - 1) . "?)
        AND sch.Sched_Date BETWEEN DATE_SUB('2025-05-20', INTERVAL 30 DAY) AND '2025-05-20'
    ");
    $totalBookingsStmt->execute($studioIds);
    $totalBookings = $totalBookingsStmt->fetchColumn();

    // Fetch active clients (last 30 days: April 20, 2025 - May 20, 2025)
    $activeClientsStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT b.ClientID) 
        FROM bookings b
        JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
        WHERE b.StudioID IN (" . str_repeat('?,', count($studioIds) - 1) . "?)
        AND sch.Sched_Date BETWEEN DATE_SUB('2025-05-20', INTERVAL 30 DAY) AND '2025-05-20'
    ");
    $activeClientsStmt->execute($studioIds);
    $activeClients = $activeClientsStmt->fetchColumn();

    // Fetch recent bookings
    $recentBookingsStmt = $pdo->prepare("
        SELECT b.BookingID, c.Name as client_name, s.StudioName, sch.Sched_Date as booking_date,
               sch.Time_Start, sch.Time_End, bs.Book_Stats as status,
               COALESCE(p.Amount, 0) as amount, srv.ServiceType as service_name
        FROM bookings b
        JOIN clients c ON b.ClientID = c.ClientID
        JOIN studios s ON b.StudioID = s.StudioID
        JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
        JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
        LEFT JOIN payment p ON b.BookingID = p.BookingID
        LEFT JOIN services srv ON b.ServiceID = srv.ServiceID
        WHERE b.StudioID IN (" . str_repeat('?,', count($studioIds) - 1) . "?)
        ORDER BY sch.Sched_Date DESC, sch.Time_Start DESC
        LIMIT 5
    ");
    $recentBookingsStmt->execute($studioIds);
    $recentBookings = $recentBookingsStmt->fetchAll();

    // Get today's bookings (May 20, 2025)
    $todayBookingsStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM bookings b
        JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
        WHERE b.StudioID IN (" . str_repeat('?,', count($studioIds) - 1) . "?)
        AND sch.Sched_Date = '2025-05-20'
    ");
    $todayBookingsStmt->execute($studioIds);
    $todayBookings = $todayBookingsStmt->fetchColumn();

    // Calculate booking growth (compare last 30 days with previous 30 days)
    // Previous period: March 21, 2025 - April 19, 2025
    $lastMonthBookingsStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM bookings b
        JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
        WHERE b.StudioID IN (" . str_repeat('?,', count($studioIds) - 1) . "?)
        AND sch.Sched_Date BETWEEN '2025-03-21' AND '2025-04-19'
    ");
    $lastMonthBookingsStmt->execute($studioIds);
    $lastMonthBookings = $lastMonthBookingsStmt->fetchColumn();

    // Current period: April 20, 2025 - May 20, 2025
    $currentMonthBookingsStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM bookings b
        JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
        WHERE b.StudioID IN (" . str_repeat('?,', count($studioIds) - 1) . "?)
        AND sch.Sched_Date BETWEEN '2025-04-20' AND '2025-05-20'
    ");
    $currentMonthBookingsStmt->execute($studioIds);
    $currentMonthBookings = $currentMonthBookingsStmt->fetchColumn();

    $bookingGrowth = $lastMonthBookings > 0
        ? round((($currentMonthBookings - $lastMonthBookings) / $lastMonthBookings) * 100)
        : 0;

    // Calculate client growth
    // Previous period: March 21, 2025 - April 19, 2025
    $lastMonthClientsStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT b.ClientID) 
        FROM bookings b
        JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
        WHERE b.StudioID IN (" . str_repeat('?,', count($studioIds) - 1) . "?)
        AND sch.Sched_Date BETWEEN '2025-03-21' AND '2025-04-19'
    ");
    $lastMonthClientsStmt->execute($studioIds);
    $lastMonthClients = $lastMonthClientsStmt->fetchColumn();

    // Current period: April 20, 2025 - May 20, 2025
    $currentMonthClientsStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT b.ClientID) 
        FROM bookings b
        JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
        WHERE b.StudioID IN (" . str_repeat('?,', count($studioIds) - 1) . "?)
        AND sch.Sched_Date BETWEEN '2025-04-20' AND '2025-05-20'
    ");
    $currentMonthClientsStmt->execute($studioIds);
    $currentMonthClients = $currentMonthClientsStmt->fetchColumn();

    $clientGrowth = $lastMonthClients > 0
        ? round((($currentMonthClients - $lastMonthClients) / $lastMonthClients) * 100)
        : 0;

    // Fetch calendar bookings for initial load
    $calendarBookingsStmt = $pdo->prepare("
        SELECT DAY(sch.Sched_Date) as day, COUNT(*) as count
        FROM bookings b
        JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
        WHERE b.StudioID IN (" . str_repeat('?,', count($studioIds) - 1) . "?)
        AND MONTH(sch.Sched_Date) = ?
        AND YEAR(sch.Sched_Date) = ?
        GROUP BY sch.Sched_Date
    ");
    $calendarBookingsStmt->execute(array_merge($studioIds, [$currentMonth, $currentYear]));
    $calendarBookings = $calendarBookingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Fetch pending notifications from the notifications table
    $pendingNotificationsStmt = $pdo->prepare("
        SELECT n.NotificationID, n.Message, n.RelatedID as BookingID, c.Name as client_name, 
               s.StudioName, sch.Sched_Date as booking_date, sch.Time_Start, sch.Time_End
        FROM notifications n
        LEFT JOIN bookings b ON n.RelatedID = b.BookingID
        LEFT JOIN clients c ON n.ClientID = c.ClientID
        LEFT JOIN studios s ON b.StudioID = s.StudioID
        LEFT JOIN schedules sch ON b.ScheduleID = sch.ScheduleID
        WHERE n.OwnerID = ?
        AND n.IsRead = 0
        AND (n.Type = 'Booking' OR n.Type LIKE 'booking_%')
        AND n.Created_At >= DATE_SUB('2025-05-20', INTERVAL 7 DAY)
        ORDER BY n.Created_At DESC
        LIMIT 5
    ");
    $pendingNotificationsStmt->execute([$ownerId]);
    $pendingNotifications = $pendingNotificationsStmt->fetchAll();
}

// Check for unread notifications
$notificationsStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM notifications 
    WHERE OwnerID = ? 
    AND IsRead = 0
");
$notificationsStmt->execute([$ownerId]);
$unreadNotifications = $notificationsStmt->fetchColumn();

// Helper functions
function getStatusBadge($status)
{
    switch (strtolower($status)) {
        case 'confirmed':
            return '<span class="badge bg-green-600">confirmed</span>';
        case 'pending':
            return '<span class="badge bg-yellow-600">pending</span>';
        case 'cancelled':
            return '<span class="badge bg-gray-600">cancelled</span>';
        default:
            return '<span class="badge bg-gray-600">' . htmlspecialchars($status) . '</span>';
    }
}

function getInitials($name)
{
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

function formatDateTime($date, $startTime, $endTime)
{
    $formattedDate = date('M j, Y', strtotime($date));
    $formattedStartTime = date('g:i A', strtotime($startTime));
    $formattedEndTime = date('g:i A', strtotime($endTime));
    return $formattedDate . ' • ' . $formattedStartTime . ' - ' . $formattedEndTime;
}

// Calendar data for JavaScript
$calendarData = [
    'currentMonth' => $currentMonth,
    'currentYear' => $currentYear,
    'calendarBookings' => $calendarBookings,
    'today' => 20, // May 20, 2025
    'currentMonthToday' => 5, // May
    'currentYearToday' => 2025
];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1" name="viewport" />
    <title>MuSeek Dashboard</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        :root {
            --primary-color: #e11d48;
            --primary-hover: #f43f5e;
            --header-height: 64px;
            --card-bg: #0f0f0f;
            --body-bg: #0a0a0a;
            --border-color: #222222;
            --text-primary: #ffffff;
            --text-secondary: #a1a1aa;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Inter", sans-serif;
            background-color: var(--body-bg);
            color: var(--text-primary);
            overflow-x: hidden;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background-color: var(--body-bg);
            border-right: 1px solid var(--border-color);
            z-index: 50;
            transition: transform 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .sidebar-hidden {
            transform: translateX(calc(-1 * var(--sidebar-width)));
        }

        .sidebar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 600;
            font-size: 1.25rem;
        }

        .sidebar-logo i {
            color: var(--primary-color);
        }

        .sidebar-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 1.25rem;
        }

        .sidebar-close:hover {
            color: var(--text-primary);
        }

        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 0;
        }

        .sidebar-menu {
            list-style: none;
        }

        .sidebar-menu-item {
            margin-bottom: 0.25rem;
        }

        .sidebar-menu-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            color: var(--text-primary);
            text-decoration: none;
            border-radius: 0.375rem;
            margin: 0 0.5rem;
            transition: background-color 0.2s;
        }

        .sidebar-menu-link:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }

        .sidebar-menu-link.active {
            background-color: var(--primary-color);
            color: white;
        }

        .sidebar-menu-link i {
            width: 1.25rem;
            text-align: center;
        }

        .sidebar-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border-color);
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            background-color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: white;
        }

        .user-info {
            flex: 1;
            min-width: 0;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.875rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-email {
            font-size: 0.75rem;
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-actions {
            display: flex;
            gap: 0.5rem;
        }

        .user-action-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 1rem;
            padding: 0.25rem;
            border-radius: 0.25rem;
            transition: background-color 0.2s;
        }

        .user-action-btn:hover {
            background-color: rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
        }

        /* Main Content Styles */
        .main-content {
            margin-left: var(--sidebar-collapsed-width);
            transition: margin-left 0.3s ease;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .main-content.full-width {
            margin-left: 0;
        }

        .header {
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background-color: var(--body-bg);
        }

        .toggle-sidebar {
            background: none;
            border: none;
            color: var(--text-primary);
            cursor: pointer;
            font-size: 1.25rem;
            margin-right: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            border-radius: 0.375rem;
            transition: background-color 0.2s;
        }

        .toggle-sidebar:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }

        .page-title {
            font-size: 1.25rem;
            font-weight: 600;
        }

        /* Notifications Section */
        .notifications-section {
            padding: 1rem;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }

        .notification {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem;
            background-color: rgba(245, 158, 11, 0.1);
            border: 1px solid var(--warning-color);
            border-radius: 0.375rem;
            margin-bottom: 0.5rem;
        }

        .notification-content {
            flex: 1;
            font-size: 0.875rem;
        }

        .notification-content a {
            color: var(--primary-color);
            text-decoration: none;
        }

        .notification-content a:hover {
            text-decoration: underline;
        }

        .notification-dismiss {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 1rem;
            padding: 0.25rem;
            border-radius: 0.25rem;
        }

        .notification-dismiss:hover {
            color: var(--text-primary);
            background-color: rgba(255, 255, 255, 0.05);
        }

        /* Dashboard Container */
        .dashboard-container {
            flex: 1;
            overflow-y: visible;
            overflow-x: hidden;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            min-height: calc(100vh - var(--header-height));
            height: auto;
            overscroll-behavior: contain;
        }

        /* Studio Overview Section */
        .studio-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.75rem;
        }

        .overview-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 0.75rem;
            display: flex;
            flex-direction: column;
        }

        .overview-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .overview-card-icon {
            width: 2rem;
            height: 2rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .overview-card-icon.bookings {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .overview-card-icon.clients {
            background-color: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }

        .overview-card-growth {
            font-size: 0.75rem;
            font-weight: 500;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .overview-card-growth.positive {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .overview-card-growth.negative {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }

        .overview-card-title {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
        }

        .overview-card-value {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .overview-card-subtitle {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            flex: 1;
            overflow: visible;
        }

        .dashboard-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .dashboard-card-header {
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dashboard-card-title {
            font-size: 0.875rem;
            font-weight: 600;
        }

        .dashboard-card-subtitle {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .dashboard-card-content {
            flex: 1;
            padding: 0.75rem;
            display: flex;
            flex-direction: column;
        }
        /* Allow sections to be collapsed without changing default appearance */
        .dashboard-card.collapsed .dashboard-card-content {
            display: none;
        }

        /* Calendar Styles */
        .calendar-container {
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .calendar-title {
            font-size: 0.875rem;
            font-weight: 600;
        }

        .calendar-nav {
            display: flex;
            gap: 0.5rem;
        }

        .calendar-nav-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 0.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s;
        }

        .calendar-nav-btn:hover {
            background-color: rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.1rem;
            flex: 1;
        }

        .calendar-weekday {
            text-align: center;
            font-size: 0.65rem;
            font-weight: 500;
            color: var(--text-secondary);
            padding: 0.15rem 0;
        }

        .calendar-day {
            aspect-ratio: auto;
            height: 1.6rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.65rem;
            border-radius: 0.2rem;
            cursor: pointer;
            position: relative;
            transition: background-color 0.2s;
        }

        .calendar-day:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }

        .calendar-day.other-month {
            color: var(--text-secondary);
            opacity: 0.5;
        }

        .calendar-day.today {
            background-color: var(--primary-color);
            color: white;
            font-weight: 600;
        }

        .calendar-day.has-bookings::after {
            content: '';
            position: absolute;
            bottom: 0.1rem;
            left: 50%;
            transform: translateX(-50%);
            width: 0.15rem;
            height: 0.15rem;
            border-radius: 50%;
            background-color: var(--primary-color);
        }

        .calendar-day.today.has-bookings::after {
            background-color: white;
        }

        .calendar-day.selected {
            border: 1px solid var(--primary-color);
        }

        .calendar-footer {
            margin-top: 0.5rem;
            padding: 0.5rem;
            border-top: 1px solid var(--border-color);
        }

        .calendar-footer-title {
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .calendar-footer-content {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        /* Bookings Styles */
        .booking-list {
            display: flex;
            flex-direction: column;
        }

        .booking-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }

        .booking-item:last-child {
            border-bottom: none;
        }

        .booking-avatar {
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.75rem;
            flex-shrink: 0;
        }

        .booking-details {
            flex: 1;
            min-width: 0;
        }

        .booking-client {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .booking-studio {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .booking-time {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
        }

        .booking-service {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .booking-status {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: capitalize;
        }

        .bg-green-600 {
            background-color: var(--success-color);
            color: white;
        }

        .bg-yellow-600 {
            background-color: var(--warning-color);
            color: white;
        }

        .bg-gray-600 {
            background-color: #4b5563;
            color: white;
        }

        .booking-amount {
            font-size: 0.875rem;
            font-weight: 600;
        }

        .view-all {
            display: block;
            text-align: center;
            padding: 0.75rem;
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            border-top: 1px solid var(--border-color);
            transition: background-color 0.2s;
        }

        .view-all:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }

        /* Responsive Styles */
        @media (max-width: 1024px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                z-index: 100;
                box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
            }

            .main-content {
                margin-left: 0;
            }

            .studio-overview {
                grid-template-columns: 1fr;
            }
        }

        /* Notification Badge */
        .notification-badge {
            position: relative;
        }

        .notification-badge::after {
            content: attr(data-count);
            position: absolute;
            top: -0.25rem;
            right: -0.25rem;
            background-color: var(--primary-color);
            color: white;
            font-size: 0.625rem;
            font-weight: 600;
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Empty State */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            text-align: center;
        }

        .empty-state-icon {
            font-size: 1.5rem;
            color: var(--text-secondary);
            margin-bottom: 0.75rem;
        }

        .empty-state-title {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .empty-state-description {
            font-size: 0.75rem;
            color: var(--text-secondary);
            max-width: 20rem;
            margin: 0 auto;
        }

       
    </style>
</head>

<body>
    <?php include __DIR__ . '/sidebar_netflix.php'; ?>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <header class="header">
            <h1 class="page-title">Dashboard</h1>
        </header>

        <div class="dashboard-container">
            <!-- Notifications Section -->
            <?php if (!empty($pendingNotifications)): ?>
                <section class="notifications-section">
                    <h2 class="section-title" style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem;">Pending Notifications</h2>
                    <?php foreach ($pendingNotifications as $notification): ?>
                        <div class="notification" data-notification-id="<?php echo $notification['NotificationID']; ?>">
                            <div class="notification-content">
                                <?php if (isset($notification['client_name']) && isset($notification['StudioName']) && isset($notification['booking_date'])): ?>
                                    <?php echo htmlspecialchars($notification['client_name']); ?>
                                    at <?php echo htmlspecialchars($notification['StudioName']); ?>
                                    on <?php echo formatDateTime($notification['booking_date'], $notification['Time_Start'], $notification['Time_End']); ?>.
                                    Please check GCash payment and confirm in
                                    <a href="bookings.php?booking_id=<?php echo $notification['BookingID']; ?>">Bookings</a>.
                                <?php else: ?>
                                    <?php echo htmlspecialchars($notification['Message']); ?>
                                    <a href="bookings.php?booking_id=<?php echo $notification['BookingID']; ?>">View Booking</a>.
                                <?php endif; ?>
                            </div>
                            <button class="notification-dismiss" title="Dismiss">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                    <a href="notifications.php" class="view-all">View all notifications</a>
                </section>
            <?php endif; ?>

            <!-- Studio Overview Section -->
            <section>
                <h2 class="section-title" style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem;">Studio Overview</h2>
                <div class="studio-overview">
                    <div class="overview-card">
                        <div class="overview-card-header">
                            <div class="overview-card-icon bookings">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div class="overview-card-growth <?php echo $bookingGrowth >= 0 ? 'positive' : 'negative'; ?>">
                                <i class="fas fa-<?php echo $bookingGrowth >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                                <span><?php echo abs($bookingGrowth); ?>%</span>
                            </div>
                        </div>
                        <div class="overview-card-title">Total Bookings</div>
                        <div class="overview-card-value"><?php echo $totalBookings; ?></div>
                        <div class="overview-card-subtitle">Last 30 days</div>
                    </div>

                    <div class="overview-card">
                        <div class="overview-card-header">
                            <div class="overview-card-icon clients">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="overview-card-growth <?php echo $clientGrowth >= 0 ? 'positive' : 'negative'; ?>">
                                <i class="fas fa-<?php echo $clientGrowth >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                                <span><?php echo abs($clientGrowth); ?>%</span>
                            </div>
                        </div>
                        <div class="overview-card-title">Active Clients</div>
                        <div class="overview-card-value"><?php echo $activeClients; ?></div>
                        <div class="overview-card-subtitle">Last 30 days</div>
                    </div>

                    <?php foreach ($studios as $studio): ?>
                        <div class="overview-card">
                            <div class="overview-card-header">
                                <div class="overview-card-icon bookings">
                                    <i class="fas fa-music"></i>
                                </div>
                            </div>
                            <div class="overview-card-title">Studio</div>
                            <div class="overview-card-value" style="font-size: 1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($studio['StudioName']); ?></div>
                            <div class="overview-card-subtitle">
                                <?php echo htmlspecialchars($studio['Time_IN']); ?> - <?php echo htmlspecialchars($studio['Time_OUT']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Calendar Card -->
                <div class="dashboard-card">
                    <div class="dashboard-card-header">
                        <div>
                            <div class="dashboard-card-title">Calendar</div>
                            <div class="dashboard-card-subtitle">Your bookings for the month</div>
                        </div>
                    </div>

                    <div class="dashboard-card-content">
                        <div class="calendar-container">
                            <div class="calendar-header">
                                <div class="calendar-title" id="currentMonthYear"></div>
                                <div class="calendar-nav">
                                    <button class="calendar-nav-btn" id="prevMonth">
                                        <i class="fas fa-chevron-left"></i>
                                    </button>
                                    <button class="calendar-nav-btn" id="nextMonth">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="calendar-grid" id="calendarGrid">
                                <!-- Weekday Headers -->
                                <div class="calendar-weekday">Su</div>
                                <div class="calendar-weekday">Mo</div>
                                <div class="calendar-weekday">Tu</div>
                                <div class="calendar-weekday">We</div>
                                <div class="calendar-weekday">Th</div>
                                <div class="calendar-weekday">Fr</div>
                                <div class="calendar-weekday">Sa</div>
                                <!-- Days will be populated by JavaScript -->
                            </div>

                            <div class="calendar-footer">
                                <div class="calendar-footer-title" id="selectedDateTitle"></div>
                                <div class="calendar-footer-content" id="selectedDateContent"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Bookings Card -->
                <div class="dashboard-card">
                    <div class="dashboard-card-header">
                        <div>
                            <div class="dashboard-card-title">Recent Bookings</div>
                            <div class="dashboard-card-subtitle">Your latest studio bookings</div>
                        </div>
                    </div>

                    <div class="dashboard-card-content">
                        <?php if (empty($recentBookings)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="fas fa-calendar-alt"></i>
                                </div>
                                <div class="empty-state-title">No bookings yet</div>
                                <div class="empty-state-description">
                                    Your recent bookings will appear here once clients start booking your studios.
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="booking-list">
                                <?php foreach ($recentBookings as $booking): ?>
                                    <div class="booking-item">
                                        <div class="booking-avatar">
                                            <?php echo getInitials($booking['client_name']); ?>
                                        </div>
                                        <div class="booking-details">
                                            <div class="booking-client"><?php echo htmlspecialchars($booking['client_name']); ?></div>
                                            <div class="booking-studio"><?php echo htmlspecialchars($booking['StudioName']); ?></div>
                                            <div class="booking-time">
                                                <?php echo formatDateTime($booking['booking_date'], $booking['Time_Start'], $booking['Time_End']); ?>
                                            </div>
                                            <div class="booking-service">
                                                <?php echo htmlspecialchars($booking['service_name'] ?? 'No service specified'); ?>
                                            </div>
                                        </div>
                                        <div class="booking-status">
                                            <?php echo getStatusBadge($booking['status']); ?>
                                            <div class="booking-amount">₱<?php echo number_format($booking['amount'], 2); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <a href="bookings.php" class="view-all">View all bookings</a>
                </div>
            </div>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Sidebar Toggle Functionality
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const toggleSidebarButton = document.getElementById('toggleSidebar');
        const closeSidebarButton = document.getElementById('closeSidebar');

        function toggleSidebar() {
            sidebar.classList.toggle('sidebar-hidden');
            mainContent.classList.toggle('full-width');
        }

        if (toggleSidebarButton) toggleSidebarButton.addEventListener('click', toggleSidebar);
        if (closeSidebarButton) closeSidebarButton.addEventListener('click', toggleSidebar);

        // Calendar Functionality
        const calendarGrid = document.getElementById('calendarGrid');
        const selectedDateTitle = document.getElementById('selectedDateTitle');
        const selectedDateContent = document.getElementById('selectedDateContent');
        const currentMonthYearElement = document.getElementById('currentMonthYear');
        const prevMonthButton = document.getElementById('prevMonth');
        const nextMonthButton = document.getElementById('nextMonth');

        // Initial calendar data from PHP
        const calendarData = <?php echo json_encode($calendarData); ?>;
        let currentViewMonth = calendarData.currentMonth;
        let currentViewYear = calendarData.currentYear;
        let calendarBookings = calendarData.calendarBookings;

        // Month names for display
        const monthNames = ["January", "February", "March", "April", "May", "June",
            "July", "August", "September", "October", "November", "December"
        ];

        // Format date for display
        function formatDate(date) {
            const options = {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            };
            return date.toLocaleDateString('en-US', options);
        }

        // Render calendar for given month and year
        function renderCalendar(month, year, bookings) {
            // Update month/year display
            currentMonthYearElement.textContent = `${monthNames[month-1]} ${year}`;

            // Calculate calendar details
            const firstDayOfMonth = new Date(year, month - 1, 1).getDay();
            const daysInMonth = new Date(year, month, 0).getDate();
            const prevMonth = month === 1 ? 12 : month - 1;
            const prevMonthYear = month === 1 ? year - 1 : year;
            const nextMonth = month === 12 ? 1 : month + 1;
            const nextMonthYear = month === 12 ? year + 1 : year;
            const daysInPrevMonth = new Date(prevMonthYear, prevMonth, 0).getDate();

            // Clear existing days (keep weekday headers)
            while (calendarGrid.children.length > 7) {
                calendarGrid.removeChild(calendarGrid.lastChild);
            }

            // Previous month days
            for (let i = 0; i < firstDayOfMonth; i++) {
                const day = daysInPrevMonth - firstDayOfMonth + i + 1;
                const dataDate = `${prevMonthYear}-${prevMonth}-${day}`;
                calendarGrid.innerHTML += `
                    <div class="calendar-day other-month" data-date="${dataDate}" data-count="0">${day}</div>
                `;
            }

            // Current month days
            for (let day = 1; day <= daysInMonth; day++) {
                const dataDate = `${year}-${month}-${day}`;
                const isToday = day === calendarData.today &&
                    month === calendarData.currentMonthToday &&
                    year === calendarData.currentYearToday;
                const bookingCount = bookings[day] || 0;
                const classes = `calendar-day ${isToday ? 'today' : ''} ${bookingCount > 0 ? 'has-bookings' : ''}`;

                calendarGrid.innerHTML += `
                    <div class="${classes}" data-date="${dataDate}" data-count="${bookingCount}">${day}</div>
                `;
            }

            // Next month days
            const totalDaysDisplayed = firstDayOfMonth + daysInMonth;
            const nextMonthDays = 42 - totalDaysDisplayed; // 6 rows
            for (let day = 1; day <= nextMonthDays; day++) {
                const dataDate = `${nextMonthYear}-${nextMonth}-${day}`;
                calendarGrid.innerHTML += `
                    <div class="calendar-day other-month" data-date="${dataDate}" data-count="0">${day}</div>
                `;
            }

            // Add event listeners to new days
            const calendarDays = document.querySelectorAll('.calendar-day');
            calendarDays.forEach(day => {
                day.addEventListener('click', function() {
                    calendarDays.forEach(d => d.classList.remove('selected'));
                    this.classList.add('selected');

                    const dateStr = this.getAttribute('data-date');
                    const date = new Date(dateStr);
                    const bookingCount = parseInt(this.getAttribute('data-count'));

                    selectedDateTitle.textContent = formatDate(date);
                    selectedDateContent.textContent = bookingCount > 0 ?
                        `${bookingCount} booking${bookingCount > 1 ? 's' : ''} on this day` :
                        'No bookings for this day';
                });
            });

            // Select today if in current view
            if (month === calendarData.currentMonthToday && year === calendarData.currentYearToday) {
                const todayElement = document.querySelector('.calendar-day.today');
                if (todayElement) {
                    todayElement.click();
                }
            }
        }

        // Fetch calendar bookings via AJAX
        function fetchCalendarBookings(month, year) {
            $.ajax({
                url: 'get_calendar_bookings.php',
                method: 'POST',
                data: {
                    month: month,
                    year: year,
                    studio_ids: <?php echo json_encode($studioIds); ?>
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        calendarBookings = response.bookings;
                        renderCalendar(month, year, calendarBookings);
                    } else {
                        console.error('Error fetching bookings:', response.message);
                        calendarBookings = {};
                        renderCalendar(month, year, calendarBookings);
                    }
                },
                error: function() {
                    console.error('AJAX error fetching calendar bookings');
                    calendarBookings = {};
                    renderCalendar(month, year, calendarBookings);
                }
            });
        }

        // Calendar navigation
        prevMonthButton.addEventListener('click', function() {
            currentViewMonth--;
            if (currentViewMonth < 1) {
                currentViewMonth = 12;
                currentViewYear--;
            }
            fetchCalendarBookings(currentViewMonth, currentViewYear);
        });

        nextMonthButton.addEventListener('click', function() {
            currentViewMonth++;
            if (currentViewMonth > 12) {
                currentViewMonth = 1;
                currentViewYear++;
            }
            fetchCalendarBookings(currentViewMonth, currentViewYear);
        });

        // Notification dismissal
        document.querySelectorAll('.notification-dismiss').forEach(button => {
            button.addEventListener('click', function() {
                const notification = this.closest('.notification');
                const notificationId = notification.getAttribute('data-notification-id');

                $.ajax({
                    url: 'mark_notification_read.php',
                    method: 'POST',
                    data: {
                        notification_id: notificationId
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            notification.remove();

                            // Update notification badge
                            const notificationBadge = document.querySelector('.notification-badge');
                            let currentCount = parseInt(notificationBadge?.getAttribute('data-count') || 0);
                            if (currentCount > 0) {
                                currentCount--;
                                if (currentCount > 0) {
                                    notificationBadge.setAttribute('data-count', currentCount);
                                } else {
                                    notificationBadge.classList.remove('notification-badge');
                                    notificationBadge.removeAttribute('data-count');
                                }
                            }

                            // Hide notifications section if empty
                            const notificationsSection = document.querySelector('.notifications-section');
                            if (!notificationsSection.querySelector('.notification')) {
                                notificationsSection.style.display = 'none';
                            }
                        } else {
                            console.error('Error dismissing notification:', response.message);
                        }
                    },
                    error: function() {
                        console.error('AJAX error dismissing notification');
                    }
                });
            });
        });

        // Initial render
        renderCalendar(currentViewMonth, currentViewYear, calendarBookings);

        // Check for mobile and initialize sidebar state
        function checkMobile() {
            if (window.innerWidth < 768) {
                sidebar.classList.add('sidebar-hidden');
                mainContent.classList.add('full-width');
            } else {
                sidebar.classList.remove('sidebar-hidden');
                mainContent.classList.remove('full-width');
            }
        }

        checkMobile();
        window.addEventListener('resize', checkMobile);

        // Non-visual interactivity: click headers to collapse/expand sections
        document.querySelectorAll('.dashboard-card-header').forEach(header => {
            const card = header.closest('.dashboard-card');
            if (!card) return;
            header.setAttribute('role', 'button');
            header.setAttribute('tabindex', '0');
            header.addEventListener('click', () => {
                card.classList.toggle('collapsed');
            });
            header.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    card.classList.toggle('collapsed');
                }
            });
        });
    </script>
</body>

</html>
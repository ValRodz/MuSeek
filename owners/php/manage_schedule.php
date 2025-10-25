<?php
// manage_schedule.php
include __DIR__ . '/db.php';

session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    // Redirect to login page if not logged in as owner
    header('Location: ../../auth/php/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if studio ID is provided
if (!isset($_GET['id'])) {
    // Fetch the first studio owned by this user
    $stmt = $conn->prepare("SELECT id FROM studios WHERE owner_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $studio = $result->fetch_assoc();
        $studio_id = $studio['id'];
    } else {
        // No studios found, redirect to create studio page
        header('Location: create_studio.php');
        exit();
    }
} else {
    $studio_id = $_GET['id'];
}

// Verify studio ownership
$stmt = $conn->prepare("SELECT * FROM studios WHERE id = ? AND owner_id = ?");
$stmt->bind_param("ii", $studio_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Studio not found or doesn't belong to this user
    header('Location: Home.php');
    exit();
}

$studio = $result->fetch_assoc();

// Fetch instructors for this studio
$stmt = $conn->prepare("SELECT * FROM instructors WHERE studio_id = ?");
$stmt->bind_param("i", $studio_id);
$stmt->execute();
$instructors_result = $stmt->get_result();
$instructors = [];
while ($row = $instructors_result->fetch_assoc()) {
    $instructors[] = $row;
}

// Fetch services for this studio
$stmt = $conn->prepare("SELECT * FROM services WHERE studio_id = ?");
$stmt->bind_param("i", $studio_id);
$stmt->execute();
$services_result = $stmt->get_result();
$services = [];
while ($row = $services_result->fetch_assoc()) {
    $services[] = $row;
}

// Fetch schedule for this studio
$stmt = $conn->prepare("SELECT s.*, i.name as instructor_name, sv.name as service_name
                        FROM schedule s
                        JOIN instructors i ON s.instructor_id = i.id
                        JOIN services sv ON s.service_id = sv.id
                        WHERE s.studio_id = ?
                        ORDER BY FIELD(s.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), s.start_time");
$stmt->bind_param("i", $studio_id);
$stmt->execute();
$schedule_result = $stmt->get_result();
$schedule = [];
while ($row = $schedule_result->fetch_assoc()) {
    $schedule[] = $row;
}

// Process form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_schedule'])) {
        // Add new schedule
        $day_of_week = $_POST['day_of_week'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $instructor_id = $_POST['instructor_id'];
        $service_id = $_POST['service_id'];

        // Validate time
        if ($start_time >= $end_time) {
            $error_message = "End time must be after start time.";
        } else {
            // Check for schedule conflicts
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM schedule
                                    WHERE studio_id = ? AND day_of_week = ? AND instructor_id = ?
                                    AND ((start_time &lt;= ? AND end_time > ?) OR (start_time &lt; ? AND end_time >= ?))");
            $stmt->bind_param("isisss", $studio_id, $day_of_week, $instructor_id, $end_time, $start_time, $end_time, $start_time);
            $stmt->execute();
            $conflict_count = $stmt->get_result()->fetch_assoc()['count'];

            if ($conflict_count > 0) {
                $error_message = "Schedule conflict detected. The instructor is already scheduled during this time.";
            } else {
                $stmt = $conn->prepare("INSERT INTO schedule (studio_id, instructor_id, service_id, day_of_week, start_time, end_time)
                                        VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iiisss", $studio_id, $instructor_id, $service_id, $day_of_week, $start_time, $end_time);

                if ($stmt->execute()) {
                    $success_message = "Schedule added successfully!";
                    // Refresh schedule list
                    $stmt = $conn->prepare("SELECT s.*, i.name as instructor_name, sv.name as service_name
                                            FROM schedule s
                                            JOIN instructors i ON s.instructor_id = i.id
                                            JOIN services sv ON s.service_id = sv.id
                                            WHERE s.studio_id = ?
                                            ORDER BY FIELD(s.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), s.start_time");
                    $stmt->bind_param("i", $studio_id);
                    $stmt->execute();
                    $schedule_result = $stmt->get_result();
                    $schedule = [];
                    while ($row = $schedule_result->fetch_assoc()) {
                        $schedule[] = $row;
                    }
                } else {
                    $error_message = "Error adding schedule: " . $conn->error;
                }
            }
        }
    } elseif (isset($_POST['update_schedule'])) {
        // Update existing schedule
        $schedule_id = $_POST['schedule_id'];
        $day_of_week = $_POST['day_of_week'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $instructor_id = $_POST['instructor_id'];
        $service_id = $_POST['service_id'];

        // Validate time
        if ($start_time >= $end_time) {
            $error_message = "End time must be after start time.";
        } else {
            // Check for schedule conflicts (excluding this schedule)
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM schedule
                                    WHERE studio_id = ? AND day_of_week = ? AND instructor_id = ? AND id != ?
                                    AND ((start_time &lt;= ? AND end_time > ?) OR (start_time &lt; ? AND end_time >= ?))");
            $stmt->bind_param("isiissss", $studio_id, $day_of_week, $instructor_id, $schedule_id, $end_time, $start_time, $end_time, $start_time);
            $stmt->execute();
            $conflict_count = $stmt->get_result()->fetch_assoc()['count'];

            if ($conflict_count > 0) {
                $error_message = "Schedule conflict detected. The instructor is already scheduled during this time.";
            } else {
                $stmt = $conn->prepare("UPDATE schedule SET day_of_week = ?, start_time = ?, end_time = ?, instructor_id = ?, service_id = ?
                                        WHERE id = ? AND studio_id = ?");
                $stmt->bind_param("sssiiii", $day_of_week, $start_time, $end_time, $instructor_id, $service_id, $schedule_id, $studio_id);

                if ($stmt->execute()) {
                    $success_message = "Schedule updated successfully!";
                    // Refresh schedule list
                    $stmt = $conn->prepare("SELECT s.*, i.name as instructor_name, sv.name as service_name
                                            FROM schedule s
                                            JOIN instructors i ON s.instructor_id = i.id
                                            JOIN services sv ON s.service_id = sv.id
                                            WHERE s.studio_id = ?
                                            ORDER BY FIELD(s.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), s.start_time");
                    $stmt->bind_param("i", $studio_id);
                    $stmt->execute();
                    $schedule_result = $stmt->get_result();
                    $schedule = [];
                    while ($row = $schedule_result->fetch_assoc()) {
                        $schedule[] = $row;
                    }
                } else {
                    $error_message = "Error updating schedule: " . $conn->error;
                }
            }
        }
    } elseif (isset($_POST['delete_schedule'])) {
        // Delete schedule
        $schedule_id = $_POST['schedule_id'];

        // Check if schedule is used in any bookings
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM bookings
                                WHERE studio_id = ? AND instructor_id = ? AND booking_date >= CURDATE()");
        $stmt->bind_param("ii", $studio_id, $instructor_id);
        $stmt->execute();
        $booking_count = $stmt->get_result()->fetch_assoc()['count'];

        if ($booking_count > 0) {
            $error_message = "Cannot delete schedule as there are upcoming bookings associated with it.";
        } else {
            $stmt = $conn->prepare("DELETE FROM schedule WHERE id = ? AND studio_id = ?");
            $stmt->bind_param("ii", $schedule_id, $studio_id);

            if ($stmt->execute()) {
                $success_message = "Schedule deleted successfully!";
                // Refresh schedule list
                $stmt = $conn->prepare("SELECT s.*, i.name as instructor_name, sv.name as service_name
                                        FROM schedule s
                                        JOIN instructors i ON s.instructor_id = i.id
                                        JOIN services sv ON s.service_id = sv.id
                                        WHERE s.studio_id = ?
                                        ORDER BY FIELD(s.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), s.start_time");
                $stmt->bind_param("i", $studio_id);
                $stmt->execute();
                $schedule_result = $stmt->get_result();
                $schedule = [];
                while ($row = $schedule_result->fetch_assoc()) {
                    $schedule[] = $row;
                }
            } else {
                $error_message = "Error deleting schedule: " . $conn->error;
            }
        }
    }
}

// Group schedule by day of week
$schedule_by_day = [
    'Monday' => [],
    'Tuesday' => [],
    'Wednesday' => [],
    'Thursday' => [],
    'Friday' => [],
    'Saturday' => [],
    'Sunday' => []
];

foreach ($schedule as $item) {
    $schedule_by_day[$item['day_of_week']][] = $item;
}
?>

&lt;!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Schedule - <?php echo htmlspecialchars($studio['name']); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .schedule-table th, .schedule-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }

        .schedule-table th {
            background-color: #f2f2f2;
        }

        .day-header {
            background-color: #e0e0e0;
            font-weight: bold;
            padding: 10px;
            margin-top: 20px;
            margin-bottom: 10px;
            border-radius: 4px;
        }

        .no-schedule {
            font-style: italic;
            color: #666;
            padding: 10px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Manage Schedule</h1>
            <nav>
                <ul>
                    <li><a href="Home.php">Home</a></li>
                    <li><a href="browse.php">Browse Studios</a></li>
                    <li><a href="booking.php">Bookings</a></li>
                    <li><a href="gallery.html">Gallery</a></li>
                    <li><a href="blog.html">Blog</a></li>
                    <li><a href="about.html">About</a></li>
                    <li><a href="contact.html">Contact</a></li>
                </ul>
            </nav>
        </header>

        <main>
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success"><?php echo $success_message; ?></div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-error"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <section class="studio-management">
                <div class="management-sidebar">
                    <h2>Management Menu</h2>
                    <ul>
                        <li><a href="manage_studio.php?id=<?php echo $studio_id; ?>">Studio Details</a></li>
                        <li><a href="manage_services.php?id=<?php echo $studio_id; ?>">Services</a></li>
                        <li><a href="manage_instructors.php?id=<?php echo $studio_id; ?>">Instructors</a></li>
                        <li class="active"><a href="manage_schedule.php?id=<?php echo $studio_id; ?>">Schedule</a></li>
                        <li><a href="manage_bookings.php?id=<?php echo $studio_id; ?>">Bookings</a></li>
                    </ul>
                </div>

                <div class="management-content">
                    <h2>Schedule for <?php echo htmlspecialchars($studio['name']); ?></h2>

                    <?php if (count($instructors) === 0 || count($services) === 0): ?>
                        <div class="alert alert-warning">
                            <?php if (count($instructors) === 0): ?>
                                <p>You need to add instructors before you can create a schedule. <a href="manage_instructors.php?id=<?php echo $studio_id; ?>">Add Instructors</a></p>
                            <?php endif; ?>

                            <?php if (count($services) === 0): ?>
                                <p>You need to add services before you can create a schedule. <a href="manage_services.php?id=<?php echo $studio_id; ?>">Add Services</a></p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="schedule-list">
                            <h3>Current Schedule</h3>

                            <?php foreach ($schedule_by_day as $day => $day_schedule): ?>
                                <div class="day-header"><?php echo $day; ?></div>

                                <?php if (count($day_schedule) > 0): ?>
                                    <table class="schedule-table">
                                        <thead>
                                            <tr>
                                                <th>Start Time</th>
                                                <th>End Time</th>
                                                <th>Instructor</th>
                                                <th>Service</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($day_schedule as $item): ?>
                                                <tr>
                                                    <td><?php echo date('h: i A', strtotime($item['start_time'])); ?></td>
                                                    <td><?php echo date('h: i A', strtotime($item['end_time'])); ?></td>
                                                    <td><?php echo htmlspecialchars($item['instructor_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($item['service_name']); ?></td>
                                                    <td>
                                                        <button class="btn btn-small" onclick="editSchedule(<?php echo $item['id']; ?>)">Edit</button>
                                                        <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this schedule?');">
                                                            <input type="hidden" name="delete_schedule" value="1">
                                                            <input type="hidden" name="schedule_id" value="<?php echo $item['id']; ?>">
                                                            <button type="submit" class="btn btn-small btn-danger">Delete</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div class="no-schedule">No schedule for this day.</div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <div class="add-schedule-form">
                            <h3>Add New Schedule</h3>
                            <form action="manage_schedule.php?id=<?php echo $studio_id; ?>" method="post" class="schedule-form">
                                <input type="hidden" name="add_schedule" value="1">

                                <div class="form-row">
                                    <div class="form-group half">
                                        <label for="day_of_week">Day of Week</label>
                                        <select id="day_of_week" name="day_of_week" required>
                                            <option value="Monday">Monday</option>
                                            <option value="Tuesday">Tuesday</option>
                                            <option value="Wednesday">Wednesday</option>
                                            <option value="Thursday">Thursday</option>
                                            <option value="Friday">Friday</option>
                                            <option value="Saturday">Saturday</option>
                                            <option value="Sunday">Sunday</option>
                                        </select>
                                    </div>

                                    <div class="form-group half">
                                        <label for="instructor_id">Instructor</label>
                                        <select id="instructor_id" name="instructor_id" required>
                                            <?php foreach ($instructors as $instructor): ?>
                                                <option value="<?php echo $instructor['id']; ?>"><?php echo htmlspecialchars($instructor['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group half">
                                        <label for="start_time">Start Time</label>
                                        <input type="time" id="start_time" name="start_time" required>
                                    </div>

                                    <div class="form-group half">
                                        <label for="end_time">End Time</label>
                                        <input type="time" id="end_time" name="end_time" required>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="service_id">Service</label>
                                    <select id="service_id" name="service_id" required>
                                        <?php foreach ($services as $service): ?>
                                            <option value="<?php echo $service['id']; ?>"><?php echo htmlspecialchars($service['name']); ?> (<?php echo $service['duration']; ?> min)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">Add Schedule</button>
                                </div>
                            </form>
                        </div>

                        &lt;!-- Edit Schedule Modal -->
                        <div id="editScheduleModal" class="modal">
                            <div class="modal-content">
                                <span class="close">&times;</span>
                                <h3>Edit Schedule</h3>
                                <form action="manage_schedule.php?id=<?php echo $studio_id; ?>" method="post" class="schedule-form">
                                    <input type="hidden" name="update_schedule" value="1">
                                    <input type="hidden" id="edit_schedule_id" name="schedule_id" value="">

                                    <div class="form-row">
                                        <div class="form-group half">
                                            <label for="edit_day_of_week">Day of Week</label>
                                            <select id="edit_day_of_week" name="day_of_week" required>
                                                <option value="Monday">Monday</option>
                                                <option value="Tuesday">Tuesday</option>
                                                <option value="Wednesday">Wednesday</option>
                                                <option value="Thursday">Thursday</option>
                                                <option value="Friday">Friday</option>
                                                <option value="Saturday">Saturday</option>
                                                <option value="Sunday">Sunday</option>
                                            </select>
                                        </div>

                                        <div class="form-group half">
                                            <label for="edit_instructor_id">Instructor</label>
                                            <select id="edit_instructor_id" name="instructor_id" required>
                                                <?php foreach ($instructors as $instructor): ?>
                                                    <option value="<?php echo $instructor['id']; ?>"><?php echo htmlspecialchars($instructor['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group half">
                                            <label for="edit_start_time">Start Time</label>
                                            <input type="time" id="edit_start_time" name="start_time" required>
                                        </div>

                                        <div class="form-group half">
                                            <label for="edit_end_time">End Time</label>
                                            <input type="time" id="edit_end_time" name="end_time" required>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="edit_service_id">Service</label>
                                        <select id="edit_service_id" name="service_id" required>
                                            <?php foreach ($services as $service): ?>
                                                <option value="<?php echo $service['id']; ?>"><?php echo htmlspecialchars($service['name']); ?> (<?php echo $service['duration']; ?> min)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-actions">
                                        <button type="submit" class="btn btn-primary">Update Schedule</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>

        <footer>
            <p>&copy; 2023 Studio Booking System. All rights reserved.</p>
        </footer>
    </div>

    <script>
        // Get the modal
        var modal = document.getElementById("editScheduleModal");

        // Get the <span> element that closes the modal
        var span = document.getElementsByClassName("close")[0];

        // When the user clicks on <span> (x), close the modal
        span.onclick = function() {
            modal.style.display = "none";
        }

        // When the user clicks anywhere outside of the modal, close it
        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }

        // Function to edit schedule
        function editSchedule(scheduleId) {
            // Get schedule data
            <?php
            echo "var schedule = " . json_encode($schedule) . ";";
            ?>

            // Find the schedule
            var scheduleItem = schedule.find(function(s) {
                return s.id == scheduleId;
            });

            if (scheduleItem) {
                // Fill the form
                document.getElementById("edit_schedule_id").value = scheduleItem.id;
                document.getElementById("edit_day_of_week").value = scheduleItem.day_of_week;
                document.getElementById("edit_instructor_id").value = scheduleItem.instructor_id;
                document.getElementById("edit_service_id").value = scheduleItem.service_id;
                document.getElementById("edit_start_time").value = scheduleItem.start_time;
                document.getElementById("edit_end_time").value = scheduleItem.end_time;

                // Show the modal
                modal.style.display = "block";
            }
        }
    </script>
</body>
</html>


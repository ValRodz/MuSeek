<?php
session_start();
include '../../shared/config/db pdo.php';
require_once __DIR__ . '/validation_utils.php';

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    // Redirect to login page if not logged in as owner
    header('Location: ../../auth/php/login.php');
    exit();
}

$owner_id = $_SESSION['user_id'];

// Get studio ID from URL or fetch first studio
$studio_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($studio_id <= 0) {
    // Fetch the first studio owned by this user
    $stmt = $pdo->prepare("SELECT StudioID FROM studios WHERE OwnerID = ? LIMIT 1");
    $stmt->execute([$owner_id]);
    $studio = $stmt->fetch();
    
    if ($studio) {
        $studio_id = $studio['StudioID'];
    } else {
        header("Location: manage_studio.php");
        exit();
    }
}

// Verify studio ownership
$stmt = $pdo->prepare("SELECT * FROM studios WHERE StudioID = ? AND OwnerID = ?");
$stmt->execute([$studio_id, $owner_id]);
$studio = $stmt->fetch();

if (!$studio) {
    header("Location: manage_studio.php");
    exit();
}

// Initialize messages
$success_message = '';
$error_message = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_instructor'])) {
        // Validate input
        $validation_rules = [
            'name' => ['type' => 'name', 'required' => true],
            'email' => ['type' => 'email', 'required' => true],
            'phone' => ['type' => 'phone', 'required' => true],
            'specialization' => ['type' => 'service_name', 'required' => true],
            'experience' => ['type' => 'price', 'required' => true]
        ];
        
        $validation_errors = ValidationUtils::validateForm($_POST, $validation_rules);
        
        if (empty($validation_errors)) {
            try {
                $name = ValidationUtils::sanitizeInput($_POST['name']);
                $email = ValidationUtils::sanitizeInput($_POST['email']);
                $phone = ValidationUtils::sanitizeInput($_POST['phone']);
                $specialization = ValidationUtils::sanitizeInput($_POST['specialization']);
                $experience = (int)$_POST['experience'];
                $bio = ValidationUtils::sanitizeInput($_POST['bio'] ?? '');
                
                $stmt = $pdo->prepare("INSERT INTO instructors (Name, Email, Phone, Specialization, Experience, Bio) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $email, $phone, $specialization, $experience, $bio]);
                
                $instructor_id = $pdo->lastInsertId();
                
                // Link instructor to studio
                $link_stmt = $pdo->prepare("INSERT INTO studio_instructors (StudioID, InstructorID) VALUES (?, ?)");
                $link_stmt->execute([$studio_id, $instructor_id]);
                
                $success_message = "Instructor added successfully!";
            } catch (Exception $e) {
                $error_message = "Error adding instructor: " . $e->getMessage();
            }
        } else {
            $error_message = ValidationUtils::formatErrors($validation_errors);
        }
    } elseif (isset($_POST['update_instructor'])) {
        $validation_rules = [
            'name' => ['type' => 'name', 'required' => true],
            'email' => ['type' => 'email', 'required' => true],
            'phone' => ['type' => 'phone', 'required' => true],
            'specialization' => ['type' => 'service_name', 'required' => true],
            'experience' => ['type' => 'price', 'required' => true]
        ];
        
        $validation_errors = ValidationUtils::validateForm($_POST, $validation_rules);
        
        if (empty($validation_errors)) {
            try {
                $instructor_id = (int)$_POST['instructor_id'];
                $name = ValidationUtils::sanitizeInput($_POST['name']);
                $email = ValidationUtils::sanitizeInput($_POST['email']);
                $phone = ValidationUtils::sanitizeInput($_POST['phone']);
                $specialization = ValidationUtils::sanitizeInput($_POST['specialization']);
                $experience = (int)$_POST['experience'];
                $bio = ValidationUtils::sanitizeInput($_POST['bio'] ?? '');
                
                $stmt = $pdo->prepare("UPDATE instructors SET Name = ?, Email = ?, Phone = ?, Specialization = ?, Experience = ?, Bio = ? WHERE InstructorID = ?");
                $stmt->execute([$name, $email, $phone, $specialization, $experience, $bio, $instructor_id]);
                
                $success_message = "Instructor updated successfully!";
            } catch (Exception $e) {
                $error_message = "Error updating instructor: " . $e->getMessage();
            }
        } else {
            $error_message = ValidationUtils::formatErrors($validation_errors);
        }
    } elseif (isset($_POST['delete_instructor'])) {
        try {
            $instructor_id = (int)$_POST['instructor_id'];
            
            // Check if instructor is assigned to any schedules
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE InstructorID = ?");
            $check_stmt->execute([$instructor_id]);
            $schedule_count = $check_stmt->fetchColumn();
            
            if ($schedule_count > 0) {
                $error_message = "Cannot delete instructor as they are assigned to existing schedules.";
            } else {
                // Remove from studio_instructors first
                $unlink_stmt = $pdo->prepare("DELETE FROM studio_instructors WHERE InstructorID = ? AND StudioID = ?");
                $unlink_stmt->execute([$instructor_id, $studio_id]);
                
                // Delete the instructor
                $delete_stmt = $pdo->prepare("DELETE FROM instructors WHERE InstructorID = ?");
                $delete_stmt->execute([$instructor_id]);
                
                $success_message = "Instructor deleted successfully!";
            }
        } catch (Exception $e) {
            $error_message = "Error deleting instructor: " . $e->getMessage();
        }
    }
}

// Fetch instructors for this studio
$instructors_stmt = $pdo->prepare("
    SELECT i.*, si.StudioID 
    FROM instructors i
    JOIN studio_instructors si ON i.InstructorID = si.InstructorID
    WHERE si.StudioID = ?
    ORDER BY i.Name
");
$instructors_stmt->execute([$studio_id]);
$instructors = $instructors_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Instructors - MuSeek Studio Management</title>
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="style.css">
    
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

        .manage-instructors-container {
            padding: 40px;
            max-width: 1200px;
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

        .form-container {
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 40px;
            border: 1px solid #333;
            position: relative;
            overflow: hidden;
        }

        .form-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--netflix-red), #ff6b6b);
        }

        .form-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--netflix-white);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }

        .form-title i {
            margin-right: 10px;
            color: var(--netflix-red);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            color: var(--netflix-white);
            font-weight: 500;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #333;
            border-radius: 8px;
            background: var(--netflix-black);
            color: var(--netflix-white);
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--netflix-red);
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.2);
        }

        .form-control::placeholder {
            color: var(--netflix-gray);
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
            color: var(--netflix-white);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #d40813, #e50914);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(229, 9, 20, 0.3);
        }

        .btn-secondary {
            background: var(--netflix-dark-gray);
            color: var(--netflix-white);
            border: 1px solid #333;
        }

        .btn-secondary:hover {
            background: var(--netflix-gray);
            border-color: var(--netflix-light-gray);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ff6b6b, #ff5252);
            color: var(--netflix-white);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #ff5252, #f44336);
            transform: translateY(-1px);
        }

        .btn-small {
            padding: 8px 16px;
            font-size: 12px;
        }

        .instructors-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .instructor-card {
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #333;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .instructor-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--netflix-red), #ff6b6b);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .instructor-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
            border-color: var(--netflix-red);
        }

        .instructor-card:hover::before {
            transform: scaleX(1);
        }

        .instructor-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .instructor-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 20px;
            color: var(--netflix-white);
        }

        .instructor-info h3 {
            color: var(--netflix-white);
            margin: 0 0 5px 0;
            font-size: 1.1rem;
        }

        .instructor-info p {
            color: var(--netflix-light-gray);
            margin: 0;
            font-size: 0.9rem;
        }

        .instructor-details {
            margin-bottom: 15px;
        }

        .detail-item {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .detail-item i {
            width: 20px;
            color: var(--netflix-red);
            margin-right: 10px;
        }

        .detail-item span {
            color: var(--netflix-light-gray);
        }

        .specialization {
            background: rgba(229, 9, 20, 0.1);
            color: var(--netflix-red);
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 10px;
        }

        .instructor-actions {
            display: flex;
            gap: 8px;
        }

        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: rgba(70, 211, 105, 0.1);
            border: 1px solid var(--success-green);
            color: var(--success-green);
        }

        .alert-danger {
            background: rgba(255, 107, 107, 0.1);
            border: 1px solid #ff6b6b;
            color: #ff6b6b;
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

        .empty-state p {
            margin-bottom: 20px;
            font-size: 1rem;
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
            
            .manage-instructors-container {
                padding: 20px;
            }
            
            .page-title {
                font-size: 2rem;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .instructors-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar_netflix.php'; ?>

    <div class="main-content">
        <div class="manage-instructors-container">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Manage Instructors</h1>
                <p class="page-subtitle">Add, edit, and manage instructors for <?php echo htmlspecialchars($studio['StudioName']); ?></p>
            </div>

            <!-- Success/Error Messages -->
            <?php if ($success_message): ?>
                <div class="alert alert-success fade-in">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-danger fade-in">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <!-- Add Instructor Form -->
            <div class="form-container fade-in">
                <h2 class="form-title">
                    <i class="fas fa-user-plus"></i>
                    Add New Instructor
                </h2>
                
                <form method="POST" class="instructor-form">
                    <input type="hidden" name="add_instructor" value="1">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="name" class="form-label">Full Name *</label>
                            <input type="text" 
                                   id="name" 
                                   name="name" 
                                   class="form-control" 
                                   placeholder="Enter instructor's full name"
                                   required
                                   maxlength="50">
                        </div>
                        
                        <div class="form-group">
                            <label for="email" class="form-label">Email Address *</label>
                            <input type="email" 
                                   id="email" 
                                   name="email" 
                                   class="form-control" 
                                   placeholder="instructor@example.com"
                                   required
                                   maxlength="100">
                        </div>
                        
                        <div class="form-group">
                            <label for="phone" class="form-label">Phone Number *</label>
                            <input type="tel" 
                                   id="phone" 
                                   name="phone" 
                                   class="form-control" 
                                   placeholder="09xxxxxxxxx"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="specialization" class="form-label">Specialization *</label>
                            <input type="text" 
                                   id="specialization" 
                                   name="specialization" 
                                   class="form-control" 
                                   placeholder="e.g., Yoga, Pilates, Fitness"
                                   required
                                   maxlength="50">
                        </div>
                        
                        <div class="form-group">
                            <label for="experience" class="form-label">Years of Experience *</label>
                            <input type="number" 
                                   id="experience" 
                                   name="experience" 
                                   class="form-control" 
                                   placeholder="5"
                                   min="0"
                                   max="50"
                                   required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="bio" class="form-label">Bio/Description</label>
                        <textarea id="bio" 
                                  name="bio" 
                                  class="form-control" 
                                  rows="3" 
                                  placeholder="Tell us about the instructor's background and expertise"
                                  maxlength="500"></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i>
                        Add Instructor
                    </button>
                </form>
            </div>

            <!-- Instructors List -->
            <div class="instructors-section fade-in">
                <h2 style="color: var(--netflix-white); margin-bottom: 20px; display: flex; align-items: center;">
                    <i class="fas fa-users" style="margin-right: 10px; color: var(--netflix-red);"></i>
                    Current Instructors (<?php echo count($instructors); ?>)
                </h2>
                
                <?php if (count($instructors) > 0): ?>
                    <div class="instructors-grid">
                        <?php foreach ($instructors as $instructor): ?>
                            <div class="instructor-card">
                                <div class="instructor-header">
                                    <div class="instructor-avatar">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <div class="instructor-info">
                                        <h3><?php echo htmlspecialchars($instructor['Name']); ?></h3>
                                        <p><?php echo htmlspecialchars($instructor['Email']); ?></p>
                                    </div>
                                </div>
                                
                                <div class="specialization">
                                    <?php echo htmlspecialchars($instructor['Specialization']); ?>
                                </div>
                                
                                <div class="instructor-details">
                                    <div class="detail-item">
                                        <i class="fas fa-phone"></i>
                                        <span><?php echo htmlspecialchars($instructor['Phone']); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <i class="fas fa-calendar-alt"></i>
                                        <span><?php echo $instructor['Experience']; ?> years experience</span>
                                    </div>
                                    <?php if (!empty($instructor['Bio'])): ?>
                                        <div class="detail-item">
                                            <i class="fas fa-info-circle"></i>
                                            <span><?php echo htmlspecialchars(substr($instructor['Bio'], 0, 100)) . (strlen($instructor['Bio']) > 100 ? '...' : ''); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="instructor-actions">
                                    <button class="btn btn-secondary btn-small" 
                                            onclick="editInstructor(<?php echo $instructor['InstructorID']; ?>)">
                                        <i class="fas fa-edit"></i>
                                        Edit
                                    </button>
                                    <form method="POST" style="display: inline;" 
                                          onsubmit="return confirm('Are you sure you want to delete this instructor?');">
                                        <input type="hidden" name="delete_instructor" value="1">
                                        <input type="hidden" name="instructor_id" value="<?php echo $instructor['InstructorID']; ?>">
                                        <button type="submit" class="btn btn-danger btn-small">
                                            <i class="fas fa-trash"></i>
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h3>No Instructors Found</h3>
                        <p>Add your first instructor to get started with managing your studio team.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Edit Instructor Modal (Hidden by default) -->
    <div id="editModal" style="display: none;">
        <div class="modal-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; display: flex; align-items: center; justify-content: center;">
            <div class="modal-content" style="background: var(--netflix-dark-gray); border-radius: 12px; padding: 30px; max-width: 500px; width: 90%; border: 1px solid #333; max-height: 90vh; overflow-y: auto; box-sizing: border-box;">
                <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="color: var(--netflix-white); margin: 0;">Edit Instructor</h3>
                    <button onclick="closeEditModal()" class="close-btn" style="background: none; border: none; color: var(--netflix-light-gray); font-size: 24px; cursor: pointer;">&times;</button>
                </div>
                <form id="editForm" method="POST">
                    <input type="hidden" name="update_instructor" value="1">
                    <input type="hidden" name="instructor_id" id="edit_instructor_id">
                    
                    <div class="form-group">
                        <label for="edit_name" class="form-label">Full Name *</label>
                        <input type="text" id="edit_name" name="name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_email" class="form-label">Email Address *</label>
                        <input type="email" id="edit_email" name="email" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_phone" class="form-label">Phone Number *</label>
                        <input type="tel" id="edit_phone" name="phone" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_specialization" class="form-label">Specialization *</label>
                        <input type="text" id="edit_specialization" name="specialization" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_experience" class="form-label">Years of Experience *</label>
                        <input type="number" id="edit_experience" name="experience" class="form-control" min="0" max="50" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_bio" class="form-label">Bio/Description</label>
                        <textarea id="edit_bio" name="bio" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="modal-actions" style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                        <button type="button" onclick="closeEditModal()" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Instructor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function editInstructor(instructorId) {
            // This would typically fetch instructor data via AJAX
            // For now, we'll show the modal
            document.getElementById('editModal').style.display = 'block';
            document.getElementById('edit_instructor_id').value = instructorId;
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('editModal');
            if (event.target === modal) {
                closeEditModal();
            }
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.remove();
                }, 500);
            });
        }, 5000);

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

<?php
session_start();
include '../../shared/config/db pdo.php';

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    // Redirect to login page if not logged in as owner
    header('Location: ../../auth/php/login.php');
    exit();
}

$owner_id = $_SESSION['user_id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studio_name = trim($_POST['studio_name']);
    $location = trim($_POST['location']);
    $time_in = $_POST['time_in'];
    $time_out = $_POST['time_out'];
    $description = trim($_POST['description']);
    
    if (!empty($studio_name) && !empty($location)) {
        try {
            $insert_studio = $pdo->prepare("
                INSERT INTO studios (StudioName, Loc_Desc, Time_IN, Time_OUT, Description, OwnerID)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $insert_studio->execute([$studio_name, $location, $time_in, $time_out, $description, $owner_id]);
            
            $_SESSION['success_message'] = "Studio added successfully!";
            header("Location: dashboard_netflix.php");
            exit();
            
        } catch (Exception $e) {
            $error_message = "Error adding studio. Please try again.";
        }
    } else {
        $error_message = "Please fill in all required fields.";
    }
}

// Get existing studios
$studios_query = "SELECT * FROM studios WHERE OwnerID = ? ORDER BY StudioName";
$studios_stmt = $pdo->prepare($studios_query);
$studios_stmt->execute([$owner_id]);
$studios = $studios_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Studios - MuSeek Studio Management</title>
    
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
            background-color: var(--netflix-black);
            color: var(--netflix-white);
            font-family: 'Netflix Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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

        .manage-studio-container {
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
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--netflix-white);
            margin-bottom: 8px;
        }

        .form-control {
            background: var(--netflix-black);
            border: 1px solid #333;
            color: var(--netflix-white);
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            border-color: var(--netflix-red);
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.2);
        }

        .form-control::placeholder {
            color: var(--netflix-gray);
        }

        .btn {
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
            border: none;
            color: var(--netflix-white);
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(229, 9, 20, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--netflix-gray), #666);
        }

        .btn-secondary:hover {
            box-shadow: 0 8px 25px rgba(102, 102, 102, 0.3);
        }

        .studios-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .studio-card {
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #333;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .studio-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--netflix-red), #ff6b6b);
        }

        .studio-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
            border-color: var(--netflix-red);
        }

        .studio-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--netflix-white);
            margin-bottom: 10px;
        }

        .studio-location {
            font-size: 0.9rem;
            color: var(--netflix-light-gray);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }

        .studio-location i {
            margin-right: 8px;
            color: var(--netflix-red);
        }

        .studio-hours {
            font-size: 0.8rem;
            color: var(--netflix-gray);
            margin-bottom: 15px;
        }

        .studio-description {
            font-size: 0.9rem;
            color: var(--netflix-light-gray);
            line-height: 1.4;
            margin-bottom: 20px;
        }

        .studio-actions {
            display: flex;
            gap: 10px;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 12px;
        }

        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            border: 1px solid;
            display: flex;
            align-items: center;
        }

        .alert-success {
            background: rgba(70, 211, 105, 0.1);
            border-color: var(--success-green);
            color: var(--success-green);
        }

        .alert-error {
            background: rgba(255, 107, 107, 0.1);
            border-color: #ff6b6b;
            color: #ff6b6b;
        }

        .alert i {
            margin-right: 12px;
            font-size: 18px;
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

        .slide-in-left {
            animation: slideInLeft 0.6s ease-out;
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            
            .manage-studio-container {
                padding: 20px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .studios-grid {
                grid-template-columns: 1fr;
            }
            
            .page-title {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar_netflix.php'; ?>

    <div class="main-content">
        <div class="manage-studio-container">
            <!-- Page Header -->
            <div class="page-header fade-in">
                <h1 class="page-title">
                    <i class="fas fa-building"></i>
                    Manage Studios
                </h1>
                <p class="page-subtitle">
                    Add and manage your studio locations
                </p>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success fade-in">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                    <?php unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-error fade-in">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- Add Studio Form -->
            <div class="form-container fade-in">
                <h3 class="form-title">
                    <i class="fas fa-plus"></i>
                    Add New Studio
                </h3>
                
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="studio_name">Studio Name *</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="studio_name" 
                                   name="studio_name" 
                                   placeholder="Enter studio name"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="location">Location *</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="location" 
                                   name="location" 
                                   placeholder="Enter studio location"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="time_in">Opening Time</label>
                            <input type="time" 
                                   class="form-control" 
                                   id="time_in" 
                                   name="time_in" 
                                   value="06:00">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="time_out">Closing Time</label>
                            <input type="time" 
                                   class="form-control" 
                                   id="time_out" 
                                   name="time_out" 
                                   value="22:00">
                        </div>
                        
                        <div class="form-group full-width">
                            <label class="form-label" for="description">Description</label>
                            <textarea class="form-control" 
                                      id="description" 
                                      name="description" 
                                      rows="3" 
                                      placeholder="Enter studio description"></textarea>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 15px; justify-content: flex-end;">
                        <button type="button" class="btn btn-secondary" onclick="clearForm()">
                            <i class="fas fa-times"></i>
                            Clear
                        </button>
                        <button type="submit" class="btn">
                            <i class="fas fa-save"></i>
                            Add Studio
                        </button>
                    </div>
                </form>
            </div>

            <!-- Existing Studios -->
            <?php if (!empty($studios)): ?>
                <div class="studios-section">
                    <h3 class="form-title" style="margin-bottom: 20px;">
                        <i class="fas fa-list"></i>
                        Your Studios
                    </h3>
                    
                    <div class="studios-grid">
                        <?php foreach ($studios as $index => $studio): ?>
                            <div class="studio-card slide-in-left" style="animation-delay: <?php echo $index * 0.1; ?>s">
                                <h4 class="studio-name"><?php echo htmlspecialchars($studio['StudioName']); ?></h4>
                                
                                <div class="studio-location">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($studio['Loc_Desc']); ?>
                                </div>
                                
                                <div class="studio-hours">
                                    <i class="fas fa-clock"></i>
                                    <?php echo date('g:i A', strtotime($studio['Time_IN'])); ?> - 
                                    <?php echo date('g:i A', strtotime($studio['Time_OUT'])); ?>
                                </div>
                                
                                <?php if (!empty($studio['Description'])): ?>
                                    <div class="studio-description">
                                        <?php echo htmlspecialchars($studio['Description']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="studio-actions">
                                    <button class="btn btn-sm" onclick="editStudio(<?php echo $studio['StudioID']; ?>)">
                                        <i class="fas fa-edit"></i>
                                        Edit
                                    </button>
                                    <button class="btn btn-sm btn-secondary" onclick="viewStudio(<?php echo $studio['StudioID']; ?>)">
                                        <i class="fas fa-eye"></i>
                                        View
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="form-container fade-in" style="text-align: center; padding: 60px 30px;">
                    <i class="fas fa-building" style="font-size: 4rem; color: var(--netflix-gray); margin-bottom: 20px;"></i>
                    <h3 style="color: var(--netflix-white); margin-bottom: 15px;">No Studios Yet</h3>
                    <p style="color: var(--netflix-light-gray); margin-bottom: 0;">
                        Add your first studio using the form above to get started.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function clearForm() {
            document.getElementById('studio_name').value = '';
            document.getElementById('location').value = '';
            document.getElementById('time_in').value = '06:00';
            document.getElementById('time_out').value = '22:00';
            document.getElementById('description').value = '';
        }

        function editStudio(studioId) {
            // Implement edit functionality
            alert('Edit functionality coming soon!');
        }

        function viewStudio(studioId) {
            // Implement view functionality
            alert('View functionality coming soon!');
        }

        // Add smooth animations
        document.addEventListener('DOMContentLoaded', function() {
            // Animate cards on scroll
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);

            // Observe all cards
            document.querySelectorAll('.studio-card').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'all 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
                observer.observe(card);
            });

            // Add hover effects
            document.querySelectorAll('.btn').forEach(btn => {
                btn.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                });
                
                btn.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        });

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
    </script>
</body>
</html>


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
    if (isset($_POST['add_service'])) {
        // Validate input
        $validation_rules = [
            'name' => ['type' => 'service_name', 'required' => true],
            'description' => ['type' => 'description', 'max_length' => 500],
            'price' => ['type' => 'price', 'required' => true]
        ];
        
        $validation_errors = ValidationUtils::validateForm($_POST, $validation_rules);
        
        if (empty($validation_errors)) {
            try {
                $name = ValidationUtils::sanitizeInput($_POST['name']);
                $description = ValidationUtils::sanitizeInput($_POST['description']);
                $price = (float)$_POST['price'];
                
                $stmt = $pdo->prepare("INSERT INTO services (ServiceType, Description, Price) VALUES (?, ?, ?)");
                $stmt->execute([$name, $description, $price]);
                
                $service_id = $pdo->lastInsertId();
                
                // Link service to studio
                $link_stmt = $pdo->prepare("INSERT INTO studio_services (StudioID, ServiceID) VALUES (?, ?)");
                $link_stmt->execute([$studio_id, $service_id]);
                
                $success_message = "Service added successfully!";
            } catch (Exception $e) {
                $error_message = "Error adding service: " . $e->getMessage();
            }
        } else {
            $error_message = ValidationUtils::formatErrors($validation_errors);
        }
    } elseif (isset($_POST['update_service'])) {
        $validation_rules = [
            'name' => ['type' => 'service_name', 'required' => true],
            'description' => ['type' => 'description', 'max_length' => 500],
            'price' => ['type' => 'price', 'required' => true]
        ];
        
        $validation_errors = ValidationUtils::validateForm($_POST, $validation_rules);
        
        if (empty($validation_errors)) {
            try {
                $service_id = (int)$_POST['service_id'];
                $name = ValidationUtils::sanitizeInput($_POST['name']);
                $description = ValidationUtils::sanitizeInput($_POST['description']);
                $price = (float)$_POST['price'];
                
                $stmt = $pdo->prepare("UPDATE services SET ServiceType = ?, Description = ?, Price = ? WHERE ServiceID = ?");
                $stmt->execute([$name, $description, $price, $service_id]);
                
                $success_message = "Service updated successfully!";
            } catch (Exception $e) {
                $error_message = "Error updating service: " . $e->getMessage();
            }
        } else {
            $error_message = ValidationUtils::formatErrors($validation_errors);
        }
    } elseif (isset($_POST['delete_service'])) {
        try {
            $service_id = (int)$_POST['service_id'];
            
            // Check if service is used in any bookings
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE ServiceID = ?");
            $check_stmt->execute([$service_id]);
            $booking_count = $check_stmt->fetchColumn();
            
            if ($booking_count > 0) {
                $error_message = "Cannot delete service as it is used in existing bookings.";
            } else {
                // Remove from studio_services first
                $unlink_stmt = $pdo->prepare("DELETE FROM studio_services WHERE ServiceID = ? AND StudioID = ?");
                $unlink_stmt->execute([$service_id, $studio_id]);
                
                // Delete the service
                $delete_stmt = $pdo->prepare("DELETE FROM services WHERE ServiceID = ?");
                $delete_stmt->execute([$service_id]);
                
                $success_message = "Service deleted successfully!";
            }
        } catch (Exception $e) {
            $error_message = "Error deleting service: " . $e->getMessage();
        }
    }
}

// Fetch services for this studio
$services_stmt = $pdo->prepare("
    SELECT s.*, ss.StudioID 
    FROM services s
    JOIN studio_services ss ON s.ServiceID = ss.ServiceID
    WHERE ss.StudioID = ?
    ORDER BY s.ServiceType
");
$services_stmt->execute([$studio_id]);
$services = $services_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Services - MuSeek Studio Management</title>
    
    <!-- Tailwind (match bookings_netflix) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../css/style.css">
    
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
            /* Responsive stepper sizing (minimalist) */
            --stepper-width: clamp(22px, 4vw, 26px);
            --stepper-height: clamp(12px, 3.5vw, 10px);
            --stepper-gap: clamp(2px, 0.8vw, 4px);
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

        .manage-services-container {
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

        .services-table {
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

        .action-buttons {
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
            
            .manage-services-container {
                padding: 20px;
            }
            
            .page-title {
                font-size: 2rem;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .table {
                font-size: 12px;
            }
            
            .table th,
            .table td {
                padding: 12px 8px;
            }
        }
        /* Floating label inputs */
        .form-group.floating {
            position: relative;
        }
        .form-group.floating .form-control {
            width: 100%;
            padding: 16px 14px 12px;
            border: 1px solid #333;
            border-radius: 10px;
            background: #0f0f0f;
            color: var(--netflix-white);
            font-size: 14px;
            transition: all 0.2s ease;
        }
        .form-group.floating .form-control:focus {
            outline: none;
            border-color: var(--netflix-red);
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.22);
        }
        .form-group.floating .form-control::placeholder {
            color: transparent;
        }
        .form-group.floating .form-label {
            position: absolute;
            left: 14px;
            top: 12px;
            color: var(--netflix-light-gray);
            background: transparent;
            padding: 0;
            transform-origin: left top;
            pointer-events: none;
            transition: all 0.15s ease;
        }
        .form-group.floating .form-control:focus + .form-label,
        .form-group.floating .form-control:not(:placeholder-shown) + .form-label {
            transform: translateY(-14px) scale(0.92);
            color: var(--netflix-red);
        }
        /* Textarea support */
        .form-group.floating textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }
        .form-group.floating textarea.form-control:focus + .form-label,
        .form-group.floating textarea.form-control:not(:placeholder-shown) + .form-label {
            transform: translateY(-14px) scale(0.92);
            color: var(--netflix-red);
        }
        /* Number input spinner customization */
        /* Native number spinners enabled: removed webkit overrides */
        /* Firefox: use default appearance for number inputs */
        .form-group.floating input[type="number"] {
            padding-right: 14px;
        }
        .form-group.floating .stepper {
            display: none !important;
        }
        .form-group.floating .stepper button {
            width: var(--stepper-width);
            height: var(--stepper-height);
            background: transparent;
            border: none;
            color: var(--netflix-light-gray);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            padding: 0;
        }
        .form-group.floating .stepper button i {
            font-size: 0.8rem;
        }
        .form-group.floating .stepper button:hover {
            color: var(--netflix-white);
        }
        .form-group.floating .stepper button:focus-visible {
            outline: 2px solid rgba(229, 9, 20, 0.4);
            border-radius: 4px;
        }
        @media (max-width: 600px) {
            .form-group.floating input[type="number"] {
                padding-right: 14px;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar_netflix.php'; ?>

    <main class="main-content min-h-screen" id="mainContent">
        <header class="flex items-center h-14 px-6 border-b border-[#222222]">
            <h1 class="text-xl font-bold ml-1">SERVICES</h1>
        </header>
        <div class="manage-services-container">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Manage Services</h1>
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

            <!-- Add Service Form -->
            <div class="form-container fade-in">
                <h2 class="form-title">
                    <i class="fas fa-plus-circle"></i>
                    Add New Service
                </h2>
                
                <form method="POST" class="service-form">
                    <input type="hidden" name="add_service" value="1">
                    
                    <div class="form-grid">
                        <div class="form-group floating">
                            <input type="text" 
                                   id="name" 
                                   name="name" 
                                   class="form-control" 
                                   placeholder=" "
                                   required
                                   maxlength="50">
                            <label for="name" class="form-label">Service Type or Service Name *</label>
                        </div>
                        
                        <div class="form-group floating">
                            <input type="number" 
                                   id="price" 
                                   name="price" 
                                   class="form-control" 
                                   placeholder=" "
                                   step="0.01"
                                   min="0"
                                   max="999999.99"
                                   required>
                            <label for="price" class="form-label">Price in Philippine Peso (₱) *</label>
                            <div class="stepper" aria-hidden="false">
                                <button type="button" class="step-up" tabindex="-1" aria-label="Increase price"><i class="fas fa-chevron-up"></i></button>
                                <button type="button" class="step-down" tabindex="-1" aria-label="Decrease price"><i class="fas fa-chevron-down"></i></button>
                            </div>
                        </div>
                        
                        <div class="form-group floating">
                            <textarea id="description" 
                                      name="description" 
                                      class="form-control" 
                                      rows="3" 
                                      placeholder=" "
                                      maxlength="500"></textarea>
                            <label for="description" class="form-label">Description</label>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i>
                            Add Service
                        </button>
                    </form>
            </div>

            <!-- Services List -->
            <div class="services-table fade-in">
                <div class="table-header">
                    <h3 class="table-title">
                        <i class="fas fa-list"></i>
                        Current Services (<?php echo count($services); ?>)
                    </h3>
                </div>
                
                <?php if (count($services) > 0): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Service Name</th>
                                <th>Description</th>
                                <th>Price (₱)</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($services as $service): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($service['ServiceType']); ?></strong>
                                    </td>
                                    <td>
                                        <?php 
                                        $description = htmlspecialchars($service['Description']);
                                        echo strlen($description) > 50 ? substr($description, 0, 50) . '...' : $description;
                                        ?>
                                    </td>
                                    <td>
                                        <span style="color: var(--success-green); font-weight: 600;">
                                            ₱<?php echo number_format($service['Price'], 2); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-secondary btn-small" 
                                                    onclick="editService(<?php echo $service['ServiceID']; ?>)">
                                                <i class="fas fa-edit"></i>
                                                Edit
                                            </button>
                                            <form method="POST" style="display: inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this service?');">
                                                <input type="hidden" name="delete_service" value="1">
                                                <input type="hidden" name="service_id" value="<?php echo $service['ServiceID']; ?>">
                                                <button type="submit" class="btn btn-danger btn-small">
                                                    <i class="fas fa-trash"></i>
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-concierge-bell"></i>
                        <h3>No Services Found</h3>
                        <p>Add your first service to get started with managing your studio offerings.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Edit Service Modal (Hidden by default) -->
    <div id="editModal" style="display: none;">
        <div class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Edit Service</h3>
                    <button onclick="closeEditModal()" class="close-btn">&times;</button>
                </div>
                <form id="editForm" method="POST">
                    <input type="hidden" name="update_service" value="1">
                    <input type="hidden" name="service_id" id="edit_service_id">
                    
                    <div class="form-group floating">
                        <input type="text" id="edit_name" name="name" class="form-control" placeholder=" " required>
                        <label for="edit_name" class="form-label">Service Type or Service Name *</label>
                    </div>
                    
                    <div class="form-group floating">
                        <textarea id="edit_description" name="description" class="form-control" rows="3" placeholder=" "></textarea>
                        <label for="edit_description" class="form-label">Description</label>
                    </div>
                    
                    <div class="form-group floating">
                        <input type="number" id="edit_price" name="price" class="form-control" placeholder=" " step="0.01" min="0" required>
                        <label for="edit_price" class="form-label">Price in Philippine Peso (₱) *</label>
                        <div class="stepper" aria-hidden="false">
                            <button type="button" class="step-up" tabindex="-1" aria-label="Increase price"><i class="fas fa-chevron-up"></i></button>
                            <button type="button" class="step-down" tabindex="-1" aria-label="Decrease price"><i class="fas fa-chevron-down"></i></button>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" onclick="closeEditModal()" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Service</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function editService(serviceId) {
            // This would typically fetch service data via AJAX
            // For now, we'll show the modal
            document.getElementById('editModal').style.display = 'block';
            document.getElementById('edit_service_id').value = serviceId;
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

        // Price stepper logic
        (function() {
            function getDecimals(step) {
                const s = String(step);
                const idx = s.indexOf('.');
                return idx >= 0 ? s.length - idx - 1 : 0;
            }
            function attachStepper(group) {
                const input = group.querySelector('input[type="number"]');
                const up = group.querySelector('.stepper .step-up');
                const down = group.querySelector('.stepper .step-down');
                if (!input || !up || !down) return;
                const step = parseFloat(input.step || '1');
                const decimals = getDecimals(step);
                const min = input.min ? parseFloat(input.min) : -Infinity;
                const max = input.max ? parseFloat(input.max) : Infinity;
                function setValue(v) {
                    if (v < min) v = min;
                    if (v > max) v = max;
                    input.value = Number(v).toFixed(decimals);
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
                up.addEventListener('click', () => {
                    const current = input.value === '' ? 0 : parseFloat(input.value);
                    setValue(current + step);
                });
                down.addEventListener('click', () => {
                    const current = input.value === '' ? 0 : parseFloat(input.value);
                    setValue(current - step);
                });
            }
            document.querySelectorAll('.form-group.floating').forEach(attachStepper);
        })();
    </script>
</body>
</html>
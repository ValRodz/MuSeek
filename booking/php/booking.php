<?php
session_start(); // Start the session
include '../../shared/config/db.php';
require_once '../../shared/config/path_config.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo "<script>
        alert('Please log in to continue.');
        window.location.href = '../../auth/php/login.php';
    </script>";
    exit;
}

// Fetch studio details based on StudioID from URL parameter
$studio_id = isset($_GET['studio_id']) ? (int)$_GET['studio_id'] : 0;
$studio = null;

if ($studio_id > 0) {
    $studio_query = "SELECT StudioID, StudioName, Loc_Desc, StudioImg, Time_IN, Time_OUT FROM studios WHERE StudioID = ?";
    $stmt = mysqli_prepare($conn, $studio_query);
    mysqli_stmt_bind_param($stmt, "i", $studio_id);
    mysqli_stmt_execute($stmt);
    $studio_result = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($studio_result)) {
        if ($row['StudioImg']) {
            $row['StudioImgBase64'] = 'data:image/jpeg;base64,' . base64_encode($row['StudioImg']);
        } else {
            $row['StudioImgBase64'] = '../../shared/assets/images/default_studio.jpg';
        }
        $studio = $row;
    }
    mysqli_stmt_close($stmt);

    // Fetch services
    $services_query = "SELECT se.ServiceID, se.ServiceType, se.Description, se.Price
                      FROM studio_services ss
                      LEFT JOIN services se ON ss.ServiceID = se.ServiceID
                      WHERE ss.StudioID = ?";
    $stmt = mysqli_prepare($conn, $services_query);
    mysqli_stmt_bind_param($stmt, "i", $studio_id);
    mysqli_stmt_execute($stmt);
    $services_result = mysqli_stmt_get_result($stmt);

    $services = [];
    while ($service_row = mysqli_fetch_assoc($services_result)) {
        $services[$service_row['ServiceID']] = [
            'ServiceType' => $service_row['ServiceType'],
            'Description' => $service_row['Description'],
            'Price' => $service_row['Price'],
            'Instructors' => []
        ];
    }
    mysqli_stmt_close($stmt);

    // First, get the owner ID for this studio
    $owner_query = "SELECT so.OwnerID FROM studio_owners so JOIN studios st ON so.OwnerID = st.OwnerID WHERE st.StudioID = ?";
    $stmt = mysqli_prepare($conn, $owner_query);
    mysqli_stmt_bind_param($stmt, "i", $studio_id);
    mysqli_stmt_execute($stmt);
    $owner_result = mysqli_stmt_get_result($stmt);
    $owner_row = mysqli_fetch_assoc($owner_result);
    $owner_id = $owner_row ? $owner_row['OwnerID'] : 0;
    mysqli_stmt_close($stmt);

    // Then fetch instructors for this owner and studio
    $instructors_query = "SELECT DISTINCT i.InstructorID, i.Name AS InstructorName, ie.ServiceID, i.Availability
                         FROM instructors i
                         INNER JOIN studio_owners so ON so.OwnerID = i.OwnerID
                         INNER JOIN instructor_services ie ON ie.InstructorID = i.InstructorID
                         INNER JOIN services se ON ie.ServiceID = se.ServiceID
                         INNER JOIN studio_services ss ON ss.ServiceID = se.ServiceID
                         WHERE so.OwnerID = ? AND ss.StudioID = ? AND i.Availability = 'Avail'";
    $stmt = mysqli_prepare($conn, $instructors_query);
    mysqli_stmt_bind_param($stmt, "ii", $owner_id, $studio_id);
    mysqli_stmt_execute($stmt);
    $instructors_result = mysqli_stmt_get_result($stmt);

    while ($instructor_row = mysqli_fetch_assoc($instructors_result)) {
        if (isset($services[$instructor_row['ServiceID']])) {
            $services[$instructor_row['ServiceID']]['Instructors'][] = [
                'InstructorID' => $instructor_row['InstructorID'],
                'InstructorName' => $instructor_row['InstructorName']
            ];
        }
    }
    mysqli_stmt_close($stmt);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">

    <title>Browse Studios - MuSeek</title>
    <!-- Loading third party fonts -->
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" type="text/css">
    <!-- Loading main css file -->
    <link rel="stylesheet" href="<?php echo getCSSPath('style.css'); ?>">
    <style>
    /* Progress Bar Styles */
    #branding img {
        width: 180px;
        display: block; 
    }
    
    .section-title {
        margin-left: 20px;
    }
    
    .studio-header {
        display: flex;
        align-items: center;
        margin-bottom: 16px;
    }

    .studio-header img {
        width: 120px;
        height: 120px;
        object-fit: cover;
        border-radius: 10px;
        margin-right: 20px;
        border: 2px solid #eee;
        background: #fff;
    }
    .studio-header h3 {
        color: white;
        margin: 0;
        font-size: 28px;
        font-weight: 700;
        letter-spacing: 1px;
    }
    .booking-container {
        display: flex;
        gap: 40px;
        justify-content: center;
        align-items: flex-start;
        margin-top: 40px;
        flex-wrap: wrap;
    }
    .booking-card {
        width: 56%;
        min-width: 320px;
        background: black;
        outline-color: #888;
        border-radius: 12px;
        padding: 26px 28px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.10);
        margin-bottom: 24px;
    }
    .booking-info-card {
        width: 34%;
        min-width: 250px;
        margin-left: 0;
        margin-bottom: 24px;
    }
    .service-card {
        margin-bottom: 8px;
        min-height: 110px;
        align-items: flex-start;
    }
    .fullwidth-block.booking-section {
        background: linear-gradient(135deg, #222 60%, #e50914 200%);
        padding: 40px 0 60px 0;
    }

    .booking-progress {
        display: flex;
        justify-content: space-between;
        max-width: 800px;
        margin: 0 auto 40px;
        position: relative;
        z-index: 5;
    }
    
    .booking-progress::before {
        content: '';
        position: absolute;
        top: 20px;
        left: 0;
        right: 0;
        height: 2px;
        background: rgba(255, 255, 255, 0.3);
        z-index: -1;
    }
    
    .progress-step {
        display: flex;
        flex-direction: column;
        align-items: center;
        color: rgba(255, 255, 255, 0.6);
        width: 25%;
    }
    
    .progress-step.active {
        color: #fff;
    }
    
    .progress-step.completed {
        color: #e50914;
    }
    
    .step-number {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: rgba(0, 0, 0, 0.5);
        border: 2px solid rgba(255, 255, 255, 0.3);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        margin-bottom: 10px;
    }
    
    .progress-step.active .step-number {
        background: #e50914;
        border-color: #fff;
    }
    
    .progress-step.completed .step-number {
        background: #333;
        border-color: #e50914;
    }
    
    .step-label {
        font-size: 14px;
        text-align: center;
    }
    
    /* Service Selection Styles */
    .service-selection-title {
        color: #fff;
        margin: 20px 0;
        font-size: 18px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        padding-bottom: 10px;
    }
    
    .services-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }
    
    .service-card {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        padding: 15px;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        justify-content: space-between;
        border: 2px solid transparent;
    }
    
    .service-card:hover {
        background: rgba(255, 255, 255, 0.15);
        transform: translateY(-3px);
    }
    
    .service-info {
        flex: 1;
    }
    
    .service-info h4 {
        color: #fff;
        margin: 0 0 10px;
        font-size: 16px;
    }
    
    .service-info p {
        color: #ccc;
        font-size: 14px;
        margin-bottom: 10px;
    }
    
    .service-price {
        color: #e50914;
        font-weight: bold;
        font-size: 18px;
    }
    
    .service-select {
        display: flex;
        align-items: center;
        margin-left: 10px;
    }
    
    .select-indicator {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        border: 2px solid #666;
        transition: all 0.2s ease;
    }
    
    .select-indicator.selected {
        background: #e50914;
        border-color: #fff;
    }
    
    /* Instructor Selection Block */
    .instructor-selection-block {
        display: none;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        padding: 15px;
        margin-top: 20px;
    }
    
    .instructor-selection-block h4 {
        color: #fff;
        margin: 0 0 15px;
        font-size: 16px;
    }
    
    .instructor-selection-block select {
        width: 100%;
        padding: 8px;
        border-radius: 4px;
        border: 1px solid #666;
        background: rgba(255, 255, 255, 0.1);
        color: #fff;
        cursor: pointer;
    }
    
    .instructor-selection-block select option {
        background: #222;
        color: #fff;
    }
    
    /* Info Card Styles */
    .booking-info-card {
        width: 40%;
        background: #fff;
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
        color: #333;
    }
    
    .booking-info-card h4 {
        margin-top: 0;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
    }
    
    .info-content {
        font-size: 14px;
    }
    
    .selected-info {
        background: #f8f8f8;
        border-radius: 5px;
        padding: 15px;
        margin: 15px 0;
    }
    
    .info-item {
        display: flex;
        justify-content: space-between;
        margin-bottom: 8px;
    }
    
    .info-label {
        font-weight: bold;
        color: #555;
    }
    
    .info-value {
        color: #e50914;
    }
    
    .booking-tips {
        margin-top: 20px;
    }
    
    .booking-tips h5 {
        margin-bottom: 10px;
    }
    
    .booking-tips ul {
        padding-left: 20px;
    }
    
    .booking-tips li {
        margin-bottom: 5px;
        color: #555;
    }
    
    /* Button Styles */
    #nextStepBtn {
        padding: 12px 24px;
        font-size: 16px;
        background-color: #e50914;
        border: none;
        color: #fff;
        border-radius: 4px;
        cursor: pointer;
        width: auto;
        display: block;
        margin: 20px auto 0;
        transition: all 0.3s ease;
    }
    
    #nextStepBtn:hover {
        background-color: #f40612;
    }
    
    #nextStepBtn:disabled {
        background-color: #888;
        cursor: not-allowed;
    }
    
    /* Responsive Styles */
    @media (max-width: 768px) {
        .booking-container {
            flex-direction: column;
        }
        
        .booking-card, .booking-info-card {
            width: 100%;
            margin-bottom: 20px;
        }
        
    .booking-progress {
        padding: 0 15px;
    }
        
    .step-label {
        font-size: 12px;
    }
}
@media (max-width: 768px) {
    .studio-header img {
        width: 100%;
        height: auto;
    }

    .booking-info-card {
        flex: 1 1 100%;
        margin-top: 20px;
    }
}
</style>
</head>

<body>
<?php include '../../shared/components/navbar.php'; ?>

<main class="main-content">
    <div class="fullwidth-block booking-section">
        <div class="booking-progress">
            <div class="progress-step active">
                <div class="step-number">1</div>
                <div class="step-label">Select Service</div>
            </div>
            <div class="progress-step">
                <div class="step-number">2</div>
                <div class="step-label">Choose Date & Time</div>
            </div>
            <div class="progress-step">
                <div class="step-number">3</div>
                <div class="step-label">Confirm Booking</div>
            </div>
            <div class="progress-step">
                <div class="step-number">4</div>
                <div class="step-label">Payment</div>
            </div>
        </div>

        <h2 class="section-title">Book Your Studio</h2>
        <div class="booking-container">
            <?php if ($studio): ?>
                <div class="booking-card">
                    <div class="studio-header">
                        <img src="<?php echo $studio['StudioImgBase64']; ?>" alt="<?php echo htmlspecialchars($studio['StudioName']); ?>">
                        <h3><?php echo htmlspecialchars($studio['StudioName']); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars($studio['Loc_Desc']); ?></p>
                    
                    <h4 class="service-selection-title">Step 1: Select a Service</h4>
                    <p style="color: #ddd; margin: -10px 0 15px 0; font-size: 14px;">
                        💡 Click on a service to select it. If instructors are available, you'll be able to choose one in the next step.
                    </p>
                    
                    <div class="services-grid">
                        <?php if (!empty($services)): ?>
                            <?php foreach ($services as $service_id => $service): ?>
                                <div class="service-card" onclick="selectService(<?php echo $service_id; ?>, '<?php echo htmlspecialchars($service['ServiceType']); ?>', <?php echo $service['Price']; ?>)">
                                    <div class="service-info">
                                        <h4><?php echo htmlspecialchars($service['ServiceType']); ?></h4>
                                        <p><?php echo htmlspecialchars($service['Description']); ?></p>
                                        <div class="service-price">₱<?php echo number_format($service['Price'], 2); ?></div>
                                    </div>
                                    <div class="service-select">
                                        <div class="select-indicator" id="indicator-<?php echo $service_id; ?>"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="no-services">No services available for this studio.</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Instructor Selection Block -->
                    <div class="instructor-selection-block" id="instructorSelectionBlock">
                        <h4>Step 2: Select an Instructor/Staff: </h4>
                        <select id="instructorSelect" onchange="updateInstructorSelection()">
                            <option value="">Select Instructor/Staff</option>
                        </select>
                        <p id="noInstructorMessage" class="no-instructors-message" style="display: none; color: #ff6b6b; margin-top: 10px; font-size: 14px;">
                            ⚠️ No instructors available for this service. Please contact the studio for assistance.
                        </p>
                    </div>
                    
                    <form id="serviceForm" action="booking2.php" method="GET">
                        <input type="hidden" name="studio_id" value="<?php echo $studio['StudioID']; ?>">
                        <input type="hidden" id="selected_service" name="service_id" value="">
                        <input type="hidden" id="selected_instructor" name="instructor_id" value="">
                        <button type="submit" id="nextStepBtn" disabled>Continue to Date & Time</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="booking-card">
                    <p style="color: #ccc; text-align: center; width: 100%;">Studio not found.</p>
                    <a href="../../client/php/browse.php" class="button">Browse Studios</a>
                </div>
            <?php endif; ?>
            
            <div class="booking-info-card">
                <h4>Booking Information</h4>
                <div class="info-content">
                    <p>Select a service and instructor to continue with your booking. Each service has different pricing and duration options.</p>
                    <div class="selected-info" id="selectedServiceInfo">
                        <p>No service selected</p>
                    </div>
                    <div class="booking-tips">
                        <h5>Tips:</h5>
                        <ul>
                            <li>Consider your project needs when selecting a service</li>
                            <li>Different services may have different availability</li>
                            <li>Prices shown are per session</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../../shared/components/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
// Store services and instructors data from PHP
const servicesData = <?php echo json_encode($services); ?>;

let selectedServiceId = null;
let selectedInstructorId = null;

function selectService(serviceId, serviceName, servicePrice) {
    // Clear previous selection
    if (selectedServiceId) {
        document.getElementById('indicator-' + selectedServiceId).classList.remove('selected');
    }
    
    // Set new selection
    selectedServiceId = serviceId;
    document.getElementById('indicator-' + serviceId).classList.add('selected');
    document.getElementById('selected_service').value = serviceId;
    
    // Reset instructor selection
    selectedInstructorId = null;
    document.getElementById('selected_instructor').value = '';
    
    // Update info panel
    let instructorInfo = '';
    if (servicesData[serviceId].Instructors.length > 0) {
        instructorInfo = `<div class="info-item">
            <span class="info-label">Instructor:</span>
            <span class="info-value" id="selectedInstructorName"></span>
        </div>`;
    }
    
    document.getElementById('selectedServiceInfo').innerHTML = `
        <div class="info-item">
            <span class="info-label">Selected Service:</span>
            <span class="info-value">${serviceName}</span>
        </div>
        <div class="info-item">
            <span class="info-label">Price:</span>
            <span class="info-value">₱${servicePrice.toFixed(2)}</span>
        </div>
        ${instructorInfo}
    `;
    
    // Show and populate instructor selection block
    const instructorBlock = document.getElementById('instructorSelectionBlock');
    const instructorSelect = document.getElementById('instructorSelect');
    
    // Clear previous options
    instructorSelect.innerHTML = '<option value="">Select Instructor</option>';
    
    if (servicesData[serviceId].Instructors.length > 0) {
        servicesData[serviceId].Instructors.forEach(instructor => {
            const option = document.createElement('option');
            option.value = instructor.InstructorID;
            option.textContent = instructor.InstructorName;
            instructorSelect.appendChild(option);
        });
        document.getElementById('noInstructorMessage').style.display = 'none';
        document.getElementById('instructorSelect').style.display = 'block';
        instructorBlock.style.display = 'block';
    } else {
        document.getElementById('noInstructorMessage').style.display = 'block';
        document.getElementById('instructorSelect').style.display = 'none';
        instructorBlock.style.display = 'block';
    }
    
    updateNextButtonState();
}

function updateInstructorSelection() {
    const instructorSelect = document.getElementById('instructorSelect');
    selectedInstructorId = instructorSelect.value;
    document.getElementById('selected_instructor').value = selectedInstructorId;
    
    const selectedOption = instructorSelect.options[instructorSelect.selectedIndex];
    const instructorNameElement = document.getElementById('selectedInstructorName');
    if (instructorNameElement) {
        instructorNameElement.textContent = selectedOption ? selectedOption.text : '';
    }
    
    updateNextButtonState();
}

function updateNextButtonState() {
    if (servicesData[selectedServiceId] && servicesData[selectedServiceId].Instructors.length > 0) {
        document.getElementById('nextStepBtn').disabled = !selectedServiceId || !selectedInstructorId;
    } else {
        document.getElementById('nextStepBtn').disabled = !selectedServiceId;
    }
}

// Form validation
document.getElementById('serviceForm').addEventListener('submit', function(e) {
    if (!selectedServiceId) {
        e.preventDefault();
        alert('Please select a service to continue.');
        return;
    }
    if (servicesData[selectedServiceId] && servicesData[selectedServiceId].Instructors.length > 0 && !selectedInstructorId) {
        e.preventDefault();
        alert('Please select an instructor to continue.');
    }
});
</script>
</body>
</html>

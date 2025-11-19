<?php
// Start the session to access session variables
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header("Location: login.php");
    exit();
}

// Include database connection
require_once '../../shared/config/db pdo.php';

// Get the logged-in owner's ID from session
$ownerId = $_SESSION['user_id'];

// Fetch all instructors for this owner
$instructors = $pdo->prepare("
    SELECT i.InstructorID, i.Name, i.Profession AS Specialty, i.Phone AS Contact_Num, i.Email,
        GROUP_CONCAT(DISTINCT srv.ServiceType SEPARATOR ', ') AS services,
        COUNT(DISTINCT b.BookingID) AS total_sessions
    FROM instructors i
    LEFT JOIN instructor_services ins ON i.InstructorID = ins.InstructorID
    LEFT JOIN services srv ON ins.ServiceID = srv.ServiceID
    LEFT JOIN bookings b ON ins.ServiceID = b.ServiceID AND i.InstructorID = b.InstructorID
    WHERE i.OwnerID = ?
    GROUP BY i.InstructorID
    ORDER BY i.Name
");
$instructors->execute([$ownerId]);
$instructors = $instructors->fetchAll(PDO::FETCH_ASSOC);

// Fetch services for this owner (for the add/edit instructor forms)
$services = $pdo->prepare("
    SELECT DISTINCT srv.ServiceID, srv.ServiceType
    FROM services srv
    LEFT JOIN studio_services ss ON srv.ServiceID = ss.ServiceID
    LEFT JOIN studios s ON ss.StudioID = s.StudioID
    WHERE s.OwnerID = ? OR s.OwnerID IS NULL
    ORDER BY srv.ServiceType
");
$services->execute([$ownerId]);
$services = $services->fetchAll(PDO::FETCH_ASSOC);

// Debug: Log number of services fetched
error_log("Fetched " . count($services) . " services for OwnerID: $ownerId");

// Check if no services are available
$noServicesMessage = count($services) === 0 ? "No services available. Please add services to your studios first." : "";

// Check for unread notifications
$notificationsStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM notifications 
    WHERE OwnerID = ? 
    AND IsRead = 0
");
$notificationsStmt->execute([$ownerId]);
$unreadNotifications = $notificationsStmt->fetchColumn();

// Fetch owner data
$owner = $pdo->prepare("
    SELECT Name, Email 
    FROM studio_owners 
    WHERE OwnerID = ?
");
$owner->execute([$ownerId]);
$owner = $owner->fetch(PDO::FETCH_ASSOC);

// Helper function to get customer initials
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1" name="viewport"/>
    <title>MuSeek - Instructors</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
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
            transition: padding-left 0.3s ease;
            width: 100%;
        }
        .avatar {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 9999px;
            background-color: #374151;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
        }
        .instructor-card {
            background-color: #0a0a0a;
            border: 1px solid #222222;
            border-radius: 0.5rem;
            overflow: hidden;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 50;
            align-items: center;
            justify-content: center;
        }
        .modal.active {
            display: flex;
        }
        .modal-content {
            background-color: #161616;
            border: 1px solid #222222;
            border-radius: 0.5rem;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
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
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
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
    </style>
</head>
<body class="bg-[#161616] text-white">
    <!-- Toggle Sidebar Button -->
    <button id="toggleSidebar" class="fixed top-4 left-4 z-50 p-2 bg-[#222222] rounded-md">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar -->
    <aside class="sidebar bg-[#161616] border-r border-[#222222]" id="sidebar">
        <div class="flex items-center justify-between p-4 border-b border-[#222222]">
            <div class="flex items-center gap-2">
                <i class="fas fa-music text-red-600"></i>
                <span class="font-semibold text-lg">MuSeek</span>
            </div>
            <button id="closeSidebar" class="text-gray-400 hover:text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <nav class="p-2">
            <ul class="space-y-1">
                <li>
                    <a href="dashboard.php" class="flex items-center gap-2 px-3 py-2 rounded-md hover:bg-[#222222] text-white">
                        <i class="fas fa-home"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="bookings.php" class="flex items-center gap-2 px-3 py-2 rounded-md hover:bg-[#222222] text-white">
                        <i class="far fa-calendar-alt"></i>
                        <span>Bookings</span>
                    </a>
                </li>
                <li>
                    <a href="schedule.php" class="flex items-center gap-2 px-3 py-2 rounded-md hover:bg-[#222222] text-white">
                        <i class="far fa-clock"></i>
                        <span>Schedule</span>
                    </a>
                </li>
                <li>
                    <a href="payments.php" class="flex items-center gap-2 px-3 py-2 rounded-md hover:bg-[#222222] text-white">
                        <i class="fas fa-credit-card"></i>
                        <span>Payments</span>
                    </a>
                </li>
                <li>
                    <a href="instructors.php" class="flex items-center gap-2 px-3 py-2 rounded-md bg-red-600 text-white">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Instructors</span>
                    </a>
                </li>
                <li>
                    <a href="feedback_owner.php" class="flex items-center gap-2 px-3 py-2 rounded-md hover:bg-[#222222] text-white">
                        <i class="fas fa-star"></i>
                        <span>Feedback</span>
                    </a>
                </li>
                <li>
                    <a href="notifications.php" class="flex items-center gap-2 px-3 py-2 rounded-md hover:bg-[#222222] text-white">
                        <i class="fas fa-bell <?php if ($unreadNotifications > 0) echo 'notification-badge'; ?>" <?php if ($unreadNotifications > 0) echo 'data-count="' . $unreadNotifications . '"'; ?>></i>
                        <span>Notifications</span>
                        <?php if ($unreadNotifications > 0): ?>
                            <span id="notificationBadge" class="ml-auto bg-red-600 text-white text-xs font-bold px-1.5 py-0.5 rounded-full">
                                <?php echo $unreadNotifications; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
                <li>
                    <a href="reports.php" class="flex items-center gap-2 px-3 py-2 rounded-md hover:bg-[#222222] text-white">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li>
                    <a href="settings.php" class="flex items-center gap-2 px-3 py-2 rounded-md hover:bg-[#222222] text-white">
                        <i class="fas fa-cog"></i>
                        <span>Settings</span>
                    </a>
                </li>
            </ul>
        </nav>
        
        <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-[#222222]">
            <div class="flex items-center gap-3">
                <div class="avatar" style="width: 2rem; height: 2rem; font-size: 0.75rem;">
                    <?php echo getInitials($owner['Name']); ?>
                </div>
                <div class="flex flex-col text-xs text-gray-400">
                    <span class="font-semibold text-white text-[13px]"><?php echo htmlspecialchars($owner['Name']); ?></span>
                    <span><?php echo htmlspecialchars($owner['Email']); ?></span>
                </div>
                <div class="dropdown ml-auto">
                    <button class="text-gray-400 hover:text-white">
                        <i class="fas fa-cog"></i>
                    </button>
                    <div class="dropdown-content">
                        <a href="settings.php"><i class="fas fa-cog mr-2"></i> Settings</a>
                        <a href="logout.php"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content min-h-screen" id="mainContent">
        <header class="flex items-center h-14 px-6 border-b border-[#222222]">
            <h1 class="text-xl font-bold ml-6">Instructors</h1>
        </header>

        <!-- Flash Message -->
        <?php if (isset($_SESSION['flash_message'])): ?>
            <div class="bg-gray-800 border border-gray-600 text-white p-4 rounded-md mb-4 mx-6 mt-4">
                <?php echo htmlspecialchars($_SESSION['flash_message']); ?>
            </div>
            <?php unset($_SESSION['flash_message']); ?>
        <?php endif; ?>

        <div class="p-6">
            <!-- Actions -->
            <div class="flex flex-wrap justify-between items-center mb-6 gap-4">
                <div class="relative">
                    <input type="text" id="search-instructors" placeholder="Search instructors..." class="bg-[#0a0a0a] border border-[#222222] rounded-md pl-10 pr-4 py-2 text-sm w-64">
                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                </div>
                <button id="addInstructorBtn" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium flex items-center gap-2">
                    <i class="fas fa-plus"></i>
                    <span>Add Instructor</span>
                </button>
            </div>
            
            <!-- Instructors Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php if (empty($instructors)): ?>
                    <div class="col-span-3 text-center py-8 text-gray-400">
                        <p>No instructors found. Add your first instructor to get started.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($instructors as $instructor): ?>
                        <div class="instructor-card instructor-item">
                            <div class="p-4 flex items-start gap-4">
                                <div class="avatar">
                                    <?php echo getInitials($instructor['Name']); ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h2 class="text-lg font-bold instructor-name"><?php echo htmlspecialchars($instructor['Name']); ?></h2>
                                    <p class="text-sm text-gray-400 instructor-specialty"><?php echo htmlspecialchars($instructor['Specialty']); ?></p>
                                    <div class="mt-2 space-y-1">
                                        <p class="text-xs flex items-center gap-2">
                                            <i class="fas fa-phone text-gray-400"></i>
                                            <span><?php echo htmlspecialchars($instructor['Contact_Num'] ?? 'N/A'); ?></span>
                                        </p>
                                        <p class="text-xs flex items-center gap-2">
                                            <i class="fas fa-envelope text-gray-400"></i>
                                            <span><?php echo htmlspecialchars($instructor['Email'] ?? 'N/A'); ?></span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="p-4 border-t border-[#222222]">
                                <div class="flex justify-between items-center mb-2">
                                    <h3 class="text-sm font-medium">Services</h3>
                                    <span class="text-xs text-gray-400"><?php echo $instructor['total_sessions'] ?? 0; ?> sessions</span>
                                </div>
                                <p class="text-sm text-gray-400"><?php echo htmlspecialchars($instructor['services'] ?? 'Not assigned'); ?></p>
                            </div>
                            
                            <div class="p-3 bg-[#0f0f0f] border-t border-[#222222] flex justify-end gap-2">
                                <button class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-3 py-1.5 text-xs font-medium" onclick="viewSchedule(<?php echo $instructor['InstructorID']; ?>)">
                                    View Schedule
                                </button>
                                <button class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-3 py-1.5 text-xs font-medium" onclick="editInstructor(<?php echo $instructor['InstructorID']; ?>, '<?php echo addslashes($instructor['Name']); ?>', '<?php echo addslashes($instructor['Specialty']); ?>', '<?php echo addslashes($instructor['Contact_Num'] ?? ''); ?>', '<?php echo addslashes($instructor['Email'] ?? ''); ?>')">
                                    Edit
                                </button>
                                <button class="bg-red-600 hover:bg-red-700 text-white rounded-md px-3 py-1.5 text-xs font-medium" onclick="confirmRemoveInstructor(<?php echo $instructor['InstructorID']; ?>, '<?php echo addslashes($instructor['Name']); ?>')">
                                    Remove
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
    
    <!-- Add Instructor Modal -->
    <div id="addInstructorModal" class="modal">
        <div class="modal-content p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold">Add New Instructor</h2>
                <button id="closeAddModalBtn" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="addInstructorForm" action="add_instructor.php" method="post">
                <div class="space-y-4">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-400 mb-1">Name</label>
                        <input type="text" id="name" name="name" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>
                    
                    <div>
                        <label for="specialty" class="block text-sm font-medium text-gray-400 mb-1">Specialty</label>
                        <input type="text" id="specialty" name="specialty" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>
                    
                    <div>
                        <label for="contact" class="block text-sm font-medium text-gray-400 mb-1">Contact Number</label>
                        <input type="text" id="contact" name="contact" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>
                    
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-400 mb-1">Email</label>
                        <input type="email" id="email" name="email" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>
                    
                    <div>
                        <label for="services" class="block text-sm font-medium text-gray-400 mb-1">Assign Services</label>
                        <?php if ($noServicesMessage): ?>
                            <p class="text-sm text-red-400"><?php echo $noServicesMessage; ?></p>
                        <?php else: ?>
                            <select id="services" name="services[]" multiple required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm h-32">
                                <?php foreach ($services as $service): ?>
                                    <option value="<?php echo $service['ServiceID']; ?>"><?php echo htmlspecialchars($service['ServiceType']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-400 mt-1">Hold Ctrl/Cmd to select multiple services</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="flex justify-end gap-3 mt-6">
                        <button type="button" id="cancelAddBtn" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium">
                            Cancel
                        </button>
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium" <?php echo $noServicesMessage ? 'disabled' : ''; ?>>
                            Add Instructor
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Instructor Modal -->
    <div id="editInstructorModal" class="modal">
        <div class="modal-content p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold">Edit Instructor</h2>
                <button id="closeEditModalBtn" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="editInstructorForm" action="update_instructor.php" method="post">
                <input type="hidden" id="edit_instructor_id" name="instructor_id">
                <div class="space-y-4">
                    <div>
                        <label for="edit_name" class="block text-sm font-medium text-gray-400 mb-1">Name</label>
                        <input type="text" id="edit_name" name="name" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="edit_specialty" class="block text-sm font-medium text-gray-400 mb-1">Specialty</label>
                        <input type="text" id="edit_specialty" name="specialty" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="edit_contact" class="block text-sm font-medium text-gray-400 mb-1">Contact Number</label>
                        <input type="text" id="edit_contact" name="contact" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="edit_email" class="block text-sm font-medium text-gray-400 mb-1">Email</label>
                        <input type="email" id="edit_email" name="email" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="edit_services" class="block text-sm font-medium text-gray-400 mb-1">Assign Services</label>
                        <?php if ($noServicesMessage): ?>
                            <p class="text-sm text-red-400"><?php echo $noServicesMessage; ?></p>
                        <?php else: ?>
                            <select id="edit_services" name="services[]" multiple required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm h-32">
                                <?php foreach ($services as $service): ?>
                                    <option value="<?php echo $service['ServiceID']; ?>"><?php echo htmlspecialchars($service['ServiceType']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-400 mt-1">Hold Ctrl/Cmd to select multiple services</p>
                        <?php endif; ?>
                    </div>
                    <div class="flex justify-end gap-3 mt-6">
                        <button type="button" id="cancelEditBtn" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium">
                            Cancel
                        </button>
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium" <?php echo $noServicesMessage ? 'disabled' : ''; ?>>
                            Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Confirm Remove Modal -->
    <div id="confirmRemoveModal" class="modal">
        <div class="modal-content p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold">Confirm Removal</h2>
                <button id="closeConfirmModalBtn" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <p class="mb-6">Are you sure you want to remove <span id="instructorToRemove" class="font-medium"></span>? This action cannot be undone.</p>
            
            <form id="removeInstructorForm" action="remove_instructor.php" method="post">
                <input type="hidden" id="remove_instructor_id" name="instructor_id">
                <div class="flex justify-end gap-3">
                    <button type="button" id="cancelRemoveBtn" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium">
                        Cancel
                    </button>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium">
                        Remove Instructor
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Sidebar toggle functionality
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const toggleSidebarButton = document.getElementById('toggleSidebar');
        const closeSidebarButton = document.getElementById('closeSidebar');
        
        function toggleSidebar() {
            sidebar.classList.toggle('active');
            if (sidebar.classList.contains('active')) {
                mainContent.style.paddingLeft = '250px';
            } else {
                mainContent.style.paddingLeft = '0';
            }
        }
        
        toggleSidebarButton.addEventListener('click', toggleSidebar);
        closeSidebarButton.addEventListener('click', toggleSidebar);
        
        // Initialize sidebar as closed on page load
        sidebar.classList.remove('active');
        mainContent.style.paddingLeft = '0';
        
        // Modal functionality
        const addInstructorModal = document.getElementById('addInstructorModal');
        const editInstructorModal = document.getElementById('editInstructorModal');
        const confirmRemoveModal = document.getElementById('confirmRemoveModal');
        
        function openModal(modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal(modal) {
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        
        // Add instructor modal
        const addInstructorBtn = document.getElementById('addInstructorBtn');
        addInstructorBtn.addEventListener('click', () => {
            console.log('Add Instructor button clicked');
            openModal(addInstructorModal);
        });
        
        document.getElementById('closeAddModalBtn').addEventListener('click', () => {
            closeModal(addInstructorModal);
        });
        
        document.getElementById('cancelAddBtn').addEventListener('click', () => {
            closeModal(addInstructorModal);
        });
        
        // Edit instructor functionality
        function editInstructor(id, name, specialty, contact, email) {
            document.getElementById('edit_instructor_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_specialty').value = specialty;
            document.getElementById('edit_contact').value = contact;
            document.getElementById('edit_email').value = email;

            fetch(`get_instructor_services.php?id=${id}`)
                .then(response => {
                    if (!response.ok) throw new Error('Failed to fetch services');
                    return response.json();
                })
                .then(data => {
                    if (data.error) throw new Error(data.error);
                    const serviceSelect = document.getElementById('edit_services');
                    Array.from(serviceSelect.options).forEach(option => {
                        option.selected = data.services.includes(parseInt(option.value));
                    });
                    openModal(editInstructorModal);
                })
                .catch(error => {
                    alert('Error loading service assignments: ' + error.message);
                    openModal(editInstructorModal);
                });
        }

        // Validate service selection for add and edit forms
        document.getElementById('addInstructorForm').addEventListener('submit', function(event) {
            const serviceSelect = document.getElementById('services');
            if (serviceSelect) {
                const selectedServices = Array.from(serviceSelect.selectedOptions).length;
                if (selectedServices === 0) {
                    event.preventDefault();
                    alert('Please select at least one service.');
                }
            }
        });

        document.getElementById('editInstructorForm').addEventListener('submit', function(event) {
            const serviceSelect = document.getElementById('edit_services');
            if (serviceSelect) {
                const selectedServices = Array.from(serviceSelect.selectedOptions).length;
                if (selectedServices === 0) {
                    event.preventDefault();
                    alert('Please select at least one service.');
                }
            }
        });
        
        document.getElementById('closeEditModalBtn').addEventListener('click', () => {
            closeModal(editInstructorModal);
        });
        
        document.getElementById('cancelEditBtn').addEventListener('click', () => {
            closeModal(editInstructorModal);
        });
        
        // Remove instructor functionality
        function confirmRemoveInstructor(id, name) {
            document.getElementById('instructorToRemove').textContent = name;
            document.getElementById('remove_instructor_id').value = id;
            openModal(confirmRemoveModal);
        }
        
        document.getElementById('closeConfirmModalBtn').addEventListener('click', () => {
            closeModal(confirmRemoveModal);
        });
        
        document.getElementById('cancelRemoveBtn').addEventListener('click', () => {
            closeModal(confirmRemoveModal);
        });
        
        // View schedule functionality
        function viewSchedule(instructorId) {
            window.location.href = `schedule.php?instructor=${instructorId}`;
        }
        
        // Search functionality
        document.getElementById('search-instructors').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const instructorItems = document.querySelectorAll('.instructor-item');
            
            instructorItems.forEach(item => {
                const name = item.querySelector('.instructor-name').textContent.toLowerCase();
                const specialty = item.querySelector('.instructor-specialty').textContent.toLowerCase();
                
                if (name.includes(searchTerm) || specialty.includes(searchTerm)) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        });
        
        // Close modals when clicking outside
        window.addEventListener('click', (event) => {
            if (event.target === addInstructorModal) {
                closeModal(addInstructorModal);
            }
            if (event.target === editInstructorModal) {
                closeModal(editInstructorModal);
            }
            if (event.target === confirmRemoveModal) {
                closeModal(confirmRemoveModal);
            }
        });
    </script>
</body>
</html>

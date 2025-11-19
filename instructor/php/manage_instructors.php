<?php
// manage_instructors.php
include '../../shared/config/db.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/php/login.html');
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

// Process form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_instructor'])) {
        // Add new instructor
        $name = $_POST['name'];
        $specialization = $_POST['specialization'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];

        // Handle image upload if provided
        $image_url = '';
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/instructors/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_name = time() . '_' . basename($_FILES['image']['name']);
            $target_file = $upload_dir . $file_name;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                $image_url = $target_file;
            } else {
                $error_message = "Error uploading image.";
            }
        }

        if (empty($error_message)) {
            $stmt = $conn->prepare("INSERT INTO instructors (studio_id, name, specialization, email, phone, image_url) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssss", $studio_id, $name, $specialization, $email, $phone, $image_url);

            if ($stmt->execute()) {
                $success_message = "Instructor added successfully!";
                // Refresh instructors list
                $stmt = $conn->prepare("SELECT * FROM instructors WHERE studio_id = ?");
                $stmt->bind_param("i", $studio_id);
                $stmt->execute();
                $instructors_result = $stmt->get_result();
                $instructors = [];
                while ($row = $instructors_result->fetch_assoc()) {
                    $instructors[] = $row;
                }
            } else {
                $error_message = "Error adding instructor: " . $conn->error;
            }
        }
    } elseif (isset($_POST['update_instructor'])) {
        // Update existing instructor
        $instructor_id = $_POST['instructor_id'];
        $name = $_POST['name'];
        $specialization = $_POST['specialization'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];

        // Get current instructor data
        $stmt = $conn->prepare("SELECT image_url FROM instructors WHERE id = ? AND studio_id = ?");
        $stmt->bind_param("ii", $instructor_id, $studio_id);
        $stmt->execute();
        $current_instructor = $stmt->get_result()->fetch_assoc();

        // Handle image upload if provided
        $image_url = $current_instructor['image_url'];
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/instructors/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_name = time() . '_' . basename($_FILES['image']['name']);
            $target_file = $upload_dir . $file_name;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                // Delete old image if exists
                if (!empty($current_instructor['image_url']) && file_exists($current_instructor['image_url'])) {
                    unlink($current_instructor['image_url']);
                }
                $image_url = $target_file;
            } else {
                $error_message = "Error uploading image.";
            }
        }

        if (empty($error_message)) {
            $stmt = $conn->prepare("UPDATE instructors SET name = ?, specialization = ?, email = ?, phone = ?, image_url = ? WHERE id = ? AND studio_id = ?");
            $stmt->bind_param("sssssii", $name, $specialization, $email, $phone, $image_url, $instructor_id, $studio_id);

            if ($stmt->execute()) {
                $success_message = "Instructor updated successfully!";
                // Refresh instructors list
                $stmt = $conn->prepare("SELECT * FROM instructors WHERE studio_id = ?");
                $stmt->bind_param("i", $studio_id);
                $stmt->execute();
                $instructors_result = $stmt->get_result();
                $instructors = [];
                while ($row = $instructors_result->fetch_assoc()) {
                    $instructors[] = $row;
                }
            } else {
                $error_message = "Error updating instructor: " . $conn->error;
            }
        }
    } elseif (isset($_POST['delete_instructor'])) {
        // Delete instructor
        $instructor_id = $_POST['instructor_id'];

        // Check if instructor is used in any bookings or schedules
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM bookings WHERE instructor_id = ?");
        $stmt->bind_param("i", $instructor_id);
        $stmt->execute();
        $booking_count = $stmt->get_result()->fetch_assoc()['count'];

        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM schedule WHERE instructor_id = ?");
        $stmt->bind_param("i", $instructor_id);
        $stmt->execute();
        $schedule_count = $stmt->get_result()->fetch_assoc()['count'];

        if ($booking_count > 0 || $schedule_count > 0) {
            $error_message = "Cannot delete instructor as they are associated with existing bookings or schedules.";
        } else {
            // Get instructor image
            $stmt = $conn->prepare("SELECT image_url FROM instructors WHERE id = ? AND studio_id = ?");
            $stmt->bind_param("ii", $instructor_id, $studio_id);
            $stmt->execute();
            $instructor = $stmt->get_result()->fetch_assoc();

            $stmt = $conn->prepare("DELETE FROM instructors WHERE id = ? AND studio_id = ?");
            $stmt->bind_param("ii", $instructor_id, $studio_id);

            if ($stmt->execute()) {
                // Delete instructor image if exists
                if (!empty($instructor['image_url']) && file_exists($instructor['image_url'])) {
                    unlink($instructor['image_url']);
                }

                $success_message = "Instructor deleted successfully!";
                // Refresh instructors list
                $stmt = $conn->prepare("SELECT * FROM instructors WHERE studio_id = ?");
                $stmt->bind_param("i", $studio_id);
                $stmt->execute();
                $instructors_result = $stmt->get_result();
                $instructors = [];
                while ($row = $instructors_result->fetch_assoc()) {
                    $instructors[] = $row;
                }
            } else {
                $error_message = "Error deleting instructor: " . $conn->error;
            }
        }
    }
}
?>

&lt;!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Instructors - <?php echo htmlspecialchars($studio['name']); ?></title>
    <link rel="stylesheet" href="../../shared/assets/css/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>Manage Instructors</h1>
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
                        <li class="active"><a href="manage_instructors.php?id=<?php echo $studio_id; ?>">Instructors</a></li>
                        <li><a href="manage_schedule.php?id=<?php echo $studio_id; ?>">Schedule</a></li>
                        <li><a href="manage_bookings.php?id=<?php echo $studio_id; ?>">Bookings</a></li>
                    </ul>
                </div>

                <div class="management-content">
                    <h2>Instructors for <?php echo htmlspecialchars($studio['name']); ?></h2>

                    <div class="instructors-list">
                        <h3>Current Instructors</h3>
                        <?php if (count($instructors) > 0): ?>
                            <div class="instructor-cards">
                                <?php foreach ($instructors as $instructor): ?>
                                    <div class="instructor-card">
                                        <div class="instructor-image">
                                            <?php if (!empty($instructor['image_url'])): ?>
                                                <img src="<?php echo htmlspecialchars($instructor['image_url']); ?>" alt="<?php echo htmlspecialchars($instructor['name']); ?>">
                                            <?php else: ?>
                                                <div class="placeholder-image">No Image</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="instructor-details">
                                            <h4><?php echo htmlspecialchars($instructor['name']); ?></h4>
                                            <p><strong>Specialization:</strong> <?php echo htmlspecialchars($instructor['specialization']); ?></p>
                                            <p><strong>Email:</strong> <?php echo htmlspecialchars($instructor['email']); ?></p>
                                            <p><strong>Phone:</strong> <?php echo htmlspecialchars($instructor['phone']); ?></p>
                                            <div class="instructor-actions">
                                                <button class="btn btn-small" onclick="editInstructor(<?php echo $instructor['id']; ?>)">Edit</button>
                                                <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this instructor?');">
                                                    <input type="hidden" name="delete_instructor" value="1">
                                                    <input type="hidden" name="instructor_id" value="<?php echo $instructor['id']; ?>">
                                                    <button type="submit" class="btn btn-small btn-danger">Delete</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p>No instructors found. Add your first instructor below.</p>
                        <?php endif; ?>
                    </div>

                    <div class="add-instructor-form">
                        <h3>Add New Instructor</h3>
                        <form action="manage_instructors.php?id=<?php echo $studio_id; ?>" method="post" class="instructor-form" enctype="multipart/form-data">
                            <input type="hidden" name="add_instructor" value="1">

                            <div class="form-group">
                                <label for="name">Instructor Name</label>
                                <input type="text" id="name" name="name" required>
                            </div>

                            <div class="form-group">
                                <label for="specialization">Specialization</label>
                                <input type="text" id="specialization" name="specialization" required>
                            </div>

                            <div class="form-row">
                                <div class="form-group half">
                                    <label for="email">Email</label>
                                    <input type="email" id="email" name="email" required>
                                </div>

                                <div class="form-group half">
                                    <label for="phone">Phone</label>
                                    <input type="tel" id="phone" name="phone" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="image">Instructor Image</label>
                                <input type="file" id="image" name="image" accept="image/*">
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Add Instructor</button>
                            </div>
                        </form>
                    </div>

                    &lt;!-- Edit Instructor Modal -->
                    <div id="editInstructorModal" class="modal">
                        <div class="modal-content">
                            <span class="close">&times;</span>
                            <h3>Edit Instructor</h3>
                            <form action="manage_instructors.php?id=<?php echo $studio_id; ?>" method="post" class="instructor-form" enctype="multipart/form-data">
                                <input type="hidden" name="update_instructor" value="1">
                                <input type="hidden" id="edit_instructor_id" name="instructor_id" value="">

                                <div class="form-group">
                                    <label for="edit_name">Instructor Name</label>
                                    <input type="text" id="edit_name" name="name" required>
                                </div>

                                <div class="form-group">
                                    <label for="edit_specialization">Specialization</label>
                                    <input type="text" id="edit_specialization" name="specialization" required>
                                </div>

                                <div class="form-row">
                                    <div class="form-group half">
                                        <label for="edit_email">Email</label>
                                        <input type="email" id="edit_email" name="email" required>
                                    </div>

                                    <div class="form-group half">
                                        <label for="edit_phone">Phone</label>
                                        <input type="tel" id="edit_phone" name="phone" required>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="edit_image">Instructor Image</label>
                                    <div id="current_image_container"></div>
                                    <input type="file" id="edit_image" name="image" accept="image/*">
                                    <small>Leave empty to keep current image</small>
                                </div>

                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">Update Instructor</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <footer>
            <p>&copy; 2023 Studio Booking System. All rights reserved.</p>
        </footer>
    </div>

    <script>
        // Get the modal
        var modal = document.getElementById("editInstructorModal");

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

        // Function to edit instructor
        function editInstructor(instructorId) {
            // Get instructor data
            <?php
            echo "var instructors = " . json_encode($instructors) . ";";
            ?>

            // Find the instructor
            var instructor = instructors.find(function(i) {
                return i.id == instructorId;
            });

            if (instructor) {
                // Fill the form
                document.getElementById("edit_instructor_id").value = instructor.id;
                document.getElementById("edit_name").value = instructor.name;
                document.getElementById("edit_specialization").value = instructor.specialization;
                document.getElementById("edit_email").value = instructor.email;
                document.getElementById("edit_phone").value = instructor.phone;

                // Show current image if exists
                var imageContainer = document.getElementById("current_image_container");
                imageContainer.innerHTML = '';

                if (instructor.image_url) {
                    var img = document.createElement('img');
                    img.src = instructor.image_url;
                    img.alt = instructor.name;
                    img.style.maxWidth = '100px';
                    img.style.marginBottom = '10px';
                    imageContainer.appendChild(img);
                }

                // Show the modal
                modal.style.display = "block";
            }
        }
    </script>
</body>
</html>

<?php
require_once '../../admin/php/config/database.php';

// Get registration ID from URL
$regId = isset($_GET['reg_id']) ? (int)$_GET['reg_id'] : 0;

if ($regId > 0) {
    $db = Database::getInstance()->getConnection();
    
    // Get studio ID
    $stmt = $db->prepare("SELECT studio_id, business_name FROM studio_registrations WHERE registration_id = ?");
    $stmt->execute([$regId]);
    $studio = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($studio && $studio['studio_id']) {
        echo "<h3>Gallery Photos for: " . htmlspecialchars($studio['business_name']) . "</h3>";
        echo "<p>Studio ID: " . $studio['studio_id'] . "</p>";
        
        // Get all gallery photos
        $galleryStmt = $db->prepare("SELECT * FROM studio_gallery WHERE StudioID = ? ORDER BY sort_order ASC, uploaded_at DESC");
        $galleryStmt->execute([$studio['studio_id']]);
        $photos = $galleryStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<p><strong>Total Photos: " . count($photos) . "</strong></p>";
        
        if (count($photos) > 0) {
            echo "<table border='1' cellpadding='10'>";
            echo "<tr><th>ID</th><th>File Path</th><th>Sort Order</th><th>Uploaded At</th><th>Preview</th></tr>";
            foreach ($photos as $photo) {
                echo "<tr>";
                echo "<td>" . $photo['image_id'] . "</td>";
                echo "<td>" . htmlspecialchars($photo['file_path']) . "</td>";
                echo "<td>" . $photo['sort_order'] . "</td>";
                echo "<td>" . $photo['uploaded_at'] . "</td>";
                echo "<td><img src='/" . htmlspecialchars($photo['file_path']) . "' style='width:100px;height:100px;object-fit:cover;'></td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>No photos found.</p>";
        }
    } else {
        echo "<p>Studio not found or no studio_id.</p>";
    }
} else {
    echo "<p>Please provide registration_id in URL: ?reg_id=YOUR_ID</p>";
}
?>


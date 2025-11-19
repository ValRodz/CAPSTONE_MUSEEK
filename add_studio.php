<?php
include 'shared/config/db.php';

$message = '';
$message_type = '';

if ($_POST) {
    $studio_name = trim($_POST['studio_name']);
    $latitude = trim($_POST['latitude']);
    $longitude = trim($_POST['longitude']);
    $location_desc = trim($_POST['location_desc']);
    $owner_id = intval($_POST['owner_id']);
    
    if (empty($studio_name) || empty($latitude) || empty($longitude) || empty($location_desc) || $owner_id <= 0) {
        $message = 'All fields are required and Owner ID must be a positive number.';
        $message_type = 'error';
    } else {
        // Validate coordinates
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            $message = 'Latitude and Longitude must be valid numbers.';
            $message_type = 'error';
        } else {
            $lat = floatval($latitude);
            $lng = floatval($longitude);
            
            // Basic coordinate validation for Philippines area
            if ($lat < 4.0 || $lat > 21.0 || $lng < 116.0 || $lng > 127.0) {
                $message = 'Coordinates seem to be outside Philippines. Please verify.';
                $message_type = 'warning';
            }
            
            // Check if owner exists
            $owner_check = "SELECT COUNT(*) as count FROM owners WHERE OwnerID = ?";
            $stmt = mysqli_prepare($conn, $owner_check);
            mysqli_stmt_bind_param($stmt, "i", $owner_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($result);
            
            if ($row['count'] == 0) {
                $message = 'Owner ID does not exist. Please check the owner ID.';
                $message_type = 'error';
            } else {
                // Insert studio
                $insert_query = "INSERT INTO studios (StudioName, Latitude, Longitude, Loc_Desc, OwnerID, Time_IN, Time_OUT) 
                                VALUES (?, ?, ?, ?, ?, '08:00:00', '22:00:00')";
                
                $stmt = mysqli_prepare($conn, $insert_query);
                mysqli_stmt_bind_param($stmt, "sddsi", $studio_name, $lat, $lng, $location_desc, $owner_id);
                
                if (mysqli_stmt_execute($stmt)) {
                    $message = "Studio '$studio_name' added successfully!";
                    $message_type = 'success';
                    // Clear form
                    $_POST = [];
                } else {
                    $message = 'Error adding studio: ' . mysqli_error($conn);
                    $message_type = 'error';
                }
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Get available owners
$owners_query = "SELECT OwnerID, Name FROM owners ORDER BY Name";
$owners_result = mysqli_query($conn, $owners_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Studio - MuSeek</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Arial', sans-serif; background: #1a1a1a; color: #fff; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #2a2a2a; padding: 30px; border-radius: 10px; }
        h1 { text-align: center; margin-bottom: 30px; color: #e50914; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 12px; border: 1px solid #444; background: #333; color: #fff; border-radius: 5px; font-size: 16px; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #e50914; }
        button { width: 100%; padding: 15px; background: #e50914; color: #fff; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; margin-top: 10px; }
        button:hover { background: #f40612; }
        .message { padding: 15px; margin-bottom: 20px; border-radius: 5px; text-align: center; }
        .success { background: #4caf50; color: white; }
        .error { background: #f44336; color: white; }
        .warning { background: #ff9800; color: white; }
        .links { text-align: center; margin-top: 20px; }
        .links a { color: #e50914; text-decoration: none; margin: 0 10px; }
        .links a:hover { text-decoration: underline; }
        .coord-help { font-size: 12px; color: #aaa; margin-top: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fa fa-plus-circle"></i> Add New Studio</h1>
        
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="studio_name"><i class="fa fa-music"></i> Studio Name</label>
                <input type="text" id="studio_name" name="studio_name" value="<?php echo htmlspecialchars($_POST['studio_name'] ?? ''); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="latitude"><i class="fa fa-map-marker"></i> Latitude</label>
                <input type="number" step="any" id="latitude" name="latitude" value="<?php echo htmlspecialchars($_POST['latitude'] ?? ''); ?>" required>
                <div class="coord-help">Example for Bacolod: 10.6760</div>
            </div>
            
            <div class="form-group">
                <label for="longitude"><i class="fa fa-map-marker"></i> Longitude</label>
                <input type="number" step="any" id="longitude" name="longitude" value="<?php echo htmlspecialchars($_POST['longitude'] ?? ''); ?>" required>
                <div class="coord-help">Example for Bacolod: 122.9544</div>
            </div>
            
            <div class="form-group">
                <label for="location_desc"><i class="fa fa-location-arrow"></i> Location Description</label>
                <textarea id="location_desc" name="location_desc" rows="3" required><?php echo htmlspecialchars($_POST['location_desc'] ?? ''); ?></textarea>
            </div>
            
            <div class="form-group">
                <label for="owner_id"><i class="fa fa-user"></i> Owner</label>
                <select id="owner_id" name="owner_id" required>
                    <option value="">Select an owner</option>
                    <?php while ($owner = mysqli_fetch_assoc($owners_result)): ?>
                        <option value="<?php echo $owner['OwnerID']; ?>" 
                                <?php echo (isset($_POST['owner_id']) && $_POST['owner_id'] == $owner['OwnerID']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($owner['Name']); ?> (ID: <?php echo $owner['OwnerID']; ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <button type="submit">
                <i class="fa fa-save"></i> Add Studio
            </button>
        </form>
        
        <div class="links">
            <a href="test_db.php"><i class="fa fa-database"></i> Test Database</a>
            <a href="client/php/browse.php"><i class="fa fa-map"></i> Browse Studios</a>
            <a href="add_sample_studios.php"><i class="fa fa-plus"></i> Add Sample Data</a>
        </div>
    </div>
    
    <script>
        // Simple coordinate validation
        document.getElementById('latitude').addEventListener('input', function() {
            const lat = parseFloat(this.value);
            if (lat && (lat < 4.0 || lat > 21.0)) {
                this.style.borderColor = '#ff9800';
            } else {
                this.style.borderColor = '#444';
            }
        });
        
        document.getElementById('longitude').addEventListener('input', function() {
            const lng = parseFloat(this.value);
            if (lng && (lng < 116.0 || lng > 127.0)) {
                this.style.borderColor = '#ff9800';
            } else {
                this.style.borderColor = '#444';
            }
        });
    </script>
</body>
</html>
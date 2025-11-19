<?php
include 'shared/config/db.php';

echo "<h2>Database Connection Test</h2>";

// Test database connection
if ($conn) {
    echo "<p style='color: green;'>✓ Database connection successful!</p>";
    
    // Check if studios table exists
    $table_check = "SHOW TABLES LIKE 'studios'";
    $result = mysqli_query($conn, $table_check);
    
    if (mysqli_num_rows($result) > 0) {
        echo "<p style='color: green;'>✓ Studios table exists</p>";
        
        // Count total studios
        $count_query = "SELECT COUNT(*) as total FROM studios";
        $count_result = mysqli_query($conn, $count_query);
        $count_row = mysqli_fetch_assoc($count_result);
        echo "<p>Total studios in database: <strong>" . $count_row['total'] . "</strong></p>";
        
        // Count studios with coordinates
        $coord_query = "SELECT COUNT(*) as with_coords FROM studios WHERE Latitude IS NOT NULL AND Longitude IS NOT NULL";
        $coord_result = mysqli_query($conn, $coord_query);
        $coord_row = mysqli_fetch_assoc($coord_result);
        echo "<p>Studios with coordinates: <strong>" . $coord_row['with_coords'] . "</strong></p>";
        
        // Display all studios
        $studios_query = "SELECT StudioID, StudioName, Latitude, Longitude, Loc_Desc FROM studios ORDER BY StudioID";
        $studios_result = mysqli_query($conn, $studios_query);
        
        if (mysqli_num_rows($studios_result) > 0) {
            echo "<h3>Existing Studios:</h3>";
            echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
            echo "<tr><th>ID</th><th>Name</th><th>Latitude</th><th>Longitude</th><th>Location</th></tr>";
            
            while ($row = mysqli_fetch_assoc($studios_result)) {
                echo "<tr>";
                echo "<td>" . $row['StudioID'] . "</td>";
                echo "<td>" . htmlspecialchars($row['StudioName']) . "</td>";
                echo "<td>" . ($row['Latitude'] ? $row['Latitude'] : 'NULL') . "</td>";
                echo "<td>" . ($row['Longitude'] ? $row['Longitude'] : 'NULL') . "</td>";
                echo "<td>" . htmlspecialchars($row['Loc_Desc']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p style='color: orange;'>⚠ No studios found in database</p>";
        }
        
    } else {
        echo "<p style='color: red;'>✗ Studios table does not exist</p>";
    }
    
} else {
    echo "<p style='color: red;'>✗ Database connection failed: " . mysqli_connect_error() . "</p>";
}

mysqli_close($conn);
?>
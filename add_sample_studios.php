<?php
include 'shared/config/db.php';

echo "<h2>Adding Sample Studios</h2>";

if (!$conn) {
    die("<p style='color: red;'>Database connection failed: " . mysqli_connect_error() . "</p>");
}

// First, check if we have any owners in the database
$owner_check = "SELECT COUNT(*) as owner_count FROM owners";
$owner_result = mysqli_query($conn, $owner_check);
$owner_row = mysqli_fetch_assoc($owner_result);

if ($owner_row['owner_count'] == 0) {
    echo "<p>No owners found. Creating sample owners first...</p>";
    
    // Create sample owners
    $sample_owners = [
        ['John Doe', 'john@example.com', 'password123', '09123456789'],
        ['Jane Smith', 'jane@example.com', 'password123', '09987654321'],
        ['Mike Johnson', 'mike@example.com', 'password123', '09555666777']
    ];
    
    foreach ($sample_owners as $owner) {
        $insert_owner = "INSERT INTO owners (Name, Email, Password, ContactNumber) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $insert_owner);
        $hashed_password = password_hash($owner[2], PASSWORD_DEFAULT);
        mysqli_stmt_bind_param($stmt, "ssss", $owner[0], $owner[1], $hashed_password, $owner[3]);
        
        if (mysqli_stmt_execute($stmt)) {
            echo "<p style='color: green;'>✓ Created owner: " . $owner[0] . "</p>";
        } else {
            echo "<p style='color: red;'>✗ Failed to create owner: " . $owner[0] . "</p>";
        }
        mysqli_stmt_close($stmt);
    }
}

// Get owner IDs
$owners_query = "SELECT OwnerID FROM owners LIMIT 3";
$owners_result = mysqli_query($conn, $owners_query);
$owner_ids = [];
while ($row = mysqli_fetch_assoc($owners_result)) {
    $owner_ids[] = $row['OwnerID'];
}

if (empty($owner_ids)) {
    die("<p style='color: red;'>No owners available to assign studios to.</p>");
}

// Clear existing studios (optional - remove this if you want to keep existing data)
echo "<p>Clearing existing studios...</p>";
mysqli_query($conn, "DELETE FROM studios");
mysqli_query($conn, "ALTER TABLE studios AUTO_INCREMENT = 1");

// Sample studios with Bacolod City coordinates
$sample_studios = [
    [
        'name' => 'Harmony Music Studio',
        'lat' => 10.6760,
        'lng' => 122.9544,
        'location' => 'Lacson Street, Bacolod City',
        'owner_id' => $owner_ids[0] ?? 1
    ],
    [
        'name' => 'Rhythm & Blues Studio',
        'lat' => 10.6740,
        'lng' => 122.9520,
        'location' => 'Araneta Avenue, Bacolod City',
        'owner_id' => $owner_ids[1] ?? 1
    ],
    [
        'name' => 'Sound Wave Recording',
        'lat' => 10.6800,
        'lng' => 122.9600,
        'location' => 'Magsaysay Avenue, Bacolod City',
        'owner_id' => $owner_ids[2] ?? 1
    ],
    [
        'name' => 'Echo Chamber Studio',
        'lat' => 10.6720,
        'lng' => 122.9480,
        'location' => 'BS Aquino Drive, Bacolod City',
        'owner_id' => $owner_ids[0] ?? 1
    ],
    [
        'name' => 'Melody Makers Hub',
        'lat' => 10.6780,
        'lng' => 122.9560,
        'location' => 'Burgos Street, Bacolod City',
        'owner_id' => $owner_ids[1] ?? 1
    ],
    [
        'name' => 'Beat Box Studio',
        'lat' => 10.6690,
        'lng' => 122.9450,
        'location' => 'Hilado Street, Bacolod City',
        'owner_id' => $owner_ids[2] ?? 1
    ]
];

echo "<h3>Inserting Sample Studios:</h3>";

foreach ($sample_studios as $studio) {
    $insert_query = "INSERT INTO studios (StudioName, Latitude, Longitude, Loc_Desc, OwnerID, Time_IN, Time_OUT) 
                     VALUES (?, ?, ?, ?, ?, '08:00:00', '22:00:00')";
    
    $stmt = mysqli_prepare($conn, $insert_query);
    mysqli_stmt_bind_param($stmt, "sddsi", 
        $studio['name'], 
        $studio['lat'], 
        $studio['lng'], 
        $studio['location'], 
        $studio['owner_id']
    );
    
    if (mysqli_stmt_execute($stmt)) {
        echo "<p style='color: green;'>✓ Added: " . $studio['name'] . " at " . $studio['location'] . "</p>";
    } else {
        echo "<p style='color: red;'>✗ Failed to add: " . $studio['name'] . " - " . mysqli_error($conn) . "</p>";
    }
    
    mysqli_stmt_close($stmt);
}

// Verify insertion
$count_query = "SELECT COUNT(*) as total FROM studios";
$count_result = mysqli_query($conn, $count_query);
$count_row = mysqli_fetch_assoc($count_result);

echo "<h3>Summary:</h3>";
echo "<p><strong>Total studios in database: " . $count_row['total'] . "</strong></p>";

$coord_query = "SELECT COUNT(*) as with_coords FROM studios WHERE Latitude IS NOT NULL AND Longitude IS NOT NULL";
$coord_result = mysqli_query($conn, $coord_query);
$coord_row = mysqli_fetch_assoc($coord_result);
echo "<p><strong>Studios with coordinates: " . $coord_row['with_coords'] . "</strong></p>";

echo "<p style='color: green; font-weight: bold;'>✓ Sample studios setup complete!</p>";
echo "<p><a href='client/php/browse.php'>→ Go to Browse Studios Page</a></p>";

mysqli_close($conn);
?>
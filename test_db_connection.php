<?php
/**
 * Database Connection Test Script
 * This script tests the database connection and checks if the museek database exists
 */

echo "<h2>Database Connection Test</h2>";

// Database configuration
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "museek";

echo "<p><strong>Testing connection parameters:</strong></p>";
echo "<ul>";
echo "<li>Server: $servername</li>";
echo "<li>Username: $username</li>";
echo "<li>Password: " . (empty($password) ? "Empty" : "Set") . "</li>";
echo "<li>Database: $dbname</li>";
echo "</ul>";

// Test basic MySQL connection
echo "<h3>1. Testing MySQL Connection</h3>";
$conn_test = new mysqli($servername, $username, $password);

if ($conn_test->connect_error) {
    echo "<p style='color: red;'>❌ MySQL Connection Failed: " . $conn_test->connect_error . "</p>";
    exit();
} else {
    echo "<p style='color: green;'>✅ MySQL Connection Successful</p>";
}

// Check if museek database exists
echo "<h3>2. Checking if 'museek' database exists</h3>";
$db_check = $conn_test->query("SHOW DATABASES LIKE 'museek'");
if ($db_check->num_rows > 0) {
    echo "<p style='color: green;'>✅ Database 'museek' exists</p>";
} else {
    echo "<p style='color: red;'>❌ Database 'museek' does not exist</p>";
    echo "<p><strong>Solution:</strong> Create the database using phpMyAdmin or import the SQL file.</p>";
}

$conn_test->close();

// Test connection to museek database
echo "<h3>3. Testing Connection to 'museek' Database</h3>";
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo "<p style='color: red;'>❌ Connection to 'museek' database failed: " . $conn->connect_error . "</p>";
} else {
    echo "<p style='color: green;'>✅ Connection to 'museek' database successful</p>";
    
    // Check some key tables
    echo "<h3>4. Checking Key Tables</h3>";
    $tables_to_check = ['clients', 'studios', 'studio_owners', 'bookings'];
    
    foreach ($tables_to_check as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        if ($result->num_rows > 0) {
            echo "<p style='color: green;'>✅ Table '$table' exists</p>";
        } else {
            echo "<p style='color: red;'>❌ Table '$table' does not exist</p>";
        }
    }
}

$conn->close();

echo "<h3>Test Complete</h3>";
echo "<p>If all tests pass, your database connection should work properly.</p>";
?>
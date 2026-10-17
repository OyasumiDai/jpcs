<?php
$servername = "localhost";
$username = "root"; // default XAMPP user
$password = "";     // default XAMPP password is empty
$dbname = "jpcs_event_system"; // pangalan ng database mo

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>

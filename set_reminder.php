<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = $_POST['title'];
    $description = $_POST['description'];
    $reminder_date = $_POST['reminder_date'];
    $reminder_time = $_POST['reminder_time'] ?: null;
    $type = $_POST['type'];
    $recurring = $_POST['recurring'];
    
    $sql = "INSERT INTO reminders (user_id, title, description, reminder_date, reminder_time, type, recurring) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("issssss", $user_id, $title, $description, $reminder_date, $reminder_time, $type, $recurring);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Reminder set successfully!";
    } else {
        $_SESSION['error'] = "Error setting reminder!";
    }
}

redirect('savings.php');
?>
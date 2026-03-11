<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $goal_name = $_POST['goal_name'];
    $target_amount = $_POST['target_amount'];
    $current_amount = $_POST['current_amount'];
    $deadline = $_POST['deadline'] ?: null;
    $priority = $_POST['priority'];
    $category = $_POST['category'] ?: null;
    
    $sql = "INSERT INTO savings_goals (user_id, goal_name, target_amount, current_amount, deadline, priority, category) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isddsss", $user_id, $goal_name, $target_amount, $current_amount, $deadline, $priority, $category);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Savings goal added successfully!";
        
        // Create a reminder for the goal
        if ($deadline) {
            $reminder_title = "Goal Deadline: " . $goal_name;
            $reminder_date = $deadline;
            $reminder_sql = "INSERT INTO reminders (user_id, title, reminder_date, type) 
                            VALUES (?, ?, ?, 'goal')";
            $stmt = $conn->prepare($reminder_sql);
            $stmt->bind_param("iss", $user_id, $reminder_title, $reminder_date);
            $stmt->execute();
        }
    } else {
        $_SESSION['error'] = "Error adding savings goal!";
    }
}

redirect('savings.php');
?>
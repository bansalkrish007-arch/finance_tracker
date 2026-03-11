<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $category = $_POST['category'];
    $amount = $_POST['amount'];
    $alert_threshold = $_POST['alert_threshold'];
    $month_year = $_POST['month_year'] . '-01';
    
    // Check if budget already exists
    $check_sql = "SELECT id FROM budgets WHERE user_id = ? AND category = ? AND month_year = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("iss", $user_id, $category, $month_year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Update existing budget
        $sql = "UPDATE budgets SET amount = ?, alert_threshold = ? 
                WHERE user_id = ? AND category = ? AND month_year = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("diiss", $amount, $alert_threshold, $user_id, $category, $month_year);
    } else {
        // Insert new budget
        $sql = "INSERT INTO budgets (user_id, category, amount, alert_threshold, month_year) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isdss", $user_id, $category, $amount, $alert_threshold, $month_year);
    }
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Budget set successfully!";
    } else {
        $_SESSION['error'] = "Error setting budget!";
    }
}

redirect('savings.php');
?>
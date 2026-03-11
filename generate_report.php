<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$type = $_GET['type'] ?? 'monthly';

// Set headers for download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="finance_report_' . $type . '_' . date('Y-m-d') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

switch($type) {
    case 'monthly':
        // Monthly Report
        fputcsv($output, ['Month', 'Income', 'Expense', 'Savings', 'Savings Rate']);
        
        $sql = "SELECT 
            DATE_FORMAT(transaction_date, '%Y-%m') as month,
            COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as income,
            COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as expense
            FROM transactions 
            WHERE user_id = ?
            GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
            ORDER BY month DESC";
            
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $savings = $row['income'] - $row['expense'];
            $savings_rate = $row['income'] > 0 ? round(($savings / $row['income']) * 100, 2) : 0;
            fputcsv($output, [
                $row['month'],
                number_format($row['income'], 2),
                number_format($row['expense'], 2),
                number_format($savings, 2),
                $savings_rate . '%'
            ]);
        }
        break;
        
    case 'category':
        // Category Analysis
        fputcsv($output, ['Category', 'Total Spent', 'Percentage of Total', 'Transaction Count']);
        
        $sql = "SELECT 
            category,
            COALESCE(SUM(amount), 0) as total,
            COUNT(*) as count
            FROM transactions 
            WHERE user_id = ? AND type = 'expense'
            GROUP BY category
            ORDER BY total DESC";
            
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // Get total expenses for percentage
        $total_sql = "SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE user_id = ? AND type = 'expense'";
        $stmt = $conn->prepare($total_sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $total_expense = $stmt->get_result()->fetch_assoc()['total'];
        
        while ($row = $result->fetch_assoc()) {
            $percentage = $total_expense > 0 ? round(($row['total'] / $total_expense) * 100, 2) : 0;
            fputcsv($output, [
                $row['category'],
                number_format($row['total'], 2),
                $percentage . '%',
                $row['count']
            ]);
        }
        break;
        
    case 'savings':
        // Savings Progress
        fputcsv($output, ['Goal', 'Target Amount', 'Current Amount', 'Progress %', 'Deadline', 'Priority']);
        
        $sql = "SELECT * FROM savings_goals WHERE user_id = ? AND status = 'active' ORDER BY deadline ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $progress = $row['target_amount'] > 0 ? round(($row['current_amount'] / $row['target_amount']) * 100, 2) : 0;
            fputcsv($output, [
                $row['goal_name'],
                number_format($row['target_amount'], 2),
                number_format($row['current_amount'], 2),
                $progress . '%',
                $row['deadline'] ?? 'No deadline',
                ucfirst($row['priority'])
            ]);
        }
        break;
        
    case 'budget':
        // Budget Performance
        fputcsv($output, ['Category', 'Budget', 'Spent', 'Remaining', 'Usage %', 'Status']);
        
        $current_month = date('Y-m') . '-01';
        $sql = "SELECT 
            b.*,
            COALESCE(SUM(t.amount), 0) as spent
            FROM budgets b
            LEFT JOIN transactions t ON b.user_id = t.user_id 
                AND b.category = t.category 
                AND t.type = 'expense'
                AND DATE_FORMAT(t.transaction_date, '%Y-%m') = DATE_FORMAT(b.month_year, '%Y-%m')
            WHERE b.user_id = ? AND b.month_year = ?
            GROUP BY b.id";
            
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $user_id, $current_month);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $remaining = $row['amount'] - $row['spent'];
            $percentage = $row['amount'] > 0 ? round(($row['spent'] / $row['amount']) * 100, 2) : 0;
            $status = $percentage >= 100 ? 'Exceeded' : ($percentage >= $row['alert_threshold'] ? 'Near Limit' : 'On Track');
            
            fputcsv($output, [
                $row['category'],
                number_format($row['amount'], 2),
                number_format($row['spent'], 2),
                number_format($remaining, 2),
                $percentage . '%',
                $status
            ]);
        }
        break;
}

fclose($output);
exit();
?>
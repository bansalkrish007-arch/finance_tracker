<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get monthly summary
$monthly_sql = "SELECT 
    DATE_FORMAT(transaction_date, '%Y-%m') as month,
    SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as income,
    SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expense
    FROM transactions 
    WHERE user_id = ?
    GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6";

$stmt = $conn->prepare($monthly_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$monthly_data = $stmt->get_result();

// Get category breakdown for current month
$category_sql = "SELECT 
    category,
    SUM(amount) as total
    FROM transactions 
    WHERE user_id = ? 
    AND type = 'expense'
    AND DATE_FORMAT(transaction_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
    GROUP BY category
    ORDER BY total DESC";

$stmt = $conn->prepare($category_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$category_data = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Finance Tracker</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <h1>Finance Tracker</h1>
            <div class="nav-links">
                <span>Welcome, <?php echo $_SESSION['username']; ?></span>
                <a href="dashboard.php">Dashboard</a>
                <a href="add_transaction.php">Add Transaction</a>
                <a href="transaction.php">All Transactions</a>
                <a href="savings.php">Savings</a>
                <a href="reports.php" class="active">Reports</a>
                <a href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <h2>Financial Reports</h2>

        <div class="reports-grid">
            <div class="report-card">
                <h3>Monthly Summary (Last 6 Months)</h3>
                <canvas id="monthlyChart"></canvas>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Income</th>
                            <th>Expense</th>
                            <th>Savings</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $months = [];
                        $incomes = [];
                        $expenses = [];
                        
                        while ($row = $monthly_data->fetch_assoc()): 
                            $months[] = $row['month'];
                            $incomes[] = $row['income'];
                            $expenses[] = $row['expense'];
                            $savings = $row['income'] - $row['expense'];
                        ?>
                        <tr>
                            <td><?php echo $row['month']; ?></td>
                            <td>₹<?php echo number_format($row['income'], 2); ?></td>
                            <td>₹<?php echo number_format($row['expense'], 2); ?></td>
                            <td class="<?php echo $savings >= 0 ? 'positive' : 'negative'; ?>">
                                ₹<?php echo number_format($savings, 2); ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <div class="report-card">
                <h3>Current Month Expenses by Category</h3>
                <canvas id="categoryChart"></canvas>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Amount</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $categories = [];
                        $amounts = [];
                        $total_expense = 0;
                        
                        // First pass to get total
                        $category_data->data_seek(0);
                        while ($row = $category_data->fetch_assoc()) {
                            $total_expense += $row['total'];
                        }
                        
                        // Second pass to display
                        $category_data->data_seek(0);
                        while ($row = $category_data->fetch_assoc()): 
                            $categories[] = $row['category'];
                            $amounts[] = $row['total'];
                            $percentage = $total_expense > 0 ? ($row['total'] / $total_expense) * 100 : 0;
                        ?>
                        <tr>
                            <td><?php echo $row['category']; ?></td>
                            <td>₹<?php echo number_format($row['total'], 2); ?></td>
                            <td><?php echo number_format($percentage, 1); ?>%</td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    // Monthly Chart
    const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
    new Chart(monthlyCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_reverse($months)); ?>,
            datasets: [{
                label: 'Income',
                data: <?php echo json_encode(array_reverse($incomes)); ?>,
                backgroundColor: 'rgba(40, 167, 69, 0.5)',
                borderColor: 'rgb(40, 167, 69)',
                borderWidth: 1
            }, {
                label: 'Expense',
                data: <?php echo json_encode(array_reverse($expenses)); ?>,
                backgroundColor: 'rgba(220, 53, 69, 0.5)',
                borderColor: 'rgb(220, 53, 69)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });

    // Category Chart
    const categoryCtx = document.getElementById('categoryChart').getContext('2d');
    new Chart(categoryCtx, {
        type: 'pie',
        data: {
            labels: <?php echo json_encode($categories); ?>,
            datasets: [{
                data: <?php echo json_encode($amounts); ?>,
                backgroundColor: [
                    '#FF6384',
                    '#36A2EB',
                    '#FFCE56',
                    '#4BC0C0',
                    '#9966FF',
                    '#FF9F40'
                ]
            }]
        },
        options: {
            responsive: true
        }
    });
    </script>
</body>
</html>

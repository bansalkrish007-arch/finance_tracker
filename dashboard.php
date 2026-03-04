<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get total income
$income_sql = "SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE user_id = ? AND type = 'income'";
$stmt = $conn->prepare($income_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$total_income = $stmt->get_result()->fetch_assoc()['total'];

// Get total expenses
$expense_sql = "SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE user_id = ? AND type = 'expense'";
$stmt = $conn->prepare($expense_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$total_expense = $stmt->get_result()->fetch_assoc()['total'];

$balance = $total_income - $total_expense;

// Get recent transactions
$recent_sql = "SELECT * FROM transactions WHERE user_id = ? ORDER BY transaction_date DESC LIMIT 5";
$stmt = $conn->prepare($recent_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$recent_transactions = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Finance Tracker</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <h1>Finance Tracker</h1>
            <div class="nav-links">
                <span>Welcome, <?php echo $_SESSION['username']; ?></span>
                <a href="dashboard.php" class="active">Dashboard</a>
                <a href="add_transaction.php">Add Transaction</a>
                <a href="transaction.php">All Transactions</a>
                <a href="reports.php">Reports</a>
                <a href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <h2>Dashboard</h2>
        
        <div class="summary-cards">
            <div class="card income">
                <h3>Total Income</h3>
                <p class="amount">₹<?php echo number_format($total_income, 2); ?></p>
            </div>
            <div class="card expense">
                <h3>Total Expenses</h3>
                <p class="amount">₹<?php echo number_format($total_expense, 2); ?></p>
            </div>
            <div class="card balance">
                <h3>Current Balance</h3>
                <p class="amount">₹<?php echo number_format($balance, 2); ?></p>
            </div>
        </div>

        <div class="recent-transactions">
            <h3>Recent Transactions</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $recent_transactions->fetch_assoc()): ?>
                    <tr class="<?php echo $row['type']; ?>">
                        <td><?php echo date('Y-m-d', strtotime($row['transaction_date'])); ?></td>
                        <td><?php echo ucfirst($row['type']); ?></td>
                        <td><?php echo $row['category']; ?></td>
                        <td><?php echo $row['description']; ?></td>
                        <td>$<?php echo number_format($row['amount'], 2); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <a href="transaction.php" class="btn btn-secondary">View All Transactions</a>
        </div>
    </div>
</body>
</html>
<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Handle delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $delete_sql = "DELETE FROM transactions WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($delete_sql);
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();
    redirect('transactions.php');
}

// Get filter parameters
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$month_filter = isset($_GET['month']) ? $_GET['month'] : '';

// Build query
$sql = "SELECT * FROM transactions WHERE user_id = ?";
$params = [$user_id];
$types = "i";

if ($type_filter) {
    $sql .= " AND type = ?";
    $params[] = $type_filter;
    $types .= "s";
}

if ($month_filter) {
    $sql .= " AND DATE_FORMAT(transaction_date, '%Y-%m') = ?";
    $params[] = $month_filter;
    $types .= "s";
}

$sql .= " ORDER BY transaction_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$transactions = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions - Finance Tracker</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <h1>Finance Tracker</h1>
            <div class="nav-links">
                <span>Welcome, <?php echo $_SESSION['username']; ?></span>
                <a href="dashboard.php">Dashboard</a>
                <a href="add_transaction.php">Add Transaction</a>
                <a href="transactions.php" class="active">All Transactions</a>
                <a href="reports.php">Reports</a>
                <a href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <h2>All Transactions</h2>

        <div class="filters">
            <form method="GET" action="">
                <select name="type">
                    <option value="">All Types</option>
                    <option value="income" <?php echo $type_filter == 'income' ? 'selected' : ''; ?>>Income</option>
                    <option value="expense" <?php echo $type_filter == 'expense' ? 'selected' : ''; ?>>Expense</option>
                </select>
                <input type="month" name="month" value="<?php echo $month_filter; ?>">
                <button type="submit" class="btn btn-secondary">Filter</button>
                <a href="transactions.php" class="btn btn-secondary">Clear</a>
            </form>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Amount</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($transactions->num_rows == 0): ?>
                <tr>
                    <td colspan="6" class="text-center">No transactions found</td>
                </tr>
                <?php else: ?>
                <?php while ($row = $transactions->fetch_assoc()): ?>
                <tr class="<?php echo $row['type']; ?>">
                    <td><?php echo date('Y-m-d', strtotime($row['transaction_date'])); ?></td>
                    <td><?php echo ucfirst($row['type']); ?></td>
                    <td><?php echo $row['category']; ?></td>
                    <td><?php echo $row['description']; ?></td>
                    <td>₹<?php echo number_format($row['amount'], 2); ?></td>
                    <td>
                        <a href="edit_transaction.php?id=<?php echo $row['id']; ?>" class="btn-edit">Edit</a>
                        <a href="?delete=<?php echo $row['id']; ?>" class="btn-delete" onclick="return confirm('Are you sure?')">Delete</a>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$id = $_GET['id'] ?? 0;

// Fetch transaction
$sql = "SELECT * FROM transactions WHERE id = ? AND user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    redirect('transactions.php');
}

$transaction = $result->fetch_assoc();

// Get categories
$cat_sql = "SELECT * FROM categories WHERE user_id IS NULL OR user_id = ? ORDER BY type, name";
$stmt = $conn->prepare($cat_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$categories = $stmt->get_result();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $type = $_POST['type'];
    $category = $_POST['category'];
    $amount = $_POST['amount'];
    $description = $_POST['description'];
    $transaction_date = $_POST['transaction_date'];
    
    $update_sql = "UPDATE transactions SET type=?, category=?, amount=?, description=?, transaction_date=? WHERE id=? AND user_id=?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("ssdssii", $type, $category, $amount, $description, $transaction_date, $id, $user_id);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Transaction updated successfully!";
        redirect('transactions.php');
    } else {
        $error = "Error updating transaction!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Transaction - Finance Tracker</title>
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
                <a href="transactions.php">All Transactions</a>
                <a href="reports.php">Reports</a>
                <a href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <h2>Edit Transaction</h2>
        
        <?php if (isset($error)): ?>
            <div class="alert error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST" action="" class="transaction-form">
            <div class="form-group">
                <label for="type">Transaction Type:</label>
                <select id="type" name="type" required onchange="filterCategories()">
                    <option value="income" <?php echo $transaction['type'] == 'income' ? 'selected' : ''; ?>>Income</option>
                    <option value="expense" <?php echo $transaction['type'] == 'expense' ? 'selected' : ''; ?>>Expense</option>
                </select>
            </div>

            <div class="form-group">
                <label for="category">Category:</label>
                <select id="category" name="category" required>
                    <option value="">Select Category</option>
                    <?php 
                    $categories->data_seek(0);
                    while($cat = $categories->fetch_assoc()): 
                    ?>
                    <option value="<?php echo $cat['name']; ?>" 
                            data-type="<?php echo $cat['type']; ?>"
                            <?php echo $transaction['category'] == $cat['name'] ? 'selected' : ''; ?>>
                        <?php echo $cat['name']; ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="amount">Amount (₹):</label>
                <input type="number" id="amount" name="amount" step="0.01" min="0" 
                       value="<?php echo $transaction['amount']; ?>" required>
            </div>

            <div class="form-group">
                <label for="description">Description:</label>
                <textarea id="description" name="description" rows="3"><?php echo htmlspecialchars($transaction['description']); ?></textarea>
            </div>

            <div class="form-group">
                <label for="transaction_date">Date:</label>
                <input type="date" id="transaction_date" name="transaction_date" 
                       value="<?php echo $transaction['transaction_date']; ?>" required>
            </div>

            <button type="submit" class="btn btn-primary">Update Transaction</button>
            <a href="transactions.php" class="btn btn-secondary" style="margin-top: 10px;">Cancel</a>
        </form>
    </div>

    <script>
    function filterCategories() {
        var type = document.getElementById('type').value;
        var categorySelect = document.getElementById('category');
        var options = categorySelect.getElementsByTagName('option');
        
        for (var i = 0; i < options.length; i++) {
            var option = options[i];
            if (option.value === "") continue;
            
            if (option.getAttribute('data-type') === type) {
                option.style.display = '';
            } else {
                option.style.display = 'none';
            }
        }
    }
    
    // Run on page load
    filterCategories();
    </script>
</body>
</html>

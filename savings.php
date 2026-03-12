<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$current_month = date('Y-m');
$last_month = date('Y-m', strtotime('-1 month'));

// Get current month savings
$current_sql = "SELECT 
    COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as total_income,
    COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as total_expense
    FROM transactions 
    WHERE user_id = ? AND DATE_FORMAT(transaction_date, '%Y-%m') = ?";
    
$stmt = $conn->prepare($current_sql);
$stmt->bind_param("is", $user_id, $current_month); // Fixed: parameters passed directly
$stmt->execute();
$current = $stmt->get_result()->fetch_assoc();
$current_savings = $current['total_income'] - $current['total_expense'];

// Get last month savings
$stmt = $conn->prepare($current_sql);
$stmt->bind_param("is", $user_id, $last_month); // Fixed: parameters passed directly
$stmt->execute();
$last = $stmt->get_result()->fetch_assoc();
$last_savings = $last['total_income'] - $last['total_expense'];

// Calculate savings rate
$savings_rate = $current['total_income'] > 0 ? 
    round(($current_savings / $current['total_income']) * 100, 2) : 0;

// Get budget status
$budget_sql = "SELECT 
    b.*,
    COALESCE(SUM(t.amount), 0) as spent
    FROM budgets b
    LEFT JOIN transactions t ON b.user_id = t.user_id 
        AND b.category = t.category 
        AND t.type = 'expense'
        AND DATE_FORMAT(t.transaction_date, '%Y-%m') = DATE_FORMAT(b.month_year, '%Y-%m')
    WHERE b.user_id = ? AND b.month_year = ?
    GROUP BY b.id";
    
$stmt = $conn->prepare($budget_sql);
$budget_month = $current_month . '-01'; // Create full date for comparison
$stmt->bind_param("is", $user_id, $budget_month); // Fixed: using variable
$stmt->execute();
$budgets = $stmt->get_result();

// Check for budget alerts
while ($budget = $budgets->fetch_assoc()) {
    $percentage = $budget['spent'] > 0 ? 
        round(($budget['spent'] / $budget['amount']) * 100, 2) : 0;
    
    if ($percentage >= $budget['alert_threshold'] && $percentage < 100) {
        // Check if alert already exists for this budget
        $check_alert = "SELECT id FROM budget_alerts 
                       WHERE user_id = ? AND category = ? 
                       AND DATE(alert_date) = CURDATE() 
                       AND is_acknowledged = FALSE";
        $stmt = $conn->prepare($check_alert);
        $stmt->bind_param("is", $user_id, $budget['category']);
        $stmt->execute();
        $alert_exists = $stmt->get_result()->fetch_assoc();
        
        if (!$alert_exists) {
            // Create alert
            $alert_sql = "INSERT INTO budget_alerts (user_id, category, budget_amount, spent_amount, percentage_used) 
                         VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($alert_sql);
            $stmt->bind_param("isdds", $user_id, $budget['category'], $budget['amount'], $budget['spent'], $percentage);
            $stmt->execute();
        }
    }
}
$budgets->data_seek(0); // Reset pointer

// Get category wise comparison
$category_sql = "SELECT 
    category,
    SUM(CASE WHEN DATE_FORMAT(transaction_date, '%Y-%m') = ? THEN amount ELSE 0 END) as current_amount,
    SUM(CASE WHEN DATE_FORMAT(transaction_date, '%Y-%m') = ? THEN amount ELSE 0 END) as last_amount
    FROM transactions 
    WHERE user_id = ? AND type = 'expense'
    GROUP BY category
    HAVING current_amount > 0 OR last_amount > 0";
    
$stmt = $conn->prepare($category_sql);
$stmt->bind_param("ssi", $current_month, $last_month, $user_id); // Fixed: correct order
$stmt->execute();
$category_comparison = $stmt->get_result();

// Get savings goals
$goals_sql = "SELECT * FROM savings_goals WHERE user_id = ? AND status = 'active' ORDER BY deadline ASC";
$stmt = $conn->prepare($goals_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$goals = $stmt->get_result();

// Get unread reminders
$reminders_sql = "SELECT * FROM reminders WHERE user_id = ? AND reminder_date >= CURDATE() AND is_read = FALSE ORDER BY reminder_date ASC";
$stmt = $conn->prepare($reminders_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$reminders = $stmt->get_result();

// Get categories for modals
$cat_sql = "SELECT DISTINCT category FROM transactions WHERE user_id = ? AND type = 'expense'";
$stmt = $conn->prepare($cat_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$categories = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Savings & Budget - Finance Tracker</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .savings-dashboard {
            padding: 20px 0;
        }
        
        .comparison-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .comparison-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            position: relative;
            overflow: hidden;
        }
        
        .comparison-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: rgba(255,255,255,0.1);
            transform: rotate(45deg);
            pointer-events: none;
        }
        
        .comparison-card h3 {
            font-size: 18px;
            margin-bottom: 15px;
            opacity: 0.9;
        }
        
        .comparison-card .amount {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .trend-indicator {
            display: inline-flex;
            align-items: center;
            padding: 5px 15px;
            border-radius: 25px;
            background: rgba(255,255,255,0.2);
            font-size: 14px;
        }
        
        .trend-up { color: #4ade80; }
        .trend-down { color: #f87171; }
        
        .savings-rate {
            margin-top: 15px;
            padding: 10px;
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
        }
        
        .budget-alert {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-left: 5px solid #f59e0b;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.8; }
            100% { opacity: 1; }
        }
        
        .budget-alert i {
            font-size: 24px;
            color: #f59e0b;
        }
        
        .budget-progress {
            height: 10px;
            background: #e5e7eb;
            border-radius: 5px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .progress-bar {
            height: 100%;
            transition: width 0.3s ease;
        }
        
        .progress-bar.warning { background: #f59e0b; }
        .progress-bar.danger { background: #ef4444; }
        .progress-bar.success { background: #10b981; }
        
        .goal-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border-left: 5px solid;
        }
        
        .goal-card.high { border-left-color: #ef4444; }
        .goal-card.medium { border-left-color: #f59e0b; }
        .goal-card.low { border-left-color: #10b981; }
        
        .category-comparison {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
            margin-top: 30px;
        }
        
        .report-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        
        .report-btn {
            flex: 1;
            min-width: 200px;
            padding: 15px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: transform 0.3s;
        }
        
        .report-btn:hover {
            transform: translateY(-2px);
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .close-modal {
            float: right;
            font-size: 24px;
            cursor: pointer;
            color: #666;
        }
        
        .priority-badge {
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .priority-badge.high {
            background: #fee2e2;
            color: #ef4444;
        }
        
        .priority-badge.medium {
            background: #fef3c7;
            color: #f59e0b;
        }
        
        .priority-badge.low {
            background: #d1fae5;
            color: #10b981;
        }
        
        .floating-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            border-radius: 50px;
            padding: 15px 25px;
            z-index: 99;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        @media (max-width: 768px) {
            .comparison-cards {
                grid-template-columns: 1fr;
            }
            
            .category-comparison {
                grid-template-columns: 1fr;
            }
            
            .floating-btn {
                bottom: 10px;
                right: 10px;
                padding: 10px 15px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <h1><i class="fas fa-chart-line"></i> FinanceTracker</h1>
            <div class="nav-links">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <a href="dashboard.php"> Dashboard</a>
                <a href="add_transaction.php"> Add Transaction</a>
                <a href="transaction.php"> All Transactions</a>
                <a href="savings.php" class="active"> Savings</a>
                <a href="reports.php"> Reports</a>
                <a href="logout.php"> Logout</a>
            </div>
        </div>
    </nav>

    <div class="container savings-dashboard">
        <!-- Reminder Alert -->
        <?php if ($reminders && $reminders->num_rows > 0): ?>
        <div class="budget-alert">
            <i class="fas fa-bell"></i>
            <div>
                <strong>You have <?php echo $reminders->num_rows; ?> pending reminder(s)</strong>
                <ul style="margin-top: 10px; list-style: none;">
                    <?php while($reminder = $reminders->fetch_assoc()): ?>
                    <li style="margin-bottom: 5px;">
                        <i class="fas fa-clock"></i> 
                        <?php echo htmlspecialchars($reminder['title']); ?> - 
                        <?php echo date('M d, Y', strtotime($reminder['reminder_date'])); ?>
                        <?php if(!empty($reminder['reminder_time'])): ?>
                        at <?php echo date('h:i A', strtotime($reminder['reminder_time'])); ?>
                        <?php endif; ?>
                    </li>
                    <?php endwhile; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <h2><i class="fas fa-piggy-bank"></i> Savings & Budget Dashboard</h2>

        <!-- Comparison Cards -->
        <div class="comparison-cards">
            <div class="comparison-card">
                <h3><i class="fas fa-calendar-current"></i> Current Month Savings</h3>
                <div class="amount">₹<?php echo number_format($current_savings, 2); ?></div>
                <?php 
                $trend = $current_savings - $last_savings;
                $trend_class = $trend >= 0 ? 'trend-up' : 'trend-down';
                $trend_icon = $trend >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                ?>
                <div class="trend-indicator">
                    <i class="fas <?php echo $trend_icon . ' ' . $trend_class; ?>"></i>
                    <?php echo $trend >= 0 ? '+' : ''; ?>₹<?php echo number_format(abs($trend), 2); ?> vs last month
                </div>
                <div class="savings-rate">
                    <strong>Savings Rate:</strong> <?php echo $savings_rate; ?>%
                </div>
            </div>

            <div class="comparison-card">
                <h3><i class="fas fa-calendar"></i> Last Month Savings</h3>
                <div class="amount">₹<?php echo number_format($last_savings, 2); ?></div>
                <div class="trend-indicator">
                    <i class="fas fa-info-circle"></i>
                    Total Income: ₹<?php echo number_format($last['total_income'], 2); ?>
                </div>
            </div>
        </div>

        <!-- Budget Overview -->
        <div style="background: white; border-radius: 15px; padding: 25px; margin-bottom: 30px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <h3 style="margin-bottom: 20px;"><i class="fas fa-tasks"></i> Budget Status - Current Month</h3>
            
            <?php if ($budgets && $budgets->num_rows > 0): ?>
            <div class="category-comparison">
                <div>
                    <canvas id="budgetChart"></canvas>
                </div>
                <div>
                    <?php 
                    $budgets->data_seek(0);
                    while($budget = $budgets->fetch_assoc()): 
                        $percentage = $budget['spent'] > 0 ? round(($budget['spent'] / $budget['amount']) * 100, 2) : 0;
                        $bar_class = $percentage >= 100 ? 'danger' : ($percentage >= $budget['alert_threshold'] ? 'warning' : 'success');
                    ?>
                    <div style="margin-bottom: 15px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span><strong><?php echo htmlspecialchars($budget['category']); ?></strong></span>
                            <span>
                                ₹<?php echo number_format($budget['spent'], 2); ?> / 
                                ₹<?php echo number_format($budget['amount'], 2); ?>
                                (<?php echo $percentage; ?>%)
                            </span>
                        </div>
                        <div class="budget-progress">
                            <div class="progress-bar <?php echo $bar_class; ?>" 
                                 style="width: <?php echo min($percentage, 100); ?>%"></div>
                        </div>
                        <?php if($percentage >= $budget['alert_threshold'] && $percentage < 100): ?>
                        <small style="color: #f59e0b;">
                            <i class="fas fa-exclamation-triangle"></i> 
                            Approaching budget limit (<?php echo $budget['alert_threshold']; ?>% threshold)
                        </small>
                        <?php elseif($percentage >= 100): ?>
                        <small style="color: #ef4444;">
                            <i class="fas fa-times-circle"></i> 
                            Budget exceeded!
                        </small>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
            <?php else: ?>
            <p class="text-center">No budgets set for this month. <a href="#" onclick="openModal('budgetModal')">Set a budget now</a>.</p>
            <?php endif; ?>
            
            <button class="btn btn-primary" onclick="openModal('budgetModal')" style="margin-top: 20px;">
                <i class="fas fa-plus"></i> Set New Budget
            </button>
        </div>

        <!-- Category Comparison -->
        <div style="background: white; border-radius: 15px; padding: 25px; margin-bottom: 30px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <h3 style="margin-bottom: 20px;"><i class="fas fa-chart-pie"></i> Category Comparison (Current vs Last Month)</h3>
            
            <?php if ($category_comparison && $category_comparison->num_rows > 0): ?>
            <div class="category-comparison">
                <div>
                    <canvas id="categoryComparisonChart"></canvas>
                </div>
                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Current Month</th>
                                <th>Last Month</th>
                                <th>Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $category_comparison->data_seek(0);
                            while($cat = $category_comparison->fetch_assoc()): 
                                $change = $cat['current_amount'] - $cat['last_amount'];
                                $change_class = $change <= 0 ? 'positive' : 'negative';
                                $change_icon = $change <= 0 ? 'fa-arrow-down' : 'fa-arrow-up';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($cat['category']); ?></td>
                                <td>₹<?php echo number_format($cat['current_amount'], 2); ?></td>
                                <td>₹<?php echo number_format($cat['last_amount'], 2); ?></td>
                                <td class="<?php echo $change_class; ?>">
                                    <i class="fas <?php echo $change_icon; ?>"></i>
                                    ₹<?php echo number_format(abs($change), 2); ?>
                                    (<?php echo $cat['last_amount'] > 0 ? round(($change / $cat['last_amount']) * 100, 1) : 0; ?>%)
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <p class="text-center">No expense data available for comparison.</p>
            <?php endif; ?>
        </div>

        <!-- Savings Goals -->
        <div style="background: white; border-radius: 15px; padding: 25px; margin-bottom: 30px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <h3 style="margin-bottom: 20px;"><i class="fas fa-bullseye"></i> Savings Goals</h3>
            
            <?php if ($goals && $goals->num_rows > 0): ?>
            <div class="category-comparison">
                <div>
                    <canvas id="goalsChart"></canvas>
                </div>
                <div>
                    <?php 
                    $goals->data_seek(0);
                    while($goal = $goals->fetch_assoc()): 
                        $progress = $goal['target_amount'] > 0 ? round(($goal['current_amount'] / $goal['target_amount']) * 100, 2) : 0;
                        $days_left = !empty($goal['deadline']) ? floor((strtotime($goal['deadline']) - time()) / (60 * 60 * 24)) : null;
                    ?>
                    <div class="goal-card <?php echo $goal['priority']; ?>">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <h4><?php echo htmlspecialchars($goal['goal_name']); ?></h4>
                            <span class="priority-badge <?php echo $goal['priority']; ?>">
                                <?php echo ucfirst($goal['priority']); ?> Priority
                            </span>
                        </div>
                        <div style="margin: 15px 0;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                <span>Progress: <?php echo $progress; ?>%</span>
                                <span>
                                    ₹<?php echo number_format($goal['current_amount'], 2); ?> / 
                                    ₹<?php echo number_format($goal['target_amount'], 2); ?>
                                </span>
                            </div>
                            <div class="budget-progress">
                                <div class="progress-bar success" style="width: <?php echo min($progress, 100); ?>%"></div>
                            </div>
                        </div>
                        <?php if($days_left !== null): ?>
                        <small>
                            <i class="fas fa-clock"></i> 
                            <?php echo $days_left; ?> days left 
                            (<?php echo date('M d, Y', strtotime($goal['deadline'])); ?>)
                        </small>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
            <?php else: ?>
            <p class="text-center">No savings goals set. <a href="#" onclick="openModal('goalModal')">Create your first goal</a>.</p>
            <?php endif; ?>
            
            <button class="btn btn-primary" onclick="openModal('goalModal')" style="margin-top: 20px;">
                <i class="fas fa-plus"></i> Add New Goal
            </button>
        </div>

        <!-- Report Generation -->
        <div style="background: white; border-radius: 15px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <h3 style="margin-bottom: 20px;"><i class="fas fa-file-pdf"></i> Generate Reports</h3>
            <div class="report-actions">
                <button class="report-btn" onclick="generateReport('monthly')">
                    <i class="fas fa-calendar-alt"></i> Monthly Report
                </button>
                <button class="report-btn" onclick="generateReport('category')">
                    <i class="fas fa-chart-pie"></i> Category Analysis
                </button>
                <button class="report-btn" onclick="generateReport('savings')">
                    <i class="fas fa-piggy-bank"></i> Savings Progress
                </button>
                <button class="report-btn" onclick="generateReport('budget')">
                    <i class="fas fa-tasks"></i> Budget Performance
                </button>
            </div>
        </div>
    </div>

    <!-- Budget Modal -->
    <div id="budgetModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal('budgetModal')">&times;</span>
            <h2>Set Monthly Budget</h2>
            <form id="budgetForm" method="POST" action="set_budget.php">
                <div class="form-group">
                    <label>Category:</label>
                    <select name="category" required>
                        <option value="">Select Category</option>
                        <?php
                        if ($categories) {
                            $categories->data_seek(0);
                            while($cat = $categories->fetch_assoc()):
                        ?>
                        <option value="<?php echo htmlspecialchars($cat['category']); ?>">
                            <?php echo htmlspecialchars($cat['category']); ?>
                        </option>
                        <?php 
                            endwhile;
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Budget Amount (₹):</label>
                    <input type="number" name="amount" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label>Alert Threshold (%):</label>
                    <input type="number" name="alert_threshold" min="1" max="99" value="80" required>
                </div>
                <div class="form-group">
                    <label>Month:</label>
                    <input type="month" name="month_year" value="<?php echo date('Y-m'); ?>" required>
                </div>
                <button type="submit" class="btn btn-primary">Set Budget</button>
            </form>
        </div>
    </div>

    <!-- Goal Modal -->
    <div id="goalModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal('goalModal')">&times;</span>
            <h2>Add Savings Goal</h2>
            <form id="goalForm" method="POST" action="set_goal.php">
                <div class="form-group">
                    <label>Goal Name:</label>
                    <input type="text" name="goal_name" placeholder="e.g., Emergency Fund" required>
                </div>
                <div class="form-group">
                    <label>Target Amount (₹):</label>
                    <input type="number" name="target_amount" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label>Current Amount (₹):</label>
                    <input type="number" name="current_amount" step="0.01" min="0" value="0">
                </div>
                <div class="form-group">
                    <label>Deadline:</label>
                    <input type="date" name="deadline">
                </div>
                <div class="form-group">
                    <label>Priority:</label>
                    <select name="priority">
                        <option value="high">High</option>
                        <option value="medium" selected>Medium</option>
                        <option value="low">Low</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Category (Optional):</label>
                    <select name="category">
                        <option value="">None</option>
                        <?php 
                        if ($categories) {
                            $categories->data_seek(0);
                            while($cat = $categories->fetch_assoc()):
                        ?>
                        <option value="<?php echo htmlspecialchars($cat['category']); ?>">
                            <?php echo htmlspecialchars($cat['category']); ?>
                        </option>
                        <?php 
                            endwhile;
                        }
                        ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Add Goal</button>
            </form>
        </div>
    </div>

    <!-- Reminder Modal -->
    <div id="reminderModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal('reminderModal')">&times;</span>
            <h2>Set Reminder</h2>
            <form id="reminderForm" method="POST" action="set_reminder.php">
                <div class="form-group">
                    <label>Title:</label>
                    <input type="text" name="title" placeholder="e.g., Electricity Bill" required>
                </div>
                <div class="form-group">
                    <label>Description:</label>
                    <textarea name="description" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label>Date:</label>
                    <input type="date" name="reminder_date" required>
                </div>
                <div class="form-group">
                    <label>Time (Optional):</label>
                    <input type="time" name="reminder_time">
                </div>
                <div class="form-group">
                    <label>Type:</label>
                    <select name="type">
                        <option value="budget">Budget Alert</option>
                        <option value="bill">Bill Payment</option>
                        <option value="goal">Savings Goal</option>
                        <option value="custom">Custom</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Recurring:</label>
                    <select name="recurring">
                        <option value="none">None</option>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Set Reminder</button>
            </form>
        </div>
    </div>

    <button onclick="openModal('reminderModal')" class="btn btn-secondary floating-btn">
        <i class="fas fa-bell"></i> Set Reminder
    </button>

    <script>
        // Chart initialization
        document.addEventListener('DOMContentLoaded', function() {
            // Budget Chart
            <?php if ($budgets && $budgets->num_rows > 0): ?>
            const budgetCtx = document.getElementById('budgetChart')?.getContext('2d');
            if (budgetCtx) {
                <?php
                $budgets->data_seek(0);
                $budget_categories = [];
                $budget_amounts = [];
                $budget_spent = [];
                while($budget = $budgets->fetch_assoc()) {
                    $budget_categories[] = $budget['category'];
                    $budget_amounts[] = floatval($budget['amount']);
                    $budget_spent[] = floatval($budget['spent']);
                }
                ?>
                
                new Chart(budgetCtx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($budget_categories); ?>,
                        datasets: [{
                            label: 'Budget',
                            data: <?php echo json_encode($budget_amounts); ?>,
                            backgroundColor: 'rgba(102, 126, 234, 0.5)',
                            borderColor: '#667eea',
                            borderWidth: 2
                        }, {
                            label: 'Spent',
                            data: <?php echo json_encode($budget_spent); ?>,
                            backgroundColor: 'rgba(245, 158, 11, 0.5)',
                            borderColor: '#f59e0b',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'top' }
                        }
                    }
                });
            }
            <?php endif; ?>

            // Category Comparison Chart
            <?php if ($category_comparison && $category_comparison->num_rows > 0): ?>
            const categoryCtx = document.getElementById('categoryComparisonChart')?.getContext('2d');
            if (categoryCtx) {
                <?php
                $category_comparison->data_seek(0);
                $cat_labels = [];
                $cat_current = [];
                $cat_last = [];
                while($cat = $category_comparison->fetch_assoc()) {
                    $cat_labels[] = $cat['category'];
                    $cat_current[] = floatval($cat['current_amount']);
                    $cat_last[] = floatval($cat['last_amount']);
                }
                ?>
                
                new Chart(categoryCtx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($cat_labels); ?>,
                        datasets: [{
                            label: 'Current Month',
                            data: <?php echo json_encode($cat_current); ?>,
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            tension: 0.4
                        }, {
                            label: 'Last Month',
                            data: <?php echo json_encode($cat_last); ?>,
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245, 158, 11, 0.1)',
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'top' }
                        }
                    }
                });
            }
            <?php endif; ?>

            // Goals Chart
            <?php if ($goals && $goals->num_rows > 0): ?>
            const goalsCtx = document.getElementById('goalsChart')?.getContext('2d');
            if (goalsCtx) {
                <?php
                $goals->data_seek(0);
                $goal_labels = [];
                $goal_current = [];
                $goal_target = [];
                while($goal = $goals->fetch_assoc()) {
                    $goal_labels[] = $goal['goal_name'];
                    $goal_current[] = floatval($goal['current_amount']);
                }
                ?>
                
                new Chart(goalsCtx, {
                    type: 'doughnut',
                    data: {
                        labels: <?php echo json_encode($goal_labels); ?>,
                        datasets: [{
                            data: <?php echo json_encode($goal_current); ?>,
                            backgroundColor: [
                                '#667eea',
                                '#f59e0b',
                                '#10b981',
                                '#ef4444',
                                '#8b5cf6'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'bottom' }
                        }
                    }
                });
            }
            <?php endif; ?>
        });

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Report generation
        function generateReport(type) {
            let url = 'generate_report.php?type=' + type;
            window.open(url, '_blank');
        }

        // Real-time budget check
        function checkBudgetAlert(category, spent, budget, threshold) {
            const percentage = (spent / budget) * 100;
            if (percentage >= threshold) {
                alert(`⚠️ Alert: You've used ${percentage.toFixed(1)}% of your ${category} budget!`);
            }
        }
    </script>
</body>
</html>

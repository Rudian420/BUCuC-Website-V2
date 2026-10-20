<?php
require_once '../backend/includes/auth.php';

// Super Admin and Panel Admin (manage_site)
require_admin(['can' => 'manage_site']);

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signup_status'])) {
    try {
        require_once '../backend/Database/db.php';
        $database = new Database();
        $conn = $database->createConnection();

        // Create table if it doesn't exist
        $createTableQuery = "
            CREATE TABLE IF NOT EXISTS signup_status (
                id INT PRIMARY KEY DEFAULT 1,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                updated_by VARCHAR(100) DEFAULT NULL
            )
        ";
        $conn->exec($createTableQuery);

        // Insert default record if table is empty
        $checkQuery = "SELECT COUNT(*) FROM signup_status";
        $stmt = $conn->prepare($checkQuery);
        $stmt->execute();
        $count = $stmt->fetchColumn();

        if ($count == 0) {
            $insertQuery = "INSERT INTO signup_status (id, is_enabled, updated_by) VALUES (1, 1, 'system')";
            $stmt = $conn->prepare($insertQuery);
            $stmt->execute();
        }

        // Update signup status
        $newStatus = ($_POST['signup_status'] === 'enabled') ? 1 : 0;
        $adminName = $_SESSION['admin_name'] ?? 'admin';

        $updateQuery = "UPDATE signup_status SET is_enabled = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1";
        $stmt = $conn->prepare($updateQuery);
        $stmt->execute([$newStatus, $adminName]);

        $statusText = $newStatus ? 'enabled' : 'disabled';
        $message = "Signup status successfully updated to: " . strtoupper($statusText);
        log_activity('signup_toggled', 'Applications ' . strtoupper($statusText));
        $messageType = 'success';

    } catch (Exception $e) {
        $message = "Error updating signup status: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Get current signup status
$currentStatus = 1; // Default to enabled
try {
    require_once '../backend/Database/db.php';
    $database = new Database();
    $conn = $database->createConnection();

    $sql = "SELECT is_enabled FROM signup_status WHERE id = 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        $currentStatus = (int) $result['is_enabled'];
    }
} catch (Exception $e) {
    // Keep default status if there's an error
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Signup Control | BRAC University Cultural Club</title>

    <!-- Bootstrap CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/bootstrap-icons.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="images/logopng.png">

    <style>
        body {
            background: linear-gradient(135deg, rgb(var(--c-paperAccent-667eea, 102 126 234)) 0%, rgb(var(--c-paperAccent-764ba2, 118 75 162)) 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .admin-container {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.95);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgb(var(--c-fixed-000000, 0 0 0) / 0.1);
            padding: 40px;
            margin-top: 50px;
            margin-bottom: 50px;
        }

        .admin-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .admin-header h1 {
            color: rgb(var(--c-fixed-333333, 51 51 51));
            font-weight: 700;
            margin-bottom: 10px;
        }

        .admin-header p {
            color: rgb(var(--c-fixed-666666, 102 102 102));
            font-size: 1.1rem;
        }

        .status-card {
            background: rgb(var(--c-fixed-f8f9fa, 248 249 250));
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            border-left: 5px solid rgb(var(--c-accent-007bff, 0 123 255));
        }

        .status-enabled {
            border-left-color: rgb(var(--c-accent2-28a745, 40 167 69));
            background: rgb(var(--c-accent2-28a745, 40 167 69) / 0.1);
        }

        .status-disabled {
            border-left-color: rgb(var(--c-danger-dc3545, 220 53 69));
            background: rgb(var(--c-danger-dc3545, 220 53 69) / 0.1);
        }

        .form-label {
            font-weight: 600;
            color: rgb(var(--c-fixed-333333, 51 51 51));
            margin-bottom: 10px;
        }

        .form-select {
            border-radius: 10px;
            border: 2px solid rgb(var(--c-fixed-e9ecef, 233 236 239));
            padding: 12px 15px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-select:focus {
            border-color: rgb(var(--c-accent-007bff, 0 123 255));
            box-shadow: 0 0 0 0.2rem rgb(var(--c-accent-007bff, 0 123 255) / 0.25);
        }

        .btn-primary {
            background: linear-gradient(135deg, rgb(var(--c-accent-007bff, 0 123 255)), rgb(var(--c-accent-0056b3, 0 86 179)));
            border: none;
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgb(var(--c-accent-007bff, 0 123 255) / 0.4);
        }

        .btn-secondary {
            background: rgb(var(--c-accent-6c757d, 108 117 125));
            border: none;
            border-radius: 10px;
            padding: 10px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background: rgb(var(--c-accent-545b62, 84 91 98));
            transform: translateY(-1px);
        }

        .alert {
            border-radius: 10px;
            border: none;
            padding: 15px 20px;
            font-weight: 500;
        }

        .current-status-badge {
            font-size: 1.1rem;
            padding: 8px 20px;
            border-radius: 25px;
            font-weight: 600;
        }

        .status-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }

        .navigation-buttons {
            margin-top: 30px;
            text-align: center;
        }
    </style>
    <link rel="stylesheet" href="css/aurora.css">
</head>

<body class="au au-admin">
    <script src="js/aurora-sky.js"></script>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8 col-md-10">
                <div class="admin-container">
                    <div class="admin-header">
                        <h1><i class="bi bi-gear"></i> Signup Control Panel</h1>
                        <p>Manage signup form availability for BRAC University Cultural Club</p>
                    </div>

                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?>"
                            role="alert">
                            <i
                                class="bi bi-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                            <?php echo htmlspecialchars($message); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Current Status Display -->
                    <div class="status-card <?php echo $currentStatus ? 'status-enabled' : 'status-disabled'; ?>">
                        <div class="text-center">
                            <div class="status-icon">
                                <?php if ($currentStatus): ?>
                                    <i class="bi bi-check-circle text-success"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle text-danger"></i>
                                <?php endif; ?>
                            </div>
                            <h4>Current Status</h4>
                            <span
                                class="badge <?php echo $currentStatus ? 'bg-success' : 'bg-danger'; ?> current-status-badge">
                                Signup Form is <?php echo $currentStatus ? 'ENABLED' : 'DISABLED'; ?>
                            </span>
                            <p class="mt-3 mb-0">
                                <?php if ($currentStatus): ?>
                                    Students can currently sign up for the cultural club through the website.
                                <?php else: ?>
                                    The signup form is currently disabled. Students will see a disabled message.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <!-- Control Form -->
                    <form method="POST" action="">
                        <div class="mb-4">
                            <label for="signup_status" class="form-label">
                                <i class="bi bi-toggles"></i> Change Signup Status:
                            </label>
                            <select name="signup_status" id="signup_status" class="form-select" required>
                                <option value="">-- Select Status --</option>
                                <option value="enabled" <?php echo $currentStatus ? 'selected' : ''; ?>>
                                    Enable Signup Form
                                </option>
                                <option value="disabled" <?php echo !$currentStatus ? 'selected' : ''; ?>>
                                    Disable Signup Form
                                </option>
                            </select>
                        </div>

                        <div class="text-center">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg"></i> Update Status
                            </button>
                        </div>
                    </form>

                    <!-- Navigation Buttons -->
                    <div class="navigation-buttons">
                        <?php
                        $dashboardLink = auth_dashboard_page();
                        ?>
                        <a href="<?php echo $dashboardLink; ?>" class="btn btn-secondary me-2">
                            <i class="bi bi-arrow-left"></i> Back to Dashboard
                        </a>
                        <a href="index.php" class="btn btn-secondary" target="_blank">
                            <i class="bi bi-eye"></i> View Website
                        </a>
                    </div>

                    <!-- Status Information -->
                    <div class="mt-4 p-3" style="background: rgb(var(--c-fixed-f8f9fa, 248 249 250)); border-radius: 10px;">
                        <h6><i class="bi bi-info-circle"></i> Information:</h6>
                        <ul class="mb-0" style="padding-left: 20px;">
                            <li><strong>Enabled:</strong> Students can fill and submit the signup form</li>
                            <li><strong>Disabled:</strong> Students will see a "Sign Up Currently Disabled" message</li>
                            <li>Changes take effect immediately on the website</li>
                            <li>All form submissions are logged with timestamp and admin details</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="js/bootstrap.min.js"></script>
</body>

</html>
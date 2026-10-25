<?php
require_once '../backend/includes/auth.php';
require_admin(['can' => 'view_members']);

$dashboardLink = auth_dashboard_page();

require_once '../backend/Database/db.php';

// Handle success and error messages from URL parameters
$success_message = '';
$warning_message = '';
$error_message   = '';

if (isset($_GET['success'])) {
    $success_message = urldecode($_GET['success']);
}
if (isset($_GET['warning'])) {
    $warning_message = urldecode($_GET['warning']);
}
if (isset($_GET['error'])) {
    $error_message = urldecode($_GET['error']);
}

// Fetch members data
try {
    $database = new Database();
    $pdo = $database->createConnection();

    // Get all approved members from dedicated table
    $stmt = $pdo->query("SELECT * FROM members ORDER BY created_at DESC");
    $approvedMembers = $stmt->fetchAll();

    $totalApproved = count($approvedMembers);

} catch (Exception $e) {
    $approvedMembers = [];
    $totalApproved = 0;
    $error_message = friendly_error($e, 'Loading the list');
}

// JS-string-safe text for onsubmit="return confirm(...)" attributes
function confirmText($text)
{
    return htmlspecialchars(json_encode($text), ENT_QUOTES);
}

// Deleting approved members: Super Admin and Panel Admin
$isSuperAdmin = can('manage_site');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approved Members | BUCuC Admin</title>

    <!-- Bootstrap CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/bootstrap-icons.css" rel="stylesheet">
    <link href="css/templatemo-festava-live.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="css/all.min.css" />

    <link rel="stylesheet" href="AdminCss/Dashboard.css">
    <link rel="icon" type="image/png" sizes="32x32" href="images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="images/favicon-16.png">
    <link rel="apple-touch-icon" href="images/apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">

    <style>
        .applications-container {
            min-height: 100vh;
            background: linear-gradient(135deg, rgb(var(--c-paperText-1a1a2e, 26 26 46)) 0%, rgb(var(--c-paperText-16213e, 22 33 62)) 50%, rgb(var(--c-surface-0f3460, 15 52 96)) 100%);
            padding: 2rem 0;
        }

        .applications-card {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 20px 40px rgb(var(--c-fixed-000000, 0 0 0) / 0.3);
            border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            margin: 0 1rem;
        }

        .applications-title {
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgb(var(--c-fixed-000000, 0 0 0) / 0.5);
            text-align: center;
        }

        .applications-subtitle {
            color: rgb(var(--c-fixed-cccccc, 204 204 204));
            font-size: 1.1rem;
            margin-bottom: 2rem;
            opacity: 0.9;
            text-align: center;
        }

        .back-btn-delicate {
            display: inline-flex;
            align-items: center;
            color: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.8);
            text-decoration: none;
            padding: 0.6rem 1.2rem;
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.05);
            border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.3s ease;
            margin-bottom: 1.5rem;
        }

        .back-btn-delicate:hover {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            border-color: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.3);
            transform: translateX(-5px);
        }

        .applications-table {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.05);
            border-radius: 15px;
            overflow: hidden;
            border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
        }

        .table-dark {
            --bs-table-bg: transparent;
        }

        .table-dark thead th {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            border-color: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            font-weight: 600;
        }

        .table-dark td {
            border-color: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            color: rgb(var(--c-fixed-cccccc, 204 204 204));
            vertical-align: middle;
        }

        .table-dark tbody tr:hover {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
        }

        .truncate {
            max-width: 150px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .btn-delete {
            background: linear-gradient(45deg, rgb(var(--c-danger-dc3545, 220 53 69)), rgb(var(--c-danger-e74c3c, 231 76 60)));
            border: none;
            border-radius: 25px;
            padding: 0.5rem 1rem;
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgb(var(--c-danger-dc3545, 220 53 69) / 0.3);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 500;
            white-space: nowrap;
        }

        .status-accepted {
            background: linear-gradient(45deg, rgb(var(--c-accent2-28a745, 40 167 69)), rgb(var(--c-accent-20c997, 32 201 151)));
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
        }

        @media (max-width: 768px) {
            .applications-card {
                margin: 0 0.5rem;
                padding: 1.5rem;
            }

            .applications-title {
                font-size: 2rem;
            }

            .table-responsive {
                font-size: 0.85rem;
            }

            .btn-delete {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }
        }
    </style>
    <link rel="stylesheet" href="css/aurora.css">
</head>

<body class="au au-admin">
    <script src="js/aurora-sky.js"></script>
    <!-- Applications Container -->
    <div class="applications-container">
        <div class="applications-card">
            <a href="<?php echo $dashboardLink; ?>" class="back-btn-delicate">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
            <h1 class="applications-title">
                <i class="fas fa-user-check mb-3"></i><br>
                Approved Members
            </h1>
            <p class="applications-subtitle">
                Finally selected and registered members of BUCuC (<?php echo $totalApproved; ?> members)
            </p>

            <!-- Success/Warning/Error Messages -->
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert"
                    style="background: rgb(var(--c-accent2-28a745, 40 167 69) / 0.2); border: 1px solid rgb(var(--c-accent2-28a745, 40 167 69) / 0.3); color: rgb(var(--c-accent2-51cf66, 81 207 102));">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"
                        style="filter: brightness(0) invert(1);"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($warning_message)): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert"
                    style="background: rgb(var(--c-highlight-ffc107, 255 193 7) / 0.15); border: 1px solid rgb(var(--c-highlight-ffc107, 255 193 7) / 0.4); color: rgb(var(--c-highlight-ffc107, 255 193 7));">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($warning_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"
                        style="filter: brightness(0) invert(1);"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert"
                    style="background: rgb(var(--c-danger-dc3545, 220 53 69) / 0.2); border: 1px solid rgb(var(--c-danger-dc3545, 220 53 69) / 0.3); color: rgb(var(--c-danger-ff6b6b, 255 107 107));">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"
                        style="filter: brightness(0) invert(1);"></button>
                </div>
            <?php endif; ?>

            <!-- Action Section -->
            <div class="action-section text-center mb-4"
                style="background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.05); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1); border-radius: 15px; padding: 1.5rem;">
                <div class="d-flex justify-content-center gap-3 flex-wrap">
                    <a href="../backend/Action/export_members_excel.php?status=Accepted" class="btn btn-export-excel"
                        style="background: linear-gradient(45deg, rgb(var(--c-accent2-28a745, 40 167 69)), rgb(var(--c-accent-20c997, 32 201 151))); border: none; border-radius: 30px; padding: 1rem 2rem; color: rgb(var(--c-fixed-ffffff, 255 255 255)); font-weight: 600; text-decoration: none; transition: all 0.3s ease;">
                        <i class="fas fa-file-excel me-2"></i>Download Member Information (Excel)
                    </a>
                </div>
            </div>

            <!-- Members Table -->
            <div class="applications-table">
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0" id="membersTable">
                        <thead>
                            <tr>
                                <th>Full Name</th>
                                <th>University ID</th>
                                <th>Email</th>
                                <th>Department</th>
                                <th>Phone</th>
                                <th>Priorities</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="membersTableBody">
                            <?php if (!empty($approvedMembers)): ?>
                                <?php foreach ($approvedMembers as $row): ?>
                                    <tr>
                                        <td class="truncate"><?php echo htmlspecialchars($row['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['university_id']); ?></td>
                                        <td><?php echo htmlspecialchars($row['gsuite_email'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($row['department']); ?></td>
                                        <td><?php echo htmlspecialchars($row['phone']); ?></td>
                                        <td><?php echo htmlspecialchars($row['firstPriority'] . ', ' . $row['secondPriority']); ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-accepted">
                                                <i class="fas fa-check-circle me-1"></i>Approved Member
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($isSuperAdmin): ?>
                                                <form method="POST" action="handle_application.php" style="display:inline;"
                                                    onsubmit="return confirm(<?php echo confirmText("Permanently delete {$row['full_name']} from the approved members list?\n\nThis cannot be undone."); ?>)">
                                                    <input type="hidden" name="action" value="delete_approved">
                                                    <input type="hidden" name="member_id" value="<?php echo $row['id']; ?>">
                                                    <input type="hidden" name="redirect_to" value="approved_members.php">
                                                    <button type="submit" class="btn btn-delete btn-sm">
                                                        <i class="fas fa-trash-alt me-1"></i>Delete
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted"><i class="fas fa-lock me-1"></i>Panel Admin only</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        <i class="fas fa-users-slash fa-3x mb-3"></i><br>
                                        No approved members found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="js/bootstrap.min.js"></script>

</body>

</html>
<?php
require_once '../backend/includes/auth.php';
require_admin(['can' => 'shortlist']);

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

    // Get all pending applications
    $stmt = $pdo->query("SELECT * FROM pending_applications ORDER BY created_at DESC");
    $members = $stmt->fetchAll();

} catch (Exception $e) {
    $members = [];
    $error_message = friendly_error($e, 'Loading the list');
}

// JS-string-safe text for onsubmit="return confirm(...)" attributes
function confirmText($text)
{
    return htmlspecialchars(json_encode($text), ENT_QUOTES);
}

// Clear All: Super Admin and Panel Admin; Set Venue: whoever can give final approval
$isSuperAdmin = can('manage_site');
$canVenue = can('final_approve');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Applications | BUCuC Admin</title>

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

        .btn-accept {
            background: linear-gradient(45deg, rgb(var(--c-accent2-28a745, 40 167 69)), rgb(var(--c-accent-20c997, 32 201 151)));
            border: none;
            border-radius: 25px;
            padding: 0.5rem 1rem;
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-accept:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgb(var(--c-accent2-28a745, 40 167 69) / 0.3);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
        }

        .btn-reject {
            background: linear-gradient(45deg, rgb(var(--c-danger-dc3545, 220 53 69)), rgb(var(--c-danger-e74c3c, 231 76 60)));
            border: none;
            border-radius: 25px;
            padding: 0.5rem 1rem;
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-reject:hover {
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

        .status-pending {
            background: linear-gradient(45deg, rgb(var(--c-highlight-ffc107, 255 193 7)), rgb(var(--c-highlight-ffeb3b, 255 235 59)));
            color: rgb(var(--c-fixed-333333, 51 51 51));
        }

        .status-accepted {
            background: linear-gradient(45deg, rgb(var(--c-accent2-28a745, 40 167 69)), rgb(var(--c-accent-20c997, 32 201 151)));
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
        }

        .search-box {
            position: relative;
            max-width: 300px;
        }

        .search-box input {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            border-radius: 25px;
            padding: 0.8rem 1rem 0.8rem 3rem;
        }

        .search-box input::placeholder {
            color: rgb(var(--c-fixed-cccccc, 204 204 204));
        }

        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: rgb(var(--c-fixed-cccccc, 204 204 204));
        }

        .stats-row {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.05);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: rgb(var(--c-accent2-51cf66, 81 207 102));
        }

        .stat-label {
            color: rgb(var(--c-fixed-cccccc, 204 204 204));
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }

        /* Clear All Applications Button Styling */
        .btn-clear-all {
            background: linear-gradient(45deg, rgb(var(--c-danger-dc3545, 220 53 69)), rgb(var(--c-danger-b52d3c, 181 45 60)));
            border: none;
            border-radius: 30px;
            padding: 1rem 2rem;
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgb(var(--c-danger-dc3545, 220 53 69) / 0.3);
            position: relative;
            overflow: hidden;
        }

        .btn-clear-all:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgb(var(--c-danger-dc3545, 220 53 69) / 0.4);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            background: linear-gradient(45deg, rgb(var(--c-danger-c82333, 200 35 51)), rgb(var(--c-danger-a02834, 160 40 52)));
        }

        .btn-clear-all:active {
            transform: translateY(-1px);
        }

        .btn-clear-all::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2), transparent);
            transition: left 0.5s;
        }

        .btn-clear-all:hover::before {
            left: 100%;
        }

        /* Set Venue Button Styling */
        .btn-set-venue {
            background: linear-gradient(45deg, rgb(var(--c-accent2-28a745, 40 167 69)), rgb(var(--c-accent-20c997, 32 201 151)));
            border: none;
            border-radius: 30px;
            padding: 1rem 2rem;
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgb(var(--c-accent2-28a745, 40 167 69) / 0.3);
            position: relative;
            overflow: hidden;
            text-decoration: none;
        }

        .btn-set-venue:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgb(var(--c-accent2-28a745, 40 167 69) / 0.4);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            background: linear-gradient(45deg, rgb(var(--c-accent2-218838, 33 136 56)), rgb(var(--c-accent2-1e7e34, 30 126 52)));
            text-decoration: none;
        }

        .btn-set-venue:active {
            transform: translateY(-1px);
        }

        .btn-set-venue::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2), transparent);
            transition: left 0.5s;
        }

        .btn-set-venue:hover::before {
            left: 100%;
        }

        /* Export Excel Button Styling */
        .btn-export-excel {
            background: linear-gradient(45deg, rgb(var(--c-accent2-28a745, 40 167 69)), rgb(var(--c-accent-20c997, 32 201 151)));
            border: none;
            border-radius: 30px;
            padding: 1rem 2rem;
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgb(var(--c-accent2-28a745, 40 167 69) / 0.3);
            position: relative;
            overflow: hidden;
            text-decoration: none;
        }

        .btn-export-excel:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgb(var(--c-accent2-28a745, 40 167 69) / 0.4);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            background: linear-gradient(45deg, rgb(var(--c-accent2-218838, 33 136 56)), rgb(var(--c-accent2-1e7e34, 30 126 52)));
            text-decoration: none;
        }

        .btn-export-excel:active {
            transform: translateY(-1px);
        }

        .btn-export-excel::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2), transparent);
            transition: left 0.5s;
        }

        .btn-export-excel:hover::before {
            left: 100%;
        }

        .clear-all-warning {
            color: rgb(var(--c-highlight-ffc107, 255 193 7));
            font-size: 0.9rem;
            margin-top: 0.5rem;
            opacity: 0.9;
        }

        .clear-all-section {
            background: rgb(var(--c-danger-dc3545, 220 53 69) / 0.1);
            border: 2px dashed rgb(var(--c-danger-dc3545, 220 53 69) / 0.3);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        /* Modal Styling */
        .modal-content {
            background: linear-gradient(135deg, rgb(var(--c-paperText-1a1a2e, 26 26 46)) 0%, rgb(var(--c-paperText-16213e, 22 33 62)) 50%, rgb(var(--c-surface-0f3460, 15 52 96)) 100%);
            border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            border-radius: 15px;
        }

        .modal-header {
            border-bottom: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
        }

        .modal-body {
            color: rgb(var(--c-fixed-cccccc, 204 204 204));
        }

        .modal-footer {
            border-top: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
        }

        .form-control {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
        }

        .form-control:focus {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.15);
            border-color: rgb(var(--c-danger-dc3545, 220 53 69));
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            box-shadow: 0 0 0 0.2rem rgb(var(--c-danger-dc3545, 220 53 69) / 0.25);
        }

        .form-control::placeholder {
            color: rgb(var(--c-fixed-999999, 153 153 153));
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

            .btn-accept,
            .btn-reject {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }

            .btn-clear-all {
                padding: 0.8rem 1.5rem;
                font-size: 1rem;
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
            <i class="fas fa-user-plus mb-3"></i><br>
            Pending Applications
        </h1>
        <p class="applications-subtitle">
            Review and manage member applications (<?php echo count($members); ?> pending)
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

        <!-- Statistics Row -->


        <!-- Clear All Applications and Set Venue Section -->
        <div class="clear-all-section text-center mb-4">
            <div class="d-flex justify-content-center gap-3 flex-wrap">
                <?php if ($isSuperAdmin): ?>
                    <button class="btn btn-clear-all" id="clearAllBtn" onclick="showClearAllModal()">
                        <i class="fas fa-trash-alt me-2"></i>Clear
                    </button>
                <?php endif; ?>
                <?php if ($canVenue): ?>
                    <a href="set_venue.php" class="btn btn-set-venue">
                        <i class="fas fa-map-marker-alt me-2"></i>Set Venue
                    </a>
                <?php endif; ?>
                <a href="../backend/Action/export_members_excel.php?status=Pending" class="btn btn-export-excel">
                    <i class="fas fa-file-excel me-2"></i>Export Excel
                </a>
            </div>
            <?php if ($isSuperAdmin): ?>
                <p class="clear-all-warning mt-2">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    <strong>Warning:</strong> Clear permanently deletes ALL pending, shortlisted AND approved records (an archive copy is kept in the server log).
                </p>
            <?php endif; ?>
        </div>

        <!-- Applications Table -->
        <div class="applications-table">
            <div class="table-responsive">
                <table class="table table-dark table-hover mb-0" id="applicationsTable">
                    <thead>
                        <tr>
                            <th>Full Name</th>
                            <th>University ID</th>
                            <th>Email</th>
                            <th>G-Suite Email</th>
                            <th>Department</th>
                            <th>Phone</th>
                            <th>Semester</th>
                            <th>Gender</th>
                            <th>Facebook</th>
                            <th>Priorities</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="applicationsTableBody">
                        <?php if (!empty($members)): ?>
                            <?php foreach ($members as $member): ?>
                                <tr data-status="pending">
                                    <td class="truncate"><?php echo htmlspecialchars($member['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($member['university_id']); ?></td>
                                    <td><?php echo htmlspecialchars($member['email']); ?></td>
                                    <td><?php echo htmlspecialchars($member['gsuite_email']); ?></td>
                                    <td><?php echo htmlspecialchars($member['department']); ?></td>
                                    <td><?php echo htmlspecialchars($member['phone']); ?></td>
                                    <td><?php echo htmlspecialchars($member['semester']); ?></td>
                                    <td><?php echo htmlspecialchars($member['gender']); ?></td>
                                    <td><a href="<?php echo htmlspecialchars($member['facebook_url']); ?>"
                                            target="_blank">Facebook</a></td>
                                    <td><?php echo htmlspecialchars($member['firstPriority'] . ', ' . $member['secondPriority']); ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-pending">
                                            <i class="fas fa-clock me-1"></i>Pending
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <form method="POST" action="handle_application.php" style="display:inline;"
                                                onsubmit="return confirm(<?php echo confirmText("Shortlist {$member['full_name']}?\n\nThis moves them to the Shortlisted Members list. No email is sent yet."); ?>)">
                                                <input type="hidden" name="action" value="shortlist">
                                                <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                <button type="submit" class="btn btn-primary btn-sm"
                                                    style="border-radius: 25px; background: linear-gradient(45deg, rgb(var(--c-accent-0d6efd, 13 110 253)), rgb(var(--c-accent-0a58ca, 10 88 202))); border: none;">
                                                    <i class="fas fa-list-ul me-1"></i>Shortlist
                                                </button>
                                            </form>
                                            <form method="POST" action="handle_application.php" style="display:inline;"
                                                onsubmit="return confirm(<?php echo confirmText("Reject {$member['full_name']}?\n\nThis permanently deletes their application."); ?>)">
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="source" value="pending">
                                                <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm"
                                                    style="border-radius: 25px; background: linear-gradient(45deg, rgb(var(--c-danger-dc3545, 220 53 69)), rgb(var(--c-danger-e74c3c, 231 76 60))); border: none;">
                                                    <i class="fas fa-times me-1"></i>Reject
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-3x mb-3"></i><br>
                                    No pending applications.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Clear All Applications Modal -->
<div class="modal fade" id="clearAllModal" tabindex="-1" aria-labelledby="clearAllModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-bottom">
                <h5 class="modal-title" id="clearAllModalLabel">
                    <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                    Confirm Clear All Applications
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger d-flex align-items-center mb-3" role="alert">
                    <i class="fas fa-skull-crossbones fa-2x me-3"></i>
                    <div>
                        <strong>DANGER ZONE!</strong><br>
                        This action will permanently delete ALL applications from the database.
                    </div>
                </div>

                <p><strong>What will be deleted:</strong></p>
                <ul class="text-warning">
                    <li>All pending applications</li>
                    <li>All shortlisted members</li>
                    <li>All approved members</li>
                </ul>

                <p><strong class="text-danger">This cannot be undone from the website.</strong> A JSON copy of every
                    deleted row is written to <code>backend/logs/cleared_applications.log</code>.</p>

                <p>To confirm this destructive action, please type exactly:<br>
                    <code class="text-warning">CLEAR ALL APPLICATIONS</code>
                </p>

                <input type="text" class="form-control mt-2" id="confirmationInput"
                    placeholder="Type: CLEAR ALL APPLICATIONS">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-danger" id="confirmClearAllBtn" disabled>
                    <i class="fas fa-trash-alt me-1"></i>Clear All Applications
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="js/bootstrap.min.js"></script>
    <script src="js/admin-toast.js"></script>

<script>

    // Clear All (the button only exists for admins allowed to use it)
    const CONFIRM_PHRASE = 'CLEAR ALL APPLICATIONS';
    const confirmInput = document.getElementById('confirmationInput');
    const confirmBtn = document.getElementById('confirmClearAllBtn');

    function showClearAllModal() {
        new bootstrap.Modal(document.getElementById('clearAllModal')).show();
        confirmInput.value = '';
        setConfirmState(false);
    }

    function setConfirmState(ready) {
        confirmBtn.disabled = !ready;
        confirmBtn.classList.toggle('btn-warning', ready);
        confirmBtn.classList.toggle('btn-danger', !ready);
        confirmBtn.innerHTML = ready
            ? '<i class="fas fa-check me-1"></i>Confirmed - Clear All Applications'
            : '<i class="fas fa-trash-alt me-1"></i>Clear All Applications';
    }

    confirmInput.addEventListener('input', e => setConfirmState(e.target.value.trim() === CONFIRM_PHRASE));

    confirmBtn.addEventListener('click', function () {
        const confirmationText = confirmInput.value.trim();
        if (confirmationText !== CONFIRM_PHRASE) {
            showNotification(`Please type "${CONFIRM_PHRASE}" exactly to confirm.`, 'error');
            return;
        }

        const originalContent = confirmBtn.innerHTML;
        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Clearing All Applications...';
        confirmBtn.disabled = true;

        fetch('../backend/Action/clear_all_applications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ action: 'clear_all_applications', confirmation: confirmationText })
        })
            .then(response => response.text())
            .then(text => {
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseError) {
                    throw new Error('Server returned invalid response: ' + text.substring(0, 100));
                }

                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('clearAllModal')).hide();
                    showNotification(data.message, 'success');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showNotification(data.message, 'error');
                }
                confirmBtn.innerHTML = originalContent;
                confirmBtn.disabled = false;
            })
            .catch(error => {
                showNotification('Error: ' + error.message, 'error');
                confirmBtn.innerHTML = originalContent;
                confirmBtn.disabled = false;
            });
    });
</script>

</body>

</html>
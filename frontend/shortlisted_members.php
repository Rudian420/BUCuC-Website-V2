<?php
require_once '../backend/includes/auth.php';
require_admin(['can' => 'shortlist']);

$dashboardLink = auth_dashboard_page();

// Everyone who shortlists can view this page. Final Approve / Reject and Set Venue need the
// final_approve permission; Clear List needs manage_site (UI here + checks in the handlers).
$canApprove = can('final_approve');
$canClear = can('manage_site');

require_once '../backend/Database/db.php';

// Handle success, warning and error messages from URL parameters
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

    // Get all shortlisted members from dedicated table
    $stmt = $pdo->query("SELECT * FROM shortlisted_members ORDER BY updated_at DESC");
    $shortlistedMembers = $stmt->fetchAll();

    $totalShortlisted = count($shortlistedMembers);

} catch (Exception $e) {
    $shortlistedMembers = [];
    $totalShortlisted = 0;
    $error_message = friendly_error($e, 'Loading the list');
}

// JS-string-safe text for onsubmit="return confirm(...)" attributes
function confirmText($text)
{
    return htmlspecialchars(json_encode($text), ENT_QUOTES);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shortlisted Members | BUCuC Admin</title>

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

        .status-shortlisted {
            background: linear-gradient(45deg, rgb(var(--c-paperAccent-6f42c1, 111 66 193)), rgb(var(--c-muted-a885d8, 168 133 216)));
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

            .btn-accept,
            .btn-reject {
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
                <i class="fas fa-check-double mb-3"></i><br>
                Shortlisted Members
            </h1>
            <p class="applications-subtitle">
                Final approval for shortlisted applicants (<?php echo $totalShortlisted; ?> shortlisted)
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

            <!-- Clear All Applications and Set Venue Section -->
            <div class="clear-all-section text-center mb-4"
                style="background: rgb(var(--c-danger-dc3545, 220 53 69) / 0.1); border: 2px dashed rgb(var(--c-danger-dc3545, 220 53 69) / 0.3); border-radius: 15px; padding: 1.5rem;">
                <div class="d-flex justify-content-center gap-3 flex-wrap">
                    <?php if ($canClear): ?>
                        <button class="btn btn-clear-all" id="clearAllBtn" onclick="showClearAllModal()"
                            style="background: linear-gradient(45deg, rgb(var(--c-danger-dc3545, 220 53 69)), rgb(var(--c-danger-b52d3c, 181 45 60))); border: none; border-radius: 30px; padding: 1rem 2rem; color: rgb(var(--c-fixed-ffffff, 255 255 255)); font-weight: 600; transition: all 0.3s ease;">
                            <i class="fas fa-trash-alt me-2"></i>Clear List
                        </button>
                    <?php endif; ?>
                    <?php if ($canApprove): ?>
                        <a href="set_venue.php" class="btn btn-set-venue"
                            style="background: linear-gradient(45deg, rgb(var(--c-accent2-28a745, 40 167 69)), rgb(var(--c-accent-20c997, 32 201 151))); border: none; border-radius: 30px; padding: 1rem 2rem; color: rgb(var(--c-fixed-ffffff, 255 255 255)); font-weight: 600; text-decoration: none; transition: all 0.3s ease;">
                            <i class="fas fa-map-marker-alt me-2"></i>Set Venue
                        </a>
                    <?php endif; ?>
                    <a href="../backend/Action/export_members_excel.php?status=Shortlisted" class="btn btn-export-excel"
                        style="background: linear-gradient(45deg, rgb(var(--c-accent2-28a745, 40 167 69)), rgb(var(--c-accent-20c997, 32 201 151))); border: none; border-radius: 30px; padding: 1rem 2rem; color: rgb(var(--c-fixed-ffffff, 255 255 255)); font-weight: 600; text-decoration: none; transition: all 0.3s ease;">
                        <i class="fas fa-file-excel me-2"></i>Export Excel
                    </a>
                </div>
                <?php if ($canClear): ?>
                    <p class="clear-all-warning mt-2" style="color: rgb(var(--c-highlight-ffc107, 255 193 7)); font-size: 0.9rem; margin-top: 0.5rem;">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <strong>Warning:</strong> Clear will permanently delete ALL SHORTLISTED applications!
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
                                <th>Department</th>
                                <th>Phone</th>
                                <th>Facebook</th>
                                <th>Priorities</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="applicationsTableBody">
                            <?php if (!empty($shortlistedMembers)): ?>
                                <?php foreach ($shortlistedMembers as $row):
                                    // Use $row['id'] for simplicity
                                    ?>
                                    <tr>
                                        <td class="truncate"><?php echo htmlspecialchars($row['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['university_id']); ?></td>
                                        <td><?php echo htmlspecialchars($row['gsuite_email']); ?></td>
                                        <td><?php echo htmlspecialchars($row['department']); ?></td>
                                        <td><?php echo htmlspecialchars($row['phone']); ?></td>
                                        <td><a href="<?php echo htmlspecialchars($row['facebook_url'] ?? ''); ?>"
                                                target="_blank">Facebook</a></td>
                                        <td><?php echo htmlspecialchars($row['firstPriority'] . ', ' . $row['secondPriority']); ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-shortlisted">
                                                <i class="fas fa-list-ul me-1"></i>Shortlisted
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($canApprove): ?>
                                                <div class="d-flex gap-2">
                                                    <form method="POST" action="handle_application.php" style="display:inline;"
                                                        onsubmit="return confirm(<?php echo confirmText("Give final approval to {$row['full_name']}?\n\nThis moves them to Approved Members, sends the congratulations email and adds them to the Google Sheet."); ?>)">
                                                        <input type="hidden" name="action" value="accept">
                                                        <input type="hidden" name="member_id" value="<?php echo $row['id']; ?>">
                                                        <input type="hidden" name="redirect_to" value="shortlisted_members.php">
                                                        <button type="submit" class="btn btn-accept btn-sm">
                                                            <i class="fas fa-check-double me-1"></i>Final Approve
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="handle_application.php" style="display:inline;"
                                                        onsubmit="return confirm(<?php echo confirmText("Reject {$row['full_name']}?\n\nThis permanently deletes their record."); ?>)">
                                                        <input type="hidden" name="action" value="reject">
                                                        <input type="hidden" name="source" value="shortlisted">
                                                        <input type="hidden" name="member_id" value="<?php echo $row['id']; ?>">
                                                        <input type="hidden" name="redirect_to" value="shortlisted_members.php">
                                                        <button type="submit" class="btn btn-reject btn-sm">
                                                            <i class="fas fa-times me-1"></i>Reject
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted"><i class="fas fa-lock me-1"></i>Needs final-approval permission</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        <i class="fas fa-clipboard-check fa-3x mb-3"></i><br>
                                        No shortlisted members found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Clear All Confirmation Modal -->
    <div class="modal fade" id="clearAllModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content"
                style="background: linear-gradient(135deg, rgb(var(--c-paperText-1a1a2e, 26 26 46)) 0%, rgb(var(--c-paperText-16213e, 22 33 62)) 50%, rgb(var(--c-surface-0f3460, 15 52 96)) 100%); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);">
                <div class="modal-header border-bottom-0">
                    <h5 class="modal-title text-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>Danger Zone
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="fas fa-trash-alt fa-3x text-danger mb-3"></i>
                    <h4 class="text-white mb-2">Clear Shortlisted Members?</h4>
                    <p class="text-secondary mb-4">
                        This action cannot be undone. All <strong>SHORTLISTED</strong> applications will be permanently
                        deleted from the database.
                    </p>

                    <div class="mb-3">
                        <label class="form-label text-light small">Type "CLEAR ALL SHORTLISTED" to confirm:</label>
                        <input type="text" class="form-control text-center bg-dark text-white border-secondary"
                            id="confirmationInput" placeholder="CLEAR ALL SHORTLISTED" autocomplete="off">
                    </div>
                </div>
                <div class="modal-footer border-top-0 justify-content-center pb-4">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger px-4" id="confirmClearAllBtn" disabled>
                        <i class="fas fa-trash-alt me-2"></i>Clear List
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification Toast -->
    <div id="notificationtoast" class="toast align-items-center text-white border-0 position-fixed bottom-0 end-0 p-3"
        role="alert" aria-live="assertive" aria-atomic="true" style="z-index: 1050; display: none;"></div>

    <!-- Bootstrap JS -->
    <script src="js/bootstrap.min.js"></script>
    <script src="js/admin-toast.js"></script>

    <script>
        // Show Clear All Modal
        function showClearAllModal() {
            const modal = new bootstrap.Modal(document.getElementById('clearAllModal'));
            modal.show();

            // Reset modal state
            document.getElementById('confirmationInput').value = '';
            document.getElementById('confirmClearAllBtn').disabled = true;
        }

        // Handle confirmation input
        document.getElementById('confirmationInput').addEventListener('input', function (e) {
            const confirmBtn = document.getElementById('confirmClearAllBtn');
            const inputValue = e.target.value.trim();

            if (inputValue === 'CLEAR ALL SHORTLISTED') {
                confirmBtn.disabled = false;
                confirmBtn.classList.remove('btn-danger');
                confirmBtn.classList.add('btn-warning');
                confirmBtn.innerHTML = '<i class="fas fa-check me-1"></i>Confirmed - Clear List';
            } else {
                confirmBtn.disabled = true;
                confirmBtn.classList.remove('btn-warning');
                confirmBtn.classList.add('btn-danger');
                confirmBtn.innerHTML = '<i class="fas fa-trash-alt me-1"></i>Clear List';
            }
        });

        // Handle Clear All Confirmation
        document.getElementById('confirmClearAllBtn').addEventListener('click', function () {
            const confirmationText = document.getElementById('confirmationInput').value.trim();

            if (confirmationText !== 'CLEAR ALL SHORTLISTED') {
                showNotification('Type CLEAR ALL SHORTLISTED exactly (capital letters) to confirm.', 'warning');
                return;
            }

            // Show loading state
            const button = this;
            const originalContent = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Clearing...';
            button.disabled = true;

            // Make AJAX request
            fetch('../backend/Action/clear_shortlisted_applications.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    action: 'clear_shortlisted_applications',
                    confirmation: confirmationText
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message, 'success');
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        showNotification(data.message || 'Could not clear the list. Reload the page and try again.', 'error');
                        button.innerHTML = originalContent;
                        button.disabled = false;
                    }
                })
                .catch(error => {
                    showNotification('Could not reach the server, so nothing was cleared. Check your connection and try again.', 'error');
                    button.innerHTML = originalContent;
                    button.disabled = false;
                });
        });
    </script>

</body>

</html>
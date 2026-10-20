<?php
require_once '../backend/includes/auth.php';
require_admin(['can' => 'final_approve']);

require_once '../backend/Database/db.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $database = new Database();
        $pdo = $database->createConnection();

        // Get form data
        $venue_name = $_POST['venue_name'] ?? '';
        $venue_location = $_POST['venue_location'] ?? '';
        $venue_date = $_POST['venue_date'] ?? '';
        $venue_time = $_POST['venue_time'] ?? '';
        $venue_starting_time = $_POST['venue_starting_time'] ?? '';
        $venue_ending_time = $_POST['venue_ending_time'] ?? '';
        $venue_ampm = $_POST['venue_ampm'] ?? 'PM';

        // Combine date and time for datetime field
        $venue_datetime = $venue_date . ' ' . $venue_time;

        if (trim($venue_name) === '' || trim($venue_location) === '' || trim($venue_starting_time) === ''
            || trim($venue_ending_time) === '' || !in_array($venue_ampm, ['AM', 'PM'], true)
            || strtotime($venue_datetime) === false) {
            throw new Exception('Please fill in every field with a valid value.');
        }

        // Check if any venue record exists
        $checkStmt = $pdo->query("SELECT COUNT(*) as count FROM venuinfo");
        $venueExists = $checkStmt->fetch()['count'] > 0;

        if ($venueExists) {
            // Update the existing venue record (update the latest one)
            $stmt = $pdo->prepare("UPDATE venuinfo SET venue_name = ?, venue_location = ?, venue_dateTime = ?, venue_startingTime = ?, venue_endingTime = ?, venu_ampm = ? WHERE venue_id = (SELECT venue_id FROM (SELECT venue_id FROM venuinfo ORDER BY venue_id DESC LIMIT 1) as temp)");
            $result = $stmt->execute([$venue_name, $venue_location, $venue_datetime, $venue_starting_time, $venue_ending_time, $venue_ampm]);

            if ($result) {
                $success_message = "Venue information updated successfully!";
                log_activity('venue_updated', "$venue_name, $venue_location, $venue_datetime");
            } else {
                $error_message = "Failed to update venue information.";
            }
        } else {
            // Insert new venue information (first time only)
            $stmt = $pdo->prepare("INSERT INTO venuinfo (venue_name, venue_location, venue_dateTime, venue_startingTime, venue_endingTime, venu_ampm) VALUES (?, ?, ?, ?, ?, ?)");
            $result = $stmt->execute([$venue_name, $venue_location, $venue_datetime, $venue_starting_time, $venue_ending_time, $venue_ampm]);

            if ($result) {
                $success_message = "Venue information created successfully!";
                log_activity('venue_created', "$venue_name, $venue_location, $venue_datetime");
            } else {
                $error_message = "Failed to create venue information.";
            }
        }

    } catch (PDOException $e) {
        error_log('set_venue: ' . $e->getMessage());
        $error_message = 'Saving failed because of a database problem. Please try again.';
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}
// After an error the form keeps what was typed
$vOld = fn($k, $d = '') => isset($error_message) ? (string) ($_POST[$k] ?? $d) : $d;

// Fetch existing venue information
try {
    $database = new Database();
    $pdo = $database->createConnection();

    $stmt = $pdo->query("SELECT * FROM venuinfo ORDER BY venue_id DESC");
    $venues = $stmt->fetchAll();

    // Get the latest venue for form pre-filling
    $latestVenue = !empty($venues) ? $venues[0] : null;

} catch (Exception $e) {
    $venues = [];
    $latestVenue = null;
    $fetch_error = "Error fetching venue data: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Venue - BRAC University Cultural Club</title>

    <!-- Bootstrap CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/bootstrap-icons.css" rel="stylesheet">
    <link href="css/templatemo-festava-live.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

    <link rel="stylesheet" href="AdminCss/Dashboard.css">
    <link rel="icon" type="image/png" href="images/logopng.png">
    <style>
        .venue-container {
            min-height: 100vh;
            background: linear-gradient(135deg, rgb(var(--c-paperText-1a1a2e, 26 26 46)) 0%, rgb(var(--c-paperText-16213e, 22 33 62)) 50%, rgb(var(--c-surface-0f3460, 15 52 96)) 100%);
            padding: 2rem 0;
        }

        .venue-card {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 20px 40px rgb(var(--c-fixed-000000, 0 0 0) / 0.3);
            border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            margin: 0 1rem;
        }

        .venue-title {
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgb(var(--c-fixed-000000, 0 0 0) / 0.5);
            text-align: center;
        }

        .venue-subtitle {
            color: rgb(var(--c-fixed-cccccc, 204 204 204));
            font-size: 1.1rem;
            margin-bottom: 2rem;
            opacity: 0.9;
            text-align: center;
        }

        .back-btn {
            position: absolute;
            top: 2rem;
            left: 2rem;
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .back-btn:hover {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            text-decoration: none;
            transform: translateX(-3px);
        }

        .venue-form {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.05);
            border-radius: 15px;
            padding: 2rem;
            border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            margin-bottom: 2rem;
        }

        .form-label {
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .form-control,
        .form-select {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1);
            border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            border-radius: 10px;
            padding: 0.8rem 1rem;
        }

        .form-control:focus,
        .form-select:focus {
            background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.15);
            border-color: rgb(var(--c-accent2-28a745, 40 167 69));
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            box-shadow: 0 0 0 0.2rem rgb(var(--c-accent2-28a745, 40 167 69) / 0.25);
        }

        .form-control::placeholder {
            color: rgb(var(--c-fixed-999999, 153 153 153));
        }

        .btn-save-venue {
            background: linear-gradient(45deg, rgb(var(--c-accent2-28a745, 40 167 69)), rgb(var(--c-accent-20c997, 32 201 151)));
            border: none;
            border-radius: 30px;
            padding: 1rem 2rem;
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgb(var(--c-accent2-28a745, 40 167 69) / 0.3);
        }

        .btn-save-venue:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgb(var(--c-accent2-28a745, 40 167 69) / 0.4);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            background: linear-gradient(45deg, rgb(var(--c-accent2-218838, 33 136 56)), rgb(var(--c-accent2-1e7e34, 30 126 52)));
        }

        .venue-list {
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

        .btn-delete {
            background: linear-gradient(45deg, rgb(var(--c-danger-dc3545, 220 53 69)), rgb(var(--c-danger-e74c3c, 231 76 60)));
            border: none;
            border-radius: 20px;
            padding: 0.4rem 0.8rem;
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgb(var(--c-danger-dc3545, 220 53 69) / 0.3);
            color: rgb(var(--c-fixed-ffffff, 255 255 255));
        }

        @media (max-width: 768px) {
            .venue-card {
                margin: 0 0.5rem;
                padding: 1.5rem;
            }

            .venue-title {
                font-size: 2rem;
            }

            .venue-form {
                padding: 1.5rem;
            }
        }
    </style>
    <link rel="stylesheet" href="css/aurora.css">
</head>

<body class="au au-admin">
    <script src="js/aurora-sky.js"></script>
    <!-- Back Button -->
    <a href="pending_applications.php" class="back-btn">
        <i class="fas fa-arrow-left me-2"></i>Back to Applications
    </a>

    <!-- Venue Container -->
    <div class="venue-container">
        <div class="venue-card">
            <h1 class="venue-title">
                <i class="fas fa-map-marker-alt mb-3"></i><br>
                Set Venue Information
            </h1>
            <p class="venue-subtitle">
                Configure venue details for events and meetings
            </p>

            <!-- Success/Error Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"
                        style="filter: brightness(0) invert(1);"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Venue Form -->
            <div class="venue-form">
                <form method="POST" action="" id="venueForm">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="venue_name" class="form-label">
                                <i class="fas fa-building me-1"></i>Venue Name
                            </label>
                            <input type="text" class="form-control" id="venue_name" name="venue_name"
                                placeholder="Enter venue name" required value="<?= htmlspecialchars($vOld('venue_name')) ?>">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="venue_location" class="form-label">
                                <i class="fas fa-map-pin me-1"></i>Location
                            </label>
                            <input type="text" class="form-control" id="venue_location" name="venue_location"
                                placeholder="Enter venue location" required value="<?= htmlspecialchars($vOld('venue_location')) ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="venue_date" class="form-label">
                                <i class="fas fa-calendar me-1"></i>Date
                            </label>
                            <input type="date" class="form-control" id="venue_date" name="venue_date" required value="<?= htmlspecialchars($vOld('venue_date')) ?>">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="venue_time" class="form-label">
                                <i class="fas fa-clock me-1"></i>Time
                            </label>
                            <input type="time" class="form-control" id="venue_time" name="venue_time" required value="<?= htmlspecialchars($vOld('venue_time')) ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="venue_starting_time" class="form-label">
                                <i class="fas fa-play me-1"></i>Starting Time
                            </label>
                            <input type="text" class="form-control" id="venue_starting_time" name="venue_starting_time"
                                placeholder="e.g., 09:00" required value="<?= htmlspecialchars($vOld('venue_starting_time')) ?>">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="venue_ending_time" class="form-label">
                                <i class="fas fa-stop me-1"></i>Ending Time
                            </label>
                            <input type="text" class="form-control" id="venue_ending_time" name="venue_ending_time"
                                placeholder="e.g., 17:00" required value="<?= htmlspecialchars($vOld('venue_ending_time')) ?>">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="venue_ampm" class="form-label">
                                <i class="fas fa-sun me-1"></i>AM/PM
                            </label>
                            <select class="form-select" id="venue_ampm" name="venue_ampm" required>
                                <option value="AM"<?= $vOld('venue_ampm', 'PM') === 'AM' ? ' selected' : '' ?>>AM</option>
                                <option value="PM"<?= $vOld('venue_ampm', 'PM') !== 'AM' ? ' selected' : '' ?>>PM</option>
                            </select>
                        </div>
                    </div>

                    <div class="text-center">
                        <button type="submit" class="btn btn-save-venue">
                            <i class="fas fa-save me-2"></i>Save Venue Information
                        </button>
                    </div>
                </form>
            </div>

            <!-- Existing Venues List -->
            <div class="venue-list">
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Venue Name</th>
                                <th>Location</th>
                                <th>Date & Time</th>
                                <th>Duration</th>
                                <th>AM/PM</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($venues)): ?>
                                <?php foreach ($venues as $venue): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($venue['venue_name']); ?></td>
                                        <td><?php echo htmlspecialchars($venue['venue_location']); ?></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($venue['venue_dateTime'])); ?></td>
                                        <td><?php echo htmlspecialchars($venue['venue_startingTime'] . ' - ' . $venue['venue_endingTime']); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($venue['venu_ampm']); ?></td>
                                        <td>
                                            <button class="btn btn-delete btn-sm"
                                                onclick="deleteVenue(<?php echo $venue['venue_id']; ?>)">
                                                <i class="fas fa-trash me-1"></i>Delete
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        <i class="fas fa-map-marker-alt fa-3x mb-3"></i><br>
                                        No venue information available.
                                        <?php if (isset($fetch_error)): ?>
                                            <br><small class="text-danger"><?php echo htmlspecialchars($fetch_error); ?></small>
                                        <?php endif; ?>
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
    <script src="js/admin-toast.js"></script>

    <script>
        // Set minimum date to today
        document.getElementById('venue_date').min = new Date().toISOString().split('T')[0];

        // Pre-fill form with existing venue data (if any)
        <?php if ($latestVenue): ?>
            document.addEventListener('DOMContentLoaded', function () {
                const venue = <?php echo json_encode([
                    'venue_name'          => $latestVenue['venue_name'],
                    'venue_location'      => $latestVenue['venue_location'],
                    'venue_date'          => date('Y-m-d', strtotime($latestVenue['venue_dateTime'])),
                    'venue_time'          => date('H:i', strtotime($latestVenue['venue_dateTime'])),
                    'venue_starting_time' => $latestVenue['venue_startingTime'],
                    'venue_ending_time'   => $latestVenue['venue_endingTime'],
                    'venue_ampm'          => $latestVenue['venu_ampm'],
                ]); ?>;
                Object.keys(venue).forEach(id => { document.getElementById(id).value = venue[id]; });
            });
        <?php endif; ?>

        // Delete venue function
        function deleteVenue(venueId) {
            if (confirm('Are you sure you want to delete this venue information?')) {
                fetch('../backend/Action/delete_venue.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        venue_id: venueId
                    })
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message, 'success');
                            setTimeout(() => {
                                window.location.reload();
                            }, 1000);
                        } else {
                            showNotification(data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('An error occurred while deleting the venue.', 'error');
                    });
            }
        }


        // Form validation: compare "H:MM" times numerically (both share the AM/PM selector)
        function toMinutes(value) {
            const m = /^(\d{1,2})(?::(\d{2}))?$/.exec(value.trim());
            return m ? parseInt(m[1], 10) * 60 + parseInt(m[2] || '0', 10) : null;
        }

        document.getElementById('venueForm').addEventListener('submit', function (e) {
            const start = toMinutes(document.getElementById('venue_starting_time').value);
            const end = toMinutes(document.getElementById('venue_ending_time').value);

            if (start !== null && end !== null && start >= end) {
                e.preventDefault();
                showNotification('Starting time must be earlier than ending time.', 'error');
            }
        });
    </script>

</body>

</html>
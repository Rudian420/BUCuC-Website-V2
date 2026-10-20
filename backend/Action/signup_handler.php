<?php
/**
 * Public "Apply Now" form handler (frontend/index.php #signup).
 * Inserts a row into pending_applications and redirects back with a flash message.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_start_session();
site_guard_public();
require_once '../Database/db.php';

const SIGNUP_REDIRECT = '../../frontend/index.php#signup';
const SIGNUP_MAX_PER_IP_HOUR = 30;    // saved applications per IP address per hour (campus Wi-Fi shares IPs)
const SIGNUP_MAX_POSTS_PER_HOUR = 90; // submissions of any kind (including failed ones) per IP per hour

function signupFail($message, array $old = [])
{
    $_SESSION['signup_error'] = $message;
    $_SESSION['signup_old'] = $old;
    header('Location: ' . SIGNUP_REDIRECT);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    signupFail('Invalid request method');
}

/** Records this submission for the per-IP limit; returns [posts this hour, saved applications this hour]. */
function signupCount(PDO $pdo, $saved = null)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS signup_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL, saved TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_signup_ip (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($saved !== null) {
        $pdo->prepare("INSERT INTO signup_attempts (ip, saved) VALUES (?, ?)")->execute([auth_ip(), $saved ? 1 : 0]);
        if (random_int(1, 50) === 1) $pdo->exec("DELETE FROM signup_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)");
        return null;
    }
    $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(saved), 0) FROM signup_attempts WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)");
    $st->execute([auth_ip()]);
    return array_map('intval', $st->fetch(PDO::FETCH_NUM));
}

// Spam trap: the hidden "website" field is only ever filled by bots. Pretend it worked, save nothing.
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    $_SESSION['signup_success'] = 'Registration successful! Your membership application has been submitted successfully.';
    header('Location: ' . SIGNUP_REDIRECT);
    exit();
}

$fields = [
    'full_name'      => trim($_POST['signup-name'] ?? ''),
    'university_id'  => trim($_POST['signup-id'] ?? ''),
    'email'          => trim($_POST['signup-main-email'] ?? ''),
    'gsuite_email'   => trim($_POST['signup-gsuite-email'] ?? ''),
    'department'     => trim($_POST['signup-department'] ?? ''),
    'phone'          => trim($_POST['signup-phone'] ?? ''),
    'semester'       => trim($_POST['signup-semester'] ?? ''),
    'gender'         => trim($_POST['signup-gender'] ?? ''),
    'date_of_birth'  => trim($_POST['signup-dob'] ?? ''),
    'facebook_url'   => trim($_POST['signup-facebook'] ?? ''),
    'firstPriority'  => trim($_POST['signup-first-dept'] ?? ''),
    'secondPriority' => trim($_POST['signup-second-dept'] ?? ''),
];

// Values echoed back into the form if validation fails
$old = [
    'signup-name' => $fields['full_name'], 'signup-id' => $fields['university_id'],
    'signup-main-email' => $fields['email'], 'signup-gsuite-email' => $fields['gsuite_email'],
    'signup-department' => $fields['department'], 'signup-phone' => $fields['phone'],
    'signup-semester' => $fields['semester'], 'signup-gender' => $fields['gender'],
    'signup-dob' => $fields['date_of_birth'], 'signup-facebook' => $fields['facebook_url'],
    'signup-first-dept' => $fields['firstPriority'], 'signup-second-dept' => $fields['secondPriority'],
];

$semesters   = ['1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th', '10th+'];
$genders     = ['Male', 'Female', 'Other'];
$departments = ['Admin', 'PR', 'HR', 'EM', 'Creative', 'Finance', 'Performance', 'RD', 'MIAP'];

// Closed applications first: a tab opened before the form was switched off gets the real reason
try {
    $st = db()->query("SELECT is_enabled FROM signup_status WHERE id = 1")->fetch();
    if ($st && !(int) $st['is_enabled']) signupFail('Applications are currently closed.', $old);
} catch (\PDOException $e) {
    error_log('Signup status: ' . $e->getMessage());
}
// Signed token from the page (not tied to the session; valid FORM_TOKEN_HOURS). The refilled page carries a new one.
if (!form_token_ok('signup', $_POST['form_token'] ?? '')) {
    signupFail('The form was open too long, so nothing was sent. Your answers are kept: please press Submit again.', $old);
}
try {
    [$posts, $savedHour] = signupCount(db());
    if ($posts >= SIGNUP_MAX_POSTS_PER_HOUR || $savedHour >= SIGNUP_MAX_PER_IP_HOUR) {
        signupFail('Too many applications were sent from this network in the last hour. Please try again later.', $old);
    }
    signupCount(db(), false);
} catch (\PDOException $e) {
    error_log('Signup rate limit: ' . $e->getMessage());
}

foreach ($fields as $value) {
    if ($value === '') {
        signupFail('Please fill in every field.', $old);
    }
}
if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL) || !filter_var($fields['gsuite_email'], FILTER_VALIDATE_EMAIL)) {
    signupFail('Please enter valid email addresses.', $old);
}
if (!in_array($fields['semester'], $semesters, true) || !in_array($fields['gender'], $genders, true)) {
    signupFail('Please choose your semester and gender from the list.', $old);
}
if (!in_array($fields['firstPriority'], $departments, true) || !in_array($fields['secondPriority'], $departments, true)) {
    signupFail('Please choose your department preferences from the list.', $old);
}
if ($fields['firstPriority'] === $fields['secondPriority']) {
    signupFail('Your first and second department preferences must be different.', $old);
}
$dob = DateTime::createFromFormat('Y-m-d', $fields['date_of_birth']);
if (!$dob || $dob->format('Y-m-d') !== $fields['date_of_birth'] || $dob > new DateTime()) {
    signupFail('Please enter a valid date of birth.', $old);
}
if (!filter_var($fields['facebook_url'], FILTER_VALIDATE_URL)) {
    signupFail('Please enter a valid Facebook profile URL.', $old);
}

try {
    $database = new Database();
    $conn = $database->createConnection();

    // The admin can close applications from "Signup Control"; enforce it here too
    $status = $conn->query("SELECT is_enabled FROM signup_status WHERE id = 1")->fetch();
    if ($status && !(int) $status['is_enabled']) {
        signupFail('Applications are currently closed.', $old);
    }

    // An applicant may only exist once across the whole pipeline
    foreach (['pending_applications' => 'already submitted an application',
              'shortlisted_members'  => 'already been shortlisted',
              'members'              => 'already a member'] as $table => $reason) {
        $stmt = $conn->prepare("SELECT id FROM $table WHERE university_id = ? OR gsuite_email = ? LIMIT 1");
        $stmt->execute([$fields['university_id'], $fields['gsuite_email']]);
        if ($stmt->fetch()) {
            signupFail("This University ID or G-Suite email has {$reason}.", $old);
        }
    }

    $columns = array_keys($fields);
    $sql = "INSERT INTO pending_applications (" . implode(', ', $columns) . ")
            VALUES (:" . implode(', :', $columns) . ")";
    $conn->prepare($sql)->execute($fields);
    application_event('applied', $fields['university_id']);   // for the homepage semester stats
    try { signupCount(db(), true); } catch (\PDOException $e) { }

    unset($_SESSION['signup_old']);
    $_SESSION['signup_success'] = 'Registration successful! Welcome to BRAC University Cultural Club. Your membership application has been submitted successfully.';
    // Shown on the success "ticket" in the Apply section
    $_SESSION['signup_ticket'] = [
        'name'     => $fields['full_name'],
        'id'       => $fields['university_id'],
        'semester' => $fields['semester'],
        'first'    => $fields['firstPriority'],
    ];
    header('Location: ' . SIGNUP_REDIRECT);
    exit();

} catch (\Throwable $e) {
    error_log('Signup error: ' . $e->getMessage());
    signupFail('Something went wrong while saving your application. Please try again, or contact hr.bucuc@gmail.com.', $old);
}

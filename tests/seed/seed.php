<?php
/**
 * Fake seed data for the test database (printed as SQL; tests/run.js imports it after schema.sql).
 * Entirely made up and the same every run: no real student is in here. Addresses use reserved
 * example domains (example.com / example.edu), which can never reach a real mailbox.
 *   375 pending applications (the suites expect this many), 3 shortlisted ones, the Apply switch on,
 *   dashboard numbers and three member-growth chart points.
 */
mt_srand(2026);
$first = ['Ayaan', 'Nusrat', 'Rahim', 'Tanjila', 'Farhan', 'Sadia', 'Imran', 'Mehjabin', 'Tahmid', 'Ritu', 'Arif', 'Lamia', 'Sakib', 'Nabila', 'Zarif', 'Anika'];
$last = ['Hasan', 'Akter', 'Rahman', 'Chowdhury', 'Islam', 'Sultana', 'Kabir', 'Haque', 'Ahmed', 'Begum', 'Sarker', 'Khan'];
$depts = ['CSE', 'EEE', 'BBA', 'Economics', 'English', 'Architecture', 'Pharmacy', 'Law', 'Physics', 'Mathematics'];
$sems = ['1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th', '10th+'];
$genders = ['Male', 'Female', 'Other'];
$prio = ['Admin', 'PR', 'HR', 'EM', 'Creative', 'Finance', 'Performance', 'RD', 'MIAP'];
$q = fn($v) => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $v) . "'";

echo "SET NAMES utf8mb4;\n";
echo "INSERT INTO signup_status (id, is_enabled, updated_by) VALUES (1, 1, 'Test seed') ON DUPLICATE KEY UPDATE is_enabled = 1;\n";
echo "INSERT INTO dashboardmanagement (totalmembers, pending_applications, completedevents, others, gd_male, gd_female) VALUES (0, 0, 600, 0, 0, 0);\n";
echo "INSERT INTO chart_data (category, label, value) VALUES ('member_growth', 'Fall 2025', 2800), ('member_growth', 'Spring 2026', 2900), ('member_growth', 'Summer 2026', 3000);\n";

$rows = [];
for ($i = 1; $i <= 375; $i++) {
    $n = sprintf('%03d', $i);
    $p1 = $prio[mt_rand(0, count($prio) - 1)];
    do { $p2 = $prio[mt_rand(0, count($prio) - 1)]; } while ($p2 === $p1);
    $created = date('Y-m-d H:i:s', strtotime('2026-06-01 09:00:00') + $i * 3571);
    $rows[] = '(' . implode(', ', [
        $q($first[$i % count($first)] . ' ' . $last[($i * 7) % count($last)] . " Test$n"),
        $q('2990' . sprintf('%04d', $i)),                       // student IDs 29900001… (not a real BRACU range)
        $q("applicant$n@example.com"),
        $q("applicant$n@g.example.edu"),
        $q($depts[$i % count($depts)]),
        $q('0190000' . sprintf('%04d', $i)),
        $q($sems[$i % count($sems)]),
        $q($genders[$i % 3]),
        $q(sprintf('200%d-%02d-%02d', 2 + $i % 6, 1 + $i % 12, 1 + $i % 27)),
        $q("https://www.facebook.com/test.applicant.$n"),
        $q($p1), $q($p2), $q($created), $q($created),
    ]) . ')';
}
echo "INSERT INTO pending_applications (full_name, university_id, email, gsuite_email, department, phone, semester, gender, date_of_birth, facebook_url, firstPriority, secondPriority, created_at, updated_at) VALUES\n"
    . implode(",\n", $rows) . ";\n";

// Three applicants already shortlisted (the export and "clear shortlist" checks need some)
$short = [];
for ($i = 1; $i <= 3; $i++) {
    $n = sprintf('%03d', $i);
    $short[] = '(' . implode(', ', [$q("Shortlisted Test$n"), $q('2991' . sprintf('%04d', $i)), $q("shortlisted$n@example.com"), $q("shortlisted$n@g.example.edu"),
        $q('CSE'), $q('0191000' . sprintf('%04d', $i)), $q('4th'), $q($genders[$i % 3]), $q('2004-05-0' . $i), $q("https://www.facebook.com/test.shortlisted.$n"), $q('HR'), $q('PR')]) . ')';
}
echo "INSERT INTO shortlisted_members (full_name, university_id, email, gsuite_email, department, phone, semester, gender, date_of_birth, facebook_url, firstPriority, secondPriority) VALUES\n"
    . implode(",\n", $short) . ";\n";

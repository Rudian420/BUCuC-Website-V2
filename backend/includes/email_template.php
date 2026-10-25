<?php
/**
 * Congratulations e-mail sent when a Super Admin gives final approval.
 * Used by frontend/handle_application.php (action=accept).
 */

/**
 * Latest row of the venuinfo table (orientation venue), or null.
 */
function getLatestVenueInfo(PDO $pdo)
{
    try {
        $stmt = $pdo->query("SELECT * FROM venuinfo ORDER BY venue_id DESC LIMIT 1");
        $venue = $stmt->fetch();
        return $venue ? $venue : null;
    } catch (\Throwable $e) {
        error_log("Venue info error: " . $e->getMessage());
        return null;
    }
}

function generateEmailTemplate(array $member, $venueInfo)
{
    $template = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to BUCuC</title>
    <style>
        body {
            font-family: Georgia, "Times New Roman", serif;
            line-height: 1.8;
            color: #2c2c2c;
            background-color: #efefef;
            margin: 0;
            padding: 20px;
        }
        .wrapper {
            max-width: 620px;
            margin: 0 auto;
        }
        .header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            color: #ffffff;
            text-align: center;
            padding: 38px 30px 30px;
            border-radius: 8px 8px 0 0;
        }
        .header h1 {
            margin: 0 0 8px 0;
            font-size: 26px;
            font-weight: bold;
            letter-spacing: 1px;
        }
        .header p {
            margin: 0;
            font-size: 13px;
            color: #b8ccee;
            letter-spacing: 0.5px;
        }
        .body {
            background-color: #ffffff;
            padding: 36px 42px;
        }
        .body p {
            margin: 0 0 16px 0;
            font-size: 15px;
        }
        .congrats-box {
            background-color: #f2f8f2;
            border-left: 4px solid #2e7d32;
            padding: 16px 20px;
            margin: 24px 0;
            border-radius: 0 4px 4px 0;
        }
        .congrats-box p {
            margin: 0;
            font-size: 15px;
        }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #0f3460;
            border-bottom: 1px solid #dde3ed;
            padding-bottom: 6px;
            margin: 28px 0 14px 0;
            font-family: Arial, sans-serif;
        }
        .details-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            margin-bottom: 24px;
        }
        .details-table td {
            padding: 9px 10px;
            border-bottom: 1px solid #f2f2f2;
            vertical-align: top;
        }
        .details-table td:first-child {
            color: #666;
            width: 38%;
            font-weight: bold;
            font-family: Arial, sans-serif;
            font-size: 13px;
        }
        .social-wrap {
            margin: 14px 0 24px 0;
        }
        .social-row {
            margin-bottom: 8px;
            font-size: 0;
        }
        .social-btn {
            display: inline-block;
            padding: 9px 0;
            width: 48%;
            margin-right: 2%;
            background-color: #0f3460;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 4px;
            font-size: 12px;
            font-family: Arial, sans-serif;
            text-align: center;
            box-sizing: border-box;
        }
        .social-btn:last-child {
            margin-right: 0;
        }
        .social-btn-full {
            display: inline-block;
            padding: 9px 0;
            width: 100%;
            background-color: #0f3460;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 4px;
            font-size: 12px;
            font-family: Arial, sans-serif;
            text-align: center;
            box-sizing: border-box;
        }
        .venue-box {
            background-color: #fffbf0;
            border-left: 4px solid #e6a817;
            padding: 16px 20px;
            margin: 24px 0;
            border-radius: 0 4px 4px 0;
        }
        .venue-box .section-title {
            color: #7a5c00;
            border-bottom-color: #edd98a;
            margin-top: 0;
        }
        .venue-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .venue-table td {
            padding: 6px 0;
            vertical-align: top;
        }
        .venue-table td:first-child {
            font-weight: bold;
            color: #666;
            width: 30%;
            font-family: Arial, sans-serif;
            font-size: 13px;
        }
        .mandatory-note {
            background-color: #fff5f5;
            border-left: 4px solid #c0392b;
            padding: 14px 20px;
            margin: 20px 0;
            border-radius: 0 4px 4px 0;
            font-size: 14px;
        }
        .tagline {
            text-align: center;
            font-style: italic;
            color: #0f3460;
            font-size: 15px;
            letter-spacing: 3px;
            margin: 30px 0 24px 0;
            font-family: Arial, sans-serif;
        }
        .signoff {
            font-size: 14px;
            margin-top: 24px;
            border-top: 1px solid #eeeeee;
            padding-top: 20px;
            color: #333;
        }
        .signoff strong {
            color: #0f3460;
        }
        .signoff a {
            color: #0f3460;
            text-decoration: none;
        }
        .footer {
            background-color: #f5f5f5;
            border-top: 1px solid #e0e0e0;
            border-radius: 0 0 8px 8px;
            text-align: center;
            padding: 18px 20px;
            font-size: 12px;
            color: #888;
            font-family: Arial, sans-serif;
        }
        .footer a {
            color: #0f3460;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>&#127881; Congratulations!</h1>
            <p>BRAC University Cultural Club &mdash; Official Membership Confirmation</p>
        </div>

        <div class="body">
            <p>Dear <strong>' . htmlspecialchars($member['full_name']) . '</strong>,</p>

            <div class="congrats-box">
                <p>We are delighted to inform you that you have successfully completed all stages of the BUCuC recruitment process and have been officially selected as a member of the <strong>BRAC University Cultural Club (BUCuC)</strong>. We warmly welcome you to the club and look forward to your contributions.</p>
            </div>

            <p class="section-title">Your Application Details</p>
            <table class="details-table">
                <tr><td>Full Name</td><td>' . htmlspecialchars($member['full_name']) . '</td></tr>
                <tr><td>University ID</td><td>' . htmlspecialchars($member['university_id']) . '</td></tr>
                <tr><td>Department</td><td>' . htmlspecialchars($member['department']) . '</td></tr>
                <tr><td>First Priority</td><td>' . htmlspecialchars($member['firstPriority']) . '</td></tr>
                <tr><td>Second Priority</td><td>' . htmlspecialchars($member['secondPriority']) . '</td></tr>
            </table>

            <p class="section-title">Stay Connected</p>
            <p style="font-size:14px; margin-bottom:14px; font-family:Arial,sans-serif;">To stay informed about all upcoming activities and announcements, please follow BUCuC on our official platforms:</p>

            <div class="social-wrap">
                <div class="social-row">
                    <a href="https://bucuc.org" class="social-btn">&#127760;&nbsp; Official Website</a><a href="https://www.facebook.com/bucuc" class="social-btn">&#128196;&nbsp; Facebook Page</a>
                </div>
                <div class="social-row">
                    <a href="https://www.facebook.com/groups/86555568937" class="social-btn">&#128101;&nbsp; Facebook Group</a><a href="https://www.instagram.com/bucuclive/" class="social-btn">&#128247;&nbsp; Instagram</a>
                </div>
                <div class="social-row">
                    <a href="https://www.youtube.com/@bracuniversityculturalclub717" class="social-btn-full">&#127909;&nbsp; YouTube</a>
                </div>
            </div>

            <p style="font-size:15px;">As a newly selected member, your first major commitment will be the <strong>Orientation</strong> &mdash; a mandatory event where you will meet your fellow members, get acquainted with the club structure, and formally begin your journey with BUCuC.</p>
            ';

    if ($venueInfo) {
        $template .= '
            <div class="venue-box">
                <p class="section-title">Orientation Details</p>
                <table class="venue-table">
                    <tr><td>Venue</td><td>' . htmlspecialchars($venueInfo['venue_name']) . '</td></tr>
                    <tr><td>Location</td><td>' . htmlspecialchars($venueInfo['venue_location']) . '</td></tr>
                    <tr><td>Date</td><td>' . date('F j, Y', strtotime($venueInfo['venue_dateTime'])) . '</td></tr>
                    <tr><td>Time</td><td>' . htmlspecialchars($venueInfo['venue_startingTime'] . ' - ' . $venueInfo['venue_endingTime'] . ' ' . $venueInfo['venu_ampm']) . '</td></tr>
                </table>
            </div>
            ';
    }

    $template .= '
            <div class="mandatory-note">
                <strong>Please note:</strong> Attendance at the Orientation is <strong>mandatory</strong> for all newly selected members. We look forward to seeing you there.
            </div>

            <p style="font-size:15px;">Following the Orientation, you will be added to the official BUCuC Messenger group for club communication. If you have not been added within a few days after the event, please reach out to the HR team directly at <a href="mailto:hr.bucuc@gmail.com" style="color:#0f3460;">hr.bucuc@gmail.com</a>.</p>

            <p style="font-size:15px;">Once again, welcome to the BUCuC family. We are excited to have you with us.</p>

            <div class="tagline">Work &bull; Bond &bull; Glow</div>

            <div class="signoff">
                <p>Warm regards,<br>
                <strong>Secretary of Human Resources</strong><br>
                BRAC University Cultural Club<br>
                <a href="mailto:hr.bucuc@gmail.com">hr.bucuc@gmail.com</a> &nbsp;|&nbsp; <a href="https://bucuc.org">bucuc.org</a></p>
            </div>
        </div>

        <div class="footer">
            <p>This is an automated email. Please do not reply directly to this message.</p>
            <p>For enquiries: <a href="mailto:hr.bucuc@gmail.com">hr.bucuc@gmail.com</a> &nbsp;|&nbsp; <a href="https://bucuc.org">bucuc.org</a></p>
        </div>
    </div>
</body>
</html>';

    return $template;
}

function generatePlainTextEmail(array $member, $venueInfo)
{
    $text = "Congratulations, " . $member['full_name'] . "!\n\n" .
        "We are delighted to inform you that you have successfully completed all stages of the BUCuC recruitment process and have been officially selected as a member of the BRAC University Cultural Club (BUCuC).\n\n" .
        "YOUR APPLICATION DETAILS\n" .
        "------------------------\n" .
        "Full Name      : " . $member['full_name'] . "\n" .
        "University ID  : " . $member['university_id'] . "\n" .
        "Department     : " . $member['department'] . "\n" .
        "First Priority : " . $member['firstPriority'] . "\n" .
        "Second Priority: " . $member['secondPriority'] . "\n\n" .
        "STAY CONNECTED\n" .
        "--------------\n" .
        "Website       : https://bucuc.org\n" .
        "Facebook Page : https://www.facebook.com/bucuc\n" .
        "Facebook Group: https://www.facebook.com/groups/86555568937\n" .
        "Instagram     : https://www.instagram.com/bucuclive/\n" .
        "YouTube       : https://www.youtube.com/@bracuniversityculturalclub717\n\n";

    if ($venueInfo) {
        $text .= "ORIENTATION DETAILS\n" .
            "-------------------\n" .
            "Venue   : " . $venueInfo['venue_name'] . "\n" .
            "Location: " . $venueInfo['venue_location'] . "\n" .
            "Date    : " . date('F j, Y', strtotime($venueInfo['venue_dateTime'])) . "\n" .
            "Time    : " . $venueInfo['venue_startingTime'] . ' - ' . $venueInfo['venue_endingTime'] . ' ' . $venueInfo['venu_ampm'] . "\n\n";
    }

    $text .= "Please note: Attendance at the Orientation is mandatory for all newly selected members.\n\n" .
        "Following the Orientation, you will be added to the official BUCuC Messenger group. If you have not been added within a few days, please contact us at hr.bucuc@gmail.com.\n\n" .
        "Work | Bond | Glow\n\n" .
        "Warm regards,\n" .
        "Secretary of Human Resources\n" .
        "BRAC University Cultural Club\n" .
        "hr.bucuc@gmail.com | bucuc.org";

    return $text;
}

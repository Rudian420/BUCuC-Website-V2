<?php
/**
 * Numbers shown on the admin / super admin dashboards.
 *
 * - total_members      = latest dashboardmanagement.totalmembers (legacy base count) + rows in members
 * - pending / shortlisted counts are always live
 * - completed_events   = latest dashboardmanagement.completedevents (typed in Dashboard Updates)
 * - member_growth      = chart_data rows (category 'member_growth'), edited in Dashboard Updates
 * - gender             = manual gd_male/gd_female if either is set, otherwise live counts from members
 */
function getDashboardStats(PDO $conn)
{
    $stats = [
        'total_members'        => 0,
        'pending_applications' => 0,
        'shortlisted'          => 0,
        'completed_events'     => 0,
        'member_growth'        => [],
        'gender'               => ['male' => 0, 'female' => 0, 'other' => 0, 'source' => 'none'],
    ];

    try {
        $latest = $conn->query("SELECT totalmembers, completedevents, gd_male, gd_female
                                FROM dashboardmanagement ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];

        $approved = (int) $conn->query("SELECT COUNT(*) FROM members")->fetchColumn();

        $stats['total_members']        = (int) ($latest['totalmembers'] ?? 0) + $approved;
        $stats['completed_events']     = (int) ($latest['completedevents'] ?? 0);
        $stats['pending_applications'] = (int) $conn->query("SELECT COUNT(*) FROM pending_applications")->fetchColumn();
        $stats['shortlisted']          = (int) $conn->query("SELECT COUNT(*) FROM shortlisted_members")->fetchColumn();

        $rows = $conn->query("SELECT label, value FROM chart_data WHERE category = 'member_growth' ORDER BY id ASC")
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $stats['member_growth'][] = ['label' => $row['label'], 'value' => (int) $row['value']];
        }

        $manualMale = (int) ($latest['gd_male'] ?? 0);
        $manualFemale = (int) ($latest['gd_female'] ?? 0);
        if ($manualMale > 0 || $manualFemale > 0) {
            $stats['gender'] = ['male' => $manualMale, 'female' => $manualFemale, 'other' => 0, 'source' => 'manual'];
        } else {
            $counts = $conn->query("SELECT gender, COUNT(*) AS c FROM members GROUP BY gender")->fetchAll(PDO::FETCH_KEY_PAIR);
            $stats['gender'] = [
                'male'   => (int) ($counts['Male'] ?? 0),
                'female' => (int) ($counts['Female'] ?? 0),
                'other'  => (int) ($counts['Other'] ?? 0),
                'source' => 'members',
            ];
        }
    } catch (\Throwable $e) {
        error_log("Error fetching dashboard stats: " . $e->getMessage());
    }

    return $stats;
}

<?php
/**
 * Handover notes: one document per tenure, written by the outgoing panel for the next one.
 *
 * handover_docs      one per tenure, keyed by the tenure label (tenure_id is cleared, not deleted,
 *                    when the tenure itself is removed by the 12-tenure rule: notes are never lost)
 * handover_sections  the current content of each section (text, or a JSON list of table rows)
 * handover_versions  every saved version of a section (who / role / when) for history and restore
 *
 * Editable: only the current tenure's document (and any document the Super Admin unlocked).
 * Pending tasks can be ticked done on any document (the next panel does that).
 * Authors are shown by role, never by name; the Super Admin shows as "an admin" to others.
 */

require_once __DIR__ . '/site.php';

/** Sections in order. type text | table; cols for tables; 'perm' = needs that permission to see. */
const HANDOVER_SECTIONS = [
    'summary' => ['title' => 'Summary of the year', 'type' => 'text', 'icon' => 'fa-book-open',
        'prompt' => 'Major events, results and the overall state of the club: membership, finances, reputation, what the next panel walks into.'],
    'contacts' => ['title' => 'Contacts', 'type' => 'table', 'icon' => 'fa-address-book', 'perm' => 'handover_contacts',
        'prompt' => 'Sponsors, vendors, venues, OCA, sound and light providers, photographers. Who they are, how to reach them, and how the relationship stands.',
        'cols' => ['name' => 'Name', 'organisation' => 'Organisation', 'role' => 'Their role', 'contact' => 'Phone / e-mail', 'notes' => 'Notes on the relationship']],
    'worked' => ['title' => 'What worked', 'type' => 'text', 'icon' => 'fa-thumbs-up',
        'prompt' => 'Things worth repeating: formats, partners, timelines, ideas that paid off.'],
    'didnt' => ['title' => "What didn't work", 'type' => 'text', 'icon' => 'fa-triangle-exclamation',
        'prompt' => 'Mistakes and things to avoid, and what you would do differently.'],
    'pending' => ['title' => 'Pending tasks', 'type' => 'table', 'icon' => 'fa-list-check',
        'prompt' => 'Unfinished work, open promises, unpaid dues. The next panel ticks each one off when it is done.',
        'cols' => ['task' => 'Task', 'notes' => 'Details (who, how much, by when)']],
    'calendar' => ['title' => 'Yearly calendar', 'type' => 'table', 'icon' => 'fa-calendar-days',
        'prompt' => 'Recurring deadlines: recruitment, OCA approvals, when flagship event planning has to start.',
        'cols' => ['when' => 'When', 'what' => 'What', 'notes' => 'Notes']],
    'accounts' => ['title' => 'Accounts and access', 'type' => 'table', 'icon' => 'fa-key',
        'prompt' => 'Which accounts the club has (social media, e-mail, Google Drive, hosting) and WHO currently holds access.',
        'warning' => 'Never write passwords here. Write who holds access; hand passwords over in person and change them.',
        'cols' => ['account' => 'Account', 'used_for' => 'Used for', 'holder' => 'Who holds access now', 'notes' => 'Notes']],
    'links' => ['title' => 'Useful links', 'type' => 'table', 'icon' => 'fa-link',
        'prompt' => 'Drive folders, sponsorship decks, past reports.',
        'cols' => ['title' => 'Title', 'url' => 'Link (https://…)', 'notes' => 'Notes']],
    'roles' => ['title' => 'Role-specific notes', 'type' => 'roles', 'icon' => 'fa-user-tie',
        'prompt' => 'Advice for whoever takes your role next. Each role writes in its own space.'],
];
const HANDOVER_TEXT_MAX = 20000;
const HANDOVER_ROWS_MAX = 200;
const HANDOVER_CELL_MAX = 1000;
const HANDOVER_REMINDER_DAYS = 30;     // reminder about one month before the tenure ends
// "password: …", "pwd=…", "pass - …": a password must never be written in the notes
const HANDOVER_PASSWORD_RE = '/\b(pass(word|wd|code)?|pwd|pin)\s*[:=\-]\s*\S/i';

function handover_ensure(PDO $pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;
    site_ensure_schema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS handover_docs (
        id INT AUTO_INCREMENT PRIMARY KEY, tenure_id INT NULL, tenure_label VARCHAR(20) NOT NULL,
        unlocked TINYINT(1) NOT NULL DEFAULT 0, ends_on DATE NULL, reminder_sent_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_handover_label (tenure_label),
        CONSTRAINT fk_handover_tenure FOREIGN KEY (tenure_id) REFERENCES tenures (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS handover_sections (
        doc_id INT NOT NULL, section_key VARCHAR(40) NOT NULL, content MEDIUMTEXT NOT NULL,
        version_id INT NULL, updated_by INT NULL, updated_by_role VARCHAR(20) NULL, updated_at DATETIME NOT NULL,
        PRIMARY KEY (doc_id, section_key),
        CONSTRAINT fk_handover_sec_doc FOREIGN KEY (doc_id) REFERENCES handover_docs (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS handover_versions (
        id INT AUTO_INCREMENT PRIMARY KEY, doc_id INT NOT NULL, section_key VARCHAR(40) NOT NULL, content MEDIUMTEXT NOT NULL,
        saved_by INT NULL, saved_by_role VARCHAR(20) NULL, saved_at DATETIME NOT NULL, note VARCHAR(120) NULL,
        KEY idx_handover_ver (doc_id, section_key, id),
        CONSTRAINT fk_handover_ver_doc FOREIGN KEY (doc_id) REFERENCES handover_docs (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ------------------------------------------------------------------ documents */

/** Every handover document joined with its tenure (if it still exists), newest tenure first. */
function handover_docs(PDO $pdo)
{
    handover_ensure($pdo);
    // one document per tenure, created on first use; re-attach a document to a tenure with the same label
    foreach (tenures_all(false) as $t) {
        $pdo->prepare("INSERT INTO handover_docs (tenure_id, tenure_label) VALUES (?, ?) ON DUPLICATE KEY UPDATE tenure_id = VALUES(tenure_id)")->execute([$t['id'], $t['label']]);
    }
    return $pdo->query("SELECT d.*, t.is_current, t.start_year FROM handover_docs d LEFT JOIN tenures t ON t.id = d.tenure_id
                        ORDER BY COALESCE(t.start_year, CAST(LEFT(d.tenure_label, 4) AS UNSIGNED)) DESC, d.id DESC")->fetchAll();
}

function handover_doc(PDO $pdo, $id)
{
    handover_ensure($pdo);
    $st = $pdo->prepare("SELECT d.*, t.is_current, t.start_year FROM handover_docs d LEFT JOIN tenures t ON t.id = d.tenure_id WHERE d.id = ?");
    $st->execute([(int) $id]);
    return $st->fetch() ?: null;
}

function handover_current_doc(PDO $pdo)
{
    foreach (handover_docs($pdo) as $d) if ($d['is_current']) return $d;
    return null;
}

/** Editable: the current tenure's document, or one the Super Admin unlocked. */
function handover_editable(array $d)
{
    return (bool) $d['is_current'] || (bool) $d['unlocked'];
}

/* ------------------------------------------------------------------ who may see / edit what */

/** Section keys of the role spaces this admin may see (the Super Admin's space is hidden from others). */
function handover_role_keys()
{
    $keys = [];
    foreach (ROLES as $role => $r) {
        if ($role === 'super_admin' && !auth_is_super()) continue;
        $keys['role:' . $role] = $r['label'];
    }
    return $keys;
}

function handover_can_see($sectionKey)
{
    $base = explode(':', $sectionKey)[0];
    if ($base === 'roles' || strpos($sectionKey, 'role:') === 0) {
        return $sectionKey === 'roles' || isset(handover_role_keys()[$sectionKey]);
    }
    $def = HANDOVER_SECTIONS[$sectionKey] ?? null;
    return $def && (empty($def['perm']) || can($def['perm']));
}

/** May edit this section's content (document editable + allowed to see + role spaces: own role only, Super Admin: all). */
function handover_can_edit(array $doc, $sectionKey)
{
    if (!handover_editable($doc) || !handover_can_see($sectionKey)) return false;
    if (strpos($sectionKey, 'role:') === 0) {
        return auth_is_super() || $sectionKey === 'role:' . role_normalize($_SESSION['admin_role'] ?? null);
    }
    return isset(HANDOVER_SECTIONS[$sectionKey]) && HANDOVER_SECTIONS[$sectionKey]['type'] !== 'roles';
}

/** "Panel Admin" / "Secretary Admin (HR)" … or "an admin" for the Super Admin when the viewer isn't. */
function handover_author($role)
{
    $role = role_normalize($role);
    if ($role === null) return 'an admin';
    if ($role === 'super_admin' && !auth_is_super()) return 'an admin';
    return role_label($role);
}

function handover_section_title($key)
{
    if (strpos($key, 'role:') === 0) return 'Role notes: ' . role_label(substr($key, 5));
    return HANDOVER_SECTIONS[$key]['title'] ?? $key;
}

/* ------------------------------------------------------------------ content */

/** Current content of every section of a document: key => ['content' => text|rows, 'role', 'at', 'version'] */
function handover_sections(PDO $pdo, $docId)
{
    $st = $pdo->prepare("SELECT * FROM handover_sections WHERE doc_id = ?");
    $st->execute([(int) $docId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['section_key']] = ['content' => handover_decode($r['section_key'], $r['content']), 'role' => $r['updated_by_role'], 'at' => $r['updated_at'], 'version' => (int) $r['version_id']];
    }
    return $out;
}

function handover_is_table($key)
{
    return (HANDOVER_SECTIONS[$key]['type'] ?? '') === 'table';
}
function handover_decode($key, $raw)
{
    if (!handover_is_table($key)) return (string) $raw;
    $rows = json_decode((string) $raw, true);
    return is_array($rows) ? $rows : [];
}
function handover_empty($content)
{
    return is_array($content) ? count($content) === 0 : trim((string) $content) === '';
}

/**
 * Cleans what was posted for a section. Returns [content, error]. Tables: rows with at least one
 * filled cell; pending tasks keep their done flags; no passwords; links must be http(s).
 */
function handover_clean($key, $posted, array $oldRows = [])
{
    if (!handover_is_table($key)) {
        $t = trim(str_replace("\r\n", "\n", (string) $posted));
        if (mb_strlen($t) > HANDOVER_TEXT_MAX) return [null, 'This section can be at most ' . number_format(HANDOVER_TEXT_MAX) . ' characters.'];
        if (preg_match(HANDOVER_PASSWORD_RE, $t)) return [null, 'This looks like a password. Never write passwords in the handover notes: write who holds access instead.'];
        return [$t, null];
    }
    $cols = array_keys(HANDOVER_SECTIONS[$key]['cols']);
    $rows = [];
    foreach ((array) $posted as $r) {
        if (!is_array($r)) continue;
        $row = [];
        foreach ($cols as $c) $row[$c] = mb_substr(trim(str_replace("\r\n", "\n", (string) ($r[$c] ?? ''))), 0, HANDOVER_CELL_MAX);
        if (implode('', $row) === '') continue;
        foreach ($row as $v) {
            if (preg_match(HANDOVER_PASSWORD_RE, $v)) return [null, 'A row looks like it contains a password ("' . mb_substr($v, 0, 40) . '…"). Never write passwords here: write who holds access instead.'];
        }
        if ($key === 'links' && $row['url'] !== '' && !preg_match('~^https?://~i', $row['url'])) return [null, 'Links must start with https:// (or http://): "' . mb_substr($row['url'], 0, 60) . '"'];
        if ($key === 'pending') {
            $id = (string) ($r['id'] ?? '');
            $old = null;
            foreach ($oldRows as $o) if (($o['id'] ?? '') === $id && $id !== '') $old = $o;
            $row = ['id' => $old['id'] ?? bin2hex(random_bytes(4))] + $row + ['done' => (bool) ($old['done'] ?? false), 'done_role' => $old['done_role'] ?? null, 'done_at' => $old['done_at'] ?? null];
        }
        $rows[] = $row;
        if (count($rows) > HANDOVER_ROWS_MAX) return [null, 'At most ' . HANDOVER_ROWS_MAX . ' rows per section.'];
    }
    return [$rows, null];
}

/** Saves a section and keeps the version. Returns the new version id. */
function handover_save(PDO $pdo, $docId, $key, $content, $note = null)
{
    $raw = is_array($content) ? json_encode(array_values($content), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $content;
    $role = role_normalize($_SESSION['admin_role'] ?? null);
    $pdo->prepare("INSERT INTO handover_versions (doc_id, section_key, content, saved_by, saved_by_role, saved_at, note) VALUES (?, ?, ?, ?, ?, NOW(), ?)")
        ->execute([(int) $docId, $key, $raw, $_SESSION['admin_id'] ?? null, $role, $note]);
    $vid = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO handover_sections (doc_id, section_key, content, version_id, updated_by, updated_by_role, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE content = VALUES(content), version_id = VALUES(version_id), updated_by = VALUES(updated_by), updated_by_role = VALUES(updated_by_role), updated_at = NOW()")
        ->execute([(int) $docId, $key, $raw, $vid, $_SESSION['admin_id'] ?? null, $role]);
    return $vid;
}

function handover_versions(PDO $pdo, $docId, $key)
{
    $st = $pdo->prepare("SELECT * FROM handover_versions WHERE doc_id = ? AND section_key = ? ORDER BY id DESC");
    $st->execute([(int) $docId, $key]);
    return $st->fetchAll();
}

/** Sections (visible to this admin) that are still empty in a document: [key => title]. */
function handover_empty_sections(PDO $pdo, array $doc)
{
    $have = handover_sections($pdo, $doc['id']);
    $out = [];
    foreach (HANDOVER_SECTIONS as $key => $def) {
        if (!handover_can_see($key)) continue;
        if ($def['type'] === 'roles') {
            $missing = array_filter(array_keys(handover_role_keys()), fn($k) => handover_empty($have[$k]['content'] ?? ''));
            if (count($missing) === count(handover_role_keys())) $out[$key] = $def['title'];
            continue;
        }
        if (handover_empty($have[$key]['content'] ?? '')) $out[$key] = $def['title'];
    }
    return $out;
}

/** Plain text of a section (search, e-mail). */
function handover_plain($key, $content)
{
    if (!is_array($content)) return (string) $content;
    $lines = [];
    foreach ($content as $r) $lines[] = implode(' · ', array_filter(array_map('strval', array_intersect_key($r, HANDOVER_SECTIONS[$key]['cols'] ?? [])), fn($v) => $v !== ''));
    return implode("\n", $lines);
}

/** Search every document's current notes. Returns [['doc', 'key', 'title', 'snippet']] (only what this admin may see). */
function handover_search(PDO $pdo, $q)
{
    $q = trim((string) $q);
    if (mb_strlen($q) < 2) return [];
    $docs = [];
    foreach (handover_docs($pdo) as $d) $docs[(int) $d['id']] = $d;
    $st = $pdo->prepare("SELECT doc_id, section_key, content FROM handover_sections WHERE content LIKE ? ORDER BY doc_id DESC");
    $st->execute(['%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%']);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        if (!isset($docs[(int) $r['doc_id']]) || !handover_can_see($r['section_key'])) continue;
        $text = handover_plain($r['section_key'], handover_decode($r['section_key'], $r['content']));
        $pos = mb_stripos($text, $q);
        if ($pos === false) continue;   // matched only inside the stored structure, not the text
        $start = max(0, $pos - 70);
        $out[] = ['doc' => $docs[(int) $r['doc_id']], 'key' => $r['section_key'], 'title' => handover_section_title($r['section_key']),
            'snippet' => ($start > 0 ? '…' : '') . mb_substr($text, $start, 200) . (mb_strlen($text) > $start + 200 ? '…' : '')];
    }
    return $out;
}

/* ------------------------------------------------------------------ reminders */

/**
 * About one month before the current tenure ends (ends_on, set by the Super Admin or Panel Admin),
 * e-mails every active admin once, listing the empty sections. Called from the dashboard (after the
 * page is sent) and from backend/scripts/handover_reminders.php (cron). Returns how many were sent.
 */
function handover_send_due_reminders(PDO $pdo)
{
    handover_ensure($pdo);
    $d = handover_current_doc($pdo);
    if (!$d || !$d['ends_on'] || $d['reminder_sent_at']) return 0;
    if (time() < strtotime($d['ends_on'] . ' 00:00:00') - HANDOVER_REMINDER_DAYS * 86400) return 0;
    // claim the reminder first, so two page loads at the same moment can't both send it
    $claim = $pdo->prepare("UPDATE handover_docs SET reminder_sent_at = NOW() WHERE id = ? AND reminder_sent_at IS NULL");
    $claim->execute([$d['id']]);
    if ($claim->rowCount() === 0) return 0;
    require_once __DIR__ . '/auth_mail.php';
    $have = handover_sections($pdo, $d['id']);
    $empty = [];
    foreach (HANDOVER_SECTIONS as $key => $def) {
        if ($def['type'] === 'roles') continue;
        if (handover_empty($have[$key]['content'] ?? '')) $empty[] = $def['title'];
    }
    $sent = 0;
    $admins = $pdo->query("SELECT email, username, role FROM adminpanel WHERE status = 'active' AND role IS NOT NULL")->fetchAll();
    $link = rtrim(APP_URL, '/') . '/frontend/handover.php';
    foreach ($admins as $a) {
        $roleKey = 'role:' . role_normalize($a['role']);
        $own = handover_empty($have[$roleKey]['content'] ?? '') ? "\n- Your role notes (" . role_label($a['role']) . ')' : '';
        $list = $empty ? "\n- " . implode("\n- ", $empty) : '';
        $text = "The " . $d['tenure_label'] . " tenure ends on " . date('j F Y', strtotime($d['ends_on'])) . ".\n\n"
            . "Please complete the handover notes for the next panel" . ($list || $own ? ". Still empty:" . $list . $own : '. Every section has something in it; check that it is up to date.') . "\n\n"
            . "Open them here: " . $link . "\n\nNever write passwords in the notes.";
        $subject = 'Handover notes: tenure ' . $d['tenure_label'] . ' ends ' . date('j M Y', strtotime($d['ends_on']));
        $html = auth_mail_layout($subject, '<div style="font-size:15px;line-height:1.7">' . nl2br(htmlspecialchars($text)) . '</div>');
        if (send_auth_email($a['email'], $a['username'], $subject, $html, $text)) $sent++;
    }
    log_activity('handover_reminder_sent', "Reminder e-mailed to $sent admin(s); empty sections: " . ($empty ? implode(', ', $empty) : 'none'), null, null, 'Handover ' . $d['tenure_label']);
    return $sent;
}

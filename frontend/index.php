<?php
require_once __DIR__ . '/../backend/includes/auth.php';
require_once __DIR__ . '/../backend/includes/ads.php';
auth_start_session();
site_guard_public();   // "We'll be back soon" page during a lockdown (the Super Admin still sees the site)

// Get success or error messages from session
$signupSuccess = isset($_SESSION['signup_success']) ? $_SESSION['signup_success'] : null;
$signupError = isset($_SESSION['signup_error']) ? $_SESSION['signup_error'] : null;
$signupOld = isset($_SESSION['signup_old']) ? $_SESSION['signup_old'] : [];
$signupTicket = isset($_SESSION['signup_ticket']) ? $_SESSION['signup_ticket'] : null;

unset($_SESSION['signup_success'], $_SESSION['signup_error'], $_SESSION['signup_old'], $_SESSION['signup_ticket']);

// Re-fill the Apply form after a validation error
function old($key)
{
    global $signupOld;
    return htmlspecialchars($signupOld[$key] ?? '', ENT_QUOTES);
}

function oldSelected($key, $value)
{
    global $signupOld;
    return (($signupOld[$key] ?? '') === $value) ? ' selected' : '';
}

// ── Cache-busting ──────────────────────────────────────────────────────────
// Version = last-modified time of this file.
// Save (touch) index.php after uploading new images to force a new version.
define('IMG_V', filemtime(__FILE__));

// Output-buffer callback: appends ?v=IMG_V to every local <img src="images/…">
// and CSS url('images/…') in the rendered HTML, so browsers never serve stale images.
function _imgCacheBust(string $html): string {
    $v = IMG_V;
    // src="images/..." and src='images/...'
    $html = preg_replace(
        '/\b(src=["\'])(images\/[^"\'?#\s]+)(["\'])/',
        '$1$2?v=' . $v . '$3',
        $html
    );
    // url('images/...'), url("images/..."), url(images/...)
    $html = preg_replace(
        '/\burl\((["\']?)(images\/[^"\'?#\s)]+)\1\)/',
        'url($1$2?v=' . $v . '$1)',
        $html
    );
    return $html;
}
ob_start('_imgCacheBust');
// ──────────────────────────────────────────────────────────────────────────

// ── Panel data ─────────────────────────────────────────────────────────────
// Panels are grouped by tenure (Admin → Website Media → Panels & Tenures). The current tenure
// shows first; older tenures are in the year select.
function panelImageSrc($path)
{
    if ($path === '' || strpos($path, '?') !== false) {
        return $path; // empty, or already encoded + versioned
    }
    return implode('/', array_map('rawurlencode', explode('/', $path))) . '?v=' . IMG_V;
}

function panelPeople(array $people)
{
    return array_map(function ($p) {
        return [
            'name'     => $p['name'] ?? '',
            'position' => $p['position'] ?? '',
            'facebook' => (string) ($p['facebook'] ?? ''),
            'src'      => panelImageSrc($p['image'] ?? ''),
        ];
    }, $people);
}

// Bootstrap grid classes: 4 members fill one row, otherwise rows of 3 (the row is centred).
// Keep in sync with columnClass() in js/site.js.
function panelColumnClass($count)
{
    return $count === 4 ? 'col-lg-3 col-md-6 col-12 pmc-col' : 'col-lg-4 col-md-6 col-12 pmc-col';
}

$panelYears = [];
foreach (tenures_public() as $t) {   // an empty current tenure is skipped: the previous panel stays up
    $panelYears[] = [
        'year'        => (int) $t['start_year'] + 1,
        'label'       => $t['is_current'] ? 'Current Panel (' . $t['label'] . ')' : 'Panel ' . $t['label'],
        'panel'       => panelPeople($t['panel']),
        'secretaries' => panelPeople($t['secretaries']),
    ];
}
$currentPanel = $panelYears[0] ?? ['year' => 0, 'label' => '', 'panel' => [], 'secretaries' => []];

// Check signup status
function getSignupStatus()
{
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

        $sql = "SELECT is_enabled FROM signup_status WHERE id = 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            return (bool) $result['is_enabled'];
        }

        return true;
    } catch (Exception $e) {
        // Default to enabled on error
        error_log("Error checking signup status: " . $e->getMessage());
        return true;
    }
}

$signupEnabled = getSignupStatus();

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#06040f">
    <meta name="description" content="BRAC University Cultural Club (BUCuC): music, dance and cultural storytelling at BRAC University since 2001.">
    <title>BRAC University Cultural Club</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11.1.14/swiper-bundle.min.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <link rel="stylesheet" href="css/aurora.css?v=<?= filemtime(__DIR__ . '/css/aurora.css') ?>">
    <link rel="stylesheet" href="css/site.css?v=<?= filemtime(__DIR__ . '/css/site.css') ?>">
    <link rel="icon" type="image/png" href="images/logopng.png">

    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
</head>

<body class="au au-site">
    <script src="js/aurora-sky.js?v=<?= filemtime(__DIR__ . '/js/aurora-sky.js') ?>"></script>

    <!-- ============================================================ Navbar -->
    <header class="site-nav" id="mainNavbar">
        <div class="wrap">
            <a class="brand" href="index.php">
                <img src="images/logo.png" alt="">
                <span class="au-eq" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                BUCuC
            </a>

            <!-- 7 links in section order: js/click-scroll.js relies on this order -->
            <nav aria-label="Main">
                <ul class="navbar-nav">
                    <li class="nav-item"><a class="nav-link click-scroll" href="#section_1">Home</a></li>
                    <li class="nav-item"><a class="nav-link click-scroll" href="#section_2">About</a></li>
                    <li class="nav-item"><a class="nav-link click-scroll" href="#advisors_section">Advisors</a></li>
                    <li class="nav-item"><a class="nav-link click-scroll" href="#section_3">Panel</a></li>
                    <li class="nav-item"><a class="nav-link click-scroll" href="#section_4">Secretaries</a></li>
                    <li class="nav-item"><a class="nav-link click-scroll" href="#section_5">Past Events</a></li>
                    <li class="nav-item"><a class="nav-link click-scroll" href="#very-bottom">Contact</a></li>
                    <li class="nav-item"><a class="nav-link" href="performances.php">Videos</a></li>
                </ul>
            </nav>

            <div class="nav-actions">
                <a href="admin-login.php" class="au-ghost">Login</a>
                <?php if ($signupEnabled): ?>
                    <a href="#signup" class="au-btn">Apply Now</a>
                <?php endif; ?>
                <button id="sidebarToggle" class="menu-btn" type="button" aria-label="Open menu" aria-controls="mobileSidebar" aria-expanded="false">
                    <span></span><span></span><span></span>
                </button>
            </div>
        </div>
    </header>

    <!-- Mobile drawer -->
    <div id="mobileSidebarOverlay" class="drawer-overlay"></div>
    <nav id="mobileSidebar" class="drawer" aria-label="Mobile">
        <a class="brand" href="index.php"><img src="images/logo.png" alt="">BUCuC</a>
        <a class="drawer-link" href="#section_1"><i class="fa fa-home"></i>Home</a>
        <a class="drawer-link" href="#section_2"><i class="fa fa-info-circle"></i>About</a>
        <a class="drawer-link" href="#advisors_section"><i class="fa fa-user-tie"></i>Advisors</a>
        <a class="drawer-link" href="#section_3"><i class="fa fa-users"></i>Panel</a>
        <a class="drawer-link" href="#section_4"><i class="fa fa-star"></i>Secretaries</a>
        <a class="drawer-link" href="#section_5"><i class="fa fa-calendar"></i>Past Events</a>
        <a class="drawer-link" href="#very-bottom"><i class="fa fa-envelope"></i>Contact</a>
        <a class="drawer-link" href="performances.php"><i class="fa fa-film"></i>Videos</a>
        <?php if ($signupEnabled): ?>
            <a class="au-btn" href="#signup">Apply Now</a>
        <?php endif; ?>
        <a class="au-ghost" href="admin-login.php"><i class="fa fa-user-shield"></i>Admin Login</a>
    </nav>

    <main>
        <!-- ============================================================ Hero -->
        <section class="hero" id="section_1">
            <div class="wrap hero-grid">
                <div class="hero-copy">
                    <div class="system" id="system" aria-hidden="true">
                        <div class="vinyl">
                            <div class="disc"><div class="vlabel"><img src="images/logopng.png" alt=""></div></div>
                            <div class="sheen"></div>
                        </div>
                    </div>
                    <h1 class="hero-title">
                        <span class="t1 au-gradient-text">BRAC University<br>Cultural Club</span>
                        <span class="t2 au-gradient-text">Work • Bond • Glow</span>
                    </h1>
                    <p class="hero-lead">The university’s oldest and largest cultural club: music, dance and cultural storytelling since 2002, with 500+ active members.</p>
                    <div class="hero-cta">
                        <?php if ($signupEnabled): ?>
                            <a href="#signup" class="au-btn">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M7 4.5v15a1 1 0 0 0 1.5.86l12.4-7.5a1 1 0 0 0 0-1.72L8.5 3.64A1 1 0 0 0 7 4.5z"/></svg>
                                Apply Now
                            </a>
                        <?php endif; ?>
                        <a href="#section_5" class="au-ghost">Past Events</a>
                    </div>
                    <ul class="socials">
                        <li>Follow us</li>
                        <li><a href="https://www.facebook.com/bucuc" target="_blank" rel="noopener" aria-label="Facebook page"><i class="fab fa-facebook-f"></i></a></li>
                        <li><a href="https://www.facebook.com/bucucarchive" target="_blank" rel="noopener" aria-label="BUCuC Archive on Facebook"><i class="fas fa-box-archive"></i></a></li>
                        <li><a href="https://www.youtube.com/@bracuniversityculturalclub717" target="_blank" rel="noopener" aria-label="YouTube"><i class="fab fa-youtube"></i></a></li>
                        <li><a href="https://www.instagram.com/bucuclive/" target="_blank" rel="noopener" aria-label="Instagram"><i class="fab fa-instagram"></i></a></li>
                    </ul>
                </div>

                <div class="screen au-glass au-lined">
                    <div class="screen-frame">
                        <video id="heroVideo" autoplay loop muted playsinline preload="auto">
                            <?php foreach (hero_video_sources() as [$vsrc, $vtype]): ?>
                                <source src="<?= htmlspecialchars($vsrc) ?>" type="<?= $vtype ?>">
                            <?php endforeach; ?>
                        </video>
                        <button class="sound" id="unmuteBtn" type="button" aria-pressed="false" aria-label="Turn video sound on">
                            <svg class="ico-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H3v6h3l5 4V5z"/><path d="m22 9-6 6"/><path d="m16 9 6 6"/></svg>
                            <svg class="ico-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H3v6h3l5 4V5z"/><path d="M15.5 9a4 4 0 0 1 0 6"/><path d="M18.5 6.5a8 8 0 0 1 0 11"/></svg>
                            <span>Tap for sound</span>
                        </button>
                    </div>
                    <div class="screen-bar">
                        <span><span class="au-eq" aria-hidden="true"><i></i><i></i><i></i><i></i></span>BUCuC live</span>
                        <span>BRAC University, Dhaka</span>
                    </div>
                </div>
            </div>
        </section>
        <?= ad_slot('home_hero') /* sponsor banner (Super Admin → Sponsor banners); prints nothing when off or empty */ ?>

        <!-- ============================================================ About (white band) -->
        <section class="au-section au-paper" id="section_2">
            <div class="wrap about-grid">
                <div class="about-copy">
                    <div class="paper-brand">
                        <img src="images/logopng.png" alt="BUCuC logo" class="paper-logo">
                        <p class="kicker">About us · since 2002</p>
                    </div>
                    <h2 class="sec-title">BRAC University Cultural Club</h2>

                    <p>BRAC University Cultural Club (BUCuC), established in 2002, is the university’s oldest, largest,
                        and most celebrated cultural club with currently 500+ active members. Dedicated to preserving
                        Bengali heritage while embracing global artistic expressions, BUCuC provides a vibrant platform for
                        students to showcase talents in music, dance, and cultural storytelling. BUCuC hosts grand events
                        like Rhythm Revive, Jolsha, Symphonic Reminiscence, Boishakh, uniting current students, alumni, and
                        the wider university community. The club uniquely blends tradition with modernity—featuring
                        everything from classical and folk performances to classical, pop, rock, fusion, and Qawali. Beyond
                        performances, BUCuC nurtures confidence, leadership, and creative growth in its members. As the only
                        cultural club at BRAC University, BUCuC is not just a platform—it’s a legacy, a family, and the
                        cultural heartbeat of the university.</p>

                    <?php // Managed in Admin → Homepage Stats (numbers from the database, shows entered by hand)
                    $homeStats = array_filter(home_stats(), fn($st) => $st['visible']);
                    if ($homeStats): ?>
                        <div class="stats" style="grid-template-columns: repeat(<?= min(count($homeStats), 4) ?>, minmax(0, 1fr));">
                            <?php foreach ($homeStats as $key => $st): ?>
                                <div data-stat="<?= $key ?>"><b><?= number_format($st['value']) ?></b><span><?= htmlspecialchars($st['label']) ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <h3>Future Plans</h3>
                    <p>The club aims to organize more interactive and engaging events in the future, including cultural
                        festivals, talent hunts, and workshops to nurture the creativity of its members. Stay tuned for more
                        updates!</p>

                    <div class="hero-cta">
                        <a href="#very-bottom" class="au-btn">Contact Us</a>
                        <a href="#section_5" class="au-ghost">Past Events</a>
                    </div>
                </div>

                <div class="about-side">
                    <div class="moments paper-card">
                        <div id="happyMomentCarousel" class="carousel slide carousel-fade" data-bs-ride="carousel" data-bs-interval="3200">
                            <div class="carousel-inner">
                                <?php
                                $moments = [
                                    ['images/2nd.JPG', 'Wild Escape', 'Our Amazing Experience with BUAC'],
                                    ['images/slide2.jpg', 'Rhythm Revive 25.0', 'Our signature recruitment show.'],
                                    ['images/slide3.jpg', 'Power Panel', 'The power house of the club'],
                                    ['images/slide4.jpg', 'BUCuC Iftar', 'Where tradition meets togetherness.'],
                                    ['images/slide5.jpg', 'বৈশাখ উৎসব', 'রঙে, গানে, আর ঐতিহ্যে রাঙানো বৈশাখ ১৪৩২!'],
                                ];
                                foreach ($moments as $i => [$img, $title, $caption]): ?>
                                    <div class="carousel-item<?= $i === 0 ? ' active' : '' ?>">
                                        <img src="<?= $img ?>" class="moment-img" alt="<?= htmlspecialchars($title) ?>" <?= $i ? 'loading="lazy"' : '' ?>>
                                        <div class="moment-cap">
                                            <span class="disc-mini"><img src="images/logopng.png" alt=""></span>
                                            <div>
                                                <h4><?= htmlspecialchars($title) ?></h4>
                                                <p><?= htmlspecialchars($caption) ?></p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button class="carousel-control-prev" type="button" data-bs-target="#happyMomentCarousel" data-bs-slide="prev">
                                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Previous</span>
                            </button>
                            <button class="carousel-control-next" type="button" data-bs-target="#happyMomentCarousel" data-bs-slide="next">
                                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Next</span>
                            </button>
                        </div>
                    </div>

                    <div class="mentors paper-card">
                        <h3>BUCuC Mentors</h3>
                        <p>BUCuC has been shaped by countless mentors whose guidance, creativity, and dedication helped build
                            the club’s vibrant legacy. Among them are figures like Rudan Al Amin, former Secretary of PR and
                            Editorial (2017–2018), celebrated for his humor and commanding stage presence; Yash Rohan,
                            ex-Secretary of Marketing, IT, and Archive (2016–2017), who later rose to national prominence as a
                            Bangladeshi actor; and Sadat Kabir Rudro, senior performer (2014–2015) and co-founder of Dhaka Guys
                            Studios, admired for his musical talent. The club’s artistic spirit was further elevated by Miftah
                            Zaman, former Performance Secretary known for transforming BUCuC’s performing arts scene, and by
                            talented performers like Ankan Kumar, now an acclaimed singer. Leaders such as Nafisa Tasnuva
                            Hossain, ex-Secretary of PR and Editorial and now General Manager at Pathao, exemplify the lasting
                            impact of BUCuC’s mentorship.</p>
                        <p>Though these are only a few names among many, each mentor—past and present—continues to inspire,
                            and together they form the legacy that BUCuC and BRAC University proudly celebrate.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================================ Advisors -->

        <section class="au-section" id="advisors_section">
            <div class="wrap">
                <div class="sec-head">
                    <p class="kicker"><span class="au-eq" aria-hidden="true"><i></i><i></i><i></i><i></i></span>Guidance</p>
                    <h2 class="sec-title au-gradient-text">Meet Our Advisor &amp; Co-Advisor</h2>
                </div>
                <div class="advisors">
                    <article class="person">
                        <div class="person-img"><img src="images/Advisor/advisor.jpg" alt="Dr. Sharmind Neelotpol" loading="lazy"></div>
                        <div class="person-body">
                            <h3 class="person-name">Dr. Sharmind Neelotpol</h3>
                            <span class="person-role">Advisor</span>
                            <a href="https://scholar.google.com/citations?user=qC6VODEAAAAJ&amp;hl=en" target="_blank" rel="noopener">Google Scholar profile</a>
                        </div>
                    </article>
                    <article class="person">
                        <div class="person-img"><img src="images/Advisor/co.JPEG.jpg" alt="Dr. Upal Aditya Oikya" loading="lazy"></div>
                        <div class="person-body">
                            <h3 class="person-name">Dr. Upal Aditya Oikya</h3>
                            <span class="person-role">Co-Advisor</span>
                            <a href="https://www.linkedin.com/in/oikya/?originalSubdomain=bd" target="_blank" rel="noopener">LinkedIn profile</a>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <!-- ============================================================ Panel -->
        <section class="au-section" id="section_3">
            <div class="wrap">
                <div class="sec-head">
                    <p class="kicker"><span class="au-eq" aria-hidden="true"><i></i><i></i><i></i><i></i></span>Leadership</p>
                    <h2 class="sec-title au-gradient-text">Meet Presidential Panel</h2>
                </div>

                <!-- Tenures come from the tenures / tenure_people tables (current first) -->
                <div class="year-pick">
                    <label for="panelYearSelect">View panel by year</label>
                    <select id="panelYearSelect" class="year-select">
                        <?php foreach ($panelYears as $i => $py): ?>
                            <option value="<?= $i ?>"><?= htmlspecialchars($py['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <h3 id="panelYearTitle"><?= htmlspecialchars($currentPanel['label']) ?></h3>

                <!-- Current panel rendered by PHP; other years by js/site.js -->
                <div class="row justify-content-center panel-members-grid" id="panelMembersGrid">
                    <?php foreach ($currentPanel['panel'] as $member): ?>
                        <div class="<?= panelColumnClass(count($currentPanel['panel'])) ?>">
                            <div class="panel-member-card">
                                <div class="pmc-image-wrap">
                                    <img src="<?= htmlspecialchars($member['src']) ?>" alt="<?= htmlspecialchars($member['name']) ?>"
                                        loading="lazy" onerror="this.onerror=null; this.src='images/placeholder.svg';">
                                    <?php if ($member['facebook']): ?>
                                        <div class="pmc-overlay">
                                            <a href="<?= htmlspecialchars($member['facebook']) ?>" target="_blank" rel="noopener"
                                                aria-label="Facebook – <?= htmlspecialchars($member['name']) ?>"><ion-icon name="logo-facebook"></ion-icon></a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="pmc-body">
                                    <p class="pmc-name"><?= htmlspecialchars($member['name']) ?></p>
                                    <span class="pmc-role"><?= htmlspecialchars($member['position']) ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- ============================================================ Secretaries -->
        <section class="au-section" id="section_4">
            <div class="wrap">
                <div class="sec-head">
                    <p class="kicker"><span class="au-eq" aria-hidden="true"><i></i><i></i><i></i><i></i></span>The team</p>
                    <h2 class="sec-title au-gradient-text" id="sbSectionTitle">Meet Our Secretaries</h2>
                </div>
            </div>
            <div class="swiper sb-swiper" id="sbSwiper">
                <div class="swiper-wrapper" id="sbSwiperWrapper">
                    <?php foreach ($currentPanel['secretaries'] as $member): ?>
                        <div class="swiper-slide">
                            <img src="<?= htmlspecialchars($member['src']) ?>"
                                alt="<?= htmlspecialchars($member['name'] . ' - ' . $member['position']) ?>"
                                loading="lazy" onerror="this.onerror=null; this.src='images/placeholder.png';" />
                            <div class="overlay">
                                <a href="<?= htmlspecialchars($member['facebook'] ?: '#') ?>" target="_blank" rel="noopener"
                                    aria-label="Facebook – <?= htmlspecialchars($member['name']) ?>"><ion-icon name="logo-facebook"></ion-icon></a>
                            </div>
                            <div class="member-name">
                                <span class="name"><?= htmlspecialchars($member['name']) ?></span>
                                <span class="position"><?= htmlspecialchars($member['position'] ?: 'Secretary') ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="swiper-pagination"></div>
            </div>
        </section>
        <?= ad_slot('home_middle') ?>

        <!-- ============================================================ Past events -->
        <section class="au-section" id="section_5">
            <div class="wrap">
                <div class="sec-head">
                    <p class="kicker"><span class="au-eq" aria-hidden="true"><i></i><i></i><i></i><i></i></span>On stage</p>
                    <h2 class="sec-title au-gradient-text">Past Events</h2>
                </div>
                <div class="events-grid">
                    <?php
                    // The 5 newest events (Website Media → Past Events; site_event_cards() in site.php).
                    // Each card links to its write-up on past_events.php.
                    foreach (site_event_cards(5) as $ev): ?>
                        <a class="event-card" href="past_events.php#<?= htmlspecialchars($ev['anchor']) ?>">
                            <span class="event-card-bg" style="background-image: url('<?= htmlspecialchars($ev['image']) ?>'), url('images/placeholder.svg');"></span>
                            <span class="event-card-content">
                                <span class="event-date"><?= date('j F Y', strtotime($ev['date'])) ?></span>
                                <span class="event-title d-block"><?= htmlspecialchars($ev['title']) ?></span>
                                <span class="event-by"><?= date('l', strtotime($ev['date'])) ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                    <a href="past_events.php" class="see-more-button au-glass au-lined">
                        <span class="disc-icon"><i class="fas fa-arrow-right"></i></span>
                        <span class="see-more-text">View All</span>
                        <span class="see-more-subtext">Past Events</span>
                    </a>
                </div>
            </div>
        </section>

        <!-- ============================================================ Apply -->
        <section class="au-section" id="signup">
            <div class="wrap">
                <div class="sec-head">
                    <p class="kicker"><span class="au-eq" aria-hidden="true"><i></i><i></i><i></i><i></i></span>Join the club</p>
                    <h2 class="sec-title au-gradient-text">Apply Now</h2>
                    <p class="sec-lead">Become part of the BUCuC family. Every field is required.</p>
                </div>

                <div class="apply-wrap">
                    <div class="apply-panel au-glass au-lined" id="applyPanel">
                        <div class="apply-head">
                            <div class="tabs nav" role="tablist">
                                <button class="nav-link active" id="nav-ContactForm-tab" data-bs-toggle="tab" data-bs-target="#nav-ContactForm"
                                    type="button" role="tab" aria-controls="nav-ContactForm" aria-selected="true">Application Form</button>
                                <button class="nav-link" id="nav-ContactMap-tab" data-bs-toggle="tab" data-bs-target="#nav-ContactMap"
                                    type="button" role="tab" aria-controls="nav-ContactMap" aria-selected="false">Find Us</button>
                            </div>
                            <?php if ($signupEnabled): ?>
                                <div class="head-right" id="applyHeadProgress" <?= $signupTicket ? 'hidden' : '' ?>>
                                    <div class="progress-eq">
                                        <span id="progText" aria-live="polite">0 of 12 filled</span>
                                        <div class="prog" id="prog" aria-hidden="true">
                                            <i style="--h:55%;--c:rgb(var(--c-accent2, 92 255 176))"></i><i style="--h:85%;--c:rgb(var(--c-accent-3df0c8, 61 240 200))"></i><i style="--h:45%;--c:rgb(var(--c-accent, 45 226 230))"></i><i style="--h:100%;--c:rgb(var(--c-accent-4ec0f5, 78 192 245))"></i>
                                            <i style="--h:70%;--c:rgb(var(--c-primary, 139 92 255))"></i><i style="--h:90%;--c:rgb(var(--c-primary-b55cf5, 181 92 245))"></i><i style="--h:50%;--c:rgb(var(--c-accent3-e052e8, 224 82 232))"></i><i style="--h:80%;--c:rgb(var(--c-accent3, 255 79 216))"></i>
                                        </div>
                                    </div>
                                    <button class="sound" id="soundBtn" type="button" aria-pressed="false" title="Play soft notes as you type">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
                                        <span>Typing sound off</span>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="nav-ContactForm" role="tabpanel" aria-labelledby="nav-ContactForm-tab">

                                <?php if ($signupError): ?>
                                    <div class="banner bad" id="applyBanner" role="alert"><?= htmlspecialchars($signupError) ?></div>
                                <?php endif; ?>

                                <?php if ($signupTicket): ?>
                                    <div class="done" id="applyDone" data-id="<?= htmlspecialchars($signupTicket['id']) ?>">
                                        <h3 tabindex="-1">You’re in the queue, <?= htmlspecialchars(explode(' ', $signupTicket['name'])[0]) ?>.</h3>
                                        <p><?= htmlspecialchars($signupSuccess ?: 'Your membership application has been submitted.') ?></p>
                                        <div class="ticket">
                                            <div class="t-main">
                                                <p class="t-event">BUCuC membership application</p>
                                                <strong class="t-name"><?= htmlspecialchars($signupTicket['name']) ?></strong>
                                                <div class="t-row">
                                                    <div><small>University ID</small><b><?= htmlspecialchars($signupTicket['id']) ?></b></div>
                                                    <div><small>Semester</small><b><?= htmlspecialchars($signupTicket['semester']) ?></b></div>
                                                    <div><small>First choice</small><b><?= htmlspecialchars($signupTicket['first']) ?></b></div>
                                                </div>
                                            </div>
                                            <div class="t-stub"><div class="wave" id="wave" aria-hidden="true"></div></div>
                                        </div>
                                        <?php if ($signupEnabled): ?>
                                            <button type="button" class="au-ghost" id="againBtn">Submit another application</button>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($signupSuccess): ?>
                                    <div class="banner good" role="status"><?= htmlspecialchars($signupSuccess) ?></div>
                                <?php endif; ?>

                                <?php if ($signupEnabled): ?>
                                    <form id="signup-form" name="signupForm" action="../backend/Action/signup_handler.php" method="POST" novalidate <?= $signupTicket ? 'hidden' : '' ?>>
                                        <input type="hidden" name="form_token" value="<?= htmlspecialchars(form_token('signup')) ?>">
                                        <!-- Spam trap: people never see or fill this field; bots that fill every field are ignored -->
                                        <div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden">
                                            <label>Leave this empty <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label>
                                        </div>
                                        <div class="fields">
                                            <?php
                                            $tick = '<svg class="tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5 10 17.5 19 7"/></svg><span class="bar"></span>';
                                            // [name, label, type, placeholder, half-width, autocomplete, extra attributes]
                                            $textFields = [
                                                ['signup-name', 'Full name', 'text', 'Your full name', false, 'name', 'maxlength="100"'],
                                                ['signup-id', 'University ID', 'text', '24141091', true, 'off', 'inputmode="numeric" maxlength="20"'],
                                                ['signup-department', 'Department', 'text', 'CSE', true, 'off', 'maxlength="100"'],
                                                ['signup-main-email', 'Personal email', 'email', 'you@gmail.com', false, 'email', 'inputmode="email" autocapitalize="none" spellcheck="false" maxlength="120"'],
                                                ['signup-gsuite-email', 'G-Suite email', 'email', 'yourname@g.bracu.ac.bd', false, 'off', 'inputmode="email" autocapitalize="none" spellcheck="false" maxlength="120"'],
                                                ['signup-phone', 'Phone number', 'tel', '01XXXXXXXXX', true, 'tel', 'inputmode="tel" maxlength="20"'],
                                            ];
                                            foreach ($textFields as [$name, $label, $type, $ph, $half, $ac, $extra]): ?>
                                                <div class="field<?= $half ? ' half' : '' ?>">
                                                    <label for="<?= $name ?>"><?= $label ?></label>
                                                    <div class="control">
                                                        <input id="<?= $name ?>" name="<?= $name ?>" type="<?= $type ?>" placeholder="<?= $ph ?>" autocomplete="<?= $ac ?>"
                                                            value="<?= old($name) ?>" required aria-describedby="<?= $name ?>-err" <?= $extra ?>>
                                                        <?= $tick ?>
                                                    </div>
                                                    <p class="err" id="<?= $name ?>-err"></p>
                                                </div>
                                            <?php endforeach; ?>

                                            <div class="field half">
                                                <label for="signup-dob">Date of birth</label>
                                                <div class="control">
                                                    <input id="signup-dob" name="signup-dob" type="date" min="1950-01-01" max="<?= date('Y-m-d') ?>"
                                                        autocomplete="bday" value="<?= old('signup-dob') ?>" required aria-describedby="signup-dob-err">
                                                    <?= $tick ?>
                                                </div>
                                                <p class="err" id="signup-dob-err"></p>
                                            </div>

                                            <div class="field half">
                                                <label for="signup-semester">Current semester</label>
                                                <div class="control">
                                                    <select id="signup-semester" name="signup-semester" required aria-describedby="signup-semester-err">
                                                        <option value="">Select semester</option>
                                                        <?php foreach (['1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th'] as $sem): ?>
                                                            <option value="<?= $sem ?>"<?= oldSelected('signup-semester', $sem) ?>><?= $sem ?></option>
                                                        <?php endforeach; ?>
                                                        <option value="10th+"<?= oldSelected('signup-semester', '10th+') ?>>10th or above</option>
                                                    </select>
                                                    <?= $tick ?>
                                                </div>
                                                <p class="err" id="signup-semester-err"></p>
                                            </div>

                                            <div class="field half">
                                                <label for="signup-gender">Gender</label>
                                                <div class="control">
                                                    <select id="signup-gender" name="signup-gender" required aria-describedby="signup-gender-err">
                                                        <option value="">Select gender</option>
                                                        <?php foreach (['Male', 'Female', 'Other'] as $g): ?>
                                                            <option value="<?= $g ?>"<?= oldSelected('signup-gender', $g) ?>><?= $g ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <?= $tick ?>
                                                </div>
                                                <p class="err" id="signup-gender-err"></p>
                                            </div>

                                            <div class="field">
                                                <label for="signup-facebook">Facebook profile link</label>
                                                <div class="control">
                                                    <input id="signup-facebook" name="signup-facebook" type="text" inputmode="url" autocapitalize="none" spellcheck="false"
                                                        maxlength="300" placeholder="facebook.com/your.profile" autocomplete="off"
                                                        value="<?= old('signup-facebook') ?>" required aria-describedby="signup-facebook-err">
                                                    <?= $tick ?>
                                                </div>
                                                <p class="err" id="signup-facebook-err"></p>
                                            </div>

                                            <div class="field half">
                                                <label for="signup-first-dept">First department preference</label>
                                                <div class="control">
                                                    <select id="signup-first-dept" name="signup-first-dept" data-old="<?= old('signup-first-dept') ?>" required aria-describedby="signup-first-dept-err">
                                                        <option value="">Select department</option>
                                                    </select>
                                                    <?= $tick ?>
                                                </div>
                                                <p class="err" id="signup-first-dept-err"></p>
                                            </div>

                                            <div class="field half">
                                                <label for="signup-second-dept">Second department preference</label>
                                                <div class="control">
                                                    <select id="signup-second-dept" name="signup-second-dept" data-old="<?= old('signup-second-dept') ?>" required aria-describedby="signup-second-dept-err">
                                                        <option value="">Select department</option>
                                                    </select>
                                                    <?= $tick ?>
                                                </div>
                                                <p class="err" id="signup-second-dept-err"></p>
                                            </div>
                                        </div>

                                        <button type="submit" class="go" id="submitBtn">
                                            <svg class="play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4.5v15a1 1 0 0 0 1.5.86l12.4-7.5a1 1 0 0 0 0-1.72L8.5 3.64A1 1 0 0 0 7 4.5z"/></svg>
                                            <span class="sending" aria-hidden="true"><i></i><i></i><i></i></span>
                                            <span id="goText">Apply Now</span>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div class="closed">
                                        <div class="closed-icon"><i class="fas fa-user-slash"></i></div>
                                        <h3>Application Form expired for current Semester</h3>
                                        <p>The membership registration system is temporarily disabled. Please check back next semester or
                                            contact the admin team at <a href="mailto:hr.bucuc@gmail.com">hr.bucuc@gmail.com</a>.</p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="tab-pane fade" id="nav-ContactMap" role="tabpanel" aria-labelledby="nav-ContactMap-tab">
                                <iframe class="map-frame" title="BRAC University on Google Maps"
                                    src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d1100.1434944730236!2d90.42450743390042!3d23.77246450986446!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3755c7715a40c603%3A0xec01cd75f33139f5!2sBRAC%20University!5e1!3m2!1sen!2sbd!4v1745998853929!5m2!1sen!2sbd"
                                    allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- ============================================================ Footer -->
        <?= ad_slot('home_footer') ?>
    <footer class="site-footer au-paper" id="footer">
        <div class="wrap">
            <div class="foot-grid">
                <div>
                    <a class="foot-brand" href="index.php"><img src="images/logopng.png" alt="BUCuC logo"><span>BRAC University<br>Cultural Club</span></a>
                    <p class="foot-tag">Work • Bond • Glow</p>
                    <ul class="socials" style="margin-top: 16px;">
                        <li><a href="https://www.facebook.com/bucuc" target="_blank" rel="noopener" aria-label="Facebook page"><i class="fab fa-facebook-f"></i></a></li>
                        <li><a href="https://www.facebook.com/bucucarchive" target="_blank" rel="noopener" aria-label="BUCuC Archive on Facebook"><i class="fas fa-box-archive"></i></a></li>
                        <li><a href="https://www.youtube.com/@bracuniversityculturalclub717" target="_blank" rel="noopener" aria-label="YouTube"><i class="fab fa-youtube"></i></a></li>
                        <li><a href="https://www.instagram.com/bucuclive/" target="_blank" rel="noopener" aria-label="Instagram"><i class="fab fa-instagram"></i></a></li>
                    </ul>
                </div>
                <div>
                    <h4>Links</h4>
                    <ul>
                        <li><a href="#section_1">Home</a></li>
                        <li><a href="#section_2">About</a></li>
                        <li><a href="#section_3">BUCuC Panel</a></li>
                        <li><a href="#section_4">Secretaries</a></li>
                        <li><a href="#section_5">Past Events</a></li>
                        <li><a href="performances.php">Performance videos</a></li>
                        <li><a href="past_events.php">All event stories</a></li>
                    </ul>
                </div>
                <div>
                    <h4>Have a question?</h4>
                    <p><a href="mailto:club.bucuc@g.bracu.ac.bd">club.bucuc@g.bracu.ac.bd</a></p>
                    <p><a href="mailto:hr.bucuc@gmail.com">hr.bucuc@gmail.com</a></p>
                </div>
                <div>
                    <h4>Location</h4>
                    <p>Kha 224 Pragati Sarani, Merul Badda, Dhaka 1212</p>
                    <p><a href="https://www.google.com/maps/place/BRAC+University/@23.7724645,90.4245074,17z" target="_blank" rel="noopener">Open in Google Maps <i class="fas fa-arrow-up-right-from-square" style="font-size: 12px;"></i></a></p>
                </div>
            </div>
            <div class="foot-bottom">
                <span>Copyright © <?= date('Y') ?> BRAC University Cultural Club</span>
                <span><a href="admin-login.php">Admin Login</a></span>
            </div>
        </div>
        <div id="very-bottom"></div>
    </footer>

    <script src="js/jquery.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script src="js/click-scroll.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/swiper@11.1.14/swiper-bundle.min.js"></script>
    <script>
        // Every panel year (tenure tables), image URLs already encoded by PHP
        window.BUCUC_PANEL_YEARS = <?= json_encode($panelYears, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
    </script>
    <script src="js/site.js?v=<?= filemtime(__DIR__ . '/js/site.js') ?>"></script>
</body>

</html>

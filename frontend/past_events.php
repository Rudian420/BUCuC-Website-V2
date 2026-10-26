<?php
/**
 * Past events page. Serves past_events.html (the page design) with every event from the
 * database (Admin → Website Media → Past Events), newest first. Closed during a lockdown.
 * frontend/.htaccess sends old links to past_events.html here; the imported events keep their old
 * anchors (#event-6, #country-music, …), so old links and QR codes still land on the right story.
 */
require_once __DIR__ . '/../backend/includes/auth.php';
site_guard_public();

$html = file_get_contents(__DIR__ . '/past_events.html');
$h = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES);

$cards = '';
foreach (site_events_all() as $i => $e) {
    $date = strtotime($e['event_date']);
    $highlights = array_filter(array_map('trim', preg_split('/\r?\n/', (string) $e['highlights'])));
    $paragraphs = array_filter(array_map('trim', preg_split('/\r?\n\s*\r?\n/', (string) $e['description'])));
    $stats = event_stats($e['stats']);
    $delay = 100 + 50 * min($i, 4);
    ob_start(); ?>

        <div id="<?= $h(event_anchor($e)) ?>" class="event-detail-card" data-aos="fade-up" data-aos-duration="1200" data-aos-delay="<?= $delay ?>">
            <div class="event-header">
                <div class="event-icon-large">
                    <span class="logo-mask-yellow" role="img" aria-label="BUCuC Logo"></span>
                </div>
                <div>
                    <h2 class="event-title-large"><?= $h($e['title']) ?></h2>
                    <div class="event-meta">
                        <i class="fas fa-calendar"></i><?= date('j F, Y, l', $date) ?><br>
                        <i class="fas fa-user"></i><?= $h($e['organizer'] ?: 'By BUCuC') ?><?php if ($e['place']): ?><br>
                        <i class="fas fa-map-marker-alt"></i><?= $h($e['place']) ?><?php endif; ?>
                    </div>
                </div>
            </div>

            <picture>
                <source srcset="<?= $h(media_webp_url($e['image'])) ?>" type="image/webp">
                <img src="<?= $h($e['image']) ?>" alt="<?= $h($e['title']) ?>" class="event-image<?= $e['image_class'] ? ' ' . $h($e['image_class']) : '' ?>" loading="<?= $i < 2 ? 'eager' : 'lazy' ?>" onerror="this.onerror=null;this.src='images/placeholder.svg'" data-aos="zoom-in" data-aos-duration="800" data-aos-delay="<?= $delay + 250 ?>">
            </picture>

            <?php if ($paragraphs): ?>
                <div class="event-description" data-aos="fade-up" data-aos-duration="800" data-aos-delay="<?= $delay + 300 ?>">
                    <?php foreach ($paragraphs as $para): ?><p><?= event_rich($para) ?></p><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($highlights): ?>
                <div class="event-highlights" data-aos="fade-up" data-aos-duration="800" data-aos-delay="<?= $delay + 400 ?>">
                    <h3 class="highlights-title">Event Highlights</h3>
                    <ul class="highlights-list">
                        <?php foreach ($highlights as $li): ?><li><?= event_rich($li) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($stats): ?>
                <div class="event-stats" data-aos="fade-up" data-aos-duration="800" data-aos-delay="<?= $delay + 500 ?>">
                    <?php foreach ($stats as [$num, $label]): ?>
                        <div class="stat-card">
                            <span class="stat-number"><?= $h($num) ?></span>
                            <div class="stat-label"><?= $h($label) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
<?php
    $cards .= ob_get_clean();
}
if ($cards === '') {
    $cards = '<div class="event-detail-card" style="text-align:center"><h2 class="event-title-large">No events yet</h2><p>Check back soon.</p></div>';
}

// favicon-32.png and apple-touch-icon are linked in past_events.html
$seoTags = site_render_seo_tags([
    'title'       => 'Past Events & Stories | BUCuC',
    'description' => 'Explore the archive, stories, and highlights of cultural festivals, concerts, drama nights, and signature celebrations by BRAC University Cultural Club.',
    'path'        => 'frontend/past_events.php',
    'image_path'  => 'frontend/images/og-events.jpg',
    'image_type'  => 'image/jpeg',
    'is_home'     => false,
]);
$html = str_replace('<' . 'title>Past Events | BUCuC</' . 'title>', '<' . "title>Past Events & Stories | BUCuC</title>\n" . $seoTags, $html);
$html = str_replace('</body>', site_render_analytics() . "</body>", $html);

echo str_replace('<!-- EVENTS: filled by past_events.php from the database (Website Media → Past Events) -->', $cards, $html);

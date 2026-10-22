<?php
/**
 * Public Performance Gallery: YouTube videos added in Admin → Performance Gallery.
 * Only thumbnails load with the page; the YouTube player (youtube-nocookie.com) loads when a
 * visitor presses play. Closed during a lockdown.
 */
require_once __DIR__ . '/../backend/includes/auth.php';
site_guard_public();
require_once __DIR__ . '/../backend/includes/gallery.php';

try {
    $videos = gallery_videos(db(), true);
} catch (\Throwable $e) {
    error_log('performances.php: ' . $e->getMessage());
    $videos = [];
}
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);

// Filters: only categories and years / tenures that have videos
$cats = [];
$whens = [];
foreach ($videos as $v) {
    $cats[$v['category']] = GALLERY_CATEGORIES[$v['category']] ?? 'Other';
    $w = gallery_when($v);
    if ($w !== '') {
        // order: tenures and years newest first (a tenure counts as its start year)
        $whens[$w] = $v['tenure_label'] ? (int) $v['tenure_year'] + 0.5 : (int) $w;
    }
}
$cats = array_intersect_key(GALLERY_CATEGORIES, $cats);
arsort($whens);
$hasUndated = (bool) array_filter($videos, fn($v) => gallery_when($v) === '');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#06040f">
    <meta name="description" content="Music videos, flash mobs and show highlights by BRAC University Cultural Club (BUCuC).">
    <title>Performances | BUCuC</title>
    <link rel="preconnect" href="https://i.ytimg.com">
    <link rel="stylesheet" href="css/all.min.css" />
    <link rel="stylesheet" href="css/aurora.css?v=<?= filemtime(__DIR__ . '/css/aurora.css') ?>">
    <link rel="stylesheet" href="css/gallery.css?v=<?= filemtime(__DIR__ . '/css/gallery.css') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="images/favicon-16.png">
    <link rel="apple-touch-icon" href="images/apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">
    <style>
        .pg-wrap { position: relative; z-index: 2; max-width: 1180px; margin: 0 auto; padding: 28px 16px 64px; }
        .pg-top { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 28px; }
        .pg-head { text-align: center; margin-bottom: 26px; }
        .pg-head .kicker { display: inline-block; font-size: .78rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: rgb(var(--c-highlight, 255 207 125)); margin-bottom: 8px; }
        .pg-head h1 { font-size: clamp(32px, 7vw, 56px); margin: 0 0 10px; line-height: 1.05; }
        .pg-head p { color: var(--mute); margin: 0 auto; max-width: 560px; }
        .pg-filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: center; margin-bottom: 26px; }
        .chips { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
        .chip { padding: 8px 16px; border-radius: 999px; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2); background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.04); color: var(--ink); font: 600 14px/1 var(--body, system-ui); cursor: pointer; }
        .chip[aria-pressed="true"] { background: linear-gradient(100deg, rgb(var(--c-accent2, 92 255 176)), rgb(var(--c-accent, 45 226 230)) 50%, rgb(var(--c-primary, 139 92 255))); color: rgb(var(--c-surface-0a0620, 10 6 32)); border-color: transparent; }
        .chip:focus-visible, .when:focus-visible { outline: 2px solid rgb(var(--c-fixed-ffffff, 255 255 255)); outline-offset: 2px; }
        .when { padding: 8px 14px; border-radius: 999px; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2); background: rgb(var(--c-surface-09061c, 9 6 28)); color: var(--ink); font: 600 14px/1.2 var(--body, system-ui); }
        .pg-empty { text-align: center; padding: 48px 20px; border-radius: 22px; background: var(--glass); border: 1px solid var(--glass-line); color: var(--mute); }
        .pg-empty i { font-size: 36px; color: rgb(var(--c-accent, 45 226 230)); margin-bottom: 12px; display: block; }
        .pg-empty b { display: block; color: var(--ink); font-size: 1.15rem; margin-bottom: 6px; }
        [hidden] { display: none !important; }
    </style>
</head>

<body class="au">
    <script src="js/aurora-sky.js"></script>
    <main class="pg-wrap">
        <div class="pg-top">
            <a href="index.php" class="au-ghost"><i class="fas fa-arrow-left" aria-hidden="true"></i>&nbsp; Back to Home</a>
            <a href="past_events.php" class="au-ghost">Past Events</a>
        </div>
        <header class="pg-head">
            <span class="kicker">On screen</span>
            <h1 class="au-gradient-text">Performance Gallery</h1>
            <p>Music videos, flash mobs and show highlights from BRAC University Cultural Club. Press play to watch.</p>
        </header>

        <?php if (!$videos): ?>
            <div class="pg-empty">
                <i class="fas fa-film" aria-hidden="true"></i>
                <b>No videos yet</b>
                Our performance videos will appear here soon. Meanwhile, see our <a href="past_events.php" style="color:inherit">past events</a>.
            </div>
        <?php else: ?>
            <div class="pg-filters">
                <?php if (count($cats) > 1): ?>
                    <div class="chips" role="group" aria-label="Filter by category">
                        <button type="button" class="chip" data-cat="" aria-pressed="true">All</button>
                        <?php foreach ($cats as $k => $label): ?><button type="button" class="chip" data-cat="<?= $h($k) ?>" aria-pressed="false"><?= $h($label) ?></button><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (count($whens) + ($hasUndated ? 1 : 0) > 1): ?>
                    <select id="when" class="when" aria-label="Filter by year or tenure">
                        <option value="">All years</option>
                        <?php foreach (array_keys($whens) as $w): ?><option value="<?= $h($w) ?>"><?= $h($w) ?></option><?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <div class="gv-grid" id="gvGrid">
                <?php foreach ($videos as $v): ?>
                    <article class="gv-card<?= $v['is_short'] ? ' short' : '' ?><?= $v['featured'] ? ' featured' : '' ?>" data-cat="<?= $h($v['category']) ?>" data-when="<?= $h(gallery_when($v)) ?>">
                        <div class="gv-frame">
                            <div class="gv-badges"><?php if ($v['featured']): ?><span class="gv-badge feat">★ Featured</span><?php endif; ?><?php if ($v['is_short']): ?><span class="gv-badge">Short</span><?php endif; ?></div>
                            <button type="button" class="gv-play" data-src="<?= $h(gallery_embed_url($v)) ?>" aria-label="Play: <?= $h($v['title']) ?>">
                                <img src="<?= $h($v['thumbnail']) ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                                <span class="gv-icon" aria-hidden="true"></span>
                            </button>
                        </div>
                        <div class="gv-body">
                            <span class="gv-cat"><?= $h(GALLERY_CATEGORIES[$v['category']] ?? 'Other') ?></span>
                            <h2 class="gv-title"><?= $h($v['title']) ?></h2>
                            <?php if ($m = gallery_meta($v)): ?><span class="gv-meta"><?= $h($m) ?></span><?php endif; ?>
                            <?php if ($v['description']): ?><p class="gv-desc"><?= nl2br($h($v['description'])) ?></p><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="pg-empty" id="noMatch" hidden style="margin-top:16px">
                <i class="fas fa-search" aria-hidden="true"></i>
                <b>No videos match this filter</b>
                Choose another category or year.
            </div>
        <?php endif; ?>
    </main>

    <script>
        (function () {
            // The YouTube player is created only when a visitor presses play (keeps the page light on phones)
            document.querySelectorAll('.gv-play').forEach(btn => btn.addEventListener('click', () => {
                const f = document.createElement('iframe');
                f.src = btn.dataset.src;
                f.title = btn.getAttribute('aria-label').replace(/^Play: /, '');
                f.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
                f.allowFullscreen = true;
                f.referrerPolicy = 'strict-origin-when-cross-origin';
                btn.closest('.gv-frame').querySelector('.gv-badges')?.remove();
                btn.replaceWith(f);
                f.focus();
            }));

            const cards = [...document.querySelectorAll('.gv-card')];
            const chips = [...document.querySelectorAll('.chip')];
            const when = document.getElementById('when');
            let cat = '';
            function apply() {
                const w = when ? when.value : '';
                let shown = 0;
                cards.forEach(c => {
                    const ok = (!cat || c.dataset.cat === cat) && (!w || c.dataset.when === w);
                    c.hidden = !ok;
                    if (ok) shown++;
                });
                const none = document.getElementById('noMatch');
                if (none) none.hidden = shown > 0;
            }
            chips.forEach(ch => ch.addEventListener('click', () => {
                cat = ch.dataset.cat;
                chips.forEach(x => x.setAttribute('aria-pressed', String(x === ch)));
                apply();
            }));
            if (when) when.addEventListener('change', apply);
        })();
    </script>
</body>

</html>

// YouTube player injection and category/filter for performances.php
(function () {
    // The YouTube player is created only when a visitor presses play (keeps the page light on phones)
    document.querySelectorAll('.gv-play').forEach(btn => btn.addEventListener('click', () => {
        const f = document.createElement('iframe');
        f.src = btn.dataset.src;
        f.title = (btn.getAttribute('aria-label') || '').replace(/^Play: /, '');
        f.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
        f.allowFullscreen = true;
        f.referrerPolicy = 'strict-origin-when-cross-origin';
        btn.closest('.gv-frame')?.querySelector('.gv-badges')?.remove();
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

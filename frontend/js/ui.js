/**
 * Shared page behaviour, added to every page by ui_inject() in backend/includes/auth.php.
 *
 * Busy buttons: when a form is sent the normal way (a new page loads), its submit button shows a
 * spinner and is disabled, so a slow save, upload or e-mail send can't be started twice.
 * Forms sent by the page's own script (fetch) handle their own button. Opt out with data-no-busy
 * on the form. Excel export links show "Preparing…" for a few seconds for the same reason.
 * Slide-out menus: the admin Menu bar (admin_menu_html()) opens and closes here.
 * Focus ring: a clear outline on whatever has keyboard focus, on every page.
 */
(function () {
    const SPIN = '<span class="ui-spin" aria-hidden="true"></span>';
    const css = document.createElement('style');
    css.textContent = '.ui-spin{display:inline-block;width:1em;height:1em;margin-right:.5em;vertical-align:-.125em;border:.15em solid currentColor;border-right-color:transparent;border-radius:50%;animation:ui-spin .7s linear infinite}@keyframes ui-spin{to{transform:rotate(360deg)}}@media (prefers-reduced-motion:reduce){.ui-spin{animation-duration:2s}}'
        // keyboard focus is always clearly visible (some older styles remove the outline)
        + 'body :is(a,button,input,select,textarea,summary,[tabindex],[role=button]):focus-visible{outline:3px solid rgb(var(--c-accent,45 226 230))!important;outline-offset:2px!important}';
    document.head.appendChild(css);

    function busy(btn) {
        if (!btn || btn.disabled || btn.dataset.busy) return;
        btn.dataset.busy = '1';
        btn.dataset.busyHtml = btn.innerHTML;
        btn.style.minWidth = btn.offsetWidth + 'px';   // no jump in size
        btn.setAttribute('aria-busy', 'true');
        if (btn.tagName === 'BUTTON') btn.insertAdjacentHTML('afterbegin', SPIN);
        btn.disabled = true;
    }
    function idle(btn) {
        if (!btn.dataset.busy) return;
        if (btn.tagName === 'BUTTON') btn.innerHTML = btn.dataset.busyHtml;
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
        delete btn.dataset.busy;
    }

    // Runs after the page's own submit handlers (window, bubble phase): a cancelled confirm() or
    // a script-handled form has defaultPrevented set and is left alone.
    window.addEventListener('submit', e => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-no-busy') || form.target === '_blank') return;
        if (e.defaultPrevented) return;
        if (form.dataset.sending) { e.preventDefault(); return; }   // second press while the first is on its way
        form.dataset.sending = '1';
        const btn = e.submitter || form.querySelector('button[type=submit], button:not([type]), input[type=submit]');
        // after this tick: the browser has already read the pressed button's name/value
        setTimeout(() => busy(btn), 0);
    });

    // Back/forward cache: a page shown again must not keep a spinning button
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('[data-busy]').forEach(idle);
        document.querySelectorAll('form[data-sending]').forEach(f => delete f.dataset.sending);
    });

    // Slide-out menus (the admin Menu bar): [data-drawer-open=id] opens, [data-drawer-close=id], Escape or a tapped
    // link closes. Closed menus are inert (not reachable with Tab); an open one keeps Tab inside it.
    function drawer(id, open, refocus) {
        const d = document.getElementById(id);
        if (!d) return;
        d.classList.toggle('open', open);
        d.inert = !open;
        document.querySelectorAll(`[data-drawer-close="${id}"]`).forEach(o => { if (o.classList.contains('am-overlay')) o.hidden = !open; });
        const btn = document.querySelector(`[data-drawer-open="${id}"]`);
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        // next frame: the menu has to be visible before it can take focus
        if (open) requestAnimationFrame(() => (d.querySelector('[aria-current="page"]') || d.querySelector('a, button')).focus());
        else if (refocus && btn) btn.focus();
    }
    document.addEventListener('click', e => {
        const t = e.target.closest ? e.target : null;
        const o = t && t.closest('[data-drawer-open]');
        if (o) { drawer(o.dataset.drawerOpen, true); return; }
        const c = t && t.closest('[data-drawer-close]');
        if (c) { drawer(c.dataset.drawerClose, false, true); return; }
        const a = t && t.closest('[data-drawer].open a');
        if (a) drawer(a.closest('[data-drawer]').id, false);
    });
    document.addEventListener('keydown', e => {
        const d = document.querySelector('[data-drawer].open');
        if (!d) return;
        if (e.key === 'Escape') { drawer(d.id, false, true); return; }
        if (e.key === 'Tab') {
            const f = [...d.querySelectorAll('a, button')];
            const i = f.indexOf(document.activeElement);
            if (e.shiftKey && i <= 0) { e.preventDefault(); f[f.length - 1].focus(); }
            else if (!e.shiftKey && (i === f.length - 1 || i === -1)) { e.preventDefault(); f[0].focus(); }
        }
    });

    // Excel downloads: no new page loads, so show "Preparing…" for a short time instead
    document.addEventListener('click', e => {
        const a = e.target.closest && e.target.closest('a[href*="export"]');
        if (!a || a.dataset.busy || e.ctrlKey || e.metaKey || e.shiftKey) return;
        a.dataset.busy = '1';
        const html = a.innerHTML;
        a.setAttribute('aria-busy', 'true');
        a.style.pointerEvents = 'none';
        a.innerHTML = SPIN + 'Preparing…';
        setTimeout(() => { a.innerHTML = html; a.style.pointerEvents = ''; a.removeAttribute('aria-busy'); delete a.dataset.busy; }, 4000);
    });

    // Sponsor ad view beacon: sends a background ping when an ad banner enters view
    if ('IntersectionObserver' in window) {
        const adObserver = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const el = entry.target;
                    const id = el.dataset.spId;
                    if (id && !el.dataset.spViewSent) {
                        el.dataset.spViewSent = '1';
                        try {
                            if (navigator.sendBeacon) {
                                navigator.sendBeacon('ad_beacon.php', new URLSearchParams({ id }));
                            } else {
                                fetch('ad_beacon.php', { method: 'POST', body: new URLSearchParams({ id }), keepalive: true });
                            }
                        } catch (e) {}
                    }
                    adObserver.unobserve(el);
                }
            });
        }, { threshold: 0.5 });
        document.querySelectorAll('.sp-band[data-sp-id]').forEach(el => adObserver.observe(el));
    }
})();

// Spot selection reload and form field persistence for sponsors.php
(function () {
    const spotEl = document.getElementById('spot');
    if (spotEl) {
        spotEl.addEventListener('change', e => {
            const f = e.target.form;
            if (!f) return;
            const p = new URLSearchParams({ spot: e.target.value });
            p.set(f.elements.id && f.elements.id.value ? 'edit' : 'new', (f.elements.id && f.elements.id.value) || '1');
            ['advertiser', 'link', 'starts_on', 'ends_on'].forEach(k => {
                if (f.elements[k]) sessionStorage.setItem('sp_' + k, f.elements[k].value);
            });
            location.href = 'sponsors.php?' + p + '#adForm';
        });
    }

    ['advertiser', 'link', 'starts_on', 'ends_on'].forEach(k => {
        try {
            const v = sessionStorage.getItem('sp_' + k);
            const inputEl = document.getElementById(k);
            if (v !== null && inputEl) {
                inputEl.value = v;
                sessionStorage.removeItem('sp_' + k);
            }
        } catch (e) { }
    });
})();

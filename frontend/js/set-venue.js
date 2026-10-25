// Venue manager: date bounds, data autofill, delete confirmation, and time validation
(function () {
    const vd = document.getElementById('venue_date');
    if (vd) {
        vd.min = new Date().toISOString().split('T')[0];
    }

    const dataEl = document.getElementById('latest-venue-data');
    if (dataEl) {
        try {
            const venue = JSON.parse(dataEl.textContent);
            if (venue) {
                Object.keys(venue).forEach(id => {
                    const el = document.getElementById(id);
                    if (el && venue[id] !== undefined && venue[id] !== null) el.value = venue[id];
                });
            }
        } catch (e) {}
    }

    function deleteVenue(venueId) {
        if (!confirm('Are you sure you want to delete this venue information?')) return;
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const csrf = csrfMeta ? csrfMeta.content : '';
        fetch('../backend/Action/delete_venue.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
            },
            body: JSON.stringify({ venue_id: venueId })
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (window.showNotification) showNotification(data.message, 'success');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    if (window.showNotification) showNotification(data.message, 'error');
                }
            })
            .catch(() => {
                if (window.showNotification) showNotification('An error occurred while deleting the venue.', 'error');
            });
    }

    document.querySelectorAll('.btn-delete-venue, [data-venue-id]').forEach(btn => {
        btn.addEventListener('click', e => {
            const id = btn.getAttribute('data-venue-id');
            if (id) {
                e.preventDefault();
                deleteVenue(id);
            }
        });
    });

    window.deleteVenue = deleteVenue;

    function toMinutes(value) {
        if (!value) return null;
        const m = /^(\d{1,2})(?::(\d{2}))?$/.exec(value.trim());
        return m ? parseInt(m[1], 10) * 60 + parseInt(m[2] || '0', 10) : null;
    }

    const form = document.getElementById('venueForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            const stEl = document.getElementById('venue_starting_time');
            const etEl = document.getElementById('venue_ending_time');
            const start = toMinutes(stEl ? stEl.value : '');
            const end = toMinutes(etEl ? etEl.value : '');

            if (start !== null && end !== null && start >= end) {
                e.preventDefault();
                if (window.showNotification) showNotification('Starting time must be earlier than ending time.', 'error');
            }
        });
    }
})();

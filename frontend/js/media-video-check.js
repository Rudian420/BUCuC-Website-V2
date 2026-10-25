// Quick video check in the browser before uploading. Images: js/image-crop.js
(function () {
    const el = document.getElementById('media-video-config');
    let maxBytes = 104857600;
    let maxSeconds = 120;
    if (el) {
        maxBytes = parseInt(el.getAttribute('data-max-bytes') || '104857600', 10);
        maxSeconds = parseInt(el.getAttribute('data-max-seconds') || '120', 10);
    }
    const VIDEO = { maxBytes, maxSeconds };

    document.querySelectorAll('input[type=file][data-video]').forEach(input => {
        input.addEventListener('change', () => {
            const out = input.parentNode.querySelector('.check-msg');
            const file = input.files[0];
            if (!out) return;
            out.textContent = '';
            out.className = 'check-msg';
            if (!file) return;
            const say = (ok, text) => { out.textContent = text; out.className = 'check-msg ' + (ok ? 'good' : 'bad'); };
            if (file.size > VIDEO.maxBytes) return say(false, `This video is ${(file.size / 1048576).toFixed(1)} MB; the limit is ${VIDEO.maxBytes / 1048576} MB.`);
            const v = document.createElement('video');
            v.preload = 'metadata';
            v.onloadedmetadata = () => {
                const r = v.videoWidth / v.videoHeight;
                if (v.duration > VIDEO.maxSeconds) say(false, `This video is ${v.duration.toFixed(1)} s; the limit is ${VIDEO.maxSeconds} s.`);
                else if (Math.abs(r - 16 / 9) / (16 / 9) > 0.02) say(false, `This video is ${v.videoWidth} × ${v.videoHeight}; it must be 16:9.`);
                else say(true, `${v.videoWidth} × ${v.videoHeight}, ${v.duration.toFixed(1)} s, ${(file.size / 1048576).toFixed(1)} MB. Looks good.`);
                URL.revokeObjectURL(v.src);
            };
            v.onerror = () => say(false, 'This browser could not read the video. It must be MP4 (H.264).');
            v.src = URL.createObjectURL(file);
        });
    });

    const confirmEl = document.getElementById('confirmDelete');
    if (confirmEl) {
        confirmEl.scrollIntoView({ block: 'center' });
    }
})();

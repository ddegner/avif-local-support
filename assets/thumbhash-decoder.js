/**
 * ThumbHash decoder for AVIF Local Support plugin.
 * Decodes ThumbHash strings to data URLs for LQIP (Low Quality Image Placeholders).
 * 
 * Based on the reference implementation from https://github.com/evanw/thumbhash
 * @license MIT
 */
(function () {
    'use strict';

    // Cached references for performance
    var doc = document,
        M = Math,
        PI = M.PI,
        cos = M.cos,
        round = M.round,
        max = M.max,
        min = M.min;

    // Cache for decoded data URLs
    var cache = {};

    // Reusable canvas for encoding
    var canvas, ctx;

    // The existing saved option is explicit; missing configuration fails open.
    var script = doc.currentScript,
        fadeEnabled = !!script && script.getAttribute('data-fade') === '1',
        initialWidth = window.innerWidth,
        initialHeight = window.innerHeight,
        initialView = window.scrollX === 0 && window.scrollY === 0,
        motion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

    // A late classification after scrolling/restoration or resizing is uncertain.
    function changedView() { initialView = false; }
    window.addEventListener('scroll', changedView, { once: true, passive: true });
    window.addEventListener('resize', changedView, { once: true, passive: true });

    function decode(hash) {
        // Decode base64 only once
        var bin = atob(hash),
            len = bin.length,
            bytes = new Uint8Array(len),
            i;

        for (i = 0; i < len; i++) bytes[i] = bin.charCodeAt(i);

        if (len < 5 || !(bytes[3] & 7)) throw new Error('Invalid ThumbHash header');

        var h24 = bytes[0] | bytes[1] << 8 | bytes[2] << 16,
            h16 = bytes[3] | bytes[4] << 8,
            l_dc = (h24 & 63) / 63,
            p_dc = ((h24 >> 6) & 63) / 31.5 - 1,
            q_dc = ((h24 >> 12) & 63) / 31.5 - 1,
            l_scale = ((h24 >> 18) & 31) / 31,
            hasAlpha = h24 >> 23 !== 0,
            p_scale = ((h16 >> 3) & 63) / 63,
            q_scale = ((h16 >> 9) & 63) / 63,
            isLandscape = h16 >> 15 !== 0,
            lx = max(3, isLandscape ? (hasAlpha ? 5 : 7) : h16 & 7),
            ly = max(3, isLandscape ? h16 & 7 : (hasAlpha ? 5 : 7)),
            a_dc = 1,
            a_scale = 0,
            ac_start = 5,
            ac_idx = 0;

        if (hasAlpha) {
            a_dc = (bytes[5] & 15) / 15;
            a_scale = (bytes[5] >> 4 & 15) / 15;
            ac_start = 6;
        }

        function readAC(nx, ny, scale) {
            var ac = [], cy, cx, nibble;
            for (cy = 0; cy < ny; cy++) {
                // Original triangular iteration: cx starts at 1 when cy=0, else 0
                // Continues while cx * ny < nx * (ny - cy)
                for (cx = cy ? 0 : 1; cx * ny < nx * (ny - cy); cx++) {
                    if (ac_start + (ac_idx >> 1) >= len) throw new Error('Truncated ThumbHash');
                    nibble = ac_idx & 1 ? bytes[ac_start + (ac_idx >> 1)] >> 4 : bytes[ac_start + (ac_idx >> 1)] & 15;
                    ac.push((nibble / 7.5 - 1) * scale);
                    ac_idx++;
                }
            }
            return ac;
        }

        var l_ac = readAC(lx, ly, l_scale),
            p_ac = readAC(3, 3, p_scale * 1.25),
            q_ac = readAC(3, 3, q_scale * 1.25),
            a_ac = hasAlpha ? readAC(5, 5, a_scale) : null;

        // Calculate dimensions from header. The aspect ratio is lx/ly using the
        // raw (unclamped) coefficient counts, per the reference implementation's
        // thumbHashToApproximateAspectRatio().
        var alpha = hasAlpha,
            landscape = isLandscape,
            _lx = landscape ? (alpha ? 5 : 7) : (h16 & 7),
            _ly = landscape ? (h16 & 7) : (alpha ? 5 : 7),
            ratio = _lx / _ly,
            w = round(ratio > 1 ? 32 : 32 * ratio),
            h = round(ratio > 1 ? 32 / ratio : 32),
            rgba = new Uint8Array(w * h * 4),
            x, y, l, p, q, a, j, cx, cy, fx, fy, fy_l, fy_p, fy_a, idx, b_, r, g, b,
            piH = PI / h,
            piW = PI / w;

        for (y = 0; y < h; y++) {
            var yFactor = (y + 0.5);

            // Precompute fy values for luminance
            fy_l = [];
            for (i = 0; i < ly; i++) fy_l[i] = cos(piH * yFactor * i);

            // Precompute fy values for PQ (always 3)
            fy_p = [cos(0), cos(piH * yFactor), cos(piH * yFactor * 2)];

            // Precompute fy values for alpha if needed
            if (hasAlpha) {
                fy_a = [];
                for (i = 0; i < 5; i++) fy_a[i] = cos(piH * yFactor * i);
            }

            for (x = 0; x < w; x++) {
                var xFactor = (x + 0.5);
                l = l_dc; p = p_dc; q = q_dc; a = a_dc;

                // Precompute fx values (matching original's approach)
                var fx_arr = [], n_fx = max(lx, hasAlpha ? 5 : 3);
                for (i = 0; i < n_fx; i++) fx_arr[i] = cos(piW * xFactor * i);

                // Luminance - triangular iteration matching original
                j = 0;
                for (cy = 0; cy < ly; cy++) {
                    var fy2_l = fy_l[cy] * 2;
                    for (cx = cy ? 0 : 1; cx * ly < lx * (ly - cy); cx++, j++) {
                        l += l_ac[j] * fx_arr[cx] * fy2_l;
                    }
                }

                // P and Q channels - triangular iteration matching original
                j = 0;
                for (cy = 0; cy < 3; cy++) {
                    var fy2_p = fy_p[cy] * 2;
                    for (cx = cy ? 0 : 1; cx < 3 - cy; cx++, j++) {
                        var f_pq = fx_arr[cx] * fy2_p;
                        p += p_ac[j] * f_pq;
                        q += q_ac[j] * f_pq;
                    }
                }

                // Alpha channel - triangular iteration matching original
                if (hasAlpha) {
                    j = 0;
                    for (cy = 0; cy < 5; cy++) {
                        var fy2_a = fy_a[cy] * 2;
                        for (cx = cy ? 0 : 1; cx < 5 - cy; cx++, j++) {
                            a += a_ac[j] * fx_arr[cx] * fy2_a;
                        }
                    }
                }

                // Convert LPQ to RGB
                b_ = l - 2 / 3 * p;
                r = (3 * l - b_ + q) / 2;
                g = r - q;
                b = b_;

                idx = (y * w + x) * 4;
                rgba[idx] = max(0, min(255, round(255 * r)));
                rgba[idx + 1] = max(0, min(255, round(255 * g)));
                rgba[idx + 2] = max(0, min(255, round(255 * b)));
                rgba[idx + 3] = max(0, min(255, round(255 * a)));
            }
        }

        return { w: w, h: h, rgba: rgba };
    }

    function toDataURL(hash) {
        if (cache[hash]) return cache[hash];
        try {
            var d = decode(hash);
            // Reuse canvas
            if (!canvas) {
                canvas = doc.createElement('canvas');
                ctx = canvas.getContext('2d');
            }
            canvas.width = d.w;
            canvas.height = d.h;
            var img = ctx.createImageData(d.w, d.h);
            img.data.set(d.rgba);
            ctx.putImageData(img, 0, 0);
            return cache[hash] = canvas.toDataURL();
        } catch (e) {
            return '';
        }
    }

    function apply(img) {
        if (img._th) return;
        img._th = 1;

        var hash = img.getAttribute('data-thumbhash');
        if (!hash || (img.complete && img.naturalWidth)) return;
        var url = toDataURL(hash);
        if (!url) return;

        var el = img.closest('picture') || img,
            isPicture = el.tagName === 'PICTURE',
            s = el.style,
            saved = [],
            done = false,
            loaded = false,
            hidden = false,
            revealing = false,
            classified = false,
            eligible = false,
            previewReady = false,
            generation = 0,
            timer = 0,
            observer,
            preview;

        // Capture the first rendered position, before even installing the preview.
        // A once-visible or unmeasurable image can never become a fade candidate.
        var initiallyBelow = belowViewport(),
            highPriority = (img.getAttribute('fetchpriority') || '').toLowerCase() === 'high',
            photoRendering = window.getComputedStyle(img).imageRendering;

        function setStyle(name, value, target) {
            target = target || s;
            var old = target.getPropertyValue(name), priority = target.getPropertyPriority(name);
            target.setProperty(name, value);
            saved.push({ style: target, name: name, value: old, priority: priority, applied: target.getPropertyValue(name) });
        }

        function cancelFade() {
            generation++;
            eligible = false;
            hidden = revealing = false;
            clearTimeout(timer);
            img.removeEventListener('transitionend', onTransition);
            img.removeEventListener('transitioncancel', onTransition);
            img.classList.remove('thumbhash-pending', 'thumbhash-revealing');
        }

        // Idempotent fail-open cleanup. Stale decodes/timers cannot hide the image
        // again or erase styles subsequently changed by another owner.
        function finish() {
            if (done) return;
            done = true;
            cancelFade();
            img.removeEventListener('load', onReady);
            img.removeEventListener('error', finish);
            doc.removeEventListener('DOMContentLoaded', classify);
            window.removeEventListener('resize', onResize);
            if (motion && motion.removeEventListener) motion.removeEventListener('change', onMotion);
            if (observer) observer.disconnect();
            if (preview) preview.onload = preview.onerror = null;
            el.classList.remove('thumbhash-bg');
            saved.forEach(function (item) {
                var target = item.style;
                if (target.getPropertyValue(item.name) !== item.applied || target.getPropertyPriority(item.name)) return;
                if (item.value) target.setProperty(item.name, item.value, item.priority);
                else target.removeProperty(item.name);
            });
        }

        function onTransition(event) {
            if (event.target === img && event.propertyName === 'opacity' && revealing) finish();
        }

        function onMotion() {
            if (motion.matches && revealing) finish();
        }

        function reveal() {
            if (done) return;
            if (!hidden || (motion && motion.matches)) { finish(); return; }
            // Commit the starting opacity even if load follows initialization quickly.
            if (window.getComputedStyle(img).opacity !== '0') { finish(); return; }
            revealing = true;
            img.addEventListener('transitionend', onTransition);
            img.addEventListener('transitioncancel', onTransition);
            img.classList.replace('thumbhash-pending', 'thumbhash-revealing');
            // transitionend normally cleans up. This bound covers detached nodes,
            // suppressed transitions and background tabs without relying on scrolling.
            timer = setTimeout(finish, 300);
        }

        function source() { return img.currentSrc || img.src; }

        function onReady() {
            if (done) return;
            loaded = true;
            var token = ++generation, selected = source();
            function current() { return !done && token === generation && selected === source(); }
            function decoded() { if (current()) reveal(); }
            function rejected() { if (current()) finish(); }
            // A responsive change may start another load during an older decode.
            if (img.decode) {
                try { img.decode().then(decoded, rejected); }
                catch (e) { rejected(); }
            } else decoded();
        }

        function onResize() {
            // Preserve the genuine preview if a replacement is still downloading.
            // Once visible, a photograph is never hidden again for another source.
            cancelFade();
            if (img.complete && img.naturalWidth) onReady();
        }

        function belowViewport() {
            var rect = img.getBoundingClientRect();
            return initialView && window.scrollX === 0 && window.scrollY === 0
                && window.innerWidth === initialWidth && window.innerHeight === initialHeight
                && img.isConnected && img.getClientRects().length && rect.width > 0 && rect.height > 0
                && isFinite(rect.top) && rect.top >= initialHeight
                && rect.left < initialWidth && rect.right > 0;
        }

        function arm() {
            if (done || !eligible || !previewReady || loaded || hidden) return;
            // It must still be pending and offscreen when the tiny preview is ready.
            if (img.complete || !belowViewport() || (img.getAttribute('fetchpriority') || '').toLowerCase() === 'high') return;
            var rect = img.getBoundingClientRect(), box = el.getBoundingClientRect(), background = window.getComputedStyle(el);
            if (background.backgroundImage.indexOf(url) === -1 || background.visibility !== 'visible' || background.opacity !== '1') return;
            if (box.width <= 0 || box.height <= 0 || box.top > rect.top || box.bottom < rect.bottom
                || box.left > rect.left || box.right < rect.right) return;
            hidden = true;
            img.classList.add('thumbhash-pending');
            if (window.getComputedStyle(img).opacity !== '0') cancelFade();
        }

        function classify() {
            if (done || classified) return;
            classified = true;
            // Do not guess layout with outstanding stylesheets. Read geometry before
            // adding opacity classes; this decision is never revisited on scroll.
            var sheets = doc.querySelectorAll('link[rel~="stylesheet"]'), i;
            for (i = 0; i < sheets.length; i++) {
                if (!sheets[i].disabled && !sheets[i].sheet
                    && (!sheets[i].media || !window.matchMedia || window.matchMedia(sheets[i].media).matches)) return;
            }
            var style = window.getComputedStyle(img);
            eligible = fadeEnabled && isPicture && initiallyBelow && !highPriority
                && (img.getAttribute('fetchpriority') || '').toLowerCase() !== 'high'
                && style.visibility === 'visible' && style.opacity === '1' && belowViewport();
            arm();
        }

        // Loading handlers are ready before any possibility of hiding.
        img.addEventListener('load', onReady);
        img.addEventListener('error', finish);
        window.addEventListener('resize', onResize, { passive: true });
        if (motion && motion.addEventListener) motion.addEventListener('change', onMotion);
        observer = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].type === 'childList' || mutations[i].target === img || mutations[i].target.tagName === 'SOURCE') {
                    cancelFade();
                    if (img.complete) {
                        if (img.naturalWidth) onReady();
                        else finish();
                    }
                    return;
                }
            }
        });
        // Ignore loading/sizes: the independent loading buffer temporarily changes
        // both, and neither determines initial-viewport eligibility.
        observer.observe(el, { attributes: true, attributeFilter: ['src', 'srcset', 'media', 'type'], childList: true, subtree: isPicture });

        setStyle('background-image', 'url(' + url + ')');
        setStyle('background-size', 'cover');
        setStyle('background-position', 'center');
        setStyle('background-repeat', 'no-repeat');
        el.classList.add('thumbhash-bg');
        // The optional pixelated-preview rule is inherited. Keep it from changing
        // the photograph's authored rendering during the crossfade.
        if (isPicture && window.getComputedStyle(img).imageRendering !== photoRendering) {
            setStyle('image-rendering', photoRendering, img.style);
        }
        if (isPicture && window.getComputedStyle(el).display === 'inline') setStyle('display', 'block');

        if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', classify, { once: true });
        else classify();

        // Decode the actual ThumbHash PNG before hiding the foreground. Its background
        // stays underneath until the foreground's own opacity transition completes.
        if (fadeEnabled && isPicture) {
            preview = new Image();
            function readyPreview() { if (!done) { previewReady = true; arm(); } }
            preview.onerror = finish;
            preview.onload = readyPreview;
            preview.src = url;
            if (preview.decode) {
                preview.onload = null;
                try { preview.decode().then(readyPreview, finish); }
                catch (e) { finish(); }
            } else if (preview.complete && preview.naturalWidth) readyPreview();
        }

        // Catch load/error events that raced setup, including cached failures.
        if (img.complete) {
            if (img.naturalWidth) onReady();
            else finish();
        }
    }

    function process(node) {
        if (!node) return;
        if (node.nodeType === 1 && node.tagName === 'IMG' && node.hasAttribute('data-thumbhash')) {
            apply(node);
        }
        if (node.querySelectorAll) {
            var imgs = node.querySelectorAll('img[data-thumbhash]'), i;
            for (i = 0; i < imgs.length; i++) apply(imgs[i]);
        }
    }

    // Start MutationObserver immediately
    new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
            var nodes = muts[i].addedNodes;
            for (var j = 0; j < nodes.length; j++) process(nodes[j]);
        }
    }).observe(doc.documentElement, { childList: true, subtree: true });

    // Handle cached/already-loaded pages
    if (doc.readyState !== 'loading') {
        process(doc.body || doc.documentElement);
    } else {
        doc.addEventListener('DOMContentLoaded', function () { process(doc.body); });
    }
})();

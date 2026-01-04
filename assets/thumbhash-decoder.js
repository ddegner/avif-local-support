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

    // Track page load time - images loading within first second skip the fade
    var pageLoadTime = Date.now();

    function decode(hash) {
        // Decode base64 only once
        var bin = atob(hash),
            len = bin.length,
            bytes = new Uint8Array(len),
            i;

        for (i = 0; i < len; i++) bytes[i] = bin.charCodeAt(i);

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

        // Calculate dimensions from header (inline aspect ratio calc)
        var alpha = ((bytes[0] | bytes[1] << 8 | bytes[2] << 16) >> 23 & 1) !== 0,
            landscape = ((bytes[3] | bytes[4] << 8) >> 15 & 1) !== 0,
            _lx = landscape ? (alpha ? 5 : 7) : (bytes[3] | bytes[4] << 8 >> 8) & 7,
            _ly = landscape ? (bytes[3] | bytes[4] << 8 >> 8) & 7 : (alpha ? 5 : 7),
            ratio = (landscape ? 32 : _lx) / (landscape ? _ly : 32),
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
        if (!hash) return;

        var url = toDataURL(hash);
        if (!url) return;

        var el = img.closest('picture') || img,
            s = el.style,
            applyStartTime = Date.now();

        s.backgroundImage = 'url(' + url + ')';
        s.backgroundSize = 'cover';
        s.backgroundPosition = 'center';
        s.backgroundRepeat = 'no-repeat';
        if (el.tagName === 'PICTURE') s.display = 'block';

        function clearBackground() {
            s.backgroundImage = s.backgroundSize = s.backgroundPosition = s.backgroundRepeat = '';
        }

        function startFade() {
            // Slow load - let CSS transition animate the fade
            if (el.classList) el.classList.remove('thumbhash-loading');
            // Delay clearing the background to allow for CSS fade transition (400ms)
            setTimeout(clearBackground, 450);
        }

        function instantReveal() {
            // Fast load - disable transition to prevent 500ms fade animation
            el.style.transition = 'none';
            if (el.classList) el.classList.remove('thumbhash-loading');
            clearBackground();
        }

        if (img.complete && img.naturalWidth) {
            // Already loaded (cached) - clear background and return, no animation needed
            clearBackground();
            return;
        }

        // Image still loading - add the class to hide it behind placeholder
        if (el.classList) el.classList.add('thumbhash-loading');

        function onReady() {
            // Check if image loaded quickly (within 2 seconds of page load)
            // Quick loads don't need fade - it would feel sluggish
            var timeSincePageLoad = Date.now() - pageLoadTime;
            var loadDuration = Date.now() - applyStartTime;

            if (timeSincePageLoad < 2000 || loadDuration < 200) {
                // Fast load - instant reveal, no fade needed
                instantReveal();
            } else {
                // Slow load - use smooth fade transition
                if (img.decode) {
                    img.decode().then(startFade).catch(startFade);
                } else {
                    startFade();
                }
            }
        }

        img.addEventListener('load', onReady, { once: true });
        img.addEventListener('error', instantReveal, { once: true });
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

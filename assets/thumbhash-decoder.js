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
                for (cx = 0; cx < nx; cx++) {
                    if (cx || cy) {
                        nibble = ac_idx & 1 ? bytes[ac_start + (ac_idx >> 1)] >> 4 : bytes[ac_start + (ac_idx >> 1)] & 15;
                        ac.push((nibble / 7.5 - 1) * scale);
                        ac_idx++;
                    }
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

                // Luminance
                j = 0;
                for (cy = 0; cy < ly; cy++) {
                    fy = fy_l[cy];
                    for (cx = 0; cx < lx; cx++) {
                        if (cx || cy) l += l_ac[j++] * cos(piW * xFactor * cx) * fy;
                    }
                }

                // P and Q channels
                j = 0;
                for (cy = 0; cy < 3; cy++) {
                    for (cx = 0; cx < 3; cx++) {
                        if (cx || cy) {
                            fx = cos(piW * xFactor * cx);
                            p += p_ac[j] * fx * fy_p[cy];
                            q += q_ac[j] * fx * fy_p[cy];
                            j++;
                        }
                    }
                }

                // Alpha channel
                if (hasAlpha) {
                    j = 0;
                    for (cy = 0; cy < 5; cy++) {
                        for (cx = 0; cx < 5; cx++) {
                            if (cx || cy) a += a_ac[j++] * cos(piW * xFactor * cx) * fy_a[cy];
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
            s = el.style;

        s.backgroundImage = 'url(' + url + ')';
        s.backgroundSize = 'cover';
        s.backgroundPosition = 'center';
        s.backgroundRepeat = 'no-repeat';
        if (el.tagName === 'PICTURE') s.display = 'block';

        if (el.classList) el.classList.add('thumbhash-loading');

        function startFade() {
            if (el.classList) el.classList.remove('thumbhash-loading');
            // Delay clearing the background to allow for CSS fade transition (500ms)
            setTimeout(function () {
                s.backgroundImage = s.backgroundSize = s.backgroundPosition = s.backgroundRepeat = '';
            }, 550);
        }

        function onReady() {
            // Use decode() to ensure image is fully decoded and ready to paint
            // This prevents the white flash between LQIP and actual image
            if (img.decode) {
                img.decode().then(startFade).catch(startFade);
            } else {
                startFade();
            }
        }

        if (img.complete && img.naturalWidth) {
            onReady();
        } else {
            img.addEventListener('load', onReady, { once: true });
            img.addEventListener('error', startFade, { once: true });
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

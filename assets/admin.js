'use strict';

(function () {
  function $(selector) { return document.querySelector(selector); }
  function $all(selector) { return Array.prototype.slice.call(document.querySelectorAll(selector)); }

  function activateTab(id) {
    var tabs = ['settings', 'tools', 'about'];
    tabs.forEach(function (t) {
      var link = $('#avif-local-support-tab-link-' + t);
      var panel = $('#avif-local-support-tab-' + t);
      if (!link || !panel) return;
      if (t === id) {
        link.classList.add('nav-tab-active');
        panel.classList.add('active');
      } else {
        link.classList.remove('nav-tab-active');
        panel.classList.remove('active');
      }
    });
  }

  function initTabs() {
    $all('.nav-tab-wrapper a').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var id = (this.getAttribute('href') || '').replace('#', '');
        if (!id) return;
        activateTab(id);
        try { history.replaceState(null, '', '#' + id); } catch (e2) {}
      });
    });
    window.addEventListener('hashchange', function () {
      var id = (location.hash || '#settings').replace('#', '');
      if (['settings', 'tools', 'about'].indexOf(id) === -1) id = 'settings';
      activateTab(id);
    });
    var initial = (location.hash || '#settings').replace('#', '');
    if (['settings', 'tools', 'about'].indexOf(initial) === -1) initial = 'settings';
    activateTab(initial);
  }

  function initStatus() {
    var btn = $('#avif-local-support-rescan');
    if (!btn || typeof AVIFLocalSupportData === 'undefined') return;
    var ajaxUrl = AVIFLocalSupportData.ajaxUrl;
    var nonce = AVIFLocalSupportData.scanNonce;
    function setLoading() {
      var totalEl = $('#avif-local-support-total-jpegs');
      var avifsEl = $('#avif-local-support-existing-avifs');
      var missingEl = $('#avif-local-support-missing-avifs');
      if (!totalEl || !avifsEl || !missingEl) return;
      var spinner = '<span class="spinner is-active"></span>';
      totalEl.innerHTML = avifsEl.innerHTML = missingEl.innerHTML = spinner;
    }
    function scan() {
      setLoading();
      var form = new URLSearchParams();
      form.append('action', 'aviflosu_scan_missing');
      form.append('_wpnonce', nonce);
      fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: form.toString()
      })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        var data = (json && json.success && json.data) ? json.data : {};
        var totalEl = $('#avif-local-support-total-jpegs');
        var avifsEl = $('#avif-local-support-existing-avifs');
        var missingEl = $('#avif-local-support-missing-avifs');
        if (!totalEl || !avifsEl || !missingEl) return;
        totalEl.textContent = String(data.total_jpegs || 0);
        avifsEl.textContent = String(data.existing_avifs || 0);
        missingEl.textContent = String(data.missing_avifs || 0);
      })
      .catch(function () {
        var totalEl = $('#avif-local-support-total-jpegs');
        var avifsEl = $('#avif-local-support-existing-avifs');
        var missingEl = $('#avif-local-support-missing-avifs');
        if (!totalEl || !avifsEl || !missingEl) return;
        totalEl.textContent = avifsEl.textContent = missingEl.textContent = '-';
      });
    }
    btn.addEventListener('click', scan);

    // No global polling controller needed; tools tab has its own progress/polling
  }

  function initAll() {
    initTabs();
    initStatus();

    // Convert-now button (AJAX queue + switch to Status with spinner + polling)
    var convertBtn = document.querySelector('#avif-local-support-convert-now');
    var spinner = document.querySelector('#avif-local-support-convert-spinner');
    var statusEl = document.querySelector('#avif-local-support-convert-status');
    var toolsProgress = document.querySelector('#avif-local-support-tools-progress');
    var toolsTotal = document.querySelector('#avif-local-support-tools-total');
    var toolsAvifs = document.querySelector('#avif-local-support-tools-avifs');
    var toolsMissing = document.querySelector('#avif-local-support-tools-missing');
    var toolsFill = document.querySelector('#avif-local-support-tools-progress-fill');
    var pollingTimerLocal = null;
    if (convertBtn && typeof AVIFLocalSupportData !== 'undefined') {
      convertBtn.addEventListener('click', function () {
        var ajaxUrl = AVIFLocalSupportData.ajaxUrl;
        var nonce = AVIFLocalSupportData.convertNonce;
        convertBtn.disabled = true;
        if (spinner) spinner.classList.add('is-active');
        if (statusEl) statusEl.textContent = 'Running…';
        var form = new URLSearchParams();
        form.append('action', 'aviflosu_convert_now');
        form.append('_wpnonce', nonce);
        fetch(ajaxUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: form.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (!(json && json.success)) {
            if (statusEl) statusEl.textContent = 'Failed';
            return;
          }
          // Show inline progress on this tab
          if (json && json.success) {
            if (toolsProgress) toolsProgress.style.display = '';
            // start local polling of counts and animate progress bar
            var prevMissing = null;
            var unchangedTicks = 0;
            var startTime = Date.now();
            var MAX_UNCHANGED_TICKS = 3; // stop if not changing
            var MAX_DURATION_MS = 5 * 60 * 1000; // 5 minutes safety
            function updateLocal() {
              var sform = new URLSearchParams();
              sform.append('action', 'aviflosu_scan_missing');
              sform.append('_wpnonce', AVIFLocalSupportData.scanNonce);
              fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: sform.toString()
              })
              .then(function (r) { return r.json(); })
              .then(function (json2) {
                var data = (json2 && json2.success && json2.data) ? json2.data : {};
                var total = data.total_jpegs || 0;
                var avifs = data.existing_avifs || 0;
                var missing = data.missing_avifs || 0;
                if (toolsTotal) toolsTotal.textContent = String(total);
                if (toolsAvifs) toolsAvifs.textContent = String(avifs);
                if (toolsMissing) toolsMissing.textContent = String(missing);
                if (toolsFill) {
                  var pct = total > 0 ? Math.round((avifs / total) * 100) : 0;
                  toolsFill.style.width = pct + '%';
                }
                // Stop conditions: finished, stalled, or too long
                if (missing === 0) {
                  if (statusEl) statusEl.textContent = 'Completed';
                  if (pollingTimerLocal) { window.clearInterval(pollingTimerLocal); pollingTimerLocal = null; }
                } else {
                  if (prevMissing !== null && missing === prevMissing) {
                    unchangedTicks++;
                  } else {
                    unchangedTicks = 0;
                  }
                  prevMissing = missing;
                  if (unchangedTicks >= MAX_UNCHANGED_TICKS || (Date.now() - startTime) > MAX_DURATION_MS) {
                    if (statusEl) statusEl.textContent = 'In progress…';
                    if (pollingTimerLocal) { window.clearInterval(pollingTimerLocal); pollingTimerLocal = null; }
                  }
                }
              })
              .catch(function () {});
            }
            if (pollingTimerLocal) window.clearInterval(pollingTimerLocal);
            // Poll more frequently for a snappier progress display
            pollingTimerLocal = window.setInterval(updateLocal, 1500);
            updateLocal();
          }
        })
        .catch(function () {
          if (statusEl) statusEl.textContent = 'Failed';
        })
        .finally(function () {
          if (spinner) spinner.classList.remove('is-active');
          convertBtn.disabled = false;
        });
      });
    }

    // Delete all AVIF files
    var deleteBtn = document.querySelector('#avif-local-support-delete-avifs');
    var deleteSpinner = document.querySelector('#avif-local-support-delete-spinner');
    var deleteStatus = document.querySelector('#avif-local-support-delete-status');
    if (deleteBtn && typeof AVIFLocalSupportData !== 'undefined') {
      deleteBtn.addEventListener('click', function () {
        if (!confirm('Delete all .avif files in uploads? This cannot be undone.')) {
          return;
        }
        var ajaxUrl = AVIFLocalSupportData.ajaxUrl;
        var nonce = AVIFLocalSupportData.deleteNonce;
        deleteBtn.disabled = true;
        if (deleteSpinner) deleteSpinner.classList.add('is-active');
        if (deleteStatus) deleteStatus.textContent = '';
        var form = new URLSearchParams();
        form.append('action', 'aviflosu_delete_all_avifs');
        form.append('_wpnonce', nonce);
        fetch(ajaxUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: form.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (json && json.success && json.data) {
            var d = json.data;
            if (deleteStatus) deleteStatus.textContent = 'Deleted ' + String(d.deleted || 0) + (d.failed ? (', failed ' + String(d.failed)) : '');
          } else {
            if (deleteStatus) deleteStatus.textContent = 'Failed';
          }
        })
        .catch(function () { if (deleteStatus) deleteStatus.textContent = 'Failed'; })
        .finally(function () {
          if (deleteSpinner) deleteSpinner.classList.remove('is-active');
          deleteBtn.disabled = false;
        });
      });
    }

    // Logs functionality
    var refreshLogsBtn = document.querySelector('#avif-local-support-refresh-logs');
    var copyLogsBtn = document.querySelector('#avif-local-support-copy-logs');
    var clearLogsBtn = document.querySelector('#avif-local-support-clear-logs');
    var logsSpinner = document.querySelector('#avif-local-support-logs-spinner');
    var logsContent = document.querySelector('#avif-local-support-logs-content');
    var copyStatus = document.querySelector('#avif-local-support-copy-status');

    if (copyLogsBtn && logsContent) {
      copyLogsBtn.addEventListener('click', function () {
        var text = logsContent.innerText;
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(text).then(function () {
            showCopyStatus();
          }, function () {
            fallbackCopy(text);
          });
        } else {
          fallbackCopy(text);
        }
      });
    }

    function fallbackCopy(text) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      try {
        document.execCommand('copy');
        showCopyStatus();
      } catch (e) {}
      document.body.removeChild(ta);
    }

    function showCopyStatus() {
      if (!copyStatus) return;
      copyStatus.style.display = 'inline';
      setTimeout(function () {
        copyStatus.style.display = 'none';
      }, 2000);
    }

    if (refreshLogsBtn && typeof AVIFLocalSupportData !== 'undefined') {
      refreshLogsBtn.addEventListener('click', function () {
        var ajaxUrl = AVIFLocalSupportData.ajaxUrl;
        var nonce = AVIFLocalSupportData.logsNonce;
        if (logsSpinner) logsSpinner.classList.add('is-active');
        
        var form = new URLSearchParams();
        form.append('action', 'aviflosu_get_logs');
        form.append('_wpnonce', nonce);
        
        fetch(ajaxUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: form.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (json && json.success && json.data && json.data.content && logsContent) {
            logsContent.innerHTML = json.data.content;
          }
        })
        .catch(function () {
          // Handle error silently
        })
        .finally(function () {
          if (logsSpinner) logsSpinner.classList.remove('is-active');
        });
      });
    }

    if (clearLogsBtn && typeof AVIFLocalSupportData !== 'undefined') {
      clearLogsBtn.addEventListener('click', function () {
        if (!confirm('Clear all logs? This cannot be undone.')) {
          return;
        }
        
        var ajaxUrl = AVIFLocalSupportData.ajaxUrl;
        var nonce = AVIFLocalSupportData.logsNonce;
        if (logsSpinner) logsSpinner.classList.add('is-active');
        
        var form = new URLSearchParams();
        form.append('action', 'aviflosu_clear_logs');
        form.append('_wpnonce', nonce);
        
        fetch(ajaxUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: form.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (json && json.success && logsContent) {
            logsContent.innerHTML = '<p class="description">No logs available.</p>';
          }
        })
        .catch(function () {
          // Handle error silently
        })
        .finally(function () {
          if (logsSpinner) logsSpinner.classList.remove('is-active');
        });
      });
    }

    // Run ImageMagick test
    var runTestBtn = document.querySelector('#avif-local-support-run-magick-test');
    var runTestSpinner = document.querySelector('#avif-local-support-magick-test-spinner');
    var runTestStatus = document.querySelector('#avif-local-support-magick-test-status');
    var runTestOutput = document.querySelector('#avif-local-support-magick-test-output');
    if (runTestBtn && typeof AVIFLocalSupportData !== 'undefined') {
      runTestBtn.addEventListener('click', function () {
        var ajaxUrl = AVIFLocalSupportData.ajaxUrl;
        var nonce = AVIFLocalSupportData.diagNonce;
        runTestBtn.disabled = true;
        if (runTestSpinner) runTestSpinner.classList.add('is-active');
        if (runTestStatus) runTestStatus.textContent = 'Running…';
        if (runTestOutput) { runTestOutput.style.display = 'none'; runTestOutput.textContent = ''; }
        var form = new URLSearchParams();
        form.append('action', 'aviflosu_run_magick_test');
        form.append('_wpnonce', nonce);
        fetch(ajaxUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: form.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (!json) return;
          if (json.success && json.data) {
            if (runTestStatus) runTestStatus.textContent = 'Exit code ' + String(json.data.code);
            var text = String(json.data.output || '');
            if (json.data.hint) { text += '\n\nHint: ' + String(json.data.hint); }
            if (runTestOutput) { runTestOutput.textContent = text; runTestOutput.style.display = ''; }
          } else if (json.data && json.data.message) {
            if (runTestStatus) runTestStatus.textContent = String(json.data.message);
          } else {
            if (runTestStatus) runTestStatus.textContent = 'Failed';
          }
        })
        .catch(function () {
          if (runTestStatus) runTestStatus.textContent = 'Failed';
        })
        .finally(function () {
          if (runTestSpinner) runTestSpinner.classList.remove('is-active');
          runTestBtn.disabled = false;
        });
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();



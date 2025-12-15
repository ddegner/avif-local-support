'use strict';

(function () {
  function $(selector) { return document.querySelector(selector); }
  function $all(selector) { return Array.prototype.slice.call(document.querySelectorAll(selector)); }

  var apiConfigured = false;
  function configureApiFetch() {
    if (apiConfigured) return;
    apiConfigured = true;
    if (typeof AVIFLocalSupportData === 'undefined') return;
    if (!window.wp || !wp.apiFetch) return;
    if (AVIFLocalSupportData.restUrl) {
      wp.apiFetch.use(wp.apiFetch.createRootURLMiddleware(AVIFLocalSupportData.restUrl));
    }
    if (AVIFLocalSupportData.restNonce) {
      wp.apiFetch.use(wp.apiFetch.createNonceMiddleware(AVIFLocalSupportData.restNonce));
    }
  }

  function apiFetch(options) {
    configureApiFetch();
    if (!window.wp || !wp.apiFetch) {
      return Promise.reject(new Error('wp.apiFetch unavailable'));
    }
    return wp.apiFetch(options);
  }

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
        try { history.replaceState(null, '', '#' + id); } catch (e2) { }
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
      apiFetch({ path: '/aviflosu/v1/scan-missing', method: 'POST' })
        .then(function (data) {
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

  function initCliSuggestions() {
    var buttons = document.querySelectorAll('.aviflosu-apply-suggestion');
    if (!buttons.length) return;
    buttons.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var targetId = this.getAttribute('data-target');
        var value = this.getAttribute('data-value');
        var target = document.getElementById(targetId);
        if (target) {
          target.value = value;
        }
      });
    });
  }

  function initAll() {
    initTabs();
    initStatus();
    initCliSuggestions();

    // Convert-now button (AJAX queue + simple counter progress)
    var convertBtn = document.querySelector('#avif-local-support-convert-now');
    var stopBtn = document.querySelector('#avif-local-support-stop-convert');
    var spinner = document.querySelector('#avif-local-support-convert-spinner');
    var statusEl = document.querySelector('#avif-local-support-convert-status');
    var progressEl = document.querySelector('#avif-local-support-convert-progress');
    var progressAvifs = document.querySelector('#avif-local-support-progress-avifs');
    var progressJpegs = document.querySelector('#avif-local-support-progress-jpegs');
    var pollingTimerLocal = null;

    function stopPolling() {
      if (pollingTimerLocal) {
        window.clearInterval(pollingTimerLocal);
        pollingTimerLocal = null;
      }
      if (stopBtn) stopBtn.style.display = 'none';
      if (progressEl) progressEl.style.display = 'none';
      if (spinner) spinner.classList.remove('is-active');
      if (convertBtn) convertBtn.disabled = false;
    }

    if (stopBtn && typeof AVIFLocalSupportData !== 'undefined') {
      stopBtn.addEventListener('click', function () {
        stopBtn.disabled = true;
        apiFetch({ path: '/aviflosu/v1/stop-convert', method: 'POST' })
          .then(function () {
            if (statusEl) statusEl.textContent = 'Stopped';
          })
          .catch(function () {
            if (statusEl) statusEl.textContent = 'Stop failed';
          })
          .finally(function () {
            stopPolling();
            stopBtn.disabled = false;
          });
      });
    }

    if (convertBtn && typeof AVIFLocalSupportData !== 'undefined') {
      convertBtn.addEventListener('click', function () {
        convertBtn.disabled = true;
        if (spinner) spinner.classList.add('is-active');
        if (statusEl) statusEl.textContent = 'Converting...';
        if (progressEl) progressEl.style.display = 'none';
        if (stopBtn) stopBtn.style.display = '';
        apiFetch({ path: '/aviflosu/v1/convert-now', method: 'POST' })
          .then(function () {
            // Show inline counter progress
            if (progressEl) progressEl.style.display = '';
            var prevMissing = null;
            var unchangedTicks = 0;
            var startTime = Date.now();
            var MAX_UNCHANGED_TICKS = 20;
            var MAX_DURATION_MS = 10 * 60 * 1000; // 10 minutes safety
            function updateLocal() {
              apiFetch({ path: '/aviflosu/v1/scan-missing', method: 'POST' })
                .then(function (data) {
                  var total = data.total_jpegs || 0;
                  var avifs = data.existing_avifs || 0;
                  var missing = data.missing_avifs || 0;

                  // Update top stats display too
                  var totalEl = document.querySelector('#avif-local-support-total-jpegs');
                  var avifsEl = document.querySelector('#avif-local-support-existing-avifs');
                  var missingEl = document.querySelector('#avif-local-support-missing-avifs');
                  if (totalEl) totalEl.textContent = String(total);
                  if (avifsEl) avifsEl.textContent = String(avifs);
                  if (missingEl) missingEl.textContent = String(missing);

                  // Update progress counter
                  if (progressAvifs) progressAvifs.textContent = String(avifs);
                  if (progressJpegs) progressJpegs.textContent = String(total);

                  // Stop conditions: finished, stalled, or too long
                  if (missing === 0) {
                    if (statusEl) statusEl.textContent = 'Complete!';
                    stopPolling();
                  } else {
                    if (prevMissing !== null && missing === prevMissing) {
                      unchangedTicks++;
                    } else {
                      unchangedTicks = 0;
                    }
                    prevMissing = missing;
                    if (unchangedTicks >= MAX_UNCHANGED_TICKS || (Date.now() - startTime) > MAX_DURATION_MS) {
                      if (statusEl) statusEl.textContent = 'Continuing in background...';
                      stopPolling();
                    }
                  }
                })
                .catch(function () { });
            }
            if (pollingTimerLocal) window.clearInterval(pollingTimerLocal);
            pollingTimerLocal = window.setInterval(updateLocal, 1500);
            updateLocal();
          })
          .catch(function () {
            if (statusEl) statusEl.textContent = 'Failed';
            stopPolling();
          })
          .finally(function () {
            // Don't disable button here - let stopPolling handle it
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
        deleteBtn.disabled = true;
        if (deleteSpinner) deleteSpinner.classList.add('is-active');
        if (deleteStatus) deleteStatus.textContent = '';
        apiFetch({ path: '/aviflosu/v1/delete-all-avifs', method: 'POST' })
          .then(function (d) {
            if (deleteStatus) deleteStatus.textContent = 'Deleted ' + String(d.deleted || 0) + (d.failed ? (', failed ' + String(d.failed)) : '');
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
    var logsOnlyErrorsToggle = document.querySelector('#avif-local-support-logs-only-errors');
    var copyStatus = document.querySelector('#avif-local-support-copy-status');
    var copySupportBtn = document.querySelector('#avif-local-support-copy-support');
    var copySupportStatus = document.querySelector('#avif-local-support-copy-support-status');

    function applyLogsFilter() {
      if (!logsContent || !logsOnlyErrorsToggle) return;
      // Ensure the container exists and is in the DOM before accessing
      if (!logsContent.parentNode || !document.body.contains(logsContent)) return;

      var onlyErrors = !!logsOnlyErrorsToggle.checked;
      var entries = logsContent.querySelectorAll('.avif-log-entry');
      for (var i = 0; i < entries.length; i++) {
        var el = entries[i];
        var status = '';
        if (el && el.getAttribute) {
          status = String(el.getAttribute('data-status') || '').toLowerCase();
        }
        if (!status && el && el.classList) {
          if (el.classList.contains('error')) status = 'error';
          else if (el.classList.contains('warning')) status = 'warning';
          else if (el.classList.contains('success')) status = 'success';
          else if (el.classList.contains('info')) status = 'info';
        }

        var isErrorish = (status === 'error');
        el.style.display = (onlyErrors && !isErrorish) ? 'none' : '';
      }
    }

    if (logsOnlyErrorsToggle) {
      logsOnlyErrorsToggle.addEventListener('change', applyLogsFilter);
      // Apply immediately on page load for the initial server-rendered logs.
      // Use setTimeout to ensure DOM is fully ready
      setTimeout(applyLogsFilter, 0);
    }

    if (copyLogsBtn) {
      copyLogsBtn.addEventListener('click', function () {
        // Re-query to ensure we have the current content state
        var contentEl = document.querySelector('#avif-local-support-logs-content');
        var text = contentEl ? contentEl.innerText : '';
        if (!text) return;

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

    if (copySupportBtn) {
      copySupportBtn.addEventListener('click', function () {
        var text = buildSupportDiagnosticsText();
        if (!text) return;

        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(text).then(function () {
            showCopySupportStatus();
          }, function () {
            fallbackCopySupport(text);
          });
        } else {
          fallbackCopySupport(text);
        }
      });
    }

    // Consolidated copy helpers
    function showStatusElement(element) {
      if (!element) return;
      element.style.display = 'inline';
      setTimeout(function () {
        element.style.display = 'none';
      }, 2000);
    }

    function fallbackCopyToClipboard(text, statusElement) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      try {
        document.execCommand('copy');
        showStatusElement(statusElement);
      } catch (e) { }
      document.body.removeChild(ta);
    }

    // Backward-compatible wrappers
    function fallbackCopy(text) {
      fallbackCopyToClipboard(text, copyStatus);
    }

    function fallbackCopySupport(text) {
      fallbackCopyToClipboard(text, copySupportStatus);
    }

    function showCopyStatus() {
      showStatusElement(copyStatus);
    }

    function showCopySupportStatus() {
      showStatusElement(copySupportStatus);
    }

    function buildSupportDiagnosticsText() {
      var panel = document.querySelector('.avif-support-panel');
      if (!panel) return '';

      // Clone to avoid mutating UI state. Force-open details so their text is included.
      var clone = panel.cloneNode(true);
      var details = clone.querySelectorAll('details');
      for (var i = 0; i < details.length; i++) {
        details[i].setAttribute('open', '');
      }

      // Remove UI-only elements
      var kill = clone.querySelectorAll('button, .spinner, script, style');
      for (var k = 0; k < kill.length; k++) {
        if (kill[k] && kill[k].parentNode) kill[k].parentNode.removeChild(kill[k]);
      }

      function clean(s) {
        return String(s || '').replace(/\s+/g, ' ').trim();
      }

      function indent(n) {
        var out = '';
        for (var j = 0; j < n; j++) out += '  ';
        return out;
      }

      var lines = [];

      function pushLine(s) {
        s = String(s || '');
        if (!s) return;
        lines.push(s);
      }

      function renderTable(tableEl, level) {
        var rows = tableEl.querySelectorAll('tr');
        for (var r = 0; r < rows.length; r++) {
          var tds = rows[r].querySelectorAll('td');
          if (!tds || tds.length < 2) continue;
          var label = clean(tds[0].textContent || '').replace(/:$/, '');
          var value = clean(tds[1].textContent || '');
          if (!label && !value) continue;
          pushLine(indent(level) + '- ' + (label ? (label + ': ') : '') + value);
        }
      }

      function renderList(listEl, level) {
        var items = listEl.querySelectorAll('li');
        for (var r = 0; r < items.length; r++) {
          var itemText = clean(items[r].textContent || '');
          if (!itemText) continue;
          pushLine(indent(level) + '- ' + itemText);
        }
      }

      function renderPre(preEl, level) {
        var t = (preEl && preEl.textContent) ? String(preEl.textContent) : '';
        // Keep original newlines for output blocks.
        t = t.replace(/\r/g, '').trim();
        if (!t) return;
        pushLine(indent(level) + '```');
        var preLines = t.split('\n');
        for (var i2 = 0; i2 < preLines.length; i2++) {
          pushLine(indent(level) + preLines[i2]);
        }
        pushLine(indent(level) + '```');
      }

      function renderElement(el, level) {
        if (!el || !el.tagName) return;
        var tag = el.tagName.toUpperCase();

        if (tag === 'H3') {
          var h = clean(el.textContent || '');
          if (h) {
            if (lines.length) pushLine('');
            pushLine('## ' + h);
          }
          return;
        }

        if (tag === 'DETAILS') {
          var summaryEl = el.querySelector('summary');
          var summary = clean(summaryEl ? summaryEl.textContent : '');
          if (summary) {
            if (lines.length) pushLine('');
            pushLine('### ' + summary);
          }
          // Render children except summary
          var kids = el.children;
          for (var d = 0; d < kids.length; d++) {
            if (kids[d].tagName && kids[d].tagName.toUpperCase() === 'SUMMARY') continue;
            renderElement(kids[d], level + 1);
          }
          return;
        }

        if (tag === 'TABLE') {
          renderTable(el, level);
          return;
        }

        if (tag === 'UL' || tag === 'OL') {
          renderList(el, level);
          return;
        }

        if (tag === 'PRE') {
          renderPre(el, level);
          return;
        }

        if (tag === 'P') {
          var p = clean(el.textContent || '');
          // Skip empty / duplicate button-ish lines.
          if (p) {
            pushLine(indent(level) + p);
          }
          return;
        }

        // Default: walk children
        var children = el.children;
        for (var c = 0; c < children.length; c++) {
          renderElement(children[c], level);
        }
      }

      // Render in visual order from the panel root
      var rootChildren = clone.children;
      for (var rc = 0; rc < rootChildren.length; rc++) {
        renderElement(rootChildren[rc], 0);
      }

      // Remove duplicate blank lines
      var compact = [];
      for (var li = 0; li < lines.length; li++) {
        var cur = lines[li];
        var prev = compact.length ? compact[compact.length - 1] : null;
        if (cur === '' && prev === '') continue;
        compact.push(cur);
      }
      var body = compact.join('\n').trim();
      if (!body) return '';

      var header = [
        'AVIF Local Support — Server Support diagnostics',
        'Generated: ' + (new Date()).toISOString(),
        'Page: ' + (window.location && window.location.href ? window.location.href : ''),
        ''
      ].join('\n');

      return header + body;
    }

    if (refreshLogsBtn && typeof AVIFLocalSupportData !== 'undefined') {
      refreshLogsBtn.addEventListener('click', function () {
        if (logsSpinner) logsSpinner.classList.add('is-active');
        apiFetch({ path: '/aviflosu/v1/logs', method: 'GET' })
          .then(function (json) {
            if (json && json.content && logsContent) {
              logsContent.innerHTML = json.content;
              applyLogsFilter();
            }
          })
          .catch(function () {
            if (logsContent) {
              logsContent.innerHTML = '<p class="description" style="color:#dc3232;">Failed to refresh logs. Please try again.</p>';
            }
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

        if (logsSpinner) logsSpinner.classList.add('is-active');
        apiFetch({ path: '/aviflosu/v1/logs/clear', method: 'POST' })
          .then(function () {
            if (logsContent) {
              logsContent.innerHTML = '<p class="description">No logs available.</p>';
            }
          })
          .catch(function () {
            alert('Failed to clear logs. Please try again.');
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
        runTestBtn.disabled = true;
        if (runTestSpinner) runTestSpinner.classList.add('is-active');
        if (runTestStatus) runTestStatus.textContent = 'Running…';
        if (runTestOutput) { runTestOutput.style.display = 'none'; runTestOutput.textContent = ''; }
        apiFetch({ path: '/aviflosu/v1/magick-test', method: 'POST' })
          .then(function (data) {
            if (!data) return;
            if (runTestStatus) runTestStatus.textContent = 'Exit code ' + String(data.code);
            var lines = [];
            if (data.selected_path) {
              lines.push('Selected binary: ' + String(data.selected_path) + (data.auto_selected ? ' (auto)' : ''));
            }
            if (data.define_strategy && data.define_strategy.namespace) {
              var s = data.define_strategy;
              lines.push(
                'Define strategy: ' + String(s.namespace) +
                ' (lossless=' + String(!!s.supports_lossless) +
                ', chroma=' + String(!!s.supports_chroma) +
                ', -depth=' + String(!!s.supports_depth) +
                ', bit-depth-define=' + String(!!s.supports_bit_depth_define) + ')'
              );
            }
            if (lines.length) { lines.push(''); }
            lines.push(String(data.output || ''));
            if (data.hint) { lines.push(''); lines.push('Hint: ' + String(data.hint)); }
            var text = lines.join('\n');
            if (runTestOutput) { runTestOutput.textContent = text; runTestOutput.style.display = ''; }
          })
          .catch(function (err) {
            if (runTestStatus) runTestStatus.textContent = (err && err.message) ? String(err.message) : 'Failed';
          })
          .finally(function () {
            if (runTestSpinner) runTestSpinner.classList.remove('is-active');
            runTestBtn.disabled = false;
          });
      });
    }

    // Test Conversion (AJAX)
    var testForm = document.querySelector('#avif-local-support-test-form');
    var testFile = document.querySelector('#avif-local-support-test-file');
    var testSubmit = document.querySelector('#avif-local-support-test-submit');
    var testSpinner = document.querySelector('#avif-local-support-test-spinner');
    var testStatus = document.querySelector('#avif-local-support-test-status');
    var testResults = document.querySelector('#avif-local-support-test-results');

    if (testForm && testFile && testSubmit && typeof AVIFLocalSupportData !== 'undefined') {
      testForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var file = testFile.files[0];
        if (!file) return;

        testSubmit.disabled = true;
        if (testSpinner) testSpinner.classList.add('is-active');
        if (testStatus) testStatus.textContent = 'Uploading...';
        if (testResults) testResults.innerHTML = '';

        function pollTestStatus(attachmentId, targetIndex) {
          apiFetch({
            path: '/aviflosu/v1/upload-test-status',
            method: 'POST',
            body: JSON.stringify({ attachment_id: attachmentId, target_index: targetIndex }),
            headers: { 'Content-Type': 'application/json' }
          })
            .then(function (json) {
              if (json && json.sizes) {
                renderTestResultsTable(json, testResults);

                // Update status text
                if (testStatus) {
                  testStatus.textContent = 'Converting...';
                }

                if (!json.complete) {
                  // Continue to next item
                  pollTestStatus(attachmentId, json.next_index);
                } else {
                  // Done
                  if (testStatus) testStatus.textContent = 'Done';
                  if (testSpinner) testSpinner.classList.remove('is-active');
                  testSubmit.disabled = false;
                }
              } else {
                // Unexpected response
                if (testStatus) testStatus.textContent = 'Failed to get status';
                if (testSpinner) testSpinner.classList.remove('is-active');
                testSubmit.disabled = false;
              }
            })
            .catch(function (err) {
              if (testStatus) testStatus.textContent = 'Error: ' + (err.message || 'Unknown');
              if (testSpinner) testSpinner.classList.remove('is-active');
              testSubmit.disabled = false;
            });
        }

        var formData = new FormData();
        formData.append('avif_local_support_test_file', file);
        apiFetch({ path: '/aviflosu/v1/upload-test', method: 'POST', body: formData })
          .then(function (json) {
            if (json && json.attachment_id) {
              // Initial render with whatever we have (likely all "Not created" if conversion on upload is off)
              renderTestResultsTable(json, testResults);

              // Update status immediately to indicate we are moving to conversion phase
              if (testStatus) testStatus.textContent = 'Converting...';

              // Start polling from index 0
              pollTestStatus(json.attachment_id, 0);
            } else {
              if (testStatus) testStatus.textContent = 'Upload failed';
              if (testSpinner) testSpinner.classList.remove('is-active');
              testSubmit.disabled = false;
            }
          })
          .catch(function (err) {
            var msg = (err && err.message) ? String(err.message) : 'Error';
            if (msg.toLowerCase().indexOf('json') !== -1 || msg.toLowerCase().indexOf('timeout') !== -1 || msg.toLowerCase().indexOf('fetch') !== -1) {
              msg = 'Upload timed out or failed. Check logs.';
            }
            if (testStatus) testStatus.textContent = msg;
            if (testSpinner) testSpinner.classList.remove('is-active');
            testSubmit.disabled = false;
          });
      });
    }

    // Engine Selection Logic
    var engineRadios = document.querySelectorAll('input[name="aviflosu_engine_mode"]');
    // The CLI settings are now in a separate field, we need to find its container row.
    // The field ID is 'aviflosu_cli_options' (the div wrapper) or we can look for the input 'aviflosu_cli_path'
    var cliOptionsDiv = document.querySelector('#aviflosu_cli_options');
    var subsamplingField = document.querySelector('#aviflosu_subsampling');
    var bitDepthField = document.querySelector('#aviflosu_bit_depth');

    // Helper to find parent row (tr) or container to hide
    function getContainer(el) {
      if (!el) return null;
      // The settings API usually wraps fields in td, then tr. 
      // We want to hide the whole tr.
      var p = el.closest('tr');
      return p ? p : el.parentElement;
    }

    function updateEngineVisibility() {
      var mode = 'auto';
      for (var i = 0; i < engineRadios.length; i++) {
        if (engineRadios[i].checked) {
          mode = engineRadios[i].value;
          break;
        }
      }

      // CLI Options: Show if Auto or CLI
      // We need to hide the entire row containing the CLI options
      var cliContainer = getContainer(cliOptionsDiv);
      if (cliContainer) {
        cliContainer.style.display = (mode === 'auto' || mode === 'cli') ? '' : 'none';
      }

      // Subsampling & Bit Depth: Show if Auto, CLI, or Imagick
      // Hide if GD (GD encoder doesn't support these in this plugin yet)
      var showAdvanced = (mode === 'auto' || mode === 'cli' || mode === 'imagick');

      var subContainer = getContainer(subsamplingField);
      if (subContainer) {
        subContainer.style.display = showAdvanced ? '' : 'none';
      }

      var bitContainer = getContainer(bitDepthField);
      if (bitContainer) {
        bitContainer.style.display = showAdvanced ? '' : 'none';
      }
    }

    if (engineRadios.length > 0) {
      for (var i = 0; i < engineRadios.length; i++) {
        engineRadios[i].addEventListener('change', updateEngineVisibility);
      }
      // Initial state
      updateEngineVisibility();
    }
  }

  function formatBytes(bytes, decimals) {
    if (bytes === 0) return '0 B';
    var k = 1024;
    var dm = decimals < 0 ? 0 : decimals;
    var sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
  }

  function renderTestResultsTable(data, container) {
    var html = '<p><strong>Test results for attachment:</strong> <a href="' + (data.edit_link || '#') + '" target="_blank">' + (data.title || data.attachment_id) + '</a></p>';
    html += '<table class="widefat striped" style="max-width:960px"><thead><tr>' +
      '<th>Size</th><th>Dimensions</th><th>JPEG</th><th>JPEG size</th><th>AVIF</th><th>AVIF size</th><th>Status</th>' +
      '</tr></thead><tbody>';

    if (data.sizes && data.sizes.length) {
      data.sizes.forEach(function (row) {
        var dims = (row.width && row.height) ? (row.width + '×' + row.height) : '';
        var jpegLink = row.jpeg_url ? '<a href="' + row.jpeg_url + '" target="_blank">View</a>' : '-';
        var avifLink = (row.status === 'success' && row.avif_url) ? '<a href="' + row.avif_url + '" target="_blank">View</a>' : '-';

        var status;
        switch (row.status) {
          case 'success':
            status = '<span style="color:#00a32a;font-weight:bold;">Converted</span>';
            break;
          case 'failure':
            status = '<span style="color:#d63638;font-weight:bold;">Failed</span>';
            if (row.error) {
              var errText = String(row.error).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
              status += '<br><span style="font-size:11px;color:#d63638;">' + errText + '</span>';
            }
            break;
          case 'processing':
            status = '<span class="spinner is-active" style="float:none;margin:0 4px -2px 0;"></span> Processing...';
            break;
          default:
            status = 'Pending';
        }

        html += '<tr>' +
          '<td>' + (row.name || '') + '</td>' +
          '<td>' + dims + '</td>' +
          '<td>' + jpegLink + '</td>' +
          '<td>' + formatBytes(row.jpeg_size || 0, 2) + '</td>' +
          '<td>' + avifLink + '</td>' +
          '<td>' + formatBytes(row.avif_size || 0, 2) + '</td>' +
          '<td>' + status + '</td>' +
          '</tr>';
      });
    } else {
      html += '<tr><td colspan="7">No sizes found.</td></tr>';
    }
    html += '</tbody></table>';
    container.innerHTML = html;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();



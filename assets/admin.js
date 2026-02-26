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

  function getI18n(key, fallback) {
    if (typeof AVIFLocalSupportData === 'undefined' || !AVIFLocalSupportData.strings) return fallback;
    var value = AVIFLocalSupportData.strings[key];
    if (typeof value !== 'string' || value === '') return fallback;
    return value;
  }

  function activateTab(id) {
    var tabs = ['settings', 'lqip', 'tools', 'about'];
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
      if (['settings', 'lqip', 'tools', 'about'].indexOf(id) === -1) id = 'settings';
      activateTab(id);
    });
    var initial = (location.hash || '#settings').replace('#', '');
    if (['settings', 'lqip', 'tools', 'about'].indexOf(initial) === -1) initial = 'settings';
    activateTab(initial);
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
    initCliSuggestions();

    function toggleHidden(element, hidden) {
      if (!element || !element.classList) return;
      if (hidden) element.classList.add('hidden');
      else element.classList.remove('hidden');
    }

    // Convert-now button (AJAX queue + simple counter progress)
    var convertBtn = document.querySelector('#avif-local-support-convert-now');
    var stopBtn = document.querySelector('#avif-local-support-stop-convert');
    var resultContainer = document.querySelector('#avif-local-support-result');
    var spinner = document.querySelector('#avif-local-support-spinner');
    var statusEl = document.querySelector('#avif-local-support-status');
    var progressEl = document.querySelector('#avif-local-support-convert-progress');
    var progressAvifs = document.querySelector('#avif-local-support-progress-avifs');
    var progressJpegs = document.querySelector('#avif-local-support-progress-jpegs');
    var insightStatus = document.querySelector('#avif-local-support-insight-status');
    var insightStarted = document.querySelector('#avif-local-support-insight-started');
    var insightHeartbeat = document.querySelector('#avif-local-support-insight-heartbeat');
    var insightProgress = document.querySelector('#avif-local-support-insight-progress');
    var insightThroughput = document.querySelector('#avif-local-support-insight-throughput');
    var insightEta = document.querySelector('#avif-local-support-insight-eta');
    var insightQuality = document.querySelector('#avif-local-support-insight-quality');
    var insightResults = document.querySelector('#avif-local-support-insight-results');
    var insightSize = document.querySelector('#avif-local-support-insight-size');
    var insightHealth = document.querySelector('#avif-local-support-insight-health');
    var insightQueue = document.querySelector('#avif-local-support-insight-queue');
    var insightError = document.querySelector('#avif-local-support-insight-error');
    var statsLoadingEl = document.querySelector('#avif-local-support-stats-loading');
    var missingFilesPanel = document.querySelector('#avif-local-support-missing-files-panel');
    var missingFilesModal = document.querySelector('#avif-local-support-missing-files-modal');
    var openMissingFilesBtn = document.querySelector('#avif-local-support-open-missing-files');
    var closeMissingFilesBtns = document.querySelectorAll('[data-close-missing-files-modal]');
    var missingFilesStatus = document.querySelector('#avif-local-support-missing-files-status');
    var missingFilesWrap = document.querySelector('#avif-local-support-missing-files-wrap');
    var missingFilesList = document.querySelector('#avif-local-support-missing-files-list');
    var missingFilesRefreshBtn = document.querySelector('#avif-local-support-refresh-missing-files');
    var applyRecommendedDefaultsBtn = document.querySelector('#avif-local-support-apply-recommended-defaults');
    var applyRecommendedDefaultsStatus = document.querySelector('#avif-local-support-recommended-defaults-status');
    var qualityInput = document.querySelector('#aviflosu_quality');
    var speedInput = document.querySelector('#aviflosu_speed');
    var pollingTimerLocal = null;
    var AVIF_PROGRESS_POLL_INTERVAL_MS = 5000;
    var INSIGHTS_POLL_INTERVAL_MS = 10000;
    var insightsTimer = null;
    var insightBaselineRunId = '';
    var insightBaselineExistingAvifs = null;

    function formatDateTime(ts) {
      if (!ts) return '-';
      var d = new Date(Number(ts) * 1000);
      if (isNaN(d.getTime())) return '-';
      return d.toLocaleString();
    }

    function formatRelative(ts) {
      if (!ts) return '-';
      var diff = Math.max(0, Math.floor(Date.now() / 1000) - Number(ts));
      if (diff < 60) return diff + 's ago';
      if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
      return Math.floor(diff / 3600) + 'h ago';
    }

    function formatDuration(seconds) {
      var s = Math.max(0, Math.floor(seconds || 0));
      if (s < 60) return s + 's';
      if (s < 3600) return Math.floor(s / 60) + 'm';
      var h = Math.floor(s / 3600);
      var m = Math.floor((s % 3600) / 60);
      return h + 'h ' + m + 'm';
    }

    function updateInsightsPanel() {
      if (!insightStatus) return;

      Promise.all([
        apiFetch({ path: '/aviflosu/v1/conversion-state', method: 'GET' }),
        apiFetch({ path: '/aviflosu/v1/scan-missing', method: 'POST' })
      ]).then(function (responses) {
        var conversion = responses[0] || {};
        var counts = responses[1] || {};
        var state = conversion.state || {};
        var lastRun = conversion.last_run || {};

        var isActive = !!conversion.active;
        var status = String((isActive ? (state.status || 'running') : (state.status || lastRun.status || 'idle'))).toLowerCase();
        if (insightStatus) insightStatus.textContent = status;

        var startedAt = Number(state.started_at || lastRun.started_at || 0);
        var heartbeatAt = Number(state.heartbeat_at || 0);
        var endedAt = Number(lastRun.ended_at || 0);

        var runId = startedAt > 0 ? String(startedAt) : 'none';
        if (runId !== insightBaselineRunId) {
          insightBaselineRunId = runId;
          insightBaselineExistingAvifs = null;
        }

        if (insightStarted) {
          insightStarted.textContent = startedAt ? (formatDateTime(startedAt) + ' (' + formatRelative(startedAt) + ')') : '-';
        }
        if (insightHeartbeat) {
          insightHeartbeat.textContent = heartbeatAt ? (formatDateTime(heartbeatAt) + ' (' + formatRelative(heartbeatAt) + ')') : '-';
        }

        var processed = Number(state.processed || lastRun.processed || 0);
        var existingAvifs = Number(counts.existing_avifs || 0);
        var total = Number(counts.total_jpegs || 0);
        if (isActive && insightBaselineExistingAvifs === null) {
          insightBaselineExistingAvifs = existingAvifs;
        }
        var createdByCoverage = (insightBaselineExistingAvifs !== null)
          ? Math.max(0, existingAvifs - Number(insightBaselineExistingAvifs))
          : 0;
        var pct = total > 0 ? ((existingAvifs / total) * 100) : 0;
        if (pct > 100) pct = 100;
        if (insightProgress) {
          insightProgress.textContent = existingAvifs + ' / ' + total + ' (' + pct.toFixed(1) + '%) • this run scanned ' +
            processed + ' files (attachments ' + Number(state.attachments || lastRun.attachments || 0) +
            ', uploads ' + Number(state.uploads || lastRun.uploads || 0) + ')';
        }

        var created = Number(state.created || lastRun.created || 0);
        if (created <= 0 && createdByCoverage > 0) {
          created = createdByCoverage;
        }

        var elapsedSec = startedAt ? Math.max(1, Math.floor(Date.now() / 1000) - startedAt) : 0;
        var createdThroughput = elapsedSec > 0 ? (created / (elapsedSec / 60)) : 0;
        var scannedThroughput = elapsedSec > 0 ? (processed / (elapsedSec / 60)) : 0;
        if (insightThroughput) {
          insightThroughput.textContent = createdThroughput > 0
            ? (createdThroughput.toFixed(1) + ' created/min (scan ' + scannedThroughput.toFixed(1) + '/min)')
            : '-';
        }

        var remaining = Math.max(0, Number(counts.missing_avifs || 0));
        var etaMinutes = createdThroughput > 0 ? (remaining / createdThroughput) : 0;
        if (insightEta) insightEta.textContent = (createdThroughput > 0 && remaining > 0) ? (Math.ceil(etaMinutes) + ' min') : '-';

        var cfgQuality = Number(state.quality || 0) || Number(lastRun.quality || 0) || Number((qualityInput && qualityInput.value) || 0);
        var cfgSpeed = Number(state.speed || 0) || Number(lastRun.speed || 0) || Number((speedInput && speedInput.value) || 0);
        var retryCount = Number(state.retry_quality_count || lastRun.retry_quality_count || 0);
        var retryAvg = retryCount > 0 ? (Number(state.retry_quality_sum || lastRun.retry_quality_sum || 0) / retryCount) : 0;
        if (insightQuality) {
          insightQuality.textContent = 'Configured q=' + cfgQuality + ', s=' + cfgSpeed +
            (retryCount > 0 ? (' • Avg final retry q=' + retryAvg.toFixed(1)) : '');
        }

        var existing = Number(state.existing || lastRun.existing || 0);
        var failedValidation = Number(state.failed_validation || lastRun.failed_validation || 0);
        var errors = Number(state.errors || lastRun.errors || 0);
        if (existing <= 0 && processed > 0) {
          existing = Math.max(0, processed - created - errors);
        }
        var stopped = status === 'stopped' ? 1 : 0;
        if (insightResults) {
          insightResults.textContent = 'created ' + created + ', already existed ' + existing + ', failed validation ' +
            failedValidation + ', errors ' + errors + ', stopped by user ' + stopped;
        }

        var bytesJpeg = Number(state.bytes_jpeg_compared || lastRun.bytes_jpeg_compared || 0);
        var bytesAvif = Number(state.bytes_avif_generated || lastRun.bytes_avif_generated || 0);
        var largerCount = Number(state.larger_than_source || lastRun.larger_than_source || 0);
        var deltaPct = bytesJpeg > 0 ? (((bytesAvif - bytesJpeg) / bytesJpeg) * 100) : 0;
        if (insightSize) {
          if (bytesJpeg <= 0 && bytesAvif <= 0) {
            var sizeHint = created > 0
              ? 'Byte totals unavailable for this run state'
              : 'Waiting for first newly created AVIF';
            insightSize.textContent = sizeHint + ' • larger-than-source ' + largerCount;
          } else {
            insightSize.textContent = 'JPEG ' + bytesJpeg + ' B vs AVIF ' + bytesAvif + ' B' +
              (bytesJpeg > 0 ? (' (' + (deltaPct >= 0 ? '+' : '') + deltaPct.toFixed(1) + '%)') : '') +
              ' • larger-than-source ' + largerCount;
          }
        }

        var lockAge = heartbeatAt ? Math.max(0, Math.floor(Date.now() / 1000) - heartbeatAt) : 0;
        var health = 'idle';
        if (isActive && lockAge <= 90) health = 'healthy';
        else if (isActive && lockAge <= 600) health = 'slow';
        else if (isActive) health = 'stale risk';
        if (insightHealth) insightHealth.textContent = health + (lockAge > 0 ? (' • lock age ' + formatDuration(lockAge)) : '');

        var nextOnDemand = Number(conversion.next_on_demand || 0);
        var nextDaily = Number(conversion.next_daily || 0);
        if (insightQueue) {
          insightQueue.textContent = 'on-demand ' + (nextOnDemand ? formatDateTime(nextOnDemand) : 'none') +
            ' • daily ' + (nextDaily ? formatDateTime(nextDaily) : 'none');
        }

        var lastError = String(state.last_error || lastRun.last_error || '');
        if (insightError) insightError.textContent = lastError || '-';
      }).catch(function () {
        if (insightStatus) insightStatus.textContent = 'unavailable';
      });
    }

    function updateRecommendedDefaultsButtonState() {
      if (!applyRecommendedDefaultsBtn) return;
      if (!qualityInput || !speedInput) {
        applyRecommendedDefaultsBtn.disabled = true;
        return;
      }
      var qualityVal = Number(qualityInput.value || 0);
      var speedVal = Number(speedInput.value || 0);
      applyRecommendedDefaultsBtn.disabled = (qualityVal === 83 && speedVal === 0);
    }

    function stopPolling() {
      if (pollingTimerLocal) {
        window.clearInterval(pollingTimerLocal);
        pollingTimerLocal = null;
      }
      toggleHidden(stopBtn, true);
      toggleHidden(progressEl, true);
      if (spinner) spinner.classList.remove('is-active');
      if (convertBtn) convertBtn.disabled = false;
    }

    function startAvifProgressPolling(initialStatusText) {
      toggleHidden(resultContainer, false);
      toggleHidden(progressEl, false);
      toggleHidden(stopBtn, false);
      if (spinner) spinner.classList.add('is-active');
      if (statusEl && initialStatusText) statusEl.textContent = initialStatusText;

      var prevMissing = null;
      var unchangedTicks = 0;
      var startTime = Date.now();
      var MAX_UNCHANGED_TICKS = 20;
      var MAX_DURATION_MS = 10 * 60 * 1000; // 10 minutes safety

      function stopIfNoActiveJob() {
        apiFetch({ path: '/aviflosu/v1/conversion-state', method: 'GET' })
          .then(function (stateResponse) {
            var isActive = !!(stateResponse && stateResponse.active);
            if (isActive) {
              // Job is still running; keep polling counters even if unchanged.
              unchangedTicks = 0;
              if (statusEl) statusEl.textContent = getI18n('avifContinuingBackground', 'AVIF generation is continuing in the background.');
              return;
            }
            if (statusEl) statusEl.textContent = getI18n('avifContinuingBackground', 'AVIF generation is continuing in the background.');
            stopPolling();
          })
          .catch(function () {
            if (statusEl) statusEl.textContent = getI18n('avifContinuingBackground', 'AVIF generation is continuing in the background.');
            stopPolling();
          });
      }

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
              if (statusEl) statusEl.textContent = getI18n('avifComplete', 'AVIF generation complete.');
              loadMissingFiles();
              stopPolling();
            } else {
              if (prevMissing !== null && missing === prevMissing) {
                unchangedTicks++;
              } else {
                unchangedTicks = 0;
              }
              prevMissing = missing;
              if (unchangedTicks >= MAX_UNCHANGED_TICKS || (Date.now() - startTime) > MAX_DURATION_MS) {
                stopIfNoActiveJob();
              }
            }
          })
          .catch(function () {
            if (statusEl) statusEl.textContent = getI18n('avifContinuingBackground', 'AVIF generation is continuing in the background.');
            stopPolling();
          });
      }

      if (pollingTimerLocal) window.clearInterval(pollingTimerLocal);
      pollingTimerLocal = window.setInterval(updateLocal, AVIF_PROGRESS_POLL_INTERVAL_MS);
      updateLocal();
    }

    function loadAvifStats(callback) {
      var totalEl = document.querySelector('#avif-local-support-total-jpegs');
      var avifsEl = document.querySelector('#avif-local-support-existing-avifs');
      var missingEl = document.querySelector('#avif-local-support-missing-avifs');
      if (statsLoadingEl) {
        statsLoadingEl.classList.remove('hidden');
        statsLoadingEl.innerHTML = '<span class="spinner is-active avif-spinner-inline"></span> ' + getI18n('avifStatsLoading', 'Loading AVIF stats...');
      }
      apiFetch({ path: '/aviflosu/v1/scan-missing', method: 'POST' })
        .then(function (data) {
          if (totalEl) totalEl.textContent = String(data.total_jpegs || 0);
          if (avifsEl) avifsEl.textContent = String(data.existing_avifs || 0);
          if (missingEl) missingEl.textContent = String(data.missing_avifs || 0);
          if (statsLoadingEl) statsLoadingEl.classList.add('hidden');
          loadMissingFiles();
          if (callback) callback(data);
        })
        .catch(function () {
          if (totalEl) totalEl.textContent = '-';
          if (avifsEl) avifsEl.textContent = '-';
          if (missingEl) missingEl.textContent = '-';
          if (statsLoadingEl) {
            statsLoadingEl.classList.remove('hidden');
            statsLoadingEl.textContent = getI18n('avifStatsLoadFailed', 'Could not load AVIF stats.');
          }
          if (missingFilesPanel) toggleHidden(missingFilesPanel, true);
          if (callback) callback({});
        });
    }

    function loadMissingFiles() {
      if (!missingFilesPanel || !missingFilesStatus || !missingFilesWrap || !missingFilesList) return;

      toggleHidden(missingFilesPanel, false);
      toggleHidden(missingFilesWrap, true);
      if (missingFilesRefreshBtn) missingFilesRefreshBtn.disabled = true;
      missingFilesStatus.innerHTML = '<span class="spinner is-active avif-spinner-inline"></span> ' + getI18n('missingFilesLoading', 'Loading files without AVIF...');

      apiFetch({ path: '/aviflosu/v1/missing-files?limit=200', method: 'GET' })
        .then(function (data) {
          var files = (data && data.files && Array.isArray(data.files)) ? data.files : [];
          missingFilesList.innerHTML = '';

          if (!files.length) {
            missingFilesStatus.textContent = getI18n('missingFilesNone', 'All discovered JPEG files already have AVIF.');
            toggleHidden(missingFilesWrap, true);
            return;
          }

          for (var i = 0; i < files.length; i++) {
            var file = files[i] || {};
            var li = document.createElement('li');
            var url = String(file.jpeg_url || '');
            var label = String(file.jpeg_path || '');

            if (url) {
              var a = document.createElement('a');
              a.href = url;
              a.target = '_blank';
              a.rel = 'noopener';
              a.textContent = label;
              li.appendChild(a);
            } else {
              li.textContent = label;
            }
            missingFilesList.appendChild(li);
          }

          var status = String(files.length) + ' ' + getI18n('missingFilesListed', 'files without AVIF listed.');
          if (data && data.truncated) {
            status += ' ' + getI18n('missingFilesTruncated', 'Showing first 200.');
          }
          missingFilesStatus.textContent = status;
          toggleHidden(missingFilesWrap, false);
        })
        .catch(function () {
          missingFilesStatus.textContent = getI18n('missingFilesLoadFailed', 'Could not load files without AVIF.');
          toggleHidden(missingFilesWrap, true);
        })
        .finally(function () {
          if (missingFilesRefreshBtn) missingFilesRefreshBtn.disabled = false;
        });
    }

    if (
      document.querySelector('#avif-local-support-total-jpegs') ||
      document.querySelector('#avif-local-support-existing-avifs') ||
      document.querySelector('#avif-local-support-missing-avifs')
    ) {
      loadAvifStats();
    }
    if (insightStatus) {
      updateInsightsPanel();
      if (insightsTimer) window.clearInterval(insightsTimer);
      insightsTimer = window.setInterval(updateInsightsPanel, INSIGHTS_POLL_INTERVAL_MS);
    }
    if (missingFilesRefreshBtn) {
      missingFilesRefreshBtn.addEventListener('click', function (e) {
        e.preventDefault();
        loadMissingFiles();
      });
    }
    if (openMissingFilesBtn && missingFilesModal) {
      openMissingFilesBtn.addEventListener('click', function (e) {
        e.preventDefault();
        missingFilesModal.classList.remove('hidden');
        loadMissingFiles();
      });
    }
    if (closeMissingFilesBtns && closeMissingFilesBtns.length && missingFilesModal) {
      for (var cm = 0; cm < closeMissingFilesBtns.length; cm++) {
        closeMissingFilesBtns[cm].addEventListener('click', function (e) {
          e.preventDefault();
          missingFilesModal.classList.add('hidden');
        });
      }
      document.addEventListener('keydown', function (evt) {
        if (evt.key === 'Escape') {
          missingFilesModal.classList.add('hidden');
        }
      });
    }

    if (applyRecommendedDefaultsBtn && typeof AVIFLocalSupportData !== 'undefined') {
      updateRecommendedDefaultsButtonState();
      if (qualityInput) {
        qualityInput.addEventListener('input', updateRecommendedDefaultsButtonState);
        qualityInput.addEventListener('change', updateRecommendedDefaultsButtonState);
      }
      if (speedInput) {
        speedInput.addEventListener('input', updateRecommendedDefaultsButtonState);
        speedInput.addEventListener('change', updateRecommendedDefaultsButtonState);
      }
      applyRecommendedDefaultsBtn.addEventListener('click', function (e) {
        e.preventDefault();
        applyRecommendedDefaultsBtn.disabled = true;
        apiFetch({ path: '/aviflosu/v1/apply-recommended-defaults', method: 'POST' })
          .then(function (json) {
            if (qualityInput && typeof json.quality !== 'undefined') {
              qualityInput.value = String(json.quality);
              if (qualityInput.nextElementSibling) qualityInput.nextElementSibling.innerText = String(json.quality);
            }
            if (speedInput && typeof json.speed !== 'undefined') {
              speedInput.value = String(json.speed);
              if (speedInput.nextElementSibling) speedInput.nextElementSibling.innerText = String(json.speed);
            }
            if (applyRecommendedDefaultsStatus) {
              applyRecommendedDefaultsStatus.textContent = getI18n('recommendDefaultsApplied', 'Recommended AVIF defaults applied (Quality 83, Speed 0).');
            } else if (statusEl) {
              statusEl.textContent = getI18n('recommendDefaultsApplied', 'Recommended AVIF defaults applied (Quality 83, Speed 0).');
            }
            updateRecommendedDefaultsButtonState();
          })
          .finally(function () {
            updateRecommendedDefaultsButtonState();
          });
      });
    }

    if (stopBtn && typeof AVIFLocalSupportData !== 'undefined') {
      stopBtn.addEventListener('click', function (e) {
        e.preventDefault();
        stopBtn.disabled = true;
        apiFetch({ path: '/aviflosu/v1/stop-convert', method: 'POST' })
          .then(function () {
            if (statusEl) statusEl.textContent = getI18n('avifStopped', 'AVIF generation stopped.');
          })
          .catch(function () {
            if (statusEl) statusEl.textContent = getI18n('avifStopFailed', 'Could not stop AVIF generation.');
          })
          .finally(function () {
            stopPolling();
            stopBtn.disabled = false;
          });
      });
    }

    if (convertBtn && typeof AVIFLocalSupportData !== 'undefined') {
      convertBtn.addEventListener('click', function (e) {
        e.preventDefault();
        convertBtn.disabled = true;
        toggleHidden(resultContainer, false);
        toggleHidden(progressEl, true);
        if (spinner) spinner.classList.add('is-active');
        if (statusEl) statusEl.textContent = getI18n('avifStarting', 'Starting AVIF generation...');

        apiFetch({ path: '/aviflosu/v1/convert-now', method: 'POST' })
          .then(function (convertResponse) {
            if (convertResponse && convertResponse.reason === 'already_running') {
              startAvifProgressPolling(getI18n('avifAlreadyRunning', 'A conversion job is already running. Progress is continuing in the background.'));
              return;
            }

            if (convertResponse && convertResponse.reason === 'already_scheduled') {
              startAvifProgressPolling(getI18n('avifAlreadyScheduled', 'A conversion job is already queued. It will continue in the background shortly.'));
              return;
            }

            if (convertResponse && convertResponse.queued === false) {
              if (statusEl) statusEl.textContent = getI18n('avifQueueFailed', 'Could not queue AVIF generation. Check WP-Cron and try again.');
              stopPolling();
              return;
            }

            startAvifProgressPolling(getI18n('avifConverting', 'Generating missing AVIF files...'));
          })
          .catch(function () {
            if (statusEl) statusEl.textContent = getI18n('avifFailed', 'AVIF generation failed.');
            stopPolling();
          })
          .finally(function () {
            // Don't disable button here - let stopPolling handle it
          });
      });
    }

    // Delete all AVIF files
    var deleteBtn = document.querySelector('#avif-local-support-delete-avifs');
    if (deleteBtn && typeof AVIFLocalSupportData !== 'undefined') {
      deleteBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (!window.confirm(getI18n('confirmDeleteAvifs', 'Delete all AVIF files generated by this plugin?'))) {
          return;
        }
        deleteBtn.disabled = true;
        toggleHidden(resultContainer, false);
        if (spinner) spinner.classList.add('is-active');
        if (statusEl) statusEl.textContent = getI18n('avifDeleting', 'Deleting AVIF files...');
        toggleHidden(progressEl, true);
        apiFetch({ path: '/aviflosu/v1/delete-all-avifs', method: 'POST' })
          .then(function (d) {
            if (statusEl) {
              var deletedPrefix = getI18n('avifDeletedPrefix', 'Deleted AVIF files:');
              var failedPrefix = getI18n('avifFailedPrefix', 'Failed:');
              var deletedText = deletedPrefix + ' ' + String(d.deleted || 0);
              statusEl.textContent = deletedText + (d.failed ? (', ' + failedPrefix + ' ' + String(d.failed)) : '');
            }
            // Refresh the AVIF counts after deletion
            apiFetch({ path: '/aviflosu/v1/scan-missing', method: 'POST' })
              .then(function (data) {
                var totalEl = document.querySelector('#avif-local-support-total-jpegs');
                var avifsEl = document.querySelector('#avif-local-support-existing-avifs');
                var missingEl = document.querySelector('#avif-local-support-missing-avifs');
                if (totalEl) totalEl.textContent = String(data.total_jpegs || 0);
                if (avifsEl) avifsEl.textContent = String(data.existing_avifs || 0);
                if (missingEl) missingEl.textContent = String(data.missing_avifs || 0);
                loadMissingFiles();
              })
              .catch(function () { /* Ignore scan errors after delete */ });
          })
          .catch(function () { if (statusEl) statusEl.textContent = getI18n('avifFailed', 'AVIF generation failed.'); })
          .finally(function () {
            if (spinner) spinner.classList.remove('is-active');
            deleteBtn.disabled = false;
            if (convertBtn) convertBtn.disabled = false;
          });
      });
    }

    // LQIP tools
    var lqipTotalEl = document.querySelector('#aviflosu-thumbhash-total');
    var lqipWithEl = document.querySelector('#aviflosu-thumbhash-with');
    var lqipWithoutEl = document.querySelector('#aviflosu-thumbhash-without');
    var lqipGenerateBtn = document.querySelector('#aviflosu-thumbhash-generate');
    var lqipStopBtn = document.querySelector('#aviflosu-thumbhash-stop');
    var lqipDeleteBtn = document.querySelector('#aviflosu-thumbhash-delete');
    var lqipResultContainer = document.querySelector('#aviflosu-thumbhash-result');
    var lqipSpinner = document.querySelector('#aviflosu-thumbhash-spinner');
    var lqipStatusEl = document.querySelector('#aviflosu-thumbhash-status');
    var lqipProgressEl = document.querySelector('#aviflosu-thumbhash-progress');
    var lqipProgressWith = document.querySelector('#aviflosu-thumbhash-progress-with');
    var lqipProgressTotal = document.querySelector('#aviflosu-thumbhash-progress-total');
    var lqipPollingTimer = null;
    var lqipStopRequested = false;

    function loadLqipStats(callback) {
      apiFetch({ path: '/aviflosu/v1/thumbhash/stats', method: 'GET' })
        .then(function (data) {
          if (lqipTotalEl) lqipTotalEl.textContent = String(data.total || 0);
          if (lqipWithEl) lqipWithEl.textContent = String(data.with_hash || 0);
          if (lqipWithoutEl) lqipWithoutEl.textContent = String(data.without_hash || 0);
          if (callback) callback(data);
        })
        .catch(function () {
          if (lqipTotalEl) lqipTotalEl.textContent = '-';
          if (lqipWithEl) lqipWithEl.textContent = '-';
          if (lqipWithoutEl) lqipWithoutEl.textContent = '-';
          if (callback) callback({});
        });
    }

    function stopLqipPolling() {
      if (lqipPollingTimer) {
        window.clearInterval(lqipPollingTimer);
        lqipPollingTimer = null;
      }
      toggleHidden(lqipStopBtn, true);
      toggleHidden(lqipProgressEl, true);
      if (lqipSpinner) lqipSpinner.classList.remove('is-active');
      if (lqipGenerateBtn) lqipGenerateBtn.disabled = false;
      if (lqipDeleteBtn) lqipDeleteBtn.disabled = false;
      lqipStopRequested = false;
    }

    if ((lqipTotalEl || lqipWithEl || lqipWithoutEl) && typeof AVIFLocalSupportData !== 'undefined') {
      loadLqipStats();
    }

    if (lqipStopBtn && typeof AVIFLocalSupportData !== 'undefined') {
      lqipStopBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (lqipStopRequested) return;
        lqipStopRequested = true;
        lqipStopBtn.disabled = true;
        if (lqipStatusEl) lqipStatusEl.textContent = getI18n('lqipStopping', 'Stopping LQIP generation...');
        apiFetch({ path: '/aviflosu/v1/thumbhash/stop', method: 'POST' })
          .catch(function () {
            if (lqipStatusEl) lqipStatusEl.textContent = getI18n('lqipStopFailed', 'Could not request LQIP stop.');
            lqipStopRequested = false;
            lqipStopBtn.disabled = false;
          });
      });
    }

    if (lqipGenerateBtn && typeof AVIFLocalSupportData !== 'undefined') {
      lqipGenerateBtn.addEventListener('click', function (e) {
        e.preventDefault();
        lqipGenerateBtn.disabled = true;
        if (lqipDeleteBtn) lqipDeleteBtn.disabled = true;
        lqipStopRequested = false;
        toggleHidden(lqipResultContainer, false);
        if (lqipSpinner) lqipSpinner.classList.add('is-active');
        if (lqipStatusEl) lqipStatusEl.textContent = getI18n('lqipGenerating', 'Generating missing LQIPs...');
        toggleHidden(lqipProgressEl, false);
        toggleHidden(lqipStopBtn, false);
        if (lqipStopBtn) lqipStopBtn.disabled = false;

        loadLqipStats(function (initialData) {
          var startWithout = initialData.without_hash || 0;
          var total = initialData.total || 0;
          if (lqipProgressTotal) lqipProgressTotal.textContent = String(total);
          if (lqipProgressWith) lqipProgressWith.textContent = String(initialData.with_hash || 0);

          apiFetch({ path: '/aviflosu/v1/thumbhash/generate-all', method: 'POST' })
            .then(function (data) {
              if (lqipStatusEl) {
                var headline = data && data.stopped
                  ? getI18n('lqipStopped', 'LQIP generation stopped.')
                  : getI18n('lqipComplete', 'LQIP generation complete.');
                lqipStatusEl.textContent = headline +
                  ' ' + getI18n('lqipGenerated', 'Generated:') + ' ' + (data.generated || 0) +
                  ', ' + getI18n('lqipSkipped', 'Skipped:') + ' ' + (data.skipped || 0) +
                  ', ' + getI18n('lqipFailed', 'Failed:') + ' ' + (data.failed || 0);
              }
              stopLqipPolling();
              loadLqipStats();
            })
            .catch(function () {
              if (lqipStatusEl) lqipStatusEl.textContent = getI18n('lqipFailedShort', 'LQIP generation failed.');
              stopLqipPolling();
            });

          var prevWithout = startWithout;
          var unchangedTicks = 0;
          var startTime = Date.now();
          var MAX_UNCHANGED_TICKS = 20;
          var MAX_DURATION_MS = 10 * 60 * 1000;

          function pollLqipProgress() {
            loadLqipStats(function (data) {
              var withHash = data.with_hash || 0;
              var without = data.without_hash || 0;

              if (lqipProgressWith) lqipProgressWith.textContent = String(withHash);

              if (!lqipStopRequested && without !== 0) {
                if (without === prevWithout) {
                  unchangedTicks++;
                } else {
                  unchangedTicks = 0;
                }
                prevWithout = without;
                if (unchangedTicks >= MAX_UNCHANGED_TICKS || (Date.now() - startTime) > MAX_DURATION_MS) {
                  if (lqipStatusEl) lqipStatusEl.textContent = getI18n('lqipContinuingBackground', 'LQIP generation is continuing in the background...');
                  stopLqipPolling();
                }
              }
            });
          }

          if (lqipPollingTimer) window.clearInterval(lqipPollingTimer);
          lqipPollingTimer = window.setInterval(pollLqipProgress, 1500);
          pollLqipProgress();
        });
      });
    }

    if (lqipDeleteBtn && typeof AVIFLocalSupportData !== 'undefined') {
      lqipDeleteBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (!window.confirm(getI18n('confirmDeleteLqips', 'Delete all generated LQIPs?'))) {
          return;
        }
        if (lqipGenerateBtn) lqipGenerateBtn.disabled = true;
        lqipDeleteBtn.disabled = true;
        toggleHidden(lqipResultContainer, false);
        if (lqipSpinner) lqipSpinner.classList.add('is-active');
        if (lqipStatusEl) lqipStatusEl.textContent = getI18n('lqipDeleting', 'Deleting LQIPs...');
        toggleHidden(lqipProgressEl, true);

        apiFetch({ path: '/aviflosu/v1/thumbhash/delete-all', method: 'POST' })
          .then(function (data) {
            if (lqipStatusEl) {
              lqipStatusEl.textContent = getI18n('lqipDeleted', 'Deleted LQIPs:') + ' ' + (data.deleted || 0) + ' ' + getI18n('lqipEntries', 'entries');
            }
            if (lqipSpinner) lqipSpinner.classList.remove('is-active');
            if (lqipGenerateBtn) lqipGenerateBtn.disabled = false;
            lqipDeleteBtn.disabled = false;
            loadLqipStats();
          })
          .catch(function () {
            if (lqipStatusEl) lqipStatusEl.textContent = getI18n('lqipFailedShort', 'LQIP generation failed.');
            if (lqipSpinner) lqipSpinner.classList.remove('is-active');
            if (lqipGenerateBtn) lqipGenerateBtn.disabled = false;
            lqipDeleteBtn.disabled = false;
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
    var logsCompactToggle = document.querySelector('#avif-local-support-logs-compact');
    var logsSearchInput = document.querySelector('#avif-local-support-logs-search');
    var logsChipWrap = document.querySelector('#avif-local-support-logs-chips');
    var logsChipButtons = logsChipWrap ? logsChipWrap.querySelectorAll('.avif-log-chip') : [];
    var copyStatus = document.querySelector('#avif-local-support-copy-status');
    var copySupportBtn = document.querySelector('#avif-local-support-copy-support');
    var copySupportStatus = document.querySelector('#avif-local-support-copy-support-status');
    var logsActiveStatusChip = 'all';
    var LOGS_COMPACT_STORAGE_KEY = 'aviflosu_logs_compact_mode';

    function getLogEntryStatus(el) {
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
      return status;
    }

    function refreshLogChipCounts(entries) {
      var counts = { all: 0, error: 0, warning: 0, success: 0, info: 0 };
      for (var i = 0; i < entries.length; i++) {
        var status = getLogEntryStatus(entries[i]);
        counts.all++;
        if (counts.hasOwnProperty(status)) counts[status]++;
      }
      for (var b = 0; b < logsChipButtons.length; b++) {
        var btn = logsChipButtons[b];
        var statusKey = String(btn.getAttribute('data-status') || 'all');
        var label = String(btn.getAttribute('data-label') || statusKey);
        var count = counts.hasOwnProperty(statusKey) ? counts[statusKey] : 0;
        btn.textContent = label + ' (' + String(count) + ')';
      }
    }

    function applyLogsCompactMode() {
      if (!logsContent || !logsCompactToggle) return;
      var compact = !!logsCompactToggle.checked;
      logsContent.classList.toggle('avif-logs-compact', compact);
      var entries = logsContent.querySelectorAll('.avif-log-entry');
      for (var i = 0; i < entries.length; i++) {
        if (!compact) {
          entries[i].classList.remove('is-expanded');
        }
      }
    }

    function applyLogsFilter() {
      if (!logsContent) return;
      // Ensure the container exists and is in the DOM before accessing
      if (!logsContent.parentNode || !document.body.contains(logsContent)) return;

      var onlyErrors = logsOnlyErrorsToggle ? !!logsOnlyErrorsToggle.checked : false;
      var query = logsSearchInput ? String(logsSearchInput.value || '').toLowerCase().trim() : '';
      var entries = logsContent.querySelectorAll('.avif-log-entry');
      refreshLogChipCounts(entries);

      for (var i = 0; i < entries.length; i++) {
        var el = entries[i];
        var status = getLogEntryStatus(el);
        var isErrorish = (status === 'error');
        var matchesChip = logsActiveStatusChip === 'all' || logsActiveStatusChip === status;
        var matchesSearch = true;
        if (query !== '') {
          var haystack = '';
          if (el && el.getAttribute) {
            haystack = String(el.getAttribute('data-search') || '');
          }
          matchesSearch = haystack.indexOf(query) !== -1;
        }
        el.style.display = (onlyErrors && !isErrorish) || !matchesChip || !matchesSearch ? 'none' : '';
      }

      applyLogsCompactMode();
    }

    if (logsOnlyErrorsToggle) {
      logsOnlyErrorsToggle.addEventListener('change', applyLogsFilter);
    }
    if (logsSearchInput) {
      logsSearchInput.addEventListener('input', applyLogsFilter);
    }
    if (logsCompactToggle) {
      try {
        logsCompactToggle.checked = localStorage.getItem(LOGS_COMPACT_STORAGE_KEY) === '1';
      } catch (e) { }
      logsCompactToggle.addEventListener('change', function () {
        try {
          localStorage.setItem(LOGS_COMPACT_STORAGE_KEY, logsCompactToggle.checked ? '1' : '0');
        } catch (e) { }
        applyLogsFilter();
      });
    }
    if (logsChipButtons && logsChipButtons.length) {
      for (var c = 0; c < logsChipButtons.length; c++) {
        logsChipButtons[c].addEventListener('click', function (e) {
          e.preventDefault();
          logsActiveStatusChip = String(this.getAttribute('data-status') || 'all');
          for (var j = 0; j < logsChipButtons.length; j++) {
            logsChipButtons[j].classList.toggle('is-active', logsChipButtons[j] === this);
          }
          applyLogsFilter();
        });
      }
    }
    if (logsContent) {
      logsContent.addEventListener('click', function (e) {
        if (!logsCompactToggle || !logsCompactToggle.checked) return;
        var target = e.target;
        if (!target) return;
        var interactive = target.closest('a, button, input, label');
        if (interactive) return;
        var header = target.closest('.avif-log-header');
        if (!header) return;
        var row = header.closest('.avif-log-entry');
        if (!row) return;
        row.classList.toggle('is-expanded');
      });
    }
    // Apply immediately on page load for initial server-rendered logs.
    setTimeout(applyLogsFilter, 0);

    if (copyLogsBtn) {
      copyLogsBtn.addEventListener('click', function (e) {
        e.preventDefault();
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
      copySupportBtn.addEventListener('click', function (e) {
        e.preventDefault();
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
      element.classList.remove('hidden');
      setTimeout(function () {
        element.classList.add('hidden');
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

    function refreshLogsContent() {
      return apiFetch({ path: '/aviflosu/v1/logs', method: 'GET' })
        .then(function (json) {
          if (!logsContent) return;
          if (json && typeof json.content === 'string' && json.content !== '') {
            logsContent.innerHTML = json.content;
          } else {
            logsContent.innerHTML = '<p class="description">' + getI18n('logsNone', 'No logs available.') + '</p>';
          }
          applyLogsFilter();
        })
    }

    var logsAutoRefreshInFlight = false;
    function triggerLogsAutoRefresh() {
      if (logsAutoRefreshInFlight) return;
      if (typeof document !== 'undefined' && document.visibilityState === 'hidden') return;
      var toolsTab = document.querySelector('#avif-local-support-tab-tools');
      if (toolsTab && !toolsTab.classList.contains('active')) return;

      logsAutoRefreshInFlight = true;
      refreshLogsContent()
        .catch(function () { })
        .finally(function () {
          logsAutoRefreshInFlight = false;
        });
    }

    if (refreshLogsBtn && typeof AVIFLocalSupportData !== 'undefined') {
      refreshLogsBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (logsSpinner) logsSpinner.classList.add('is-active');
        refreshLogsContent()
          .catch(function () {
            if (logsContent) {
              logsContent.innerHTML = '<p class="description avif-error-text">' + getI18n('logsRefreshFailed', 'Could not refresh logs. Please try again.') + '</p>';
            }
          })
          .finally(function () {
            if (logsSpinner) logsSpinner.classList.remove('is-active');
          });
      });
    }

    if (clearLogsBtn && typeof AVIFLocalSupportData !== 'undefined') {
      clearLogsBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (!window.confirm(getI18n('confirmClearLogs', 'Clear all logs?'))) {
          return;
        }

        clearLogsBtn.disabled = true;
        if (logsSpinner) logsSpinner.classList.add('is-active');
        apiFetch({ path: '/aviflosu/v1/logs/clear', method: 'POST' })
          .then(function () {
            return refreshLogsContent().catch(function () {
              if (logsContent) {
                logsContent.innerHTML = '<p class="description avif-error-text">' + getI18n('logsRefreshFailed', 'Could not refresh logs. Please try again.') + '</p>';
              }
            });
          })
          .catch(function () {
            if (logsContent) {
              logsContent.innerHTML = '<p class="description avif-error-text">' + getI18n('logsClearFailed', 'Could not clear logs. Please try again.') + '</p>';
              applyLogsFilter();
            }
          })
          .finally(function () {
            if (logsSpinner) logsSpinner.classList.remove('is-active');
            clearLogsBtn.disabled = false;
          });
      });
    }

    if (typeof AVIFLocalSupportData !== 'undefined') {
      window.setInterval(triggerLogsAutoRefresh, 15000);
    }

    // Run ImageMagick test
    var runTestBtn = document.querySelector('#avif-local-support-run-magick-test');
    var runTestSpinner = document.querySelector('#avif-local-support-magick-test-spinner');
    var runTestStatus = document.querySelector('#avif-local-support-magick-test-status');
    var runTestOutput = document.querySelector('#avif-local-support-magick-test-output');
    if (runTestBtn && typeof AVIFLocalSupportData !== 'undefined') {
      runTestBtn.addEventListener('click', function (e) {
        e.preventDefault();
        runTestBtn.disabled = true;
        if (runTestSpinner) runTestSpinner.classList.add('is-active');
        if (runTestStatus) runTestStatus.textContent = getI18n('magickRunning', 'Running ImageMagick check...');
        if (runTestOutput) {
          toggleHidden(runTestOutput, true);
          runTestOutput.textContent = '';
        }
        apiFetch({ path: '/aviflosu/v1/magick-test', method: 'POST' })
          .then(function (data) {
            if (!data) return;
            if (runTestStatus) runTestStatus.textContent = getI18n('magickExitCode', 'Exit code') + ' ' + String(data.code);
            var lines = [];
            if (data.selected_path) {
              lines.push(getI18n('magickSelectedBinary', 'Selected binary:') + ' ' + String(data.selected_path) + (data.auto_selected ? (' ' + getI18n('magickAuto', '(auto)')) : ''));
            }
            if (data.define_strategy && data.define_strategy.namespace) {
              var s = data.define_strategy;
              lines.push(
                getI18n('magickDefineStrategy', 'Define strategy:') + ' ' + String(s.namespace) +
                ' (lossless=' + String(!!s.supports_lossless) +
                ', chroma=' + String(!!s.supports_chroma) +
                ', -depth=' + String(!!s.supports_depth) +
                ', bit-depth-define=' + String(!!s.supports_bit_depth_define) + ')'
              );
            }
            if (lines.length) { lines.push(''); }
            lines.push(String(data.output || ''));
            if (data.hint) { lines.push(''); lines.push(getI18n('magickHint', 'Hint:') + ' ' + String(data.hint)); }
            var text = lines.join('\n');
            if (runTestOutput) {
              runTestOutput.textContent = text;
              toggleHidden(runTestOutput, false);
            }
          })
          .catch(function (err) {
            if (runTestStatus) runTestStatus.textContent = (err && err.message) ? String(err.message) : getI18n('magickFailed', 'ImageMagick check failed.');
          })
          .finally(function () {
            if (runTestSpinner) runTestSpinner.classList.remove('is-active');
            runTestBtn.disabled = false;
          });
      });
    }

    // AVIF settings playground
    var playgroundUploadForm = document.querySelector('#avif-local-support-playground-upload-form');
    var playgroundFileInput = document.querySelector('#avif-local-support-playground-file');
    var playgroundSizeSelect = document.querySelector('#avif-local-support-playground-size');
    var playgroundUploadSubmit = document.querySelector('#avif-local-support-playground-upload-submit');
    var playgroundUploadSpinner = document.querySelector('#avif-local-support-playground-upload-spinner');
    var playgroundUploadStatus = document.querySelector('#avif-local-support-playground-upload-status');
    var playgroundPanel = document.querySelector('#avif-local-support-playground-panel');

    var playgroundQuality = document.querySelector('#avif-local-support-playground-quality');
    var playgroundQualityValue = document.querySelector('#avif-local-support-playground-quality-value');
    var playgroundSpeed = document.querySelector('#avif-local-support-playground-speed');
    var playgroundSpeedValue = document.querySelector('#avif-local-support-playground-speed-value');
    var playgroundSubsampling = document.querySelector('#avif-local-support-playground-subsampling');
    var playgroundBitDepth = document.querySelector('#avif-local-support-playground-bit-depth');
    var playgroundEngineMode = document.querySelector('#avif-local-support-playground-engine-mode');
    var playgroundRefreshBtn = document.querySelector('#avif-local-support-playground-refresh');
    var playgroundApplyBtn = document.querySelector('#avif-local-support-playground-apply-settings');
    var playgroundPreviewSpinner = document.querySelector('#avif-local-support-playground-preview-spinner');
    var playgroundPreviewStatus = document.querySelector('#avif-local-support-playground-preview-status');
    var playgroundSizeSummary = document.querySelector('#avif-local-support-playground-size-summary');

    var playgroundViewJpg = document.querySelector('#avif-local-support-playground-view-jpg');
    var playgroundViewAvif = document.querySelector('#avif-local-support-playground-view-avif');
    var playgroundDownloadJpeg = document.querySelector('#avif-local-support-playground-download-jpeg');
    var playgroundDownloadAvif = document.querySelector('#avif-local-support-playground-download-avif');
    var playgroundPreviewTitle = document.querySelector('#avif-local-support-playground-preview-title');
    var playgroundPreviewImage = document.querySelector('#avif-local-support-playground-preview-image');

    var playgroundToken = '';
    var playgroundData = null;
    var playgroundPreviewFormat = 'jpeg';

    function setStatusText(element, message, isError) {
      if (!element) return;
      element.textContent = String(message || '');
      if (isError) element.classList.add('avif-status-error');
      else element.classList.remove('avif-status-error');
    }

    function updateRangeValue(input, output) {
      if (!input || !output) return;
      output.textContent = String(input.value || '');
    }

    function getPlaygroundSettings() {
      return {
        quality: playgroundQuality ? Number(playgroundQuality.value || 0) : 83,
        speed: playgroundSpeed ? Number(playgroundSpeed.value || 0) : 0,
        subsampling: playgroundSubsampling ? String(playgroundSubsampling.value || '420') : '420',
        bit_depth: playgroundBitDepth ? String(playgroundBitDepth.value || '8') : '8',
        engine_mode: playgroundEngineMode ? String(playgroundEngineMode.value || 'auto') : 'auto'
      };
    }

    function syncPlaygroundSettings(settings) {
      if (!settings) return;
      if (playgroundQuality && typeof settings.quality !== 'undefined') {
        playgroundQuality.value = String(settings.quality);
      }
      if (playgroundSpeed && typeof settings.speed !== 'undefined') {
        playgroundSpeed.value = String(settings.speed);
      }
      if (playgroundSubsampling && settings.subsampling) {
        playgroundSubsampling.value = String(settings.subsampling);
      }
      if (playgroundBitDepth && settings.bit_depth) {
        playgroundBitDepth.value = String(settings.bit_depth);
      }
      if (playgroundEngineMode && settings.engine_mode) {
        playgroundEngineMode.value = String(settings.engine_mode);
      }
      updateRangeValue(playgroundQuality, playgroundQualityValue);
      updateRangeValue(playgroundSpeed, playgroundSpeedValue);
    }

    function syncMainSettingsInputs(settings) {
      if (!settings) return;
      var qualityInput = document.querySelector('#aviflosu_quality');
      var speedInput = document.querySelector('#aviflosu_speed');
      var subInput = document.querySelector('input[name="aviflosu_subsampling"][value="' + String(settings.subsampling || '') + '"]');
      var bitInput = document.querySelector('input[name="aviflosu_bit_depth"][value="' + String(settings.bit_depth || '') + '"]');
      var engineInput = document.querySelector('input[name="aviflosu_engine_mode"][value="' + String(settings.engine_mode || '') + '"]');

      if (qualityInput && typeof settings.quality !== 'undefined') {
        qualityInput.value = String(settings.quality);
        if (qualityInput.nextElementSibling) qualityInput.nextElementSibling.textContent = String(settings.quality);
      }
      if (speedInput && typeof settings.speed !== 'undefined') {
        speedInput.value = String(settings.speed);
        if (speedInput.nextElementSibling) speedInput.nextElementSibling.textContent = String(settings.speed);
      }
      if (subInput) subInput.checked = true;
      if (bitInput) bitInput.checked = true;
      if (engineInput) {
        engineInput.checked = true;
        updateEngineVisibility();
      }
    }

    function setDownloadLink(linkEl, url, filename) {
      if (!linkEl) return;
      if (url) {
        linkEl.href = url;
        if (filename) linkEl.setAttribute('download', filename);
        linkEl.classList.remove('disabled');
        linkEl.removeAttribute('aria-disabled');
      } else {
        linkEl.removeAttribute('href');
        linkEl.classList.add('disabled');
        linkEl.setAttribute('aria-disabled', 'true');
      }
    }

    function setPlaygroundViewButtons(mode) {
      var buttons = [
        { el: playgroundViewJpg, mode: 'jpeg' },
        { el: playgroundViewAvif, mode: 'avif' }
      ];
      buttons.forEach(function (item) {
        if (!item.el) return;
        if (item.mode === mode) item.el.classList.add('is-primary');
        else item.el.classList.remove('is-primary');
      });
    }

    function updatePlaygroundImage() {
      if (!playgroundData || !playgroundPreviewImage) return;
      var jpgSrc = String(playgroundData.jpeg_url || '');
      var avifSrc = String(playgroundData.avif_url || '');
      var hasAvif = !!avifSrc;

      var source = jpgSrc;
      var title = getI18n('playgroundSizeLabelJpeg', 'JPEG');
      if (playgroundPreviewFormat === 'avif' && hasAvif) {
        source = avifSrc;
        title = getI18n('playgroundSizeLabelAvif', 'AVIF');
      }

      playgroundPreviewImage.src = source;
      if (playgroundPreviewTitle) playgroundPreviewTitle.textContent = title;
      setPlaygroundViewButtons(playgroundPreviewFormat);
    }

    function renderPlaygroundData(data) {
      if (!data || !data.token) return;
      playgroundData = data;
      playgroundToken = String(data.token);
      syncPlaygroundSettings(data.settings || null);

      if (playgroundPanel) toggleHidden(playgroundPanel, false);

      setDownloadLink(playgroundDownloadJpeg, data.jpeg_download_url || data.jpeg_url || '', data.jpeg_name || 'preview.jpg');
      setDownloadLink(playgroundDownloadAvif, data.avif_download_url || '', data.avif_name || 'preview.avif');

      if (!data.avif_url && playgroundPreviewFormat === 'avif') {
        playgroundPreviewFormat = 'jpeg';
      }

      if (playgroundSizeSummary) {
        var summary = getI18n('playgroundSizeLabelJpeg', 'JPEG') + ': ' + formatBytes(data.jpeg_size || 0, 2);
        summary += ' • ' + getI18n('playgroundSizeLabelAvif', 'AVIF') + ': ' + formatBytes(data.avif_size || 0, 2);
        if (typeof data.jpeg_quality !== 'undefined' && Number(data.jpeg_quality || 0) > 0) {
          summary += ' • ' + getI18n('playgroundJpegQualityLabel', 'JPEG quality') + ': ' + String(data.jpeg_quality);
        } else if (String(data.jpeg_quality_source || '') === 'original') {
          summary += ' • ' + getI18n('playgroundJpegQualityOriginal', 'JPEG quality: original upload (not recompressed)');
        }
        if (data.width && data.height) {
          summary += ' • ' + String(data.width) + '×' + String(data.height);
        }
        playgroundSizeSummary.textContent = summary;
      }

      updatePlaygroundImage();
    }

    function setPlaygroundBusy(uploadBusy, previewBusy) {
      var hasAvif = !!(playgroundData && playgroundData.avif_url);
      if (playgroundFileInput) playgroundFileInput.disabled = !!uploadBusy;
      if (playgroundSizeSelect) playgroundSizeSelect.disabled = !!uploadBusy;
      if (playgroundUploadSubmit) playgroundUploadSubmit.disabled = !!uploadBusy;
      if (playgroundRefreshBtn) playgroundRefreshBtn.disabled = !!uploadBusy || !!previewBusy || !playgroundToken;
      if (playgroundApplyBtn) playgroundApplyBtn.disabled = !!uploadBusy || !!previewBusy || !playgroundToken;
      if (playgroundViewJpg) playgroundViewJpg.disabled = !!uploadBusy || !playgroundToken;
      if (playgroundViewAvif) playgroundViewAvif.disabled = !!uploadBusy || !playgroundToken || !hasAvif;
    }

    function handlePlaygroundPreviewResponse(data, defaultSuccessMessage) {
      if (!data || !data.token) {
        setStatusText(playgroundPreviewStatus, getI18n('playgroundPreviewFailed', 'Could not render AVIF preview.'), true);
        return;
      }
      renderPlaygroundData(data);
      if (data.error) {
        setStatusText(playgroundPreviewStatus, String(data.error), true);
      } else {
        if (data.avif_url) {
          playgroundPreviewFormat = 'avif';
          updatePlaygroundImage();
        }
        setStatusText(playgroundPreviewStatus, defaultSuccessMessage, false);
      }
    }

    if (playgroundQuality) {
      playgroundQuality.addEventListener('input', function () { updateRangeValue(playgroundQuality, playgroundQualityValue); });
    }
    if (playgroundSpeed) {
      playgroundSpeed.addEventListener('input', function () { updateRangeValue(playgroundSpeed, playgroundSpeedValue); });
    }
    updateRangeValue(playgroundQuality, playgroundQualityValue);
    updateRangeValue(playgroundSpeed, playgroundSpeedValue);

    if (playgroundViewJpg) {
      playgroundViewJpg.addEventListener('click', function (e) {
        e.preventDefault();
        if (!playgroundData) return;
        playgroundPreviewFormat = 'jpeg';
        updatePlaygroundImage();
      });
    }
    if (playgroundViewAvif) {
      playgroundViewAvif.addEventListener('click', function (e) {
        e.preventDefault();
        if (!playgroundData || !playgroundData.avif_url) return;
        playgroundPreviewFormat = 'avif';
        updatePlaygroundImage();
      });
    }

    if (playgroundUploadForm && playgroundFileInput && playgroundUploadSubmit && typeof AVIFLocalSupportData !== 'undefined') {
      setPlaygroundBusy(false, false);
      playgroundUploadForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var file = playgroundFileInput.files && playgroundFileInput.files.length ? playgroundFileInput.files[0] : null;
        if (!file) {
          setStatusText(playgroundUploadStatus, getI18n('playgroundSelectFile', 'Please choose a JPEG file first.'), true);
          return;
        }

        setPlaygroundBusy(true, false);
        if (playgroundUploadSpinner) playgroundUploadSpinner.classList.add('is-active');
        setStatusText(playgroundUploadStatus, getI18n('playgroundUploading', 'Uploading playground JPEG...'), false);
        setStatusText(playgroundPreviewStatus, '', false);

        var formData = new FormData();
        formData.append('avif_local_support_test_file', file);
        if (playgroundSizeSelect && playgroundSizeSelect.value) {
          formData.append('avif_local_support_playground_size', String(playgroundSizeSelect.value));
        }

        apiFetch({ path: '/aviflosu/v1/playground/create', method: 'POST', body: formData })
          .then(function (data) {
            renderPlaygroundData(data);
            if (data && data.error) {
              setStatusText(playgroundUploadStatus, String(data.error), true);
            } else {
              setStatusText(playgroundUploadStatus, getI18n('playgroundLoaded', 'Playground image ready.'), false);
            }
          })
          .catch(function (err) {
            var msg = (err && err.message) ? String(err.message) : getI18n('playgroundUploadFailed', 'Failed to load playground image.');
            setStatusText(playgroundUploadStatus, msg, true);
          })
          .finally(function () {
            if (playgroundUploadSpinner) playgroundUploadSpinner.classList.remove('is-active');
            setPlaygroundBusy(false, false);
          });
      });
    }

    if (playgroundRefreshBtn && typeof AVIFLocalSupportData !== 'undefined') {
      playgroundRefreshBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (!playgroundToken) return;

        setPlaygroundBusy(false, true);
        if (playgroundPreviewSpinner) playgroundPreviewSpinner.classList.add('is-active');
        setStatusText(playgroundPreviewStatus, getI18n('playgroundPreviewing', 'Rendering AVIF preview...'), false);

        apiFetch({
          path: '/aviflosu/v1/playground/preview',
          method: 'POST',
          body: JSON.stringify({ token: playgroundToken, settings: getPlaygroundSettings() }),
          headers: { 'Content-Type': 'application/json' }
        })
          .then(function (data) {
            handlePlaygroundPreviewResponse(data, getI18n('playgroundPreviewReady', 'AVIF preview updated.'));
          })
          .catch(function (err) {
            var msg = (err && err.message) ? String(err.message) : getI18n('playgroundPreviewFailed', 'Could not render AVIF preview.');
            setStatusText(playgroundPreviewStatus, msg, true);
          })
          .finally(function () {
            if (playgroundPreviewSpinner) playgroundPreviewSpinner.classList.remove('is-active');
            setPlaygroundBusy(false, false);
          });
      });
    }

    if (playgroundApplyBtn && typeof AVIFLocalSupportData !== 'undefined') {
      playgroundApplyBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (!playgroundToken) return;

        setPlaygroundBusy(false, true);
        if (playgroundPreviewSpinner) playgroundPreviewSpinner.classList.add('is-active');
        setStatusText(playgroundPreviewStatus, getI18n('playgroundApplying', 'Saving AVIF settings...'), false);

        apiFetch({
          path: '/aviflosu/v1/playground/apply-settings',
          method: 'POST',
          body: JSON.stringify({ settings: getPlaygroundSettings() }),
          headers: { 'Content-Type': 'application/json' }
        })
          .then(function (data) {
            if (data && data.settings) {
              syncPlaygroundSettings(data.settings);
              syncMainSettingsInputs(data.settings);
            }
            var message = (data && data.message) ? String(data.message) : getI18n('playgroundApplySuccess', 'Plugin AVIF settings updated.');
            setStatusText(playgroundPreviewStatus, message, false);
          })
          .catch(function (err) {
            var msg = (err && err.message) ? String(err.message) : getI18n('playgroundApplyFailed', 'Could not update plugin settings.');
            setStatusText(playgroundPreviewStatus, msg, true);
          })
          .finally(function () {
            if (playgroundPreviewSpinner) playgroundPreviewSpinner.classList.remove('is-active');
            setPlaygroundBusy(false, false);
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

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();

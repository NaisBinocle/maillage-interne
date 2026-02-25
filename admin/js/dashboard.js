/**
 * Dashboard page JS — handles bulk actions and progress polling.
 */
(function () {
	'use strict';

	var config = window.miAdmin || {};

	function apiCall(endpoint, method, data) {
		var options = {
			method: method || 'GET',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
		};
		if (data) {
			options.body = JSON.stringify(data);
		}
		return fetch(config.restUrl + endpoint, options).then(function (res) {
			return res.json();
		});
	}

	function showProgress(text) {
		var el = document.getElementById('mi-bulk-progress');
		var status = document.getElementById('mi-bulk-status');
		if (el) el.style.display = 'block';
		if (status) status.textContent = text || '';
	}

	function updateProgressBar(percent) {
		var fill = document.querySelector('#mi-bulk-progress .mi-progress-fill');
		if (fill) fill.style.width = percent + '%';
	}

	function pollQueueStatus() {
		apiCall('status/queue').then(function (data) {
			var percent = data.percent_complete || 0;
			updateProgressBar(percent);
			showProgress(
				data.completed + ' / ' + data.total + ' traités — ' +
				data.pending + ' en attente, ' + data.failed + ' en erreur'
			);

			if (data.pending > 0 || data.processing > 0) {
				setTimeout(pollQueueStatus, 3000);
			} else {
				showProgress('Terminé !');
				setTimeout(function () { location.reload(); }, 2000);
			}
		});
	}

	// Bulk vectorize button.
	var btnVectorize = document.getElementById('mi-btn-vectorize');
	if (btnVectorize) {
		btnVectorize.addEventListener('click', function () {
			if (!confirm('Vectoriser tout le contenu ? Cela consommera des crédits API.')) return;
			btnVectorize.disabled = true;
			showProgress('Lancement...');
			apiCall('bulk/vectorize', 'POST').then(function (data) {
				showProgress(data.queued_count + ' articles mis en file d\'attente.');
				if (data.queued_count > 0) pollQueueStatus();
			});
		});
	}

	// Bulk scan links button.
	var btnScan = document.getElementById('mi-btn-scan-links');
	if (btnScan) {
		btnScan.addEventListener('click', function () {
			btnScan.disabled = true;
			showProgress('Scan des liens en cours...');
			apiCall('bulk/scan-links', 'POST').then(function (data) {
				showProgress(data.posts_scanned + ' articles scannés, ' + data.links_found + ' liens trouvés.');
				setTimeout(function () { location.reload(); }, 2000);
			});
		});
	}

	// Recompute similarities button.
	var btnRecompute = document.getElementById('mi-btn-recompute');
	if (btnRecompute) {
		btnRecompute.addEventListener('click', function () {
			btnRecompute.disabled = true;
			showProgress('Invalidation du cache...');
			apiCall('bulk/recompute-similarities', 'POST').then(function () {
				showProgress('Cache invalidé. Les similarités seront recalculées à la prochaine consultation.');
				setTimeout(function () { location.reload(); }, 2000);
			});
		});
	}

	// Test API button (settings page).
	var btnTest = document.getElementById('mi-btn-test-api');
	if (btnTest) {
		btnTest.addEventListener('click', function () {
			var resultEl = document.getElementById('mi-test-result');
			if (resultEl) resultEl.textContent = 'Test en cours...';
			btnTest.disabled = true;
			apiCall('settings/test-api', 'POST').then(function (data) {
				if (resultEl) {
					resultEl.textContent = data.message || (data.success ? 'OK' : 'Erreur');
					resultEl.style.color = data.success ? '#00a32a' : '#d63638';
				}
				btnTest.disabled = false;
			}).catch(function () {
				if (resultEl) {
					resultEl.textContent = 'Erreur de connexion.';
					resultEl.style.color = '#d63638';
				}
				btnTest.disabled = false;
			});
		});
	}
})();

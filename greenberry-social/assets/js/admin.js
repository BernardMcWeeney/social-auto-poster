/**
 * Greenberry Social — Admin JS
 *
 * Handles credential form toggling, manual entry toggles,
 * and Facebook page selection from broker.
 */
(function () {
	'use strict';

	// Toggle manual credential forms.
	document.querySelectorAll('.gbsocial-manual-toggle').forEach(function (toggle) {
		toggle.addEventListener('click', function (e) {
			e.preventDefault();
			var card = this.closest('.gbsocial-provider-card');
			var form = card.querySelector('.gbsocial-credentials-form');
			if (!form) return;

			var isHidden = form.classList.contains('hidden');
			form.classList.toggle('hidden');
			this.textContent = isHidden
				? 'Hide manual entry'
				: 'Or enter credentials manually';
		});
	});

	// Facebook: load pages from broker and allow selection without re-auth.
	var loadBtn = document.getElementById('gbsocial-load-fb-pages');
	var pagesList = document.getElementById('gbsocial-fb-pages-list');
	var pageSelect = document.getElementById('gbsocial-fb-page-select');
	var useBtn = document.getElementById('gbsocial-fb-page-use');
	var statusEl = document.getElementById('gbsocial-fb-pages-status');

	if (loadBtn && pagesList && pageSelect) {
		loadBtn.addEventListener('click', function () {
			loadBtn.disabled = true;
			loadBtn.textContent = 'Loading pages...';

			var nonce = (window.gbsocialAdmin || {}).fbPagesNonce || '';
			fetch(ajaxurl + '?action=gbsocial_list_fb_pages&_wpnonce=' + nonce)
				.then(function (r) { return r.json(); })
				.then(function (result) {
					if (!result.success) {
						loadBtn.disabled = false;
						loadBtn.textContent = 'Select from connected pages';
						if (statusEl) {
							statusEl.textContent = result.data || 'No pages found. Connect Facebook on one site first.';
							statusEl.style.color = '#d63638';
						}
						return;
					}

					var pages = result.data;
					pageSelect.innerHTML = '';
					pages.forEach(function (p) {
						var opt = document.createElement('option');
						opt.value = p.page_id;
						opt.textContent = p.page_name + ' (' + p.page_id + ')';
						pageSelect.appendChild(opt);
					});

					loadBtn.style.display = 'none';
					pagesList.style.display = 'flex';
					pagesList.style.gap = '8px';
					pagesList.style.alignItems = 'center';
				})
				.catch(function (err) {
					loadBtn.disabled = false;
					loadBtn.textContent = 'Select from connected pages';
					if (statusEl) {
						statusEl.textContent = 'Error: ' + err.message;
						statusEl.style.color = '#d63638';
					}
				});
		});
	}

	if (useBtn && pageSelect && statusEl) {
		useBtn.addEventListener('click', function () {
			var pageId = pageSelect.value;
			if (!pageId) return;

			useBtn.disabled = true;
			statusEl.textContent = 'Connecting...';
			statusEl.style.color = '#646970';

			var nonce = (window.gbsocialAdmin || {}).fbPagesNonce || '';
			var formData = new FormData();
			formData.append('action', 'gbsocial_use_fb_page');
			formData.append('_wpnonce', nonce);
			formData.append('page_id', pageId);

			fetch(ajaxurl, { method: 'POST', body: formData })
				.then(function (r) { return r.json(); })
				.then(function (result) {
					if (result.success) {
						statusEl.textContent = 'Connected to ' + (result.data.page_name || pageId) + '! Reloading...';
						statusEl.style.color = '#00a32a';
						setTimeout(function () { location.reload(); }, 1000);
					} else {
						useBtn.disabled = false;
						statusEl.textContent = result.data || 'Failed.';
						statusEl.style.color = '#d63638';
					}
				})
				.catch(function (err) {
					useBtn.disabled = false;
					statusEl.textContent = 'Error: ' + err.message;
					statusEl.style.color = '#d63638';
				});
		});
	}
})();

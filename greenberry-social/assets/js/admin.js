/**
 * Greenberry Social — Admin JS
 *
 * Handles credential form toggling and manual entry toggles.
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

	// Collapsible provider cards (non-connected).
	document.querySelectorAll('.gbsocial-provider-card:not(.connected) .gbsocial-provider-header').forEach(function (header) {
		var body = header.nextElementSibling;
		if (!body || !body.classList.contains('gbsocial-provider-body')) return;

		// If there's no OAuth section, body starts visible.
		var hasOAuth = body.querySelector('.gbsocial-oauth-section');
		if (!hasOAuth) return;

		// Body is already visible for OAuth providers.
	});
})();

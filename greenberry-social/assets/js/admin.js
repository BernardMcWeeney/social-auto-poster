/**
 * Greenberry Social — Admin JS
 *
 * Handles credential form toggling and UI interactions.
 */
(function () {
	'use strict';

	// Toggle credential forms.
	document.querySelectorAll('.gbsocial-provider-card').forEach(function (card) {
		var header = card.querySelector('.gbsocial-provider-header');
		var form = card.querySelector('.gbsocial-credentials-form');

		if (!form || card.classList.contains('connected')) return;

		// Start collapsed if OAuth section exists.
		var oauthSection = card.querySelector('.gbsocial-oauth-section');
		if (oauthSection) {
			form.style.display = 'none';

			var toggleLink = document.createElement('a');
			toggleLink.href = '#';
			toggleLink.textContent = 'Enter credentials manually';
			toggleLink.style.fontSize = '13px';
			oauthSection.querySelector('.description').replaceWith(toggleLink);

			toggleLink.addEventListener('click', function (e) {
				e.preventDefault();
				form.style.display = form.style.display === 'none' ? '' : 'none';
				this.textContent = form.style.display === 'none'
					? 'Enter credentials manually'
					: 'Hide manual entry';
			});
		}
	});
})();

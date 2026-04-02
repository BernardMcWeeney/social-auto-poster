/**
 * Greenberry Social — Gutenberg Sidebar Panel
 *
 * Adds a "Social Sharing" panel to the post editor sidebar.
 */
(function (wp) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	var CheckboxControl = wp.components.CheckboxControl;
	var TextareaControl = wp.components.TextareaControl;
	var Button = wp.components.Button;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var useState = wp.element.useState;
	var registerPlugin = wp.plugins.registerPlugin;

	var providers = (window.gbsocialEditor || {}).providers || [];
	var nonce = (window.gbsocialEditor || {}).nonce || '';
	var ajaxUrl = (window.gbsocialEditor || {}).ajaxUrl || '';

	function GBSocialPanel() {
		var postId = useSelect(function (select) {
			return select('core/editor').getCurrentPostId();
		});

		var meta = useSelect(function (select) {
			return select('core/editor').getEditedPostAttribute('meta') || {};
		});

		var editPost = useDispatch('core/editor').editPost;

		var disabled = meta._gbsocial_disable || false;
		var selectedProviders = meta._gbsocial_providers || [];
		var customMessage = meta._gbsocial_message || '';

		var _state = useState(null);
		var reshareStatus = _state[0];
		var setReshareStatus = _state[1];

		function updateMeta(key, value) {
			var newMeta = {};
			newMeta[key] = value;
			editPost({ meta: newMeta });
		}

		function handleReshare() {
			setReshareStatus('sharing');
			fetch(ajaxUrl + '?action=gbsocial_reshare&post_id=' + postId + '&_wpnonce=' + nonce)
				.then(function (r) { return r.json(); })
				.then(function (data) {
					setReshareStatus(data.success ? 'done' : 'error');
				})
				.catch(function () {
					setReshareStatus('error');
				});
		}

		if (providers.length === 0) {
			return el(PluginDocumentSettingPanel, {
				name: 'gbsocial-panel',
				title: 'Social Sharing',
				className: 'gbsocial-panel'
			},
				el('p', null, 'No social accounts connected. ',
					el('a', { href: '/wp-admin/options-general.php?page=greenberry-social' }, 'Connect now'))
			);
		}

		return el(PluginDocumentSettingPanel, {
			name: 'gbsocial-panel',
			title: 'Social Sharing',
			className: 'gbsocial-panel'
		},
			el(Fragment, null,
				el(CheckboxControl, {
					label: 'Disable auto-sharing for this post',
					checked: disabled,
					onChange: function (val) { updateMeta('_gbsocial_disable', val); }
				}),

				!disabled && el(Fragment, null,
					el('p', { style: { fontWeight: 600, marginBottom: 4 } }, 'Share to:'),
					providers.map(function (p) {
						var isChecked = selectedProviders.length === 0 || selectedProviders.indexOf(p.id) !== -1;
						return el(CheckboxControl, {
							key: p.id,
							label: p.name,
							checked: isChecked,
							onChange: function (val) {
								var next = selectedProviders.slice();
								if (val) {
									if (next.indexOf(p.id) === -1) next.push(p.id);
								} else {
									next = next.filter(function (x) { return x !== p.id; });
								}
								updateMeta('_gbsocial_providers', next);
							}
						});
					}),

					el(TextareaControl, {
						label: 'Custom message',
						help: 'Leave blank to use template.',
						value: customMessage,
						onChange: function (val) { updateMeta('_gbsocial_message', val); }
					})
				),

				postId && el('div', { style: { marginTop: 12 } },
					el(Button, {
						variant: 'secondary',
						onClick: handleReshare,
						isBusy: reshareStatus === 'sharing',
						disabled: reshareStatus === 'sharing'
					}, 'Re-share Now'),
					reshareStatus === 'done' && el('span', { style: { color: 'green', marginLeft: 8 } }, 'Shared!'),
					reshareStatus === 'error' && el('span', { style: { color: 'red', marginLeft: 8 } }, 'Error')
				)
			)
		);
	}

	registerPlugin('gbsocial', {
		render: GBSocialPanel,
		icon: 'share'
	});

})(window.wp);

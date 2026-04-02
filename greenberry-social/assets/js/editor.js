/**
 * Greenberry Social — Gutenberg Sidebar Panel
 *
 * Rich social sharing panel with:
 * - Social link preview card
 * - Per-platform custom messages with character counts
 * - Provider enable/disable toggles
 * - Custom social image picker
 * - Share scheduling
 * - Re-share controls with status
 */
(function (wp) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	var CheckboxControl = wp.components.CheckboxControl;
	var TextareaControl = wp.components.TextareaControl;
	var Button = wp.components.Button;
	var PanelRow = wp.components.PanelRow;
	var ToggleControl = wp.components.ToggleControl;
	var DateTimePicker = wp.components.DateTimePicker;
	var Popover = wp.components.Popover;
	var MediaUpload = wp.blockEditor ? wp.blockEditor.MediaUpload : (wp.editor ? wp.editor.MediaUpload : null);
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var useState = wp.element.useState;
	var useCallback = wp.element.useCallback;
	var registerPlugin = wp.plugins.registerPlugin;

	var config = window.gbsocialEditor || {};
	var providers = config.providers || [];
	var nonce = config.nonce || '';
	var ajaxUrl = config.ajaxUrl || '';
	var settingsUrl = config.settingsUrl || '';
	var homeUrl = config.homeUrl || '';

	// Provider icon colors.
	var PROVIDER_COLORS = {};
	providers.forEach(function (p) { PROVIDER_COLORS[p.id] = p.color; });

	function GBSocialPanel() {
		var postId = useSelect(function (s) { return s('core/editor').getCurrentPostId(); });
		var postTitle = useSelect(function (s) { return s('core/editor').getEditedPostAttribute('title') || ''; });
		var postExcerpt = useSelect(function (s) { return s('core/editor').getEditedPostAttribute('excerpt') || ''; });
		var postContent = useSelect(function (s) { return s('core/editor').getEditedPostAttribute('content') || ''; });
		var featuredImageId = useSelect(function (s) { return s('core/editor').getEditedPostAttribute('featured_media'); });
		var postStatus = useSelect(function (s) { return s('core/editor').getEditedPostAttribute('status'); });

		var meta = useSelect(function (s) { return s('core/editor').getEditedPostAttribute('meta') || {}; });
		var editPost = useDispatch('core/editor').editPost;

		// Featured image URL.
		var featuredImage = useSelect(function (s) {
			if (!featuredImageId) return null;
			var media = s('core').getMedia(featuredImageId);
			return media ? (media.media_details && media.media_details.sizes && media.media_details.sizes.medium
				? media.media_details.sizes.medium.source_url
				: media.source_url) : null;
		});

		// Social image URL.
		var socialImageId = meta._gbsocial_social_image || 0;
		var socialImage = useSelect(function (s) {
			if (!socialImageId) return null;
			var media = s('core').getMedia(socialImageId);
			return media ? (media.media_details && media.media_details.sizes && media.media_details.sizes.medium
				? media.media_details.sizes.medium.source_url
				: media.source_url) : null;
		});

		var disabled = meta._gbsocial_disable || false;
		var selectedProviders = meta._gbsocial_providers || [];
		var globalMessage = meta._gbsocial_message || '';
		var perPlatformMessages = meta._gbsocial_messages || {};
		var schedule = meta._gbsocial_schedule || '';

		var _reshareState = useState(null);
		var reshareStatus = _reshareState[0];
		var setReshareStatus = _reshareState[1];

		var _activeTab = useState('all');
		var activeTab = _activeTab[0];
		var setActiveTab = _activeTab[1];

		var _showSchedule = useState(!!schedule);
		var showSchedule = _showSchedule[0];
		var setShowSchedule = _showSchedule[1];

		function updateMeta(key, value) {
			var obj = {};
			obj[key] = value;
			editPost({ meta: obj });
		}

		function updatePlatformMessage(providerId, msg) {
			var updated = Object.assign({}, perPlatformMessages);
			if (msg) {
				updated[providerId] = msg;
			} else {
				delete updated[providerId];
			}
			updateMeta('_gbsocial_messages', updated);
		}

		function handleReshare() {
			setReshareStatus('sharing');
			fetch(ajaxUrl + '?action=gbsocial_reshare&post_id=' + postId + '&_wpnonce=' + nonce)
				.then(function (r) { return r.json(); })
				.then(function (data) {
					setReshareStatus(data.success ? 'done' : 'error');
					if (!data.success) console.warn('Reshare error:', data.data);
				})
				.catch(function () { setReshareStatus('error'); });
		}

		// No providers connected.
		if (providers.length === 0) {
			return el(PluginDocumentSettingPanel, {
				name: 'gbsocial-panel',
				title: 'Social Sharing',
				className: 'gbsocial-panel',
				icon: 'share'
			},
				el('p', { style: { color: '#646970', fontSize: '13px' } },
					'No social accounts connected.'),
				el('a', {
					href: settingsUrl,
					className: 'components-button is-secondary',
					style: { marginTop: '8px', display: 'inline-block' }
				}, 'Connect Accounts')
			);
		}

		// Preview data.
		var previewImage = socialImage || featuredImage;
		var previewTitle = postTitle || '(No title)';
		var rawExcerpt = postExcerpt || (postContent ? postContent.replace(/<[^>]+>/g, '').substring(0, 120) + '...' : '');
		var domain = homeUrl.replace(/^https?:\/\//, '').replace(/\/$/, '');

		return el(PluginDocumentSettingPanel, {
			name: 'gbsocial-panel',
			title: 'Social Sharing',
			className: 'gbsocial-panel',
			icon: 'share'
		},

			// ── Disable toggle ──
			el(ToggleControl, {
				label: 'Disable auto-sharing',
				checked: disabled,
				onChange: function (val) { updateMeta('_gbsocial_disable', val); }
			}),

			!disabled && el(Fragment, null,

				// ── Social Preview Card ──
				el('div', { className: 'gbsocial-preview', style: { marginBottom: '16px' } },
					previewImage
						? el('img', { className: 'gbsocial-preview-image', src: previewImage })
						: el('div', {
							className: 'gbsocial-preview-image',
							style: { background: '#e2e4e7', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#8c8f94', fontSize: '12px' }
						}, 'No image'),
					el('div', { className: 'gbsocial-preview-body' },
						el('p', { className: 'gbsocial-preview-domain' }, domain),
						el('p', { className: 'gbsocial-preview-title' }, previewTitle),
						el('p', { className: 'gbsocial-preview-excerpt' }, rawExcerpt)
					)
				),

				// ── Custom Social Image ──
				MediaUpload && el(PanelRow, null,
					el(MediaUpload, {
						onSelect: function (media) { updateMeta('_gbsocial_social_image', media.id); },
						allowedTypes: ['image'],
						value: socialImageId,
						render: function (obj) {
							return el(Fragment, null,
								el(Button, {
									onClick: obj.open,
									variant: 'secondary',
									isSmall: true,
									style: { marginRight: '8px' }
								}, socialImageId ? 'Change Social Image' : 'Custom Social Image'),
								socialImageId && el(Button, {
									onClick: function () { updateMeta('_gbsocial_social_image', 0); },
									isDestructive: true,
									isSmall: true,
									variant: 'tertiary'
								}, 'Remove')
							);
						}
					})
				),

				// ── Provider Toggles ──
				el('div', { style: { marginTop: '12px', marginBottom: '12px' } },
					el('p', { style: { fontWeight: 600, marginBottom: '6px', fontSize: '13px' } }, 'Share to'),
					providers.map(function (p) {
						var isChecked = selectedProviders.length === 0 || selectedProviders.indexOf(p.id) !== -1;
						return el('div', {
							key: p.id,
							style: { display: 'flex', alignItems: 'center', marginBottom: '4px', gap: '8px' }
						},
							el('span', {
								style: {
									width: '16px', height: '16px', borderRadius: '3px',
									background: p.color, display: 'inline-block', flexShrink: 0
								}
							}),
							el(CheckboxControl, {
								label: p.name,
								checked: isChecked,
								onChange: function (val) {
									var next = selectedProviders.slice();
									if (next.length === 0) {
										next = providers.map(function (pp) { return pp.id; });
									}
									if (val) {
										if (next.indexOf(p.id) === -1) next.push(p.id);
									} else {
										next = next.filter(function (x) { return x !== p.id; });
									}
									updateMeta('_gbsocial_providers', next);
								},
								__nextHasNoMarginBottom: true
							}),
							p.limit > 0 && el('span', {
								style: { fontSize: '11px', color: '#646970', marginLeft: 'auto', flexShrink: 0 }
							}, p.limit + ' chars')
						);
					})
				),

				// ── Message Tabs ──
				el('div', { style: { marginBottom: '12px' } },
					el('div', { className: 'gbsocial-platform-tabs' },
						el('button', {
							type: 'button',
							className: 'gbsocial-platform-tab' + (activeTab === 'all' ? ' active' : ''),
							onClick: function () { setActiveTab('all'); }
						}, 'All'),
						providers.map(function (p) {
							return el('button', {
								key: p.id,
								type: 'button',
								className: 'gbsocial-platform-tab' + (activeTab === p.id ? ' active' : ''),
								onClick: function () { setActiveTab(p.id); }
							}, p.name);
						})
					),

					// All tab.
					activeTab === 'all' && el(Fragment, null,
						el(TextareaControl, {
							value: globalMessage,
							onChange: function (val) { updateMeta('_gbsocial_message', val); },
							placeholder: 'Custom message for all platforms. Leave blank to use template.',
							rows: 3,
							__nextHasNoMarginBottom: true
						})
					),

					// Per-platform tabs.
					providers.map(function (p) {
						if (activeTab !== p.id) return null;
						var msg = perPlatformMessages[p.id] || '';
						var len = msg.length;
						var overLimit = p.limit > 0 && len > p.limit;

						return el(Fragment, { key: p.id },
							el(TextareaControl, {
								value: msg,
								onChange: function (val) { updatePlatformMessage(p.id, val); },
								placeholder: 'Custom message for ' + p.name + ' only.',
								rows: 3,
								__nextHasNoMarginBottom: true
							}),
							p.limit > 0 && el('div', {
								className: 'gbsocial-char-count' + (overLimit ? ' over-limit' : ''),
							}, len + '/' + p.limit)
						);
					})
				),

				// ── Schedule ──
				el(PanelRow, null,
					el(ToggleControl, {
						label: 'Schedule sharing',
						checked: showSchedule,
						onChange: function (val) {
							setShowSchedule(val);
							if (!val) updateMeta('_gbsocial_schedule', '');
						}
					})
				),

				showSchedule && el('div', { style: { padding: '0 0 12px' } },
					el('input', {
						type: 'datetime-local',
						value: schedule,
						onChange: function (e) { updateMeta('_gbsocial_schedule', e.target.value); },
						style: { width: '100%', fontSize: '13px' }
					}),
					el('p', { style: { fontSize: '11px', color: '#646970', marginTop: '4px' } },
						'Sharing will happen at this time instead of immediately on publish.')
				),

				// ── Re-share button ──
				postStatus === 'publish' && postId && el('div', {
					style: { borderTop: '1px solid #f0f0f1', paddingTop: '12px', marginTop: '4px' }
				},
					el(Button, {
						variant: 'secondary',
						onClick: handleReshare,
						isBusy: reshareStatus === 'sharing',
						disabled: reshareStatus === 'sharing',
						isSmall: true
					}, 'Re-share Now'),
					reshareStatus === 'done' && el('span', { style: { color: '#00a32a', marginLeft: '8px', fontSize: '13px' } }, 'Shared!'),
					reshareStatus === 'error' && el('span', { style: { color: '#d63638', marginLeft: '8px', fontSize: '13px' } }, 'Failed — check log')
				)
			)
		);
	}

	registerPlugin('gbsocial', {
		render: GBSocialPanel,
		icon: 'share'
	});

})(window.wp);

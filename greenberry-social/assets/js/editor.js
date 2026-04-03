/**
 * Greenberry Social — Gutenberg Sidebar Panel + Pre-Publish Modal
 *
 * Matches Jetpack Social's UI:
 * - PluginDocumentSettingPanel for sidebar
 * - PluginPrePublishPanel for publish confirmation
 * - Social link preview card
 * - Per-platform custom messages with character counts
 * - Provider enable/disable toggles with brand colors
 * - Custom social image picker
 * - Share scheduling toggle
 */
(function (wp) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	var PluginPrePublishPanel = wp.editPost.PluginPrePublishPanel;
	var CheckboxControl = wp.components.CheckboxControl;
	var TextareaControl = wp.components.TextareaControl;
	var ToggleControl = wp.components.ToggleControl;
	var Button = wp.components.Button;
	var PanelRow = wp.components.PanelRow;
	var MediaUpload = wp.blockEditor ? wp.blockEditor.MediaUpload : (wp.editor ? wp.editor.MediaUpload : null);
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var useState = wp.element.useState;
	var registerPlugin = wp.plugins.registerPlugin;

	var config = window.gbsocialEditor || {};
	var providers = config.providers || [];
	var nonce = config.nonce || '';
	var ajaxUrl = config.ajaxUrl || '';
	var settingsUrl = config.settingsUrl || '';
	var homeUrl = config.homeUrl || '';

	/* ── Shared hook: all the social sharing state ── */

	function useSocialState() {
		var meta = useSelect(function (s) { return s('core/editor').getEditedPostAttribute('meta') || {}; });
		var editPost = useDispatch('core/editor').editPost;

		var postId = useSelect(function (s) { return s('core/editor').getCurrentPostId(); });
		var postTitle = useSelect(function (s) { return s('core/editor').getEditedPostAttribute('title') || ''; });
		var postExcerpt = useSelect(function (s) { return s('core/editor').getEditedPostAttribute('excerpt') || ''; });
		var postContent = useSelect(function (s) { return s('core/editor').getEditedPostAttribute('content') || ''; });
		var featuredImageId = useSelect(function (s) { return s('core/editor').getEditedPostAttribute('featured_media'); });
		var postStatus = useSelect(function (s) { return s('core/editor').getEditedPostAttribute('status'); });

		var featuredImage = useSelect(function (s) {
			if (!featuredImageId) return null;
			var media = s('core').getMedia(featuredImageId);
			if (!media) return null;
			var sizes = media.media_details && media.media_details.sizes;
			return (sizes && sizes.medium) ? sizes.medium.source_url : media.source_url;
		});

		var socialImageId = meta._gbsocial_social_image || 0;
		var socialImage = useSelect(function (s) {
			if (!socialImageId) return null;
			var media = s('core').getMedia(socialImageId);
			if (!media) return null;
			var sizes = media.media_details && media.media_details.sizes;
			return (sizes && sizes.medium) ? sizes.medium.source_url : media.source_url;
		});

		var disabled = meta._gbsocial_disable || false;
		var selectedProviders = meta._gbsocial_providers || [];
		var globalMessage = meta._gbsocial_message || '';

		var rawMessages = meta._gbsocial_messages || '';
		var perPlatformMessages = {};
		if (rawMessages && typeof rawMessages === 'string') {
			try { perPlatformMessages = JSON.parse(rawMessages); } catch (e) {}
		} else if (rawMessages && typeof rawMessages === 'object') {
			perPlatformMessages = rawMessages;
		}

		var schedule = meta._gbsocial_schedule || '';

		function updateMeta(key, value) {
			var obj = {};
			obj[key] = value;
			editPost({ meta: obj });
		}

		function updatePlatformMessage(providerId, msg) {
			var updated = Object.assign({}, perPlatformMessages);
			if (msg) { updated[providerId] = msg; } else { delete updated[providerId]; }
			updateMeta('_gbsocial_messages', JSON.stringify(updated));
		}

		return {
			meta: meta, postId: postId, postTitle: postTitle, postExcerpt: postExcerpt,
			postContent: postContent, postStatus: postStatus,
			featuredImage: featuredImage, socialImage: socialImage, socialImageId: socialImageId,
			disabled: disabled, selectedProviders: selectedProviders,
			globalMessage: globalMessage, perPlatformMessages: perPlatformMessages,
			schedule: schedule,
			updateMeta: updateMeta, updatePlatformMessage: updatePlatformMessage
		};
	}

	/* ── Helper: get char count display text ── */

	function charCountText(msg, limit) {
		if (!limit) return '';
		var len = (msg || '').length;
		return len + '/' + limit;
	}

	/* ── Sidebar Panel ── */

	function GBSocialSidebar() {
		var s = useSocialState();
		var _activeTab = useState('all');
		var activeTab = _activeTab[0];
		var setActiveTab = _activeTab[1];
		var _showSchedule = useState(!!s.schedule);
		var showSchedule = _showSchedule[0];
		var setShowSchedule = _showSchedule[1];
		var _reshareState = useState(null);
		var reshareStatus = _reshareState[0];
		var setReshareStatus = _reshareState[1];

		// No providers connected.
		if (providers.length === 0) {
			return el(PluginDocumentSettingPanel, {
				name: 'gbsocial-panel',
				title: 'Social Sharing',
				icon: 'share'
			},
				el('p', { style: { color: '#646970', fontSize: '13px' } }, 'No social accounts connected.'),
				el('a', { href: settingsUrl, className: 'components-button is-secondary', style: { marginTop: '8px' } }, 'Connect Accounts')
			);
		}

		var previewImage = s.socialImage || s.featuredImage;
		var domain = homeUrl.replace(/^https?:\/\//, '').replace(/\/$/, '');
		var rawExcerpt = s.postExcerpt || (s.postContent ? s.postContent.replace(/<[^>]+>/g, '').substring(0, 100) + ' ...' : '');

		function handleReshare() {
			setReshareStatus('sharing');
			fetch(ajaxUrl + '?action=gbsocial_reshare&post_id=' + s.postId + '&_wpnonce=' + nonce)
				.then(function (r) { return r.json(); })
				.then(function (data) { setReshareStatus(data.success ? 'done' : 'error'); })
				.catch(function () { setReshareStatus('error'); });
		}

		return el(PluginDocumentSettingPanel, {
			name: 'gbsocial-panel',
			title: 'Social Sharing',
			icon: 'share'
		},

			// ── Disable toggle ──
			el(ToggleControl, {
				label: 'Disable auto-sharing',
				checked: s.disabled,
				onChange: function (val) { s.updateMeta('_gbsocial_disable', val); }
			}),

			!s.disabled && el(Fragment, null,

				// ── Social Preview Card ──
				el('div', { className: 'gbsocial-preview', style: { margin: '0 0 16px' } },
					previewImage
						? el('img', { className: 'gbsocial-preview-image', src: previewImage, alt: '' })
						: el('div', { className: 'gbsocial-preview-image',
							style: { background: '#e2e4e7', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#8c8f94', fontSize: '13px' }
						}, 'No image'),
					el('div', { className: 'gbsocial-preview-body' },
						el('p', { className: 'gbsocial-preview-domain' }, domain),
						el('p', { className: 'gbsocial-preview-title' }, s.postTitle || '(No title)'),
						el('p', { className: 'gbsocial-preview-excerpt' }, rawExcerpt)
					)
				),

				// ── Custom Social Image button ──
				MediaUpload && el('div', { style: { marginBottom: '16px' } },
					el(MediaUpload, {
						onSelect: function (media) { s.updateMeta('_gbsocial_social_image', media.id); },
						allowedTypes: ['image'],
						value: s.socialImageId,
						render: function (obj) {
							return el('div', { style: { display: 'flex', gap: '8px', alignItems: 'center' } },
								el(Button, { onClick: obj.open, variant: 'secondary', isSmall: true },
									s.socialImageId ? 'Change Social Image' : 'Custom Social Image'),
								s.socialImageId && el(Button, {
									onClick: function () { s.updateMeta('_gbsocial_social_image', 0); },
									isDestructive: true, isSmall: true, variant: 'tertiary'
								}, 'Remove')
							);
						}
					})
				),

				// ── Share to (provider toggles) ──
				el('div', { style: { marginBottom: '16px' } },
					el('p', { style: { fontWeight: 600, fontSize: '13px', margin: '0 0 8px' } }, 'Share to'),
					providers.map(function (p) {
						var isChecked = s.selectedProviders.length === 0 || s.selectedProviders.indexOf(p.id) !== -1;
						var msg = s.perPlatformMessages[p.id] || s.globalMessage || '';
						return el('div', {
							key: p.id,
							style: { display: 'flex', alignItems: 'center', marginBottom: '4px' }
						},
							el('span', {
								style: { width: '18px', height: '18px', borderRadius: '4px', background: p.color,
									display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
									color: '#fff', fontSize: '9px', fontWeight: 700, marginRight: '8px', flexShrink: 0 }
							}, p.id === 'linkedin' ? 'in' : p.id[0].toUpperCase()),
							el(CheckboxControl, {
								label: p.name,
								checked: isChecked,
								onChange: function (val) {
									var next = s.selectedProviders.length === 0
										? providers.map(function (pp) { return pp.id; })
										: s.selectedProviders.slice();
									if (val) { if (next.indexOf(p.id) === -1) next.push(p.id); }
									else { next = next.filter(function (x) { return x !== p.id; }); }
									s.updateMeta('_gbsocial_providers', next);
								},
								__nextHasNoMarginBottom: true
							}),
							p.limit > 0 && el('span', {
								style: { marginLeft: 'auto', fontSize: '11px', color: '#646970', flexShrink: 0, whiteSpace: 'nowrap' }
							}, charCountText(msg, p.limit))
						);
					})
				),

				// ── Custom Message tabs ──
				el('div', { style: { marginBottom: '16px' } },
					el('div', { className: 'gbsocial-platform-tabs' },
						el('button', {
							type: 'button',
							className: 'gbsocial-platform-tab' + (activeTab === 'all' ? ' active' : ''),
							onClick: function () { setActiveTab('all'); }
						}, 'All'),
						providers.map(function (p) {
							return el('button', {
								key: p.id, type: 'button',
								className: 'gbsocial-platform-tab' + (activeTab === p.id ? ' active' : ''),
								onClick: function () { setActiveTab(p.id); }
							}, p.name);
						})
					),
					activeTab === 'all' && el(TextareaControl, {
						value: s.globalMessage,
						onChange: function (val) { s.updateMeta('_gbsocial_message', val); },
						placeholder: 'Custom message for all platforms.\nLeave blank to use template.',
						rows: 3, __nextHasNoMarginBottom: true
					}),
					providers.map(function (p) {
						if (activeTab !== p.id) return null;
						var msg = s.perPlatformMessages[p.id] || '';
						var overLimit = p.limit > 0 && msg.length > p.limit;
						return el(Fragment, { key: p.id },
							el(TextareaControl, {
								value: msg,
								onChange: function (val) { s.updatePlatformMessage(p.id, val); },
								placeholder: 'Custom message for ' + p.name + ' only.',
								rows: 3, __nextHasNoMarginBottom: true
							}),
							p.limit > 0 && el('div', {
								style: { textAlign: 'right', fontSize: '12px', marginTop: '2px',
									color: overLimit ? '#d63638' : '#646970', fontWeight: overLimit ? 600 : 400 }
							}, msg.length + '/' + p.limit)
						);
					})
				),

				// ── Schedule sharing ──
				el(ToggleControl, {
					label: 'Schedule sharing',
					checked: showSchedule,
					onChange: function (val) { setShowSchedule(val); if (!val) s.updateMeta('_gbsocial_schedule', ''); }
				}),
				showSchedule && el('div', { style: { marginTop: '-8px', marginBottom: '12px' } },
					el('input', {
						type: 'datetime-local', value: s.schedule,
						onChange: function (e) { s.updateMeta('_gbsocial_schedule', e.target.value); },
						style: { width: '100%', fontSize: '13px' }
					}),
					el('p', { style: { fontSize: '11px', color: '#646970', margin: '4px 0 0' } },
						'Leave empty to share immediately on publish.')
				),

				// ── Re-share (published posts only) ──
				s.postStatus === 'publish' && s.postId && el('div', {
					style: { borderTop: '1px solid #e0e0e0', paddingTop: '12px', marginTop: '4px', display: 'flex', alignItems: 'center', gap: '8px' }
				},
					el(Button, {
						variant: 'secondary', onClick: handleReshare, isSmall: true,
						isBusy: reshareStatus === 'sharing', disabled: reshareStatus === 'sharing'
					}, 'Re-share Now'),
					reshareStatus === 'done' && el('span', { style: { color: '#00a32a', fontSize: '13px' } }, 'Shared!'),
					reshareStatus === 'error' && el('span', { style: { color: '#d63638', fontSize: '13px' } }, 'Failed')
				)
			)
		);
	}

	/* ── Pre-Publish Confirmation Panel ── */

	function GBSocialPrePublish() {
		var s = useSocialState();

		if (s.disabled || providers.length === 0) return null;

		var activeProviders = providers.filter(function (p) {
			return s.selectedProviders.length === 0 || s.selectedProviders.indexOf(p.id) !== -1;
		});

		if (activeProviders.length === 0) return null;

		var previewImage = s.socialImage || s.featuredImage;
		var domain = homeUrl.replace(/^https?:\/\//, '').replace(/\/$/, '');
		var rawExcerpt = s.postExcerpt || (s.postContent ? s.postContent.replace(/<[^>]+>/g, '').substring(0, 100) + '...' : '');

		return el(PluginPrePublishPanel, {
			title: 'Confirm social sharing',
			initialOpen: true,
			icon: 'share'
		},
			// Provider toggles.
			el('div', { style: { marginBottom: '12px' } },
				activeProviders.map(function (p) {
					return el('div', {
						key: p.id,
						style: { display: 'flex', alignItems: 'center', padding: '6px 0', borderBottom: '1px solid #f0f0f1' }
					},
						el(ToggleControl, {
							label: el('span', { style: { display: 'flex', alignItems: 'center', gap: '8px' } },
								el('span', {
									style: { width: '18px', height: '18px', borderRadius: '4px', background: p.color,
										display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
										color: '#fff', fontSize: '9px', fontWeight: 700 }
								}, p.id === 'linkedin' ? 'in' : p.id[0].toUpperCase()),
								p.name
							),
							checked: true,
							onChange: function (val) {
								if (!val) {
									var next = s.selectedProviders.length === 0
										? providers.map(function (pp) { return pp.id; })
										: s.selectedProviders.slice();
									next = next.filter(function (x) { return x !== p.id; });
									s.updateMeta('_gbsocial_providers', next);
								}
							},
							__nextHasNoMarginBottom: true
						})
					);
				})
			),

			// Message preview.
			el('div', { style: { marginBottom: '12px' } },
				el('p', { style: { fontSize: '11px', fontWeight: 600, textTransform: 'uppercase', color: '#646970', margin: '0 0 6px' } }, 'Message'),
				el(TextareaControl, {
					value: s.globalMessage,
					onChange: function (val) { s.updateMeta('_gbsocial_message', val); },
					placeholder: 'Write a custom message for your social audience here.',
					rows: 3, __nextHasNoMarginBottom: true
				})
			),

			// Link preview card.
			el('div', { className: 'gbsocial-preview' },
				previewImage
					? el('img', { className: 'gbsocial-preview-image', src: previewImage, alt: '' })
					: el('div', { className: 'gbsocial-preview-image',
						style: { background: '#e2e4e7', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#8c8f94', fontSize: '13px' }
					}, 'No image'),
				el('div', { className: 'gbsocial-preview-body' },
					el('p', { className: 'gbsocial-preview-domain' }, domain),
					el('p', { className: 'gbsocial-preview-title' }, s.postTitle || '(No title)'),
					el('p', { className: 'gbsocial-preview-excerpt' }, rawExcerpt)
				)
			)
		);
	}

	/* ── Combined render ── */

	function GBSocialPlugin() {
		return el(Fragment, null,
			el(GBSocialSidebar),
			el(GBSocialPrePublish)
		);
	}

	registerPlugin('gbsocial', {
		render: GBSocialPlugin,
		icon: 'share'
	});

})(window.wp);

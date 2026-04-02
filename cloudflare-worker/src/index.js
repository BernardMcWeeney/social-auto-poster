/**
 * Greenberry Social — OAuth Broker (Cloudflare Worker)
 *
 * Handles OAuth 2.0 flows for Facebook, LinkedIn, Threads, and Tumblr.
 *
 * Flow:
 * 1. WordPress plugin activates → POST /register → broker generates a
 *    per-site secret, stores it in KV, returns it to the plugin.
 * 2. User clicks "Connect with Facebook" → GET /auth/facebook?site_id=...&state=...
 *    → broker verifies state HMAC with the site's secret → redirects to Facebook.
 * 3. Facebook redirects back → GET /callback/facebook?code=... → broker exchanges
 *    code for token → signs the token with the site's secret → redirects back
 *    to WordPress with the signed credentials.
 *
 * Security:
 * - Each WordPress site gets its own HMAC secret (generated server-side)
 * - OAuth app secrets never leave this Worker (Cloudflare Workers Secrets)
 * - Tokens are passed via signed redirect, never stored in the broker
 * - States expire after 10 minutes
 * - Site registration is validated by origin URL
 */

const PROVIDERS = {
	facebook: {
		authorizeUrl: 'https://www.facebook.com/v21.0/dialog/oauth',
		tokenUrl: 'https://graph.facebook.com/v21.0/oauth/access_token',
		scopes: 'pages_show_list,pages_manage_posts,pages_read_engagement',
		getCredentials: async (tokenData, env) => {
			const longLivedRes = await fetch(
				`https://graph.facebook.com/v21.0/oauth/access_token?` +
				`grant_type=fb_exchange_token&client_id=${env.FACEBOOK_APP_ID}` +
				`&client_secret=${env.FACEBOOK_APP_SECRET}` +
				`&fb_exchange_token=${tokenData.access_token}`
			);
			const longLived = await longLivedRes.json();
			if (longLived.error) throw new Error(longLived.error.message);

			const pagesRes = await fetch(
				`https://graph.facebook.com/v21.0/me/accounts?access_token=${longLived.access_token}`
			);
			const pages = await pagesRes.json();
			if (!pages.data || pages.data.length === 0) {
				throw new Error('No Facebook Pages found. Make sure your account manages at least one Page.');
			}

			const page = pages.data[0];
			return {
				page_id: page.id,
				access_token: page.access_token,
				page_name: page.name,
			};
		},
	},

	linkedin: {
		authorizeUrl: 'https://www.linkedin.com/oauth/v2/authorization',
		tokenUrl: 'https://www.linkedin.com/oauth/v2/accessToken',
		scopes: 'openid profile w_member_social',
		getCredentials: async (tokenData) => {
			const profileRes = await fetch('https://api.linkedin.com/v2/userinfo', {
				headers: { Authorization: `Bearer ${tokenData.access_token}` },
			});
			const profile = await profileRes.json();
			return {
				access_token: tokenData.access_token,
				author_urn: `urn:li:person:${profile.sub}`,
			};
		},
	},

	threads: {
		authorizeUrl: 'https://threads.net/oauth/authorize',
		tokenUrl: 'https://graph.threads.net/oauth/access_token',
		scopes: 'threads_basic,threads_content_publish',
		getCredentials: async (tokenData) => {
			const longLivedRes = await fetch(
				`https://graph.threads.net/access_token?` +
				`grant_type=th_exchange_token&client_secret=${tokenData._app_secret}` +
				`&access_token=${tokenData.access_token}`
			);
			const longLived = await longLivedRes.json();

			const meRes = await fetch(
				`https://graph.threads.net/v1.0/me?fields=id,username&access_token=${longLived.access_token}`
			);
			const me = await meRes.json();
			return {
				user_id: me.id,
				access_token: longLived.access_token,
			};
		},
	},

	tumblr: {
		authorizeUrl: 'https://www.tumblr.com/oauth2/authorize',
		tokenUrl: 'https://api.tumblr.com/v2/oauth2/token',
		scopes: 'basic write',
		getCredentials: async (tokenData) => {
			const userRes = await fetch('https://api.tumblr.com/v2/user/info', {
				headers: { Authorization: `Bearer ${tokenData.access_token}` },
			});
			const userData = await userRes.json();
			const blog = userData.response?.user?.blogs?.[0];
			return {
				blog_name: blog ? blog.name : '',
				access_token: tokenData.access_token,
			};
		},
	},
};

/* ── Crypto helpers ─────────────────────────────────────── */

async function hmacSign(data, secret) {
	const encoder = new TextEncoder();
	const key = await crypto.subtle.importKey(
		'raw',
		encoder.encode(secret),
		{ name: 'HMAC', hash: 'SHA-256' },
		false,
		['sign']
	);
	const sig = await crypto.subtle.sign('HMAC', key, encoder.encode(data));
	return arrayBufferToHex(sig);
}

async function hmacVerify(data, signature, secret) {
	const expected = await hmacSign(data, secret);
	return timingSafeEqual(expected, signature);
}

function timingSafeEqual(a, b) {
	if (a.length !== b.length) return false;
	let result = 0;
	for (let i = 0; i < a.length; i++) {
		result |= a.charCodeAt(i) ^ b.charCodeAt(i);
	}
	return result === 0;
}

function arrayBufferToHex(buffer) {
	return [...new Uint8Array(buffer)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

function generateSecret(length = 64) {
	const bytes = new Uint8Array(length);
	crypto.getRandomValues(bytes);
	return arrayBufferToHex(bytes.buffer);
}

function generateSiteId() {
	const bytes = new Uint8Array(16);
	crypto.getRandomValues(bytes);
	return arrayBufferToHex(bytes.buffer);
}

function jsonResponse(data, status = 200) {
	return new Response(JSON.stringify(data), {
		status,
		headers: {
			'Content-Type': 'application/json',
			'Access-Control-Allow-Origin': '*',
		},
	});
}

/* ── Request handler ────────────────────────────────────── */

export default {
	async fetch(request, env) {
		const url = new URL(request.url);
		const path = url.pathname;

		// CORS preflight.
		if (request.method === 'OPTIONS') {
			return new Response(null, {
				headers: {
					'Access-Control-Allow-Origin': '*',
					'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
					'Access-Control-Allow-Headers': 'Content-Type',
				},
			});
		}

		// Health check.
		if (path === '/health') {
			return jsonResponse({ status: 'ok', version: '1.0.0' });
		}

		// POST /register — site registration.
		if (path === '/register' && request.method === 'POST') {
			return handleRegister(request, env);
		}

		// GET /auth/{provider} — start OAuth flow.
		const authMatch = path.match(/^\/auth\/([a-z]+)$/);
		if (authMatch && request.method === 'GET') {
			return handleAuth(authMatch[1], url, env);
		}

		// GET /callback/{provider} — handle OAuth callback from platform.
		const callbackMatch = path.match(/^\/callback\/([a-z]+)$/);
		if (callbackMatch && request.method === 'GET') {
			return handleCallback(callbackMatch[1], url, env);
		}

		return jsonResponse({ error: 'Not Found' }, 404);
	},
};

/* ── Site Registration ──────────────────────────────────── */

async function handleRegister(request, env) {
	let body;
	try {
		body = await request.json();
	} catch {
		return jsonResponse({ error: 'Invalid JSON.' }, 400);
	}

	const { site_url, site_key, callback } = body;
	if (!site_url || !site_key || !callback) {
		return jsonResponse({ error: 'Missing site_url, site_key, or callback.' }, 400);
	}

	// Validate site_url is a real URL.
	let parsedUrl;
	try {
		parsedUrl = new URL(site_url);
	} catch {
		return jsonResponse({ error: 'Invalid site_url.' }, 400);
	}

	// Normalize site URL for the KV key.
	const normalizedUrl = parsedUrl.origin.toLowerCase();

	// Check if this site is already registered.
	const existingData = await env.SITES.get(`url:${normalizedUrl}`);
	if (existingData) {
		const existing = JSON.parse(existingData);
		// Site already registered — return the existing site_id and secret.
		// The site_key must match for re-registration (prevents hijacking).
		if (existing.site_key === site_key) {
			return jsonResponse({
				site_id: existing.site_id,
				site_secret: existing.site_secret,
			});
		}
		// Different site_key — generate new credentials (site was reinstalled).
	}

	// Generate per-site credentials.
	const siteId = generateSiteId();
	const siteSecret = generateSecret();

	const siteData = {
		site_id: siteId,
		site_secret: siteSecret,
		site_key: site_key,
		site_url: normalizedUrl,
		callback_url: callback,
		registered_at: new Date().toISOString(),
	};

	// Store in KV (indexed by both site_id and URL).
	await env.SITES.put(`site:${siteId}`, JSON.stringify(siteData));
	await env.SITES.put(`url:${normalizedUrl}`, JSON.stringify(siteData));

	return jsonResponse({
		site_id: siteId,
		site_secret: siteSecret,
	});
}

/* ── Auth: redirect user to platform ────────────────────── */

async function handleAuth(providerName, url, env) {
	const provider = PROVIDERS[providerName];
	if (!provider) {
		return jsonResponse({ error: 'Unknown provider.' }, 400);
	}

	const siteId = url.searchParams.get('site_id');
	const state = url.searchParams.get('state');
	const callbackUrl = url.searchParams.get('callback_url');

	if (!siteId || !state || !callbackUrl) {
		return jsonResponse({ error: 'Missing site_id, state, or callback_url.' }, 400);
	}

	// Look up the site.
	const siteDataJson = await env.SITES.get(`site:${siteId}`);
	if (!siteDataJson) {
		return jsonResponse({ error: 'Site not registered. Please deactivate and reactivate the plugin.' }, 403);
	}
	const siteData = JSON.parse(siteDataJson);

	// Verify the state was signed by this site's secret.
	const stateDecoded = atob(state);
	const parts = stateDecoded.split('|');
	if (parts.length < 4) {
		return jsonResponse({ error: 'Invalid state format.' }, 400);
	}

	const [stateProvider, nonce, timestamp, sig] = parts;
	const statePayload = `${stateProvider}|${nonce}|${timestamp}`;

	const valid = await hmacVerify(statePayload, sig, siteData.site_secret);
	if (!valid) {
		return jsonResponse({ error: 'Invalid state signature.' }, 403);
	}

	// Check expiry (10 minutes).
	if (Date.now() / 1000 - parseInt(timestamp, 10) > 600) {
		return jsonResponse({ error: 'State expired. Please try again.' }, 400);
	}

	// Build broker state (wraps original state + metadata for the callback).
	const brokerState = JSON.stringify({
		original_state: state,
		callback_url: callbackUrl,
		site_id: siteId,
		provider: providerName,
		ts: Date.now(),
	});
	const brokerStateSig = await hmacSign(brokerState, siteData.site_secret);
	const encodedBrokerState = btoa(brokerState + '|||' + brokerStateSig);

	// Build the platform authorize URL.
	const clientId = getClientId(providerName, env);
	const brokerCallbackUrl = `${url.origin}/callback/${providerName}`;

	const authUrl = new URL(provider.authorizeUrl);
	authUrl.searchParams.set('client_id', clientId);
	authUrl.searchParams.set('redirect_uri', brokerCallbackUrl);
	authUrl.searchParams.set('response_type', 'code');
	authUrl.searchParams.set('scope', provider.scopes);
	authUrl.searchParams.set('state', encodedBrokerState);

	return Response.redirect(authUrl.toString(), 302);
}

/* ── Callback: exchange code for token ──────────────────── */

async function handleCallback(providerName, url, env) {
	const provider = PROVIDERS[providerName];
	if (!provider) {
		return jsonResponse({ error: 'Unknown provider.' }, 400);
	}

	const code = url.searchParams.get('code');
	const stateParam = url.searchParams.get('state');
	const error = url.searchParams.get('error');

	if (error) {
		const desc = url.searchParams.get('error_description') || error;
		return new Response(errorPage('Connection Failed', desc), {
			status: 400,
			headers: { 'Content-Type': 'text/html' },
		});
	}

	if (!code || !stateParam) {
		return jsonResponse({ error: 'Missing code or state.' }, 400);
	}

	// Decode broker state.
	let decoded;
	try {
		decoded = atob(stateParam);
	} catch {
		return jsonResponse({ error: 'Invalid state encoding.' }, 400);
	}

	const separatorIndex = decoded.lastIndexOf('|||');
	if (separatorIndex === -1) {
		return jsonResponse({ error: 'Invalid broker state format.' }, 400);
	}

	const brokerStateJson = decoded.substring(0, separatorIndex);
	const brokerStateSig = decoded.substring(separatorIndex + 3);

	let brokerState;
	try {
		brokerState = JSON.parse(brokerStateJson);
	} catch {
		return jsonResponse({ error: 'Corrupt broker state.' }, 400);
	}

	// Look up the site to get its secret.
	const siteDataJson = await env.SITES.get(`site:${brokerState.site_id}`);
	if (!siteDataJson) {
		return jsonResponse({ error: 'Site not found.' }, 403);
	}
	const siteData = JSON.parse(siteDataJson);

	// Verify broker state signature.
	const valid = await hmacVerify(brokerStateJson, brokerStateSig, siteData.site_secret);
	if (!valid) {
		return jsonResponse({ error: 'Invalid broker state signature.' }, 403);
	}

	// Check expiry.
	if (Date.now() - brokerState.ts > 600000) {
		return jsonResponse({ error: 'Session expired. Please try again.' }, 400);
	}

	// Exchange the authorization code for an access token.
	const clientId = getClientId(providerName, env);
	const clientSecret = getClientSecret(providerName, env);
	const brokerCallbackUrl = `${url.origin}/callback/${providerName}`;

	const tokenBody = new URLSearchParams({
		client_id: clientId,
		client_secret: clientSecret,
		code: code,
		redirect_uri: brokerCallbackUrl,
		grant_type: 'authorization_code',
	});

	const tokenRes = await fetch(provider.tokenUrl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: tokenBody.toString(),
	});

	const tokenData = await tokenRes.json();
	if (tokenData.error) {
		return new Response(
			errorPage('Token Exchange Failed', JSON.stringify(tokenData.error)),
			{ status: 400, headers: { 'Content-Type': 'text/html' } }
		);
	}

	// Pass app secret for providers that need it (e.g., Threads long-lived exchange).
	tokenData._app_secret = clientSecret;

	// Get platform-specific credentials.
	let credentials;
	try {
		credentials = await provider.getCredentials(tokenData, env);
	} catch (err) {
		return new Response(
			errorPage('Connection Failed', err.message),
			{ status: 400, headers: { 'Content-Type': 'text/html' } }
		);
	}

	// Sign the credentials with the site's secret and redirect back.
	const tokenDataEncoded = btoa(JSON.stringify(credentials));
	const signature = await hmacSign(tokenDataEncoded, siteData.site_secret);

	const callbackUrl = new URL(brokerState.callback_url);
	callbackUrl.searchParams.set('state', brokerState.original_state);
	callbackUrl.searchParams.set('token_data', tokenDataEncoded);
	callbackUrl.searchParams.set('signature', signature);

	return Response.redirect(callbackUrl.toString(), 302);
}

/* ── Helpers ────────────────────────────────────────────── */

function getClientId(provider, env) {
	const map = {
		facebook: env.FACEBOOK_APP_ID,
		linkedin: env.LINKEDIN_CLIENT_ID,
		threads: env.THREADS_APP_ID,
		tumblr: env.TUMBLR_CONSUMER_KEY,
	};
	return map[provider] || '';
}

function getClientSecret(provider, env) {
	const map = {
		facebook: env.FACEBOOK_APP_SECRET,
		linkedin: env.LINKEDIN_CLIENT_SECRET,
		threads: env.THREADS_APP_SECRET,
		tumblr: env.TUMBLR_CONSUMER_SECRET,
	};
	return map[provider] || '';
}

function errorPage(title, message) {
	return `<!DOCTYPE html>
<html>
<head><title>${title}</title>
<style>
	body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; max-width: 500px; margin: 80px auto; text-align: center; color: #333; }
	h1 { color: #d63638; }
	p { color: #666; line-height: 1.6; }
	a { color: #2271b1; }
</style>
</head>
<body>
	<h1>${title}</h1>
	<p>${message}</p>
	<p>Please close this window and try again from your WordPress dashboard.</p>
</body>
</html>`;
}

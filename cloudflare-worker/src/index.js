/**
 * Greenberry Social — OAuth Broker (Cloudflare Worker)
 *
 * Handles OAuth 2.0 flows for Facebook, LinkedIn, Threads, and Tumblr.
 * The WordPress plugin redirects here → we redirect to the platform →
 * platform redirects back → we exchange the code for a token →
 * we redirect back to WordPress with the encrypted token.
 *
 * Security:
 * - All state parameters are HMAC-SHA256 signed
 * - OAuth app secrets never leave this Worker
 * - Tokens are passed back via signed redirect, never stored here
 * - States expire after 10 minutes
 */

const PROVIDERS = {
	facebook: {
		authorizeUrl: 'https://www.facebook.com/v21.0/dialog/oauth',
		tokenUrl: 'https://graph.facebook.com/v21.0/oauth/access_token',
		scopes: 'pages_show_list,pages_manage_posts,pages_read_engagement',
		getCredentials: async (tokenData, env) => {
			// Exchange short-lived token for long-lived token.
			const longLivedRes = await fetch(
				`https://graph.facebook.com/v21.0/oauth/access_token?` +
				`grant_type=fb_exchange_token&client_id=${env.FACEBOOK_APP_ID}` +
				`&client_secret=${env.FACEBOOK_APP_SECRET}` +
				`&fb_exchange_token=${tokenData.access_token}`
			);
			const longLived = await longLivedRes.json();
			if (longLived.error) throw new Error(longLived.error.message);

			// Get user's pages.
			const pagesRes = await fetch(
				`https://graph.facebook.com/v21.0/me/accounts?access_token=${longLived.access_token}`
			);
			const pages = await pagesRes.json();
			if (!pages.data || pages.data.length === 0) {
				throw new Error('No Facebook Pages found for this account.');
			}

			// Return the first page's long-lived token.
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
			// Get the user's profile to build the author URN.
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
			// Exchange for long-lived token.
			const longLivedRes = await fetch(
				`https://graph.threads.net/access_token?` +
				`grant_type=th_exchange_token&client_secret=${tokenData._app_secret}` +
				`&access_token=${tokenData.access_token}`
			);
			const longLived = await longLivedRes.json();

			// Get user ID.
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
			// Get user info to find blog name.
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

/* ── Request handler ────────────────────────────────────── */

export default {
	async fetch(request, env) {
		const url = new URL(request.url);
		const path = url.pathname;

		// Health check.
		if (path === '/health') {
			return new Response(JSON.stringify({ status: 'ok', version: '1.0.0' }), {
				headers: { 'Content-Type': 'application/json' },
			});
		}

		// Route: /auth/{provider} — start OAuth flow.
		const authMatch = path.match(/^\/auth\/([a-z]+)$/);
		if (authMatch) {
			return handleAuth(authMatch[1], url, env);
		}

		// Route: /callback/{provider} — handle OAuth callback.
		const callbackMatch = path.match(/^\/callback\/([a-z]+)$/);
		if (callbackMatch) {
			return handleCallback(callbackMatch[1], url, env);
		}

		return new Response('Not Found', { status: 404 });
	},
};

/* ── Auth: redirect user to platform ────────────────────── */

async function handleAuth(providerName, url, env) {
	const provider = PROVIDERS[providerName];
	if (!provider) {
		return new Response('Unknown provider.', { status: 400 });
	}

	const state = url.searchParams.get('state');
	const callbackUrl = url.searchParams.get('callback_url');

	if (!state || !callbackUrl) {
		return new Response('Missing state or callback_url.', { status: 400 });
	}

	// Verify the state was signed by a valid WordPress site.
	const stateDecoded = atob(state);
	const parts = stateDecoded.split('|');
	if (parts.length < 4) {
		return new Response('Invalid state.', { status: 400 });
	}

	const [stateProvider, nonce, timestamp, sig] = parts;
	const stateData = `${stateProvider}|${nonce}|${timestamp}`;

	const valid = await hmacVerify(stateData, sig, env.BROKER_SECRET);
	if (!valid) {
		return new Response('Invalid state signature.', { status: 403 });
	}

	// Check expiry.
	if (Date.now() / 1000 - parseInt(timestamp, 10) > 600) {
		return new Response('State expired.', { status: 400 });
	}

	// Build broker state (includes original state + callback URL).
	const brokerState = JSON.stringify({
		original_state: state,
		callback_url: callbackUrl,
		provider: providerName,
		ts: Date.now(),
	});
	const brokerStateSig = await hmacSign(brokerState, env.BROKER_SECRET);
	const encodedBrokerState = btoa(brokerState + '|||' + brokerStateSig);

	// Get app credentials.
	const clientId = getClientId(providerName, env);
	const brokerCallbackUrl = `${url.origin}/callback/${providerName}`;

	// Build the authorize URL.
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
		return new Response('Unknown provider.', { status: 400 });
	}

	const code = url.searchParams.get('code');
	const stateParam = url.searchParams.get('state');
	const error = url.searchParams.get('error');

	if (error) {
		return new Response(`OAuth error: ${error}`, { status: 400 });
	}

	if (!code || !stateParam) {
		return new Response('Missing code or state.', { status: 400 });
	}

	// Decode and verify broker state.
	const decoded = atob(stateParam);
	const separatorIndex = decoded.lastIndexOf('|||');
	if (separatorIndex === -1) {
		return new Response('Invalid broker state.', { status: 400 });
	}

	const brokerStateJson = decoded.substring(0, separatorIndex);
	const brokerStateSig = decoded.substring(separatorIndex + 3);

	const valid = await hmacVerify(brokerStateJson, brokerStateSig, env.BROKER_SECRET);
	if (!valid) {
		return new Response('Invalid broker state signature.', { status: 403 });
	}

	const brokerState = JSON.parse(brokerStateJson);

	// Check expiry (10 minutes from when auth was initiated).
	if (Date.now() - brokerState.ts > 600000) {
		return new Response('Broker state expired.', { status: 400 });
	}

	// Exchange code for token.
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
		return new Response(`Token exchange failed: ${JSON.stringify(tokenData)}`, { status: 400 });
	}

	// Pass app secret to provider-specific handler if needed (e.g., Threads long-lived exchange).
	tokenData._app_secret = clientSecret;

	// Get platform-specific credentials.
	let credentials;
	try {
		credentials = await provider.getCredentials(tokenData, env);
	} catch (err) {
		return new Response(`Failed to get credentials: ${err.message}`, { status: 400 });
	}

	// Sign the credentials and redirect back to WordPress.
	const tokenDataEncoded = btoa(JSON.stringify(credentials));
	const signature = await hmacSign(tokenDataEncoded, env.BROKER_SECRET);

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

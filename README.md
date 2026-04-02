# Greenberry Social

A lightweight WordPress plugin for auto-sharing posts to social media. **~120KB** — a 99.3% reduction from Jetpack Social's 16MB.

Zero Jetpack dependencies. Zero Composer. Zero configuration. Install, activate, click Connect.

## Supported Platforms

| Provider | Connection Method |
|----------|------------------|
| **Bluesky** | Enter app password (one field) |
| **Mastodon** | Enter access token (any instance) |
| **Facebook** | Click "Connect with Facebook" |
| **LinkedIn** | Click "Connect with LinkedIn" |
| **Threads** | Click "Connect with Threads" |
| **Tumblr** | Click "Connect with Tumblr" |

## Quick Start

1. Install the plugin (upload `greenberry-social/` or install from marketplace)
2. Activate in WordPress admin
3. Go to **Settings → Greenberry Social**
4. Click **Connect** for each platform you want — that's it

No API keys. No wp-config editing. No broker secrets. Just like Jetpack, but without the 16MB of bloat.

### How it works

- **Bluesky & Mastodon** — direct credential entry. You paste an app password or token. No external service involved.
- **Facebook, LinkedIn, Threads & Tumblr** — one-click OAuth. The plugin automatically registers with the Greenberry OAuth service on activation. Click "Connect with Facebook", log in, authorise, done. The OAuth app credentials are managed by Greenberry — you never see them.

Manual token entry is always available as a fallback for all providers.

## Features

- **Auto-share on first publish** — configurable per post type
- **Per-post controls** — disable sharing, select providers, custom message
- **Message templates** — `{title}`, `{excerpt}`, `{url}`, `{author}`, `{site_name}`, `{date}`
- **Gutenberg sidebar panel** + classic editor meta box
- **Manual re-share button** for already-published posts
- **OG + Twitter Card meta tags** (auto-detects Yoast/Rank Math/AIOSEO/SEO Framework)
- **REST API** for headless use (`/wp-json/gbsocial/v1/`)
- **Credentials encrypted at rest** — libsodium + BLAKE2b key derivation
- **Extensible** — `gbsocial_register_providers` hook for custom networks

## Security Model

**Layer 1 — Encrypted credentials:** All tokens encrypted with libsodium before storage. Key derived from `AUTH_KEY` via BLAKE2b. A database dump alone cannot reveal tokens.

**Layer 2 — Automatic site registration:** On activation, the plugin registers with the Greenberry OAuth broker and receives a unique per-site HMAC secret. This happens automatically — no admin action needed.

**Layer 3 — HMAC-signed OAuth state:** Every WordPress↔Broker round-trip uses HMAC-SHA256 signed with the per-site secret. States expire after 10 minutes. Prevents CSRF, state injection, and token tampering.

**Layer 4 — Broker isolation:** Facebook/LinkedIn/Threads/Tumblr OAuth app secrets live only in Cloudflare Workers Secrets (encrypted at rest by Cloudflare, never logged, never in source code). WordPress sites never see the app secrets.

## REST API

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/gbsocial/v1/settings` | GET | Current configuration |
| `/gbsocial/v1/providers` | GET | List providers & status |
| `/gbsocial/v1/test/{provider}` | POST | Test a connection |
| `/gbsocial/v1/share/{post_id}` | POST | Manually share a post |
| `/gbsocial/v1/oauth/callback` | GET | OAuth callback handler |

## Self-hosting the OAuth Broker (optional)

If you want to run your own broker instead of using Greenberry's:

```bash
cd cloudflare-worker
wrangler kv namespace create SITES       # create the KV store
# update wrangler.toml with the KV namespace ID
wrangler secret put FACEBOOK_APP_ID      # set your OAuth app credentials
wrangler secret put FACEBOOK_APP_SECRET
# ... repeat for LinkedIn, Threads, Tumblr
wrangler deploy
```

Then change `GBSOCIAL_BROKER_URL` in `greenberry-social.php` to your Worker URL.

## Requirements

- WordPress 6.0+
- PHP 8.0+ with libsodium (bundled with PHP 7.2+)

## License

MIT

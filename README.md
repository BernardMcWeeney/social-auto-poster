# Greenberry Social

A lightweight WordPress plugin for auto-sharing posts to social media. **~120KB** — a 99.3% reduction from Jetpack Social's 16MB.

Zero Jetpack dependencies. Zero Composer. Drop in and activate.

## Supported Platforms

| Provider | Auth Method | Broker Needed? |
|----------|------------|----------------|
| **Bluesky** | App Password | No |
| **Mastodon** | Access Token | No |
| **Facebook** | OAuth 2.0 / Manual Token | Optional |
| **LinkedIn** | OAuth 2.0 / Manual Token | Optional |
| **Threads** | OAuth 2.0 / Manual Token | Optional |
| **Tumblr** | OAuth 2.0 / Manual Token | Optional |

## Quick Start

1. Copy `greenberry-social/` to `wp-content/plugins/`
2. Activate in WordPress admin
3. Go to **Settings → Greenberry Social**
4. Connect your social accounts

### Bluesky / Mastodon (no broker needed)
Enter your credentials directly — app password (Bluesky) or access token (Mastodon).

### Facebook / LinkedIn / Threads / Tumblr (OAuth)

**Option A — With the Cloudflare Worker broker (recommended):**

1. Deploy the broker: `cd cloudflare-worker && npx wrangler deploy`
2. Set secrets:
   ```bash
   npx wrangler secret put BROKER_SECRET
   npx wrangler secret put FACEBOOK_APP_ID
   npx wrangler secret put FACEBOOK_APP_SECRET
   # ... etc for each platform
   ```
3. Add to `wp-config.php`:
   ```php
   define('GBSOCIAL_OAUTH_BROKER_URL', 'https://social-oauth.greenberry.ie');
   define('GBSOCIAL_BROKER_SECRET', 'your-shared-secret');
   ```
4. Users get a one-click "Connect with Facebook" button.

**Option B — Manual token entry:**
Generate tokens via each platform's developer tools and paste them directly. No broker needed.

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

**Layer 1 — Encrypted credentials:** All tokens encrypted with libsodium before storage. Key derived from `AUTH_KEY` via BLAKE2b.

**Layer 2 — HMAC-signed OAuth state:** WordPress↔Broker round-trip uses HMAC-SHA256. States expire after 10 minutes.

**Layer 3 — Broker isolation:** OAuth app secrets live only in Cloudflare Workers Secrets. WordPress sites never see them.

## REST API

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/gbsocial/v1/settings` | GET | Current configuration |
| `/gbsocial/v1/providers` | GET | List providers & status |
| `/gbsocial/v1/test/{provider}` | POST | Test a connection |
| `/gbsocial/v1/share/{post_id}` | POST | Manually share a post |
| `/gbsocial/v1/oauth/callback` | GET | OAuth callback handler |

## Requirements

- WordPress 6.0+
- PHP 8.0+ with libsodium extension (included in PHP 7.2+)

## License

MIT

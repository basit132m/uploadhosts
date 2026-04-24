# UploadHost — Setup Guide

## Architecture

```
Browser  →  PUT (direct, XHR with progress)  →  Cloudflare R2
        ↕
      POST /api/sign.php  (tiny PHP, only signs URL — no file bytes touch the server)
```

Zero file bytes pass through Hostinger. PHP only generates a signed URL.

---

## Step 1 — Configure R2 Credentials

Copy `config.php` to your server and fill in your values:

```php
define('R2_ACCOUNT_ID',      'abc123...');        // Cloudflare account ID
define('R2_ACCESS_KEY_ID',   'your-key-id');
define('R2_SECRET_KEY',      'your-secret');
define('R2_BUCKET',          'my-uploads');

// Public URL where uploaded files can be accessed
define('R2_PUBLIC_BASE_URL', 'https://files.uploadhost.site');
```

> **Important:** `config.php` is blocked from web access via `.htaccess`.
> Never commit it to git (it's in `.gitignore`).

---

## Step 2 — Make R2 Bucket Public (for shareable links)

Choose ONE option:

### Option A — Custom domain (recommended)
1. In Cloudflare R2 dashboard → your bucket → **Settings** → **Custom Domains**
2. Add `files.uploadhost.site`
3. Set `R2_PUBLIC_BASE_URL=https://files.uploadhost.site` in `config.php`

### Option B — R2 public dev URL
1. Cloudflare R2 dashboard → your bucket → **Settings** → **Public Access** → Enable
2. Copy the `pub-XXXX.r2.dev` URL shown
3. Set `R2_PUBLIC_BASE_URL=https://pub-XXXX.r2.dev` in `config.php`

---

## Step 3 — Configure R2 CORS

In Cloudflare R2 dashboard → your bucket → **Settings** → **CORS Policy**, add:

```json
[
  {
    "AllowedOrigins": ["https://uploadhost.site"],
    "AllowedMethods": ["PUT", "GET", "HEAD"],
    "AllowedHeaders": ["Content-Type", "Content-Length"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }
]
```

> For local development, also add `http://localhost` to `AllowedOrigins`.

---

## Step 4 — Deploy to Hostinger

Upload via Hostinger File Manager or FTP (exclude `config.php` from git):

```
public_html/
├── .htaccess
├── index.html
├── assets/
│   ├── style.css
│   └── app.js
├── api/
│   ├── .htaccess
│   └── sign.php
└── config.php     ← upload manually, never commit to git
```

> If Hostinger uses `public_html`, upload to that folder.
> PHP is enabled by default on all Hostinger shared plans.

---

## Step 5 — Verify

1. Open `https://uploadhost.site`
2. Drop a small file
3. Watch the progress bar — it should go to 100% and show a copy link
4. Click the link to verify the file is accessible

---

## Limits & Tuning

| Setting | Location | Default |
|---------|----------|---------|
| Max file size | `config.php → MAX_FILE_SIZE_MB` | 500 MB |
| Presign URL expiry | `config.php → PRESIGN_EXPIRES_SEC` | 3600 s |
| Concurrent uploads | `assets/app.js → CONCURRENT` | 3 |
| Allowed origin | `config.php → ALLOWED_ORIGIN` | your domain |

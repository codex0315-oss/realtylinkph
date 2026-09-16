# Deploying RealtyLink PH

Frontend → **Vercel**. Backend → **Render**. Database → Render PostgreSQL.
Uploads → **Cloudflare R2 or AWS S3** (not the app's disk — see below).

> Work top to bottom. Steps 1–3 are the ones that break a demo if skipped.

---

## 1. File storage — do this first

**Render's filesystem is ephemeral.** It is wiped on every deploy, restart and
sleep/wake. Anything written to the app's own disk is gone by the next deploy,
so listing photos and agent documents would vanish mid-demo.

Create a bucket (Cloudflare R2 has a free tier; AWS S3 works identically), then
set on the Render service:

```
UPLOAD_DISK=s3
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=auto            # 'auto' for R2; a real region for S3
AWS_BUCKET=realtylinkph
AWS_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com   # blank for S3
AWS_URL=https://pub-xxxxxxxx.r2.dev                          # public bucket URL
AWS_USE_PATH_STYLE_ENDPOINT=true
```

Make the bucket's objects publicly readable (listing photos are shown to
anonymous visitors). Agent documents are only ever linked from admin screens,
but note they live in the same bucket — if that matters to you, use a second
private bucket and signed URLs.

Nothing in the code needs changing: every upload goes through
`App\Support\Uploads`, which reads `config('filesystems.uploads')`.

---

## 2. Render — you need TWO services, not one

The app runs four processes. On Render:

### Web Service (the API)

- **Build:** `composer install --no-dev --optimize-autoloader && php artisan config:cache && php artisan route:cache`
- **Start:** `php artisan serve --host 0.0.0.0 --port $PORT`
  (or `heroku-php-apache2 public/` if you prefer a real web server)
- **Health check path:** `/up`

### Background Worker (queue + scheduler)

Emails, the AI auto-reply, and the document-purge job all run here. Without it
they simply never happen.

- **Start:** `php artisan queue:work --tries=3 --timeout=90`

> Use `queue:work` in production (long-lived, faster). `queue:listen` is the dev
> choice because it reloads code per job — see the README.

For the hourly jobs (`properties:score-featured`,
`agents:purge-rejected-documents`), add a **Render Cron Job**:

- **Schedule:** `*/5 * * * *`
- **Command:** `php artisan schedule:run`

### Reverb (websockets) — optional but recommended

Real-time chat, typing indicators and live notifications need it.

- Separate **Web Service**, start: `php artisan reverb:start --host 0.0.0.0 --port $PORT`
- Render terminates TLS, so the browser connects over **WSS on port 443**

Set on the backend:

```
REVERB_HOST=realtylinkph-reverb.onrender.com
REVERB_PORT=443
REVERB_SCHEME=https
```

If you skip Reverb the app still works — messages just need a refresh.

---

## 3. Environment — production values

On the **Render web service and worker** (both need the full set):

```
APP_ENV=production
APP_DEBUG=false                 # ← critical: true leaks stack traces publicly
APP_KEY=<php artisan key:generate --show>
APP_URL=https://realtylinkph-api.onrender.com
FRONTEND_URL=https://realtylinkph.vercel.app
LOG_LEVEL=warning

DB_CONNECTION=pgsql             # Render gives you these in the DB dashboard
DB_HOST=... DB_PORT=5432 DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=...

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database

CORS_ALLOWED_ORIGINS=https://realtylinkph.vercel.app
# Vercel preview deploys get generated subdomains — allow them if you use them:
# CORS_ALLOWED_ORIGIN_PATTERNS=#^https://realtylinkph-.*\.vercel\.app$#

GOOGLE_REDIRECT_URI=https://realtylinkph.vercel.app/google/callback
GOOGLE_LOGIN_REDIRECT_URI=https://realtylinkph.vercel.app/auth/google/callback
```

⚠️ **Both Google redirect URIs must also be added to the Google Cloud console**
(APIs & Services → Credentials → your OAuth client), or sign-in fails with
`redirect_uri_mismatch`.

On **Vercel**:

```
NUXT_PUBLIC_API_BASE=https://realtylinkph-api.onrender.com/api
NUXT_PUBLIC_APP_URL=https://realtylinkph.vercel.app
NUXT_PUBLIC_GEOAPIFY_KEY=...
NUXT_PUBLIC_REVERB_HOST=realtylinkph-reverb.onrender.com
NUXT_PUBLIC_REVERB_PORT=443
NUXT_PUBLIC_REVERB_KEY=<same as backend REVERB_APP_KEY>
```

Vercel settings: **Root Directory** `realtylinkph-frontend`, framework Nuxt
(auto-detected).

---

## 4. First deploy

```bash
php artisan migrate --force
php artisan db:seed --force        # optional: demo accounts + listings
```

Render can run these in the build command, or via a one-off Shell.

`php artisan storage:link` is **not** needed when `UPLOAD_DISK=s3`.

---

## 5. Rotate your keys

Every credential that has lived in a local `.env` — or been pasted into a chat
or screenshot — should be regenerated before it protects a public service:
Groq, Gemini, Geoapify, Google OAuth client secret, Brevo SMTP, and the
database password.

---

## Before you demo

- [ ] Open the site ~2 minutes early. **Render's free tier sleeps after 15 min
      idle and takes ~50s to wake.** A cold start mid-presentation looks broken.
- [ ] Upload one listing photo and confirm it still loads after a redeploy —
      that proves S3/R2 is actually wired up.
- [ ] Send a message between two accounts to confirm Reverb is connected.
- [ ] Check the worker service is running (queue jobs = emails + AI replies).
- [ ] Confirm `APP_DEBUG=false`: visit a bad URL and check you get a plain
      error page, not a stack trace.

---

## Known limitations (be ready to say these out loud)

- **No automated tests.** Everything has been verified by hand.
- **Free-tier cold starts** make the first request slow.
- **Agent documents share the public bucket** — fine for a demo, would need a
  private bucket with signed URLs for real use.
- **No admin UI to waive a cancellation strike** — the data model supports it,
  but it currently needs a database edit.

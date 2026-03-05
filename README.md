# Dialer Backend V2

Lumen (PHP 8) API backend for the Phonify / Linkswitchcommunications dialer platform.

## Requirements

- PHP 8.1+
- MySQL 8.0+
- Redis 6+
- Asterisk PBX (AMI access)
- Composer

## Setup

```bash
composer install
cp .env.example .env
# Fill in all required values (see below)
php artisan migrate --path=database/migrations/master   # master DB tables
php artisan migrate --path=database/migrations/client   # per-client DB tables
```

## Environment Variables

Copy `.env.example` to `.env` and fill in all required values.

---

### Application

| Variable | Required | Description |
|----------|----------|-------------|
| `APP_NAME` | Yes | Application name (e.g. `Phonify`) |
| `APP_ENV` | Yes | `local`, `staging`, or `production` |
| `APP_KEY` | Yes | 32-character random string — used for encryption |
| `APP_DEBUG` | Yes | `true` in dev, `false` in production |
| `APP_URL` | Yes | Backend base URL (e.g. `https://api.phonify.app`) |
| `API_URL` | No | Internal API URL override |
| `APP_TIMEZONE` | Yes | PHP timezone (e.g. `America/New_York`) |

---

### Database (Master)

| Variable | Required | Description |
|----------|----------|-------------|
| `DB_CONNECTION` | Yes | `mysql` |
| `DB_HOST` | Yes | MySQL host |
| `DB_PORT` | Yes | MySQL port (default `3306`) |
| `DB_DATABASE` | Yes | Master database name |
| `DB_USERNAME` | Yes | MySQL username |
| `DB_PASSWORD` | Yes | MySQL password |
| `NEW_CLIENT_HOST` | Yes | MySQL host for provisioning new client DBs |
| `NEW_CLIENT_PORT` | Yes | Port for new client DB host |
| `NEW_CLIENT_USERNAME` | Yes | MySQL user with CREATE DATABASE privilege |
| `NEW_CLIENT_PASSWORD` | Yes | Password for `NEW_CLIENT_USERNAME` |

---

### Cache & Sessions

| Variable | Required | Description |
|----------|----------|-------------|
| `CACHE_DRIVER` | Yes | `redis` (recommended) or `file` |
| `SESSION_DRIVER` | Yes | `redis` or `file` |
| `REDIS_CLIENT` | Yes | `predis` or `phpredis` |
| `REDIS_HOST` | Yes | Redis host |
| `REDIS_PASSWORD` | No | Redis password (leave blank if none) |
| `REDIS_PORT` | Yes | Redis port (default `6379`) |
| `REDIS_DB` | Yes | Redis DB index for session/general cache |
| `REDIS_CACHE_DB` | Yes | Redis DB index for application cache |

---

### Queue

| Variable | Required | Description |
|----------|----------|-------------|
| `QUEUE_CONNECTION` | Yes | `database` or `redis` |
| `QUEUE_FAILED_DRIVER` | Yes | `database` |
| `JOBS_TABLE` | Yes | Table name for queued jobs |
| `FAILED_JOBS_TABLE` | Yes | Table name for failed jobs |

---

### Authentication & Security

| Variable | Required | Description |
|----------|----------|-------------|
| `JWT_SECRET` | Yes | HS256 JWT signing secret — keep private, min 32 chars |
| `X_CLIENT` | Yes | Internal client header key for inter-service calls |
| `EASIFY_APP_KEY` | No | API key for Easify platform V2 login integration |

---

### Asterisk / PBX

| Variable | Required | Description |
|----------|----------|-------------|
| `ASTERISK_AMI_HOST` | Yes | Asterisk AMI host IP/hostname |
| `asterisk_conf` | Yes | Path to Asterisk config directory on AMI server |

---

### Pusher (Real-time)

| Variable | Required | Description |
|----------|----------|-------------|
| `PUSHER_APP_ID` | Yes | Pusher application ID |
| `PUSHER_APP_KEY` | Yes | Pusher application key (public) |
| `PUSHER_APP_SECRET` | Yes | Pusher application secret (private) |
| `PUSHER_APP_CLUSTER` | Yes | Pusher cluster (e.g. `us2`) |

---

### SMS

| Variable | Required | Description |
|----------|----------|-------------|
| `SMS_API` | No | SMS gateway API key |
| `SMS_ACCESS` | No | SMS gateway access token |
| `SMS_URL_API` | No | SMS gateway base URL |
| `SMS_NUMBER` | No | Default outbound SMS number |
| `PLIVO_SMS_NUMBER` | No | Plivo SMS DID |
| `PLIVO_USER` | No | Plivo Auth ID |
| `PLIVO_PASS` | No | Plivo Auth Token |
| `SMS_SERVICE_URL` | No | SMS microservice URL |
| `SMS_SERVICE_KEY` | No | SMS microservice API key |
| `SMS_SERVICE_TOKEN` | No | SMS microservice auth token |
| `SMS_FROM_NUMBER` | No | Fallback outbound number |

---

### SMS AI (Telnyx)

| Variable | Required | Description |
|----------|----------|-------------|
| `TELNYX_SMS_AI_TOKEN` | No | Telnyx API token for SMS AI |
| `TELNYX_SMS_AI_WEBHOOK` | No | Telnyx webhook URL |
| `TELNYX_SMS_AI_URL` | No | Telnyx API base URL |
| `SMS_AI_LIST_FILE_UPLOAD_PATH` | No | Absolute path for SMS AI list file uploads (must end with `/`) |

---

### Ringless Voicemail

| Variable | Required | Description |
|----------|----------|-------------|
| `RINGLESS_VOICEMAIL` | No | RVM provider API key |
| `RINGLESS_LIST_FILE_UPLOAD_PATH` | No | Absolute path for RVM list uploads (must end with `/`) |

---

### DID / Telnyx

| Variable | Required | Description |
|----------|----------|-------------|
| `DID_SALE_API_URL` | No | DID provisioning API URL |
| `DID_SALE_SERVICE_KEY` | No | DID service key |
| `DID_SALE_SERVICE_TOKEN` | No | DID service token |
| `DID_TELNYX_API_URL` | No | Telnyx API URL |
| `TELNYX_CDR_REPORT_URL` | No | Telnyx CDR reporting URL |
| `DID_FORWARD_SMS_URL` | No | SMS forwarding webhook URL |
| `DID_FORWARD_FAX_URL` | No | Fax forwarding webhook URL |
| `TEL_PRIVATE_KEY` | No | RSA private key for Telnyx webhook signature verification |

---

### Stripe / Billing

| Variable | Required | Description |
|----------|----------|-------------|
| `STRIPE_KEY` | No | Stripe publishable key |
| `STRIPE_SECRET` | No | Stripe secret key |
| `CALL_CHARGE_SECRET` | No | Shared secret for call charging webhook |
| `PREDICTIVE_CALL_TOKEN` | No | Token for predictive dialer billing |

---

### Email (OTP / Error / Portal)

| Variable | Required | Description |
|----------|----------|-------------|
| `OTP_MAIL_HOST` | No | SMTP host for OTP emails |
| `OTP_MAIL_PORT` | No | SMTP port |
| `OTP_MAIL_USERNAME` | No | SMTP username |
| `OTP_MAIL_PASSWORD` | No | SMTP password |
| `OTP_MAIL_ENCRYPTION` | No | `tls` or `ssl` |
| `ERROR_MAIL_HOST` | No | SMTP host for error alert emails |
| `ERROR_MAIL_PORT` | No | SMTP port |
| `ERROR_MAIL_USERNAME` | No | SMTP username |
| `ERROR_MAIL_PASSWORD` | No | SMTP password |
| `ERROR_MAIL_ENCRYPTION` | No | `tls` or `ssl` |
| `PORTAL_MAIL_HOST` | No | SMTP host for portal/invoice emails |
| `PORTAL_MAIL_PORT` | No | SMTP port |
| `PORTAL_MAIL_USERNAME` | No | SMTP username |
| `PORTAL_MAIL_PASSWORD` | No | SMTP password |
| `PORTAL_MAIL_SENDER_NAME` | No | Display name for portal emails |
| `portal_mail_sender_email` | No | Sender address for portal emails |
| `PORTAL_MAIL_ENCRYPTION` | No | `tls` or `ssl` |

---

### Branding / Portal

| Variable | Required | Description |
|----------|----------|-------------|
| `PORTAL_NAME` | No | Portal display name |
| `SITE_NAME` | No | Site name used in emails |
| `SITE_NAME_LOGO` | No | URL to site logo |
| `DEFAULT_COMPANY_NAME` | No | Default company name for new accounts |
| `DEFAULT_EMAIL` | No | Default admin email |
| `DEFAULT_NAME` | No | Default admin display name |
| `SYSTEM_ADMIN_EMAIL` | No | Internal system admin email |
| `SUPPORT_TEAM_EMAIL_GROUP` | No | Comma-separated support team emails |
| `SALES_TEAM_EMAIL_GROUP` | No | Comma-separated sales team emails |
| `SUPPORT_TEAM_TEXT_GROUP` | No | SMS group for support alerts |
| `SALES_TEAM_TEXT_GROUP` | No | SMS group for sales alerts |

---

### Invoice

| Variable | Required | Description |
|----------|----------|-------------|
| `INVOICE_COMPANY_LOGO` | No | URL to company logo for invoices |
| `INVOICE_COMPANY_NAME` | No | Company name on invoices |
| `INVOICE_COMPANY_ADDRESS1` | No | Address line 1 |
| `INVOICE_COMPANY_ADDRESS2` | No | Address line 2 |
| `INVOICE_COMPANY_PHONE` | No | Phone number on invoices |
| `INVOICE_COMPANY_EMAIL` | No | Email address on invoices |
| `SIGNED_APPLICATION_PDF_LOGO` | No | Logo path for signed application PDFs |
| `SIGNED_APPLICATION_SIGNATURE_IMG` | No | Signature image for PDFs |

---

### Google / Gmail

| Variable | Required | Description |
|----------|----------|-------------|
| `GOOGLE_CLIENT_ID` | No | Google OAuth 2.0 client ID |
| `GOOGLE_CLIENT_SECRET` | No | Google OAuth 2.0 client secret |
| `GOOGLE_GMAIL_REDIRECT_URI` | No | OAuth redirect URI for Gmail |
| `GOOGLE_CLOUD_PROJECT_ID` | No | Google Cloud project ID |
| `GOOGLE_JSON_KEY` | No | Path to Google service account JSON key |
| `GMAIL_PUBSUB_TOPIC` | No | Google Pub/Sub topic for Gmail push |
| `GMAIL_PUBSUB_SUBSCRIPTION` | No | Google Pub/Sub subscription name |

---

### OpenAI

| Variable | Required | Description |
|----------|----------|-------------|
| `OPENAI_API_KEY` | No | OpenAI API key for AI features |
| `OPENAI_MODEL` | No | Model ID (e.g. `gpt-4o`) |

---

### Logging

| Variable | Required | Description |
|----------|----------|-------------|
| `LOG_CHANNEL` | Yes | `stack`, `daily`, or `slack` |
| `LOG_SLACK_WEBHOOK_URL` | No | Slack webhook for error notifications |

---

### Misc

| Variable | Required | Description |
|----------|----------|-------------|
| `SWAGGER_GENERATE_ALWAYS` | No | `true` to auto-regenerate Swagger docs |
| `FILE_UPLOAD_PATH` | No | Absolute path for general file uploads |
| `SERVER_API_KEY` | No | Internal server-to-server API key |
| `ROHIT_EMAIL` | No | Developer notification email |
| `VIJAY_EMAIL` | No | Developer notification email |

---

## Security Notes

- **Never commit `.env`** — it is in `.gitignore`
- `JWT_SECRET` must be at least 32 random characters
- `STRIPE_SECRET`, `OPENAI_API_KEY`, and `TEL_PRIVATE_KEY` are high-value secrets — rotate immediately if exposed
- `APP_DEBUG=false` in production — debug mode exposes stack traces
- `SMS_AI_LIST_FILE_UPLOAD_PATH` and `RINGLESS_LIST_FILE_UPLOAD_PATH` must be outside the webroot

## Architecture Notes

- Multi-tenant: each client gets its own MySQL database (`mysql_{parent_id}`)
- JWT HS256 auth — no refresh token, re-login required on expiry
- Real-time events via Pusher (channel `my-channel`, event `my-event`)
- Asterisk AMI integration for call control
- Audit logging: all admin-level (level ≥ 7) POST/PUT/PATCH/DELETE actions are written to `audit_log` in the master DB

# RVM v2 cutover runbook

> **Scope:** Phase 5b — legacy → v2 divert, dry-run mode (sections 0–7).
> **Phase 5c** — dry_run → live cutover with real carrier dispatch
> (sections 8–12) is now covered below.

This runbook walks an operator through moving one tenant from the legacy RVM
pipeline (`SendRvmJob` → AMI/Asterisk) onto the v2 pipeline (`RvmDropService`
→ `ProcessRvmDropJob` → provider router → real carrier). Sections 0–7 cover
the dry_run soak using MockProvider. Sections 8–12 cover promoting that
tenant onto a real carrier (Twilio / Plivo / Slybroadcast) with a daily-cap
ramp and emergency rollback. Every step up to the live flip is reversible by
a single `UPDATE`; the live flip itself is also reversible, but any rows
already dispatched to the carrier obviously can't be unsent.

---

## 0. Pre-flight

Before you touch any tenant flags, confirm the v2 stack is healthy:

- **Master DB migrations** applied up to at least
  `2026_04_11_200001_add_divert_columns_to_rvm_cdr_log.php`:
  ```
  php artisan migrate:status --database=master | grep rvm
  ```
  All rows should read `Ran`. If anything reads `Pending`, run
  `php artisan migrate --database=master --force`.
- **Redis** reachable from both the web API and the queue worker
  (`redis-cli ping` = `PONG`). RvmDropService uses Redis for idempotency
  and rate-limiting; divert skips the tenant if either subsystem is down.
- **v2 queue worker running:** `php artisan queue:work redis --queue=rvm`
  should be in supervisor. Divert enqueues `ProcessRvmDropJob` onto this
  queue; without a worker, diverted drops just accumulate in `queued` state.
- **Mock provider enabled** in `config/rvm.php`:
  ```php
  'providers' => [
      'mock' => ['enabled' => true],
      ...
  ]
  ```
  Dry-run mode forces `provider = mock`, so the mock driver must be
  registered by `RvmServiceProvider`.
- **Smoke test green** on a throwaway tenant id:
  ```
  php tests/rvm_divert_smoke.php
  ```
  Expect `25 passed, 0 failed` and a clean exit 0.

If any of the above fails, stop — do **not** flip a real tenant yet.

---

## 1. Pick a wave-1 tenant

Criteria for a good first candidate:

- Has `api_key` populated in `master.clients` (backfill queries `rvm_cdr_log.api_token
  = clients.api_key`).
- Has a low-volume RVM footprint — <5k drops/day is a comfortable starting point.
- Has an operator contact on-call for the cutover window.
- Not using any unusual legacy extensions (custom dialplans, hand-written
  `voicemail_file_name` paths that the v2 audio resolver doesn't know about).

Record the numeric `client_id` — we'll refer to it as `$CLIENT_ID` for the
rest of the runbook.

---

## 2. Shadow mode (24h soak)

**Goal:** Confirm the legacy payload → v2 translation doesn't blow up under
real traffic *before* anything short-circuits the legacy dispatcher.

```sql
-- on master
INSERT INTO rvm_tenant_flags (client_id, pipeline_mode, enabled_by_user_id, notes, created_at, updated_at)
VALUES ($CLIENT_ID, 'shadow', 1, 'Phase 5b wave 1 shadow soak', NOW(), NOW())
ON DUPLICATE KEY UPDATE pipeline_mode='shadow', updated_at=NOW();
```

In `shadow` mode, `SendRvmJob` continues to dial via AMI as before. It also
writes a `rvm_shadow_log` row per dispatch and then calls `RvmDivertService::divert()`
— which, because `shadow !== dry_run`, immediately returns
`DivertResult::skipped('mode_not_diverted')`. So nothing is written to
`rvm_drops` yet. The value of this phase is that the shadow path lets us
watch for payload-level anomalies (missing phone, missing cli, unknown
voicemail id) without affecting live calls.

**What to watch (during soak):**
- `tail -f storage/logs/lumen.log | grep -i 'RvmShadow\|RvmDivert'` — any
  WARN / ERROR lines indicate translation trouble.
- `rvm_shadow_log`: row count should climb at roughly the same rate as
  `rvm_cdr_log` for this tenant.
  ```sql
  SELECT COUNT(*) FROM rvm_shadow_log WHERE client_id = $CLIENT_ID AND created_at > NOW() - INTERVAL 1 HOUR;
  SELECT COUNT(*) FROM rvm_cdr_log    WHERE api_token = (SELECT api_key FROM clients WHERE id = $CLIENT_ID) AND created_at > NOW() - INTERVAL 1 HOUR;
  ```
  If shadow is materially lower than cdr, something in the legacy payload
  isn't matching `resolveClientIdFromLegacyPayload()` — investigate before
  moving on.

Soak for at least 24 hours (ideally over a peak-volume window).

---

## 3. Flip to dry_run

**Goal:** Route legacy dispatches through the full v2 pipeline, using the
mock provider so nothing actually dials. This is the first phase where
`rvm_drops` rows start appearing for the tenant.

```sql
UPDATE rvm_tenant_flags
   SET pipeline_mode = 'dry_run', updated_at = NOW()
 WHERE client_id = $CLIENT_ID;
```

What happens on the next `SendRvmJob::handle()` for this tenant:

1. `recordShadowObservation()` still runs (cheap, logs to `rvm_shadow_log`).
2. `attemptDivert()` resolves the tenant mode, sees `dry_run`, and calls
   `RvmDivertService::divert()`.
3. The divert service translates the payload, forces `providerHint = 'mock'`,
   calls `RvmDropService::createDrop()` with idempotency key
   `legacy_cdr:{$CLIENT_ID}:{$cdrId}`.
4. On success, `rvm_cdr_log` is stamped with `v2_drop_id`, `divert_mode = 'dry_run'`,
   and `diverted_at = NOW()`. `SendRvmJob::handle()` returns early — **the
   legacy AMI dispatch is skipped for that row.**
5. The v2 worker picks up `ProcessRvmDropJob`, runs compliance, commits the
   wallet reservation, and dispatches through MockProvider (instant success).

**What to watch (first 30 minutes):**

```sql
-- Should climb from 0
SELECT COUNT(*) FROM rvm_drops WHERE client_id = $CLIENT_ID;

-- Divert linkage — every recent cdr row should have diverted_at set
SELECT
  COUNT(*)                                         AS total,
  SUM(CASE WHEN diverted_at IS NOT NULL THEN 1 END) AS diverted,
  SUM(CASE WHEN v2_drop_id  IS NOT NULL THEN 1 END) AS linked
FROM rvm_cdr_log
WHERE api_token = (SELECT api_key FROM clients WHERE id = $CLIENT_ID)
  AND created_at > NOW() - INTERVAL 30 MINUTE;

-- v2 drops should all be delivered (mock provider = instant)
SELECT status, COUNT(*) FROM rvm_drops
 WHERE client_id = $CLIENT_ID
   AND created_at > NOW() - INTERVAL 30 MINUTE
 GROUP BY status;
```

Ideal state after 30 minutes:
- `total ≈ diverted ≈ linked` for the recent window.
- `rvm_drops` all in `status = delivered` (or a mix of `queued` / `delivered`
  if the worker is chewing through a backlog).
- No ERROR lines in the app log for `RvmDivertService`.

---

## 4. Backfill in-flight legacy rows

After the flag flip there's a brief window where rows already queued by
`SendRvmJob` haven't been picked up yet. Those rows will naturally divert
the next time the worker processes them, but if you want a clean break,
walk them eagerly:

```
# Preview first
php artisan rvm:backfill-legacy --client=$CLIENT_ID --dry-run

# Execute (default limit 500)
php artisan rvm:backfill-legacy --client=$CLIENT_ID

# Walk bigger batches if needed
php artisan rvm:backfill-legacy --client=$CLIENT_ID --limit=5000

# Optionally include rows that the legacy side already marked 'initiated'
php artisan rvm:backfill-legacy --client=$CLIENT_ID --include-initiated
```

The command refuses to run unless the tenant is already in `dry_run` mode
(the contract that rows get diverted must be established before backfill).
Rows already stamped with `diverted_at` are skipped by `RvmDivertService` —
re-running the command is safe.

Expected output — a table like:

```
+---------+----------+---------+--------+
| scanned | diverted | skipped | failed |
+---------+----------+---------+--------+
| 482     | 480      | 2       | 0      |
+---------+----------+---------+--------+
```

`skipped` is usually `already_diverted` (worker beat backfill to a row) or
`translate_failed` (legacy payload missing phone/cli — rare, but investigate
if it spikes).

---

## 5. Monitor for 48 hours

Key dashboards / SQL to watch:

- **Divert coverage** — ratio of legacy cdr rows that ended up with v2
  linkage. Should stabilize >99%.
  ```sql
  SELECT
    DATE(created_at) AS day,
    SUM(CASE WHEN diverted_at IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*) AS coverage
  FROM rvm_cdr_log
  WHERE api_token = (SELECT api_key FROM clients WHERE id = $CLIENT_ID)
    AND created_at > NOW() - INTERVAL 7 DAY
  GROUP BY day ORDER BY day;
  ```
- **Drop status distribution** — should be essentially all `delivered`
  under the mock provider. Any `failed` row means MockProvider itself
  rejected something, which should not happen and warrants investigation.
- **Wallet activity** — dry-run drops still cost wallet cents (reserve +
  commit against the mock provider's `estimateCost()` = 0.5c). Expect
  `rvm_wallet_ledger` rows to appear at roughly the rate of diverted drops.

If anything looks off, **flip back immediately**:

```sql
UPDATE rvm_tenant_flags SET pipeline_mode = 'shadow', updated_at = NOW()
 WHERE client_id = $CLIENT_ID;
```

A shadow flip instantly restores the legacy AMI dispatch for all new rows.
It does not undo already-diverted rows — those remain in `rvm_drops`, and
`rvm_cdr_log` retains the `diverted_at` stamp. That's fine; the historical
record is preserved and no new double-dispatches can happen.

---

## 6. Rollback (if needed)

| From    | To      | Action                                                                 | Safe? |
| ------- | ------- | ---------------------------------------------------------------------- | ----- |
| shadow  | legacy  | `DELETE FROM rvm_tenant_flags WHERE client_id = $CLIENT_ID;`           | yes   |
| dry_run | shadow  | `UPDATE rvm_tenant_flags ... SET pipeline_mode='shadow';`              | yes   |
| dry_run | legacy  | `UPDATE ... SET pipeline_mode='legacy';`                               | yes   |
| live    | dry_run | `UPDATE ... SET pipeline_mode='dry_run';` (see §12 — preferred first step) | yes   |
| live    | shadow  | `UPDATE ... SET pipeline_mode='shadow';`                               | yes   |
| live    | legacy  | `UPDATE ... SET pipeline_mode='legacy';` (nuclear — use only if v2 is broken) | yes   |

All are instant and reversible at the flag level. The only thing you **cannot**
undo is:
- Rows already written to `rvm_drops` / `rvm_cdr_log.v2_drop_id` — audit data
  only, harmless to leave.
- Rows already dispatched to a real carrier under `live` mode — once the
  carrier accepted the job, the voicemail is out the door. Rollback prevents
  *future* dispatches, not past ones.

---

## 7. Sign-off checklist

Before moving on to wave 2, confirm on wave 1 tenant:

- [ ] 24h of shadow mode, no warnings in `rvm_shadow_log`/app log.
- [ ] 48h of dry_run mode, divert coverage >99%, no `failed` drops, no
      ERROR from `RvmDivertService`.
- [ ] `rvm:backfill-legacy` ran once with zero failures.
- [ ] Tenant operator confirms no user-visible regression (call volumes,
      customer complaints, reporting).
- [ ] Wallet accounting matches expectations — no unexpected commit/refund
      drift.

Once all boxes are checked, the tenant is "proven on v2 in dry_run" and is
ready to enter the Phase 5c live cutover wave (sections 8–12 below).

---

## 8. Phase 5c — live cutover prerequisites

**Goal:** Confirm the tenant is ready to promote from `dry_run` (mock provider)
to `live` (real carrier) dispatch. This section is read-only — it does **not**
flip any flags.

Before running the readiness audit, make sure the v2 stack has the
infrastructure a live provider actually needs:

- **Master migration applied:** `2026_04_11_210001_add_live_columns_to_rvm_tenant_flags.php`.
  Check:
  ```
  php artisan migrate:status --database=master | grep live_columns
  ```
  The row should read `Ran`. Without it, `rvm_tenant_flags.live_provider`
  doesn't exist and RvmDivertService will fall over on the first live row.
- **Provider credentials + enabled flag** set in `config/rvm.php` via env:
  - **Twilio:** `RVM_TWILIO_ENABLED=true`, `RVM_TWILIO_ACCOUNT_SID`,
    `RVM_TWILIO_AUTH_TOKEN`, `RVM_TWILIO_FROM_NUMBER`.
  - **Plivo:** `RVM_PLIVO_ENABLED=true`, `RVM_PLIVO_AUTH_ID`,
    `RVM_PLIVO_AUTH_TOKEN`, `RVM_PLIVO_FROM_NUMBER`.
  - **Slybroadcast:** `RVM_SLYBROADCAST_ENABLED=true`, `RVM_SLYBROADCAST_USERNAME`,
    `RVM_SLYBROADCAST_PASSWORD`, `RVM_SLYBROADCAST_CALLER_ID`.
  After changing env vars, restart the queue worker (`supervisorctl restart
  rvm-worker:*`) so the new config is picked up.
- **Audio URL resolvable for every voicemail the tenant uses.** The v2 live
  pipeline needs a publicly fetchable HTTPS URL for the audio file. Divert
  tries, in order:
  1. `voicemail_file_name` from the legacy payload if it's already an
     `http(s)://` URL (newer tenants that pre-upload to S3).
  2. `env('RVM_LEGACY_AUDIO_BASE_URL') + filename` if a base URL is set.
  3. `voice_templete.audio_file_url` or `voice_templete.audio_url` on the
     client DB (historical schemas vary across tenants).
  If **none** of these resolve to a URL, the divert skips the row with
  reason `no_audio_for_live` and the legacy AMI path takes over for that
  row. That's safe, but it means the tenant is effectively still on legacy
  for those rows — not what you want for a cutover.
- **Callback URL publicly reachable.** Providers POST status updates back
  to `/rvm/v2/providers/{provider}/callback`. Run a quick sanity check
  from an external host:
  ```
  curl -v https://<your-domain>/rvm/v2/providers/twilio/callback
  ```
  You should get a signed-request error (401/403), **not** a timeout or
  DNS failure. A timeout means the route is behind a firewall / basic auth
  and the carrier will never be able to deliver status updates.
- **Router circuit breaker not stuck open.** If a previous test run tripped
  the breaker, it may still be open. Check Redis:
  ```
  redis-cli get rvm:breaker:{provider}:state
  ```
  `closed` or empty = ok. `open` = wait for the cooldown to elapse, or
  inspect the last failure in the log and fix the underlying issue before
  flipping live.

Once the infrastructure is confirmed, run the automated readiness audit:

```
php artisan rvm:check-live-ready --client=$CLIENT_ID
```

The command runs 9 checks (T1–T9):

| id | check                                                          |
| -- | -------------------------------------------------------------- |
| T1 | Client exists in `master.clients` and has an `api_key`         |
| T2 | Current `pipeline_mode = dry_run` (must soak dry_run first)    |
| T3 | `rvm_tenant_flags.live_provider` is set                        |
| T4 | `config/rvm.php` has `providers.{name}.enabled = true`         |
| T5 | Provider is healthy in the router (circuit breaker closed)    |
| T6 | Wallet available balance ≥ threshold (default 1000c = $10)    |
| T7 | Last-24h dry_run delivered rate ≥95% with zero `failed` rows  |
| T8 | No spike of `rvm_cdr_log` rows without a matching divert in the last 1h |
| T9 | At least one `voice_templete.audio_file_url` row exists, or `RVM_LEGACY_AUDIO_BASE_URL` is set |

Exit 0 = all green. Exit 1 = at least one red — fix before flipping. Use
`--json` for CI / dashboards:

```
php artisan rvm:check-live-ready --client=$CLIENT_ID --json
```

Raise the wallet threshold for bigger tenants:

```
# require $50 available before flipping live
php artisan rvm:check-live-ready --client=$CLIENT_ID --wallet-threshold=5000
```

**Do not proceed to section 9 until all T-checks are green.**

---

## 9. Set the live provider and daily cap

**Goal:** Pre-populate the live-mode columns on `rvm_tenant_flags` *before*
changing `pipeline_mode`. Until the flag row has both `live_provider` set
and `pipeline_mode = 'live'`, RvmDivertService will refuse to dispatch.

Pick a provider (`twilio`, `plivo`, `slybroadcast`, or for a dress rehearsal
`mock`) and a day-1 daily cap. Recommended wave-1 ramp:

| day | `live_daily_cap`       | intent                           |
| --- | ---------------------- | -------------------------------- |
| 1   | 100                    | ~1 hour of real carrier traffic  |
| 2   | 500                    | ~half-day of traffic             |
| 3   | 2000                   | ~full day                        |
| 4+  | `NULL` (uncap)         | steady state                     |

Update the flag row (no mode change yet):

```sql
UPDATE rvm_tenant_flags
   SET live_provider   = 'twilio',
       live_daily_cap  = 100,
       live_enabled_at = NOW(),
       updated_at      = NOW()
 WHERE client_id = $CLIENT_ID;
```

Re-run the readiness audit — T3, T4, T5 should all flip from red to green:

```
php artisan rvm:check-live-ready --client=$CLIENT_ID
```

If T5 (router health) still reads red, the breaker is open. Check the
last 5 minutes of app log for the provider name; you're most likely
looking at a credential typo in env vars.

---

## 10. Flip to live

**Goal:** Route diverted rows through the real carrier. This is the one
irreversible (in the sense of "unsend") step in the runbook.

Preconditions (double-check):

- [ ] Section 8 readiness audit is fully green.
- [ ] `live_provider` and `live_daily_cap` are populated (section 9).
- [ ] An operator is watching the queue worker log (`tail -f
      storage/logs/lumen.log | grep RvmDivert`) and at least one dashboard
      (app log, drop status counts, wallet balance).
- [ ] You have a copy of the dry_run rollback SQL in another terminal,
      ready to paste (see section 12).

Flip the flag:

```sql
UPDATE rvm_tenant_flags
   SET pipeline_mode = 'live', updated_at = NOW()
 WHERE client_id = $CLIENT_ID;
```

What happens on the next `SendRvmJob::handle()` for this tenant:

1. `recordShadowObservation()` still runs (cheap, logs to `rvm_shadow_log`).
2. `attemptDivert()` sees `pipeline_mode = 'live'` and calls
   `RvmDivertService::divert()`.
3. Divert loads the tenant flag, confirms `live_provider` is set, checks
   the daily cap, and resolves an audio URL for the voicemail.
4. `RvmDropService::createDrop()` is called with `providerHint =
   $flag->live_provider` and `metadata.audio_url = <resolved URL>`. The
   idempotency key is the same as dry_run: `legacy_cdr:{$CLIENT_ID}:{$cdrId}`.
5. `rvm_cdr_log` is stamped with `v2_drop_id`, `divert_mode = 'live'`, and
   `diverted_at = NOW()`. `SendRvmJob::handle()` returns early — legacy
   AMI dispatch is skipped.
6. The v2 worker picks up `ProcessRvmDropJob`, runs compliance, commits
   the wallet reservation, and dispatches through **the real carrier**.
7. The carrier POSTs a status callback → `CallbackController` updates
   `rvm_drops.status`.

---

## 11. First-hour verification

**Goal:** Catch any live-mode surprise within the blast radius of the
day-1 cap (≤100 rows).

Run these every 10 minutes for the first hour:

```sql
-- A. Live drops for this tenant today
SELECT status, COUNT(*)
FROM rvm_drops
WHERE client_id = $CLIENT_ID
  AND created_at >= CURDATE()
  AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.divert.mode')) = 'live'
GROUP BY status;

-- B. Daily cap utilization (against the value you set in §9)
SELECT COUNT(*) AS used_today
FROM rvm_drops
WHERE client_id = $CLIENT_ID
  AND created_at >= CURDATE()
  AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.divert.mode')) = 'live';

-- C. Divert skips in the last hour (should be tiny)
SELECT
  SUM(CASE WHEN diverted_at IS NULL THEN 1 ELSE 0 END) AS not_diverted,
  SUM(CASE WHEN diverted_at IS NOT NULL THEN 1 ELSE 0 END) AS diverted,
  COUNT(*) AS total
FROM rvm_cdr_log
WHERE api_token = (SELECT api_key FROM clients WHERE id = $CLIENT_ID)
  AND created_at > NOW() - INTERVAL 1 HOUR;

-- D. Wallet balance movement
SELECT balance_cents, reserved_cents FROM rvm_wallet WHERE client_id = $CLIENT_ID;
SELECT direction, SUM(amount_cents) AS total, COUNT(*) AS n
FROM rvm_wallet_ledger
WHERE client_id = $CLIENT_ID
  AND created_at > NOW() - INTERVAL 1 HOUR
GROUP BY direction;
```

Ideal state after the first hour:
- (A) status distribution is ≥95% `delivered`, 0 `failed`. A small number
  of `queued` or `sending` is normal if traffic is in-flight.
- (B) `used_today` is less than `live_daily_cap`. If it's equal, the cap
  is clipping traffic — either intentional (safe) or the cap was set too
  low; adjust in section 9 and re-run the audit.
- (C) `diverted ≈ total`. A growing `not_diverted` count means rows are
  slipping through the divert (reason will be in the app log — most
  likely `no_audio_for_live` for a template the tenant uses that didn't
  appear in T9).
- (D) wallet balance dropping smoothly; ledger showing `reserve` + `commit`
  pairs per drop. Runaway `reserve` without matching `commit` means the
  worker can't reach the provider — investigate.

Watch the app log for these error patterns:

```
tail -f storage/logs/lumen.log | grep -iE 'RvmDivert|RvmDrop|circuit.*open|rate.*limit'
```

Red flags:
- `circuit breaker open for {provider}` — T5 was wrong or the provider
  started failing mid-cutover. Roll back immediately.
- `rate limit exceeded` — you're hitting the carrier's per-second quota
  (not our daily cap). Lower worker concurrency or file a quota request.
- `no_audio_for_live` spikes — a template the tenant uses lacks an audio
  URL. Either upload audio to S3 and update `voice_templete.audio_file_url`,
  or set `RVM_LEGACY_AUDIO_BASE_URL`. Until then, the affected rows
  silently fall back to legacy AMI — not catastrophic, but not the
  intended state.

---

## 12. Live-mode rollback

**Goal:** Stop new carrier dispatches as quickly as possible if something
is wrong. Rollback is a single SQL statement and takes effect on the next
`SendRvmJob::handle()` — typically within a second.

**Preferred: fall back to dry_run.** This keeps the v2 pipeline engaged
(so you still get divert coverage, wallet accounting, drop rows) but
swaps the real carrier for MockProvider. Use this when you want to
*pause* live dispatches without losing v2 observability:

```sql
UPDATE rvm_tenant_flags
   SET pipeline_mode = 'dry_run', updated_at = NOW()
 WHERE client_id = $CLIENT_ID;
```

New rows will immediately divert to MockProvider (instant delivered).
Rows already in flight at the provider will still get their callback
processed — they show up in `rvm_drops` as `delivered` / `failed` from
the real carrier's last state.

**Escape hatch: fall all the way back to legacy.** Use this only if the
v2 pipeline itself is unhealthy (queue worker crashed, Redis down,
RvmDropService throwing). `SendRvmJob` will resume AMI dispatch on the
next row:

```sql
UPDATE rvm_tenant_flags
   SET pipeline_mode = 'legacy', updated_at = NOW()
 WHERE client_id = $CLIENT_ID;
```

Rows already dispatched through the real carrier are obviously not
recallable — but the tenant stops accumulating new live-mode exposure
from this point forward.

**To permanently revoke live capability** (so a stray flag flip can't
re-enable it), clear the live columns too:

```sql
UPDATE rvm_tenant_flags
   SET live_provider   = NULL,
       live_daily_cap  = NULL,
       live_enabled_at = NULL,
       updated_at      = NOW()
 WHERE client_id = $CLIENT_ID;
```

After this, even flipping `pipeline_mode` back to `live` will fail the
divert with `reason=live_provider_not_set` until you re-run section 9.

---

## 13. Phase 5c sign-off checklist

Before declaring wave-1 live cutover complete and moving to wave 2:

- [ ] §8 `rvm:check-live-ready` was fully green at flip time.
- [ ] §10 flip happened with an operator actively watching.
- [ ] First-hour (§11) verification showed ≥95% `delivered`, 0 `failed`,
      no circuit-breaker trips, no `no_audio_for_live` spikes.
- [ ] Daily cap ramp-up followed the schedule in §9 (or a variant
      explicitly approved by the tenant operator).
- [ ] Wallet accounting matches carrier pricing — no unexpected
      `reserve` without `commit`, no runaway refunds.
- [ ] Tenant operator confirms voicemails are landing on real phones —
      this is the one check the automation cannot do for you.
- [ ] 48h of live operation with no emergency rollback.

Once all boxes are checked, the tenant is "proven on v2 in live" and
the next wave can begin.

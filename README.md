# Pixelforge Studio

Laravel 13 + Vue 3 + Inertia v3 app for generating images through the Pixelforge API. The focus is on **exact billing**, **reliable webhook handling** and **graceful API error handling**.

## Quick start

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
# set DB_* (MySQL) and PIXELFORGE_API_KEY / PIXELFORGE_WEBHOOK_SECRET
php artisan migrate
npm run build

php artisan serve            # web
php artisan queue:work       # submits generations to Pixelforge
php artisan schedule:work    # runs generations:reconcile every minute
```

The first account registered becomes the admin. You can also run `php artisan user:admin you@example.com`.
Point the Pixelforge webhook at `https://<host>/webhooks/pixelforge`. For local development, use a tunnel such as `cloudflared tunnel --url http://localhost:8000`.

Tests: `php artisan test` (Pest, SQLite in-memory).

## Money

* **All money is stored as integer nanodollars (`BIGINT`, 1 USD = 1,000,000,000).** Pixelforge reports money to 9 decimal places, so every provider amount is exact. Floats are never used for money. Decimal strings are parsed with bcmath (`App\Support\Money`), and an amount finer than 1 nanodollar is *rejected*, never silently rounded.
* **Provider cost** = `Σ tokens × rate_nanos_per_1M / 1,000,000`. The products are summed first and divided once. With the published rates the division is exact; if a rate ever left a remainder, we round up.
* **User price** = `ceil(provider × 1.25)`, computed as `intdiv(p × 125 + 99, 100)`. We never under-charge by a rounding step.
* **Estimate** (worst case): `ceil(ceil(chars/4) × 1.15)` text tokens, done in integer math so a float result like `114.99999…` can't cause an off-by-one. Plus 765 tokens per reference image, the output-token table and the maximum reasoning tokens from `/v1/pricing`. `/v1/pricing` is cached for 5 minutes. If it can't be reached we use the last good response, then the published defaults.
* The browser estimator (`resources/js/lib/pricing.ts`) mirrors the PHP code using `BigInt`. The server **recomputes** the estimate on submit, and only the server's number is held.
* **Rates are snapshotted** on each generation when it's created, so the final charge uses the rates in force when it was submitted. If a webhook includes `rates`, those take precedence.
* **Failed generations cost $0.** The cost columns are set to 0 and the hold is released.

### Ledger and credits

`ledger_entries` is append-only. Every balance change happens through `App\Services\Ledger` as *one ledger row plus the matching balance change, in one transaction*. The invariant is `users.credit_balance_nanos == SUM(ledger_entries.amount_nanos)`; tests assert it.

Lifecycle: **hold** (−estimate) on submit → on success, **release** (+estimate) and **charge** (−actual); on failure, **release** only.
A **unique index on `(generation_id, type)`** means a generation can have at most one hold, one charge and one release. Duplicate webhooks therefore cannot double-charge, and this is enforced by the database, not only by the application code. Inserts use `INSERT IGNORE`, and the balance changes only if the row was actually written.

The submit-time balance check locks the user row (`SELECT … FOR UPDATE`), so two concurrent submits can't both pass it.

## Webhooks (`POST /webhooks/pixelforge`)

1. **Signature:** parse `Pixelforge-Signature: t=…,v1=…`, compute `HMAC-SHA256("{t}.{raw_body}")`, compare with `hash_equals`, and reject anything outside ±300 s. Any failure returns `400`. Multiple `v1` values are accepted, to allow secret rotation.
2. **Dedup:** `INSERT IGNORE` into `webhook_events` (unique `event_id`). An event that was already processed returns `204` immediately. Delivery counts are tracked.
3. **State machine** (`App\Services\GenerationStateMachine`): the single code path for webhooks, POST responses and the reconcile poller.
   * The generation row is locked with `lockForUpdate()` and found by `pixelforge_id`, **or** by `metadata.local_generation_id`. We generate our UUID *before* calling Pixelforge and send it as metadata, so a webhook that arrives before the POST response returns still matches.
   * `status_rank`: submitting 0 → queued 1 → processing 2 → succeeded/failed 3. An update applies only if it **strictly increases** the rank. A late `processing` after `succeeded` is a no-op, and a terminal state never changes to another terminal state.

## Outbound resilience (`SubmitGenerationJob`)

* Sends `Authorization: Bearer …`, `Idempotency-Key` (stable per generation, so retries are safe) and only the fields Pixelforge accepts.
* `429 rate_limit_exceeded` / `concurrency_limit_exceeded`, `503 overloaded`, other 5xx and network errors: the job is released for `max(Retry-After, 2^attempt)` seconds, capped at 120 s, with jitter. After 6 provider retries the generation is failed and the hold is refunded.
* `400 content_policy_violation`, `422 validation_failed`, `429 daily_limit_exceeded`: the generation fails immediately with a clear message and the hold is refunded.
* **Local back-pressure:** an atomic lock plus a cap of 3 generations in flight (queued or processing). Waiting for a slot doesn't use up the retry budget.
* **Missed webhooks:** `generations:reconcile` runs every minute and polls `GET /v1/generations/{id}` for anything that has been queued or processing for more than 45 s.

## UI

* **Generate** (`/dashboard`): prompt with a character count, size, quality, up to 4 reference URLs, and a live worst-case estimate with its breakdown. The balance is shown, and Generate is disabled if the balance can't cover the estimate.
* **History:** status badges, estimated vs actual cost (exact 9-decimal value on hover), failure reasons with "you were not charged", the image (with a message when the URL has expired), and token usage. The list polls every 3 s while any generation is still running.
* **Buy credits:** a fake checkout that adds $5 or $10 through a `topup` ledger entry. Each new account starts with a $5 welcome credit.
* **Admin → Financial report** (`/admin/report`): totals, daily (by **UTC completion date**, the same rule as `/v1/usage`) and per-user revenue, provider cost and profit. Also a **Reconcile** panel that fetches `/v1/usage` and diffs it against our recorded provider cost day by day.

## Assumptions to verify against the live docs

The Pixelforge host couldn't be reached from the build machine, so the parsing is deliberately tolerant. These are the points to check against the real payloads:

* Webhook body: `{id, type: "generation.<status>", data: {object: {...generation}}}`.
* Generation object: `usage.{input_text,input_image,output,reasoning}_tokens`, `image_url`, `image_url_expires_at`, `error.{code,message}`, `completed_at`, and optional `cost_usd`. If `cost_usd` is present and differs from our computed cost, the difference is logged and the reported value is stored.
* `/v1/pricing`: `rates.{key}` in USD per 1M tokens, `tokens.output[quality][size]`, `tokens.reasoning[quality].max`.
* `/v1/usage`: a list of `{date, cost_usd, generations}` under `data`, `daily` or `days`. The raw response is shown in the admin panel.
* The `$0.373875000` worked example is checked in `tests/Unit/MoneyTest.php` using a token breakdown that produces that total. Replace it with the breakdown from the docs.

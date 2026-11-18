# Pixelforge Studio: Project Summary & Manual E2E Test Guide

## 1. Project summary

A full-stack image-generation app built for the PromptEdit Senior Product Engineer take-home. Users enter a prompt, see a worst-case price **before** submitting, and are billed the **exact** provider cost plus 25% once Pixelforge finishes. Pixelforge is a simulated API that deliberately sends duplicate and out-of-order webhooks and returns rate limits, so most of the engineering is in **financial correctness** and **resilience**.

**Stack:** Laravel 13 · Vue 3 + TypeScript · Inertia v3 · Tailwind v4 · MySQL 8 · Pest · Fortify auth (Laravel Vue starter kit)

### What it does

| Area | Behaviour |
|---|---|
| **Generate** | Prompt (with character count), size (`1024x1024`, `1536x1024`, `1024x1536`), quality (`low`/`medium`/`high`), up to 4 reference image URLs. |
| **Live estimate** | Worst-case cost recalculated on every keystroke, with a token-by-token breakdown. The server recomputes it on submit; only the server's number is used. |
| **Credits** | $5.00 welcome credit. A fake "Buy credits" adds $5 or $10. Generate is disabled if the balance can't cover the estimate. |
| **History** | Status badge (submitting/retrying → queued → processing → succeeded/failed), estimated vs actual cost, image, token usage, and a friendly failure reason with "you were not charged". Refreshes every 3 s while anything is running. |
| **Admin report** | `/admin/report`: totals, daily and per-user revenue / provider cost / profit (by UTC completion day), and a **Reconcile** panel comparing our costs with Pixelforge's `GET /v1/usage`. |

### Money: how it stays exact

- **All money is integer nanodollars** in `BIGINT` columns (1 USD = 1,000,000,000). Pixelforge reports 9 decimal places, so every amount is represented exactly. Floats never touch money; decimal strings are parsed with bcmath (`app/Support/Money.php`).
- **Provider cost** = `Σ(tokens × rate_nanos_per_1M) / 1,000,000`, summed first and divided once (exact at the published rates).
- **User price** = `ceil(provider × 1.25)` = `intdiv(provider × 125 + 99, 100)`.
- **Estimate** = text tokens `ceil(ceil(chars/4) × 1.15)` (integer math) + 765 per reference image + output tokens for that quality and size + the maximum reasoning tokens for that quality.
- **Rates are saved on each generation** when it's created, so later price changes don't affect a generation already in progress.
- **Failed generations cost $0.**

### Ledger and billing lifecycle

```
submit ──► HOLD  −estimate          (balance checked with the user row locked)
             │
   ┌─────────┴──────────┐
succeeded             failed
RELEASE +estimate     RELEASE +estimate
CHARGE  −actual       (no charge)
```

- `ledger_entries` is append-only. Invariant: **`users.credit_balance_nanos == SUM(ledger_entries.amount_nanos)`**.
- A **unique index on `(generation_id, type)`** allows at most one hold, one charge and one release per generation. Duplicate webhooks therefore *cannot* double-charge, and the database enforces it, not just the application code.

### Webhook safety (`POST /webhooks/pixelforge`)

1. **HMAC check:** `Pixelforge-Signature: t=<unix>,v1=<hex>`, where the signature is `HMAC-SHA256("{t}.{raw_body}")`. It's compared with `hash_equals`, and timestamps more than 300 s off are rejected. Any failure returns **400**.
2. **Dedup:** every event is recorded by `event_id` (unique). An event already processed returns **204** immediately; repeat deliveries are counted.
3. **State machine** (`app/Services/GenerationStateMachine.php`):
   - Finds the generation by `pixelforge_id` **or** `metadata.local_generation_id`. Our UUID is created before calling Pixelforge, so a webhook that arrives before Pixelforge's reply to our submit request still matches.
   - Locks the row (`SELECT … FOR UPDATE`).
   - Each status has a rank: submitting 0 → queued 1 → processing 2 → succeeded/failed 3. An update applies only if it moves to a **strictly higher** rank, so a generation never goes backwards and never switches between succeeded and failed.

### Outbound resilience (`app/Jobs/SubmitGenerationJob.php`)

| Pixelforge response | Action |
|---|---|
| `2xx` | Store `pixelforge_id` and move to `queued` (ignored if a webhook already moved it further). |
| `429 rate_limit_exceeded` / `concurrency_limit_exceeded`, `503`, other 5xx, network error | Retry after `max(Retry-After, 2^attempt)` s (capped at 120 s). After **6** failed attempts: mark failed and refund. Retries are safe because each request sends the same `Idempotency-Key`. |
| `400 content_policy_violation`, `422 validation_failed`, `429 daily_limit_exceeded` | Fail immediately with a clear message and refund the hold. |
| Our own limit: 3 generations in flight | Wait locally without calling Pixelforge. This doesn't use up the retry budget. |

A `generations:reconcile` command runs every minute and polls `GET /v1/generations/{id}` for anything stuck in queued/processing for more than 45 s, as a safety net for missed webhooks.

### Key files

```
app/Support/Money.php                         nanodollar conversion, markup, ceil division
app/Services/Pixelforge/                      client, pricing (cached), estimator, cost calculator, signature verifier
app/Services/Ledger.php                       the only code that changes balances
app/Services/GenerationService.php            price → hold → persist → queue submission
app/Services/GenerationStateMachine.php       every status change goes through here
app/Jobs/SubmitGenerationJob.php              outbound POST + retry/back-off policy
app/Http/Controllers/Webhooks/…               webhook endpoint
app/Http/Controllers/Admin/FinancialReport…   report + /v1/usage reconciliation
app/Console/Commands/ReconcileGenerations.php missed-webhook poller (scheduled every minute)
resources/js/lib/money.ts, pricing.ts         BigInt money formatting + estimator mirror
resources/js/pages/Dashboard.vue              generate form + live history
resources/js/pages/admin/Report.vue           admin financial report
tests/Unit/MoneyTest.php, tests/Feature/Pixelforge/*   Pest suite
```

---

## 2. Setup & running locally

```bash
cd promptedit-pixelforge
composer install && npm install
cp .env.example .env && php artisan key:generate   # skip if .env already exists
```

Edit `.env`:

```dotenv
DB_CONNECTION=mysql
DB_DATABASE=promptedit_pixelforge
DB_USERNAME=...
DB_PASSWORD=...

PIXELFORGE_BASE_URL=https://promptedit.dev
PIXELFORGE_API_KEY=pf_...                 # your dev key
PIXELFORGE_WEBHOOK_SECRET=whsec_...       # use whsec_local_test for the offline simulation in §4
```

```bash
mysql -u<user> -p -e "CREATE DATABASE IF NOT EXISTS promptedit_pixelforge"
php artisan migrate
```

Start the four processes (separate terminals, or in the background as shown):

```bash
mkdir -p storage/logs/dev
nohup php artisan serve --host=127.0.0.1 --port=8000 > storage/logs/dev/server.log 2>&1 &
nohup php artisan queue:work --sleep=1 --tries=0     > storage/logs/dev/queue.log  2>&1 &
nohup php artisan schedule:work                      > storage/logs/dev/scheduler.log 2>&1 &
nohup npm run dev                                    > storage/logs/dev/vite.log   2>&1 &
```

> `--tries=0` is intentional: the job limits itself (6 provider retries, 30-minute ceiling), and its own waits for a free slot must not count as failed attempts.

Stop everything (the `[a]` stops `pkill` from matching its own shell):

```bash
pkill -f "[a]rtisan (serve|queue:work|schedule:work)"; pkill -f "[v]p dev"
```

**After changing `.env`**, restart the queue worker, because it keeps the old config in memory.

Open **http://127.0.0.1:8000** and register. **The first account registered becomes the admin.** Others can be promoted with `php artisan user:admin someone@example.com`.

### Receiving real webhooks locally

Pixelforge has to reach your machine:

```bash
cloudflared tunnel --url http://localhost:8000      # or: ngrok http 8000
```

In the Pixelforge dashboard, set the webhook URL to `https://<tunnel-host>/webhooks/pixelforge`. Without a tunnel, results still arrive through the reconcile poller, about 45–60 s late.

### Handy inspection commands

```bash
# Latest generations
php artisan tinker --execute 'App\Models\Generation::latest()->take(5)->get(["id","status","pixelforge_id","estimated_user_cost_nanos","actual_user_cost_nanos","error_code"])->each(fn($g)=>print(json_encode($g).PHP_EOL));'

# Ledger + invariant check for a user
php artisan tinker --execute '$u=App\Models\User::where("email","YOU@example.com")->first(); App\Models\LedgerEntry::where("user_id",$u->id)->get(["type","amount_nanos","description"])->each(fn($e)=>print(json_encode($e).PHP_EOL)); echo "balance={$u->credit_balance_nanos} ledger_sum=".App\Models\LedgerEntry::where("user_id",$u->id)->sum("amount_nanos").PHP_EOL;'

tail -f storage/logs/laravel.log storage/logs/dev/queue.log
```

---

## 3. Automated tests

```bash
php artisan test
```

86 tests (in-memory SQLite, faked HTTP) covering: nanodollar math and the `$0.373875000` worked example, markup rounding, estimator edge cases (multibyte prompts, float edge cases), HMAC (valid / tampered / wrong secret / expired / missing), duplicate events, out-of-order delivery, a webhook arriving before Pixelforge's reply to the submit request, 429/503 retry behaviour, permanent errors, the retry limit, the in-flight limit, the reconcile poller, the credit balance check, top-ups, the admin restriction and report reconciliation.

---

## 4. Manual end-to-end test plan

Work through these in order. Each step lists the **expected result**.

### A. Auth, balance and estimator (no Pixelforge needed)

| # | Steps | Expected |
|---|---|---|
| A1 | Register at `/register`. | Lands on **Generate**. Balance shows **$5.0000**. First account: sidebar shows **Financial report**. |
| A2 | Type `a red fox in snow`, size **Square**, quality **medium**, then open **Breakdown**. | text in **6** tok, image in **0**, output **1,056**, reasoning (max) **1,500**. Provider **$0.061020000**, with markup **$0.076275000**. Headline **$0.0763**. *(With live Pixelforge pricing the token tables may differ.)* |
| A3 | Switch quality to **high** and size to **Landscape**. | Estimate rises immediately (output 6,208, reasoning 4,000). |
| A4 | Add a reference URL. | `image in` becomes **765** tokens and the estimate rises by 765 × $10/1M × 1.25 ≈ **$0.0096**. |
| A5 | Add 4 reference URLs. | **+ Add URL** becomes disabled. |
| A6 | Enter `not-a-url` as a reference and press Generate. | Validation error: *"Each reference image must be a valid http(s) URL."* No generation is created and the balance is unchanged. |
| A7 | Type a 4,001-character prompt. | Counter turns red and **Generate** is disabled. |
| A8 | **Buy credits → + $5.00**. | Toast *"Added $5.00 in credits."* Balance **$10.0000**. The ledger gets a `topup` row. |
| A9 | Low-balance check: drain the balance through the ledger (keeps the ledger and balance in sync):<br>`php artisan tinker --execute '$u=App\Models\User::where("email","YOU@example.com")->first(); app(App\Services\Ledger::class)->topUp($u, -($u->credit_balance_nanos - 10000000), "Test: drain to \$0.01");'`<br>Reload the page. | Balance **$0.0100**. For any estimate above that: red *"Your balance doesn't cover this estimate"*, and Generate is disabled. Buy credits to continue. |
| A10 | Log in as a non-admin user and visit `/admin/report`. | **403**. |

### B. Webhook simulation without Pixelforge (works offline)

Uses a locally signed webhook to exercise signature checking, deduplication, ordering and billing. **This exact flow was run against the local app on 2026-11-18 and produced the results below.**

1. Set `PIXELFORGE_WEBHOOK_SECRET=whsec_local_test` in `.env`.
2. **Stop the queue worker** so the generation stays in `submitting` and can't be sent: `pkill -f "[a]rtisan queue:work"`.
3. In the UI, submit `a red fox in snow` / Square / medium. The card shows **submitting** and the balance drops by the $0.076275 hold.
4. Copy its ID: `php artisan tinker --execute 'echo App\Models\Generation::latest()->value("id");'`
5. Paste into a terminal:

```bash
GEN_ID=<paste-id>
SECRET=whsec_local_test
send() {
  BODY="$1"; TS=$(date +%s)
  SIG=$(printf '%s.%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.*= //')
  curl -s -o /dev/null -w "%{http_code}\n" -X POST http://127.0.0.1:8000/webhooks/pixelforge \
    -H "Content-Type: application/json" -H "Pixelforge-Signature: t=$TS,v1=$SIG" --data-raw "$BODY"
}
PROC='{"id":"evt_sim_1","type":"generation.processing","data":{"object":{"id":"gen_sim_1","status":"processing","metadata":{"local_generation_id":"'$GEN_ID'"}}}}'
SUCC='{"id":"evt_sim_2","type":"generation.succeeded","data":{"object":{"id":"gen_sim_1","status":"succeeded","metadata":{"local_generation_id":"'$GEN_ID'"},"usage":{"input_text_tokens":6,"input_image_tokens":0,"output_tokens":1056,"reasoning_tokens":900},"image_url":"https://picsum.photos/seed/fox/1024","image_url_expires_at":"2030-01-01T00:00:00Z"}}}'
LATE='{"id":"evt_sim_3","type":"generation.processing","data":{"object":{"id":"gen_sim_1","status":"processing","metadata":{"local_generation_id":"'$GEN_ID'"}}}}'
```

Send them one at a time and watch the UI after each (it refreshes every 3 s):

| # | Command | HTTP | Expected in UI / DB |
|---|---|---|---|
| B1 | `send "$PROC"` | 204 | Badge **processing**. `pixelforge_id = gen_sim_1`, matched by `metadata.local_generation_id` before any POST reply existed. |
| B2 | `send "$SUCC"` | 204 | Badge **succeeded**, image shown. **Actual $0.0669** (`0.066900000` exact on hover) vs estimated $0.0763; "Under estimate by $0.0094". Balance = start − **$0.0669**. |
| B3 | `send "$SUCC"` (exact duplicate) | 204 | **Nothing changes.** Still one `charge` row. `webhook_events.deliveries = 2`. |
| B4 | `send "$LATE"` (out of order) | 204 | Still **succeeded**: no going backwards, no re-billing. |
| B5 | Tampered signature: `curl -s -o /dev/null -w "%{http_code}\n" -X POST http://127.0.0.1:8000/webhooks/pixelforge -H "Content-Type: application/json" -H "Pixelforge-Signature: t=$(date +%s),v1=deadbeef" --data-raw "$SUCC"` | **400** | Rejected and not recorded. |
| B6 | Expired: re-sign with `TS=$(( $(date +%s) - 400 ))` instead of `date +%s`. | **400** | Rejected (outside ±300 s). |

**Verify the money by hand:**
`6×5,000 + 1,056×40,000 + 900×12,500 = 53,520,000` nanos provider cost → `× 1.25 = 66,900,000` nanos = **$0.066900000** charged.
Ledger for that generation: `hold −76,275,000`, `release +76,275,000`, `charge −66,900,000`. The ledger total still equals the balance.

**Failure path:** submit another generation (worker still stopped), set `GEN_ID` to it, then:

```bash
send '{"id":"evt_sim_4","type":"generation.failed","data":{"object":{"id":"gen_sim_2","status":"failed","metadata":{"local_generation_id":"'$GEN_ID'"},"error":{"code":"content_policy_violation","message":"Prompt blocked."}}}}'
```

Expected: badge **failed**, message *"Your prompt was blocked by the content policy…"* and *"You were not charged"*. Actual **$0.000000**, the hold is returned, and there's no `charge` row.

Restart the worker afterwards: `nohup php artisan queue:work --sleep=1 --tries=0 >> storage/logs/dev/queue.log 2>&1 &`

### C. Live Pixelforge tests (needs API key, webhook secret, tunnel)

Prerequisites: `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` set to the **real** values, the queue worker restarted, the tunnel running and registered as the webhook URL. Check the host resolves: `getent hosts promptedit.dev`.

| # | Prompt / action | Expected |
|---|---|---|
| C1 | Normal prompt, e.g. `a lighthouse at dusk`. | submitting → **queued** → **processing** → **succeeded** without reloading. Image appears. Actual ≤ estimate. Breakdown pricing source is **live** (no amber fallback warning). |
| C2 | Prompt containing **`[fail]`**. | Ends **failed** with Pixelforge's reason. **$0 charged**, hold returned, balance back to where it was. |
| C3 | Prompt containing **`[duplicate]`**. | Pixelforge sends events more than once. One **succeeded**, exactly **one** `charge` row. `webhook_events.deliveries > 1` for the repeated event IDs: `php artisan tinker --execute 'App\Models\WebhookEvent::where("deliveries",">",1)->get(["event_id","event_type","deliveries"])->each(fn($e)=>print(json_encode($e).PHP_EOL));'` |
| C4 | Prompt containing **`[slow]`**. | Stays **processing** for a while. After 45 s without updates, `scheduler.log` shows `generations:reconcile` polling it. Ends succeeded and is billed once. |
| C5 | Out-of-order delivery (Pixelforge may send `succeeded` before `processing`). | Final state stays **succeeded**. `storage/logs/laravel.log` has no errors. |
| C6 | **Missed webhook:** stop the tunnel, submit a normal prompt, wait about 60 s. | The reconcile poller (`scheduler.log`) fetches the result and the card becomes **succeeded** without any webhook. |
| C7 | **Rate / concurrency limits:** submit 6–8 generations quickly. | At most 3 are queued/processing at Pixelforge at once; the rest stay **submitting**. Any 429/503 shows as **retrying** in the UI and `Pixelforge submit deferred` (with delay) in `laravel.log`. All eventually finish, each billed once. |
| C8 | Prompt likely to break the content policy (if Pixelforge simulates one). | **failed** immediately with a friendly message and no retries. $0. |
| C9 | **Admin → Financial report → Run reconciliation.** | Per UTC day, **Pixelforge cost** equals **our provider cost**: *"✓ Every day matches to the nanodollar."* Daily and per-user profit = revenue − provider cost. Open *Raw /v1/usage response* to see exactly what Pixelforge returned. |
| C10 | Repeat C9 after a day with failures. | Failed generations add **$0** on both sides and are counted separately as "failed ($0)". |

### D. Invariant check (run after any test session)

```bash
php artisan tinker --execute '
foreach (App\Models\User::all() as $u) {
  $sum = (int) App\Models\LedgerEntry::where("user_id",$u->id)->sum("amount_nanos");
  echo str_pad($u->email,30)." balance=".$u->credit_balance_nanos." ledger=".$sum.($sum===$u->credit_balance_nanos?"  OK":"  MISMATCH").PHP_EOL;
}
echo "generations with >1 charge: ".DB::table("ledger_entries")->where("type","charge")->select("generation_id")->groupBy("generation_id")->havingRaw("count(*)>1")->get()->count().PHP_EOL;
echo "failed generations with cost: ".App\Models\Generation::where("status","failed")->where("actual_user_cost_nanos","!=",0)->count().PHP_EOL;'
```

Expected: every user **OK**, and **0** for both counts.

---

## 5. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Every generation shows **retrying**, then fails after 6 attempts with *"Could not resolve host: promptedit.dev"*. | DNS can't find Pixelforge from this machine. Check `getent hosts promptedit.dev`, your VPN or DNS settings, or the base URL. The hold is refunded, so it's safe. |
| Breakdown shows *"uses the published default rates"*. | `/v1/pricing` couldn't be reached; the estimate uses fallback rates. Fixes itself once Pixelforge is reachable (retried every 30 s). |
| Webhooks return **400**. | `PIXELFORGE_WEBHOOK_SECRET` doesn't match Pixelforge's, or the server clock is more than 300 s off. |
| Generation stuck in **submitting**. | The queue worker isn't running: `pgrep -af "[a]rtisan queue:work"`. |
| `.env` change not taking effect. | Restart the queue worker, and run `php artisan config:clear`. |
| `pkill -f "artisan …"` kills your own shell. | Use the bracket form: `pkill -f "[a]rtisan queue:work"`. |

### Known assumptions

`promptedit.dev` couldn't be reached while this was built, so the webhook, pricing and usage payload formats follow the brief and are parsed to accept a few variants (see `README.md` → *Assumptions*). The first live run (section C) is the real check; the admin page shows the raw `/v1/usage` response to make any format mismatch obvious.

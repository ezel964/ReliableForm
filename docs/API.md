# ReliableForm — API v1 & Webhooks

Developer reference for the REST API (`/v1/...`), outbound webhooks, and CSV
export. Every response and payload below was captured live against the
running stack — nothing is invented.

See also: [RUNBOOK.md](RUNBOOK.md) (operations, webhook surgery) ·
[SRE-GUIDE.md](SRE-GUIDE.md) (trace model, SLIs) · [METRICS.md](METRICS.md)
(telemetry catalog) · [TESTING.md](TESTING.md) (test suites).

## Basics

- Base URL: `http://localhost:8080` (through the load balancer). The API
  lives under `/v1/` — **not** `/api/`, which nginx routes to the status
  service.
- All requests and responses are JSON. Success envelope `{"ok":true,...}`,
  error envelope below.
- The API is **sessionless**: no cookies are read or set. CSRF does not apply.
- Every response carries `X-Trace-Id` (32 hex); error bodies repeat it as
  `error.trace_id` — quote it when filing a problem, it joins the app logs
  and spans (see [SRE-GUIDE.md](SRE-GUIDE.md)).

## Authentication

Pass your API key (40 hex chars) either way:

```
Authorization: Bearer <key>
X-Api-Key: <key>
```

- **Where to find it:** the dashboard's "Your API key" card (shown after
  login, with a copy button and a ready-made curl line).
- **Seeded demo key:** `deadbeefdeadbeefdeadbeefdeadbeefdeadbeef`
  (the `demo@reliableform.dev` account).
- **Regenerate:** the dashboard card's button (`POST /account/api-key`,
  CSRF-protected, web session only). The old key stops working
  **immediately** — the key is the lookup column. Metric:
  `web.apikey.regenerate`.
- The key authenticates its owner; all `/v1/forms/...` endpoints target the
  owner's forms only. A form that exists but belongs to someone else returns
  the same 404 as a missing one (no existence leak).

Smoke test:

```bash
curl http://localhost:8080/v1/me \
  -H "Authorization: Bearer deadbeefdeadbeefdeadbeefdeadbeefdeadbeef"
```

## Error envelope and codes

```json
{
  "ok": false,
  "error": {
    "code": "unauthorized",
    "message": "Provide a valid API key via \"Authorization: Bearer <key>\" or \"X-Api-Key: <key>\".",
    "trace_id": "1ee7f6966621c5bd7902f5eb90617395"
  }
}
```

| code | HTTP | when |
|---|---|---|
| `unauthorized` | 401 | missing/malformed key, or key unknown (`Unknown API key.`) |
| `not_found` | 404 | no such endpoint, or form missing / not yours |
| `method_not_allowed` | 405 | wrong method on a known path; `Allow:` header lists what works |
| `unsupported_media_type` | 415 | `POST .../submissions` without `Content-Type: application/json` |
| `validation_failed` | 422 | bad body or field values; adds `error.fields` (per-field messages) |
| `rate_limited` | 429 | over the per-key limit; `Retry-After: 30` header |
| `internal_error` | 500 | uncaught exception (`api.error` counter; message is sanitized outside `APP_ENV=dev`) |

422 carries a `fields` map keyed by field id (captured — required fields you
omitted are reported too):

```json
{
  "ok": false,
  "error": {
    "code": "validation_failed",
    "message": "Validation failed for one or more fields.",
    "trace_id": "9fd396ca7bce8bd455b57abc79bd5895",
    "fields": {
      "f_name01": "This field is required.",
      "f_email1": "Enter a valid email address.",
      "f_dept01": "This field is required.",
      "f_first1": "This field is required.",
      "f_rate01": "Pick a rating between 1 and 5."
    }
  }
}
```

## Rate limiting

Per key: `ratelimit:api:<key>` is INCR'd with a 60s expiry; over
`API_RATE_LIMIT_PER_MIN` (`.env`, default `120`, `0` disables) ⇒ 429:

```
HTTP/1.1 429 Too Many Requests
Retry-After: 30

{"ok":false,"error":{"code":"rate_limited","message":"API rate limit exceeded (120 requests per minute per key). Try again shortly.","trace_id":"b6c2fbd0bee162ffa92766838154803a"}}
```

Redis errors **fail open** — the limiter never takes the API down with it.
Metric: `api.ratelimited` per rejected request.

## Endpoints

All examples use the demo key and the seeded `demofeedbk` form.

```bash
KEY=deadbeefdeadbeefdeadbeefdeadbeefdeadbeef
```

### GET /v1/me

```bash
curl http://localhost:8080/v1/me -H "Authorization: Bearer $KEY"
```

```json
{
  "ok": true,
  "user": {
    "id": 1,
    "email": "demo@reliableform.dev",
    "name": "Demo Owner",
    "created_at": "2026-06-10 23:03:54"
  }
}
```

### GET /v1/forms

Your forms, newest first, with submission counts.

```bash
curl http://localhost:8080/v1/forms -H "Authorization: Bearer $KEY"
```

```json
{
  "ok": true,
  "forms": [
    {
      "id": 2,
      "public_id": "6bzos3ltar",
      "title": "Incident Postmortem Intake",
      "is_published": true,
      "submissions": 0,
      "webhook_url": null,
      "created_at": "2026-06-10 23:05:27",
      "updated_at": "2026-06-10 23:05:27"
    },
    {
      "id": 1,
      "public_id": "demofeedbk",
      "title": "Customer Feedback",
      "is_published": true,
      "submissions": 126,
      "webhook_url": null,
      "created_at": "2026-06-10 23:03:54",
      "updated_at": "2026-06-10 23:03:54"
    }
  ]
}
```

### GET /v1/forms/{public_id}

Full definition including the `fields` array (the widget schema —
see ARCHITECTURE.md "forms.fields JSON").

```bash
curl http://localhost:8080/v1/forms/demofeedbk -H "X-Api-Key: $KEY"
```

```json
{
  "ok": true,
  "form": {
    "id": 1,
    "public_id": "demofeedbk",
    "title": "Customer Feedback",
    "description": "Tell us how we did. Takes about a minute — every answer helps us improve.",
    "fields": [
      {"id": "f_name01", "type": "text", "label": "Full name", "required": true, "placeholder": "Jane Doe"},
      {"id": "f_email1", "type": "email", "label": "Email address", "required": true, "placeholder": "jane@example.com"},
      {"id": "f_dept01", "type": "select", "label": "Which team did you talk to?", "options": ["Sales", "Support", "Billing", "Other"], "required": true},
      {"id": "f_prod01", "type": "checkbox", "label": "Which products do you use?", "options": ["Forms", "PDF Reports", "Email Alerts", "API"], "required": false},
      {"id": "f_first1", "type": "radio", "label": "Was this your first contact with us?", "options": ["Yes", "No"], "required": true},
      {"id": "f_visit1", "type": "date", "label": "When did you contact us?", "required": false},
      {"id": "f_count1", "type": "number", "label": "How many times have you contacted support this year?", "required": false, "placeholder": "0"},
      {"id": "f_rate01", "max": 5, "type": "rating", "label": "How would you rate the experience?", "required": true},
      {"id": "f_notes1", "type": "textarea", "label": "Anything else we should know?", "required": false, "placeholder": "The good, the bad, the slow..."}
    ],
    "is_published": true,
    "webhook_url": null,
    "created_at": "2026-06-10 23:03:54",
    "updated_at": "2026-06-10 23:03:54"
  }
}
```

### GET /v1/forms/{public_id}/submissions

Query params: `limit` (1–100, default 20 — out-of-range values are clamped)
and `offset` (≥ 0, default 0). `total` is the unpaged count; newest first.

```bash
curl "http://localhost:8080/v1/forms/demofeedbk/submissions?limit=1" \
  -H "Authorization: Bearer $KEY"
```

```json
{
  "ok": true,
  "total": 127,
  "submissions": [
    {
      "id": 130,
      "created_at": "2026-06-11 04:10:44",
      "data": {
        "f_count1": "",
        "f_dept01": "Support",
        "f_email1": "jane@example.com",
        "f_first1": "Yes",
        "f_name01": "Jane Doe",
        "f_notes1": "Docs capture submission",
        "f_prod01": ["Forms", "API"],
        "f_rate01": "5",
        "f_visit1": ""
      }
    }
  ]
}
```

`data` is keyed by field id: string values, except checkbox (`string[]`) and
file fields (the stored relative path, or `""`).

### POST /v1/forms/{public_id}/submissions

Body `{"answers":{"<field_id>": value}}` with
`Content-Type: application/json` (else **415**). Validation is identical to
the public web form (required, email format, number, date, allowed options,
rating range); JSON numbers are accepted for number/rating fields. On success
(**201**) the full submission pipeline runs exactly as a web submit: one
transaction (submission + pending `pdf_jobs` + `emails` rows, plus a pending
`webhook_deliveries` row when the form has a webhook), then stats INCRs and
queue pushes. A Redis outage after commit still returns 201 (the submission
is stored; `api.submission.redis_degraded` counts it).

```bash
curl -X POST http://localhost:8080/v1/forms/demofeedbk/submissions \
  -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{"answers":{"f_name01":"Jane Doe","f_email1":"jane@example.com","f_dept01":"Support","f_prod01":["Forms","API"],"f_first1":"Yes","f_rate01":5,"f_notes1":"Docs capture submission"}}'
```

```json
{"ok":true,"submission":{"id":130}}
```

Wrong content type (captured):

```json
{"ok":false,"error":{"code":"unsupported_media_type","message":"Content-Type must be application/json.","trace_id":"a8323f8152d5ced48309d5baef67e658"}}
```

### File fields over the API

JSON has no multipart, so `file` widgets are excluded from API submissions:

- A **required** file field can never be satisfied ⇒ 422 with
  `"<field_id>": "File uploads are not supported via the API. Use the public form for this required field."`
- An **optional** file field is ignored; any answer you send for it is
  dropped (it never reaches the stored data).

File uploads only work on the public web form (`POST /f/{public_id}`), and
are write-only — see ARCHITECTURE.md.

## Webhooks

When a form has a webhook URL, every accepted submission (web or API) is
POSTed to it as JSON by the `worker-webhook` service.

**Enable:** form builder → "Integrations" → Webhook URL. Must match
`^https?://`, ≤ 500 chars; leave empty to disable. Saved per form
(`forms.webhook_url`, also visible in `GET /v1/forms`).

### Delivery request (captured verbatim from a live delivery)

```
POST /hook HTTP/1.1
Host: 127.0.0.1:9445
Accept: */*
Content-Type: application/json
User-Agent: ReliableForm-Webhook/1.0
X-ReliableForm-Delivery: 5
traceparent: 00-09b040bcc52d54769ff0d23fbb26987f-6243d6c5186db9f8-01
Content-Length: 295

{"event":"submission.created","form":{"public_id":"78cijpf3r4","title":"Docs webhook capture (throwaway)"},"submission":{"id":131,"created_at":"2026-06-11 04:11:45","answers":[{"id":"f_whcap1","label":"Note","type":"text","value":"hello from the docs capture"}]},"delivery":{"id":5,"attempt":0}}
```

Payload shape:

| key | meaning |
|---|---|
| `event` | always `submission.created` |
| `form.public_id`, `form.title` | the form |
| `submission.id`, `submission.created_at` | the submission row |
| `submission.answers[]` | one entry per form field, **in field order**: `{id, label, type, value}` — value is a string; checkbox values joined `", "`, rating rendered `"4 / 5"`, unanswered fields `"—"` |
| `delivery.id` | `webhook_deliveries` row id (same value as the `X-ReliableForm-Delivery` header) |
| `delivery.attempt` | 0-based attempt counter |

Headers: `Content-Type: application/json`,
`User-Agent: ReliableForm-Webhook/1.0`, `X-ReliableForm-Delivery: <id>`, and
`traceparent` — the delivery continues the submission's trace into your
receiver (one CLIENT span per attempt; see [SRE-GUIDE.md](SRE-GUIDE.md)).

### Timeouts, retries, dead-letter

- Connect timeout **2s**, total timeout **5s**, redirects **not** followed.
- Any 2xx ⇒ `delivered` (response code + duration stored on the row).
- Non-2xx or transport error ⇒ retry with backoff (sleep `min(2^attempt, 8)`s,
  heartbeat kept alive). After **3 failed attempts** the job is dead-lettered
  to `queue:dead` and the row is marked `failed` (`error` truncated to
  500 chars). Replay procedure: [RUNBOOK.md](RUNBOOK.md) → Webhook operations.
- Deliveries are **at-least-once**; a re-run of an already-`delivered`
  delivery is a no-op. Dedup on `X-ReliableForm-Delivery` if you need
  exactly-once.

Delivery states (`webhook_deliveries.status`): `pending` → `delivering` →
`delivered` | `failed`. The row also records `attempts`, `response_code`,
`duration_ms`, `error`.

Metrics: `worker.webhook.delivered` / `worker.webhook.failed` (per failed
attempt) / `worker.webhook.job_ms`, plus `worker.webhook.start`.

**Self-loop warning:** pointing a webhook at the stack's own LB/web ports
(localhost 8080/9001/9002) logs a `self-loop drill hazard` warning in
`storage/logs/worker-webhook.log` — it still delivers, but every delivered
submission would hit your own app.

### Local test sink

```bash
php -S 127.0.0.1:9444 tools/webhook-sink.php
```

| request | behavior |
|---|---|
| `POST /hook` | 200 `{"ok":true}` |
| `POST /hook?fail=1` | always 500 — exercises retries → dead-letter |
| `POST /hook?fail_first=N` | 500 for the first N attempts of each delivery id (state in `/tmp/rf-sink-<id>.count`), then 200 — deterministic "retry then deliver" |
| `GET /` | usage page |

One stdout line per delivery (captured — note the same trace across the
failed attempt and its retry):

```
[2026-06-11T01:12:54Z] delivery=7 submission=133 attempt=0 served=500 traceparent=00-b13e71c236e21f6a31b473259882deee-5620f5b7b946fe08-01
[2026-06-11T01:12:56Z] delivery=7 submission=133 attempt=1 served=200 traceparent=00-b13e71c236e21f6a31b473259882deee-a5f4a7dfcda049b8-01
```

## CSV export

`GET /forms/{id}/export.csv` — web session auth + ownership (numeric form id,
not the public id; linked from the form's submissions page). Streams:

```
Content-Type: text/csv; charset=utf-8
Content-Disposition: attachment; filename="<public_id>-submissions.csv"
```

- Columns: `submission_id`, `submitted_at_utc`, then one column per field
  (label as header, in field order).
- Checkbox arrays joined `"; "`; file answers are the stored path; missing
  answers are empty.
- RFC-4180 quoting (cells containing `"`, comma, CR or LF are wrapped in
  quotes, inner quotes doubled). Cells starting with `=` `+` `-` `@` get a
  single-quote prefix — the spreadsheet formula-injection guard:

```csv
submission_id,submitted_at_utc,Full name,Email address,...
129,2026-06-11 01:22:01,'=SUM(A1:A9),csv2@example.com,...
```

Metric: `web.export` per export.

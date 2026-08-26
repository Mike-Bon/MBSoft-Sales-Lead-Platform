# Communications & Messaging (Phase 6)

This document is the authoritative reference for the communication
architecture, provider integrations, security model, and known
limitations introduced in Phase 6. If this document and the code ever
disagree, the code is a bug against this document, not the other way
around.

**The LLM is never involved in sending, receiving, or deciding to send
any communication, now or in any future phase.** Phase 6 exists as the
secure, auditable, manually-triggered action layer a future agent would
eventually be restricted to using — see "Core principle" at the bottom.

## Activity vs. Communication

Two related but distinct concepts:

- **Activity** (Phase 3) — a lightweight, immutable fact on the CRM
  timeline ("a call happened", "a note was logged"). Never mutates after
  creation.
- **Communication** (Phase 6) — the actual external message: full
  provider lifecycle, provider identifiers, encrypted-account linkage,
  failure tracking. Its core content (channel, direction, recipient,
  body) never changes after creation, but `status`/`provider_message_id`/
  `delivered_at`/`read_at`/`failed_at` mutate as the send job and inbound
  webhooks update it — so, unlike Activity, it is not treated as fully
  immutable.

Every Communication that is sent or received also writes exactly one
Activity row (via `ActivityLogger`, given a `communication_id`), so the
existing CRM timeline UI keeps working unchanged, and a communication
event is visually distinguishable there (a coloured status badge linking
to the full Communication).

## Provider architecture (STEP 2)

```
Controller → CommunicationService → CommunicationAuthorizer (auth)
                                   → TemplateRenderer (if a template was selected)
                                   → Communication row (status: Queued)
                                   → SendCommunicationJob (queued)
                                          → EmailProvider | WhatsAppProvider (interface)
                                                → GmailEmailProvider | WhatsAppCloudApiProvider
```

Nothing outside `app/Services/Communication/Providers/` calls the Gmail
API or WhatsApp Cloud API directly. `CommunicationService` and
`SendCommunicationJob` depend only on the `EmailProvider`/
`WhatsAppProvider` interfaces (`app/Contracts/Communication/`), bound to
their concrete implementations in `AppServiceProvider`. This is also
what makes every provider call fully mockable in tests (STEP 28) without
touching the calling code.

## Gmail (Email) integration

- **Library**: `google/apiclient` (official Google-maintained PHP SDK).
  No raw curl against Google endpoints.
- **Auth**: OAuth2, per-user (`EmailAccount`, one per `user_id`). A
  user's Gmail password is never seen or stored — only Google's own
  hosted consent screen collects it. `access_token`/`refresh_token` are
  stored using Laravel's `encrypted` Eloquent cast (transparently
  encrypted with `APP_KEY` before every write, decrypted on read),
  `$hidden` on the model so they can never leak through JSON
  serialization, and never written to any log line
  (`GmailEmailProvider` logs only the account id/email address on
  error).
- **Sending**: `users.messages.send`, a base64url-encoded RFC 2822
  message. Fully supported and implemented.
- **Threading**: outgoing messages pass Gmail's own `threadId` through
  when replying within an existing conversation
  (`communications.provider_thread_id`).
- **Inbound email is NOT implemented in this phase.** This is a
  deliberate, honestly-documented scope boundary, not an oversight:
  genuine push-notification inbound requires Google Cloud Pub/Sub
  (a second GCP service, a subscription, and a public endpoint to
  receive notifications), which was judged out of proportion for this
  phase. A pull-based "sync replies" poll was considered as a lighter
  alternative but was also not built, to keep this phase's scope
  honest and bounded rather than half-implementing two approaches.
  Every Communication row for email is therefore currently `Outbound`
  only. Building genuine inbound email is a good candidate for a
  focused follow-up phase.
- **Status capabilities**: Queued → Sending → Sent → Failed. Gmail's
  send API confirms acceptance (`Sent`) but provides no officially
  supported delivered/read receipt mechanism, so `Delivered`/`Read` are
  never set for email communications.

## WhatsApp Business Platform (Cloud API) integration

- **Official API only.** `POST /{phone-number-id}/messages` against
  `graph.facebook.com`, via Laravel's `Http` facade (Meta publishes no
  official PHP SDK; their own docs use plain HTTPS, so this is the
  correct, documented approach). Explicitly and permanently excluded:
  WhatsApp Web automation, browser automation, unofficial libraries,
  scraping, personal-account automation.
- **Credentials**: app-wide (WABA-level) System User access token and
  App Secret, from `config/services.php` / `.env`
  (`WHATSAPP_API_TOKEN`, `WHATSAPP_APP_SECRET`) — not per-number,
  because Meta issues these per-app, not per-phone-number.
  `WhatsAppBusinessNumber` rows deliberately hold no credentials of
  their own, only `phone_number_id`/`waba_id` identifiers.
- **Numbers**: business-owned, registered by a Manager
  (`WhatsAppBusinessNumberPolicy`), optionally scoped to one team or
  left organisation-wide.
- **Sending**: free-form text messages. Meta only accepts free-form text
  to a recipient inside an open 24-hour customer-service window (i.e.
  after that recipient has messaged the business); outside that window
  only a pre-approved message *template* (Meta's own template-approval
  concept, distinct from this app's `MessageTemplate`) is deliverable.
  This application does not implement Meta's template-catalog/
  approval-status API — respecting the 24-hour window is currently the
  sender's own responsibility, surfaced as an on-screen reminder in the
  composer, not enforced server-side. This is a known limitation.
- **Inbound**: fully implemented via webhook (see below) — genuinely
  supported by the Cloud API, unlike Gmail inbound.
- **Status capabilities**: Queued → Sending → Sent → Delivered → Read
  (or → Failed at any point), driven by Meta's own webhook status
  callbacks (`entry[].changes[].value.statuses[]`). Out-of-order
  delivery is guarded against: a late "sent" callback never downgrades
  an already Delivered/Read communication.

### Webhook security (STEP 14)

`GET /webhooks/whatsapp` — one-time verification handshake
(`hub_mode`/`hub_verify_token`/`hub_challenge`), checked against
`WHATSAPP_WEBHOOK_VERIFY_TOKEN` with `hash_equals()`.

`POST /webhooks/whatsapp` — every request must carry a valid
`X-Hub-Signature-256` header: `sha256=` + HMAC-SHA256 of the *raw*
request body, keyed with `WHATSAPP_APP_SECRET`, compared with
`hash_equals()`. A missing or invalid signature is rejected with 403
before any payload parsing happens. This route is exempted from
Laravel's CSRF middleware (`bootstrap/app.php`) since Meta's servers
never carry this application's session — the signature check is the
route's actual authentication.

**Idempotency**: inbound messages are keyed by `(provider,
provider_message_id)`. A duplicate delivery of the same event (Meta may
resend if our 200 response was delayed or dropped) is detected and
silently ignored — never recorded twice, never re-processed. Enforced
at two layers: an application-level existence check before insert, and
(on Postgres) a partial unique index
(`communications_unique_provider_message_id`) as a database-level
backstop.

**Unmatched senders**: an inbound message whose sender phone number
doesn't match any `Contact` on file is still recorded (never silently
discarded) — with `contact_id`/`user_id` left null. Matching is done in
PHP (normalizing both numbers to digits-only and comparing suffixes with
a minimum-length guard), not a database-specific SQL function, since it
must work identically against Postgres (production) and SQLite (the
automated test suite). This is a known scale limitation: it compares
against every contact with a phone number on file rather than using an
indexed lookup, worth revisiting if the contact list grows very large.

## Templates (STEP 17)

`{{variable}}` placeholders only, resolved by
`App\Support\Communication\TemplateRenderer` via plain string
substitution (`preg_replace_callback`) — never Blade compilation, never
`eval()`, never a resolved callable. A template body can never execute
code, regardless of its content. Supported placeholders: `first_name`
(from the linked Contact, resolved via Contact → Lead → Opportunity in
that order), `company_name` (from the linked Organization, resolved the
same way), `salesperson_name` (the sending user's own name). An
unresolved placeholder (no matching CRM record attached) is left as
literal `{{name}}` text rather than silently blanked — a visible data
gap is safer than a silent one.

## Manual send only (STEP 18 — critical security rule)

Every send in this codebase originates from a human clicking "Send" on
`CommunicationController::sendEmail()`/`sendWhatsApp()`, behind a
composer screen that shows the recipient, channel, rendered message, and
sending account, and requires an explicit `confirm` checkbox
(server-side validated, not just a frontend affordance). Nothing in this
codebase calls `CommunicationService` automatically, on a schedule, from
a queue triggered by anything other than that same human action, or from
AI-generated content. There is no LLM integration anywhere in this
phase.

## Authorization (STEP 19/20)

`CommunicationAuthorizer` re-derives every authorization decision
server-side — it never trusts a frontend-supplied account id beyond
"which one was requested":

- **Email**: the sender must own the connected `EmailAccount` used —
  true for every role, no exception. A Gmail connection is a real
  personal OAuth identity; nobody, not even a Manager, sends "as"
  someone else's Gmail account.
- **WhatsApp**: a Manager may use any business number; a Team Head/
  Member may use an organisation-wide number (`team_id` null) or one
  scoped to their own team.
- **CRM attachment**: every non-null `organization_id`/`contact_id`/
  `lead_id`/`opportunity_id` the communication is attached to must
  independently pass that record's own `view` policy for the acting
  user — mirrors `ActivityService`'s equivalent check exactly.

`MessageTemplatePolicy`/`WhatsAppBusinessNumberPolicy` govern who may
create/edit templates and register/remove business numbers,
respectively — WhatsApp number management is Manager-only.

## Queues, retry, and idempotency (STEP 21/22)

Sends are always queued (`SendCommunicationJob`), never called inline
from a web request. `$tries = 3` with a growing backoff (`[10, 30, 90]`
seconds) — controlled, not indefinite. Only failures the provider marked
retryable (`CommunicationFailureCode::isRetryable()` —
`RateLimited`/`TemporaryNetworkError`) are retried; anything else
(authentication error, invalid recipient, a hard provider error) is
recorded as a permanent `Failed` on the first attempt.

**Idempotency**: before calling a provider, the job checks whether the
Communication has already reached a terminal state or already has a
`provider_message_id` — if so, it returns without sending again. This
guards against the classic "worker crashed after the provider accepted
the message but before our own status update landed" scenario. True
provider-level idempotency (an idempotency key Gmail/WhatsApp would
themselves deduplicate on) is not available from either API, so this
application-level check plus the terminal-state guard is the safest
practical mitigation, not a mathematical guarantee against every
conceivable double-send.

## Failure handling (STEP 23)

A closed set of failure codes (`CommunicationFailureCode`):
`EMAIL_FAILED`, `WHATSAPP_FAILED`, `AUTHENTICATION_ERROR`,
`INVALID_RECIPIENT`, `PROVIDER_ERROR`, `RATE_LIMITED`,
`TEMPORARY_NETWORK_ERROR`. Each has a safe, user-facing label. Raw
provider exceptions/stack traces are logged server-side only
(`Log::warning`/`Log::error` in the providers and
`SendCommunicationJob::failed()`) and are never shown to the end user or
stored in the `failure_reason` column.

## Credential security (STEP 24)

- Gmail tokens: Eloquent `encrypted` cast (AES-256-CBC via `APP_KEY`,
  Laravel's standard encryption), `$hidden` on `EmailAccount`.
- WhatsApp access token / App Secret: `.env` → `config/services.php`
  only, never a database row.
- No secret is ever logged. No secret is ever returned to the browser —
  `EmailAccount::$hidden` and the fact that no controller ever returns
  the model as raw JSON both enforce this.
- `.env.example` carries only empty placeholders (`GOOGLE_CLIENT_ID`,
  `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`,
  `WHATSAPP_BUSINESS_ACCOUNT_ID`, `WHATSAPP_API_TOKEN`,
  `WHATSAPP_APP_SECRET`, `WHATSAPP_API_VERSION`,
  `WHATSAPP_WEBHOOK_VERIFY_TOKEN`) — no real values were ever committed.

## Dashboard metrics (STEP 26)

`App\Services\Dashboard\CommunicationMetricsService::summary()` adds
four simple counts (emails sent, WhatsApp sent, total communications,
failed) to the existing Manager/Team Head/Team Member dashboards, scoped
identically to that dashboard's existing organisation/team/individual
scope and to the same selected period. No new charts, no redesign — the
existing `<x-performance.kpi>` tile component is reused as-is.

## Known limitations (honest, not exhaustive)

1. **No inbound email.** See "Gmail integration" above.
2. **No WhatsApp 24-hour-window / template-approval enforcement.** The
   composer reminds the sender; nothing server-side currently blocks a
   free-text send outside that window (Meta itself will reject it if
   so — the failure would surface as a normal `Failed` communication
   with a provider-reported reason).
3. **Contact phone matching scans every contact with a phone number**,
   not an indexed lookup — fine at this application's expected scale,
   worth revisiting if it grows very large.
4. **A Manager's own sent communications are always `team_id = null`**
   (a Manager has no team), even when attached to a specific team's
   CRM record — so a team's dashboard communication count reflects
   messages *sent by* that team's members, not every message *about*
   that team's records. This mirrors how Activity's own `team_id`
   already behaves; it is not a new inconsistency introduced by this
   phase.
5. **No real Google/Meta credentials were available while building this
   phase.** See the separate Manual Provider Verification checklist in
   the Phase 6 final report — live OAuth consent, live sending, live
   webhook registration, and live delivery have NOT been performed and
   must not be assumed to work until someone with real credentials
   completes that checklist.

## Core principle (binding on all future phases)

The LLM must never directly call the Gmail or WhatsApp APIs. The LLM
must never receive raw credentials. The LLM must never bypass
`CommunicationAuthorizer`. The LLM must never send an external message
without passing through `CommunicationService`. A future agent phase may
eventually *prepare* a draft or recommendation, but the actual send must
always remain a human clicking "Send" through this same controlled
layer, under this same authorization model.

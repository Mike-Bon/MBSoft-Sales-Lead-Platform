# V2 — Market Intelligence & Prospect Discovery — Release Readiness

Authoritative release-readiness record for the V2 track (V2.1–V2.6). If
this document and the code disagree, the code is a bug against this
document. Companion to `docs/MARKET_INTELLIGENCE.md` (the detailed
architecture reference).

**Status: GO for V2 feature freeze.** See [§14](#14-freeze-declaration).

---

## 1. Final V2 architecture

The complete, frozen workflow:

```
Internet (public web only)
  → V2.1  discover_prospects        deterministic search + bounded page fetch (OutboundUrlGuard)
  → V2.2  qualify_prospects         hard/supporting criteria → deterministic non-numeric outcome
  → V2.3  score_prospects           deterministic config-backed 100-point prioritisation + ranking
  → V2.4  check_prospect_duplicates ONE narrow scopeToUser-scoped read of `organizations`
  → V2.5  prepare_prospect_for_crm  PROPOSAL row only — no CRM write
        → GET  /market-intelligence/prospect-proposals/{id}      human review (editable CRM fields)
        → POST …/confirm   (throttle:12,1)                       explicit "Create Lead" click
             → ConfirmProspectLeadRequest (server-side validation + V1 Lead create policy)
             → ProspectLeadCreationService::confirmAndCreate()  (locked txn):
                  idempotency → actionability → fingerprint → eligibility → acknowledgement
                  → FRESH authorised CRM duplicate re-check (V2.4 matcher, no web)
                  → OrganizationService::create + LeadService::create  (existing V1 services)
                  → proposal = confirmed → audit market_intelligence.crm_lead_created (HUMAN actor)
        → redirect to the new CRM lead
```

- **One agent** — `AgentIdentifier::MarketIntelligence`, the same single
  `App\Services\Ai\Agent` engine as every other agent. No orchestrator,
  no swarm, no agent-to-agent calls, no second AI engine.
- **Six tools, frozen**: `discover_prospects`, `qualify_prospects`,
  `score_prospects`, `check_prospect_duplicates`, `prepare_prospect_for_crm`,
  scoped `search_knowledge` (SalesPlaybook + ProductGuide only).
- **One new table** — `prospect_lead_proposals` (V2.5). No other schema
  change anywhere in V2.
- **No new dependency** — every V2 line is Laravel built-ins + PHP core
  (`hash`, `filter_var`, `parse_url`). No composer package added.

## 2. Role authorization matrix

| Capability | Manager | Team Head | Team Member | Unauthenticated |
|---|---|---|---|---|
| See Market Intelligence in the assistant | ✅ | ✅ | ❌ (not offered) | ❌ |
| Auto-route to MI / select it explicitly | ✅ | ✅ | ❌ (rejected → Sales fallback) | ❌ |
| `discover` / `qualify` / `score` tools | ✅ | ✅ | ❌ `AuthorizationException` | ❌ |
| `check_prospect_duplicates` | ✅ **org-wide** | ✅ **own team scope only** | ❌ | ❌ |
| `prepare_prospect_for_crm` | ✅ | ✅ | ❌ | ❌ |
| View a prospect proposal (`GET …/{id}`) | ✅ own only | ✅ own only | ❌ 403 | ❌ → login |
| Confirm / cancel a proposal (`POST`) | ✅ own only | ✅ own only | ❌ 403 | ❌ → login |
| `ProspectLeadCreationService::confirmAndCreate()` directly | ✅ own proposal | ✅ own proposal | ❌ `forbidden` (defense-in-depth) | n/a |
| Resulting Organization + Lead assignment | V1 Manager rules (`CrmAssignmentService`) | **forced to the head's own team** | — | — |

Enforced at four layers: `AgentIdentifier::isAvailableTo()` +
`SendAssistantMessageRequest` (agent selection), each tool's own
`isManager() || isTeamHead()` re-check, `ProspectLeadProposalPolicy`
(in `ConfirmProspectLeadRequest::authorize()` **and** the controller),
and `ProspectLeadCreationService`'s own actor + MI-eligibility guard.

### `LeadPolicy::create()` review

`LeadPolicy::create()` returns `true` for every authenticated user — this
is **intentional, pre-existing V1 behaviour** (a Team Member can create
their own CRM leads through the normal CRM UI). It is **not** a V2.5
hole: the V2.5 confirm path additionally requires
`ProspectLeadProposalPolicy::confirm` (Manager/Team-Head **and** proposal
owner), so a Team Member cannot reach the V2.5 write path even though
`can('create', Lead::class)` is true for them. `V2WorkflowUatTest` and
`V2ConfirmationSecurityTest` prove the containment end-to-end.
**No change made to `LeadPolicy::create()`.**

## 3. Security invariants (freeze conditions)

Each is pinned by an automated test; if any fails, the freeze is broken.

| # | Invariant | Test(s) |
|---|---|---|
| I1 | The MI agent has **exactly** the six frozen tools and **no** create/update/delete/assign/confirm/send/draft/SQL/CtS/performance tool. | `V2FreezeInvariantsTest`, `AgentRegistryTest` |
| I2 | The AI can never create or confirm a CRM record. `prepare_prospect_for_crm` has no `confirm`/`owner_id`/`team_id`/`create` parameter and no code path to `LeadService`. | `PrepareProspectForCrmToolTest`, `MarketIntelligenceLeadCreationInjectionTest` |
| I3 | Every URL fetched — and every redirect hop — passes `OutboundUrlGuard::assertSafe()`. Loopback / RFC1918 / CGNAT / link-local / ULA IPv6 / metadata IPs / reserved hostnames / the app host / the configured DB host / non-80/443 ports / non-http schemes are rejected. | `OutboundUrlGuardTest` (35 cases), `V2SsrfAdversarialTest` (redirect-to-private through the fetcher) |
| I4 | External web / model / CRM text is always DATA. Injection cannot change an outcome, score, eligibility, duplicate status, weights, priority, or trigger a write. | `MarketIntelligence*InjectionTest` (×4), `V2HostileContentMatrixTest` |
| I5 | Scoring is deterministic; same `QualifiedProspect` + `ScoringModel` → identical `ScoredProspect`. Invalid config → frozen defaults. Weights total 100. | `ProspectScoringTest`, `ProspectScoringModelTest` |
| I6 | Duplicate matching is deterministic; no LLM, no embeddings. Exact domain outweighs fuzzy name; generic names need domain corroboration. | `ProspectDuplicateMatcherTest`, `IdentityNormalizerTest` |
| I7 | A Team Head can never see, count, or be told about a CRM record outside their scope — `scopeToUser` runs before the query executes. | `ProspectDuplicateCheckServiceTest`, `V2CrossTeamPrivacyAttackTest` |
| I8 | A confirmation is bound to one specific proposal (id + content + actor + duplicate state + policy version, sha256). Proposal A's fingerprint cannot confirm Proposal B. Stale / superseded / cancelled / expired proposals fail. A confirmed proposal is idempotent (one lead). | `ProspectLeadCreationServiceTest`, `V2ConfirmationSecurityTest`, `ProspectLeadProposalControllerTest` |
| I9 | A CRM duplicate that appears between review and confirm aborts the write (TOCTOU). A duplicate re-check that cannot complete aborts the write and is **never** treated as `no_match`. | `ProspectLeadCreationServiceTest` |
| I10 | Organization + Lead creation is atomic — no orphan Organization on any failure, including a `organizations.name` unique-index race. | `ProspectLeadCreationServiceTest` |
| I11 | No Cost-to-Serve reach; no outreach (email/WhatsApp/draft/follow-up) on creation. | `MarketIntelligence*InjectionTest`, `V2WorkflowUatTest` |
| I12 | Every audit event logs counts / statuses / versions only — no webpage body, no HTML, no API key, no CRM record name/note, no restricted record, no PII, no system prompt. The `crm_lead_created` audit names the **human** actor. | `*ServiceTest` audit assertions, `V2CrossTeamPrivacyAttackTest` |

## 4. Threat model summary

| Class | Vectors considered | Mitigation | Residual |
|---|---|---|---|
| **External web** | prompt injection, hostile HTML, malicious URLs, SSRF, redirects, oversized bodies, non-text content, metadata/private IPs, app/DB host, userinfo/encoded tricks, DNS rebinding | `OutboundUrlGuard` (per-hop, resolves every A/AAAA record, blocks reserved ranges + infra hosts); `WebEvidenceFetcher` bounds (text/* only, 2 MB, 40k chars kept, no retry, ≤2 redirects); provider isolation | none material |
| **Model / tool abuse** | model tries `create_lead` / `confirm` / `send` / SQL / CtS / raw query; model passes `role` / `team_id` / `owner_id`; model fabricates score/status/match | 6-tool frozen registry (structural); every tool re-derives authz from the actor; scoring/qualification/duplicate outcomes computed by the app; no model-supplied identifiers trusted | none material |
| **CRM privacy** | Team Head cross-team discovery, crafted identifiers, count/timing/audit leaks, direct route access, proposal-ownership manipulation | `scopeToUser` before query execution; audit counts scoped rows only; bounded scan; policy on every route/action; proposal `user_id` bound in fingerprint + checked in service | Team Head `no_match` cannot assert org-wide absence — **documented**, surfaced in `next_action` |
| **Human-confirmation abuse** | forged/replayed/stale fingerprint, changed proposal, cross-proposal token, acknowledgement bypass, exact/likely override, Team Member POST, direct service call | sha256 fingerprint bound to proposal id; `lockForUpdate` idempotency; eligibility state machine (blocked states have no code path); `accepted` rule + re-check on the acknowledgement; policy + service guard | none material |
| **Concurrency / races** | duplicate created after check, two confirms of one prospect, org-name uniqueness race, row-lock behaviour | fresh re-check inside the locked txn; `lockForUpdate` + `confirmed → already_created`; unique-index `QueryException` → `duplicate_appeared` with full rollback | true simultaneous DB-level races rely on the row lock — proven at service level, not with wall-clock threads |
| **Data integrity** | missing/malformed identity, bad config, provider failure, DB failure, partial write, migration order | `check_status: skipped/unavailable` (never `no_match`); `ScoringModel`/`DuplicateMatchPolicy` validate + fall back; every provider failure → safe status; outer transaction; migration ordered after V1 | invalid `proposal_ttl_hours` fails safe (proposal expires early) — **documented** |

## 5. Adversarial test results

| Area | Result |
|---|---|
| SSRF (guard in isolation + through the fetcher, incl. redirect-to-private) | **PASS** — every canonical dangerous target rejected; redirects re-validated per hop; no fetch after a block |
| Prompt injection (page title/body, business name, evidence summary, source snippet, prospect identity, qualification text, proposed lead notes, human-editable fields, confirm-payload extra keys) | **PASS** — all inert; no outcome/score/eligibility/status change; no write |
| Agent tool-registry freeze | **PASS** — exactly 6 tools; no dangerous tool by name or capability |
| Cross-team non-disclosure (exact-domain, exact-name, fuzzy, null-team, secret-note records under Team B) | **PASS** — none surfaced/counted/named; crafted `team_id`/`owner_id`/`crm_record_id` inert; audit clean |
| Scoring adversarial | **PASS** — deterministic, config-fallback, caps applied, weak/insufficient never HIGH, no self-scoring, no double-count, website ≠ online selling |
| Qualification adversarial | **PASS** — unknown stays unknown, absence ≠ false, failed hard not rescued by supporting, no numeric score, bounded research |
| Duplicate-matching adversarial | **PASS** — legal suffixes/punctuation/whitespace, generic-name protection, `"ABC Trading"` ≠ `"ABC Trading Solutions"`, domain outweighs fuzzy name, subdomains distinct |
| Proposal / fingerprint security | **PASS** — cross-proposal, cross-user, stale, changed-state, forged-acknowledgement all rejected; direct non-MI actor refused |
| TOCTOU / concurrency | **PASS** — new duplicate aborts; re-check failure aborts (never `no_match`); double-submit → one lead; unique conflict → no orphan |
| Idempotency | **PASS** — `confirmed → already_created`, no second write |
| Transaction / atomicity | **PASS** — no orphan Organization; proposal only `confirmed` when the write succeeds; V1 activity present |
| Rate limits | **PASS** — every limit actor-scoped (`market-intel:{op}:{id}` + per-user `throttle` on confirm); `recheckForCreation` consumes no search/duplicate-check budget; one user cannot exhaust another's |
| Audit security | **PASS** — no bodies/HTML/keys/notes/PII/prompt; human actor on `crm_lead_created`; Team Head counts scoped |

## 6. UAT results (deterministic, fake provider — no live Brave/web)

### Manager end-to-end (`V2WorkflowUatTest::test_manager_end_to_end_…`)
Discover 3 candidates → qualify → score (ranked, highest first) →
duplicate-check org-wide → **Glow Beauty = exact_duplicate → BLOCKED**;
**Acme = no_match → eligible** → prepare (0 CRM writes) → open review page
→ edit fields → **Create Lead** → fresh re-check passes → **exactly one
Organization + one Lead**, `source = "Market Intelligence"`, V1
"Lead created" activity present, owner = the Manager, **0 Communications**,
proposal → `confirmed`. ✅

### Team Head end-to-end (`…test_team_head_end_to_end_…`)
Team B holds an exact duplicate of the prospect. Head A's duplicate check
→ `no_match`, `candidates_examined = 0` (restricted record invisible) →
prepare → confirm → Lead + Organization **forced to Head A's team**;
Team B's record untouched. ✅

### Team Member negative (`…test_team_member_…`)
`AgentIdentifier::MarketIntelligence->isAvailableTo()` = false; assistant
page does not show "Market Intelligence"; every MI tool → 
`AuthorizationException`; `GET`/`POST`/cancel on the proposal routes →
403; no CRM mutation; the Manager's proposal stays `pending`. ✅

### Unauthenticated
`GET`/`POST` on the proposal routes → redirect to `login`. ✅

### UI UAT (`ProspectLeadProposalControllerTest` + manual view review)
Review page shows business name, website, editable industry/city/country,
score & priority **as advisory** (not a control), the duplicate status,
an obvious possible-duplicate warning with **unchecked** acknowledgement,
no "Create Lead" button on a blocked proposal, explicit "Create Lead" /
"Cancel — do not create" wording, `onsubmit` confirm dialog, and a
"Review existing record" link that points only to an authorised
organisation id. No usability/safety defect found; **no UI redesign**.

### Industry-field UAT (spec §32)
The proposed `industry` is derived from the user's own search category
(e.g. "cosmetics"), is a plainly labelled **editable** field
("Industry / category"), is not asserted anywhere as a verified fact, and
the lead `description` records it as part of the research provenance, not
as evidence. **No misleading wording found; no taxonomy built.**

## 7. Defects found & fixed in V2.6

| # | Severity | Finding | Root cause | Fix | Tests |
|---|---|---|---|---|---|
| D1 | Low (hardening) | The proposal content fingerprint bound content + actor + duplicate state, **not the proposal id** — so two proposals by one user with byte-identical proposed content shared a fingerprint, and Proposal A's fingerprint could satisfy the check on Proposal B's confirm route (both owned by the same user; no privilege escalation, but the wrong proposal row could be consumed). | `ProspectLeadProposal::fingerprintFor()` omitted the id (which does not exist until insert). | Added `?int $proposalId` to `fingerprintFor()`, threaded `$this->id` through `currentFingerprint()`; `ProspectLeadProposalService::prepare()` now inserts, then sets the fingerprint. Backward-compatible (the factory recomputes after create). | `V2ConfirmationSecurityTest::test_proposal_a_fingerprint_cannot_confirm_proposal_b` |
| D2 | Low (defense-in-depth) | `ProspectLeadCreationService::confirmAndCreate()` did not itself check the actor may use Market Intelligence — it relied entirely on the HTTP layer's policy checks. No reachable exploit (a Team Member can never own a proposal, and the controller + Form Request both check the policy), but the write service's own guarantee was incomplete. | The service only checked `user_id === actor->id`. | Added `if (! $actor->isManager() && ! $actor->isTeamHead()) return failure('forbidden', …)` at the top of `confirmAndCreate()`. | `V2ConfirmationSecurityTest::test_the_creation_service_itself_refuses_a_non_market_intelligence_actor` |

**No critical or high-severity defect found.**

## 8. Migration review

| Migration | Order | Notes |
|---|---|---|
| V1 CRM (`2026_08_26_14xx`), RLS (`2026_08_26_130200`, `…140500`) | batches 1–2 | unchanged |
| `2026_08_31_080000_create_settings_table` (V1 recovery / Phase 12A) | batch 8, **Ran** | prerequisite for nothing in V2, independent |
| **`2026_08_31_090000_create_prospect_lead_proposals_table`** (V2.5) | **Pending** — last migration | FKs → `users`, `organizations`, `leads` (all earlier); `json` columns; `varchar(64)` fingerprint; three indexes (`user_id`, `status`, `expires_at`); `cascadeOnDelete` on `user_id`, `nullOnDelete` on `organization_id`/`lead_id`/`decided_by`. **No soft-deletes.** |

- The full test suite runs **every** migration on a fresh SQLite database
  via `RefreshDatabase` (1001 tests green) — ordering + syntax proven.
- `php artisan migrate:status` against the configured DB shows exactly
  one pending migration (`…create_prospect_lead_proposals_table`).
- **Intended production action: `php artisan migrate --force`** (one
  forward migration, no data migration, no locking risk — a `CREATE
  TABLE`). Not executed here.
- **Do not** run `migrate:fresh` / `migrate:reset` / `rollback` against
  Supabase — see [§13](#13-rollback-considerations).

## 9. Supabase / PostgreSQL compatibility review

| Concern | V2 usage | PG-safe? |
|---|---|---|
| `lower()` + `LIKE` | `ProspectDuplicateCheckService` prefilter: `whereRaw('lower(website) like ?')` / `lower(name)` — deliberately lowercases both sides so it is case-insensitive on PG (whose `LIKE` is case-sensitive) and SQLite alike | ✅ |
| JSON columns | `prospect_lead_proposals.prospect_snapshot` / `proposed_organization` / `proposed_lead` — `$table->json()` → `json` on PG; cast to `array` | ✅ |
| `lockForUpdate()` | one `SELECT … FOR UPDATE` in `confirmAndCreate()` | ✅ (PG native) |
| Nested `DB::transaction` | outer confirm txn + `OrganizationService`/`LeadService` inner txns → PG `SAVEPOINT` | ✅ |
| Unique constraint handling | `organizations.name` unique violation caught by SQLSTATE `23505` (PG) / `23000` (generic) + `str_contains('unique')` fallback | ✅ |
| Enums | stored as plain `varchar` (`status`, `eligibility`, `duplicate_*`), cast in the model | ✅ |
| Timestamps | `$table->timestamp('expires_at')` etc. → `timestamp(0) without time zone`; model casts to `datetime` | ✅ |
| `withCount` | `->withCount(['leads', 'opportunities'])` — standard correlated subquery | ✅ |

Nothing SQLite-only or MySQL-only. Tests run on SQLite `:memory:`
(`phpunit.xml`); the SQL patterns above were inspected for PG. No new
production credentials created.

## 10. Configuration & environment

All V2 config lives under `config('services.market_intelligence')` and
`config('services.search')` — plain arrays, **config-cache safe** (no
closures). `php artisan config:cache`, `route:cache`, `view:cache` all
succeed on the current codebase (verified). V2 adds no closure routes.

| Env var | Purpose | Default (safe) | Required for… |
|---|---|---|---|
| `SEARCH_PROVIDER` | web-search adapter | *(unset → NullSearchProvider, discovery reports "not configured")* | V2.1–V2.3 live discovery |
| `BRAVE_SEARCH_API_KEY` | Brave key | `''` (never a baked-in key) | `SEARCH_PROVIDER=brave` |
| `BRAVE_SEARCH_COUNTRY` | result bias | *(unset)* | optional |
| `SEARCH_HTTP_TIMEOUT` | seconds | `15` | — |
| `MARKET_INTELLIGENCE_MAX_*` (results / searches / fetches / fetch_timeout / per_hour) | V2.1 discovery bounds | 20 / 3 / 8 / 12 / 8 / 12 | — |
| `MARKET_INTELLIGENCE_MAX_QUALIFY_*` | V2.2 bounds | 8 / 6 / 8 / 12 | — |
| `MI_SCORING_VERSION` / `MI_SCORING_BAND_HIGH` / `MI_SCORING_BAND_MEDIUM` / `MARKET_INTELLIGENCE_MAX_SCORE_PER_HOUR` | V2.3 | `v2.3-default-1` / 75 / 50 / 12 | — |
| `MI_DUP_POLICY_VERSION` / `MARKET_INTELLIGENCE_MAX_DUP_PER_HOUR` | V2.4 | `v2.4-default-1` / 12 | — |
| `MI_LEAD_PROPOSAL_VERSION` / `MARKET_INTELLIGENCE_MAX_PROPOSALS_PER_HOUR` | V2.5 | `v2.5-default-1` / 20 | — |

Scoring **weights**, duplicate **thresholds**, and proposal **TTL** are
edited directly in `config/services.php` (matching V1's
`config('services.business_development')`). `ScoringModel::fromConfig()`
and `DuplicateMatchPolicy::fromConfig()` **validate on load and fall back
to frozen defaults** with `config_valid: false` surfaced in the output +
audit. `.env.example` carries every var name with a safe placeholder; no
secret is committed (verified in the V2.6 config test).

## 11. Route / cache review

| Route | Method | Middleware | Notes |
|---|---|---|---|
| `market-intelligence.prospect-proposals.show` | GET | `auth`, `verified` | read-only; `ProspectLeadProposalPolicy::view` |
| `market-intelligence.prospect-proposals.confirm` | **POST** | `auth`, `verified`, **`throttle:12,1`** | the only V2 CRM-write route; policy + Form Request |
| `market-intelligence.prospect-proposals.cancel` | **POST** | `auth`, `verified` | state change is POST, never GET |

No state-changing GET. No debug route. CSRF is Laravel's default web
middleware (`@csrf` in the review form). `route:cache` works.

## 12. Hostinger / deployment readiness

V2 requires **none** of: local persistent file storage for MI state
(proposals are DB-backed), writable app source dirs, a long-running
worker (search/fetch is request-bound and bounded; no queue jobs added),
browser automation, or a Node process in production (`public/build/` is
committed per V1). The document root stays `/public`. Outbound HTTPS to
the search provider is the only new external network requirement, and
only when `SEARCH_PROVIDER` is set. The `proc_open`-disabled
Hostinger constraint from V1 is unaffected (no new artisan-in-composer
steps).

## 13. Rollback considerations

- **Application code** rolls back cleanly (git revert of the V2 commits)
  — the MI agent simply disappears from the assistant.
- **`prospect_lead_proposals`** is a forward-only additive table. Rolling
  the *code* back leaves the table in place (harmless, unused). Do **not**
  drop it on a code rollback if rows exist.
- **Leads / Organizations created through V2.5 are real CRM data.** A code
  rollback must **not** delete them — they are indistinguishable from any
  other lead except `source = "Market Intelligence"` and the linked
  (now-orphaned but harmless) proposal row.
- **Disabling V2 without a code rollback:** set `SEARCH_PROVIDER` unset →
  discovery/qualification/scoring report "not configured" and produce
  nothing; `check_prospect_duplicates` and `prepare_prospect_for_crm`
  still function on supplied identities but a proposal can only be
  prepared from a successful duplicate check. There is **no dedicated
  feature flag** for the whole MI agent — if a hard kill-switch is
  required, the smallest safe option is to remove the `MarketIntelligence`
  `AgentDefinition` from `AppServiceProvider` (one edit) and deploy. Not
  implemented in V2.6 (spec §42 — "do not invent one unless necessary").

## 14. Freeze declaration

### Final test baseline

```
php artisan test          → 1001 passed, 3451 assertions, 0 failures
./vendor/bin/pint --test  → PASS
```

Delta from the V2.5 baseline (966 / 3185): **+35 tests, +266 assertions**
— all V2.6 security / adversarial / UAT coverage (`V2FreezeInvariantsTest`,
`V2CrossTeamPrivacyAttackTest`, `V2HostileContentMatrixTest`,
`V2WorkflowUatTest`, `V2ConfirmationSecurityTest`, `V2SsrfAdversarialTest`).
No prior-phase regression.

Static analysis: the repository has **no** PHPStan / Larastan / Psalm /
Rector configured — Pint is the only quality gate, and it passes. No new
analysis dependency introduced (spec §39).

### Release-blocker status

**No critical or high release blocker remains.** Two low-severity
hardening items were found and fixed in V2.6 (§7). Every freeze criterion
in the V2.6 spec §43 is met.

### Known remaining limitations (all classified B/C — not blockers)

| Item | Class |
|---|---|
| No "my pending proposals" list page — a proposal is reached by the URL the assistant returns (a lost URL → re-prepare, which supersedes cleanly). | B — post-V2 UX |
| No expiry sweep for `prospect_lead_proposals` (mirrors V1 `workflow_approvals`, which also persists after decision). `isExpired()` is the authoritative gate, so stale rows are inert. | B — post-V2 operational (`market-intelligence:prune-proposals` command) |
| Team Head `no_match` cannot assert org-wide absence — surfaced honestly in `next_action`, by design. | Documented, not a defect |
| `industry` on a proposal is human-provided (from the search category), not evidence-verified — clearly labelled editable. | Documented, not a defect |
| `LIKE %token%` duplicate prefilter does not use an index — fine at current CRM scale (`candidate_scan_cap = 50`); a `website_host` column + b-tree, or `pg_trgm` GIN on `name`, would help at tens of thousands of orgs. | C — future-scale |
| `registrableDomain()` / host-normalisation logic exists in 4 places (`IdentityNormalizer` is the natural home; V2.1–V2.2 private copies not yet pointed at it). | B — post-V2 cleanup |
| `MatchSignal::strength` / small `str()` cleaners / physical-category hint list — minor duplication / heuristics. | B — post-V2 cleanup |
| Invalid `proposal_ttl_hours` fails safe (proposal expires early) rather than validating. | C — trivial hardening |

### Decision

- **V2 feature freeze: GO.**
- **V2 release tag: GO** — recommended `v2.0.0` **after explicit
  approval** (not created here; deployment not executed here).

> ## V2 FEATURE FREEZE
>
> V2.1–V2.6 are complete and frozen. No further V2 feature changes before
> deployment / UAT sign-off, except: release-blocking defect fixes,
> security fixes, and deployment fixes. All enhancements move to V3 /
> backlog (`docs/V2_BACKLOG.md`).

---

## 15. Production deployment checklist (document — do not execute without instruction)

**Code**
- [ ] V2.6 commit on `main`, clean working tree, no secrets, `AGENTS.md` excluded
- [ ] `composer install --no-dev --optimize-autoloader` (Hostinger: `--no-scripts` + `php artisan package:discover` per `deploy/redeploy.sh`)
- [ ] `public/build/` present (committed — no Node build step)
- [ ] `php artisan config:cache`, `route:cache`, `view:cache`

**Environment (`.env` on the server)**
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://app.mbsoft.online`
- [ ] `TRUSTED_PROXIES` set, `SESSION_SECURE_COOKIE=true`
- [ ] DB / Supabase vars (Session Pooler) set
- [ ] `SEARCH_PROVIDER=brave` + `BRAVE_SEARCH_API_KEY=…` **only if** MI discovery is to be enabled (otherwise leave unset → MI reports "not configured", safe)
- [ ] any overridden `MARKET_INTELLIGENCE_*` / `MI_*` limits/versions

**Database**
- [ ] backup / point-in-time-restore awareness for Supabase
- [ ] `php artisan migrate --force` → applies `2026_08_31_090000_create_prospect_lead_proposals_table` (one `CREATE TABLE`)
- [ ] `php artisan migrate:status` shows all Ran, no Pending

**Laravel / web**
- [ ] `storage/`, `bootstrap/cache/` writable
- [ ] session/cache driver = `database` (V1 default), queue = `database` (no new jobs)
- [ ] scheduler unchanged (no new scheduled task in V2)
- [ ] subdomain document root → Laravel `/public` (V1 `.htaccess` rewrite)
- [ ] HTTPS enforced; outbound HTTPS to `api.search.brave.com` allowed

**Post-deploy smoke tests**
- [ ] log in
- [ ] Manager: open assistant, confirm "Market Intelligence" is offered
- [ ] Team Head: same
- [ ] Team Member: assistant does **not** offer "Market Intelligence"
- [ ] (if `SEARCH_PROVIDER` set) Manager: "find cosmetics sellers in Cebu" → prospects returned; else expect a clean "not configured" message
- [ ] Manager: `check_prospect_duplicates` on a known CRM org → `exact_duplicate`
- [ ] Manager: `prepare_prospect_for_crm` on a `no_match` → review URL, **no CRM row created**
- [ ] **Only if the deployment plan explicitly allows a test write:** confirm one proposal → verify exactly one Organization + Lead with `source = "Market Intelligence"`, then archive them
- [ ] check the `audit` log channel for `market_intelligence.*` entries with no bodies/secrets

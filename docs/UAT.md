# User Acceptance Testing — Scripts and Sign-off

Run against a **staging environment with demo data** (never production
— see `docs/OPERATIONS.md` §3's seed-data guard). Use the existing
seeded demo accounts (`database/seeders/OrganisationSeeder.php`) —
manager, 3 seeded team heads/team pairs are enough to exercise every
role; the remaining 7 team heads share the identical code path (proven
by the automated suite's role-scoped, not team-count-scoped, tests —
see `docs/TEST_STRATEGY.md`), so UAT does not need to separately script
all 10.

Each script below: **Steps**, **Expected result**, **Pass/Fail**,
**Notes**. The product owner signs off per section; overall sign-off
at the bottom gates the go/no-go decision (`docs/GO_NO_GO.md`).

## 1. Manager workflows

1. Log in as the Manager. **Expected**: reach `/dashboard`, see
   organisation-wide metrics (all teams).
2. Create a new Team Head user. **Expected**: appears in `/users`,
   audit log entry recorded (`storage/logs/audit.log`, `user.created`).
3. Change an existing user's role/team. **Expected**: change applies,
   audit log entry `user.role_or_team_changed` recorded with old/new
   values.
4. Create a new team; assign a head. **Expected**: team appears,
   previous head (if any) demoted to Team Member, not removed; audit
   entries `team.created`/`team.head_assigned` recorded.
5. View any team's detail/performance page. **Expected**: full access,
   no restriction.

## 2. Each Team Head portal

Repeat for at least 3 different teams (seeded team heads):

1. Log in as that Team Head. **Expected**: dashboard shows only that
   team's metrics — no other team's data visible anywhere (lists,
   totals, charts).
2. Attempt to view another team's detail page by editing the URL.
   **Expected**: forbidden (403), not silently redirected with data
   leaked.
3. Create/assign a lead within the team. **Expected**: succeeds;
   assignment restricted to members of that team only (attempt to type
   another team's member id — should be rejected server-side even if
   somehow submitted).

## 3. Lead and target workflows

1. Create a lead, progress it through statuses to Converted.
   **Expected**: each transition validates current state; activity
   history shows every change.
2. Reassign a lead's owner. **Expected**: succeeds within authorized
   bounds; an Activity entry records the reassignment (previous →
   new owner).
3. Set a target for a period; record enough opportunity activity to
   partially achieve it. **Expected**: achievement percentage matches
   manual calculation (spot-check the arithmetic — see
   `docs/PERFORMANCE.md` for the documented formula).

## 4. Dashboard accuracy

1. As Manager, compare the dashboard's organisation totals against a
   manual count of the underlying leads/opportunities for the same
   period. **Expected**: match exactly.
2. As a Team Head, confirm their dashboard total equals the sum of
   only their own team's records — never inflated by another team's
   data.
3. Change the period filter (if present) and confirm figures update
   correctly for the new boundary.

## 5. Gmail/WhatsApp approval workflow

**Use a designated safe test Gmail account and/or WhatsApp test
number only** — never a real customer-facing number/inbox, per
CLAUDE.md's explicit instruction not to send real messages during
UAT/demos unless a safe test account is authorized.

1. Connect a test Gmail account via OAuth. **Expected**: connection
   succeeds; disconnect also works cleanly.
2. Compose a draft email to a test recipient. **Expected**: message is
   **not** sent until explicit confirmation on the compose screen.
3. Confirm and send. **Expected**: message sends via the real Gmail
   API to the test recipient only; `Communication` record shows
   `sent`/`delivered` status as the provider reports it.
4. Repeat for WhatsApp using a registered test number.
5. Force a send failure (e.g. an invalid recipient). **Expected**:
   `Communication` marked `failed` with a plain-language reason, and
   — new in Phase 11 — a notification appears for the sending user.

## 6. Notifications (new in Phase 11)

1. Trigger a workflow that produces a draft approval (e.g. wait for
   or manually invoke the daily follow-up review for a user with an
   overdue follow-up). **Expected**: a "draft waiting for your review"
   notification appears in the bell/`/notifications` page, linking to
   the correct compose screen pre-filled with that draft.
2. Cause a send failure (§5.5). **Expected**: a "could not be sent"
   notification appears, linking to the failed communication.
3. Mark one notification read, then "mark all read". **Expected**: the
   unread badge count updates correctly each time.
4. As a second user, confirm you never see the first user's
   notifications.

## 7. Knowledge search

1. As Manager, add a short policy document (Markdown, with at least
   two `#`/`##` headings). **Expected**: appears with status
   "Processing", then "Active" shortly after (async job).
2. Ask the appropriate agent (matching the document's assigned
   knowledge type — see `docs/KNOWLEDGE.md`'s permission matrix) a
   question the document answers. **Expected**: the agent's answer
   cites the document title/section, and does not fabricate an answer
   for something not covered.
3. Ask about a topic with no matching document. **Expected**: the
   agent states plainly it couldn't find it in company knowledge —
   never invents a policy.
4. As a Team Head, confirm you cannot retrieve a document marked
   Manager-only visibility (ask a question only that document would
   answer; expect "not found," never the content).

## 8. Existing AI-agent interactions — approval and access boundaries

1. Ask the Sales, Performance, and Communication agents (via the
   `/assistant` dropdown) a question each is suited to. **Expected**:
   accurate, tool-backed answers; never a fabricated number.
2. Ask the Communication agent to draft a message. **Expected**: a
   draft is produced and requires the normal compose-screen
   confirmation before anything sends — the agent itself never sends.
3. Ask a deliberately out-of-scope question (e.g. ask the Performance
   agent to draft an email). **Expected**: the agent explains it
   cannot do that and suggests the right agent — it does not attempt
   the task via an unrelated tool.
4. Trigger a "management review" style request as Manager. **Expected**:
   both Performance and Sales analyses appear, clearly sectioned.

## 9. Permission-denied and error/recovery scenarios

1. As a Team Member, attempt to reach `/users`, `/teams`, or another
   team's records via direct URL. **Expected**: 403, no data leaked.
2. As a guest (logged out), attempt any authenticated route.
   **Expected**: redirected to `/login`.
3. Submit a form with invalid data (e.g. missing required field).
   **Expected**: clear, field-level validation error, no stack trace,
   no data loss of what was already correctly filled in.
4. Attempt to reuse an already-approved or expired `WorkflowApproval`.
   **Expected**: rejected with a plain message, nothing sent.

## Sign-off

| Section | Tester | Result | Date |
|---|---|---|---|
| 1. Manager workflows | | ☐ Pass ☐ Fail | |
| 2. Team Head portals | | ☐ Pass ☐ Fail | |
| 3. Lead/target workflows | | ☐ Pass ☐ Fail | |
| 4. Dashboard accuracy | | ☐ Pass ☐ Fail | |
| 5. Gmail/WhatsApp approval | | ☐ Pass ☐ Fail | |
| 6. Notifications | | ☐ Pass ☐ Fail | |
| 7. Knowledge search | | ☐ Pass ☐ Fail | |
| 8. AI-agent interactions | | ☐ Pass ☐ Fail | |
| 9. Permission-denied/error scenarios | | ☐ Pass ☐ Fail | |

**Overall UAT sign-off (product owner):** ☐ Approved ☐ Not approved —
date/signature: ______________________

This checklist is intentionally left unchecked — it has not yet been
executed against a live staging environment with real (test) Gmail/
WhatsApp credentials. Completing it is a prerequisite for `docs/GO_NO_GO.md`.

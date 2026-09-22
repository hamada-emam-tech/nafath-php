# Getting credentials from Nafath

> See also: [how verification actually flows](flow.md) ·
> [Laravel integration guide](laravel.md)

Before any code, you need a registration with Elm's **Rabet** platform — the
credential pair (`APP-ID` / `APP-KEY`) this package needs does not exist until
that is done. This is a business/legal process as much as a technical one, and
it is worth doing in the right order, because each environment is a **separate**
registration with its **own** credentials (see `Environment` in the README).

## 1. Register as a service provider on Rabet

- Rabet is Elm's integration platform for Nafath and other national services:
  https://rabet.elm.sa (Elm account required — a company, not a personal one).
- You apply as a "service provider" (SP) and describe the use case: what you're
  verifying identity for (onboarding, login, high-value actions, KYC, etc.).
  Nafath's service catalogue (`Login`, `OpenAccount`, `ResetPassword`, and the
  `WithoutBio` variants) maps to the journeys you describe — see `Purpose` in
  this package for how that's modelled on the code side.
- Elm/Nafath review the application. Expect this step to take the longest —
  it is a manual approval, not a self-serve signup, and requires a commercial
  relationship (there is a cost per verification).

## 2. You get sandbox first, not production

Approval starts you in **Sandbox** — synthetic test identities, no real
citizens. Use this to build and test both flows end-to-end before anyone at
Elm looks at your integration again.

Sandbox and staging credentials are separate registrations from production;
budget time for each promotion step, they are not automatic.

## 3. Promotion to Staging, then Production

Moving from Sandbox → Staging → Production is a review each time — Elm expects
to see a working integration before promoting you, and production is where
compliance requirements (below) are actually checked, not just documented.

Each promotion issues a **new** `APP-ID`/`APP-KEY` pair for that environment.
Do not reuse a sandbox pair anywhere near production config — see the README's
trap table; a mismatched pair fails with a `403` that looks exactly like a bad
key, not like "wrong environment".

## 4. What Nafath requires of you before going live

These are hard requirements, not recommendations — non-compliance is why
integrations get suspended, not just why they get support tickets.

- **Your callback endpoint must be hosted inside Saudi Arabia.** This is a
  regulatory requirement tied to handling citizens' identity data, not a
  technical one — a callback hosted on infrastructure outside the Kingdom
  (including some "global" CDN edge configurations) is a compliance problem
  Elm will flag. `ConfigurationException::callbackNotInSaudiArabia()` exists
  in this package to give that failure a name instead of a generic error.
- **Your server's outbound IP must be allowlisted with Elm.** This is the
  `serverIp` you pass to `Nafath::make()` — it's not just metadata, requests
  from an IP Elm hasn't allowlisted are rejected. If you're behind a load
  balancer or scale horizontally, every egress IP needs to be registered, or
  you need a stable NAT gateway IP.
- **You never see or store the Nafath password/OTP flow.** The person
  authenticates entirely within the Nafath app or portal; your integration
  only ever receives the *result* (a signed token). Any UI or design implying
  you collect Nafath credentials directly is not how this system is meant to
  work and will not pass review.
- **Signature verification is mandatory.** Elm expects the JWK-based signature
  check on every token — this package does not offer a way to skip it (see
  `TokenVerifier`), and neither should your integration.
- **Handle `identifier` (NIN/Iqama/Visa/Border) as regulated data** — encrypt
  at rest, restrict access, and don't log it. `Claims::identifierDigest()`
  exists for uniqueness checks and audit trails without storing or logging the
  raw number.

## 5. What to bring to the review

Elm's review typically wants to see:

- A working sandbox integration exercising both flows you intend to use (web
  redirect, app-push, or both).
- Your callback URL, hosted in KSA, reachable over HTTPS.
- The server IP(s) you want allowlisted for staging/production.
- A description of what happens to the verified identity afterwards — where
  it's stored, who can access it, how long it's retained. This package stays
  intentionally stateless (see the framework guides in this `docs/` folder for
  how to build that storage yourself) — Elm's review is about *your*
  handling of the data, not the library's.

## 6. Day-to-day operations

- Run `$nafath->preflight()` at deploy time against each environment — it
  exercises auth, the path prefix, and reachability in one call, so a bad
  credential or IP allowlist gap surfaces at deploy, not from a user's failed
  verification.
- Keep sandbox/staging/production credentials in separate secrets, scoped so a
  deploy to one environment can't accidentally read another's pair.
- Elm support requests will ask for the `reference` number from a failed call
  — that's why `CallRecorder` exists (see the framework guides): without it,
  you're reconstructing an incident from application logs instead of handing
  Elm their own trace number.

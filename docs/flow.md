# How verification actually happens

The README shows the API calls. This is what happens between them — who is
waiting on whom, and which channel each piece of information actually arrives
on. Both flows have a detail the official docs don't make obvious: the
"result" of a verification and the "proof" of it arrive separately, and
conflating them is the mistake this package exists to prevent.

## Web flow

The person leaves your site, authenticates on Nafath's own portal, and comes
back. There are two independent ways your backend learns the outcome — build
for both, because relying on only the first is what breaks in production.

```
 Person          Your app                    Nafath
   |                 |                           |
   | 1. clicks       |                           |
   |   "Verify"      |                           |
   |---------------->|                           |
   |                 | 2. web()->start()         |
   |                 |-------------------------->|
   |                 |<-- AuthorizeUrl -----------|
   |                 |    (url, state, hashedState)
   |                 |
   |                 | 3. store state, keyed by hashedState
   |                 |    (cache — it's single-use and short-lived)
   |                 |
   |<-- redirect to Nafath's portal (url), unmodified --|
   |                 |
   | 4. authenticates on iam.sa / iam.gov.sa (outside your site)
   |<--------------------------------------------------|
   |                 |
   |                 |  === from here, two independent paths ===
   |                 |
   |  5a. browser returns to your callback URL, carrying `state`
   |----------------------------------------->|
   |                 | 6a. digest(state), look up by hashedState,
   |                 |     web()->complete(state, ip)
   |                 |-------------------------->|
   |                 |<-- verified / pending -----|
   |                 |
   |                 |  5b. independently, poll web()->complete(storedState, ip)
   |                 |      every few seconds using the state you kept in
   |                 |      step 3 — does NOT require the browser to return
   |                 |-------------------------->|
   |                 |<-- verified / pending -----|
```

**Why both paths matter.** Nafath's callback to your site is a browser
redirect, which means it depends on the person's network, their browser not
losing the tab, and your callback URL being reachable — none of which is
guaranteed. Because `start()` already hands you the `state` up front, you are
never dependent on that redirect actually arriving: a background poll using
the stored `state` finishes the verification even if the callback never
fires. Treat the callback as the fast path and the poll as the path that
makes the integration actually reliable.

**The state is single-use.** Once `complete()` returns `verified`, that state
is spent — whichever path got there first "wins"; asking again with the same
state fails. Persist the result the moment you have it and stop polling.

**`hashedState` vs `state`.** You get both up front. `hashedState` is safe to
store as a lookup key (it's what correlates an incoming callback to the
session you started); `state` is the single-use credential you redeem with.
They are never interchangeable — see the trap table in the [README](../README.md).

## App-push flow

The person never leaves your site. This flow has two channels running in
parallel that most integrations wire only one of: a status you poll (a
decision), and a token Nafath pushes to your callback (the actual identity).
**"Approved" is not "verified"** — treat them as different events.

```
 Person          Your app                    Nafath
   |                 |                           |
   | 1. enters       |                           |
   |  national ID    |                           |
   |---------------->|                           |
   |                 | 2. app()->start(id, ...)  |
   |                 |-------------------------->|
   |                 |<-- AppTransaction ---------|
   |                 |    (transactionId, random) |
   |                 |                           |
   |<-- show the two-digit `random` on your page -|
   |                 |                           |
   |                 |          3. Nafath pushes a request to the
   |                 |             person's phone, in parallel
   |                 |<--------------------------|
   |                 |                           |
   | 4. opens Nafath app, sees the same digits,  |
   |    matches them against your page, approves |
   |    (or rejects)                             |
   |                 |                           |
   |                 | 5. meanwhile, poll app()->status()
   |                 |    every 2-3s             |
   |                 |-------------------------->|
   |                 |<-- WAITING / COMPLETED / REJECTED / EXPIRED
   |                 |
   |                 |  COMPLETED means "approved", NOT "identity in hand" —
   |                 |  it's an unsigned status string. Do not treat it as
   |                 |  proof of who the person is.
   |                 |
   |                 | 6. separately, Nafath POSTs { token, transId,
   |                 |    requestId } to your callback URL
   |                 |<--------------------------|
   |                 |
   |                 | 7. app()->verifyCallbackToken(token, ip)
   |                 |    — verifies the signature, returns Claims
```

**Why the split.** The status endpoint tells you a decision was made; the
callback token is the only place the actual attributes (name, nationality,
document type) arrive, and only as a signature you verify. An integration
that reads `COMPLETED` from the status poll and treats the person as
identified — without ever checking the callback token — is trusting an
unsigned string for something a cryptographic signature exists to prove.
Correlate the two channels on `requestId` (yours) or `transactionId`
(Nafath's); both are on `AppTransaction` and echoed on the callback body.

**Only one transaction per identity.** A second `start()` call for the same
national ID while one is pending is refused (`400-034-050`). Surface that to
the person as "check your Nafath app," not as a generic error — see
[`VerificationException::transactionAlreadyActive()`](../src/Exception/VerificationException.php).

## Where to go next

- [README](../README.md) — the API itself, and the full trap table.
- [Getting credentials from Nafath](getting-started-with-nafath.md) — the
  Rabet registration process and what Elm requires before you go live.
- [Laravel](laravel.md) · [Symfony](symfony.md) · [CodeIgniter](codeigniter.md)
  — wiring, plus a full persistence walkthrough (single-tenant and
  multi-tenant) for each.

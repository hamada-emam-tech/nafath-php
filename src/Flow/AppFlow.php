<?php

declare(strict_types=1);

namespace HamadaEmam\Nafath\Flow;

use HamadaEmam\Nafath\Exception\VerificationException;
use HamadaEmam\Nafath\Http\AppTransaction;
use HamadaEmam\Nafath\Http\NafathClient;
use HamadaEmam\Nafath\Identity\VerificationResult;
use HamadaEmam\Nafath\Purpose\Purpose;
use HamadaEmam\Nafath\Token\TokenVerifier;

/**
 * The app-push flow: the person never leaves your site.
 *
 * You collect their national ID, Nafath pushes a request to their phone, you
 * display a two-digit number, they match it and approve. No redirect, no return
 * URL, no browser to lose.
 *
 * Worth considering as the default for most journeys. The web flow's hand-off
 * to an external portal is where most integration problems live, and this
 * avoids it entirely — at the cost of asking for the national ID yourself.
 *
 * One rule that surprises people: only ONE transaction may be active per
 * identity at a time. A second request while one is pending is refused with
 * 400-034-050, which means a user who taps "verify" twice locks themselves out
 * until the first expires. Surface that as "check your Nafath app", never as a
 * generic failure.
 */
final class AppFlow
{
    public function __construct(
        private readonly NafathClient $client,
        private readonly TokenVerifier $verifier,
    ) {}

    /**
     * Push a request to the person's Nafath app.
     *
     * Display the returned `random` — the same two digits appear in their app,
     * and matching them is what stops somebody approving a request they did not
     * initiate.
     *
     * @throws VerificationException 400-034-050 when they already have one pending
     */
    public function start(
        string $identifier,
        string $requestId,
        string $userIp,
        ?Purpose $purpose = null,
        string $locale = 'ar',
    ): AppTransaction {
        return $this->client->createAppRequest(
            identifier: $identifier,
            service:    ($purpose ?? Purpose::login())->serviceKey,
            locale:     $locale,
            requestId:  $requestId,
            userIp:     $userIp,
        );
    }

    /**
     * Poll the request.
     *
     * Nafath answers WAITING, COMPLETED, REJECTED or EXPIRED. Note that
     * COMPLETED here means the person approved — the identity itself arrives on
     * your callback as a signed token, or you fetch it separately.
     */
    public function status(AppTransaction $transaction, string $identifier, string $userIp): VerificationResult
    {
        $status = $this->client->appRequestStatus(
            $identifier,
            $transaction->transactionId,
            $transaction->random,
            $userIp,
        );

        return match ($status) {
            // Approved, not Verified. The status endpoint reports a decision;
            // the attributes arrive as a signed token on your callback. Treating
            // an unsigned status string as proof of identity would defeat the
            // whole point of the signature.
            'COMPLETED' => VerificationResult::approved($transaction->transactionId),
            'REJECTED' => VerificationResult::rejected($transaction->transactionId),
            'EXPIRED'  => VerificationResult::expired($transaction->transactionId),
            default    => VerificationResult::pending($transaction->transactionId),
        };
    }

    /**
     * Verify the token Nafath posts to your callback.
     *
     * Their callback body is `{ token, transId, requestId }` — note `token`, and
     * note there is no `state` in this flow. Correlate on the `requestId` you
     * generated.
     */
    public function verifyCallbackToken(string $token, string $userIp): VerificationResult
    {
        $claims = $this->verifier->verify($token, $userIp);

        return VerificationResult::verified($claims, $claims->transactionId);
    }
}

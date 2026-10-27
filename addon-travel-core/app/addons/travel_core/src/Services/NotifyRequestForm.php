<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\NotifyRequestRepository;

/**
 * The hotel page's "notify me" form (docs/novoton-availability-gaps-plan.md
 * §8): one email address, an unticked consent box the guest must tick, and
 * the request stored with when they agreed and to which text.
 *
 * What the guest waits for comes from the hotel's panel, never from the
 * form: a hotel whose panel offers no "notify me" (bookable now, no longer
 * available, panels switched off) takes no request. A public form that
 * stores an address and later writes to it is guarded like the Sphinx
 * request form: a honeypot field, a pause between two requests and a few
 * requests per visit.
 */
final class NotifyRequestForm
{
    /** Seconds between two requests from one visit. */
    public const int COOLDOWN = 60;

    /** Requests one visit may make. */
    public const int PER_SESSION = 5;

    private const string SESSION_KEY = 'travel_notify_requests';

    public function __construct(
        private readonly AvailabilityPanel $panels = new AvailabilityPanel(),
        private readonly NotifyRequestRepository $requests = new NotifyRequestRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $input the form: product_id, email, consent, website (the honeypot)
     * @param array<array-key, mixed>|\ArrayAccess<mixed, mixed> $session the visit (CS-Cart's session object)
     * @param \DateTimeImmutable $now the time of the request (the cooldown and the stored date)
     * @param string $lang the visitor's language; its first two letters are stored
     *
     * @return 'saved'|'invalid_email'|'no_consent'|'too_soon'|'unavailable'|'failed'
     */
    public function submit(array $input, array|\ArrayAccess &$session, \DateTimeImmutable $now, string $lang): string
    {
        // Humans never see the honeypot; a bot fills every field. Pretend it worked.
        if (TypeCoerce::toString($input['website'] ?? '') !== '') {
            return 'saved';
        }
        $sent = array_values(array_filter(
            array_map(static fn (mixed $t): int => TypeCoerce::toInt($t), (array) ($session[self::SESSION_KEY] ?? [])),
            static fn (int $t): bool => $t > 0,
        ));
        if (($sent !== [] && $now->getTimestamp() - max($sent) < self::COOLDOWN) || count($sent) >= self::PER_SESSION) {
            return 'too_soon';
        }

        $email = trim(TypeCoerce::toString($input['email'] ?? ''));
        if ($email === '' || mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'invalid_email';
        }
        if (TypeCoerce::toString($input['consent'] ?? '') !== 'Y') {
            return 'no_consent';
        }

        $productId = TypeCoerce::toInt($input['product_id'] ?? 0);
        $panel = $this->panels->forProduct($productId, $now);
        if ($panel === null || $panel['notify'] === '') {
            return 'unavailable';
        }

        try {
            $auth = TypeCoerce::toStringMap($session['auth'] ?? []);
            $this->requests->create([
                'provider' => $panel['provider'],
                'hotel_id' => $panel['hotel_id'],
                'hotel_name' => $panel['hotel_name'],
                'product_id' => $productId,
                'user_id' => TypeCoerce::toInt($auth['user_id'] ?? 0),
                'email' => $email,
                'lang_code' => mb_substr($lang, 0, 2),
                'watch_for' => $panel['notify'],
                'consent_version' => AvailabilityPanel::CONSENT_VERSION,
            ], $now->format('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', ['message' => '[TravelAvailability] notify-me request for product ' . $productId . ' failed: ' . $e->getMessage()]);

            return 'failed';
        }
        $sent[] = $now->getTimestamp();
        $session[self::SESSION_KEY] = $sent;

        return 'saved';
    }
}

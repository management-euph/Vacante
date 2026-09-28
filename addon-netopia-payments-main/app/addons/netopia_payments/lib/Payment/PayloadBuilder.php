<?php

declare(strict_types=1);

namespace Netopia\CsCart\Payment;

use Netopia\CsCart\Dto\Address;
use Netopia\CsCart\Dto\CardData;
use Netopia\CsCart\Dto\Product;
use Netopia\CsCart\Dto\ThreeDsData;
use Netopia\CsCart\Support\Arr;
use Netopia\CsCart\Support\ClockInterface;
use Netopia\CsCart\Support\CountryCodes;

/**
 * Builds JSON payloads for NETOPIA /payment/card/start and /verify-auth endpoints.
 *
 * @phpstan-type ProcessorParams array{
 *     pos_signature: string,
 *     api_key: string,
 *     mode?: string,
 *     currency?: string,
 *     allow_installments?: string,
 *     max_installments?: int|string,
 *     ...<string, mixed>
 * }
 * @phpstan-type OrderInfo array{
 *     order_id: int|string,
 *     total: float|int|string,
 *     email?: string,
 *     secondary_currency?: string,
 *     b_firstname?: string,
 *     b_lastname?: string,
 *     b_phone?: string,
 *     b_address?: string,
 *     b_address_2?: string,
 *     b_city?: string,
 *     b_state?: string,
 *     b_state_descr?: string,
 *     b_zipcode?: string,
 *     b_country?: string,
 *     s_firstname?: string,
 *     s_lastname?: string,
 *     s_phone?: string,
 *     s_address?: string,
 *     s_address_2?: string,
 *     s_city?: string,
 *     s_state?: string,
 *     s_state_descr?: string,
 *     s_zipcode?: string,
 *     s_country?: string,
 *     phone?: string,
 *     payment_info?: array<string, mixed>,
 *     products?: array<int, array<string, mixed>>
 * }
 */
final class PayloadBuilder
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly string $notifyUrl,
        private readonly string $redirectUrl,
        private readonly string $primaryCurrency,
        private readonly string $language = 'RO',
        private readonly string $siteUrl = '',
    ) {
    }

    /**
     * Build a full payment start request.
     *
     * Pass $card = null for hosted-page flow (empty instrument).
     *
     * @param array<string, mixed> $processorParams
     * @param array<string, mixed> $orderInfo
     */
    public function buildStartRequest(
        array $processorParams,
        array $orderInfo,
        ThreeDsData $threeDs,
        int $installments,
        ?CardData $card,
    ): string {
        [$currency, $amount] = $this->resolveCurrency($processorParams, $orderInfo);

        $orderId = Arr::string($orderInfo, 'order_id');
        $apiKey = Arr::string($processorParams, 'api_key');

        $payload = [
            'config' => $this->buildConfig($orderId, $apiKey),
            'payment' => [
                'options' => [
                    'installments' => max(1, $installments),
                    'bonus' => 0,
                ],
                'instrument' => ($card ?? CardData::empty())->toInstrument(),
                'data' => $threeDs->toArray(),
            ],
            'order' => $this->buildOrderSection($processorParams, $orderInfo, $currency, $amount, $installments),
        ];

        return $this->encode($payload);
    }

    /**
     * @param array<string, mixed> $processorParams
     * @param array<string, mixed> $orderInfo
     * @return array{string, float} [currency, amount]
     */
    public function resolveCurrency(array $processorParams, array $orderInfo): array
    {
        $amount = Arr::float($orderInfo, 'total'); // always in primary currency

        $configuredCurrency = Arr::string($processorParams, 'currency');

        if ($configuredCurrency === '' || $configuredCurrency === 'order_currency') {
            return [$this->primaryCurrency, $amount];
        }

        $currency = $configuredCurrency;

        if ($currency !== $this->primaryCurrency && function_exists('fn_format_price_by_currency')) {
            $raw = fn_format_price_by_currency($orderInfo['total'], $this->primaryCurrency, $currency);
            $amount = is_numeric($raw) ? (float) $raw : $amount;
        }

        return [$currency, $amount];
    }

    /**
     * @return array{emailTemplate: string, notifyUrl: string, redirectUrl: string, cancelUrl: string, language: string}
     */
    private function buildConfig(string $orderId = '', string $apiKey = ''): array
    {
        $returnUrl = $this->redirectUrl;
        if ($orderId !== '') {
            $separator = str_contains($returnUrl, '?') ? '&' : '?';
            $returnUrl .= $separator . 'order_id=' . urlencode($orderId);
            if ($apiKey !== '') {
                $returnUrl .= '&ntp_sig=' . urlencode(self::signOrderId($orderId, $apiKey));
            }
        }

        return [
            'emailTemplate' => 'confirm',
            'notifyUrl' => $this->notifyUrl,
            // `redirectUrl` is where NETOPIA forwards 3-D Secure auth
            // responses; `cancelUrl` is where the hosted page sends the
            // customer when they use the "Back to merchant" / cancel
            // navigation. Per NETOPIA v2 docs the latter is optional, but
            // leaving it unset makes their hosted flow briefly render a
            // generic "Tranzacția nu a fost finalizată" error page before
            // finally redirecting. Pointing both at the same signed return
            // handler collapses all post-payment UX paths onto one
            // endpoint; the order's final state is still authoritatively
            // set by the IPN.
            'redirectUrl' => $returnUrl,
            'cancelUrl' => $returnUrl,
            'language' => $this->language,
        ];
    }

    /**
     * Compute the HMAC signature that binds an order ID to the merchant's API key.
     *
     * Used to prove that a return URL hit was produced by this server rather than
     * crafted by a third party who merely guessed a valid order ID.
     */
    public static function signOrderId(string $orderId, string $secret): string
    {
        return hash_hmac('sha256', $orderId, $secret);
    }

    /**
     * Constant-time verification of {@see signOrderId()}.
     */
    public static function verifyOrderIdSignature(string $orderId, string $signature, string $secret): bool
    {
        if ($orderId === '' || $signature === '' || $secret === '') {
            return false;
        }
        return hash_equals(self::signOrderId($orderId, $secret), $signature);
    }

    /**
     * @param array<string, mixed> $processorParams
     * @param array<string, mixed> $orderInfo
     * @return array<string, mixed>
     */
    private function buildOrderSection(
        array $processorParams,
        array $orderInfo,
        string $currency,
        float $amount,
        int $installments,
    ): array {
        $billing = $this->buildBillingAddress($orderInfo);
        $shipping = $this->buildShippingAddress($orderInfo);

        // NETOPIA rejects any re-use of an `orderID` on their side — even if
        // the previous attempt failed, retrying a new /payment/card/start
        // with the same ID returns HTTP 400 "Order already processed". Append
        // a per-attempt unique suffix (microsecond-precision clock) so every
        // retry is a fresh NETOPIA transaction; the IPN handler extracts the
        // CS-Cart order_id via (int) cast, which PHP truncates at the first
        // non-digit.
        $retrySuffix = str_replace('.', '', $this->clock->now()->format('U.u'));

        return [
            'ntpID' => null,
            'posSignature' => Arr::string($processorParams, 'pos_signature'),
            'dateTime' => $this->clock->now()->format('c'),
            'description' => $this->buildDescription($processorParams, $orderInfo),
            'orderID' => Arr::string($orderInfo, 'order_id') . '-' . $retrySuffix,
            'amount' => $amount,
            'currency' => $currency,
            'billing' => $billing->toArray(),
            'shipping' => $shipping->toArray(),
            'products' => $this->buildProducts($orderInfo, $amount),
            'installments' => $this->buildInstallments($installments),
            'data' => null,
        ];
    }

    /**
     * @param array<string, mixed> $orderInfo
     */
    private function buildBillingAddress(array $orderInfo): Address
    {
        return new Address(
            email:      Arr::string($orderInfo, 'email'),
            phone:      Arr::firstString($orderInfo, 'b_phone', 'phone'),
            firstName:  Arr::string($orderInfo, 'b_firstname'),
            lastName:   Arr::string($orderInfo, 'b_lastname'),
            city:       Arr::string($orderInfo, 'b_city'),
            country:    CountryCodes::toNumeric(Arr::string($orderInfo, 'b_country', 'RO')),
            state:      Arr::firstString($orderInfo, 'b_state_descr', 'b_state'),
            postalCode: Arr::string($orderInfo, 'b_zipcode'),
            details:    trim(Arr::string($orderInfo, 'b_address') . ' ' . Arr::string($orderInfo, 'b_address_2')),
        );
    }

    /**
     * @param array<string, mixed> $orderInfo
     */
    private function buildShippingAddress(array $orderInfo): Address
    {
        return new Address(
            email:      Arr::string($orderInfo, 'email'),
            phone:      Arr::firstString($orderInfo, 's_phone', 'phone'),
            firstName:  Arr::firstString($orderInfo, 's_firstname', 'b_firstname'),
            lastName:   Arr::firstString($orderInfo, 's_lastname', 'b_lastname'),
            city:       Arr::firstString($orderInfo, 's_city', 'b_city'),
            country:    CountryCodes::toNumeric(Arr::firstString($orderInfo, 's_country', 'b_country') ?: 'RO'),
            state:      Arr::firstString($orderInfo, 's_state_descr', 's_state', 'b_state'),
            postalCode: Arr::firstString($orderInfo, 's_zipcode', 'b_zipcode'),
            details:    trim(
                Arr::firstString($orderInfo, 's_address', 'b_address')
                . ' '
                . Arr::firstString($orderInfo, 's_address_2', 'b_address_2'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $orderInfo
     * @return list<array{name: string, code: string, category: string, price: float, vat: int}>
     */
    private function buildProducts(array $orderInfo, float $amount): array
    {
        $products = [];

        $rawProducts = Arr::array($orderInfo, 'products');
        foreach ($rawProducts as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $product = new Product(
                name:     Arr::string($raw, 'product', 'Product'),
                code:     Arr::firstString($raw, 'product_code', 'product_id'),
                category: 'General',
                price:    Arr::float($raw, 'price'),
                vat:      0,
            );
            $products[] = $product->toArray();
        }

        if ($products === []) {
            $products[] = (new Product(
                name:     'Order #' . Arr::string($orderInfo, 'order_id'),
                code:     Arr::string($orderInfo, 'order_id'),
                category: 'General',
                price:    $amount,
                vat:      0,
            ))->toArray();
        }

        return $products;
    }

    /**
     * @return array{selected: int, available: list<int>}
     */
    private function buildInstallments(int $installments): array
    {
        $selected = max(1, $installments);
        $available = [0];
        if ($selected > 1) {
            $available = range(2, $selected);
            array_unshift($available, 0);
        }
        return ['selected' => $selected, 'available' => $available];
    }

    /**
     * Build order description from template with placeholders.
     *
     * Supports: [order_id], [total], [currency], [email], [company], [site_url]
     * Default: "Order #[order_id]"
     *
     * @param array<string, mixed> $processorParams
     * @param array<string, mixed> $orderInfo
     */
    private function buildDescription(array $processorParams, array $orderInfo): string
    {
        $template = Arr::string($processorParams, 'order_description');
        if ($template === '') {
            $template = 'comanda #[order_id] din [site_url]';
        }

        $orderId = Arr::string($orderInfo, 'order_id');

        return str_replace(
            ['[order_id]', '[total]', '[currency]', '[email]', '[company]', '[site_url]'],
            [
                $orderId,
                Arr::string($orderInfo, 'total'),
                Arr::string($orderInfo, 'secondary_currency', $this->primaryCurrency),
                Arr::string($orderInfo, 'email'),
                $this->language,
                $this->siteUrl,
            ],
            $template,
        );
    }

    /**
     * Build the JSON payload for NETOPIA /payment/card/verify-auth.
     */
    public function buildVerifyAuthRequest(string $authenticationToken, string $ntpId, string $paRes): string
    {
        return $this->encode([
            'authenticationToken' => $authenticationToken,
            'ntpID' => $ntpId,
            'formData' => ['paRes' => $paRes],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}

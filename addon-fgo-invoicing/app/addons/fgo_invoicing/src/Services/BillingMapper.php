<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services;

use Tygh\Addons\FgoInvoicing\Api\FgoSigner;
use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Dto\Billing\BillingParty;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\InvoiceLine;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\IssueInvoiceRequest;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\VatRate;
use Tygh\Addons\FgoInvoicing\Helpers\RomanianTaxId;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;

/**
 * Translates a CS-Cart `$order_info` array (the standard structure returned
 * by `fn_get_order_info`) into an `IssueInvoiceRequest`.
 *
 * The `fgo_billing_*` keys (CIF, Reg. Com., CNP) are not CS-Cart's: run the
 * order through BillingExtrasResolver first, which fills them from the
 * store's custom profile fields. InvoiceIssuer does.
 *
 * Rules of thumb:
 *
 *   - PJ (company) when the order carries a company name (`fgo_billing_company`,
 *     `b_company` or CS-Cart's `company`, first non-blank) or a CIF
 *     (`fgo_billing_cui`). Otherwise PF. `fgo_billing_tip` (1 = PJ, 2 = PF)
 *     overrides both.
 *   - The CIF is compacted ("RO 123 456 78" -> "RO12345678") and counts only
 *     when it holds a digit 1-9: what customers type into a required CUI
 *     field when they have none ("N/A", "nu e cazul", "0", "RO") is no CIF,
 *     and must neither make them a company nor reach FGO as CodUnic.
 *   - An `RO` prefix is stripped and marks the customer `PlatitorTVA=true`;
 *     leading zeros are dropped from Romanian ids only (a Belgian 0123456789
 *     is a different number without its zero). Any other EU VAT prefix
 *     (EU_VAT_PREFIXES) also marks `PlatitorTVA=true`: a VIES VAT id means a
 *     VAT-registered company. It is sent as typed, prefix included.
 *   - A company with a CIF is `Client[IdExtern]` = the CIF's digits (as the
 *     reference WooCommerce plugin does), so guest checkouts — all user_id 0 —
 *     do not share one client record in FGO. Individuals keep their user_id.
 *   - PF customers send their CNP (`fgo_billing_cnp`) as `CodUnic`.
 *   - Foreign customers (`b_country !== 'RO'`) carry `Strain=true`.
 *   - `Valuta` is the store's primary currency: CS-Cart keeps every order
 *     amount in it, whatever currency the shopper was browsing in.
 *   - VAT per line is computed from `subtotal` vs `subtotal_tax` and
 *     snapped to {0,5,9,11,21}. Discounts ride as a single negative-qty
 *     line; shipping rides as a service line with VAT decided by the
 *     `shipping_tax_vat` setting (vat_included / vat_not_included / vat_zero).
 *
 * The `RequestId` is a deterministic UUIDv5 of `('fgo_invoicing', order_id)`
 * so retries do not change it (FGO uses it for server-side dedup).
 */
final class BillingMapper
{
    /**
     * VAT number prefixes of the EU member states (VIES). Greece's official
     * prefix is EL; GR is what customers type, so it counts too. XI is
     * Northern Ireland, which keeps EU VAT numbers for goods. RO is handled
     * separately (its prefix is stripped).
     */
    private const EU_VAT_PREFIXES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'GR', 'HR',
        'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'SE', 'SI', 'SK', 'XI',
    ];

    /**
     * $primaryCurrency is the store's primary currency code. Null reads
     * ConfigProvider::primaryCurrency() at mapping time; tests pin it here.
     */
    public function __construct(
        private readonly ?string $primaryCurrency = null,
    ) {
    }

    /**
     * @param array<string, mixed> $orderInfo
     */
    public function mapOrderInfo(array $orderInfo): IssueInvoiceRequest
    {
        $orderId = TypeCoerce::toInt($orderInfo['order_id'] ?? 0);
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('order_info.order_id must be a positive integer');
        }

        $client = $this->buildClient($orderInfo);

        $continut = $this->buildProductLines($orderInfo);
        $discount = $this->buildDiscountLine($orderInfo);
        if ($discount !== null) {
            $continut[] = $discount;
        }
        $shipping = $this->buildShippingLine($orderInfo);
        if ($shipping !== null) {
            $continut[] = $shipping;
        }

        if ($continut === []) {
            // Defensive: an order with no products and no shipping/discount
            // would produce an empty Continut and FGO would reject it. Emit
            // a $0 placeholder so the call still records a receipt.
            $continut[] = new InvoiceLine(
                denumire: 'Comanda #' . $orderId,
                nrProduse: 1.0,
                cotaTva: new VatRate(0),
                pretTotal: 0.0,
            );
        }

        return new IssueInvoiceRequest(
            client:    $client,
            continut:  $continut,
            valuta:    $this->resolveCurrency(),
            tipFactura:ConfigProvider::invoiceType(),
            idExtern:  $orderId,
            requestId: $this->buildRequestId($orderId),
            verificareDuplicat: ConfigProvider::verifyDuplicate(),
            valideazaCodUnicRo: ConfigProvider::sanitizeVat(),
            serie:     ConfigProvider::invoiceSeries() !== '' ? ConfigProvider::invoiceSeries() : null,
            explicatii:$this->buildExplicatii($orderInfo, $orderId),
            text:      $this->resolveOrderNote($orderInfo),
        );
    }

    /**
     * @param array<string, mixed> $o
     */
    private function buildClient(array $o): BillingParty
    {
        // First NON-BLANK, not `??`: fn_get_order_info() sets every column the
        // add-on added to ?:user_profiles (fgo_billing_company among them) to
        // '' on every order, so a `??` chain stopped at that blank and never
        // reached CS-Cart's own `company`.
        $company = self::firstFilled($o, 'fgo_billing_company', 'b_company', 'company');
        $first = trim(TypeCoerce::toString($o['b_firstname'] ?? $o['firstname'] ?? ''));
        $last = trim(TypeCoerce::toString($o['b_lastname'] ?? $o['lastname'] ?? ''));
        $personName = trim($first . ' ' . $last);

        $cifRaw = self::usableId(RomanianTaxId::compact(TypeCoerce::toString($o['fgo_billing_cui'] ?? '')));
        $cnp = self::usableId(RomanianTaxId::compact(TypeCoerce::toString($o['fgo_billing_cnp'] ?? '')));
        $regComStr = trim(TypeCoerce::toString($o['fgo_billing_reg'] ?? ''));
        $regCom = $regComStr !== '' ? $regComStr : null;
        $tipExplicit = isset($o['fgo_billing_tip']) ? TypeCoerce::toInt($o['fgo_billing_tip']) : 0;

        $isCompany = ($tipExplicit === 1) || ($tipExplicit !== 2 && ($company !== '' || $cifRaw !== ''));

        $denumire = $isCompany
            ? ($company !== '' ? $company : ($personName !== '' ? $personName : 'Client'))
            : ($personName !== '' ? $personName : 'Client');

        $country = strtoupper(TypeCoerce::toString($o['b_country'] ?? 'RO'));
        $strain = $country !== '' && $country !== 'RO';

        [$cif, $platitorTva] = $this->normalizeCif($cifRaw, !$strain);
        $email = trim(TypeCoerce::toString($o['email'] ?? ''));
        $phone = trim(TypeCoerce::toString($o['phone'] ?? ''));
        $county = trim(TypeCoerce::toString($o['b_state'] ?? ''));
        $city = trim(TypeCoerce::toString($o['b_city'] ?? ''));
        $address = trim(TypeCoerce::toString($o['b_address'] ?? ''));
        $address2 = trim(TypeCoerce::toString($o['b_address_2'] ?? ''));
        if ($address2 !== '') {
            $address = $address === '' ? $address2 : $address . ', ' . $address2;
        }
        $idExtern = TypeCoerce::toInt($o['user_id'] ?? 0);
        if ($isCompany && $cif !== '') {
            $idFromCif = self::clientIdFromCif($cif);
            if ($idFromCif > 0) {
                $idExtern = $idFromCif;
            }
        }

        return new BillingParty(
            denumire:   $denumire,
            tip:        $isCompany ? Constants::TIP_COMPANY : Constants::TIP_PERSON,
            idExtern:   $idExtern,
            email:      $email,
            telefon:    $phone,
            tara:       $country,
            judet:      $county,
            localitate: $city,
            adresa:     $address,
            strain:     $strain,
            // PF: the CNP; failing that, whatever the legacy CUI column held
            // for an explicit PF (it doubled as the CNP slot).
            codUnic:    $isCompany
                ? ($cif !== '' ? $cif : null)
                : ($cnp !== '' ? $cnp : ($cifRaw !== '' ? $cifRaw : null)),
            nrRegCom:   $isCompany ? $regCom : null,
            platitorTva:$isCompany && $platitorTva,
        );
    }

    /**
     * @param array<string, mixed> $o
     */
    private static function firstFilled(array $o, string ...$keys): string
    {
        foreach ($keys as $key) {
            $value = trim(TypeCoerce::toString($o[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * An identifier only when it holds a digit 1-9; '' otherwise.
     *
     * @param string $compacted already compacted (RomanianTaxId::compact)
     */
    private static function usableId(string $compacted): string
    {
        return preg_match('/[1-9]/', $compacted) === 1 ? $compacted : '';
    }

    /**
     * Strip a leading "RO" from a CIF and report whether the id marks a
     * VAT payer: an RO prefix, or any other EU VAT prefix (kept, the id is
     * sent as typed). Leading zeros are dropped only from a Romanian id (RO
     * prefix, or a customer in Romania): elsewhere a leading zero is part of
     * the number.
     *
     * @param string $cifRaw already compacted and usable (usableId)
     *
     * @return array{0: string, 1: bool} [cleanedCif, isVatPayer]
     */
    private function normalizeCif(string $cifRaw, bool $romanianCustomer): array
    {
        if ($cifRaw === '') {
            return ['', false];
        }
        if (str_starts_with($cifRaw, 'RO')) {
            return [ltrim(substr($cifRaw, 2), '0'), true];
        }
        if (self::hasEuVatPrefix($cifRaw)) {
            return [$cifRaw, true];
        }
        return [$romanianCustomer ? ltrim($cifRaw, '0') : $cifRaw, false];
    }

    /**
     * True for an EU VAT number: a member-state prefix followed by a body
     * that holds a digit ("DE123456789", "ATU12345678", "NL123456789B01").
     */
    private static function hasEuVatPrefix(string $id): bool
    {
        return strlen($id) > 2
            && in_array(substr($id, 0, 2), self::EU_VAT_PREFIXES, true)
            && preg_match('/\d/', substr($id, 2)) === 1;
    }

    /**
     * Client[IdExtern] for a company, from its CIF — the reference
     * WooCommerce plugin's rule: drop a two-letter country prefix, keep the
     * digits, drop leading zeros, keep the last 9 digits of anything longer
     * than 10, then FgoSigner::normalizeCustomerId() (int32). 0 when no digit
     * is left, and the caller then keeps the user_id.
     *
     * @param string $cif normalised (normalizeCif)
     */
    private static function clientIdFromCif(string $cif): int
    {
        $body = preg_match('/^[A-Z]{2}/', $cif) === 1 ? substr($cif, 2) : $cif;
        $digits = ltrim((string) preg_replace('/\D/', '', $body), '0');
        if ($digits === '') {
            return 0;
        }
        if (strlen($digits) > 10) {
            $digits = substr($digits, -9);
        }

        return FgoSigner::normalizeCustomerId($digits);
    }

    /**
     * @param array<string, mixed> $o
     * @return InvoiceLine[]
     */
    private function buildProductLines(array $o): array
    {
        $products = $o['products'] ?? [];
        if (!is_array($products)) {
            return [];
        }

        $articleField = ConfigProvider::articleIdField();
        $codGestiune = ConfigProvider::administrationCode() !== '' ? ConfigProvider::administrationCode() : null;
        $appendDesc = ConfigProvider::productDescription();

        $lines = [];
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            /** @var array<string, mixed> $p */
            $qty = TypeCoerce::toFloat($p['amount'] ?? 0);
            if ($qty === 0.0) {
                continue;
            }
            $subtotal = TypeCoerce::toFloat($p['subtotal'] ?? TypeCoerce::toFloat($p['price'] ?? 0) * $qty);
            $subtotalTax = TypeCoerce::toFloat($p['tax_value'] ?? 0);
            $gross = $subtotal + $subtotalTax;
            $vat = VatRate::fromSubtotalAndTax($subtotal, $subtotalTax);

            $name = trim(TypeCoerce::toString($p['product'] ?? $p['product_code'] ?? 'Produs'));
            $name = self::depositLabel(strip_tags($name), $p);
            $code = $this->resolveArticleCode($p, $articleField);
            $sku = TypeCoerce::toString($p['product_code'] ?? '');
            $descr = $appendDesc && $sku !== '' ? 'SKU: ' . $sku : null;

            $lines[] = new InvoiceLine(
                denumire:   $name !== '' ? $name : 'Produs',
                nrProduse:  $qty,
                cotaTva:    $vat,
                um:         Constants::UM_PIECE,
                codArticol: $code,
                pretTotal:  round($gross, 2),
                codGestiune:$codGestiune,
                descriere:  $descr,
            );
        }
        return $lines;
    }

    /**
     * Travel bookings paid with a deposit (travel_core DepositCartLine /
     * BalanceService): the deposit order invoices an advance, the balance
     * order the rest — say so on the line, so neither reads as the whole stay.
     *
     * @param array<string, mixed> $p
     */
    private static function depositLabel(string $name, array $p): string
    {
        $extra = is_array($p['extra'] ?? null) ? $p['extra'] : [];
        if (!empty($extra['travel_balance_id'])) {
            $parent = TypeCoerce::toInt($extra['parent_order_id'] ?? 0);

            return 'Rest de plată rezervare: ' . $name . ($parent > 0 ? ' (comanda #' . $parent . ')' : '');
        }
        $deposit = $extra['travel_deposit'] ?? null;
        if (is_array($deposit) && TypeCoerce::toFloat($deposit['deposit'] ?? 0) > 0) {
            return 'Avans rezervare: ' . $name;
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $p
     */
    private function resolveArticleCode(array $p, string $field): ?string
    {
        if ($field === 'none') {
            return null;
        }
        $key = match ($field) {
            'ean13' => 'ean_13',
            'isbn' => 'isbn',
            'upc' => 'upc',
            default => 'product_code',
        };
        $val = trim(TypeCoerce::toString($p[$key] ?? ''));
        return $val !== '' ? $val : null;
    }

    /**
     * @param array<string, mixed> $o
     */
    private function buildDiscountLine(array $o): ?InvoiceLine
    {
        $discount = TypeCoerce::toFloat($o['subtotal_discount'] ?? 0);
        $couponDiscount = TypeCoerce::toFloat($o['coupons_discount'] ?? 0);
        $total = $discount + $couponDiscount;
        if ($total <= 0.0) {
            return null;
        }

        // Use the snap-from-products VAT (or 21 fallback).
        $vat = $this->dominantProductVat($o);

        return InvoiceLine::discount(
            description: 'Reducere',
            amount:      round($total, 2),
            articleCode: ConfigProvider::discountCode() !== '' ? ConfigProvider::discountCode() : null,
            vat:         $vat,
        );
    }

    /**
     * @param array<string, mixed> $o
     */
    private function buildShippingLine(array $o): ?InvoiceLine
    {
        $shippingCost = TypeCoerce::toFloat($o['shipping_cost'] ?? 0);
        if ($shippingCost <= 0.0) {
            return null;
        }

        $mode = ConfigProvider::shippingTaxVat();
        $vat = match ($mode) {
            'vat_zero' => new VatRate(0),
            default => $this->dominantProductVat($o),
        };

        $usePretUnitar = $mode === 'vat_not_included';

        return InvoiceLine::shipping(
            description: 'Cost transport',
            amount:      $shippingCost,
            articleCode: ConfigProvider::shippingCode() !== '' ? ConfigProvider::shippingCode() : null,
            vat:         $vat,
            usePretUnitar: $usePretUnitar,
        );
    }

    /**
     * @param array<string, mixed> $o
     */
    private function dominantProductVat(array $o): VatRate
    {
        $subtotal = TypeCoerce::toFloat($o['subtotal'] ?? 0);
        $tax = TypeCoerce::toFloat($o['subtotal_tax_amount'] ?? $o['tax_subtotal'] ?? 0);
        if ($subtotal > 0.0 && $tax > 0.0) {
            return VatRate::fromSubtotalAndTax($subtotal, $tax);
        }
        // Walk products and pick the most common rate.
        $rates = [];
        $products = is_array($o['products'] ?? null) ? $o['products'] : [];
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            $s = TypeCoerce::toFloat($p['subtotal'] ?? 0);
            $t = TypeCoerce::toFloat($p['tax_value'] ?? 0);
            $rates[] = VatRate::fromSubtotalAndTax($s, $t)->percent;
        }
        if ($rates === []) {
            return new VatRate(0);
        }
        $counts = array_count_values(array_map('strval', $rates));
        arsort($counts);
        $dominant = (int) array_key_first($counts);
        return new VatRate($dominant);
    }

    /**
     * The store's primary currency, always.
     *
     * NOT $order_info['secondary_currency']: that is the currency the shopper
     * was BROWSING in (CS-Cart saves it in ?:order_data for display), while
     * every amount of the order is stored in the primary currency. Reading it
     * labelled RON amounts as EUR whenever the customer had switched the
     * storefront to euro. Core sets no 'currency' key at all.
     */
    private function resolveCurrency(): string
    {
        $cur = strtoupper(trim($this->primaryCurrency ?? ConfigProvider::primaryCurrency()));

        return $cur !== '' ? $cur : 'RON';
    }

    /**
     * @param array<string, mixed> $o
     */
    private function buildExplicatii(array $o, int $orderId): ?string
    {
        $parts = [];
        if (ConfigProvider::additionalInfo()) {
            $parts[] = 'Comanda nr. ' . $orderId;
        }
        $paymentMethod = $o['payment_method'] ?? null;
        $paymentRaw = is_array($paymentMethod) ? ($paymentMethod['payment'] ?? null) : null;
        $payment = trim(TypeCoerce::toString($paymentRaw ?? $o['payment_method_name'] ?? ''));
        if ($payment !== '') {
            $parts[] = 'Modalitate plata: ' . $payment;
        }
        $shippingArr = $o['shipping'] ?? null;
        $shippingFirst = is_array($shippingArr) && isset($shippingArr[0]) && is_array($shippingArr[0])
            ? ($shippingArr[0]['shipping'] ?? null)
            : null;
        $shippingMethod = trim(TypeCoerce::toString($shippingFirst ?? $o['shipping_method'] ?? ''));
        if ($shippingMethod !== '') {
            $parts[] = 'Modalitate livrare: ' . $shippingMethod;
        }
        $joined = implode(' | ', $parts);
        return $joined !== '' ? $joined : null;
    }

    /**
     * @param array<string, mixed> $o
     */
    private function resolveOrderNote(array $o): ?string
    {
        $note = trim(TypeCoerce::toString($o['notes'] ?? ''));
        return $note !== '' ? $note : null;
    }

    private function buildRequestId(int $orderId): string
    {
        // Deterministic UUIDv5 (RFC 4122 §4.3) over the namespace ('fgo_invoicing')
        // and the order id, so retries reuse the same RequestId.
        $namespace = sha1('fgo_invoicing');
        $hash = sha1($namespace . (string) $orderId);
        return sprintf(
            '%s-%s-5%s-%s%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            dechex((hexdec(substr($hash, 16, 2)) & 0x3F) | 0x80),
            substr($hash, 18, 2),
            substr($hash, 20, 12),
        );
    }
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services;

use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Dto\Billing\BillingParty;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\InvoiceLine;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\IssueInvoiceRequest;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\VatRate;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;

/**
 * Translates a CS-Cart `$order_info` array (the standard structure returned
 * by `fn_get_order_info`) into an `IssueInvoiceRequest`.
 *
 * Rules of thumb:
 *
 *   - PJ (company) when the order's billing block carries either a
 *     `company` name or an `fgo_billing_cui` (CIF). Otherwise PF.
 *   - `RO`-prefixed CIFs are stripped of the prefix and the customer is
 *     marked `PlatitorTVA=true`.
 *   - Foreign customers (`b_country !== 'RO'`) carry `Strain=true`.
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
    public function __construct()
    {
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
            valuta:    $this->resolveCurrency($orderInfo),
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
        // Prefer explicit fgo_billing_company, fall back to b_company / company.
        $company = trim(TypeCoerce::toString($o['fgo_billing_company'] ?? $o['b_company'] ?? $o['company'] ?? ''));
        $first = trim(TypeCoerce::toString($o['b_firstname'] ?? $o['firstname'] ?? ''));
        $last = trim(TypeCoerce::toString($o['b_lastname'] ?? $o['lastname'] ?? ''));
        $personName = trim($first . ' ' . $last);

        $cifRaw = trim(TypeCoerce::toString($o['fgo_billing_cui'] ?? ''));
        $regComStr = trim(TypeCoerce::toString($o['fgo_billing_reg'] ?? ''));
        $regCom = $regComStr !== '' ? $regComStr : null;
        $tipExplicit = isset($o['fgo_billing_tip']) ? TypeCoerce::toInt($o['fgo_billing_tip']) : 0;

        $isCompany = ($tipExplicit === 1) || ($tipExplicit !== 2 && ($company !== '' || $cifRaw !== ''));

        $denumire = $isCompany
            ? ($company !== '' ? $company : ($personName !== '' ? $personName : 'Client'))
            : ($personName !== '' ? $personName : 'Client');

        [$cif, $platitorTva] = $this->normalizeCif($cifRaw);

        $country = strtoupper(TypeCoerce::toString($o['b_country'] ?? 'RO'));
        $strain = $country !== '' && $country !== 'RO';
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
            codUnic:    $isCompany ? ($cif !== '' ? $cif : null) : ($cifRaw !== '' ? $cifRaw : null),
            nrRegCom:   $isCompany ? $regCom : null,
            platitorTva:$isCompany && $platitorTva,
        );
    }

    /**
     * Strip a leading "RO" from a CIF and report whether it was present
     * (meaning the customer is a VAT-payer).
     *
     * @return array{0: string, 1: bool} [cleanedCif, hadRoPrefix]
     */
    private function normalizeCif(string $cifRaw): array
    {
        $upper = strtoupper(trim($cifRaw));
        if ($upper === '') {
            return ['', false];
        }
        if (str_starts_with($upper, 'RO')) {
            return [ltrim(substr($upper, 2), '0'), true];
        }
        return [ltrim($upper, '0'), false];
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
     * @param array<string, mixed> $o
     */
    private function resolveCurrency(array $o): string
    {
        $cur = TypeCoerce::toString($o['secondary_currency'] ?? $o['currency'] ?? 'RON');
        return strtoupper($cur !== '' ? $cur : 'RON');
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

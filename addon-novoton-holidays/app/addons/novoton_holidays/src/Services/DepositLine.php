<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\ViewModels\NovotonBookingSidebarBuilder;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DepositCartLine;
use Tygh\Addons\TravelCore\Services\DepositPlan;
use Tygh\Addons\TravelCore\Services\DepositPolicy;

/**
 * add_to_cart's "Pay a deposit" step (travel_core DepositPolicy).
 *
 * The booking form posts only the choice (pay_mode=deposit); the deposit is
 * worked out here from the room quote's live <TermsOfPayment>, so the amount
 * charged can never come from the request. Terms that no longer split the
 * price (or a balance now within 7 days) keep the full price and tell the
 * guest why.
 */
final class DepositLine
{
    /**
     * @param array<string, mixed> $row cart row at the full price (primary currency)
     * @param array<string, mixed> $request the posted booking form
     * @return array<string, mixed>
     */
    public static function fromRequest(array $row, string $termsOfPaymentXml, array $request, ?DepositPolicy $policy = null, ?string $today = null): array
    {
        $policy ??= DepositPolicy::current();
        if (!$policy->offers('novoton_holidays')
            || TypeCoerce::toString($request[DepositPolicy::FIELD] ?? '') !== DepositPolicy::MODE_DEPOSIT) {
            return $row;
        }

        $payment = $termsOfPaymentXml !== '' ? TermsFormatter::parsePaymentTerms($termsOfPaymentXml) : [];
        [, $installments] = NovotonBookingSidebarBuilder::terms([], $payment);
        $plan = $policy->chosen('novoton_holidays', $request, DepositPlan::fromInstallments($installments, $today ?? date('Y-m-d')));
        if ($plan === null) {
            if (function_exists('fn_set_notification')) {
                fn_set_notification('W', __('notice'), __('travel_core.deposit_unavailable'));
            }

            return $row;
        }

        return DepositCartLine::apply($row, $plan);
    }
}

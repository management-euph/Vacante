<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * Leaves what offers_update named on the rows, for the runs that follow.
 *
 * Novoton asked (28 Oct 2026) to run offers_update every 12 hours and then
 * call priceinfo only for what it returned. Each <Offer> names one package
 * of one hotel (IdHotel, PackageName):
 *  - a package we know gets price_changed_at, and the next priceinfo run
 *    asks for its prices again (BatchedPriceInfoSyncV2);
 *  - a package we do not know yet marks its hotel (hotelinfo_due_at): the
 *    next hotelinfo run lists the hotel's packages, the new one among them,
 *    and priceinfo then asks for it as a new package.
 *
 * The mark is the time the question was asked: every change it reports
 * happened before then, so a package fetched after that time already has it.
 */
final class OfferChangeMarker
{
    public const string PACKAGE = 'package';

    public const string HOTEL = 'hotel';

    /**
     * @param string $askedAt when offers_update was asked (Y-m-d H:i:s)
     * @return self::PACKAGE|self::HOTEL what was marked
     */
    public function mark(string $hotelId, string $packageName, string $askedAt): string
    {
        $packageName = trim($packageName);
        if ($packageName !== '') {
            $known = db_get_field(
                'SELECT package_id FROM ?:novoton_hotel_packages WHERE hotel_id = ?s AND package_name = ?s LIMIT 1',
                $hotelId,
                $packageName,
            );
            if (is_scalar($known) && (string) $known !== '') {
                db_query(
                    'UPDATE ?:novoton_hotel_packages SET price_changed_at = ?s WHERE hotel_id = ?s AND package_name = ?s',
                    $askedAt,
                    $hotelId,
                    $packageName,
                );

                return self::PACKAGE;
            }
        }

        db_query('UPDATE ?:novoton_hotels SET hotelinfo_due_at = ?s WHERE hotel_id = ?s', $askedAt, $hotelId);

        return self::HOTEL;
    }
}

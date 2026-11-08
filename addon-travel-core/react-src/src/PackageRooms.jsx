/**
 * Travel Booking Engine - "Camere și prețuri" in a package mode (board 16.2)
 *
 * Under the package bar on a hotel's page, once the shopper asks for the
 * price: the hotel's offers for the chosen departure and group. The
 * transport is the same for every room, so it stands once above them (Dus,
 * Întors, what is included); a second block only when another flight leaves
 * that day. Then the room types, each with its meal plans cheapest first:
 * the total for the group, ≈ per person, the confirmation, and "Rezervă",
 * which opens the package booking form on that very offer.
 *
 * The logic without React lives in packageModes.js.
 */

import { MealIcon, InfoIcon, PlaneIcon, BusIcon } from './icons';
import { t, tPlural } from './utils';
import { Leg } from './CircuitRooms';
import { chipDate, formatPrice, partySummary } from './circuitFormat';
import { roomsLabel, perPerson } from './circuitRooms';
import { roomTypes } from './packageModes';

/** "7 nopți" / "7 nights". */
function nightsText(nights) {
    return tPlural(nights, 'circuitNightsOne', 'circuitNightsFew', 'circuitNightsMany', '[n] night', '[n] nights', '[n] nights').replace('[n]', nights);
}

/** "direct" / "cu escală", after the airline. */
function stopsText(leg) {
    if (!leg) return '';
    return leg.stops > 0 ? t('packageWithStops', 'with a stop') : t('packageDirect', 'direct');
}

export default function PackageRooms({ quote, bookHref }) {
    const { status, data, choice } = quote;
    const blocks = status === 'ready' && data ? data.blocks : [];
    const persons = data ? (data.adults || 0) + (data.children || 0) : 0;
    const nights = choice ? choice.nights : 0;

    let state = '';
    if (status === 'loading') state = t('packageLoadingRooms', 'Looking for the prices of this departure… it can take up to half a minute.');
    if (status === 'error') state = t('circuitRoomsError', 'The rooms cannot be loaded right now. Please try again.');
    if (status === 'ages') state = t('circuitRoomsAges', "Choose each child's age to see the price.");
    if (status === 'ready' && blocks.length === 0) state = t('circuitNoRooms', 'No rooms left for this departure. Choose another date.');

    return (
        <section className="travel-circuit-rooms travel-package-rooms" aria-labelledby="travel-package-rooms-title" aria-busy={status === 'loading'}>
            <h2 className="travel-circuit-rooms__title" id="travel-package-rooms-title">
                {t('packageRoomsTitle', 'Rooms and prices')}
                {status === 'ready' && choice && data && blocks.length > 0 && (
                    <span className="travel-circuit-rooms__meta">
                        {[
                            chipDate(choice.date),
                            nightsText(nights),
                            partySummary(data.adults || 0, data.children || 0, (choice.rooms || []).length),
                            blocks.length === 1 ? t('packageSameTransport', 'the transport is the same for every room') : '',
                        ].filter(Boolean).join(' · ')}
                    </span>
                )}
            </h2>

            <div aria-live="polite">
                {state && <p className="travel-circuit-rooms__state">{state}</p>}

                {blocks.length > 1 && (
                    <p className="travel-package-rooms__choice">
                        {t('packageFlightChoice', '[count] flights leave that day: choose the room under the flight you want.').replace('[count]', blocks.length)}
                    </p>
                )}

                {blocks.map((block, b) => {
                    const flight = block.transport === 'flight';
                    const included = flight
                        ? t('packageIncludedFlight', 'Included: the return flight · [nights] at the hotel')
                        : t('packageIncludedBus', 'Included: a coach seat, return · [nights] at the hotel');
                    return (
                        <div className="travel-circuit-block travel-package-block" key={block.offers[0].offer_id}>
                            <div className="travel-package-transport">
                                {block.flight && (
                                    <dl className="travel-circuit-flight">
                                        {block.flight.outbound && (
                                            <Leg label={t('circuitOutbound', 'Outbound')} leg={{ ...block.flight.outbound, airline: [block.flight.outbound.airline, stopsText(block.flight.outbound)].filter(Boolean).join(' · ') }} />
                                        )}
                                        {block.flight.inbound && (
                                            <Leg label={t('circuitInbound', 'Return')} leg={{ ...block.flight.inbound, airline: [block.flight.inbound.airline, stopsText(block.flight.inbound)].filter(Boolean).join(' · ') }} />
                                        )}
                                    </dl>
                                )}
                                <p className="travel-package-transport__included">
                                    {flight ? <PlaneIcon /> : <BusIcon />}
                                    <span>{included.replace('[nights]', nightsText(nights))}</span>
                                </p>
                            </div>

                            {roomTypes(block.offers).map((type, r) => (
                                <div className="travel-package-room" key={type.options[0].offer_id}>
                                    <p className="travel-package-room__name">{roomsLabel(type.rooms)}</p>
                                    <ul className="travel-circuit-offers__list">
                                        {type.options.map((offer, o) => {
                                            const first = b === 0 && r === 0 && o === 0;
                                            const each = perPerson(offer.price, persons);
                                            return (
                                                <li className="travel-circuit-offer" key={offer.offer_id}>
                                                    <div className="travel-circuit-offer__main">
                                                        <p className="travel-circuit-offer__name">
                                                            <MealIcon />
                                                            <span>{offer.meal}</span>
                                                        </p>
                                                        {offer.confirmation && (
                                                            <p className={`travel-package-offer__confirm${offer.confirmation === 'immediate' ? ' travel-package-offer__confirm--now' : ''}`}>
                                                                {offer.confirmation === 'immediate'
                                                                    ? t('pdpaRImmediate', 'Instant confirmation')
                                                                    : t('pdpaROnRequestTag', 'On request')}
                                                            </p>
                                                        )}
                                                        {first && (
                                                            <p className="travel-circuit-offer__note">
                                                                <InfoIcon />
                                                                <span>{t('circuitTermsAtBooking', 'You see the cancellation and payment terms when you book.')}</span>
                                                            </p>
                                                        )}
                                                    </div>
                                                    <p className="travel-circuit-offer__price">
                                                        <strong className="travel-circuit-offer__total">{formatPrice(offer.price, offer.currency)}</strong>
                                                        {persons === 1 && <span className="travel-circuit-offer__each">{t('circuitForOneAdult', 'for 1 adult')}</span>}
                                                        {each > 0 && (
                                                            <span className="travel-circuit-offer__each">
                                                                {t('circuitPerPerson', '≈ [price] / person').replace('[price]', formatPrice(each, offer.currency))}
                                                            </span>
                                                        )}
                                                    </p>
                                                    <a
                                                        className={`travel-circuit-offer__book${first ? ' travel-circuit-offer__book--primary' : ''}`}
                                                        href={bookHref(offer)}
                                                    >
                                                        {t('circuitBook', 'Book')}
                                                    </a>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    );
                })}
            </div>
        </section>
    );
}

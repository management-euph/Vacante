/**
 * Travel Booking Engine - "Camera pentru tot circuitul" (board 16.3)
 *
 * Under the circuit bar, once the shopper asks for the price: the rooms of
 * the chosen departure for their group. Each block is one flight (Dus,
 * Întors), the hotels on the route (their stars, the nights, the meal
 * plan), then its rooms, cheapest first: each with the total for the
 * group, ≈ per person, and "Rezervă", which opens the booking form on that
 * very offer. One room is booked for the whole route.
 *
 * The text without React lives in circuitRooms.js.
 */

import { MealIcon, InfoIcon } from './icons';
import { t, tPlural } from './utils';
import { chipDate, formatPrice, partySummary } from './circuitFormat';
import { roomsLabel, starsText, commonMeal, perPerson, place, daysLater } from './circuitRooms';

/** "lun. 5 iul. · 10:40 Bucharest … (OTP) → 12:25 Heraklion … (HER)" */
function Leg({ label, leg }) {
    const later = daysLater(leg.departure_date, leg.arrival_date);
    return (
        <div className="travel-circuit-flight__row">
            <dt className="travel-circuit-flight__way">{label}</dt>
            <dd className="travel-circuit-flight__route">
                {`${chipDate(leg.departure_date)} · `}
                {leg.departure_time && `${leg.departure_time} `}
                {place(leg.from, leg.from_code)}
                {' → '}
                {leg.arrival_time && `${leg.arrival_time} `}
                {place(leg.to, leg.to_code)}
                {later > 0 && <sup className="travel-circuit-flight__later">{`+${later}`}</sup>}
            </dd>
            {leg.airline && <dd className="travel-circuit-flight__airline">{leg.airline}</dd>}
        </div>
    );
}

export default function CircuitRooms({ quote, hotelStars, bookHref }) {
    const { status, data, choice } = quote;
    const blocks = status === 'ready' && data ? data.blocks : [];
    const persons = data ? (data.adults || 0) + (data.children || 0) : 0;
    const stars = starsText(hotelStars);

    let state = '';
    if (status === 'loading') state = t('circuitLoadingRooms', 'Looking for the rooms…');
    if (status === 'error') state = t('circuitRoomsError', 'The rooms cannot be loaded right now. Please try again.');
    if (status === 'ages') state = t('circuitRoomsAges', "Choose each child's age to see the price.");
    if (status === 'ready' && blocks.length === 0) state = t('circuitNoRooms', 'No rooms left for this departure. Choose another date.');

    return (
        <section className="travel-circuit-rooms" aria-labelledby="travel-circuit-rooms-title" aria-busy={status === 'loading'}>
            <h2 className="travel-circuit-rooms__title" id="travel-circuit-rooms-title">
                {t('circuitRoomsTitle', 'Room for the whole circuit')}
                {status === 'ready' && choice && data && (
                    <span className="travel-circuit-rooms__meta">
                        {`${t('circuitRoomsDeparture', 'departure [date]').replace('[date]', chipDate(choice.date))} · ${partySummary(data.adults || 0, data.children || 0, (choice.rooms || []).length)}`}
                    </span>
                )}
            </h2>

            <div aria-live="polite">
                {state && <p className="travel-circuit-rooms__state">{state}</p>}

                {blocks.map((block, b) => {
                    const meal = commonMeal(block.offers);
                    const nights = block.nights > 0
                        ? tPlural(block.nights, 'circuitNightsOne', 'circuitNightsFew', 'circuitNightsMany', '[n] night', '[n] nights', '[n] nights').replace('[n]', block.nights)
                        : '';
                    return (
                        <div className="travel-circuit-block" key={block.offers[0].offer_id}>
                            {block.flight && (
                                <dl className="travel-circuit-flight">
                                    {block.flight.outbound && <Leg label={t('circuitOutbound', 'Outbound')} leg={block.flight.outbound} />}
                                    {block.flight.inbound && <Leg label={t('circuitInbound', 'Return')} leg={block.flight.inbound} />}
                                </dl>
                            )}

                            <div className="travel-circuit-offers">
                                <div className="travel-circuit-stay">
                                    <p className="travel-circuit-stay__title">
                                        {stars
                                            ? t('circuitHotelsStars', '[stars]★ hotels on the route').replace('[stars]', stars)
                                            : t('circuitHotels', 'Accommodation on the route')}
                                    </p>
                                    {(nights || meal) && (
                                        <p className="travel-circuit-stay__text">{[nights, meal].filter(Boolean).join(' · ')}</p>
                                    )}
                                </div>

                                <ul className="travel-circuit-offers__list">
                                    {block.offers.map((offer, o) => {
                                        const each = perPerson(offer.price, persons);
                                        return (
                                            <li className="travel-circuit-offer" key={offer.offer_id}>
                                                <div className="travel-circuit-offer__main">
                                                    <p className="travel-circuit-offer__name">
                                                        <MealIcon />
                                                        <span>{[roomsLabel(offer.rooms), offer.meal].filter(Boolean).join(' · ')}</span>
                                                    </p>
                                                    {/* The booking form shows the terms (board 16.3: once, on the first room). */}
                                                    {b === 0 && o === 0 && (
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
                                                    className={`travel-circuit-offer__book${b === 0 && o === 0 ? ' travel-circuit-offer__book--primary' : ''}`}
                                                    href={bookHref(offer)}
                                                >
                                                    {t('circuitBook', 'Book')}
                                                </a>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        </div>
                    );
                })}
            </div>
        </section>
    );
}

<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Contracts\BookingAdminProviderInterface;
use Tygh\Addons\TravelCore\Contracts\CronDispatcherInterface;
use Tygh\Addons\TravelCore\Contracts\HotelProductProviderInterface;
use Tygh\Addons\TravelCore\Contracts\ProviderNormalizerInterface;
use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;

/**
 * Registry of active travel providers.
 *
 * Each API addon (novoton_holidays, sphinx_holidays) registers its provider
 * here during init.php. The registry resolves which provider handles a given
 * hotel and provides access to provider normalizers and booking admin providers.
 */
class TravelProviderRegistry
{
    /**
     * Known provider addon names.
     * Used by travel_core to check dependencies at uninstall time.
     */
    public const array KNOWN_PROVIDER_ADDONS = ['novoton_holidays', 'sphinx_holidays', 'eurosite'];

    /** @var array<string, array{name: string, label: string, normalizer: ProviderNormalizerInterface, booking_admin_provider?: BookingAdminProviderInterface, hotel_product_provider?: HotelProductProviderInterface, status_sync_callback?: callable, single_status_callback?: callable, scan_config?: array{table: string, id_col: string, json_col: string}}> */
    private static array $providers = [];

    /** @var array<string, array{name: string, label: string, addon: string, dispatcher: class-string<CronDispatcherInterface>, dashboard: string, anchor: string}> */
    private static array $cron = [];

    /** @var array<string, callable(array<string, mixed>): array<string, mixed>> provider name => cart-line terms resolver */
    private static array $cartTerms = [];

    /** @var array<string, callable(array<string, mixed>): array<string, mixed>> provider name => order-line facts resolver */
    private static array $orderCardFacts = [];

    /**
     * Register a travel provider.
     */
    public static function register(string $name, string $label, ProviderNormalizerInterface $normalizer): void
    {
        self::$providers[$name] = [
            'name' => $name,
            'label' => $label,
            'normalizer' => $normalizer,
        ];
    }

    /**
     * Set the booking admin provider for a registered provider.
     */
    public static function setBookingAdminProvider(string $name, BookingAdminProviderInterface $adminProvider): void
    {
        if (isset(self::$providers[$name])) {
            self::$providers[$name]['booking_admin_provider'] = $adminProvider;
        }
    }

    /**
     * Set callbacks for status sync operations.
     *
     * @param string $name Provider name
     * @param callable|null $bulkCallback Callback for bulk status sync (no args, returns ['checked'=>int,'changed'=>int])
     * @param callable|null $singleCallback Callback for single booking status check (booking_id arg)
     */
    public static function setStatusCallbacks(string $name, ?callable $bulkCallback, ?callable $singleCallback): void
    {
        if (isset(self::$providers[$name])) {
            if ($bulkCallback !== null) {
                self::$providers[$name]['status_sync_callback'] = $bulkCallback;
            }
            if ($singleCallback !== null) {
                self::$providers[$name]['single_status_callback'] = $singleCallback;
            }
        }
    }

    /**
     * Get the BookingAdminProviderInterface for a provider, if registered.
     */
    public static function getBookingAdminProvider(string $name): ?BookingAdminProviderInterface
    {
        return self::$providers[$name]['booking_admin_provider'] ?? null;
    }

    /**
     * Get a provider entry by name.
     *
     * @return array{name: string, label: string, normalizer: ProviderNormalizerInterface, booking_admin_provider?: BookingAdminProviderInterface, hotel_product_provider?: HotelProductProviderInterface, status_sync_callback?: callable, single_status_callback?: callable, scan_config?: array{table: string, id_col: string, json_col: string}}|null
     */
    public static function get(string $name): ?array
    {
        return self::$providers[$name] ?? null;
    }

    /**
     * Get the normalizer for a provider.
     */
    public static function getNormalizer(string $name): ?ProviderNormalizerInterface
    {
        return isset(self::$providers[$name]) ? self::$providers[$name]['normalizer'] : null;
    }

    /**
     * Get all registered providers.
     *
     * @return array<string, array{name: string, label: string, normalizer: ProviderNormalizerInterface, booking_admin_provider?: BookingAdminProviderInterface, hotel_product_provider?: HotelProductProviderInterface, status_sync_callback?: callable, single_status_callback?: callable, scan_config?: array{table: string, id_col: string, json_col: string}}>
     */
    public static function all(): array
    {
        return self::$providers;
    }

    /**
     * Determine which provider handles a given hotel ID.
     *
     * Hotel IDs are prefixed with the provider name (e.g., "novoton_12345", "sphinx_s1-hotel-99").
     *
     * @return array{name: string, label: string, normalizer: ProviderNormalizerInterface}|null
     */
    public static function getProviderForHotel(string $hotelId): ?array
    {
        foreach (self::$providers as $name => $provider) {
            if (str_starts_with($hotelId, $name . '_')) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Register the hotel-product resolver for a provider.
     * Called from each provider addon's init.php alongside register().
     */
    public static function setHotelProductProvider(string $name, HotelProductProviderInterface $provider): void
    {
        if (isset(self::$providers[$name])) {
            self::$providers[$name]['hotel_product_provider'] = $provider;
        }
    }

    /**
     * Get the hotel-product resolver for a provider, if registered.
     */
    public static function getHotelProductProvider(string $name): ?HotelProductProviderInterface
    {
        return self::$providers[$name]['hotel_product_provider'] ?? null;
    }

    /**
     * Register how a provider reads the cancellation & payment terms of its
     * own cart lines, for the cart / checkout booking card
     * (ViewModels\CartBookingCardFactory). The resolver gets the line's extra
     * and returns [] for lines it does not own, else
     * {cancel_windows, payment_rows, cancel_lines, payment_lines}.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $resolver
     */
    public static function setCartTermsResolver(string $name, callable $resolver): void
    {
        if (isset(self::$providers[$name])) {
            self::$cartTerms[$name] = $resolver;
        }
    }

    /**
     * The terms of a cart line from the provider that owns it; [] when no
     * registered provider claims it (eurosite stores none on the line).
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function cartTerms(array $extra): array
    {
        foreach (self::$cartTerms as $name => $resolver) {
            if (!isset(self::$providers[$name])) {
                continue;
            }
            $terms = $resolver($extra);
            if ($terms !== []) {
                return $terms;
            }
        }

        return [];
    }

    /**
     * Register how a provider describes its own booking on an order line, for
     * the order booking card (ViewModels\OrderBookingCardFactory): what only
     * the provider's booking row knows. The resolver gets the line's extra
     * and returns [] for lines it does not own, else
     * {provider_booking_id, reference, our_reference, error, not_sent, note,
     * kind, transport, meals, departure, services} (every key optional).
     *
     * @param callable(array<string, mixed>): array<string, mixed> $resolver
     */
    public static function setOrderCardResolver(string $name, callable $resolver): void
    {
        if (isset(self::$providers[$name])) {
            self::$orderCardFacts[$name] = $resolver;
        }
    }

    /**
     * The provider facts of an order line, with the owning provider's name
     * under 'provider'; [] when no registered provider claims the line.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function orderCardFacts(array $extra): array
    {
        foreach (self::$orderCardFacts as $name => $resolver) {
            if (!isset(self::$providers[$name])) {
                continue;
            }
            $facts = $resolver($extra);
            if ($facts !== []) {
                return ['provider' => $name] + $facts;
            }
        }

        return [];
    }

    /**
     * Iterate all registered providers and return the first HotelSeoData match.
     *
     * Returns null when no active provider claims the product. Because only active
     * addons run init.php, deactivated providers are never registered — their tables
     * are never queried, eliminating "table does not exist" crashes.
     *
     * @param int $productId CS-Cart product_id
     * @param string $productCode CS-Cart product_code (may be empty)
     */
    public static function resolveProductOwner(int $productId, string $productCode): ?HotelSeoData
    {
        foreach (self::$providers as $provider) {
            $impl = $provider['hotel_product_provider'] ?? null;
            if ($impl === null) {
                continue;
            }
            $result = $impl->resolveProduct($productId, $productCode);
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    /**
     * Resolve which registered provider owns a bare (provider-native) hotel id.
     *
     * Returns the provider entry (as get()) or null. Deactivated providers are
     * not registered, so their tables are never queried.
     *
     * @return array{name: string, label: string, normalizer: ProviderNormalizerInterface, booking_admin_provider?: BookingAdminProviderInterface, hotel_product_provider?: HotelProductProviderInterface, status_sync_callback?: callable, single_status_callback?: callable, scan_config?: array{table: string, id_col: string, json_col: string}}|null
     */
    public static function resolveHotelIdOwner(string $hotelId): ?array
    {
        foreach (self::$providers as $provider) {
            $impl = $provider['hotel_product_provider'] ?? null;
            if ($impl === null) {
                continue;
            }
            if ($impl->ownsHotelId($hotelId)) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * Register facility scan configuration for a provider.
     *
     * @param string $name Provider name ('sphinx', 'novoton', etc.)
     * @param string $table Database table containing hotel data
     * @param string $idCol Column name for hotel ID
     * @param string $jsonCol Column name containing facilities JSON array
     */
    public static function setScanConfig(string $name, string $table, string $idCol, string $jsonCol): void
    {
        if (isset(self::$providers[$name])) {
            self::$providers[$name]['scan_config'] = [
                'table' => $table,
                'id_col' => $idCol,
                'json_col' => $jsonCol,
            ];
        }
    }

    /**
     * Get facility scan configuration for a provider.
     *
     * @return array{table: string, id_col: string, json_col: string}|null
     */
    public static function getScanConfig(string $name): ?array
    {
        return self::$providers[$name]['scan_config'] ?? null;
    }

    /**
     * Get all providers that have scan configuration registered.
     *
     * @return array<string, array{table: string, id_col: string, json_col: string}>
     */
    public static function getAllScanConfigs(): array
    {
        $configs = [];
        foreach (self::$providers as $name => $provider) {
            if (!empty($provider['scan_config'])) {
                $configs[$name] = $provider['scan_config'];
            }
        }
        return $configs;
    }

    /**
     * Check if a provider is registered.
     */
    public static function has(string $name): bool
    {
        return isset(self::$providers[$name]);
    }

    /**
     * A provider's cron: the add-on id its runs are logged under, the
     * dispatcher that knows its job types, and the admin page that shows its
     * commands.
     *
     * The PROVIDER supplies this, from its own init.php, so Travel Core's
     * Tools page never hard-codes another add-on's jobs: each provider owns
     * its schedules and options, and a disabled provider never runs init.php,
     * so its row simply disappears. Kept apart from $providers so that array's
     * shape — repeated in four docblocks — stays as it is.
     *
     * Ignored unless register() ran first, like the other set* methods, and
     * unless $dispatcherClass really implements CronDispatcherInterface: the
     * Tools page calls its static getAvailableModes().
     *
     * @param string $addon CS-Cart add-on id ('sphinx_holidays'), which the short
     *                      registry name ('sphinx') does not carry
     * @param string $dispatcherClass class implementing CronDispatcherInterface
     * @param string $dashboard admin dispatch of the page listing its cron commands
     * @param string $anchor element id of the cron section on that page, '' for none
     */
    public static function setCron(string $name, string $addon, string $dispatcherClass, string $dashboard, string $anchor = ''): void
    {
        if (!isset(self::$providers[$name]) || !is_a($dispatcherClass, CronDispatcherInterface::class, true)) {
            return;
        }

        self::$cron[$name] = [
            'name' => $name,
            'label' => self::$providers[$name]['label'],
            'addon' => $addon,
            'dispatcher' => $dispatcherClass,
            'dashboard' => $dashboard,
            'anchor' => $anchor,
        ];
    }

    /**
     * Every registered provider that declared its cron, in registration order.
     *
     * @return array<string, array{name: string, label: string, addon: string, dispatcher: class-string<CronDispatcherInterface>, dashboard: string, anchor: string}>
     */
    public static function getCronProviders(): array
    {
        return array_intersect_key(self::$cron, self::$providers);
    }

    /**
     * Reset registry (for testing).
     */
    public static function reset(): void
    {
        self::$providers = [];
        self::$cron = [];
        self::$cartTerms = [];
        self::$orderCardFacts = [];
    }
}

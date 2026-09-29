<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Support;

/**
 * Records what the code under test sent to CS-Cart's fn_log_event(), which
 * tests/bootstrap.php routes here. Some behaviour is ONLY a log entry (for
 * instance InvoiceIssuer's warning when client_vat_required is on but the
 * store has no CIF field), and a no-op stub could not tell it from silence.
 *
 * Call reset() in setUp()/tearDown(): the state is static and leaks otherwise.
 */
final class LogStub
{
    /** @var list<array{type: string, action: string, data: array<mixed>}> */
    public static array $events = [];

    public static function reset(): void
    {
        self::$events = [];
    }

    /**
     * @param array<mixed> $data
     */
    public static function record(string $type, string $action, array $data): void
    {
        self::$events[] = ['type' => $type, 'action' => $action, 'data' => $data];
    }

    /**
     * The `message` of every recorded event, e.g. '[warn] identity-source-missing'.
     *
     * @return list<string>
     */
    public static function messages(): array
    {
        return array_map(
            static fn (array $event): string => is_string($event['data']['message'] ?? null) ? $event['data']['message'] : '',
            self::$events,
        );
    }
}

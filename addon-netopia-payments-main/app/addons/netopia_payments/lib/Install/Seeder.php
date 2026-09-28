<?php

declare(strict_types=1);

namespace Netopia\CsCart\Install;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Runtime self-heal for addon-owned rows that CS-Cart normally populates
 * during install/reinstall but sometimes skips (file-level addon updates,
 * partial install state).
 *
 *   - `?:language_values` — the payment_info field labels
 *     (`netopia_payment_link`, `netopia_payment_link_at`, `netopia_amount`).
 *     Normally shipped via `var/langs/{en,ro}/addons/netopia_payments.po`
 *     and imported by CS-Cart's .po importer on install. Re-seeded here
 *     when that import didn't run.
 *
 * The `?:template_emails` row for `netopia_payment_retry` is owned
 * exclusively by the `<email_templates>` block in addon.xml — CS-Cart's
 * `\Tygh\Template\Mail\Exim` importer creates it on install. Merchants
 * whose install pre-dates that block must uninstall + reinstall once.
 */
final class Seeder
{
    public const string MARKER_LABEL = 'netopia_payment_link';

    /** @var array<string, array<string, string>> */
    public const array LABELS = [
        'en' => [
            'netopia_payment_link' => 'Payment Link',
            'netopia_payment_link_at' => 'Payment link time',
            'netopia_start_amount' => 'Charged Amount',
            'netopia_amount' => 'Amount',
            'netopia_refunded_amount' => 'Refunded Amount',
            'netopia_refund_log' => 'Refund History',
            'netopia_order_id' => 'Payment ID',
        ],
        'ro' => [
            'netopia_payment_link' => 'Link plată',
            'netopia_payment_link_at' => 'Oră link plată',
            'netopia_start_amount' => 'Sumă plătită',
            'netopia_amount' => 'Sumă',
            'netopia_refunded_amount' => 'Sumă rambursată',
            'netopia_refund_log' => 'Istoric returnări',
            'netopia_order_id' => 'ID plată',
        ],
    ];

    private bool $checkedThisRequest = false;

    /**
     * @param Closure(string, mixed...): mixed             $dbQuery
     * @param Closure(string, mixed...): (string|false)    $dbGetField
     */
    public function __construct(
        private readonly Closure $dbQuery,
        private readonly Closure $dbGetField,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Build a Seeder backed by CS-Cart's global db helpers.
     *
     * Deliberately does NOT go through Bootstrap::instance() — Bootstrap
     * eagerly builds payment URLs via `fn_url()`, which at `init.php`
     * load time (addons are initialised before CS-Cart sets
     * `CART_LANGUAGE`) throws "Undefined constant CART_LANGUAGE" and
     * locks the admin out of the site.
     */
    public static function forRuntime(?LoggerInterface $logger = null): self
    {
        return new self(
            dbQuery:    static fn (string $sql, mixed ...$params): mixed => db_query($sql, ...$params),
            dbGetField: static fn (string $sql, mixed ...$params): string|false => db_get_field($sql, ...$params),
            logger:     $logger ?? new NullLogger(),
        );
    }

    /**
     * Runtime self-heal. Safe to call on every request; short-circuits
     * after the first call within the same PHP process.
     */
    public function ensureSeeded(): void
    {
        if ($this->checkedThisRequest) {
            return;
        }
        $this->checkedThisRequest = true;

        try {
            if (!$this->labelsPresent()) {
                $this->seedLabels();
            }
        } catch (Throwable $e) {
            // A seeding failure must not break the request — log and move on.
            $this->logger->warning('NETOPIA seeder skipped after error: ' . $e->getMessage(), [
                'exception_class' => $e::class,
            ]);
        }
    }

    /**
     * Lifecycle-install path. The `<email_templates>` block in addon.xml
     * is handled by CS-Cart's Exim importer during install; here we only
     * belt-and-braces seed the payment_info labels in case the .po
     * importer didn't run.
     */
    public function seedLabelsOnInstall(): void
    {
        $this->seedLabels();
    }

    private function labelsPresent(): bool
    {
        $row = ($this->dbGetField)(
            'SELECT value FROM ?:language_values WHERE name = ?s LIMIT 1',
            self::MARKER_LABEL,
        );
        return $row !== false && $row !== '';
    }

    private function seedLabels(): void
    {
        $values = [];
        $params = [];
        foreach (self::LABELS as $langCode => $labels) {
            foreach ($labels as $name => $value) {
                $values[] = '(?s, ?s, ?s)';
                $params[] = $langCode;
                $params[] = $name;
                $params[] = $value;
            }
        }

        ($this->dbQuery)(
            'REPLACE INTO ?:language_values (lang_code, name, value) VALUES ' . implode(', ', $values),
            ...$params,
        );
    }
}

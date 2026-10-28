<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Api;

/**
 * Downloads one hotel photo from Novoton's image server, through the
 * store's request gate like every other request to Novoton (at most 5 at
 * once and 5 a second, 28 Oct 2026).
 */
final class ImageDownloader
{
    private const int TIMEOUT = 30;

    /** A larger answer is not taken as a photo. */
    public const int MAX_BYTES = 15 * 1024 * 1024;

    public function __construct(private readonly ?RequestGate $gate = null)
    {
    }

    /** The file at $url; null when it does not come (an error, not found, too large). */
    public function get(string $url): ?string
    {
        $ch = curl_init();
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXFILESIZE => self::MAX_BYTES,
        ]);
        $gate = $this->gate ?? RequestGate::shared();
        $tickets = $gate->acquire();
        try {
            $body = curl_exec($ch);
        } finally {
            $gate->release($tickets);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return is_string($body) && $status === 200 && $body !== '' && strlen($body) <= self::MAX_BYTES ? $body : null;
    }
}

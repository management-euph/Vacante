<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Api;

use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;

/**
 * Helper for Sphinx image URL handling.
 *
 * The Sphinx API provides watermark-free images when authenticated with
 * X-Copyright-Authorization header and watermark=0 query param.
 */
class ImageHelper
{
    /**
     * Last download error message, populated by fn_sphinx_holidays_add_product_image()
     * so cron callers can surface the HTTP code in console output without inspecting
     * the CS-Cart event log. Reset to '' at the start of every download attempt.
     */
    public static string $lastDownloadError = '';

    /**
     * Build an image URL with watermark disabled.
     *
     * The resulting URL still requires the X-Copyright-Authorization header
     * to be sent by the HTTP client — this method only adds the query param.
     */
    public static function withoutWatermark(string $imageUrl): string
    {
        if ($imageUrl === '') {
            return '';
        }
        $separator = str_contains($imageUrl, '?') ? '&' : '?';
        return $imageUrl . $separator . 'watermark=0';
    }

    /**
     * Return true if $imageUrl is hosted on the same server (or a sibling subdomain)
     * as the configured Sphinx API base URL.
     *
     * Sibling subdomain matching handles cases where images are served from
     * e.g. media.sphinx2.example.com while the API is at api.sphinx2.example.com.
     */
    public static function matchesApiHost(string $imageUrl, string $apiBaseUrl): bool
    {
        $apiHost = (string) parse_url($apiBaseUrl, PHP_URL_HOST);
        $imageHost = (string) parse_url($imageUrl, PHP_URL_HOST);
        if ($apiHost === '' || $imageHost === '') {
            return false;
        }
        if ($imageHost === $apiHost) {
            return true;
        }
        // Compare base domain after stripping the first subdomain component.
        $apiBase = implode('.', array_slice(explode('.', $apiHost), 1));
        $imageBase = implode('.', array_slice(explode('.', $imageHost), 1));
        return $apiBase !== '' && $apiBase === $imageBase;
    }

    /**
     * Get the authentication headers required for watermark-free image access.
     *
     * @return array<string, string> HTTP headers as key => value
     */
    public static function getAuthHeaders(): array
    {
        $apiKey = ConfigProvider::getApiKey();
        if ($apiKey === '') {
            return [];
        }
        return [
            'Authorization' => 'Bearer ' . $apiKey,
            'X-Copyright-Authorization' => 'Bearer ' . $apiKey,
        ];
    }

    /**
     * Get the cURL-formatted authentication headers for API-hosted image access.
     *
     * Sends both the standard Bearer token (required for auth) and the
     * copyright header (required for watermark-free delivery).
     *
     * @return list<string> Headers in "Name: Value" format for cURL
     */
    public static function getCurlAuthHeaders(): array
    {
        $apiKey = ConfigProvider::getApiKey();
        if ($apiKey === '') {
            return [];
        }
        return [
            'Authorization: Bearer ' . $apiKey,
            'X-Copyright-Authorization: Bearer ' . $apiKey,
        ];
    }

    /**
     * Download an image URL and attach it to a CS-Cart product (body of the
     * fn_sphinx_holidays_add_product_image shell).
     *
     * Every image is downloaded here, with cURL, then attached through
     * CS-Cart's image pairs (fn_travel_core_attach_product_image), which
     * generate the thumbnails and store the file. An API-hosted image is asked
     * with the API's auth headers and without its watermark; any other is
     * asked as it is, without them (the API key never leaves for another host).
     *
     * Public images used to go through fn_update_product with the URL
     * (fn_travel_core_attach_images_from_urls), which reports no reason when
     * CS-Cart does not fetch it: a sandbox queue of 1230 images all "FAILED"
     * with an empty reason, in 8.7 s, none downloaded. Now a failure says why:
     * the HTTP code, cURL's error, or that the file is not an image.
     *
     * On failure, the short reason is exposed via self::$lastDownloadError
     * so cron callers can echo it without inspecting the CS-Cart event log.
     *
     * @param int $product_id CS-Cart product ID
     * @param string $image_url External image URL to download
     * @param bool $is_main True for main product image, false for additional
     * @return bool True on success
     */
    public static function attachToProduct(int $product_id, string $image_url, bool $is_main = false): bool
    {
        self::$lastDownloadError = '';
        if (empty($product_id) || empty($image_url)) {
            self::$lastDownloadError = 'empty args';
            return false;
        }
        $scheme = strtolower((string) parse_url($image_url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            self::$lastDownloadError = 'not an http(s) URL';
            return false;
        }

        $temp_file = fn_create_temp_file();
        $temp_file = is_string($temp_file) ? $temp_file : '';
        if ($temp_file === '') {
            self::$lastDownloadError = 'temp file failed';
            fn_log_event('general', 'runtime', ['message' => "Sphinx: fn_create_temp_file() returned empty for product #{$product_id}"]);
            return false;
        }

        // API-hosted: strip the watermark and send the Bearer auth headers.
        $isApiHosted = self::matchesApiHost($image_url, ConfigProvider::getApiBaseUrl());
        $download_url = $isApiHosted ? self::withoutWatermark($image_url) : $image_url;
        $headers = $isApiHosted ? self::getCurlAuthHeaders() : [];

        if (!self::download($download_url, $headers, $temp_file, $product_id)) {
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
            return false;
        }

        if (fn_travel_core_attach_product_image($product_id, $temp_file, 'sphinx', $is_main)) {
            return true;
        }
        self::$lastDownloadError = \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError !== ''
            ? \Tygh\Addons\TravelCore\Helpers\DebugLogger::$lastImageAttachError
            : 'attach failed';

        return false;
    }

    /**
     * Download $url into $tempFile; false (with self::$lastDownloadError)
     * unless it answered 200 with a body of at least 1 KB.
     *
     * @param list<string> $headers "Name: Value" headers
     */
    private static function download(string $url, array $headers, string $tempFile, int $productId): bool
    {
        $fp = fopen($tempFile, 'wb');
        if ($fp === false) {
            self::$lastDownloadError = 'fopen failed';
            fn_log_event('general', 'runtime', ['message' => "Sphinx: fopen() failed for temp file '{$tempFile}' product #{$productId}"]);
            return false;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            self::$lastDownloadError = 'curl init failed';
            fclose($fp);
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'CS-Cart/SphinxHolidays ImageSync/1.0',
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        clearstatcache(true, $tempFile);
        $size = file_exists($tempFile) ? (int) filesize($tempFile) : -1;
        if ($httpCode !== 200 || $size < 1000) {
            self::$lastDownloadError = "HTTP {$httpCode}" . ($curlError !== '' ? " ({$curlError})" : '') . ($httpCode === 200 ? ", {$size} bytes" : '');
            $host = (string) parse_url($url, PHP_URL_HOST);
            fn_log_event('general', 'runtime', ['message' => "Sphinx: image download failed for product #{$productId}: " . self::$lastDownloadError . ", host={$host}"]);

            return false;
        }

        return true;
    }
}

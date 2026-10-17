<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Api;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Exceptions\ApiException;
use Tygh\Addons\NovotonHolidays\NovotonHttpClient;
use Tygh\Addons\NovotonHolidays\NovotonXmlParser;
use Tygh\Addons\NovotonHolidays\Services\CacheServiceInterface;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\ValueObjects\Occupancy;
use Tygh\Addons\TravelCore\ValueObjects\RequestDebugInfo;

abstract class ApiClientBase
{
    protected NovotonHttpClient $httpClient;
    protected NovotonXmlParser $xmlParser;
    protected ?CacheServiceInterface $cache;
    protected bool $enableCache;

    /** @var array<string, mixed> Cache TTL by function (seconds) */
    protected array $cacheTtl = [];

    /** @var list<string> Functions that bypass cache */
    protected array $noCacheFunctions = [];

    // Debug state — encapsulated in RequestDebugInfo value object.
    // Public properties kept for backward compatibility but populated via debugInfo().
    public string $lastRequest = '';
    public string $lastResponse = '';
    public string $lastResponseRaw = '';
    /** @var array<string, mixed> */
    public array $lastRequestFormatted = [];
    public string $lastError = '';
    public int $lastHttpCode = 0;

    private RequestDebugInfo $debugInfo;

    public function __construct(
        NovotonHttpClient $httpClient,
        NovotonXmlParser $xmlParser,
        ?CacheServiceInterface $cache,
        bool $enableCache,
    ) {
        $this->httpClient = $httpClient;
        $this->xmlParser = $xmlParser;
        $this->cache = $cache;
        $this->enableCache = $enableCache;
        $this->debugInfo = new RequestDebugInfo();
    }

    protected function callApi(string $function, string $xml, string $lang = 'UK'): string
    {
        try {
            $raw = $this->httpClient->sendRequest($function, $xml, $lang);
        } catch (ApiException $e) {
            $this->syncDebugState();
            throw $e;
        }

        $this->syncDebugState();
        $cleaned = $this->xmlParser->clean($raw);
        $this->lastResponse = $cleaned;
        return $cleaned;
    }

    protected function callApiAndParse(string $function, string $xml, string $lang = 'UK'): \SimpleXMLElement
    {
        $response = $this->callApi($function, $xml, $lang);
        return $this->xmlParser->parse($response);
    }

    /**
     * callApiAndParse() for a reply that is stored or moves a baseline: a
     * refusal (Refusal) throws like a failed request instead of reading as an
     * answer that held no data.
     *
     * @throws ApiException
     */
    protected function callApiForAnswer(string $function, string $xml, string $lang = 'UK'): \SimpleXMLElement
    {
        $reply = $this->callApiAndParse($function, $xml, $lang);
        $reason = Refusal::reason($reply);
        if ($reason !== null) {
            throw ApiException::refused($function, $reason);
        }

        return $reply;
    }

    public function syncDebugState(): void
    {
        $this->lastResponseRaw = $this->httpClient->lastResponseRaw;
        $this->lastError = $this->httpClient->lastError;
        $this->lastHttpCode = $this->httpClient->lastHttpCode;

        // Update the encapsulated debug info object
        $this->debugInfo = new RequestDebugInfo(
            $this->lastRequest,
            $this->lastResponse,
            $this->lastResponseRaw,
            $this->lastRequestFormatted,
            $this->lastError,
            $this->lastHttpCode,
        );
    }

    /**
     * Get debug info as an immutable value object.
     */
    public function debugInfo(): RequestDebugInfo
    {
        return $this->debugInfo;
    }

    protected function getFromCache(string $function, string $cacheKey): mixed
    {
        if (in_array($function, $this->noCacheFunctions)) {
            return null;
        }
        if (!$this->enableCache || $this->cache === null) {
            return null;
        }
        return $this->cache->get($cacheKey);
    }

    /** @param mixed $data */
    protected function saveToCache(string $function, string $cacheKey, $data): void
    {
        if (in_array($function, $this->noCacheFunctions)) {
            return;
        }
        if (!$this->enableCache || $this->cache === null || $data === null) {
            return;
        }
        $ttl = TypeCoerce::toInt($this->cacheTtl[$function] ?? ConfigProvider::getCacheTtlSearch());
        $this->cache->set($cacheKey, $data, $ttl);
    }

    /**
     * Build XML header with encoding declaration.
     */
    protected function xmlHeader(): string
    {
        return '<?xml version="1.0" encoding="windows-1251"?>';
    }

    /**
     * Build <usr> and <psw> credential elements.
     */
    protected function xmlCredentials(): string
    {
        return '<usr>' . htmlspecialchars($this->httpClient->getApiUser()) . '</usr>'
             . '<psw>' . htmlspecialchars($this->httpClient->getApiPassword()) . '</psw>';
    }

    /**
     * Mask <usr>/<psw> credentials in request XML before logging or display.
     *
     * Every API request embeds live credentials via xmlCredentials(); this MUST
     * be applied to any XML passed to a logger (fn_log_event / DebugLogger) so
     * credentials never land in cscart_event_log or log aggregation.
     */
    protected function maskCredentials(string $xml): string
    {
        $xml = (string) preg_replace('/<usr>.*?<\/usr>/', '<usr>*****</usr>', $xml);
        $xml = (string) preg_replace('/<psw>.*?<\/psw>/', '<psw>*****</psw>', $xml);
        return $xml;
    }

    /**
     * Wrap a value in CDATA for safe XML embedding.
     *
     * Resort/city/hotel names may contain characters like & which, when
     * encoded via htmlspecialchars(), produce &amp; — technically valid XML,
     * but the Novoton API performs literal string matching and expects the
     * raw value.  CDATA preserves the exact string as returned by the API's
     * own resort_list / hotel_list responses.
     */
    protected function xmlCdata(string $value): string
    {
        // CDATA sections cannot contain the literal ']]>' sequence.
        // If present (extremely unlikely in resort names), split into two CDATA sections.
        $safe = str_replace(']]>', ']]]]><![CDATA[>', $value);
        return '<![CDATA[' . $safe . ']]>';
    }

    /**
     * Build children ages XML (<Age> elements).
     * Delegates to Occupancy::buildAgeXml() — single source of truth.
     * @param array<string, mixed> $children
     */
    protected function buildChildrenAgesXml(array $children): string
    {
        return Occupancy::buildAgeXml(TypeCoerce::toIntList($children));
    }

    /**
     * Build adult ages XML (<Age> elements).
     * Uses default adult age from Constants for missing entries.
     * @param array<int|string, mixed> $adultAges
     */
    protected function buildAdultAgesXml(int $count, array $adultAges = []): string
    {
        $ages = [];
        for ($i = 0; $i < $count; $i++) {
            $ages[] = isset($adultAges[$i]) ? TypeCoerce::toInt($adultAges[$i]) : Constants::DEFAULT_ADULT_AGE;
        }
        return Occupancy::buildAgeXml($ages);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function buildCacheKey(string $function, array $params): string
    {
        // Include hotel_id before the hash so cache invalidation per hotel
        // can use index-friendly prefix matching (no leading wildcards).
        $hotelId = TypeCoerce::toString($params['hotel_id'] ?? '');
        $hotelPart = $hotelId !== '' ? $hotelId . '_' : '';
        return 'nvt_api_' . $function . '_' . $hotelPart . md5((string) json_encode($params));
    }
}

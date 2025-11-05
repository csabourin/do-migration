<?php
namespace modules\externalapidata\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;
use yii\web\BadRequestHttpException;

class DataController extends Controller
{
    protected array|int|bool $allowAnonymous = ['fetch-json'];

    public function actionFetchJson(string $url = null): Response
{
    $decodedUrl = urldecode($url);
    if (!$url || !filter_var($decodedUrl, FILTER_VALIDATE_URL)) {
        return $this->asJson(['error' => 'Invalid URL or missing URL']);
    }

    // Domain allowlist for security
    $allowedDomains = ['services2.arcgis.com'];
    $parsedHost = parse_url($decodedUrl, PHP_URL_HOST);
    if (!in_array($parsedHost, $allowedDomains)) {
        return $this->asJson(['error' => 'URL not from a trusted domain']);
    }

    $cacheKey = 'externalFeed_' . md5($url);
    $cacheDuration = 3600; // 1 hour
    $cache = Craft::$app->getCache();
    $client = Craft::createGuzzleClient();

    // Check for cached data
    $cached = $cache->get($cacheKey);
    $cachedTimestamp = $cached['timestamp'] ?? null;
    $decodedData = $cached['payload'] ?? null;

    try {
        // Always check current Last-Modified header from API
        $headResponse = $client->head($decodedUrl);
        $latestTimestamp = $headResponse->getHeaderLine('Last-Modified');

        if (!$cached || !$cachedTimestamp || strtotime($latestTimestamp) > strtotime($cachedTimestamp)) {
            // Fetch fresh data if no cache or API has newer data
            $response = $client->get($decodedUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                ]
            ]);

            $body = (string)$response->getBody();
            Craft::info("Raw response body from API: $body", __METHOD__);
            Craft::info("Final requested URL: " . $url, __METHOD__);

            $decodedData = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Craft::error("JSON decode error: " . json_last_error_msg(), __METHOD__);
                return $this->asJson(['error' => 'Invalid JSON format from the API']);
            }

            // Save to cache
            $cache->set($cacheKey, [
                'timestamp' => $latestTimestamp,
                'payload' => $decodedData,
            ], $cacheDuration);
        }

    } catch (\Throwable $e) {
        Craft::error("API fetch failed: " . $e->getMessage(), __METHOD__);
        return $this->asJson(['error' => 'Failed to fetch data: ' . $e->getMessage()]);
    }

    return $this->asJson($decodedData);
    }
}

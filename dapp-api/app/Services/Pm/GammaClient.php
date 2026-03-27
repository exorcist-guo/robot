<?php

namespace App\Services\Pm;

use GuzzleHttp\Client;

class GammaClient
{
    private Client $client;

    public function __construct(?Client $client = null)
    {
        $baseUrl = rtrim((string) config('pm.gamma_base_url'), '/');
        if ($baseUrl === '') {
            $baseUrl = 'https://gamma-api.polymarket.com';
        }

        $this->client = $client ?? new Client([
            'base_uri' => $baseUrl . '/',
            'timeout' => 15,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function getPublicProfile(string $address): array
    {
        $address = strtolower(trim($address));

        $res = $this->client->get('public-profile', [
            'query' => ['address' => $address],
        ]);

        $json = json_decode($res->getBody()->getContents(), true);
        return is_array($json) ? $json : [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getEventBySlug(string $slug): ?array
    {
        return $this->getFirstItem('events', $slug);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getMarketBySlug(string $slug): ?array
    {
        return $this->getFirstItem('markets', $slug);
    }

    public function extractSlug(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('~polymarket\.com/(?:[a-z]{2}/)?event/([^/?#]+)~i', $value, $matches)) {
            return trim((string) ($matches[1] ?? ''));
        }

        return trim($value, " \t\n\r\0\x0B/");
    }

    /**
     * @return array<string,mixed>
     */
    public function getPricesHistory(string $marketId, int $startTs, ?int $endTs = null, string $interval = '1m', int $fidelity = 10): array
    {
        $marketId = trim($marketId);
        if ($marketId === '') {
            return [];
        }

        $query = [
            'market' => $marketId,
            'startTs' => $startTs,
            'interval' => $interval,
            'fidelity' => $fidelity,
        ];
        if ($endTs !== null && $endTs >= $startTs) {
            $query['endTs'] = $endTs;
        }

        $baseUrl = rtrim((string) config('pm.clob_base_url', 'https://clob.polymarket.com'), '/');
        $res = $this->client->get($baseUrl.'/prices-history', [
            'query' => $query,
        ]);

        $json = json_decode($res->getBody()->getContents(), true);
        if (!is_array($json)) {
            return [];
        }

        $history = $json['history'] ?? [];
        return is_array($history) ? array_values($history) : [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function resolveTailSweepMarket(string $input): ?array
    {
        $slug = $this->extractSlug($input);
        if ($slug === '') {
            return null;
        }

        $event = $this->getEventBySlug($slug) ?? [];
        $market = $this->findMarketFromEvent($event, $slug);

        if (!$market) {
            $market = $this->getMarketBySlug($slug);
        }

        if (!$market) {
            return null;
        }

        if ($event === []) {
            $event = $this->resolveEventFromMarket($market) ?? [];
        }

        $outcomes = $this->decodeJsonArray($market['outcomes'] ?? []);
        $tokenIds = $this->decodeJsonArray($market['clobTokenIds'] ?? []);
        $tokenMap = [];
        foreach ($outcomes as $index => $name) {
            $tokenMap[strtolower((string) $name)] = (string) ($tokenIds[$index] ?? '');
        }

        return [
            'slug' => (string) ($market['slug'] ?? $slug),
            'market_id' => (string) ($market['id'] ?? ''),
            'question' => (string) ($market['question'] ?? $event['title'] ?? ''),
            'symbol' => $this->inferMarketSymbol($market, $event),
            'resolution_source' => (string) ($market['resolutionSource'] ?? $event['resolutionSource'] ?? ''),
            'price_to_beat' => (string) ($event['eventMetadata']['priceToBeat'] ?? $market['eventMetadata']['priceToBeat'] ?? '0'),
            'end_at' => (string) ($market['endDate'] ?? $event['endDate'] ?? ''),
            'token_yes_id' => $tokenMap['up'] ?? $tokenMap['yes'] ?? '',
            'token_no_id' => $tokenMap['down'] ?? $tokenMap['no'] ?? '',
            'outcomes' => $outcomes,
            'token_ids' => $tokenIds,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getFirstItem(string $path, string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $baseUrl = rtrim((string) config('pm.gamma_base_url', 'https://gamma-api.polymarket.com'), '/');
        $res = $this->client->get($baseUrl.'/'.$path, [
            'query' => ['slug' => $slug],
        ]);

        $json = json_decode($res->getBody()->getContents(), true);
        if (!is_array($json) || $json === []) {
            return null;
        }

        $item = $json[0] ?? null;
        return is_array($item) ? $item : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveEventFromMarket(array $market): ?array
    {
        $event = $market['event'] ?? null;
        if (is_array($event)) {
            return $event;
        }

        $eventSlug = trim((string) ($market['eventSlug'] ?? $market['event_slug'] ?? ''));
        return $eventSlug !== '' ? $this->getEventBySlug($eventSlug) : null;
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>|null
     */
    private function findMarketFromEvent(array $event, string $slug): ?array
    {
        $markets = $event['markets'] ?? [];
        if (!is_array($markets)) {
            return null;
        }

        foreach ($markets as $item) {
            if (is_array($item) && (string) ($item['slug'] ?? '') === $slug) {
                return $item;
            }
        }

        if (isset($markets[0]) && is_array($markets[0])) {
            return $markets[0];
        }

        return null;
    }

    /**
     * @return array<int,mixed>
     */
    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param array<string,mixed> $market
     * @param array<string,mixed> $event
     */
    private function inferMarketSymbol(array $market, array $event): string
    {
        $resolutionSource = strtolower((string) ($market['resolutionSource'] ?? $event['resolutionSource'] ?? ''));
        if (preg_match('~/streams/([a-z]+)-([a-z]+)~', $resolutionSource, $matches)) {
            return strtolower($matches[1].'/'.$matches[2]);
        }

        return 'btc/usd';
    }
}

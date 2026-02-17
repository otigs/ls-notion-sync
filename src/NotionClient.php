<?php

namespace LightlySalted\NotionSync;

class NotionClient
{
    private const API_BASE = 'https://api.notion.com/v1';
    private const API_VERSION = '2025-09-03';
    private const LOG_OPTION = 'ls_notion_sync_log';
    private const MAX_LOG_ENTRIES = 50;
    private const MAX_RETRIES = 3;
    private const RETRY_DELAYS = [30, 120]; // seconds between retry 0→1, 1→2

    /**
     * Update a Notion page's Status select property.
     *
     * Called by Action Scheduler via the `ls_notion_sync_status` hook.
     * Queries the Notion database to find the page by WP Post ID,
     * then patches the Status property.
     */
    public static function updateStatus(int $post_id, string $notion_status): void
    {
        $apiKey = Config::getApiKey();

        if (! $apiKey) {
            error_log('[NotionSync] API key not configured — skipping sync for post ' . $post_id);
            self::log($post_id, '', $notion_status, 0, false, 'API key not configured');

            return;
        }

        $databaseId = Config::getDatabaseId();

        if (! $databaseId) {
            error_log('[NotionSync] Database ID not configured. Skipping sync for post ' . $post_id);
            self::log($post_id, '', $notion_status, 0, false, 'Database ID not configured');

            return;
        }

        // Step 1 — Find the Notion page by WP Post ID
        $notion_page_id = self::findPageByWpPostId($post_id, $databaseId, $apiKey);

        if ($notion_page_id === false) {
            // API error — let the job fail so Action Scheduler retries it
            $retryKey = self::retryKey($post_id);
            $attempt = (int) get_transient($retryKey);
            self::log($post_id, '', $notion_status, 0, false, 'Database query failed');
            self::maybeRetry($post_id, $notion_status, $attempt, $retryKey);

            return;
        }

        if ($notion_page_id === null) {
            error_log("[NotionSync] No Notion page found with WP Post ID {$post_id}. Skipping.");
            self::log($post_id, '', $notion_status, 0, false, 'No Notion page found for WP Post ID');

            return;
        }

        // Step 2 — Update the page's Status property
        $statusProperty = Config::getStatusProperty();

        $body = wp_json_encode([
            'properties' => [
                $statusProperty => [
                    'select' => [
                        'name' => $notion_status,
                    ],
                ],
            ],
        ]);

        $url = self::API_BASE . '/pages/' . $notion_page_id;

        $response = wp_remote_request($url, [
            'method'  => 'PATCH',
            'headers' => self::headers($apiKey),
            'body'    => $body,
            'timeout' => 10,
        ]);

        // Get attempt count from transient
        $retryKey = self::retryKey($post_id);
        $attempt = (int) get_transient($retryKey);

        if (is_wp_error($response)) {
            $errorMsg = $response->get_error_message();
            error_log("[NotionSync] WP HTTP error for post {$post_id}: {$errorMsg}");
            self::log($post_id, $notion_page_id, $notion_status, 0, false, $errorMsg);
            self::maybeRetry($post_id, $notion_status, $attempt, $retryKey);

            return;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $success = $statusCode >= 200 && $statusCode < 300;

        if ($success) {
            error_log("[NotionSync] Synced post {$post_id} → Notion page {$notion_page_id} as \"{$notion_status}\"");
            self::log($post_id, $notion_page_id, $notion_status, $statusCode, true);
            delete_transient($retryKey);

            return;
        }

        // Rate limited — reschedule using Retry-After header
        if ($statusCode === 429) {
            $retryAfter = (int) wp_remote_retrieve_header($response, 'retry-after');

            if ($retryAfter < 1) {
                $retryAfter = 1;
            }

            error_log("[NotionSync] Rate limited for post {$post_id} — retrying in {$retryAfter}s");
            self::log($post_id, $notion_page_id, $notion_status, 429, false, "Rate limited, retry in {$retryAfter}s");

            self::scheduleRetry($retryAfter, $post_id, $notion_status);

            return;
        }

        // Other failure — retry with backoff
        $responseBody = wp_remote_retrieve_body($response);
        error_log("[NotionSync] Failed for post {$post_id} — HTTP {$statusCode}: {$responseBody}");
        self::log($post_id, $notion_page_id, $notion_status, $statusCode, false, "HTTP {$statusCode}");
        self::maybeRetry($post_id, $notion_status, $attempt, $retryKey);
    }

    /**
     * Query the Notion database's data sources for a page where WP Post ID matches.
     *
     * Multi-source databases (API 2025-09-03) require discovering data sources
     * first, then querying each one individually.
     *
     * @param int    $wp_post_id  The WordPress post ID to search for.
     * @param string $database_id The Notion database ID.
     * @param string $api_key     The Notion API key.
     * @return string|false|null Page ID on success, null if not found, false on API error.
     */
    private static function findPageByWpPostId(int $wp_post_id, string $database_id, string $api_key): string|false|null
    {
        $dataSources = self::getDataSourceIds($database_id, $api_key);

        if ($dataSources === false) {
            return false; // Discovery failed — retriable
        }

        $body = wp_json_encode([
            'filter' => [
                'property' => 'WP Post ID',
                'number'   => [
                    'equals' => $wp_post_id,
                ],
            ],
            'page_size' => 1,
        ]);

        foreach ($dataSources as $sourceId) {
            $url = self::API_BASE . '/data_sources/' . $sourceId . '/query';

            $response = wp_remote_post($url, [
                'headers' => self::headers($api_key),
                'body'    => $body,
                'timeout' => 10,
            ]);

            if (is_wp_error($response)) {
                error_log("[NotionSync] Data source query failed for WP Post ID {$wp_post_id}: " . $response->get_error_message());

                return false;
            }

            $statusCode = wp_remote_retrieve_response_code($response);

            if ($statusCode < 200 || $statusCode >= 300) {
                error_log("[NotionSync] Data source query returned HTTP {$statusCode} for WP Post ID {$wp_post_id}");

                return false;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            $results = $data['results'] ?? [];

            if (! empty($results)) {
                return $results[0]['id'];
            }
        }

        return null; // No matching page in any data source
    }

    /**
     * Get the data source IDs for the Notion database, cached in a transient.
     *
     * @return string[]|false Array of data source IDs, or false on API error.
     */
    private static function getDataSourceIds(string $database_id, string $api_key): array|false
    {
        $transientKey = 'ls_notion_data_sources';
        $cached = get_transient($transientKey);

        if (is_array($cached) && ! empty($cached)) {
            return $cached;
        }

        $url = self::API_BASE . '/databases/' . $database_id;

        $response = wp_remote_get($url, [
            'headers' => self::headers($api_key),
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            error_log('[NotionSync] Data source discovery failed: ' . $response->get_error_message());

            return false;
        }

        $statusCode = wp_remote_retrieve_response_code($response);

        if ($statusCode < 200 || $statusCode >= 300) {
            error_log("[NotionSync] Data source discovery returned HTTP {$statusCode}");

            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $sources = $data['data_sources'] ?? [];

        if (empty($sources)) {
            error_log('[NotionSync] No data sources found on database ' . $database_id);

            return false;
        }

        $ids = array_column($sources, 'id');

        set_transient($transientKey, $ids, DAY_IN_SECONDS);

        return $ids;
    }

    /**
     * Build common Notion API request headers.
     */
    private static function headers(string $api_key): array
    {
        return [
            'Authorization'  => 'Bearer ' . $api_key,
            'Notion-Version' => self::API_VERSION,
            'Content-Type'   => 'application/json',
        ];
    }

    /**
     * Retry with escalating backoff if under the max attempt count.
     */
    private static function maybeRetry(
        int $post_id,
        string $notion_status,
        int $attempt,
        string $retryKey
    ): void {
        if ($attempt >= self::MAX_RETRIES) {
            error_log("[NotionSync] Max retries reached for post {$post_id} — giving up");
            delete_transient($retryKey);

            return;
        }

        $delay = self::RETRY_DELAYS[$attempt] ?? 120;
        $nextAttempt = $attempt + 1;

        set_transient($retryKey, $nextAttempt, HOUR_IN_SECONDS);

        error_log("[NotionSync] Scheduling retry {$nextAttempt}/" . self::MAX_RETRIES . " for post {$post_id} in {$delay}s");

        self::scheduleRetry($delay, $post_id, $notion_status);
    }

    /**
     * Schedule a delayed retry via Action Scheduler, falling back to WP cron.
     */
    private static function scheduleRetry(int $delay, int $post_id, string $notion_status): void
    {
        $args = [$post_id, $notion_status];

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + $delay, 'ls_notion_sync_status', $args, 'notion-sync');
        } else {
            wp_schedule_single_event(time() + $delay, 'ls_notion_sync_status', $args);
        }
    }

    /**
     * Transient key for tracking retry attempts.
     */
    private static function retryKey(int $post_id): string
    {
        return 'ls_notion_retry_' . $post_id;
    }

    /**
     * Log a sync attempt to the options-based log.
     */
    private static function log(
        int $post_id,
        string $notion_page_id,
        string $notion_status,
        int $status_code,
        bool $success,
        string $error = ''
    ): void {
        $log = get_option(self::LOG_OPTION, []);

        if (! is_array($log)) {
            $log = [];
        }

        array_unshift($log, [
            'post_id'        => $post_id,
            'notion_page_id' => $notion_page_id,
            'notion_status'  => $notion_status,
            'status_code'    => $status_code,
            'success'        => $success,
            'error'          => $error,
            'timestamp'      => gmdate('c'),
        ]);

        $log = array_slice($log, 0, self::MAX_LOG_ENTRIES);

        update_option(self::LOG_OPTION, $log, false);
    }

    /**
     * Get the sync log for display.
     *
     * @return array<int, array{post_id: int, notion_page_id: string, notion_status: string, status_code: int, success: bool, error: string, timestamp: string}>
     */
    public static function getLog(int $limit = 20): array
    {
        $log = get_option(self::LOG_OPTION, []);

        return is_array($log) ? array_slice($log, 0, $limit) : [];
    }
}

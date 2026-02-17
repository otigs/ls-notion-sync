<?php

namespace LightlySalted\NotionSync;

class Config
{
    private static ?array $cachedMap = null;

    /**
     * Whether Notion status sync is enabled in Theme Settings.
     */
    public static function isEnabled(): bool
    {
        if (! function_exists('get_field')) {
            return false;
        }

        return (bool) get_field('notion_sync_enabled', 'option');
    }

    /**
     * Notion API key (decrypted by ACF hooks).
     */
    public static function getApiKey(): ?string
    {
        if (! function_exists('get_field')) {
            return null;
        }

        $key = get_field('notion_api_key', 'option');

        return ! empty($key) ? $key : null;
    }

    /**
     * Notion database ID to query for page lookups.
     */
    public static function getDatabaseId(): ?string
    {
        if (! function_exists('get_field')) {
            return null;
        }

        $id = get_field('notion_database_id', 'option');

        return ! empty($id) ? $id : null;
    }

    /**
     * The Notion property name for the Status select field.
     */
    public static function getStatusProperty(): string
    {
        if (! function_exists('get_field')) {
            return 'Status';
        }

        $prop = get_field('notion_status_property', 'option');

        return ! empty($prop) ? $prop : 'Status';
    }

    /**
     * WordPress status → Notion status label mapping.
     *
     * Built from the ACF repeater `notion_status_map` and cached per-request.
     *
     * @return array<string, string> e.g. ['publish' => 'Published', 'draft' => 'Draft']
     */
    public static function getStatusMap(): array
    {
        if (self::$cachedMap !== null) {
            return self::$cachedMap;
        }

        $map = [];

        if (function_exists('get_field')) {
            $rows = get_field('notion_status_map', 'option');

            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $wpStatus = $row['wp_status'] ?? '';
                    $notionStatus = $row['notion_status'] ?? '';

                    if ($wpStatus !== '' && $notionStatus !== '') {
                        $map[$wpStatus] = $notionStatus;
                    }
                }
            }
        }

        /** @var array<string, string> */
        $map = apply_filters('notion_sync_status_map', $map);

        self::$cachedMap = $map;

        return $map;
    }

    /**
     * Post types eligible for Notion sync.
     *
     * @return string[]
     */
    public static function getSyncablePostTypes(): array
    {
        $types = get_post_types(['public' => true]);
        unset($types['attachment']);

        /** @var string[] */
        $types = apply_filters('notion_sync_post_types', array_values($types));

        return $types;
    }
}

<?php

namespace LightlySalted\NotionSync;

use WP_Post;

class StatusListener
{
    /**
     * Post IDs already enqueued this request (deduplication).
     *
     * @var array<int, true>
     */
    private static array $queued = [];

    /**
     * Handle post status transitions.
     *
     * Hooked to `transition_post_status` — fires on publish, draft,
     * trash, restore, and any other status change.
     */
    public static function onTransition(string $new_status, string $old_status, WP_Post $post): void
    {
        // 1. Loop prevention — skip if this change originated from Notion/MCP
        if (defined('NOTION_SYNC_INBOUND')) {
            return;
        }

        // 2. Feature toggle
        if (! Config::isEnabled()) {
            return;
        }

        // 3. No actual change
        if ($new_status === $old_status) {
            return;
        }

        // 4. Skip revisions and autosaves
        if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
            return;
        }

        // 5. Post type check
        if (! in_array($post->post_type, Config::getSyncablePostTypes(), true)) {
            return;
        }

        // 6. Status must be in the mapping
        $statusMap = Config::getStatusMap();
        if (! isset($statusMap[$new_status])) {
            return;
        }

        // 7. Deduplication — only one job per post per request
        if (isset(self::$queued[$post->ID])) {
            return;
        }

        $mappedStatus = $statusMap[$new_status];

        self::enqueue($post->ID, $mappedStatus);

        self::$queued[$post->ID] = true;
    }

    /**
     * Handle permanent post deletion.
     *
     * Hooked to `before_delete_post` so the sync job is enqueued
     * before WordPress removes the post and its meta.
     */
    public static function onDelete(int $post_id, WP_Post $post): void
    {
        // 1. Loop prevention
        if (defined('NOTION_SYNC_INBOUND')) {
            return;
        }

        // 2. Feature toggle
        if (! Config::isEnabled()) {
            return;
        }

        // 3. Skip revisions and autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // 4. Post type check
        if (! in_array($post->post_type, Config::getSyncablePostTypes(), true)) {
            return;
        }

        // 5. "deleted" must be in the status mapping
        $statusMap = Config::getStatusMap();
        if (! isset($statusMap['deleted'])) {
            return;
        }

        // 6. Deduplication
        if (isset(self::$queued[$post_id])) {
            return;
        }

        $mappedStatus = $statusMap['deleted'];

        self::enqueue($post_id, $mappedStatus);

        self::$queued[$post_id] = true;
    }

    /**
     * Enqueue a sync job via Action Scheduler, falling back to WP cron.
     *
     * Action Scheduler loads during `plugins_loaded`, but this mu-plugin's
     * hooks can fire before that (e.g. during early wp_insert_post calls).
     */
    private static function enqueue(int $post_id, string $mappedStatus): void
    {
        $args = [$post_id, $mappedStatus];

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('ls_notion_sync_status', $args, 'notion-sync');
        } else {
            wp_schedule_single_event(time(), 'ls_notion_sync_status', $args);
        }
    }
}

<?php

namespace LightlySalted\NotionSync;

use LightlySalted\NotionSync\Rest\ContentController;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

class DeletionLog
{
    private const OPTION = 'ls_notion_deletions';

    private const MAX_ENTRIES = 1000;

    private const MAX_AGE_DAYS = 90;

    /**
     * Record a permanent deletion for the worker's deletions feed.
     *
     * Hooked to `before_delete_post` at priority 9 so the post object is
     * still fully readable. Trash/restore are NOT recorded here — trashed
     * posts still exist and flow through the content feed as status changes.
     */
    public static function onDelete(int $post_id, WP_Post $post): void
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (! in_array($post->post_type, ContentController::SYNCED_POST_TYPES, true)) {
            return;
        }

        $entries = get_option(self::OPTION, []);

        if (! is_array($entries)) {
            $entries = [];
        }

        $entries[] = [
            'post_id'        => $post_id,
            'post_type'      => $post->post_type,
            'deleted_at_gmt' => gmdate('c'),
        ];

        update_option(self::OPTION, self::prune($entries), false);
    }

    /**
     * REST callback for GET /ls-notion/v1/deletions.
     */
    public static function getDeletions(WP_REST_Request $request): WP_REST_Response
    {
        $since    = $request->get_param('since');
        $postType = $request->get_param('post_type');

        $sinceTs = ! empty($since) ? strtotime($since) : false;

        $entries = get_option(self::OPTION, []);

        if (! is_array($entries)) {
            $entries = [];
        }

        $items = array_filter($entries, static function (array $entry) use ($sinceTs, $postType): bool {
            if ($postType !== null && $postType !== '' && ($entry['post_type'] ?? '') !== $postType) {
                return false;
            }

            if ($sinceTs !== false && strtotime($entry['deleted_at_gmt'] ?? '') < $sinceTs) {
                return false;
            }

            return true;
        });

        return rest_ensure_response([
            'items'           => array_values($items),
            'server_time_gmt' => gmdate('c'),
        ]);
    }

    /**
     * Cap the log by age and size so the option never grows unbounded.
     *
     * @param array<int, array{post_id: int, post_type: string, deleted_at_gmt: string}> $entries
     *
     * @return array<int, array{post_id: int, post_type: string, deleted_at_gmt: string}>
     */
    private static function prune(array $entries): array
    {
        $cutoff = time() - self::MAX_AGE_DAYS * DAY_IN_SECONDS;

        $entries = array_filter($entries, static function (array $entry) use ($cutoff): bool {
            $ts = strtotime($entry['deleted_at_gmt'] ?? '');

            return $ts !== false && $ts >= $cutoff;
        });

        return array_values(array_slice($entries, -self::MAX_ENTRIES));
    }
}

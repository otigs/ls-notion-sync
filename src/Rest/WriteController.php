<?php

namespace LightlySalted\NotionSync\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class WriteController
{
    public static function registerRoutes(): void
    {
        $typePattern = implode('|', ContentController::SYNCED_POST_TYPES);

        register_rest_route('ls-notion/v1', '/content/(?P<post_type>' . $typePattern . ')/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'updatePost'],
            'permission_callback' => [ContentController::class, 'canRead'],
        ]);

        register_rest_route('ls-notion/v1', '/content/(?P<post_type>' . $typePattern . ')', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'createPost'],
            'permission_callback' => [ContentController::class, 'canRead'],
        ]);
    }

    /**
     * Partial update of an existing post from the Notion worker.
     *
     * Conflict guard: if WP was modified after the Notion page's last edit,
     * WP wins — respond 409 so the worker skips and the forward sync
     * reconciles Notion instead.
     */
    public static function updatePost(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $postType = $request->get_param('post_type');
        $postId   = (int) $request->get_param('id');
        $payload  = $request->get_json_params();

        if (! is_array($payload)) {
            return new WP_Error('ls_notion_bad_payload', 'Request body must be a JSON object.', ['status' => 400]);
        }

        $post = get_post($postId);

        if (! $post || $post->post_type !== $postType) {
            return new WP_Error('ls_notion_not_found', 'Post not found for this post type.', ['status' => 404]);
        }

        $notionEditedGmt = isset($payload['notion_last_edited_gmt']) ? (string) $payload['notion_last_edited_gmt'] : '';
        $notionEditedTs  = $notionEditedGmt !== '' ? strtotime($notionEditedGmt) : false;

        if ($notionEditedTs === false) {
            return new WP_Error('ls_notion_bad_payload', 'notion_last_edited_gmt (ISO 8601 GMT) is required.', ['status' => 400]);
        }

        $wpModifiedTs = strtotime($post->post_modified_gmt . ' GMT');

        if ($wpModifiedTs !== false && $wpModifiedTs > $notionEditedTs) {
            return new WP_Error('ls_notion_conflict', 'WordPress post is newer than the Notion edit.', [
                'status'       => 409,
                'modified_gmt' => mysql2date('c', $post->post_modified_gmt, false),
            ]);
        }

        // Loop guard: suppress the StatusListener push for this request.
        if (! defined('NOTION_SYNC_INBOUND')) {
            define('NOTION_SYNC_INBOUND', true);
        }

        $result = PostUpdater::apply($post, $payload);

        if (is_wp_error($result)) {
            $result->add_data(['status' => 500]);

            return $result;
        }

        clean_post_cache($postId);

        return rest_ensure_response(PostSerializer::serialize(get_post($postId)));
    }

    /**
     * Create a draft post from a Notion-authored page.
     */
    public static function createPost(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $postType = $request->get_param('post_type');
        $payload  = $request->get_json_params();

        if (! is_array($payload)) {
            return new WP_Error('ls_notion_bad_payload', 'Request body must be a JSON object.', ['status' => 400]);
        }

        $title = isset($payload['title']) && is_string($payload['title']) ? sanitize_text_field($payload['title']) : '';

        if ($title === '') {
            return new WP_Error('ls_notion_bad_payload', 'A title is required to create a post.', ['status' => 400]);
        }

        if (! defined('NOTION_SYNC_INBOUND')) {
            define('NOTION_SYNC_INBOUND', true);
        }

        $postId = wp_insert_post([
            'post_type'   => $postType,
            'post_status' => 'draft',
            'post_title'  => $title,
        ], true);

        if (is_wp_error($postId)) {
            $postId->add_data(['status' => 500]);

            return $postId;
        }

        $post = get_post($postId);

        // Reuse the updater for content/yoast/acf/taxonomies; status stays draft.
        unset($payload['status']);

        $result = PostUpdater::apply($post, $payload);

        if (is_wp_error($result)) {
            $result->add_data(['status' => 500]);

            return $result;
        }

        clean_post_cache($postId);

        return rest_ensure_response(PostSerializer::serialize(get_post($postId)));
    }
}

<?php

namespace LightlySalted\NotionSync\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class MediaController
{
    public static function registerRoutes(): void
    {
        register_rest_route('ls-notion/v1', '/media/unsplash', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'sideload'],
            'permission_callback' => [self::class, 'canUpload'],
        ]);
    }

    public static function canUpload(): bool
    {
        return current_user_can('edit_others_posts') && current_user_can('upload_files');
    }

    /**
     * Sideload an Unsplash image (already selected by the worker) into the
     * media library, set it as the post's featured image, and record
     * attribution. The worker is responsible for triggering Unsplash's
     * download_location endpoint per their API guidelines.
     */
    public static function sideload(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $payload = $request->get_json_params();

        if (! is_array($payload)) {
            return new WP_Error('ls_notion_bad_payload', 'Request body must be a JSON object.', ['status' => 400]);
        }

        $postId   = isset($payload['post_id']) ? (int) $payload['post_id'] : 0;
        $imageUrl = isset($payload['image_url']) ? esc_url_raw((string) $payload['image_url']) : '';
        $alt      = isset($payload['alt']) ? sanitize_text_field((string) $payload['alt']) : '';
        $credit   = isset($payload['credit']) ? sanitize_text_field((string) $payload['credit']) : '';
        $keyword  = isset($payload['keyword']) ? sanitize_text_field((string) $payload['keyword']) : '';

        $post = get_post($postId);

        if (! $post || ! in_array($post->post_type, ContentController::SYNCED_POST_TYPES, true)) {
            return new WP_Error('ls_notion_not_found', 'Post not found.', ['status' => 404]);
        }

        if ($imageUrl === '' || ! str_starts_with($imageUrl, 'https://images.unsplash.com/')) {
            return new WP_Error('ls_notion_bad_payload', 'image_url must be an images.unsplash.com URL.', ['status' => 400]);
        }

        if (! defined('NOTION_SYNC_INBOUND')) {
            define('NOTION_SYNC_INBOUND', true);
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachmentId = media_sideload_image($imageUrl, $postId, $credit ?: $alt, 'id');

        if (is_wp_error($attachmentId)) {
            $attachmentId->add_data(['status' => 502]);

            return $attachmentId;
        }

        if ($alt !== '') {
            update_post_meta($attachmentId, '_wp_attachment_image_alt', $alt);
        }

        if (isset($payload['photographer_url'])) {
            update_post_meta($attachmentId, '_ls_unsplash_photographer_url', esc_url_raw((string) $payload['photographer_url']));
        }

        set_post_thumbnail($postId, $attachmentId);

        if ($credit !== '' && function_exists('update_field')) {
            update_field('image_credit', $credit, $postId);
        }

        if ($keyword !== '') {
            update_post_meta($postId, '_ls_unsplash_keyword', $keyword);
        }

        // Bump post_modified so the forward sync mirrors the new featured
        // image back to Notion on the next delta cycle.
        wp_update_post(['ID' => $postId]);
        clean_post_cache($postId);

        return rest_ensure_response([
            'attachment_id' => $attachmentId,
            'url'           => wp_get_attachment_image_url($attachmentId, 'full'),
        ]);
    }
}

<?php

namespace LightlySalted\NotionSync\Rest;

use WP_Error;
use WP_Post;

class PostUpdater
{
    /**
     * Post types whose editor content (post_content) is synced.
     *
     * @var string[]
     */
    public const EDITOR_POST_TYPES = ['post', 'service', 'case_study'];

    /**
     * Apply a partial update payload from the Notion worker to a post.
     *
     * Callers must define NOTION_SYNC_INBOUND before invoking so the
     * StatusListener does not echo the change back to Notion.
     *
     * @param array<string, mixed> $payload
     */
    public static function apply(WP_Post $post, array $payload): true|WP_Error
    {
        $postArgs = ['ID' => $post->ID];

        if (isset($payload['title']) && is_string($payload['title']) && $payload['title'] !== '') {
            $postArgs['post_title'] = sanitize_text_field($payload['title']);
        }

        if (isset($payload['content_html']) && is_string($payload['content_html'])
            && in_array($post->post_type, self::EDITOR_POST_TYPES, true)
        ) {
            $postArgs['post_content'] = wp_kses_post($payload['content_html']);
        }

        $status = isset($payload['status']) && is_string($payload['status']) ? $payload['status'] : null;

        if ($status === 'trash') {
            // Apply non-status changes first, then trash.
            $result = wp_update_post($postArgs, true);

            if (is_wp_error($result)) {
                return $result;
            }

            self::applyMeta($post, $payload);
            wp_trash_post($post->ID);

            return true;
        }

        if ($status !== null && in_array($status, ['publish', 'draft', 'private', 'pending', 'future'], true)) {
            if ($post->post_status === 'trash') {
                wp_untrash_post($post->ID);
            }

            $postArgs['post_status'] = $status;
        }

        // Always run wp_update_post — even for meta-only payloads — so
        // post_modified advances and downstream consumers see the change.
        $result = wp_update_post($postArgs, true);

        if (is_wp_error($result)) {
            return $result;
        }

        self::applyMeta($post, $payload);

        return true;
    }

    /**
     * Apply Yoast meta, ACF fields, and taxonomies from the payload.
     *
     * @param array<string, mixed> $payload
     */
    private static function applyMeta(WP_Post $post, array $payload): void
    {
        if (isset($payload['yoast']) && is_array($payload['yoast'])) {
            $yoastMap = [
                'title'    => '_yoast_wpseo_title',
                'metadesc' => '_yoast_wpseo_metadesc',
                'focuskw'  => '_yoast_wpseo_focuskw',
            ];

            foreach ($yoastMap as $key => $metaKey) {
                if (array_key_exists($key, $payload['yoast'])) {
                    update_post_meta($post->ID, $metaKey, sanitize_text_field((string) $payload['yoast'][$key]));
                }
            }
        }

        if (isset($payload['acf']) && is_array($payload['acf']) && function_exists('update_field')) {
            $allowed = self::allowedAcfFields($post->post_type);

            foreach ($payload['acf'] as $field => $value) {
                if (isset($allowed[$field])) {
                    update_field($field, self::sanitizeAcfValue($value, $allowed[$field]), $post->ID);
                }
            }
        }

        if ($post->post_type === 'post' && isset($payload['taxonomies']) && is_array($payload['taxonomies'])) {
            $taxMap = [
                'categories' => 'category',
                'tags'       => 'post_tag',
                'topics'     => 'topic',
            ];

            foreach ($taxMap as $key => $taxonomy) {
                if (isset($payload['taxonomies'][$key]) && is_array($payload['taxonomies'][$key])) {
                    $terms = array_filter(array_map('sanitize_text_field', $payload['taxonomies'][$key]));
                    wp_set_post_terms($post->ID, $terms, $taxonomy, false);
                }
            }
        }
    }

    /**
     * Registered ACF field names (and types) for a post type — the inbound
     * whitelist. Anything not registered for the CPT is silently dropped.
     *
     * @return array<string, string> field name => field type
     */
    private static function allowedAcfFields(string $post_type): array
    {
        if (! function_exists('acf_get_field_groups')) {
            return [];
        }

        static $cache = [];

        if (isset($cache[$post_type])) {
            return $cache[$post_type];
        }

        $allowed = [];

        foreach (acf_get_field_groups(['post_type' => $post_type]) as $group) {
            foreach ((array) acf_get_fields($group) as $field) {
                if (! empty($field['name']) && $field['type'] !== 'tab') {
                    $allowed[$field['name']] = $field['type'];
                }
            }
        }

        $cache[$post_type] = $allowed;

        return $allowed;
    }

    /**
     * Sanitise an inbound ACF value by field type. Relationship and
     * post_object values arrive as arrays of WP post IDs; wysiwyg arrives
     * as HTML (converted from markdown by the worker).
     */
    private static function sanitizeAcfValue(mixed $value, string $field_type): mixed
    {
        return match ($field_type) {
            'relationship', 'post_object', 'gallery' => array_values(array_filter(array_map('intval', (array) $value))),
            'wysiwyg'    => wp_kses_post((string) $value),
            'textarea'   => sanitize_textarea_field((string) $value),
            'true_false' => (bool) $value,
            'number'     => is_numeric($value) ? $value + 0 : '',
            'url'        => esc_url_raw((string) $value),
            'image', 'file' => is_numeric($value) ? (int) $value : $value,
            default      => is_scalar($value) ? sanitize_text_field((string) $value) : $value,
        };
    }
}

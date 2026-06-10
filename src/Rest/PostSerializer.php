<?php

namespace LightlySalted\NotionSync\Rest;

use WP_Post;

class PostSerializer
{
    /**
     * ACF fields excluded from the payload, keyed by post type.
     *
     * `page_builder` is a large flexible-content tree irrelevant to the
     * Notion mirror; `geojson_data` is a raw geometry blob.
     *
     * @var array<string, string[]>
     */
    private const EXCLUDED_ACF_FIELDS = [
        'post' => ['page_builder'],
        'area' => ['geojson_data'],
    ];

    /**
     * Serialize a post for the Notion worker content feed.
     *
     * @return array<string, mixed>
     */
    public static function serialize(WP_Post $post): array
    {
        $thumbId = get_post_thumbnail_id($post);

        return [
            'id'             => $post->ID,
            'type'           => $post->post_type,
            'title'          => get_the_title($post),
            'slug'           => $post->post_name,
            'status'         => $post->post_status,
            'date_gmt'       => mysql2date('c', $post->post_date_gmt, false),
            'modified_gmt'   => mysql2date('c', $post->post_modified_gmt, false),
            'permalink'      => is_post_type_viewable($post->post_type) ? get_permalink($post) : null,
            'author_name'    => get_the_author_meta('display_name', (int) $post->post_author),
            'excerpt'        => post_type_supports($post->post_type, 'excerpt') ? get_the_excerpt($post) : '',
            'featured_image' => $thumbId ? [
                'id'  => $thumbId,
                'url' => wp_get_attachment_image_url($thumbId, 'full'),
            ] : null,
            'taxonomies'     => self::serializeTaxonomies($post),
            'yoast'          => [
                'title'    => (string) get_post_meta($post->ID, '_yoast_wpseo_title', true),
                'metadesc' => (string) get_post_meta($post->ID, '_yoast_wpseo_metadesc', true),
                'focuskw'  => (string) get_post_meta($post->ID, '_yoast_wpseo_focuskw', true),
            ],
            'acf'            => self::serializeAcf($post),
        ];
    }

    /**
     * Term names per taxonomy (blog posts only).
     *
     * @return array<string, string[]>
     */
    private static function serializeTaxonomies(WP_Post $post): array
    {
        if ($post->post_type !== 'post') {
            return [];
        }

        $names = static function (string $taxonomy) use ($post): array {
            $terms = get_the_terms($post, $taxonomy);

            if (! is_array($terms)) {
                return [];
            }

            return array_values(wp_list_pluck($terms, 'name'));
        };

        return [
            'categories' => $names('category'),
            'tags'       => $names('post_tag'),
            'topics'     => $names('topic'),
        ];
    }

    /**
     * All ACF fields for the post, normalised for transport.
     *
     * @return array<string, mixed>
     */
    private static function serializeAcf(WP_Post $post): array
    {
        if (! function_exists('get_fields')) {
            return [];
        }

        $fields = get_fields($post->ID);

        if (! is_array($fields)) {
            return [];
        }

        $excluded = self::EXCLUDED_ACF_FIELDS[$post->post_type] ?? [];

        /** @var string[] $excluded */
        $excluded = apply_filters('ls_notion_content_excluded_acf_fields', $excluded, $post);

        foreach ($excluded as $key) {
            unset($fields[$key]);
        }

        return array_map([self::class, 'normalizeValue'], $fields);
    }

    /**
     * Normalise ACF values for JSON transport.
     *
     * Relationship/post_object fields become plain post IDs regardless of
     * the field's return_format, and image/file arrays are reduced to
     * `{id, url, alt}` so the worker never sees WP_Post-shaped payloads.
     */
    private static function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof WP_Post) {
            return $value->ID;
        }

        if (is_array($value)) {
            // ACF image/file array
            if (isset($value['ID'], $value['url'], $value['mime_type'])) {
                return [
                    'id'  => $value['ID'],
                    'url' => $value['url'],
                    'alt' => $value['alt'] ?? '',
                ];
            }

            return array_map([self::class, 'normalizeValue'], $value);
        }

        return $value;
    }
}

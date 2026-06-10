<?php

namespace LightlySalted\NotionSync\Rest;

use LightlySalted\NotionSync\DeletionLog;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

class ContentController
{
    /**
     * Post types exposed through the content feed.
     *
     * @var string[]
     */
    public const SYNCED_POST_TYPES = ['post', 'service', 'case_study', 'area', 'testimonial', 'faq'];

    /**
     * Post statuses included in the feed. Trash is deliberate: trashing
     * bumps post_modified, so trashed posts surface through the delta
     * cursor and the worker marks them "Trashed" rather than deleting.
     *
     * @var string[]
     */
    private const SYNCED_STATUSES = ['publish', 'draft', 'pending', 'future', 'private', 'trash'];

    public static function registerRoutes(): void
    {
        register_rest_route('ls-notion/v1', '/content/(?P<post_type>' . implode('|', self::SYNCED_POST_TYPES) . ')', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'getContent'],
            'permission_callback' => [self::class, 'canRead'],
            'args'                => [
                'modified_after' => [
                    'type'        => 'string',
                    'description' => 'GMT ISO 8601 timestamp; inclusive lower bound on post_modified_gmt.',
                ],
                'page'           => [
                    'type'    => 'integer',
                    'default' => 1,
                    'minimum' => 1,
                ],
                'per_page'       => [
                    'type'    => 'integer',
                    'default' => 20,
                    'minimum' => 1,
                    'maximum' => 50,
                ],
            ],
        ]);

        register_rest_route('ls-notion/v1', '/deletions', [
            'methods'             => 'GET',
            'callback'            => [DeletionLog::class, 'getDeletions'],
            'permission_callback' => [self::class, 'canRead'],
            'args'                => [
                'since'     => [
                    'type'        => 'string',
                    'description' => 'GMT ISO 8601 timestamp; inclusive lower bound on deleted_at_gmt.',
                ],
                'post_type' => [
                    'type' => 'string',
                    'enum' => self::SYNCED_POST_TYPES,
                ],
            ],
        ]);
    }

    /**
     * The feed exposes drafts, private and pending content, so it requires
     * an editor-level Application Password user.
     */
    public static function canRead(): bool
    {
        return current_user_can('edit_others_posts');
    }

    public static function getContent(WP_REST_Request $request): WP_REST_Response
    {
        $postType = $request->get_param('post_type');
        $page     = max(1, (int) $request->get_param('page'));
        $perPage  = (int) $request->get_param('per_page');

        $args = [
            'post_type'              => $postType,
            'post_status'            => self::SYNCED_STATUSES,
            'posts_per_page'         => $perPage,
            'paged'                  => $page,
            'orderby'                => ['modified' => 'ASC', 'ID' => 'ASC'],
            'no_found_rows'          => false,
            'update_post_term_cache' => $postType === 'post',
        ];

        $modifiedAfter = $request->get_param('modified_after');

        if (! empty($modifiedAfter)) {
            $args['date_query'] = [
                [
                    'column'    => 'post_modified_gmt',
                    'after'     => $modifiedAfter,
                    'inclusive' => true,
                ],
            ];
        }

        $query = new WP_Query($args);

        $items = array_map([PostSerializer::class, 'serialize'], $query->posts);

        return rest_ensure_response([
            'items'           => $items,
            'page'            => $page,
            'per_page'        => $perPage,
            'total'           => (int) $query->found_posts,
            'total_pages'     => (int) $query->max_num_pages,
            'has_more'        => $page < (int) $query->max_num_pages,
            'server_time_gmt' => gmdate('c'),
        ]);
    }
}

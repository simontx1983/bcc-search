<?php

namespace BCC\Search\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\DB\DB;

class SearchController
{
    const NAMESPACE    = 'bcc/v1';
    const ROUTE        = '/search';
    const LIMIT        = 12;
    const CAT_CACHE_KEY    = 'bcc_search_categories';
    const CAT_CACHE_TTL    = 43200; // 12 hours
    const SEARCH_CACHE_TTL  = 15;   // seconds
    const SEARCH_VERSION_KEY = 'bcc_search_cache_version';
    const RATE_LIMIT         = 10;  // max requests
    const RATE_WINDOW        = 5;   // seconds

    public function register_routes(): void
    {
        // Bust category cache whenever a page-category is saved or deleted
        add_action('save_post_peepso-page-cat', [__CLASS__, 'bust_category_cache']);
        add_action('delete_post', function (int $post_id): void {
            if (get_post_type($post_id) === 'peepso-page-cat') {
                self::bust_category_cache();
            }
        });

        // Bust search cache whenever a PeepSo page is saved or deleted
        add_action('save_post_peepso-page', [__CLASS__, 'bust_search_cache']);
        add_action('delete_post', function (int $post_id): void {
            if (get_post_type($post_id) === 'peepso-page') {
                self::bust_search_cache();
            }
        });

        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_search'],
            'permission_callback' => '__return_true',
            'args'                => [
                'q' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                ],
                'type' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                ],
            ],
        ]);
    }

    public function handle_search(\WP_REST_Request $request)
    {
        // Rate limiting by client IP.
        $ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rate_key  = 'bcc_search_rate_' . md5($ip);
        $hits      = (int) get_transient($rate_key);

        if ($hits >= self::RATE_LIMIT) {
            return new \WP_Error(
                'rate_limit_exceeded',
                'Too many requests. Please wait a few seconds.',
                ['status' => 429]
            );
        }

        set_transient($rate_key, $hits + 1, self::RATE_WINDOW);

        global $wpdb;

        $q    = trim($request->get_param('q'));
        $type = trim($request->get_param('type')); // category slug, e.g. "validators"

        // Require at least 2 chars to search
        if (strlen($q) < 2) {
            return rest_ensure_response(['results' => [], 'categories' => $this->get_categories()]);
        }

        // Return cached results if available.
        $cache_version = get_option( self::SEARCH_VERSION_KEY, 1 );
        $cache_key     = 'bcc_search_' . md5( $q . '|' . $type . '|' . $cache_version );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return rest_ensure_response( $cached );
        }

        $posts_table      = $wpdb->posts;
        $page_cat_table   = $wpdb->prefix . 'peepso_page_categories';
        $scores_table     = DB::table('trust_page_scores');

        $like = '%' . $wpdb->esc_like($q) . '%';

        // Build category join clause when a type is selected
        $cat_join  = '';
        $cat_where = '';
        if ($type !== '') {
            $cat_join = "
                INNER JOIN {$page_cat_table} pc  ON pc.pm_page_id = p.ID
                INNER JOIN {$posts_table}    cat ON cat.ID = pc.pm_cat_id
                                                 AND cat.post_type = 'peepso-page-cat'
                                                 AND cat.post_status = 'publish'
            ";
            $cat_where = $wpdb->prepare("AND cat.post_name = %s", $type);
        }

        // Left join to get one category per page (the first one found)
        $cat_label_join = "
            LEFT JOIN {$page_cat_table}  pcl  ON pcl.pm_page_id = p.ID
            LEFT JOIN {$posts_table}     catl ON catl.ID = pcl.pm_cat_id
                                              AND catl.post_type = 'peepso-page-cat'
                                              AND catl.post_status = 'publish'
        ";

        $sql = $wpdb->prepare(
            "SELECT
                p.ID,
                p.post_title,
                p.post_name,
                ts.total_score,
                ts.reputation_tier,
                MIN(catl.post_title) AS category_name,
                MIN(catl.post_name)  AS category_slug
             FROM {$posts_table} p
             {$cat_join}
             LEFT JOIN {$scores_table} ts ON ts.page_id = p.ID
             {$cat_label_join}
             WHERE p.post_type   = 'peepso-page'
               AND p.post_status = 'publish'
               AND p.post_title LIKE %s
               {$cat_where}
             GROUP BY p.ID
             ORDER BY COALESCE(ts.total_score, 0) DESC
             LIMIT %d",
            $like,
            self::LIMIT
        );

        $rows = $wpdb->get_results($sql);

        if ($wpdb->last_error) {
            return new \WP_REST_Response(
                ['code' => 'db_error', 'message' => 'A database error occurred. Please try again.'],
                500
            );
        }

        $results = [];

        // Batch-fetch all avatar hashes in one query to avoid N+1.
        $ids          = array_map( 'intval', array_column( (array) $rows, 'ID' ) );
        $hashes_by_id = [];
        if ( ! empty( $ids ) ) {
            $id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $meta_rows       = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                     WHERE meta_key = 'peepso_page_avatar_hash'
                     AND post_id IN ({$id_placeholders})",
                    ...$ids
                )
            );
            foreach ( $meta_rows as $m ) {
                $hashes_by_id[ (int) $m->post_id ] = $m->meta_value;
            }
        }

        // Pre-compute URL base and asset paths once instead of per-row.
        $pages_url_base = null;
        $peepso_uri     = '';
        $default_avatar = '';
        if ( class_exists( 'PeepSo' ) ) {
            $base           = \PeepSo::get_page( 'pages' );
            $pages_url_base = $base ? trailingslashit( $base ) : null;
            $peepso_uri     = \PeepSo::get_peepso_uri();
            $default_avatar = \PeepSo::get_asset( 'images/avatar/page.png' );
        }

        foreach ($rows as $row) {
            $pid = (int) $row->ID;

            $hash   = $hashes_by_id[ $pid ] ?? '';
            $avatar = $hash
                ? $peepso_uri . 'pages/' . $pid . '/' . $hash . '-avatar-full.jpg'
                : $default_avatar;

            $url = $pages_url_base
                ? $pages_url_base . $row->post_name . '/'
                : home_url( '/pages/' . $row->post_name . '/' );

            $results[] = [
                'id'            => $pid,
                'title'         => $row->post_title,
                'url'           => $url,
                'avatar'        => $avatar,
                'score'         => $row->total_score !== null ? (int) $row->total_score : null,
                'tier'          => $row->reputation_tier ?? null,
                'category'      => $row->category_name ?? null,
                'category_slug' => $row->category_slug ?? null,
            ];
        }

        $response = [
            'results'    => $results,
            'categories' => $this->get_categories(),
        ];

        set_transient( $cache_key, $response, self::SEARCH_CACHE_TTL );

        return rest_ensure_response( $response );
    }

    // ----------------------------------------------------------------

    private function get_page_avatar(int $page_id): string
    {
        if (class_exists('PeepSoPage')) {
            $page = new \PeepSoPage($page_id);
            if (method_exists($page, 'get_avatar_url_full')) {
                return (string) $page->get_avatar_url_full();
            }
        }

        // Fallback: check post meta directly (same logic PeepSoPage uses)
        global $wpdb;
        $hash = get_post_meta($page_id, 'peepso_page_avatar_hash', true);
        $dir  = \PeepSo::get_peepso_uri() . 'pages/' . $page_id . '/';
        if ($hash) {
            return $dir . $hash . '-avatar-full.jpg';
        }

        return \PeepSo::get_asset('images/avatar/page.png');
    }

    private function get_page_url(string $post_name): string
    {
        if (class_exists('PeepSo')) {
            $pages_url = \PeepSo::get_page('pages');
            if ($pages_url) {
                return trailingslashit($pages_url) . $post_name . '/';
            }
        }

        // WordPress permalink fallback
        $post = get_page_by_path($post_name, OBJECT, 'peepso-page');
        if ($post) {
            return get_permalink($post->ID);
        }

        return home_url('/pages/' . $post_name . '/');
    }

    /**
     * Returns available category options for the type dropdown.
     * Always includes an "All" entry at index 0.
     * Result is cached in a transient for CAT_CACHE_TTL seconds.
     */
    private function get_categories(): array
    {
        $cached = get_transient(self::CAT_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $cats = get_posts([
            'post_type'      => 'peepso-page-cat',
            'post_status'    => 'publish',
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'posts_per_page' => -1,
            'fields'         => 'all',
        ]);

        $options = [['slug' => '', 'name' => 'All Types']];
        foreach ($cats as $cat) {
            $options[] = [
                'slug' => $cat->post_name,
                'name' => $cat->post_title,
            ];
        }

        set_transient(self::CAT_CACHE_KEY, $options, self::CAT_CACHE_TTL);

        return $options;
    }

    public static function bust_category_cache(): void
    {
        delete_transient(self::CAT_CACHE_KEY);
    }

    public static function bust_search_cache(): void
    {
        update_option(self::SEARCH_VERSION_KEY, time());
    }
}

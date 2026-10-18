<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class Oyiso_Comments_Protection
{
    /** @var array<string, true> Comment support removed by this feature during this request. */
    private static array $commentTypes = [];

    private readonly bool $enabled;
    private readonly bool $allTypes;
    /** @var list<string> */
    private readonly array $postTypes;
    private readonly bool $showExisting;
    private readonly bool $disableRest;
    private readonly bool $disableXmlrpc;

    /** @param array<string, mixed> $options */
    public function __construct(array $options)
    {
        $this->enabled = !empty($options['opt-disable-comments']);
        $this->allTypes = ($options['opt-disable-comments-scope'] ?? 'selected') === 'all';
        $this->postTypes = self::sanitizePostTypes($options['opt-disable-comments-post-types'] ?? []);
        $this->showExisting = !array_key_exists('opt-disable-comments-show-existing', $options)
            || !empty($options['opt-disable-comments-show-existing']);
        $this->disableRest = !empty($options['opt-disable-comments-rest']);
        $this->disableXmlrpc = !empty($options['opt-disable-comments-xmlrpc']);
    }

    /** @param array<string, mixed> $options */
    public static function register(array $options): void
    {
        $protection = new self($options);
        if (!$protection->enabled) {
            return;
        }

        add_action('init', [$protection, 'updatePostTypes'], PHP_INT_MAX);
        add_action('registered_post_type', [$protection, 'removeSupport'], PHP_INT_MAX);
        add_filter('comments_open', [$protection, 'commentsOpen'], PHP_INT_MAX, 2);
        add_filter('pings_open', [$protection, 'commentsOpen'], PHP_INT_MAX, 2);
        add_filter('pre_comment_approved', [$protection, 'approveComment'], PHP_INT_MAX, 2);
        foreach (['rest_pre_insert_comment', 'rest_pre_insert_product_review', 'woocommerce_rest_pre_insert_product_review'] as $hook) {
            add_filter($hook, [$protection, 'restComment'], PHP_INT_MAX);
        }
        add_filter('xmlrpc_methods', [$protection, 'xmlrpcMethods'], PHP_INT_MAX);
        add_filter('comments_array', [$protection, 'commentsArray'], PHP_INT_MAX, 2);
        add_filter('get_comments_number', [$protection, 'commentCount'], PHP_INT_MAX, 2);
        add_filter('comments_template', [$protection, 'commentsTemplate'], PHP_INT_MAX);
        add_filter('comments_clauses', [$protection, 'commentClauses'], PHP_INT_MAX);
        foreach (['review_count', 'rating_counts', 'average_rating'] as $property) {
            add_filter('woocommerce_product_get_' . $property, [$protection, 'productReviewData'], PHP_INT_MAX, 2);
        }
    }

    /** @return array<string, string> */
    public static function postTypeOptions(): array
    {
        $types = get_post_types(['show_ui' => true], 'objects');
        $orderTypes = function_exists('wc_get_order_types') ? wc_get_order_types() : [];
        $options = [];
        $names = array_unique(array_merge(['post', 'page', 'attachment'], array_keys($types)));
        foreach ($names as $name) {
            // WooCommerce uses comment support for internal order notes.
            if (!isset($types[$name]) || in_array($name, $orderTypes, true)) {
                continue;
            }
            if (post_type_supports($name, 'comments') || isset(self::$commentTypes[$name])) {
                $options[$name] = $types[$name]->labels->name;
            }
        }
        return $options;
    }

    /** @return list<string> */
    public static function sanitizePostTypes(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $types = [];
        foreach ($value as $type) {
            if (is_string($type) && preg_match('/^[a-z0-9_-]{1,20}$/D', $type)) {
                $types[] = $type;
            }
        }
        return array_values(array_unique($types));
    }

    public function updatePostTypes(): void
    {
        foreach (get_post_types() as $type) {
            $this->removeSupport($type);
        }
    }

    public function removeSupport(string $postType): void
    {
        if ($this->disabledType($postType)) {
            // Keep disabled types selectable after their editor support has been removed.
            if (post_type_supports($postType, 'comments')) {
                self::$commentTypes[$postType] = true;
            }
            remove_post_type_support($postType, 'comments');
            remove_post_type_support($postType, 'trackbacks');
        }
    }

    public function commentsOpen(bool $open, int $postId): bool
    {
        return $this->disabledPost($postId) ? false : $open;
    }

    /** @param array<string, mixed> $data */
    public function approveComment(int|string|WP_Error $approved, array $data): int|string|WP_Error
    {
        if ($approved instanceof WP_Error || ($data['comment_type'] ?? '') === 'order_note') {
            return $approved;
        }
        if ($this->disabledPost(self::postId($data['comment_post_ID'] ?? null))) {
            return self::error();
        }
        return $approved;
    }

    /**
     * @param array<string, mixed>|WP_Error $data
     * @return array<string, mixed>|WP_Error
     */
    public function restComment(array|WP_Error $data): array|WP_Error
    {
        if ($data instanceof WP_Error) {
            return $data;
        }
        if ($this->disableRest || $this->disabledPost(self::postId($data['comment_post_ID'] ?? null))) {
            return self::error();
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $methods
     * @return array<string, mixed>
     */
    public function xmlrpcMethods(array $methods): array
    {
        if ($this->disableXmlrpc) {
            unset($methods['wp.newComment'], $methods['pingback.ping']);
        }
        return $methods;
    }

    /**
     * @param array<WP_Comment> $comments
     * @return array<WP_Comment>
     */
    public function commentsArray(array $comments, int $postId): array
    {
        return $this->hideExisting() && $this->disabledPost($postId) ? [] : $comments;
    }

    public function commentCount(int|string $count, int $postId): int|string
    {
        return $this->hideExisting() && $this->disabledPost($postId) ? 0 : $count;
    }

    public function commentsTemplate(string $template): string
    {
        return $this->hideExisting() && $this->disabledPost((int) get_the_ID())
            ? __DIR__ . '/comments-empty.php' : $template;
    }

    /**
     * @param array<string, string> $clauses
     * @return array<string, string>
     */
    public function commentClauses(array $clauses): array
    {
        if (!$this->hideExisting() || (!$this->allTypes && $this->postTypes === [])) {
            return $clauses;
        }
        global $wpdb;
        $condition = '1=1';
        if (!$this->allTypes) {
            $placeholders = implode(', ', array_fill(0, count($this->postTypes), '%s'));
            $condition = $wpdb->prepare(
                "EXISTS (SELECT 1 FROM %i AS oyiso_comment_post WHERE oyiso_comment_post.ID = %i.comment_post_ID AND oyiso_comment_post.post_type IN ($placeholders))",
                $wpdb->posts,
                $wpdb->comments,
                ...$this->postTypes
            );
        }
        // WooCommerce order notes are operational records, not public comments.
        $clauses['where'] = ($clauses['where'] ?? '') . $wpdb->prepare(
            " AND (%i.comment_type = %s OR NOT ($condition))",
            $wpdb->comments,
            'order_note'
        );
        return $clauses;
    }

    /**
     * @param array<int, int>|int|string $value
     * @return array<int, int>|int|string
     */
    public function productReviewData(array|int|string $value, WC_Product $product): array|int|string
    {
        if (!$this->hideExisting() || !$this->disabledPost($product->get_id())) {
            return $value;
        }
        return is_array($value) ? [] : (is_string($value) ? '0' : 0);
    }

    private function hideExisting(): bool
    {
        return $this->enabled && !$this->showExisting && !is_admin()
            && !(defined('REST_REQUEST') && REST_REQUEST)
            && !(defined('XMLRPC_REQUEST') && XMLRPC_REQUEST);
    }

    private function disabledPost(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }
        $type = get_post_type($postId);
        return is_string($type) && $this->disabledType($type);
    }

    private function disabledType(string $type): bool
    {
        return $this->enabled && ($this->allTypes || in_array($type, $this->postTypes, true));
    }

    private static function postId(mixed $value): int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? max(0, (int) $value) : 0;
    }

    private static function error(): WP_Error
    {
        return new WP_Error('oyiso_comments_disabled', __('Sorry, comments are closed for this item.'), ['status' => 403]);
    }
}

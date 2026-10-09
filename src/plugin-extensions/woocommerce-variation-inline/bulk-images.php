<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * @phpstan-type CoverRow array{variation_id: int, image_id: int, gallery_ids: list<int>}
 * @phpstan-type CoverSelection array{variation_id: int, expected_image_id: int}
 * @phpstan-type ClearResult array{variation_id: int, success: bool, message: string, gallery_ids?: list<int>, gallery_html?: string}
 */
final class Oyiso_WC_Variation_Bulk_Images
{
    public const AJAX_ACTION = 'oyiso_wc_variation_bulk_clear_covers';

    public static function init(): void
    {
        if (Oyiso_WC_Variation_Inline::isSkuBatchEnabled()) {
            add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'ajaxClearCovers']);
        }
    }

    /** @return list<CoverRow> */
    public static function preview(WC_Product_Variable $product): array
    {
        $rows = [];
        $ids = get_posts([
            'post_parent' => $product->get_id(),
            'post_type' => 'product_variation',
            'post_status' => ['publish', 'private', 'draft'],
            'numberposts' => -1,
            'orderby' => ['menu_order' => 'ASC', 'ID' => 'ASC'],
            'fields' => 'ids',
        ]);
        foreach ($ids as $id) {
            $variation = wc_get_product($id);
            if (!$variation instanceof WC_Product_Variation || $variation->get_parent_id() !== $product->get_id()) {
                continue;
            }
            $rows[] = [
                'variation_id' => $variation->get_id(),
                'image_id' => (int) $variation->get_image_id('edit'),
                'gallery_ids' => self::displayGalleryIds($variation),
            ];
        }

        return $rows;
    }

    /**
     * Clear only saved independent covers, retaining galleries and other fields.
     * Each selection is checked again so a newer cover is never overwritten.
     *
     * @param list<CoverSelection> $selections
     * @return list<ClearResult>|WP_Error
     */
    public static function clear(WC_Product_Variable $product, array $selections): array|WP_Error
    {
        if ($selections === [] || count($selections) > 20) {
            return new WP_Error('batch', '每次最多清除 20 个变体封面，请分批处理。');
        }
        $results = [];
        $seen = [];
        foreach ($selections as $selection) {
            $id = $selection['variation_id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $variation = wc_get_product($id);
            if (!$variation instanceof WC_Product_Variation || $variation->get_parent_id() !== $product->get_id()
                || !current_user_can('edit_post', $id)) {
                $results[] = ['variation_id' => $id, 'success' => false, 'message' => '变体不存在或没有修改权限。'];
                continue;
            }
            if ($selection['expected_image_id'] <= 0 || (int) $variation->get_image_id('edit') !== $selection['expected_image_id']) {
                $results[] = ['variation_id' => $id, 'success' => false, 'message' => '封面已更改，请重新打开批量操作后重试。'];
                continue;
            }
            try {
                $variation->set_image_id(0);
                $variation->save();
                $results[] = [
                    'variation_id' => $id,
                    'success' => true,
                    'message' => '封面已清除并保存',
                    'gallery_ids' => self::displayGalleryIds($variation),
                    'gallery_html' => self::galleryMarkup($id),
                ];
            } catch (Throwable $exception) {
                $results[] = ['variation_id' => $id, 'success' => false, 'message' => '封面清除失败，请重试。'];
            }
        }

        return $results;
    }

    /** @return list<int> */
    private static function displayGalleryIds(WC_Product_Variation $variation): array
    {
        $ids = array_map('intval', $variation->get_gallery_image_ids());
        $imageId = (int) $variation->get_image_id();
        if ($imageId > 0 && !in_array($imageId, $ids, true)) {
            array_unshift($ids, $imageId);
        }

        return $ids;
    }

    private static function galleryMarkup(int $variationId): string
    {
        $post = get_post($variationId);
        if (!$post instanceof WP_Post) {
            return '';
        }
        ob_start();
        do_action('woocommerce_variation_after_upload_image', 0, get_post_meta($variationId), $post);

        return ob_get_clean() ?: '';
    }

    private static function requestText(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? sanitize_text_field((string) wp_unslash($value)) : '';
    }

    public static function ajaxClearCovers(): void
    {
        if (check_ajax_referer('oyiso_wc_variation_inline_save', 'nonce', false) === false) {
            wp_send_json_error(['message' => '页面已过期，请刷新后重试。'], 403);
        }
        $id = absint(self::requestText('product_id'));
        if (!Oyiso_WC_Variation_Inline::isSkuBatchEnabled() || !$id || !current_user_can('edit_post', $id)) {
            wp_send_json_error(['message' => '没有修改此产品的权限。'], 403);
        }
        $product = wc_get_product($id);
        if (!$product instanceof WC_Product_Variable) {
            wp_send_json_error(['message' => '请先设置可变产品并添加变体。']);
        }
        if (self::requestText('preview') === '1') {
            wp_send_json_success(['rows' => self::preview($product)]);
        }
        $input = json_decode(self::requestText('selections'), true);
        $selections = [];
        if (!is_array($input) || !array_is_list($input) || $input === [] || count($input) > 20) {
            wp_send_json_error(['message' => '清除数据无效，请重新打开批量操作后重试。']);
        }
        foreach ($input as $selection) {
            if (!is_array($selection) || !isset($selection['variation_id'], $selection['expected_image_id'])
                || !is_int($selection['variation_id']) || !is_int($selection['expected_image_id'])
                || $selection['variation_id'] <= 0 || $selection['expected_image_id'] <= 0) {
                wp_send_json_error(['message' => '清除数据无效，请重新打开批量操作后重试。']);
            }
            $selections[] = ['variation_id' => $selection['variation_id'], 'expected_image_id' => $selection['expected_image_id']];
        }
        $results = self::clear($product, $selections);
        if (is_wp_error($results)) {
            wp_send_json_error(['message' => $results->get_error_message()]);
        }
        wp_send_json_success(['results' => $results]);
    }
}

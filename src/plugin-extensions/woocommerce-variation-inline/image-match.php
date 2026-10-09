<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

require_once __DIR__ . '/image-matcher.php';

/**
 * @phpstan-type GalleryImage array{id: int, filename: string, url: string}
 * @phpstan-type ImageData array{id: int, filename: string, url: string, ambiguous: bool}
 * @phpstan-type PreviewRow array{id: int, label: string, value: string, separator_invalid: bool, current_image_id: int, current_image_url: string, current_gallery_ids: list<int>, candidates: list<ImageData>, suggested_image_id: int}
 * @phpstan-type PreviewData array{attribute: string, attributes: list<array{id: string, label: string}>, gallery: list<GalleryImage>, rows: list<PreviewRow>}
 * @phpstan-type Selection array{variation_id: int, image_id: int, expected_image_id: int}
 * @phpstan-type SaveResult array{variation_id: int, success: bool, message: string, image_id?: int, url?: string, gallery_html?: string}
 */
final class Oyiso_WC_Variation_Image_Match
{
    private const OPTION = 'oyiso_wc_variation_image_match_enabled';
    private const NONCE = 'oyiso_wc_variation_image_match';
    private const PREVIEW_ACTION = 'oyiso_wc_variation_image_match_preview';
    private const APPLY_ACTION = 'oyiso_wc_variation_image_match_apply';

    public static function init(): void
    {
        if (!self::isEnabled()) {
            return;
        }
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('admin_footer-post.php', [self::class, 'renderTemplate']);
        add_action('admin_footer-post-new.php', [self::class, 'renderTemplate']);
        add_action('wp_ajax_' . self::PREVIEW_ACTION, [self::class, 'ajaxPreview']);
        add_action('wp_ajax_' . self::APPLY_ACTION, [self::class, 'ajaxApply']);
    }

    public static function isEnabled(): bool
    {
        $options = get_option('oyiso', []);
        if (!is_array($options)) {
            return false;
        }
        $quickOps = $options['oyiso_wc_variation_quick_ops'] ?? [];

        return is_array($quickOps) && !empty($quickOps[self::OPTION]);
    }

    public static function enqueueAssets(string $hook): void
    {
        $screen = get_current_screen();
        if (!in_array($hook, ['post.php', 'post-new.php'], true) || !$screen || $screen->post_type !== 'product') {
            return;
        }
        wp_enqueue_script('oyiso-variation-image-match', plugins_url('assets/image-match.js', __FILE__), ['jquery'], (string) filemtime(__DIR__ . '/assets/image-match.js'), true);
        wp_enqueue_style('oyiso-variation-image-match', plugins_url('assets/image-match.css', __FILE__), [], (string) filemtime(__DIR__ . '/assets/image-match.css'));
        wp_localize_script('oyiso-variation-image-match', 'oyisoVariationImageMatch', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'preview_action' => self::PREVIEW_ACTION,
            'apply_action' => self::APPLY_ACTION,
        ]);
    }

    /** @return list<array{id: string, label: string}> */
    private static function attributes(WC_Product_Variable $product): array
    {
        $attributes = [];
        foreach ($product->get_attributes() as $key => $definition) {
            if ($definition->get_variation()) {
                $attributes[] = ['id' => $key, 'label' => wc_attribute_label($definition->get_name(), $product)];
            }
        }

        return $attributes;
    }

    /**
     * @param list<int> $galleryIds
     * @return PreviewData|WP_Error
     */
    public static function preview(WC_Product_Variable $product, string $attribute, array $galleryIds, bool $multiFlavor = false, string $separator = ''): array|WP_Error
    {
        $separator = trim($separator);
        $customSeparator = $multiFlavor && $separator !== '';
        $attributes = self::attributes($product);
        $attribute = $attribute !== '' ? $attribute : ($attributes[0]['id'] ?? '');
        if (!in_array($attribute, array_column($attributes, 'id'), true)) {
            return new WP_Error('attribute', '请先添加用于变体的属性。');
        }
        $images = self::getImages($galleryIds);
        if ($images === []) {
            return new WP_Error('gallery', '请先在右侧产品图库添加图片，再进行匹配。');
        }

        $variations = [];
        $values = [];
        $variationIds = get_posts([
            'post_parent' => $product->get_id(),
            'post_type' => 'product_variation',
            'post_status' => ['publish', 'private', 'draft'],
            'numberposts' => -1,
            'orderby' => ['menu_order' => 'ASC', 'ID' => 'ASC'],
            'fields' => 'ids',
        ]);
        foreach ($variationIds as $variationId) {
            $variation = wc_get_product($variationId);
            if (!$variation instanceof WC_Product_Variation || $variation->get_parent_id() !== $product->get_id()) {
                continue;
            }
            $variations[] = $variation;
            $value = $variation->get_attributes()[$attribute] ?? '';
            if ($value !== '') {
                $label = self::attributeValueLabel($attribute, $value);
                // A custom separator applies to the visible attribute name, not its sanitized slug.
                $values['value:' . $value] = $customSeparator ? [$label] : [$label, $value];
            }
        }
        if ($variations === []) {
            return new WP_Error('variations', '请先生成或添加变体，再进行匹配。');
        }
        $matches = Oyiso_Variation_Image_Matcher::match($values, $images, $multiFlavor, $separator);
        $rows = [];
        foreach ($variations as $variation) {
            $value = $variation->get_attributes()[$attribute] ?? '';
            $match = $matches['value:' . $value] ?? ['matches' => [], 'ambiguous' => []];
            $candidates = [];
            foreach (['matches', 'ambiguous'] as $bucket) {
                foreach ($match[$bucket] as $image) {
                    $candidates[] = ['id' => $image->id, 'filename' => $image->filename, 'url' => $image->url, 'ambiguous' => $bucket === 'ambiguous'];
                }
            }
            $imageId = (int) $variation->get_image_id('edit');
            $currentGalleryIds = self::displayGalleryIds($variation);
            $label = self::attributeValueLabel($attribute, $value);
            $rows[] = [
                'id' => $variation->get_id(),
                'label' => self::variationLabel($variation, $product),
                'value' => $label,
                'separator_invalid' => $customSeparator && $value !== '' && !Oyiso_Variation_Image_Matcher::canSplitFlavors($label, $separator),
                'current_image_id' => $imageId,
                'current_image_url' => $imageId > 0 ? (wp_get_attachment_image_url($imageId, 'thumbnail') ?: '') : '',
                'current_gallery_ids' => $currentGalleryIds,
                'candidates' => $candidates,
                'suggested_image_id' => $match['matches'][0]->id ?? 0,
            ];
        }

        $gallery = [];
        foreach ($images as $image) {
            $gallery[] = ['id' => $image->id, 'filename' => $image->filename, 'url' => $image->url];
        }

        return ['attribute' => $attribute, 'attributes' => $attributes, 'gallery' => $gallery, 'rows' => $rows];
    }

    /**
     * Save only the cover through WC CRUD. Other variation fields and gallery
     * images are retained; a stale preview cannot overwrite a newer cover.
     *
     * @param list<int> $galleryIds
     * @param list<Selection> $selections
     * @return list<SaveResult>|WP_Error
     */
    public static function apply(WC_Product_Variable $product, string $attribute, array $galleryIds, array $selections, bool $overwrite, bool $multiFlavor = false, string $separator = ''): array|WP_Error
    {
        if (count($selections) > 20) {
            return new WP_Error('batch', '每次最多处理 20 个变体，请分批应用。');
        }
        $preview = self::preview($product, $attribute, $galleryIds, $multiFlavor, $separator);
        if (is_wp_error($preview)) {
            return $preview;
        }
        $rows = array_column($preview['rows'], null, 'id');
        $allowedImages = array_column($preview['gallery'], 'id');
        $results = [];
        $seen = [];
        foreach ($selections as $selection) {
            $id = $selection['variation_id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $row = $rows[$id] ?? null;
            $error = '';
            if ($row === null || !current_user_can('edit_post', $id)) {
                $error = '变体不存在或没有修改权限。';
            } elseif ($row['current_image_id'] !== $selection['expected_image_id']) {
                $error = '封面已更改，请重新识别。';
            } elseif (!$overwrite && $row['current_image_id'] > 0) {
                $error = '已有封面，已跳过。';
            } elseif (!in_array($selection['image_id'], $allowedImages, true)) {
                $error = '图片不在当前产品图库中或无权使用，请重新识别。';
            }
            if ($error !== '') {
                $results[] = ['variation_id' => $id, 'success' => false, 'message' => $error];
                continue;
            }
            $variation = wc_get_product($id);
            if (!$variation instanceof WC_Product_Variation) {
                $results[] = ['variation_id' => $id, 'success' => false, 'message' => '变体不存在。'];
                continue;
            }
            try {
                $variation->set_image_id($selection['image_id']);
                $variation->save();
                $results[] = [
                    'variation_id' => $id,
                    'success' => true,
                    'message' => '封面已保存',
                    'image_id' => $selection['image_id'],
                    'url' => wp_get_attachment_image_url($selection['image_id'], 'thumbnail') ?: '',
                    'gallery_html' => self::galleryMarkup($id),
                ];
            } catch (Throwable $exception) {
                $results[] = ['variation_id' => $id, 'success' => false, 'message' => '保存失败，请重新识别后重试。'];
            }
        }

        return $results;
    }

    /**
     * @param list<int> $galleryIds
     * @return list<Oyiso_Variation_Image_Candidate>
     */
    private static function getImages(array $galleryIds): array
    {
        $images = [];
        foreach (array_unique($galleryIds) as $id) {
            if ($id <= 0 || !wp_attachment_is_image($id) || !current_user_can('read_post', $id)) {
                continue;
            }
            $file = get_attached_file($id, true);
            if (!is_string($file) || $file === '') {
                continue;
            }
            $images[] = new Oyiso_Variation_Image_Candidate($id, wp_basename($file), wp_get_attachment_image_url($id, 'thumbnail') ?: '');
        }

        return $images;
    }

    private static function attributeValueLabel(string $attribute, string $value): string
    {
        if (taxonomy_exists($attribute)) {
            $term = get_term_by('slug', $value, $attribute);
            if ($term instanceof WP_Term) {
                $value = $term->name;
            }
        }

        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function variationLabel(WC_Product_Variation $variation, WC_Product_Variable $product): string
    {
        $labels = [];
        foreach ($variation->get_attributes() as $key => $value) {
            $labels[] = $value !== '' ? self::attributeValueLabel($key, $value) : '任意 ' . wc_attribute_label($key, $product);
        }

        return implode(' / ', $labels);
    }

    /**
     * Match WC's rendered gallery, including inherited images and its display order.
     * The saved cover still uses edit context to distinguish an inherited image.
     *
     * @return list<int>
     */
    private static function displayGalleryIds(WC_Product_Variation $variation): array
    {
        $ids = [];
        foreach ($variation->get_gallery_image_ids() as $id) {
            $ids[] = (int) $id;
        }
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

    private static function requestedProduct(): WC_Product_Variable|WP_Error
    {
        $id = absint(self::requestText('product_id'));
        if (!self::isEnabled() || !$id || !current_user_can('edit_post', $id)) {
            return new WP_Error('permission', '没有修改此产品的权限。');
        }
        $product = wc_get_product($id);

        return $product instanceof WC_Product_Variable ? $product : new WP_Error('product', '请先设置可变产品并添加变体。');
    }

    private static function requestText(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? sanitize_text_field((string) wp_unslash($value)) : '';
    }

    private static function requestSeparator(): string
    {
        $value = $_POST['separator'] ?? '';

        // Used as a literal delimiter, including symbols that HTML sanitization removes.
        return is_string($value) ? trim(wp_check_invalid_utf8((string) wp_unslash($value))) : '';
    }

    /** @return list<int> */
    private static function requestGalleryIds(): array
    {
        $values = $_POST['gallery_ids'] ?? [];
        $ids = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if ((is_int($value) || is_string($value)) && ctype_digit((string) $value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function verifyNonce(): void
    {
        if (check_ajax_referer(self::NONCE, 'nonce', false) === false) {
            wp_send_json_error(['message' => '页面已过期，请刷新后重试。'], 403);
        }
    }

    public static function ajaxPreview(): void
    {
        self::verifyNonce();
        $product = self::requestedProduct();
        if (is_wp_error($product)) {
            wp_send_json_error(['message' => $product->get_error_message()], 403);
        }
        $attribute = self::requestText('attribute');
        $preview = self::preview($product, $attribute, self::requestGalleryIds(), self::requestText('multi_flavor') === '1', self::requestSeparator());
        if (is_wp_error($preview)) {
            $attributes = self::attributes($product);
            wp_send_json_error([
                'message' => $preview->get_error_message(),
                'attributes' => $attributes,
                'attribute' => in_array($attribute, array_column($attributes, 'id'), true) ? $attribute : ($attributes[0]['id'] ?? ''),
            ]);
        }
        wp_send_json_success($preview);
    }

    public static function ajaxApply(): void
    {
        self::verifyNonce();
        $product = self::requestedProduct();
        if (is_wp_error($product)) {
            wp_send_json_error(['message' => $product->get_error_message()], 403);
        }
        $input = json_decode(self::requestText('selections'), true);
        $selections = [];
        if (!is_array($input) || $input === [] || count($input) > 20) {
            wp_send_json_error(['message' => '请选择要应用的变体。']);
        }
        foreach ($input as $selection) {
            if (!is_array($selection) || !isset($selection['variation_id'], $selection['image_id'], $selection['expected_image_id'])
                || !is_int($selection['variation_id']) || !is_int($selection['image_id']) || !is_int($selection['expected_image_id'])
                || $selection['variation_id'] <= 0 || $selection['image_id'] <= 0 || $selection['expected_image_id'] < 0) {
                wp_send_json_error(['message' => '匹配数据无效，请重新识别。']);
            }
            $selections[] = ['variation_id' => $selection['variation_id'], 'image_id' => $selection['image_id'], 'expected_image_id' => $selection['expected_image_id']];
        }
        $results = self::apply($product, self::requestText('attribute'), self::requestGalleryIds(), $selections, self::requestText('overwrite') === '1', self::requestText('multi_flavor') === '1', self::requestSeparator());
        if (is_wp_error($results)) {
            wp_send_json_error(['message' => $results->get_error_message()]);
        }
        wp_send_json_success(['results' => $results]);
    }

    public static function renderTemplate(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'product') {
            return;
        }
        ?>
        <div id="oyiso-vim-modal" class="oyiso-vim-backdrop" hidden>
            <div class="oyiso-vim-dialog" role="dialog" aria-modal="true" aria-labelledby="oyiso-vim-title" tabindex="-1">
                <div class="oyiso-vim-header">
                    <h2 id="oyiso-vim-title">从产品图库匹配封面</h2>
                    <button type="button" class="oyiso-vim-close" aria-label="关闭">&times;</button>
                </div>
                <div class="oyiso-vim-body">
                    <p class="oyiso-vim-description">按图库文件名推荐封面，也可为每个变体手动选择一张图库图片。</p>
                    <div class="oyiso-vim-controls">
                        <label for="oyiso-vim-attribute">匹配属性
                            <select id="oyiso-vim-attribute"></select>
                        </label>
                        <label><input type="checkbox" id="oyiso-vim-multi-flavor"> 多口味</label>
                        <label class="oyiso-vim-overwrite-label"><input type="checkbox" id="oyiso-vim-overwrite"> 覆盖已有封面</label>
                        <button type="button" class="button oyiso-vim-rescan">重新识别</button>
                    </div>
                    <div class="oyiso-vim-multi-flavor-settings" hidden>
                        <label for="oyiso-vim-separator">自定义分隔符
                            <input type="text" id="oyiso-vim-separator" placeholder="留空使用预设" autocomplete="off" aria-describedby="oyiso-vim-separator-hint oyiso-vim-message" disabled>
                        </label>
                        <span id="oyiso-vim-separator-hint">预设 /、|、+、&amp;、逗号、分号、顿号（含全角）；填写后仅按该分隔符拆分，无法拆分时不自动匹配。口味组合顺序不限。</span>
                    </div>
                    <p id="oyiso-vim-message" role="status" aria-live="polite"></p>
                    <div class="oyiso-vim-table-wrap">
                        <table class="widefat oyiso-vim-table">
                            <thead><tr>
                                <td class="check-column"><input type="checkbox" id="oyiso-vim-select-all" aria-label="选择全部可应用的变体"></td>
                                <th scope="col">变体</th><th scope="col">当前封面</th><th scope="col">选择封面</th>
                            </tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <div class="oyiso-vim-footer">
                    <span id="oyiso-vim-selection-count"></span>
                    <button type="button" class="button oyiso-vim-cancel">取消</button>
                    <button type="button" class="button button-primary oyiso-vim-apply" disabled>批量应用并保存</button>
                </div>
            </div>
        </div>
        <?php
    }
}

Oyiso_WC_Variation_Image_Match::init();

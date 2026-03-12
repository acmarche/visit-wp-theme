<?php

namespace VisitMarche\ThemeWp\Inc;

use WP_Term;

class CategoryMetaData
{
    public const KEY_IMAGE = 'visit_category_image';
    public const KEY_VIDEO = 'visit_category_video';
    public const KEY_ICONE = 'visit_category_icone';
    public const KEY_COLOR = 'visit_category_color';

    public function __construct()
    {
        add_action('admin_enqueue_scripts', fn(string $hook) => $this->enqueueMedia($hook));
        add_action('category_add_form_fields', fn() => $this->addFormFields());
        add_action('category_edit_form_fields', fn(WP_Term $term) => $this->editFormFields($term));
        add_action('created_category', fn(int $termId) => $this->saveMetas($termId));
        add_action('edited_category', fn(int $termId) => $this->saveMetas($termId));

        foreach ([self::KEY_IMAGE, self::KEY_VIDEO, self::KEY_ICONE, self::KEY_COLOR] as $key) {
            register_term_meta('category', $key, [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
            ]);
        }
    }

    private function enqueueMedia(string $hook): void
    {
        if (!in_array($hook, ['term.php', 'edit-tags.php'], true)) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || $screen->taxonomy !== 'category') {
            return;
        }
        wp_enqueue_media();
    }

    private function addFormFields(): void
    {
        ?>
        <div class="form-field">
            <label><?php esc_html_e('Image de fond', 'flavor'); ?></label>
            <?php $this->renderMediaField(self::KEY_IMAGE, 0, 'image'); ?>
        </div>
        <div class="form-field">
            <label><?php esc_html_e('Vidéo de fond', 'flavor'); ?></label>
            <?php $this->renderMediaField(self::KEY_VIDEO, 0, 'video'); ?>
        </div>
        <div class="form-field">
            <label for="<?php echo esc_attr(self::KEY_ICONE); ?>"><?php esc_html_e('Icône', 'flavor'); ?></label>
            <input type="text" name="<?php echo esc_attr(self::KEY_ICONE); ?>" id="<?php echo esc_attr(self::KEY_ICONE); ?>" value="">
        </div>
        <div class="form-field">
            <label for="<?php echo esc_attr(self::KEY_COLOR); ?>"><?php esc_html_e('Couleur (classe CSS)', 'flavor'); ?></label>
            <input type="text" name="<?php echo esc_attr(self::KEY_COLOR); ?>" id="<?php echo esc_attr(self::KEY_COLOR); ?>" value="">
        </div>
        <?php
    }

    private function editFormFields(WP_Term $term): void
    {
        $imageId = (int) get_term_meta($term->term_id, self::KEY_IMAGE, true);
        $videoId = (int) get_term_meta($term->term_id, self::KEY_VIDEO, true);
        $icone = get_term_meta($term->term_id, self::KEY_ICONE, true);
        $color = get_term_meta($term->term_id, self::KEY_COLOR, true);
        ?>
        <tr class="form-field">
            <th scope="row"><label><?php esc_html_e('Image de fond', 'flavor'); ?></label></th>
            <td><?php $this->renderMediaField(self::KEY_IMAGE, $imageId, 'image'); ?></td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label><?php esc_html_e('Vidéo de fond', 'flavor'); ?></label></th>
            <td><?php $this->renderMediaField(self::KEY_VIDEO, $videoId, 'video'); ?></td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="<?php echo esc_attr(self::KEY_ICONE); ?>"><?php esc_html_e('Icône', 'flavor'); ?></label></th>
            <td>
                <input type="text" name="<?php echo esc_attr(self::KEY_ICONE); ?>" id="<?php echo esc_attr(self::KEY_ICONE); ?>"
                       value="<?php echo esc_attr($icone); ?>">
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="<?php echo esc_attr(self::KEY_COLOR); ?>"><?php esc_html_e('Couleur (classe CSS)', 'flavor'); ?></label></th>
            <td>
                <input type="text" name="<?php echo esc_attr(self::KEY_COLOR); ?>" id="<?php echo esc_attr(self::KEY_COLOR); ?>"
                       value="<?php echo esc_attr($color); ?>">
            </td>
        </tr>
        <?php
    }

    private function renderMediaField(string $key, int $attachmentId, string $type): void
    {
        $preview = '';
        if ($attachmentId) {
            if ($type === 'image') {
                $preview = wp_get_attachment_image_url($attachmentId, 'thumbnail');
            } else {
                $preview = wp_get_attachment_url($attachmentId);
            }
        }
        $btnLabel = $type === 'image' ? __('Choisir une image', 'flavor') : __('Choisir une vidéo', 'flavor');
        $mediaType = $type === 'image' ? 'image' : 'video';
        ?>
        <div class="visit-media-field" data-media-type="<?php echo esc_attr($mediaType); ?>">
            <input type="hidden" name="<?php echo esc_attr($key); ?>" class="visit-media-id" value="<?php echo esc_attr($attachmentId ?: ''); ?>">
            <div class="visit-media-preview" style="margin-bottom:8px;">
                <?php if ($attachmentId && $type === 'image' && $preview): ?>
                    <img src="<?php echo esc_url($preview); ?>" style="max-width:200px;height:auto;">
                <?php elseif ($attachmentId && $type === 'video' && $preview): ?>
                    <video src="<?php echo esc_url($preview); ?>" style="max-width:200px;" controls></video>
                <?php endif; ?>
            </div>
            <button type="button" class="button visit-media-select"><?php echo esc_html($btnLabel); ?></button>
            <button type="button" class="button visit-media-remove" <?php echo $attachmentId ? '' : 'style="display:none;"'; ?>><?php esc_html_e('Supprimer', 'flavor'); ?></button>
        </div>
        <script>
            jQuery(function ($) {
                const wrap = $('[name="<?php echo esc_js($key); ?>"]').closest('.visit-media-field');
                let frame;
                wrap.find('.visit-media-select').on('click', function (e) {
                    e.preventDefault();
                    if (frame) { frame.open(); return; }
                    frame = wp.media({
                        title: '<?php echo esc_js($btnLabel); ?>',
                        library: {type: '<?php echo esc_js($mediaType); ?>'},
                        multiple: false
                    });
                    frame.on('select', function () {
                        const attachment = frame.state().get('selection').first().toJSON();
                        wrap.find('.visit-media-id').val(attachment.id);
                        let html = '';
                        if ('<?php echo esc_js($type); ?>' === 'image') {
                            const url = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
                            html = '<img src="' + url + '" style="max-width:200px;height:auto;">';
                        } else {
                            html = '<video src="' + attachment.url + '" style="max-width:200px;" controls></video>';
                        }
                        wrap.find('.visit-media-preview').html(html);
                        wrap.find('.visit-media-remove').show();
                    });
                    frame.open();
                });
                wrap.find('.visit-media-remove').on('click', function (e) {
                    e.preventDefault();
                    wrap.find('.visit-media-id').val('');
                    wrap.find('.visit-media-preview').html('');
                    $(this).hide();
                });
            });
        </script>
        <?php
    }

    private function saveMetas(int $termId): void
    {
        $metas = [self::KEY_IMAGE, self::KEY_VIDEO, self::KEY_ICONE, self::KEY_COLOR];
        foreach ($metas as $key) {
            if (!isset($_POST[$key])) {
                continue;
            }
            $value = sanitize_text_field(wp_unslash($_POST[$key]));
            if ($value !== '') {
                update_term_meta($termId, $key, $value);
            } else {
                delete_term_meta($termId, $key);
            }
        }
    }

    public static function getImage(WP_Term $category): string
    {
        $imageId = (int) get_term_meta($category->term_id, self::KEY_IMAGE, true);
        if ($imageId) {
            $url = wp_get_attachment_image_url($imageId, 'full');
            if ($url) {
                return esc_url($url);
            }
        }

        return get_template_directory_uri() . '/assets/tartine/bg_inspirations.png';
    }

    public static function getVideo(WP_Term $category): ?string
    {
        $videoId = (int) get_term_meta($category->term_id, self::KEY_VIDEO, true);
        if ($videoId) {
            $url = wp_get_attachment_url($videoId);
            if ($url) {
                return esc_url($url);
            }
        }

        return null;
    }

    public static function getIcon(WP_Term $category): string
    {
        return get_term_meta($category->term_id, self::KEY_ICONE, true) ?: '';
    }

    public static function getColor(WP_Term $category): string
    {
        return get_term_meta($category->term_id, self::KEY_COLOR, true) ?: '';
    }
}

<?php

namespace VisitMarche\ThemeWp\Inc;

use WP_Term;

class CategorySortMetaData
{
    public const string KEY_SORT = 'visit_category_sort';

    public function __construct()
    {
        add_action('category_add_form_fields', fn() => $this->addFormFields());
        add_action('category_edit_form_fields', fn(WP_Term $term) => $this->editFormFields($term));
        add_action('created_category', fn(int $termId) => $this->saveMetas($termId));
        add_action('edited_category', fn(int $termId) => $this->saveMetas($termId));

        foreach ([self::KEY_SORT] as $key) {
            register_term_meta('category', $key, [
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
            ]);
        }
    }

    private function addFormFields(): void
    {
        ?>
        <div class="form-field">
            <label for="<?php echo esc_attr(self::KEY_SORT); ?>"><?php esc_html_e('Tri', 'flavor'); ?></label>
            <select name="<?php echo esc_attr(self::KEY_SORT); ?>" id="<?php echo esc_attr(self::KEY_SORT); ?>">
                <option value="default"><?php esc_html_e('A-Z', 'flavor'); ?></option>
                <option value="manual"><?php esc_html_e('Manuel', 'flavor'); ?></option>
            </select>
        </div>
        <?php
    }

    private function editFormFields(WP_Term $term): void
    {
        $sorting = self::getSortMethod($term->term_id);
        ?>
        <tr class="form-field">
            <th scope="row"><label><?php esc_html_e('Méthode de tri', 'flavor'); ?></label></th>
            <td>
                <select name="<?php echo esc_attr(self::KEY_SORT); ?>" id="<?php echo esc_attr(self::KEY_SORT); ?>">
                    <option value="default" <?php if ($sorting === 'default') { ?> selected <?php } ?> >
                        <?php esc_html_e('A-Z', 'flavor'); ?></option>
                    <option value="manual"
                            value="default" <?php if ($sorting === 'manual') { ?> selected <?php } ?>><?php esc_html_e(
                            'Manuel',
                            'flavor'
                        ); ?></option>
                </select>
            </td>
        </tr>
        <?php
    }

    private function saveMetas(int $termId): void
    {
        $metas = [self::KEY_SORT];
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

    public static function getSortMethod(int $term_id): ?string
    {
        return get_term_meta($term_id, self::KEY_SORT, true) ?: null;
    }
}

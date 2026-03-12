<?php

namespace VisitMarche\ThemeWp\Lib\Sort;

use WP_Error;
use WP_Post;

class SortPlugin
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'loadPages']);
        add_action('admin_enqueue_scripts', [$this, 'load_files']);
        add_action('wp_ajax_update-custom-type-order', [$this, 'saveNewsOrder']);
    }

    public static function load_files(): void
    {
        wp_enqueue_script(
            'jquery-ui-sortable',
            null,
            array("jquery", "jquery-ui-core", "interface", "jquery-ui-sortable", "wp-lists", "jquery-ui-sortable")
        );
        wp_enqueue_style(
            'marchebe-sort-style',
            get_template_directory_uri().'/assets/css/cpt.css',
            array(),
            wp_get_theme()->get('Version')
        );
    }

    /**
     * @param WP_Post[] $news
     *
     * @return  WP_Post[]
     */
    public static function trieNews(array $news): array
    {
        // dump($news);
        //obtient table avec id news id blog order
        $ordre = self::getOrdreNews();
        /*
         * je check si chaque news a un classement
         * si oui set position
         */
        array_map(
            function ($post) use ($ordre) {
                $post->order = 0;
                $needle = array_filter(
                    $ordre,
                    function ($e) use ($post) {
                        if ($post->ID == $e->id_news || $post->blod_id == $e->id_blog) {
                            return $e;
                        }

                        return null;
                    }
                );
                if (count($needle) > 0) {
                    $post->order = (int)end($needle)->order;
                }
            },
            $news
        );
        //  dump($news);
        $news = SortUtil::sortByPosition($news);

        //   dump($news);

        return $news;
    }

    public static function getOrdreNews(): array
    {
        global $wpdb;
        $query = "SELECT * FROM `news_order` ORDER BY `order` ";

        $num_rows = $wpdb->query($query);

        if ($wpdb->last_error) {

            return [];
        }

        if ($num_rows == 0) {
            return array();
        }

        return $wpdb->get_results($query);
    }

    static function saveNewsOrder(): void
    {
        global $wpdb;
        parse_str($_POST['order'], $data);
        $i = 0;
        $type = null;
        foreach ($data as $clef => $tab) :
            $vars = array();
            $varsUp = array();
            list($nul, $post_id) = explode("_", $clef);
            $blog_id = $tab[0];
            $vars["id_blog"] = $blog_id;
            $vars["id_news"] = $post_id;
            $vars["order"] = $i;
            $varsUp["order"] = $i;
            $i++;

            $where = array('id_blog' => $blog_id, 'id_news' => $post_id);

            //try update if existe
            $update = $wpdb->update('news_order', $varsUp, $where);

            if ($wpdb->last_error) {
                $error = "Impossible update. Erreur : <br />";
                echo $wpdb->last_error;
                echo $wpdb->last_query;
                $wp_error = new WP_Error(200, $error);
                echo $wp_error->get_error_message();
            } elseif ($update == 0) {
                /*
                 * check if already in table
                 */
                $query = "SELECT * FROM `news_order` WHERE `id_news` = %s AND `id_blog` = %s ";

                $num_rows = $wpdb->query($wpdb->prepare($query, $post_id, $blog_id));

                if ($wpdb->last_error) {
                    echo '<p style="color: red;">Impossible d\'obtenir les infos de la news. Erreur : <br />'.
                        $wpdb->last_error.'</p>';

                    return;
                }

                if ($num_rows == 0) {

                    $wpdb->insert('news_order', $vars, $type);

                    if ($wpdb->last_error) {

                        $error = "Impossible d'enregistrer la commande. Erreur : <br />";
                        echo $wpdb->last_error;
                        echo $wpdb->last_query;
                        $wp_error = new WP_Error(200, $error);
                        echo $wp_error->get_error_message();
                    } else {
                        // $wpdb->insert_id;
                    }
                }
            }
        endforeach;
    }
}
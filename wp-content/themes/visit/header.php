<?php

namespace VisitMarche\ThemeWp;

use VisitMarche\ThemeWp\Lib\CookieHelper;
use VisitMarche\ThemeWp\Lib\LocaleHelper;
use VisitMarche\ThemeWp\Lib\Twig;
use VisitMarche\ThemeWp\Repository\MenuRepository;

$locale = LocaleHelper::getSelectedLanguage();
?>
    <!doctype html>
<html lang="<?php echo $locale; ?>">
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="author" content="Epn">
        <meta name="author" content="Esquare">
        <link rel="profile" href="https://gmpg.org/xfn/11">
        <link rel="icon" type="image/png" href="<?php echo get_template_directory_uri() ?>/assets/images/favicon.png"/>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet"
              href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap">
        <?php wp_head(); ?>
    </head>

<body <?php body_class(); ?> id="app" data-langwp="<?= $locale ?>" data-langsf="<?= $locale ?>">
    <?php
wp_body_open();

$menu = new MenuRepository();
$items = $menu->getMenuTop($locale);
$icons = $menu->getIcons($locale);
Twig::renderPage(
        '@Visit/header/_header.html.twig',
        [
                'locale' => $locale,
                'items' => $items,
                'icons' => $icons,
                'hasNotAcceptCookie' => !CookieHelper::isAuthorizedByName(CookieHelper::$essential),
        ]
);


<?php

namespace VisitMarche\ThemeWp\Lib;

class Frankenphp
{
    public function __construct()
    {
        add_action('wp_head', [$this, 'hot_reload']);
    }

    function hot_reload(): void
    {
        ?>
        <?php if (isset($_SERVER['FRANKENPHP_HOT_RELOAD'])): ?>
        <meta name="frankenphp-hot-reload:url" content="<?= $_SERVER['FRANKENPHP_HOT_RELOAD'] ?>">
        <script src="https://cdn.jsdelivr.net/npm/idiomorph"></script>
        <script src="https://cdn.jsdelivr.net/npm/frankenphp-hot-reload/+esm" type="module"></script>
    <?php endif ?>
        <?php
    }

}

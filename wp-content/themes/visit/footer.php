<?php

namespace VisitMarche\ThemeWp;

use VisitMarche\ThemeWp\Lib\LocaleHelper;
use VisitMarche\ThemeWp\Lib\Twig;
use VisitMarche\ThemeWp\Repository\MenuRepository;

$menu = new MenuRepository();
$locale = LocaleHelper::getSelectedLanguage();
$items = $menu->getMenuTop($locale);
$icones = $menu->getIcons($locale);
Twig::renderPage(
    '@Visit/_footer.html.twig',
    [
        'items' => $items,
        'icons' => $icones,
    ]
);
if (WP_DEBUG) {
    echo '
<!-- impeccable-live-start -->
<script src="http://localhost:8400/live.js"></script>
<!-- impeccable-live-end -->';
}
echo '
</body>
</html>';

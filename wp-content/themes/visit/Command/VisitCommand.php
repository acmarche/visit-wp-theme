<?php

namespace VisitMarche\ThemeWp\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use AcMarche\PivotAi\Enums\TypeOffreEnum;
use VisitMarche\ThemeWp\Inc\Theme;
use VisitMarche\ThemeWp\Repository\PivotRepository;
use VisitMarche\ThemeWp\Repository\WpRepository;

// Set up server variables for WordPress multisite CLI context
$_SERVER['HTTP_HOST'] = $_ENV['WP_URL_HOME'];

// Bootstrap WordPress to make WordPress functions available
define('WP_USE_THEMES', false);
require __DIR__.'/../../../../wp-load.php';

#[AsCommand(
    name: 'visit:do',
    description: ' ',
)]
class VisitCommand extends Command
{
    private PivotRepository $pivotRepository;
    private SymfonyStyle $io;

    protected function configure(): void
    {
        $this->setDescription('Do something');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        $this->categories();
        $this->restaurants();
        $this->accommodations();

        return Command::SUCCESS;
    }

    private function restaurants(): void
    {
        $this->pivotRepository = new PivotRepository();
        $restaurants = $this->pivotRepository->loadRestaurants();
        $parentCategoryId = Theme::CATEGORIES_RESTAURATION;

        $urns = [
            'urn:fld:cat:resto',
            'urn:fld:cat:restorap',
            'urn:fld:cat:brass',
            'urn:fld:cat:bar:vin',
            'urn:fld:cat:saldegust',
            'urn:fld:cat:trait',
            'urn:fld:cat:foodtruck',
            'urn:fld:cat:bar',
        ];

        // Step 1: Find the label for each URN from the offers data
        $urnLabels = [];
        foreach ($restaurants as $offer) {
            foreach ($offer->getClassificationLabels() as $label) {
                if (in_array($label->urn, $urns, true) && !isset($urnLabels[$label->urn])) {
                    $urnLabels[$label->urn] = $label->label;
                }
            }
        }

        // Step 2: Create WP categories and collect URN → category ID mapping
        $urnToCategoryId = [];
        foreach ($urns as $urn) {
            if (!isset($urnLabels[$urn])) {
                $this->io->warning(sprintf('No label found for URN: %s', $urn));
                continue;
            }

            $categoryName = $urnLabels[$urn];
            $categoryId = $this->findOrCreateCategory($categoryName, $parentCategoryId);
            if ($categoryId === null) {
                continue;
            }

            $urnToCategoryId[$urn] = $categoryId;
        }

        // Step 3: For each category URN, find matching offers and store their codeCgt
        foreach ($urnToCategoryId as $urn => $categoryId) {
            $codesCgt = [];
            foreach ($restaurants as $offer) {
                foreach ($offer->getClassificationLabels() as $label) {
                    if ($label->urn === $urn) {
                        $codesCgt[] = $offer->codeCgt;
                        break;
                    }
                }
            }

            update_term_meta($categoryId, WpRepository::PIVOT_REFOFFERS, $codesCgt);
            $this->io->text(sprintf('  URN %s → %d offers linked to category %d', $urn, count($codesCgt), $categoryId));
        }
    }

    private function accommodations(): void
    {
        $this->pivotRepository = new PivotRepository();
        $accommodations = $this->pivotRepository->loadAccommodations();
        $parentCategoryId = Theme::CATEGORIES_HEBERGEMENT;

        // Reset: delete pivot_ref_offers meta from parent and all children
        delete_term_meta($parentCategoryId, WpRepository::PIVOT_REFOFFERS);
        $children = get_categories(['parent' => $parentCategoryId, 'hide_empty' => false]);
        foreach ($children as $child) {
            delete_term_meta($child->term_id, WpRepository::PIVOT_REFOFFERS);
            wp_delete_category($child->term_id);
            $this->io->text(sprintf('Reset meta for child category "%s" (ID %d)', $child->name, $child->term_id));
        }

        // Merge GITE (2) and HOLIDAY_HOME (4) into a single category
        $mergedTypes = [TypeOffreEnum::GITE->value, TypeOffreEnum::HOLIDAY_HOME->value];

        // Group offers by their TypeOffre
        $accommodationTypes = TypeOffreEnum::accommodations();
        $typeToCategoryId = [];

        foreach ($accommodationTypes as $type) {
            if (in_array($type->value, $mergedTypes, true)) {
                continue;
            }

            // Get the French label from the first offer of this type
            $categoryName = null;
            foreach ($accommodations as $offer) {
                if ($offer->typeOffre !== null && $offer->typeOffre->idTypeOffre === $type->value) {
                    $categoryName = $offer->typeOffre->getLabelByLang('fr');
                    break;
                }
            }

            if ($categoryName === null) {
                continue;
            }

            $categoryId = $this->findOrCreateCategory($categoryName, $parentCategoryId);
            if ($categoryId === null) {
                continue;
            }

            $typeToCategoryId[$type->value] = $categoryId;
        }

        // Create merged "Gîte / Meublé" category for GITE + HOLIDAY_HOME
        $mergedCategoryId = $this->findOrCreateCategory('Gîte / Meublé', $parentCategoryId);
        if ($mergedCategoryId !== null) {
            foreach ($mergedTypes as $typeId) {
                $typeToCategoryId[$typeId] = $mergedCategoryId;
            }
        }

        // Link offers to their type category
        /** @var array<int, string[]> $categoryOffers */
        $categoryOffers = [];
        foreach ($accommodations as $offer) {
            if ($offer->typeOffre === null) {
                continue;
            }
            $typeId = $offer->typeOffre->idTypeOffre;
            if (!isset($typeToCategoryId[$typeId])) {
                continue;
            }
            $categoryId = $typeToCategoryId[$typeId];
            $categoryOffers[$categoryId][] = $offer->codeCgt;
        }

        foreach ($categoryOffers as $categoryId => $codesCgt) {
            update_term_meta($categoryId, WpRepository::PIVOT_REFOFFERS, $codesCgt);
            $this->io->text(
                sprintf('  %d offers linked to category %d', count($codesCgt), $categoryId)
            );
        }
    }

    private function findOrCreateCategory(string $categoryName, int $parentCategoryId): ?int
    {
        $existingTerm = term_exists($categoryName, 'category');

        if ($existingTerm) {
            $categoryId = (int)$existingTerm['term_id'];
            $this->io->text(sprintf('Category "%s" already exists (ID %d)', $categoryName, $categoryId));

            return $categoryId;
        }

        $result = wp_insert_term($categoryName, 'category', [
            'parent' => $parentCategoryId,
        ]);

        if (is_wp_error($result)) {
            $this->io->error(
                sprintf('Failed to create category "%s": %s', $categoryName, $result->get_error_message())
            );

            return null;
        }

        $categoryId = (int)$result['term_id'];
        $this->io->success(sprintf('Created category "%s" (ID %d)', $categoryName, $categoryId));

        return $categoryId;
    }

    private function specculi($label): array
    {
        if (strpos($label->urn, 'specculi')) {
            $labels[$label->urn] = [$label->label, $label->urn];
        }

        return [];
    }

    private function index(): void
    {
        $pivotRepository = new PivotRepository();
        $events = $pivotRepository->loadRestaurants();
        $labels = [];
        foreach ($events as $event) {
            $this->io->title($event->name());
            foreach ($event->getClassificationLabels() as $label) {
                $this->io->text($label);
                if (!strpos($label->urn, 'specculi')) {
                    $labels[$label->urn] = [$label->label, $label->urn];
                }
            }
            $this->io->newLine(2);
        }

        $this->io->table(
            ['Label', 'Urn'],
            $labels,
        );
    }

    private function categories(): void
    {
        $decouvrir = $this->findOrCreateCategory('Découvrir', 0);
        $toMove = [9, 10, 11, 12, 13];
        foreach ($toMove as $categoryId) {
            wp_update_term($categoryId, 'category', ['parent' => $decouvrir]);
        }
        $organiser = get_category_by_slug('sorganiser');
        if ($organiser instanceof \WP_Term) {
            $toMove = [5, 6, 84];
            foreach ($toMove as $categoryId) {
                if (!$cat =get_category($categoryId)) {
                    $this->io->warning(sprintf('Category %d does not exist', $categoryId));
                    continue;
                }
                dump($cat->name);
                wp_update_term($categoryId, 'category', ['parent' => $organiser->term_id]);
            }
        }
    }
}

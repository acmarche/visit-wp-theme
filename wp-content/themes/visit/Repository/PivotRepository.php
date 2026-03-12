<?php

namespace VisitMarche\ThemeWp\Repository;

use AcMarche\PivotAi\Api\PivotClient;
use AcMarche\PivotAi\Entity\Pivot\DateEvent;
use AcMarche\PivotAi\Entity\Pivot\Offer;
use AcMarche\PivotAi\Enums\ContentLevel;
use AcMarche\PivotAi\Enums\TypeOffreEnum;
use Carbon\Carbon;
use VisitMarche\ThemeWp\Inc\RouterPivot;
use VisitMarche\ThemeWp\Inc\Theme;
use VisitMarche\ThemeWp\Lib\Di;

readonly class PivotRepository
{
    private PivotClient $pivotClient;

    public function __construct()
    {
        $this->pivotClient = Di::getInstance()->get(PivotClient::class);
    }

    /**
     * @return array<int, Offer>
     */
    public function loadEvents(
        bool $skip = false
    ): array {
        $response = $this->pivotClient->fetchOffersByCriteria();
        $today = Carbon::today();

        $data = [];
        foreach ($response->getOffers() as $offer) {
            if ($offer->typeOffre->idTypeOffre != TypeOffreEnum::EVENT->value) {
                continue;
            }

            // Remove outdated dates from each event
            $offer->dates = array_values(
                array_filter(
                    $offer->dates,
                    fn(DateEvent $date) => ($date->endDate !== null && Carbon::parse($date->endDate)->gte($today))
                        || ($date->startDate !== null && Carbon::parse($date->startDate)->gte($today)),
                )
            );

            // Skip events with no upcoming dates
            if ($offer->dates === []) {
                continue;
            }

            // When skip is true, remove events where all dates span more than 10 days
            if ($skip) {
                $offer->dates = array_values(
                    array_filter(
                        $offer->dates,
                        function (DateEvent $date) {
                            if ($date->startDate === null || $date->endDate === null) {
                                return true;
                            }

                            return Carbon::parse($date->startDate)->diffInDays(Carbon::parse($date->endDate)) <= 10;
                        },
                    )
                );

                if ($offer->dates === []) {
                    continue;
                }
            }

            // Sort dates within the event by startDate ASC
            usort($offer->dates, fn(DateEvent $a, DateEvent $b) => $a->startDate <=> $b->startDate);

            $data[] = $offer;
        }

        // Sort events by their earliest startDate ASC
        usort($data, function (Offer $a, Offer $b) {
            $dateA = $a->dates[0]->startDate ?? null;
            $dateB = $b->dates[0]->startDate ?? null;

            return $dateA <=> $dateB;
        });

        array_map(
            fn(Offer $offer) => $offer->url = RouterPivot::getOfferUrl($offer->codeCgt,Theme::CATEGORIES_AGENDA),
            $data
        );

        return $data;
    }

    public function loadOffer(string $codeCgt, ?ContentLevel $contentLevel = null): ?Offer
    {
        return $this->pivotClient->loadOffer($codeCgt, $contentLevel);
    }

    /**
     * @return Offer[]
     */
    public function loadAccommodations(): array
    {
        $accommodationIds = array_map(
            fn(TypeOffreEnum $type) => $type->value,
            TypeOffreEnum::accommodations(),
        );

        return $this->loadOffersByTypeIds($accommodationIds);
    }

    /**
     * @return Offer[]
     */
    public function loadRestaurants(): array
    {
        return $this->loadOffersByTypeIds([TypeOffreEnum::RESTAURANT->value]);
    }

    /**
     * @param int[] $typeIds
     * @return Offer[]
     */
    private function loadOffersByTypeIds(array $typeIds): array
    {
        $response = $this->pivotClient->fetchOffersByCriteria();

        $offers = array_filter(
            $response->getOffers(),
            fn(Offer $offer) => $offer->typeOffre !== null
                && in_array($offer->typeOffre->idTypeOffre, $typeIds, true),
        );

        $offers = array_values($offers);
        usort($offers, fn(Offer $a, Offer $b) => strcasecmp($a->nom ?? '', $b->nom ?? ''));

        return $offers;
    }

    /**
     * @return \stdClass[]
     */
    public function getAllOffersShorts(): array
    {
        $offerResponse = $this->pivotClient->fetchOffersByCriteria(ContentLevel::Full);

        $offers = [];
        foreach ($offerResponse->getOffers() as $offer) {
            $std = new \stdClass();
            $std->codeCgt = $offer->codeCgt;
            $std->name = $offer->nom;
            $std->type = ($offer->typeOffre && $offer->typeOffre->label) ? ($offer->typeOffre->label[0]->value ?? '') : '';
            $offers[] = $std;
        }

        usort($offers, fn(\stdClass $a, \stdClass $b) => strcasecmp($a->name ?? '', $b->name ?? ''));

        return $offers;
    }

    /**
     * @param string[] $codesCgt
     * @return \stdClass[]
     */
    public function findOffersShortByCodesCgt(array $codesCgt): array
    {
        $allOffers = $this->getAllOffersShorts();
        $offers = [];
        foreach ($codesCgt as $codeCgt) {
            foreach ($allOffers as $offerShort) {
                if ($offerShort->codeCgt === $codeCgt) {
                    $offers[] = $offerShort;
                    break;
                }
            }
        }

        return $offers;
    }

    /**
     * @return \stdClass[]
     */
    public function findShortsByNameOrCode(string $search): array
    {
        $offers = array_filter($this->getAllOffersShorts(), function (\stdClass $offre) use ($search) {
            return preg_match('#'.preg_quote($search, '#').'#i', $offre->name ?? '')
                || preg_match('#'.preg_quote($search, '#').'#i', $offre->codeCgt ?? '');
        });

        return array_values($offers);
    }

    /**
     * @return Offer[]
     */
    public function loadOffersByClassificationUrn(string $urn): array
    {
        $response = $this->pivotClient->fetchOffersByCriteria();

        $offers = array_filter(
            $response->getOffers(),
            fn(Offer $offer) => count(
                    array_filter(
                        $offer->getClassificationLabels(),
                        fn($label) => $label->urn === $urn,
                    )
                ) > 0,
        );

        $offers = array_values($offers);
        usort($offers, fn(Offer $a, Offer $b) => strcasecmp($a->nom ?? '', $b->nom ?? ''));

        return $offers;
    }

    /**
     * @return Offer[]
     */
    public function getAllOffers(): array
    {
        $offerResponse = $this->pivotClient->fetchOffersByCriteria(ContentLevel::Full);

        return $offerResponse->getOffers();
    }
}

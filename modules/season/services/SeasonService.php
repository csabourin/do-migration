<?php
namespace modules\season\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use craft\base\ElementInterface;
use craft\elements\db\CategoryQuery;

/**
 * SeasonService
 *
 * Core business logic for managing seasonal content filtering across the site.
 * This service enables assets and content to be filtered and displayed based on
 * the active season (winter, summer, fall).
 *
 * ========================================
 * CRAFT CMS CONFIGURATION REQUIREMENTS
 * ========================================
 *
 * Before using this service, configure the following in Craft CMS:
 *
 * 1. CATEGORY GROUP: "Seasons"
 *    Handle: seasons
 *    Location: Settings → Categories → New Category Group
 *
 *    Required categories (must use these exact slugs):
 *    ┌─────────┬─────────────┬──────────────────┐
 *    │ Slug    │ Title       │ Purpose          │
 *    ├─────────┼─────────────┼──────────────────┤
 *    │ winter  │ Hiver       │ Winter content   │
 *    │ summer  │ Été         │ Summer content   │
 *    │ fall    │ Automne     │ Fall content     │
 *    └─────────┴─────────────┴──────────────────┘
 *
 * 2. GLOBAL SET: "Site Theme"
 *    Handle: siteTheme
 *    Location: Settings → Globals → New Global Set
 *
 *    Required field:
 *    - Name: "Current Season"
 *      Handle: currentSeason
 *      Type: Categories
 *      Sources: Seasons category group
 *      Limit: 1
 *      Purpose: Controls the site-wide active season
 *
 * 3. ASSET FIELD: "Seasons"
 *    Handle: seasons
 *    Type: Categories
 *    Location: Settings → Fields → New Field
 *
 *    Configuration:
 *    - Sources: Seasons category group
 *    - Limit: Multiple (no limit)
 *    - Must be added to Asset Volume field layouts
 *
 *    Purpose: Tags assets with the seasons they should appear in
 *
 * 4. OPTIONAL - ENTRY/BLOCK FIELD: "Season Override"
 *    Handle: seasonOverride
 *    Type: Categories
 *    Location: Settings → Fields → New Field
 *
 *    Configuration:
 *    - Sources: Seasons category group
 *    - Limit: 1
 *    - Add to Entry Types or Matrix Blocks as needed
 *
 *    Purpose: Allows individual entries to override the global season
 *
 * ========================================
 * ARCHITECTURE & DESIGN DECISIONS
 * ========================================
 *
 * WHY STRING SLUGS INSTEAD OF CATEGORY IDs?
 *
 * This service uses season slugs (strings) as the primary data type rather
 * than Category IDs (integers). This design choice provides:
 *
 * 1. Environment Stability
 *    - Category IDs change between dev/staging/production
 *    - Slugs remain consistent across all environments
 *
 * 2. Code Readability
 *    - season === 'winter' is self-documenting
 *    - categoryId === 17 requires context to understand
 *
 * 3. API Simplicity
 *    - Methods accept/return simple strings
 *    - No need to pass around Category objects
 *
 * 4. Conversion on Demand
 *    - getSeasonCategoryId() converts to ID only when needed
 *    - IDs used at the last possible moment (database queries)
 *
 * This follows the principle: "Work with high-level concepts, convert to
 * low-level IDs only when necessary for database operations."
 *
 * ========================================
 * PUBLIC API METHODS
 * ========================================
 *
 * Season Retrieval:
 * - getActiveSeason(?string $override): Get global season or override
 * - getActiveSeasonForElement($element, $override): Get element-specific season
 *
 * Asset Filtering:
 * - applySeasonToAssetQuery($query, $season, $returnArray): Database-level filtering (efficient)
 *   - $returnArray = false (default): Returns single Asset or null
 *   - $returnArray = true: Returns ElementQueryInterface (chainable)
 *
 * - filterAssetListBySeason($assets, $season, $fallback, $returnArray): PHP-level filtering
 *   - $returnArray = false (default): Returns single Asset or null
 *   - $returnArray = true: Returns array of Assets
 *   - $fallback: 'original' (default), 'empty', or 'noSeason'
 *
 * Utilities:
 * - getSeasonCategoryId($season): Convert slug to Category ID
 * - seasonLabel($code): Get human-readable label
 *
 * @see SeasonTwigExtension for Twig template functions
 */
class SeasonService extends Component
{
    public string $seasonGroupHandle = 'seasons';
    public string $assetSeasonFieldHandle = 'seasons';
    public string $globalSetHandle = 'siteTheme';
    public string $globalFieldHandle = 'currentSeason';

    /** Renvoie 'winter'|'summer'|'fall' (ou override si fourni) */
    public function getActiveSeason(?string $override = null): string
{
    if ($override) {
        return $override;
    }
    $set = Craft::$app->globals->getSetByHandle($this->globalSetHandle);
    if ($set && isset($set->{$this->globalFieldHandle})) {
        $cat = $set->{$this->globalFieldHandle};
        // If it's a category field, get the first selected category's slug
        if ($cat instanceof \craft\elements\Category) {
            return $cat->slug;
        }
        // If it's a query, get the first category
        if (is_object($cat) && method_exists($cat, 'one')) {
            $selected = $cat->one();
            if ($selected instanceof \craft\elements\Category) {
                return $selected->slug;
            }
        }
    }
    return 'summer';
}

/** Retourne true si $element possède le champ $handle dans son Field Layout */
public function elementHasField(ElementInterface $element, string $handle): bool
{
    $field = Craft::$app->fields->getFieldByHandle($handle);
    if (!$field) {
        return false;
    }
    $layout = $element->getFieldLayout();
    if (!$layout) {
        return false;
    }
    foreach ($layout->getCustomFields() as $f) {
        if ($f->handle === $handle) {
            return true;
        }
    }
    return false;
}

/** Normalise un override (string|Category|CategoryQuery|null) en slug de saison (string|null) */
public function normalizeSeasonOverride(mixed $override): ?string
{
    if (is_string($override) && $override !== '') {
        return $override;
    }
    if ($override instanceof Category) {
        return $override->slug ?? null;
    }
    if ($override instanceof CategoryQuery) {
        $cat = $override->one();
        return $cat?->slug;
    }
    return null;
}

/**
 * Saison active pour un élément (entrée/bloc), avec override optionnel.
 * - $explicitOverride : string|Category|CategoryQuery|null
 * - Si pas d’override, tente de lire le champ 'seasonOverride' sur l’élément **sans planter**
 * - Sinon retombe sur la saison **globale** (champ Catégories)
 */
public function getActiveSeasonForElement(?ElementInterface $element = null, mixed $explicitOverride = null): string
{
    // 1) Override explicite (string/Category/Query)
    $norm = $this->normalizeSeasonOverride($explicitOverride);
    if ($norm) {
        return $norm;
    }

    // 2) Override depuis le champ de l’élément, si présent
    if ($element && $this->elementHasField($element, 'seasonOverride')) {
        $raw = $element->getFieldValue('seasonOverride');
        $norm = $this->normalizeSeasonOverride($raw);
        if ($norm) {
            return $norm;
        }
    }

    // 3) Saison globale depuis Globaux (Catégories)
    $set = Craft::$app->globals->getSetByHandle($this->globalSetHandle);
    if ($set && isset($set->{$this->globalFieldHandle})) {
        $raw = $set->{$this->globalFieldHandle};
        // Peut être Category, CategoryQuery ou autre
        $norm = $this->normalizeSeasonOverride($raw);
        if ($norm) {
            return $norm;
        }
        // fallback si c'était un Dropdown (ancien setup)
        $val = $set->{$this->globalFieldHandle}->value ?? null;
        if (is_string($val) && $val !== '') {
            return $val;
        }
    }

    // 4) Défaut
    return 'summer';
}

    

    /** ID de la catégorie correspondant à la saison, ou null */
    public function getSeasonCategoryId(string $season): ?int
    {
        $cat = Category::find()
            ->group($this->seasonGroupHandle)
            ->slug($season)
            ->one();
        return $cat?->id;
    }

    /** IDs de toutes les catégories du groupe "seasons" */
    public function getAllSeasonCategoryIds(): array
    {
        return Category::find()
            ->group($this->seasonGroupHandle)
            ->ids() ?? [];
    }

    /**
     * Applique le filtre saison à une ElementQuery d'Assets (filtrage DB).
     * $returnArray:
     *  - false : retourne un seul asset (le premier) ou null (défaut)
     *  - true  : retourne une ElementQuery (chaînable)
     */
    public function applySeasonToAssetQuery(ElementQueryInterface $query, string $season, bool $returnArray = false): ElementQueryInterface|Asset|null
    {
        $catId = $this->getSeasonCategoryId($season);
        if (!$catId) {
            return $returnArray ? $query : $query->one(); // pas de cat => ne pas filtrer
        }
        $q = clone $query;
        $q->relatedTo(['targetElement' => $catId]);
        return $returnArray ? $q : $q->one();
    }

    /**
     * Filtre un tableau d'assets déjà chargés (PHP).
     * $fallback:
     *  - 'original' : si aucun match, retourne la liste d'origine
     *  - 'empty'    : si aucun match, retourne []
     *  - 'noSeason' : si aucun match, assets sans AUCUNE catégorie du groupe seasons
     * $returnArray:
     *  - false : retourne un seul asset (le premier) ou null (défaut)
     *  - true  : retourne un array d'assets
     */
    public function filterAssetListBySeason(array $assets, string $season, string $fallback = 'original', bool $returnArray = false): array|Asset|null
    {
        $catId = $this->getSeasonCategoryId($season);
        if (!$catId) {
            return $returnArray ? $assets : ($assets[0] ?? null); // pas de catégorie saison: ne filtre pas
        }

        // Si le champ n'existe pas du tout, ne filtre pas et ne plante pas
        $seasonField = Craft::$app->fields->getFieldByHandle($this->assetSeasonFieldHandle);
        if (!$seasonField) {
            Craft::warning("Champ d'assets '{$this->assetSeasonFieldHandle}' introuvable. Aucun filtrage saison appliqué.", __METHOD__);
            return $returnArray ? $assets : ($assets[0] ?? null);
        }

        $matched = [];
        foreach ($assets as $a) {
            if (!$a instanceof Asset) {
                continue;
            }
            // Si cet asset n'a pas le champ dans son layout, on l'ignore pour le matching
            if (!$this->assetHasSeasonField($a)) {
                continue;
            }
            $ids = $a->getFieldValue($this->assetSeasonFieldHandle)->ids() ?? [];
            if (in_array($catId, $ids, true)) {
                $matched[] = $a;
            }
        }

        if (!empty($matched)) {
            return $returnArray ? $matched : ($matched[0] ?? null);
        }

        // Fallbacks si rien ne matche
        if ($fallback === 'empty') {
            return $returnArray ? [] : null;
        }

        if ($fallback === 'noSeason') {
            $allSeasonIds = $this->getAllSeasonCategoryIds();
            $filtered = array_values(array_filter($assets, function ($a) use ($allSeasonIds) {
                if (!$a instanceof Asset) {
                    return false;
                }
                // Si l'asset n'a pas ce champ dans son layout => on le considère "sans saison"
                if (!$this->assetHasSeasonField($a)) {
                    return true;
                }
                $ids = $a->getFieldValue($this->assetSeasonFieldHandle)->ids() ?? [];
                return count(array_intersect($ids, $allSeasonIds)) === 0;
            }));
            return $returnArray ? $filtered : ($filtered[0] ?? null);
        }

        // 'original' => on rend la liste telle quelle
        return $returnArray ? $assets : ($assets[0] ?? null);
    }


    /** Libellé humain (laisse la traduction au template via |t('site')) */
    public function seasonLabel(string $code): string
    {
        return match ($code) {
            'winter' => 'Hiver',
            'summer' => 'Été',
            'fall' => 'Automne',
            default => $code,
        };
    }

    private function assetHasSeasonField(Asset $asset): bool
    {
        // 1) Le champ existe-t-il dans le système ?
        $field = Craft::$app->fields->getFieldByHandle($this->assetSeasonFieldHandle);
        if (!$field) {
            return false;
        }
        // 2) Est-il présent dans le Field Layout de CET asset ?
        $layout = $asset->getFieldLayout();
        if (!$layout) {
            return false;
        }
        foreach ($layout->getCustomFields() as $f) {
            if ($f->handle === $this->assetSeasonFieldHandle) {
                return true;
            }
        }
        return false;
    }

}

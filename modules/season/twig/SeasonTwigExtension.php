<?php
namespace modules\season\twig;

use Twig\TwigFunction;
use Twig\Extension\AbstractExtension;
use modules\season\services\SeasonService;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Asset;

/**
 * SeasonTwigExtension
 *
 * Twig adapter layer for SeasonService, exposing season functionality to templates.
 * This extension acts as a thin wrapper, delegating all business logic to SeasonService.
 *
 * Pattern: Adapter Pattern
 * - Separates template presentation from business logic
 * - Allows unit testing of SeasonService without Twig dependencies
 * - Provides template-friendly function names
 *
 * @see SeasonService for underlying business logic
 */

        /**
         * Season Module - Gestion du contenu saisonnier
         *
         * Permet de filtrer les assets et le contenu par saison (hiver, été, automne).
         * Les templates peuvent afficher différents assets selon la saison active.
         *
         * Configuration Craft CMS requise:
         * --------------------------------
         * 1. Groupe de catégories "Seasons" (handle: seasons)
         *    - Catégories requises: winter, summer, fall
         *
         * 2. Globaux "Site Theme" (handle: siteTheme)
         *    - Champ "Current Season" (handle: currentSeason, type: Categories)
         *    - Contrôle la saison active du site
         *
         * 3. Champ "Seasons" sur les Assets (handle: seasons, type: Categories)
         *    - Permet de tagger les assets par saison
         *
         * 4. (Optionnel) Champ "Season Override" sur Entries/Blocks
         *    - Permet d'overrider la saison globale par entrée
         *
         * Fonctions Twig disponibles:
         * ---------------------------
         * - season_active()                                Saison globale
         * - season_active_for(entry)                       Saison spécifique à un élément
         * - season_apply(query, season, returnArray)       Filtrage DB (performant)
         * - season_filter(assets, season, fallback, returnArray)  Filtrage PHP (post-query)
         * - season_label(season)                           Libellé humain
         *
         * Contrôle du type de retour (returnArray):
         * -----------------------------------------
         * Par défaut (false), les fonctions retournent un seul Asset (le premier) ou null.
         * Si true, retourne un array d'Assets ou une ElementQuery (pour season_apply).
         *
         * Architecture:
         * -------------
         * Les saisons sont gérées comme des SLUGS (strings) plutôt que des IDs
         * pour assurer la portabilité entre environnements et la lisibilité du code.
         * Conversion en Category ID uniquement au moment des requêtes DB.
         *
         * @see modules/season/Module.php pour la documentation complète
         * @see modules/season/services/SeasonService.php pour la logique métier
         */

class SeasonTwigExtension extends AbstractExtension
{
    /**
     * Constructor with dependency injection
     *
     * @param SeasonService $svc The season service instance (injected automatically)
     */
    public function __construct(private SeasonService $svc)
    {
    }

    /**
     * Register Twig functions
     *
     * All functions are exposed to templates and delegate to SeasonService methods.
     *
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            // Global season retrieval
            new TwigFunction('season_active', [$this, 'seasonActive']),

            // Element-specific season with override support
            new TwigFunction('season_active_for', [$this, 'seasonActiveFor']),

            // Database-level asset filtering (before query execution)
            new TwigFunction('season_apply', [$this, 'seasonApply']),

            // PHP-level asset filtering (after assets loaded)
            new TwigFunction('season_filter', [$this, 'seasonFilter']),

            // Human-readable season labels
            new TwigFunction('season_label', [$this, 'seasonLabel']),
        ];
    }

    /**
     * Get the globally active season
     *
     * Retrieves the current season from global settings (siteTheme.currentSeason).
     * Returns season slug as string ('winter', 'summer', 'fall').
     *
     * Template usage:
     * ```twig
     * {# Get current global season #}
     * {% set currentSeason = season_active() %}
     *
     * {# Force a specific season for testing #}
     * {% set testSeason = season_active('winter') %}
     * ```
     *
     * @param string|null $override Optional season override ('winter'|'summer'|'fall')
     * @return string Season slug (defaults to 'summer' if not configured)
     * @see SeasonService::getActiveSeason()
     */
    public function seasonActive(?string $override = null): string
    {
        return $this->svc->getActiveSeason($override);
    }

    /**
     * Apply season filter to asset query (database-level filtering)
     *
     * Filters assets at the SQL query level BEFORE execution, making it efficient
     * for large asset volumes. Adds a relatedTo condition for the season category.
     *
     * Performance: PREFERRED METHOD for filtering - uses database query
     *
     * Return type control:
     * - false: Returns a single Asset (the first match) or null (default)
     * - true: Returns an ElementQueryInterface (chainable for further filtering)
     *
     * Template usage:
     * ```twig
     * {# Get single asset (default behavior) #}
     * {% set heroImage = season_apply(
     *     craft.assets.volume('photos'),
     *     season_active()
     * ) %}
     *
     * {# Get query for further chaining #}
     * {% set winterQuery = season_apply(
     *     craft.assets.volume('photos'),
     *     season_active(),
     *     true
     * ) %}
     * {% for image in winterQuery.limit(10).all() %}
     *     <img src="{{ image.url }}" alt="{{ image.title }}">
     * {% endfor %}
     * ```
     *
     * @param ElementQueryInterface $assetQuery Asset query to filter
     * @param string $season Season slug to filter by ('winter'|'summer'|'fall')
     * @param bool $returnArray Return query (true) or single asset (false, default)
     * @return ElementQueryInterface|Asset|null Modified query, single Asset, or null
     * @see SeasonService::applySeasonToAssetQuery()
     */
    public function seasonApply(ElementQueryInterface $assetQuery, string $season, bool $returnArray = false): ElementQueryInterface|Asset|null
    {
        return $this->svc->applySeasonToAssetQuery($assetQuery, $season, $returnArray);
    }

    /**
     * Filter already-loaded assets by season (PHP-level filtering)
     *
     * Filters assets that are already loaded in memory. Use this when you've
     * fetched assets from a relationship field and need to filter them post-query.
     *
     * Performance: Use season_apply() instead when possible for better performance
     *
     * Fallback strategies when no seasonal matches are found:
     * - 'original': Return the full original list (default)
     * - 'empty': Return an empty array
     * - 'noSeason': Return assets with no season categories assigned
     *
     * Return type control:
     * - false: Returns a single Asset (the first match) or null (default)
     * - true: Returns an array of all matching Assets
     *
     * Template usage:
     * ```twig
     * {# Get single asset (default behavior) #}
     * {% set heroImage = season_filter(allImages, season_active()) %}
     *
     * {# Get array of all matching assets #}
     * {% set galleryImages = season_filter(allImages, season_active(), 'noSeason', true) %}
     *
     * {# With explicit fallback #}
     * {% set winterOnly = season_filter(images, 'winter', 'empty', true) %}
     * ```
     *
     * @param array $assets Array of Asset elements to filter
     * @param string $season Season slug to filter by ('winter'|'summer'|'fall')
     * @param string $fallback Fallback strategy: 'original'|'empty'|'noSeason' (default: 'original')
     * @param bool $returnArray Return array (true) or single asset (false, default)
     * @return array|Asset|null Filtered Asset(s) or null
     * @see SeasonService::filterAssetListBySeason()
     */
    public function seasonFilter(array $assets, string $season, string $fallback = 'original', bool $returnArray = false): array|Asset|null
    {
        return $this->svc->filterAssetListBySeason($assets, $season, $fallback, $returnArray);
    }

    /**
     * Get human-readable label for a season code
     *
     * Converts season slugs to human-readable labels (in French).
     * Should be passed through |t('site') filter for translation support.
     *
     * Mappings:
     * - 'winter' → 'Hiver'
     * - 'summer' → 'Été'
     * - 'fall' → 'Automne'
     *
     * Template usage:
     * ```twig
     * {# Display translated season label #}
     * {{ season_label('winter')|t('site') }}
     * {# Output: Hiver #}
     *
     * {# Display current season label #}
     * {{ season_label(season_active())|t('site') }}
     * ```
     *
     * @param string $code Season slug ('winter'|'summer'|'fall')
     * @return string Human-readable label (returns code if unrecognized)
     * @see SeasonService::seasonLabel()
     */
    public function seasonLabel(string $code): string
    {
        return $this->svc->seasonLabel($code);
    }

    /**
     * Get the active season for a specific element (with override support)
     *
     * Determines the season for a specific element with the following priority:
     * 1. Explicit override parameter (if provided)
     * 2. Element's 'seasonOverride' field (if exists and set)
     * 3. Global season setting (from siteTheme.currentSeason)
     * 4. Default fallback ('summer')
     *
     * Use case: Allows individual entries/blocks to override the global season,
     * enabling content editors to display different seasonal assets per entry.
     *
     * Template usage:
     * ```twig
     * {# Respect element's season override field #}
     * {% set season = season_active_for(entry) %}
     *
     * {# Force specific season for an element #}
     * {% set season = season_active_for(entry, 'fall') %}
     *
     * {# Use with season_apply for element-specific filtering #}
     * {% set images = season_apply(
     *     craft.assets.volume('photos'),
     *     season_active_for(entry)
     * ) %}
     * ```
     *
     * @param ElementInterface|null $element The element to check for season override
     * @param string|Category|CategoryQuery|null $explicitOverride Optional explicit override
     * @return string Season slug ('winter'|'summer'|'fall')
     * @see SeasonService::getActiveSeasonForElement()
     */
    public function seasonActiveFor($element = null, $explicitOverride = null): string
    {
        return $this->svc->getActiveSeasonForElement($element, $explicitOverride);
    }
}

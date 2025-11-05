<?php
namespace modules\season;

use Craft;
use yii\base\Module as BaseModule;
use modules\season\twig\SeasonTwigExtension;
use modules\season\services\SeasonService;

/**
 * Season Module
 *
 * Comprehensive seasonal content management system for Craft CMS.
 * Enables dynamic filtering of assets and content based on the active season
 * (winter, summer, fall) with support for global and element-specific overrides.
 *
 * ========================================
 * OVERVIEW
 * ========================================
 *
 * This module provides a complete solution for managing seasonal variations of
 * images and content across your site. Content editors can:
 *
 * - Tag assets with one or more seasons
 * - Set a global site-wide active season
 * - Override the season on a per-entry or per-block basis
 * - Automatically display season-appropriate assets in templates
 *
 * Common use cases:
 * - Hero images that change with the season
 * - Entry thumbnails with seasonal variants
 * - Image galleries filtered by season
 * - Seasonal product photography
 * - Location-based content (ski trails vs beaches)
 *
 * ========================================
 * CRAFT CMS CONFIGURATION
 * ========================================
 *
 * Before using this module, configure the following in Craft CMS:
 *
 * 1. CATEGORY GROUP: "Seasons"
 *    Location: Settings → Categories → New Category Group
 *    Handle: seasons
 *
 *    Create these categories (exact slugs required):
 *    ┌─────────┬─────────────┬──────────────────┐
 *    │ Slug    │ Title       │ Purpose          │
 *    ├─────────┼─────────────┼──────────────────┤
 *    │ winter  │ Hiver       │ Winter content   │
 *    │ summer  │ Été         │ Summer content   │
 *    │ fall    │ Automne     │ Fall content     │
 *    └─────────┴─────────────┴──────────────────┘
 *
 * 2. GLOBAL SET: "Site Theme"
 *    Location: Settings → Globals → New Global Set
 *    Handle: siteTheme
 *
 *    Field configuration:
 *    - Name: "Current Season"
 *      Handle: currentSeason
 *      Type: Categories
 *      Sources: Seasons category group
 *      Limit: 1
 *      Purpose: Controls the site-wide active season
 *
 * 3. ASSET FIELD: "Seasons"
 *    Location: Settings → Fields → New Field
 *    Handle: seasons
 *    Type: Categories
 *
 *    Configuration:
 *    - Sources: Seasons category group
 *    - Limit: Multiple (no limit)
 *    - Add this field to your Asset Volume field layouts
 *
 *    Purpose: Tags assets with the seasons they should appear in
 *
 * 4. OPTIONAL - ENTRY/BLOCK FIELD: "Season Override"
 *    Location: Settings → Fields → New Field
 *    Handle: seasonOverride
 *    Type: Categories
 *
 *    Configuration:
 *    - Sources: Seasons category group
 *    - Limit: 1
 *    - Add to Entry Types or Matrix Blocks as needed
 *
 *    Purpose: Allows individual entries to override the global season
 *
 * ========================================
 * TWIG FUNCTIONS
 * ========================================
 *
 * season_active(?override)
 *   Get the global active season
 *   Returns: string ('winter'|'summer'|'fall')
 *
 * season_active_for(element, ?override)
 *   Get the active season for a specific element
 *   Returns: string ('winter'|'summer'|'fall')
 *
 * season_apply(query, season, returnArray = false)
 *   Apply season filter to asset query (database-level, efficient)
 *   Returns: Asset|null (default) or ElementQueryInterface (if returnArray = true)
 *
 * season_filter(assets, season, fallback = 'original', returnArray = false)
 *   Filter already-loaded assets by season (PHP-level)
 *   Returns: Asset|null (default) or array (if returnArray = true)
 *   Fallback: 'original'|'empty'|'noSeason'
 *
 * season_label(season)
 *   Get human-readable label for a season
 *   Returns: string ('Hiver'|'Été'|'Automne')
 *
 * ========================================
 * USAGE EXAMPLES
 * ========================================
 *
 * EXAMPLE 1: Entry Thumbnail (Single Asset)
 * ------------------------------------------
 * Use case: Display a seasonal thumbnail for blog posts, products, or entries.
 * Each entry has a "thumbnail" Assets field with multiple seasonal variants.
 *
 * Template: templates/blog/_entry.twig
 * ```twig
 * {# Get the seasonal thumbnail for this entry #}
 * {% set thumbnail = season_filter(entry.thumbnail.all(), season_active()) %}
 *
 * {% if thumbnail %}
 *   <img src="{{ thumbnail.url }}"
 *        alt="{{ entry.title }}"
 *        class="entry-thumbnail">
 * {% endif %}
 * ```
 *
 * With element-specific season override:
 * ```twig
 * {# Entry can override the global season via entry.seasonOverride field #}
 * {% set season = season_active_for(entry) %}
 * {% set thumbnail = season_filter(entry.thumbnail.all(), season) %}
 *
 * {% if thumbnail %}
 *   <img src="{{ thumbnail.url }}" alt="{{ entry.title }}">
 * {% endif %}
 * ```
 *
 * EXAMPLE 2: Hero Image (Single Asset, Database Query)
 * -----------------------------------------------------
 * Use case: Display a large hero image from an Assets field, filtered at
 * the database level for optimal performance.
 *
 * Template: templates/pages/home.twig
 * ```twig
 * {# Get single hero image filtered by current season (efficient DB query) #}
 * {% set heroImage = season_apply(
 *   craft.assets()
 *     .volume('heroImages')
 *     .kind('image'),
 *   season_active()
 * ) %}
 *
 * {% if heroImage %}
 *   <section class="hero"
 *            style="background-image: url('{{ heroImage.url }}')">
 *     <h1>{{ entry.title }}</h1>
 *   </section>
 * {% endif %}
 * ```
 *
 * With specific folder and transforms:
 * ```twig
 * {% set heroImage = season_apply(
 *   craft.assets()
 *     .volume('siteImages')
 *     .folderId(heroFolder.id)
 *     .kind('image'),
 *   season_active()
 * ) %}
 *
 * {% if heroImage %}
 *   <picture>
 *     <source media="(min-width: 768px)"
 *             srcset="{{ heroImage.url({ width: 1920, height: 800 }) }}">
 *     <img src="{{ heroImage.url({ width: 768, height: 400 }) }}"
 *          alt="{{ entry.heroTitle }}">
 *   </picture>
 * {% endif %}
 * ```
 *
 * EXAMPLE 3: Image Gallery (Multiple Assets, Array)
 * --------------------------------------------------
 * Use case: Display a gallery of images filtered by season, showing all
 * matching seasonal images.
 *
 * Template: templates/galleries/show.twig
 * ```twig
 * {# Get all seasonal images for a gallery (returns array) #}
 * {% set galleryImages = season_filter(
 *   entry.galleryImages.all(),
 *   season_active(),
 *   'noSeason',
 *   true
 * ) %}
 *
 * {% if galleryImages|length %}
 *   <div class="gallery">
 *     {% for image in galleryImages %}
 *       <figure class="gallery-item">
 *         <img src="{{ image.url({ width: 600, height: 400 }) }}"
 *              alt="{{ image.title }}">
 *         {% if image.caption %}
 *           <figcaption>{{ image.caption }}</figcaption>
 *         {% endif %}
 *       </figure>
 *     {% endfor %}
 *   </div>
 * {% endif %}
 * ```
 *
 * Database-level filtering for large galleries:
 * ```twig
 * {# More efficient for large asset volumes - filter at DB level #}
 * {% set galleryQuery = season_apply(
 *   craft.assets()
 *     .volume('galleries')
 *     .folderId(entry.galleryFolder.id)
 *     .kind('image'),
 *   season_active(),
 *   true
 * ) %}
 *
 * {# Further refine the query #}
 * {% set galleryImages = galleryQuery
 *   .orderBy('title ASC')
 *   .limit(20)
 *   .all() %}
 *
 * <div class="gallery">
 *   {% for image in galleryImages %}
 *     <img src="{{ image.url({ width: 400, height: 300 }) }}"
 *          alt="{{ image.title }}">
 *   {% endfor %}
 * </div>
 * ```
 *
 * EXAMPLE 4: Matrix Block with Seasonal Assets
 * ---------------------------------------------
 * Use case: Matrix blocks with seasonal image variants.
 *
 * Template: templates/_blocks/imageBlock.twig
 * ```twig
 * {# Get season for this specific block (respects block.seasonOverride if set) #}
 * {% set blockSeason = season_active_for(block) %}
 *
 * {# Get the seasonal image for this block #}
 * {% set blockImage = season_filter(
 *   block.image.all(),
 *   blockSeason
 * ) %}
 *
 * {% if blockImage %}
 *   <div class="image-block">
 *     <img src="{{ blockImage.url({ width: 800 }) }}"
 *          alt="{{ block.imageCaption }}">
 *     <p class="caption">{{ block.imageCaption }}</p>
 *     <small class="season-indicator">
 *       {{ season_label(blockSeason)|t('site') }}
 *     </small>
 *   </div>
 * {% endif %}
 * ```
 *
 * EXAMPLE 5: Fallback Strategies
 * -------------------------------
 * Use case: Handling cases where no seasonal assets are found.
 *
 * ```twig
 * {# Fallback to 'noSeason' assets if no seasonal match #}
 * {% set thumbnail = season_filter(
 *   entry.thumbnail.all(),
 *   season_active(),
 *   'noSeason'
 * ) %}
 *
 * {# Fallback to empty (null) if no match #}
 * {% set thumbnail = season_filter(
 *   entry.thumbnail.all(),
 *   season_active(),
 *   'empty'
 * ) %}
 *
 * {# Fallback to original array (first asset) if no match #}
 * {% set thumbnail = season_filter(
 *   entry.thumbnail.all(),
 *   season_active(),
 *   'original'
 * ) %}
 * ```
 *
 * EXAMPLE 6: Product Grid with Seasonal Images
 * ---------------------------------------------
 * Use case: Seasonal product photos.
 *
 * Template: templates/shop/products.twig
 * ```twig
 * {% set products = craft.entries()
 *   .section('products')
 *   .all() %}
 *
 * <div class="product-grid">
 *   {% for product in products %}
 *     {% set productImage = season_filter(
 *       product.productImages.all(),
 *       season_active()
 *     ) %}
 *
 *     <article class="product-card">
 *       {% if productImage %}
 *         <img src="{{ productImage.url({ width: 300, height: 300 }) }}"
 *              alt="{{ product.title }}">
 *       {% endif %}
 *       <h3>{{ product.title }}</h3>
 *       <p class="price">{{ product.price|currency }}</p>
 *     </article>
 *   {% endfor %}
 * </div>
 * ```
 *
 * ========================================
 * PERFORMANCE BEST PRACTICES
 * ========================================
 *
 * 1. PREFER season_apply() OVER season_filter()
 *    - season_apply() filters at the database level (SQL WHERE clause)
 *    - season_filter() filters in PHP after loading all assets
 *    - Use season_apply() for standalone queries
 *    - Use season_filter() only for relationship fields
 *
 * 2. USE returnArray = false FOR SINGLE ASSETS
 *    - Default behavior returns first matching asset or null
 *    - More memory efficient for hero images and thumbnails
 *    - Only use returnArray = true when you need multiple assets
 *
 * 3. CACHE SEASON LOOKUPS
 *    ```twig
 *    {% set currentSeason = season_active() %}
 *    {# Reuse currentSeason variable instead of calling season_active() repeatedly #}
 *    ```
 *
 * 4. COMBINE WITH EAGER LOADING
 *    ```twig
 *    {% set entries = craft.entries()
 *      .section('blog')
 *      .with(['thumbnail'])
 *      .all() %}
 *    ```
 *
 * ========================================
 * ARCHITECTURE NOTES
 * ========================================
 *
 * Design Pattern: Service Layer + Adapter Pattern
 * - SeasonService: Core business logic (environment-agnostic)
 * - SeasonTwigExtension: Template adapter (Twig-specific wrapper)
 * - Module: Initialization and dependency injection
 *
 * Data Flow:
 * 1. Templates call Twig functions (season_filter, season_apply, etc.)
 * 2. Twig functions delegate to SeasonService methods
 * 3. SeasonService performs filtering/querying logic
 * 4. Results return through the same path
 *
 * String-based API:
 * - Season slugs ('winter', 'summer', 'fall') used throughout
 * - Category IDs only used internally for database queries
 * - Ensures consistency across dev/staging/production environments
 *
 * @see SeasonService for business logic documentation
 * @see SeasonTwigExtension for Twig function documentation
 *
 * @package modules\season
 * @author Charbel Sabouri
 * @version 1.0.0
 */
class Module extends BaseModule
{
    /** @var string Handle of the Seasons category group */
    public string $seasonGroupHandle = 'seasons';

    /** @var string Handle of the Categories field on Assets */
    public string $assetSeasonFieldHandle = 'seasons';

    /** @var string Handle of the global set containing current season */
    public string $globalSetHandle = 'siteTheme';

    /** @var string Handle of the current season field in globals */
    public string $globalFieldHandle = 'currentSeason';

    /**
     * Initialize the Season module
     *
     * Registers the SeasonService component and Twig extension,
     * making season filtering functions available throughout the application.
     */
    public function init(): void
    {
        parent::init();

        // Register the service accessible via Craft::$app->getModule('season')->season
        $this->setComponents([
            'season' => SeasonService::class,
        ]);

        // Pass configuration to the service
        /** @var SeasonService $svc */
        $svc = $this->get('season');
        $svc->seasonGroupHandle = $this->seasonGroupHandle;
        $svc->assetSeasonFieldHandle = $this->assetSeasonFieldHandle;
        $svc->globalSetHandle = $this->globalSetHandle;
        $svc->globalFieldHandle = $this->globalFieldHandle;

        // Register Twig extension (provides season_active, season_apply, season_filter, season_label functions)
        Craft::$app->view->registerTwigExtension(new SeasonTwigExtension($svc));
    }
}

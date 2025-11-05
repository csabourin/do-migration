# Trousse de migration AWS S3 vers DigitalOcean Spaces

**Migration complète pour Craft CMS 4**

Source : **AWS S3 (ncc-website-2, ca-central-1)**
Destination : **DigitalOcean Spaces (Toronto - tor1)**

---

## 📋 Table des matières

- [Aperçu](#aperçu)
- [Prérequis](#prérequis)
- [Configuration initiale](#configuration-initiale)
- [Processus de migration](#processus-de-migration)
- [Contrôleurs disponibles](#contrôleurs-disponibles)
- [Dépannage](#dépannage)
- [Critères de réussite](#critères-de-réussite)

---

## Aperçu

**11 contrôleurs spécialisés** pour migrer une installation Craft CMS 4 :

- ✅ Remplacement des URL dans la base de données
- ✅ Remplacement des URL dans les gabarits
- ✅ Migration des fichiers physiques (avec reprise possible)
- ✅ Gestion des systèmes de fichiers et volumes
- ✅ Validation pré-migration
- ✅ Vérification post-migration
- ✅ Découverte et pré-génération des transformations d'images
- ✅ Audit des configurations de plugiciels

**Couverture :** 85-90% automatisée → 95-98% avec étapes supplémentaires

**Espace de noms :** Toutes les commandes utilisent `craft ncc-module/{contrôleur}/{action}`

---

## Prérequis

### Synchro AWS et Digital Ocean

```bash
rclone copy aws-s3:ncc-website-2 medias:medias \
  --exclude "_*/**" \
  --fast-list \
  --transfers=32 \
  --checkers=16 \
  --use-mmap \
  --s3-acl=public-read \
  -P
  ```


### 1. Craft CMS
- Craft CMS 4.x installé
- Environnement DDEV ou PHP local
- Sauvegarde de la base de données complétée
- Accès administrateur au panneau de contrôle Craft

### 2. DigitalOcean Spaces
- Compartiment Spaces créé
- Clé d'accès et clé secrète générées
- Permissions du compartiment configurées (lecture/écriture)
- CORS configuré si nécessaire

### 3. Plugiciels requis
- [vaersaagod/dospaces](https://github.com/vaersaagod/dospaces) installé
- Ou adaptateur de système de fichiers compatible S3

### 4. Variables d'environnement

Ajoutez à votre fichier `.env` :

```bash
# Environnement de migration
MIGRATION_ENV=dev  # ou staging, prod

# Informations d'identification DigitalOcean Spaces
DO_S3_ACCESS_KEY=votre_clé_accès
DO_S3_SECRET_KEY=votre_clé_secrète
DO_S3_BUCKET=nom-de-votre-compartiment
DO_S3_BASE_URL=https://votre-compartiment.tor1.digitaloceanspaces.com
DO_S3_REGION=tor1

# Sous-dossiers (optionnel - peut être vide)
DO_S3_SUBFOLDER_IMAGES=images
DO_S3_SUBFOLDER_OPTIMISEDIMAGES=optimisedImages
DO_S3_SUBFOLDER_IMAGETRANSFORMS=imageTransforms
DO_S3_SUBFOLDER_DOCUMENTS=documents
DO_S3_SUBFOLDER_VIDEOS=videos
DO_S3_SUBFOLDER_FORMDOCUMENTS=formDocuments
DO_S3_SUBFOLDER_CHARTDATA=chartData
DO_S3_SUBFOLDER_QUARANTINE=quarantine
```

Voir `config/.env.example` pour un exemple complet.

---

## Configuration initiale

### Étape 1 : Copier les fichiers de configuration

```bash
# Copier la configuration centralisée
cp config/migration-config.php votre-projet-craft/config/

# Copier la classe helper
cp MigrationConfig.php votre-projet-craft/modules/helpers/

# Copier le gabarit d'environnement
cp config/.env.dev .env
# Modifier .env avec vos informations réelles
```

⚠️ **Note :** Les contrôleurs n'utilisent pas encore la configuration centralisée. Vous devez mettre à jour manuellement les valeurs codées en dur dans chaque fichier de contrôleur.

### Étape 2 : Installer les contrôleurs

```bash
# Copier tous les contrôleurs
cp *Controller.php votre-projet-craft/modules/console/controllers/
```

### Étape 3 : Configurer l'espace de noms

Dans votre classe de module :

```php
namespace modules;

use craft\console\Application as ConsoleApplication;
use yii\base\Event;

class Module extends \yii\base\Module
{
    public function init()
    {
        parent::init();
        Craft::$app->setModule('ncc-module', $this);

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'modules\\console\\controllers';
        }
    }
}
```

### Étape 4 : Vérifier l'installation

```bash
./craft help ncc-module
./craft ncc-module/fs-diag/list-fs
```

---

## Processus de migration

Suivez ces étapes **dans l'ordre** :

### Phase 0 : Configuration (À FAIRE EN PREMIER!)

#### 0.1 Créer les systèmes de fichiers DigitalOcean Spaces

```bash
# Créer tous les systèmes de fichiers
./craft ncc-module/filesystem/create-all

# Vérifier
./craft ncc-module/fs-diag/list-fs
```

Crée 8 systèmes de fichiers :
- `images_do`
- `optimisedImages_do`
- `imageTransforms_do`
- `documents_do`
- `videos_do`
- `formDocuments_do`
- `chartData_do`
- `quarantine`

**Alternative manuelle :** Créer dans le panneau de contrôle Craft :
1. Réglages → Actifs → Systèmes de fichiers
2. Cliquer sur "+ Nouveau système de fichiers"
3. Configurer pour chaque volume

---

### Phase 1 : Vérifications pré-migration

#### 1.1 Diagnostics

```bash
# Vérifier la connectivité
./craft ncc-module/fs-diag/test-connection images_do
./craft ncc-module/fs-diag/test-connection optimisedImages_do

# Lister tous les systèmes de fichiers
./craft ncc-module/fs-diag/list-fs

# Vérification complète
./craft ncc-module/migration-check/check-all
```

#### 1.2 Sauvegardes

```bash
# Sauvegarder la base de données
./craft db/backup

# Ou avec DDEV
ddev export-db --file=sauvegarde-avant-migration.sql.gz

# Sauvegarder les gabarits et config
tar -czf sauvegarde-fichiers.tar.gz templates/ config/ modules/
```

#### 1.3 Scanner les références S3

```bash
# Scanner les configurations de plugiciels
./craft ncc-module/plugin-config-audit/scan

# Rechercher les URL S3 codées en dur
grep -r "s3.amazonaws.com\|ncc-website-2" config/ modules/ templates/
```

---

### Phase 2 : Remplacement des URL dans la base de données

#### 2.1 Test à blanc

```bash
./craft ncc-module/url-replacement/replace-s3-urls --dryRun=1
```

Vérifier :
- Nombre de lignes affectées
- Exemples d'URL à remplacer
- Tables et colonnes à modifier

#### 2.2 Exécution

```bash
./craft ncc-module/url-replacement/replace-s3-urls
# Confirmer avec 'y'
```

#### 2.3 Vérification

```bash
./craft ncc-module/url-replacement/verify
./craft ncc-module/url-replacement/show-stats
```

**Résultat attendu :** "✓ No AWS S3 URLs found in database content"

---

### Phase 3 : Remplacement des URL dans les gabarits

#### 3.1 Scanner

```bash
./craft ncc-module/template-url/scan
```

#### 3.2 Remplacer

```bash
./craft ncc-module/template-url/replace
```

#### 3.3 Vérifier

```bash
./craft ncc-module/template-url/verify

# Ou vérification manuelle
grep -r "s3.amazonaws.com\|ncc-website-2" templates/
```

---

### Phase 4 : Migration des fichiers physiques

**Fonctionnalités :**
- Système de points de contrôle (reprise si interrompu)
- Journal des changements pour retour en arrière
- Suivi de progression
- Gestion des fichiers orphelins

#### 4.1 Préparation

```bash
./craft ncc-module/image-migration/show-plan
./craft ncc-module/image-migration/show-stats
```

#### 4.2 Test à blanc

```bash
./craft ncc-module/image-migration/migrate --dryRun=1
```

#### 4.3 Exécution

```bash
./craft ncc-module/image-migration/migrate
```

**Si interrompu :**
```bash
./craft ncc-module/image-migration/migrate  # Reprend automatiquement
```

#### 4.4 Suivi

```bash
./craft ncc-module/image-migration/status
./craft ncc-module/image-migration/show-changes
```

#### 4.5 Vérification

```bash
./craft ncc-module/migration-check/verify-files
./craft ncc-module/migration-check/check-broken-assets
```

---

### Phase 5 : Basculement des systèmes de fichiers

#### 5.1 Statut actuel

```bash
./craft ncc-module/filesystem-switch/show
```

#### 5.2 Basculer vers DigitalOcean

```bash
# Tous les volumes
./craft ncc-module/filesystem-switch/to-do

# Ou volumes individuels
./craft ncc-module/filesystem-switch/to-do images
./craft ncc-module/filesystem-switch/to-do optimisedImages
```

#### 5.3 Vérifier

```bash
./craft ncc-module/filesystem-switch/verify
./craft ncc-module/fs-diag/list-files images_do --limit=10
```

---

### Phase 6 : Tâches post-migration

#### 6.1 Reconstruire les index

```bash
# CRITIQUE : Reconstruire les index d'actifs
./craft index-assets/all

# Reconstruire les index de recherche
./craft resave/entries --update-search-index=1

# Réenregistrer tous les actifs
./craft resave/assets
```

#### 6.2 Vider les caches

```bash
# Caches Craft
./craft clear-caches/all
./craft invalidate-tags/all

# Caches de gabarits
./craft clear-caches/template-caches

# Cache de données
./craft clear-caches/data-caches
```

#### 6.3 Purger le cache CDN

Si vous utilisez CloudFlare, Fastly ou autre CDN :

```bash
# CloudFlare : Tableau de bord → Caching → Purge Everything
# Fastly : Tableau de bord → Purge → Purge All
```

#### 6.4 Diagnostics post-migration

```bash
./craft ncc-module/migration-diag/analyze
./craft ncc-module/migration-check/check-all
```

---

### Phase 7 : Transformations d'images (si applicable)

#### 7.1 Découvrir les transformations

```bash
./craft ncc-module/transform-discovery/scan
./craft ncc-module/transform-discovery/show-stats
```

#### 7.2 Pré-générer les transformations

```bash
./craft ncc-module/transform-pre-generation/generate
```

---

### Phase 8 : Vérification finale

#### 8.1 Scanner la base de données

```bash
# Scanner pour les URL AWS restantes
./craft db/query "SELECT COUNT(*) as count FROM content WHERE field_body LIKE '%s3.amazonaws%'"
./craft db/query "SELECT COUNT(*) as count FROM content WHERE field_body LIKE '%ncc-website-2%'"

# Vérifier projectconfig
./craft db/query "SELECT path FROM projectconfig WHERE value LIKE '%s3.amazonaws%'"
```

**Résultat attendu :** Toutes les requêtes retournent 0 ligne.

#### 8.2 Tests manuels

- [ ] Naviguer sur le site - les images s'affichent correctement
- [ ] Tester le téléversement d'images dans le panneau de contrôle
- [ ] Tester l'insertion d'images Redactor/CKEditor
- [ ] Vérifier le navigateur d'actifs fonctionne
- [ ] Vérifier les transformations d'images se génèrent
- [ ] Tester depuis différents navigateurs
- [ ] Vérifier la réactivité mobile

#### 8.3 Surveiller les journaux

```bash
# Surveiller les erreurs (laisser tourner quelques heures)
tail -f storage/logs/web.log
tail -f storage/logs/console.log

# Vérifier les erreurs 404
grep "404" /var/log/nginx/access.log | grep -i "\.jpg\|\.png\|\.gif\|\.svg"
```

---

### Phase 9 : Cas particuliers supplémentaires

#### 9.1 Configurations de plugiciels

```bash
# Vérifier les fichiers de config
ls -la config/imager-x.php config/blitz.php config/redactor.php
```

Plugiciels courants à vérifier :
- **Imager-X :** Emplacements de stockage des transformations
- **Blitz :** Stockage du cache statique
- **Redactor :** Chemins de config personnalisés
- **Feed Me :** URL sources d'importation

#### 9.2 Champs JSON

```bash
# Rechercher les URL S3 dans les champs JSON
./craft db/query "SELECT * FROM content WHERE field_tableField LIKE '%s3.amazonaws%' LIMIT 5"
```

#### 9.3 Actifs statiques (JS/CSS)

```bash
# Rechercher les URL S3 codées en dur
grep -r "s3.amazonaws.com\|ncc-website-2" web/assets/ web/dist/
```

---

## Contrôleurs disponibles

### 1. FilesystemController
Créer les systèmes de fichiers DigitalOcean Spaces.

```bash
./craft ncc-module/filesystem/show-plan
./craft ncc-module/filesystem/create-all
./craft ncc-module/filesystem/create images_do
```

### 2. FilesystemSwitchController
Basculer les volumes entre AWS et DO.

```bash
./craft ncc-module/filesystem-switch/show
./craft ncc-module/filesystem-switch/to-do [handle-volume]
./craft ncc-module/filesystem-switch/to-aws [handle-volume]
./craft ncc-module/filesystem-switch/verify
```

### 3. UrlReplacementController
Remplacer les URL AWS S3 par les URL DO Spaces dans la base de données.

```bash
./craft ncc-module/url-replacement/replace-s3-urls --dryRun=1
./craft ncc-module/url-replacement/replace-s3-urls
./craft ncc-module/url-replacement/verify
./craft ncc-module/url-replacement/show-stats
```

### 4. TemplateUrlReplacementController
Remplacer les URL AWS S3 dans les gabarits Twig.

```bash
./craft ncc-module/template-url/scan
./craft ncc-module/template-url/replace
./craft ncc-module/template-url/verify
./craft ncc-module/template-url/list-backups
```

### 5. ImageMigrationController
Migrer les fichiers d'actifs physiques d'AWS vers DO.

```bash
./craft ncc-module/image-migration/show-plan
./craft ncc-module/image-migration/migrate --dryRun=1
./craft ncc-module/image-migration/migrate
./craft ncc-module/image-migration/status
./craft ncc-module/image-migration/show-changes
./craft ncc-module/image-migration/rollback
```

### 6. MigrationCheckController
Validation et vérifications pré-migration.

```bash
./craft ncc-module/migration-check/check-all
./craft ncc-module/migration-check/check-filesystems
./craft ncc-module/migration-check/check-credentials
./craft ncc-module/migration-check/check-volumes
./craft ncc-module/migration-check/verify-files
./craft ncc-module/migration-check/check-broken-assets
```

### 7. FsDiagController
Diagnostics des systèmes de fichiers.

```bash
./craft ncc-module/fs-diag/list-fs
./craft ncc-module/fs-diag/test-connection [handle-système-fichiers]
./craft ncc-module/fs-diag/list-files [handle-système-fichiers] --limit=20
./craft ncc-module/fs-diag/info [handle-système-fichiers]
```

### 8. MigrationDiagController
Analyse et diagnostics post-migration.

```bash
./craft ncc-module/migration-diag/analyze
./craft ncc-module/migration-diag/check-volumes
./craft ncc-module/migration-diag/check-assets
./craft ncc-module/migration-diag/check-transforms
```

### 9. TransformDiscoveryController
Découvrir les transformations d'images.

```bash
./craft ncc-module/transform-discovery/scan
./craft ncc-module/transform-discovery/show-stats
./craft ncc-module/transform-discovery/list
```

### 10. TransformPreGenerationController
Pré-générer les transformations d'images.

```bash
./craft ncc-module/transform-pre-generation/generate
./craft ncc-module/transform-pre-generation/generate --volume=images
./craft ncc-module/transform-pre-generation/status
```

### 11. PluginConfigAuditController
Auditer les configurations de plugiciels.

```bash
./craft ncc-module/plugin-config-audit/list-plugins
./craft ncc-module/plugin-config-audit/scan
```

---

## Dépannage

### Images ne s'affichent pas

```bash
./craft clear-caches/all
./craft ncc-module/filesystem-switch/verify
./craft ncc-module/fs-diag/test-connection images_do
./craft ncc-module/fs-diag/list-files images_do --limit=10
```

### URL AWS encore présentes

```bash
./craft db/query "SELECT * FROM content WHERE field_body LIKE '%s3.amazonaws%' LIMIT 1"
./craft db/query "SELECT * FROM projectconfig WHERE value LIKE '%s3.amazonaws%'"
./craft db/query "SELECT * FROM elements_sites WHERE metadata LIKE '%s3.amazonaws%'"
./craft db/query "SELECT * FROM revisions WHERE data LIKE '%s3.amazonaws%'"
```

### Transformations ne se génèrent pas

```bash
./craft ncc-module/fs-diag/test-connection imageTransforms_do
./craft ncc-module/fs-diag/info imageTransforms_do
./craft clear-caches/asset-transform-index
./craft clear-caches/asset-indexes
```

### Migration interrompue

```bash
# Reprendre automatiquement
./craft ncc-module/image-migration/migrate

# Vérifier le statut
./craft ncc-module/image-migration/status
./craft ncc-module/image-migration/show-changes
```

### Erreurs de permissions

```bash
# Vérifier les permissions dans le tableau de bord DO Spaces
# Vérifier les informations d'identification
./craft ncc-module/fs-diag/test-connection images_do
```

### Utilisation élevée de la mémoire

```bash
# Réduire la taille des lots
# Modifier ImageMigrationController.php:
# Ligne ~80: private $batchSize = 50;

# Augmenter la limite de mémoire PHP
# Dans .env:
PHP_MEMORY_LIMIT=512M
```

### Activer la journalisation de débogage

```bash
# Dans .env
CRAFT_DEV_MODE=true
CRAFT_LOG_LEVEL=4

# Surveiller les journaux
tail -f storage/logs/console.log
tail -f storage/logs/web.log
```

---

## Critères de réussite

La migration est **100% complète** lorsque :

- ✅ Base de données : Aucune URL AWS dans les tables de contenu
- ✅ Gabarits : Aucune URL AWS dans les fichiers de gabarits
- ✅ Fichiers : Tous les actifs migrés vers DO Spaces
- ✅ Volumes : Tous les volumes pointent vers les systèmes de fichiers DO
- ✅ Site web : Les images s'affichent correctement
- ✅ Admin : Le navigateur d'actifs fonctionne dans le panneau de contrôle
- ✅ Téléversements : Les nouveaux téléversements fonctionnent
- ✅ Transformations : Les transformations d'images se génèrent
- ✅ Recherche : Les index de recherche reconstruits
- ✅ Caches : Tous les caches vidés (Craft + CDN)
- ✅ Journaux : Aucune erreur 404 pour les actifs
- ✅ Plugiciels : Configurations des plugiciels mises à jour
- ✅ Tests : Tests manuels réussis

---

## Référence rapide des commandes

```bash
# === CONFIGURATION (À FAIRE EN PREMIER!) ===
./craft ncc-module/filesystem/create-all

# === PRÉ-MIGRATION ===
./craft ncc-module/fs-diag/list-fs
./craft ncc-module/migration-check/check-all
./craft db/backup

# === BASE DE DONNÉES ===
./craft ncc-module/url-replacement/replace-s3-urls --dryRun=1
./craft ncc-module/url-replacement/replace-s3-urls
./craft ncc-module/url-replacement/verify

# === GABARITS ===
./craft ncc-module/template-url/scan
./craft ncc-module/template-url/replace
./craft ncc-module/template-url/verify

# === FICHIERS ===
./craft ncc-module/image-migration/migrate
./craft ncc-module/image-migration/status

# === BASCULEMENT ===
./craft ncc-module/filesystem-switch/to-do
./craft ncc-module/filesystem-switch/verify

# === POST-MIGRATION ===
./craft index-assets/all
./craft resave/entries --update-search-index=1
./craft clear-caches/all
./craft ncc-module/migration-diag/analyze
```

---

## Documentation

### Documentation principale

| Fichier | Description |
|---------|-------------|
| **README_FR.md** | Guide principal (ce fichier) |
| **Fiche_Reference_FR.md** | Fiche de référence rapide |
| **README.md** | Guide principal (anglais) |
| **MIGRATION_ANALYSIS.md** | Analyse de couverture complète |
| **QUICK_CHECKLIST.md** | Liste de vérification rapide |
| **migrationGuide.md** | Guide opérationnel détaillé |

### Documentation de configuration

| Fichier | Description |
|---------|-------------|
| **CONFIGURATION_GUIDE.md** | Guide du système de configuration |
| **CONFIG_QUICK_REFERENCE.md** | Référence rapide de configuration |
| **config/migration-config.php** | Configuration centralisée |
| **MigrationConfig.php** | Classe helper de configuration |

### Documentation avancée

| Fichier | Description |
|---------|-------------|
| **EXTENDED_CONTROLLERS.md** | Contrôleurs supplémentaires pour cas particuliers |
| **ARCHITECTURE_RECOMMENDATION.md** | Recommandations d'architecture |
| **MANAGER_EXTRACTION_GUIDE.md** | Guide d'extraction des gestionnaires |

---

## Statistiques de migration

### Source (AWS S3)
- **Compartiment :** ncc-website-2
- **Région :** ca-central-1
- **Formats d'URL :** 6 modèles différents détectés

### Destination (DigitalOcean Spaces)
- **Région :** tor1 (Toronto)
- **Systèmes de fichiers :** 8
- **Sous-dossiers :** Configurables par système de fichiers

### Trousse
- **Contrôleurs :** 11 contrôleurs spécialisés
- **Documentation :** 9 guides complets
- **Couverture :** 85-90% automatisée → 95-98% avec étapes supplémentaires
- **Espace de noms :** `ncc-module`
- **Temps estimé :** 3-5 jours pour une migration complète

---

## Ressources

- [Documentation Craft CMS 4](https://craftcms.com/docs/4.x/)
- [Documentation DigitalOcean Spaces](https://docs.digitalocean.com/products/spaces/)
- [Plugiciel vaersaagod/dospaces](https://github.com/vaersaagod/dospaces)

---

**Projet :** do-migration
**Statut :** Prêt pour l'exécution 🚀
**Objectif :** Migration 100% AWS S3 → DigitalOcean Spaces
**Confiance :** 95-98% de couverture réalisable
**Dernière mise à jour :** 2025-11-05
**Version :** 2.0

## Annexe 1
### Commandes
```bash
- ncc-module/extended-url-replacement                                    Extended URL Replacement Controller
    ncc-module/extended-url-replacement/replace-additional               Replace AWS S3 URLs in additional tables
    ncc-module/extended-url-replacement/replace-json                     Replace URLs in JSON fields
    ncc-module/extended-url-replacement/scan-additional (default)        Scan additional database tables for AWS S3 URLs

- ncc-module/filesystem                                                  Filesystem setup commands
    ncc-module/filesystem/create                                         Create DigitalOcean Spaces filesystems
    ncc-module/filesystem/delete                                         Delete all DigitalOcean Spaces filesystems
    ncc-module/filesystem/list                                           List all configured filesystems

- ncc-module/filesystem-switch                                           Filesystem Switch Controller (Craft 4 compatible)
    ncc-module/filesystem-switch/list-filesystems                        List all filesystems defined in Project Config
    ncc-module/filesystem-switch/preview (default)                       Preview what will be changed (dry run)
    ncc-module/filesystem-switch/test-connectivity                       Test connectivity to all filesystems defined in Project Config
    ncc-module/filesystem-switch/to-aws                                  Rollback to AWS S3
    ncc-module/filesystem-switch/to-do                                   Switch to DigitalOcean Spaces
    ncc-module/filesystem-switch/verify                                  Verify current filesystem setup

- ncc-module/fs-diag                                                     Enhanced Filesystem Diagnostic Tool
    ncc-module/fs-diag/compare-fs                                        Compare two filesystems to find differences
    ncc-module/fs-diag/list-fs                                           List files in a filesystem by handle (NO VOLUME REQUIRED)
    ncc-module/fs-diag/search-fs                                         Search for specific files in a filesystem by handle
    ncc-module/fs-diag/verify-fs                                         Verify if specific file exists in filesystem

- ncc-module/image-migration                                             Asset Migration Controller - PRODUCTION GRADE v4.0
    ncc-module/image-migration/cleanup                                   Cleanup old checkpoints and logs
    ncc-module/image-migration/force-cleanup                             Force cleanup - removes ALL locks and old data
    ncc-module/image-migration/migrate (default)                         Main migration action with checkpoint/resume support
    ncc-module/image-migration/monitor                                   Monitor migration progress in real-time
    ncc-module/image-migration/rollback                                  Rollback migration using change log
    ncc-module/image-migration/status                                    List available checkpoints and migrations

- ncc-module/migration-check                                             Pre-Migration Diagnostic
    ncc-module/migration-check/analyze                                   Show detailed asset analysis
    ncc-module/migration-check/check (default)                           Run comprehensive pre-migration checks

- ncc-module/migration-diag                                              Post-Migration Diagnostic Controller
    ncc-module/migration-diag/analyze (default)                          Analyze current state after migration
    ncc-module/migration-diag/check-missing-files                        Check for missing files that caused errors
    ncc-module/migration-diag/move-originals                             Move assets from /originals to /images

- ncc-module/plugin-config-audit                                         Plugin Configuration Audit Controller
    ncc-module/plugin-config-audit/list-plugins                          List all installed plugins
    ncc-module/plugin-config-audit/scan (default)                        Scan plugin configurations for S3 URLs

- ncc-module/static-asset-scan                                           Static Asset Scan Controller
    ncc-module/static-asset-scan/scan (default)                          Scan JS and CSS files for S3 URLs

- ncc-module/template-url-replacement                                    Template URL Replacement Controller
    ncc-module/template-url-replacement/replace                          Replace hardcoded URLs with environment variables
    ncc-module/template-url-replacement/restore-backups                  Restore templates from backups
    ncc-module/template-url-replacement/scan (default)                   Scan templates for hardcoded AWS S3 URLs
    ncc-module/template-url-replacement/verify                           Verify no AWS URLs remain in templates

- ncc-module/transform-discovery                                         Transform Discovery Controller (ENHANCED)
    ncc-module/transform-discovery/discover (default)                    Discover ALL transforms (database + templates)
    ncc-module/transform-discovery/scan-database                         Scan only database
    ncc-module/transform-discovery/scan-templates                        Scan only Twig templates

- ncc-module/transform-pre-generation                                    Pre-Generate Image Transforms Controller
    ncc-module/transform-pre-generation/discover (default)               Discover all image transforms being used in the database
    ncc-module/transform-pre-generation/generate                         Generate transforms based on discovery report
    ncc-module/transform-pre-generation/verify                           Verify that transforms exist for all discovered references
    ncc-module/transform-pre-generation/warmup                           Warm up transforms by visiting pages (simulates real traffic)

- ncc-module/url-replacement                                             
    ncc-module/url-replacement/replace-s3-urls (default)                 Replace AWS S3 URLs with DigitalOcean Spaces URLs
    ncc-module/url-replacement/show-samples                              Show sample URLs from the database (helps verify correct paths)
    ncc-module/url-replacement/verify                                    Verify that no AWS S3 URLs remain in the database
```
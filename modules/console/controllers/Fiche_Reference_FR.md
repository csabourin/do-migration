# Fiche de référence - Migration AWS S3 vers DO Spaces

**Référence rapide pour Craft CMS 4 | Migration ${AWS_SOURCE_BUCKET} → DigitalOcean Spaces tor1**

---

## ⚡ Commandes essentielles

### Configuration initiale (UNE FOIS)

```bash
# 1. Créer les systèmes de fichiers DO
./craft ncc-module/filesystem/create-all

# 2. Configurer transform filesystem pour TOUS les volumes
./craft ncc-module/volume-config/set-transform-filesystem

# 3. Vérifier
./craft ncc-module/fs-diag/list-fs
./craft ncc-module/volume-config/status

# 4. Sauvegarder
./craft db/backup
ddev export-db --file=sauvegarde-avant-migration.sql.gz
```

### Migration complète (ORDRE)

```bash
# PHASE 1: Pré-vérifications
./craft ncc-module/migration-check/check
./craft ncc-module/plugin-config-audit/scan

# PHASE 2: Base de données
./craft ncc-module/url-replacement/replace-s3-urls dryRun=1    # Test
./craft ncc-module/url-replacement/replace-s3-urls             # Réel
./craft ncc-module/url-replacement/verify                       # Vérifier

# PHASE 3: Gabarits
./craft ncc-module/template-url-replacement/scan               # Scanner
./craft ncc-module/template-url-replacement/replace            # Remplacer
./craft ncc-module/template-url-replacement/verify             # Vérifier

# PHASE 4: Fichiers
./craft ncc-module/image-migration/migrate dryRun=1            # Test
./craft ncc-module/image-migration/migrate                     # Réel

# PHASE 5: Basculement
./craft ncc-module/filesystem-switch/to-do                     # Basculer
./craft ncc-module/filesystem-switch/verify                    # Vérifier

# PHASE 6: Post-migration (CRITIQUE!)
./craft index-assets/all                                       # Index
./craft resave/entries --update-search-index=1                 # Recherche
./craft clear-caches/all                                       # Caches
./craft ncc-module/migration-diag/analyze                      # Diagnostics

# PHASE 7: Ajouter optimisedImagesField AVANT transforms (CRITIQUE!)
./craft ncc-module/volume-config/add-optimised-field images_do # Ajouter champ

# PHASE 8: Transformations
./craft ncc-module/transform-pre-generation/discover           # Découvrir
./craft ncc-module/transform-pre-generation/generate           # Générer
```

---

## 📋 Liste de vérification complète

### ☐ Avant migration

- [ ] **Plugiciel DO Spaces installé** : `composer require vaersaagod/dospaces`
- [ ] **rclone installé** : `which rclone`
- [ ] **Sync AWS → DO fraîche complétée** : `rclone copy aws-s3:bucket do:bucket -P`
- [ ] Sauvegarde base de données : `./craft db/backup`
- [ ] Sauvegarde fichiers : `tar -czf sauvegarde.tar.gz templates/ config/`
- [ ] Systèmes de fichiers DO créés : `./craft ncc-module/filesystem/create`
- [ ] **Transform filesystem configuré** : `./craft ncc-module/volume-config/set-transform-filesystem`
- [ ] Connectivité vérifiée : `./craft ncc-module/filesystem-switch/test-connectivity`
- [ ] Variables d'environnement configurées dans `.env`
- [ ] **Vérifications pré-migration** : `./craft ncc-module/migration-check/check`
- [ ] Scanner plugiciels : `./craft ncc-module/plugin-config-audit/scan`

### ☐ Migration base de données

- [ ] Afficher exemples : `./craft ncc-module/url-replacement/show-samples`
- [ ] Exécution réelle : `./craft ncc-module/url-replacement/replace-s3-urls`
- [ ] Vérification : `./craft ncc-module/url-replacement/verify`
- [ ] Aucune URL AWS trouvée ✓

### ☐ Migration gabarits

- [ ] Scanner : `./craft ncc-module/template-url-replacement/scan`
- [ ] Remplacer : `./craft ncc-module/template-url-replacement/replace`
- [ ] Vérifier : `./craft ncc-module/template-url-replacement/verify`
- [ ] Vérification manuelle : `grep -r "s3.amazonaws" templates/`

### ☐ Migration fichiers

- [ ] Vérifier statut : `./craft ncc-module/image-migration/status`
- [ ] Test à blanc : `./craft ncc-module/image-migration/migrate dryRun=1`
- [ ] Exécution réelle : `./craft ncc-module/image-migration/migrate`
- [ ] Surveiller : `./craft ncc-module/image-migration/monitor`
- [ ] Vérifier fichiers : `./craft ncc-module/migration-check/analyze`

### ☐ Basculement volumes

- [ ] Aperçu (dry run) : `./craft ncc-module/filesystem-switch/preview`
- [ ] Basculer vers DO : `./craft ncc-module/filesystem-switch/to-do`
- [ ] Vérifier basculement : `./craft ncc-module/filesystem-switch/verify`

### ☐ Post-migration (CRITIQUE!)

- [ ] Reconstruire index actifs : `./craft index-assets/all`
- [ ] Reconstruire index recherche : `./craft resave/entries --update-search-index=1`
- [ ] Réenregistrer actifs : `./craft resave/assets`
- [ ] Vider caches Craft : `./craft clear-caches/all`
- [ ] Vider caches gabarits : `./craft clear-caches/template-caches`
- [ ] Purger CDN (CloudFlare/Fastly)
- [ ] Diagnostics : `./craft ncc-module/migration-diag/analyze`
- [ ] **Ajouter optimisedImagesField** : `./craft ncc-module/volume-config/add-optimised-field images_do`
- [ ] **Vérifier configuration** : `./craft ncc-module/volume-config/status`

### ☐ Vérification finale

- [ ] Scanner BD : `./craft db/query "SELECT COUNT(*) FROM content WHERE field_body LIKE '%s3.amazonaws%'"`
- [ ] Résultat = 0 ✓
- [ ] Images s'affichent sur le site ✓
- [ ] Navigateur d'actifs fonctionne ✓
- [ ] Téléversements fonctionnent ✓
- [ ] Transformations se génèrent ✓
- [ ] Aucune erreur 404 dans les journaux ✓
- [ ] Tests manuels réussis ✓

### ☐ Cas particuliers

- [ ] Vérifier configs plugiciels : `ls -la config/imager-x.php config/blitz.php`
- [ ] Champs JSON : `./craft db/query "SELECT * FROM content WHERE field_tableField LIKE '%s3.amazonaws%'"`
- [ ] Actifs statiques : `grep -r "s3.amazonaws" web/assets/ web/dist/`
- [ ] Projectconfig : `./craft db/query "SELECT path FROM projectconfig WHERE value LIKE '%s3.amazonaws%'"`

---

## 🔧 Contrôleurs par catégorie

### Configuration

```bash
./craft ncc-module/filesystem/list                   # Lister systèmes fichiers
./craft ncc-module/filesystem/create                 # Créer systèmes fichiers DO
./craft ncc-module/filesystem/delete                 # Supprimer systèmes fichiers DO

./craft ncc-module/volume-config/status              # Afficher état configuration volumes
./craft ncc-module/volume-config/set-transform-filesystem  # Configurer transform filesystem
./craft ncc-module/volume-config/add-optimised-field       # Ajouter optimisedImagesField
./craft ncc-module/volume-config/configure-all             # Configurer tout (convenience)
```

### Diagnostic

```bash
./craft ncc-module/fs-diag/list-fs                   # Lister fichiers dans système
./craft ncc-module/fs-diag/compare-fs                # Comparer deux systèmes fichiers
./craft ncc-module/fs-diag/search-fs                 # Rechercher fichiers spécifiques
./craft ncc-module/fs-diag/verify-fs                 # Vérifier si fichier existe
```

### Vérification

```bash
./craft ncc-module/migration-check/check             # Vérifier tout (défaut)
./craft ncc-module/migration-check/analyze           # Analyser actifs en détail
```

### Remplacement URL

```bash
./craft ncc-module/url-replacement/show-samples      # Afficher exemples URL
./craft ncc-module/url-replacement/replace-s3-urls   # Remplacer (défaut)
./craft ncc-module/url-replacement/verify            # Vérifier aucune URL AWS
```

### Gabarits

```bash
./craft ncc-module/template-url-replacement/scan            # Scanner (défaut)
./craft ncc-module/template-url-replacement/replace         # Remplacer
./craft ncc-module/template-url-replacement/verify          # Vérifier
./craft ncc-module/template-url-replacement/restore-backups # Restaurer sauvegardes
```

### Migration fichiers

```bash
./craft ncc-module/image-migration/status            # Statut/checkpoints
./craft ncc-module/image-migration/migrate dryRun=1  # Test à blanc
./craft ncc-module/image-migration/migrate           # Exécuter migration
./craft ncc-module/image-migration/monitor           # Surveiller en temps réel
./craft ncc-module/image-migration/rollback          # Retour arrière
./craft ncc-module/image-migration/cleanup           # Nettoyer checkpoints (72h)
./craft ncc-module/image-migration/force-cleanup     # Forcer nettoyage (TOUS verrous)

# Flags disponibles pour migrate:
#   dryRun=1              - Test sans modifications
#   skipBackup=1          - Sauter la sauvegarde
#   skipInlineDetection=1 - Sauter détection inline (plus rapide)
#   resume=1              - Reprendre migration interrompue
#   checkpointId=<id>     - Reprendre depuis checkpoint spécifique
#   skipLock=1            - Ignorer verrou (dangereux!)

# Exemples
./craft ncc-module/image-migration/migrate resume=1
./craft ncc-module/image-migration/migrate checkpointId=migration_20250105_143022
./craft ncc-module/image-migration/cleanup olderThanHours=48
```

### Basculement

```bash
./craft ncc-module/filesystem-switch/preview            # Aperçu (dry run, défaut)
./craft ncc-module/filesystem-switch/list-filesystems   # Lister systèmes fichiers
./craft ncc-module/filesystem-switch/test-connectivity  # Tester connectivité
./craft ncc-module/filesystem-switch/to-do              # Basculer vers DO
./craft ncc-module/filesystem-switch/to-aws             # Retour vers AWS
./craft ncc-module/filesystem-switch/verify             # Vérifier setup
```

### Analyse post-migration

```bash
./craft ncc-module/migration-diag/analyze               # Analyser (défaut)
./craft ncc-module/migration-diag/check-missing-files   # Vérifier fichiers manquants
./craft ncc-module/migration-diag/move-originals        # Déplacer originaux
```

### Transformations

```bash
# Découverte
./craft ncc-module/transform-discovery/discover         # Découvrir tout (défaut)
./craft ncc-module/transform-discovery/scan-database    # Scanner BD seulement
./craft ncc-module/transform-discovery/scan-templates   # Scanner gabarits seulement

# Pré-génération
./craft ncc-module/transform-pre-generation/discover    # Découvrir (défaut)
./craft ncc-module/transform-pre-generation/generate    # Générer
./craft ncc-module/transform-pre-generation/verify      # Vérifier
./craft ncc-module/transform-pre-generation/warmup      # Préchauffer
```

### Plugiciels

```bash
./craft ncc-module/plugin-config-audit/list-plugins  # Lister plugiciels
./craft ncc-module/plugin-config-audit/scan          # Scanner configs
```

---

## 🚨 Dépannage rapide

### Images ne s'affichent pas

```bash
./craft clear-caches/all
./craft ncc-module/filesystem-switch/verify
./craft ncc-module/filesystem-switch/test-connectivity
./craft ncc-module/fs-diag/verify-fs
```

### URL AWS encore présentes

```bash
./craft db/query "SELECT * FROM content WHERE field_body LIKE '%s3.amazonaws%' LIMIT 5"
./craft db/query "SELECT * FROM projectconfig WHERE value LIKE '%s3.amazonaws%'"
```

### Migration interrompue

```bash
./craft ncc-module/image-migration/migrate  # Reprend automatiquement
./craft ncc-module/image-migration/status   # Vérifier statut
```

### Transformations ne se génèrent pas

```bash
./craft ncc-module/fs-diag/verify-fs
./craft clear-caches/asset-transform-index
./craft clear-caches/asset-indexes
```

### Erreurs de mémoire

```bash
# Augmenter dans .env
PHP_MEMORY_LIMIT=512M
```

### Activer débogage

```bash
# Dans .env
CRAFT_DEV_MODE=true
CRAFT_LOG_LEVEL=4

# Surveiller
tail -f storage/logs/console.log
```

---

## 📊 Requêtes SQL utiles

### Rechercher URL AWS

```sql
-- Recherche générale
SELECT COUNT(*) FROM content WHERE field_body LIKE '%s3.amazonaws%';
SELECT COUNT(*) FROM content WHERE field_body LIKE '%${AWS_SOURCE_BUCKET}%';

-- Projectconfig
SELECT path FROM projectconfig WHERE value LIKE '%s3.amazonaws%';

-- Metadata
SELECT * FROM elements_sites WHERE metadata LIKE '%s3.amazonaws%';

-- Révisions
SELECT * FROM revisions WHERE data LIKE '%s3.amazonaws%';

-- Champs JSON spécifiques (remplacer field_XXX)
SELECT * FROM content WHERE field_tableData LIKE '%s3.amazonaws%';
```

### Vérification complète

```sql
-- Aucune URL AWS dans content
SELECT table_name, column_name
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name LIKE '%content%'
  AND data_type IN ('text', 'mediumtext', 'longtext');

-- Scanner chaque colonne pour S3
-- (Utiliser le contrôleur url-replacement pour automatiser)
```

---

## 📁 Variables d'environnement (.env)

```bash
# Environnement
MIGRATION_ENV=dev  # dev, staging, prod

# DigitalOcean Spaces
DO_S3_ACCESS_KEY=votre_clé_accès
DO_S3_SECRET_KEY=votre_clé_secrète
DO_S3_BUCKET=votre-compartiment
DO_S3_BASE_URL=https://votre-compartiment.tor1.digitaloceanspaces.com
DO_S3_REGION=tor1

# Sous-dossiers (optionnel)
DO_S3_SUBFOLDER_IMAGES=images
DO_S3_SUBFOLDER_OPTIMISEDIMAGES=optimisedImages
DO_S3_SUBFOLDER_IMAGETRANSFORMS=imageTransforms
DO_S3_SUBFOLDER_DOCUMENTS=documents
DO_S3_SUBFOLDER_VIDEOS=videos
DO_S3_SUBFOLDER_FORMDOCUMENTS=formDocuments
DO_S3_SUBFOLDER_CHARTDATA=chartData
DO_S3_SUBFOLDER_QUARANTINE=quarantine

# Débogage
CRAFT_DEV_MODE=true
CRAFT_LOG_LEVEL=4
PHP_MEMORY_LIMIT=512M
```

---

## 🎯 Scénarios courants

### Migration complète (première fois)

```bash
# 1. Configuration
./craft ncc-module/filesystem/create
./craft db/backup

# 2. Vérifications
./craft ncc-module/migration-check/check
./craft ncc-module/filesystem-switch/preview

# 3. Migration
./craft ncc-module/url-replacement/replace-s3-urls
./craft ncc-module/template-url-replacement/replace
./craft ncc-module/image-migration/migrate dryRun=1  # Test d'abord
./craft ncc-module/image-migration/migrate           # Puis exécuter

# 4. Basculement
./craft ncc-module/filesystem-switch/to-do

# 5. Post-migration
./craft index-assets/all
./craft resave/entries --update-search-index=1
./craft clear-caches/all
```

### Reprise après interruption

```bash
# Reprendre depuis le dernier checkpoint
./craft ncc-module/image-migration/migrate resume=1

# Ou reprendre depuis un checkpoint spécifique
./craft ncc-module/image-migration/status  # Liste les checkpoints disponibles
./craft ncc-module/image-migration/migrate checkpointId=<id>

# Surveiller la progression
./craft ncc-module/image-migration/monitor
```

### Retour arrière (rollback)

```bash
# Retour arrière migration fichiers
./craft ncc-module/image-migration/rollback

# Retour arrière basculement volumes
./craft ncc-module/filesystem-switch/to-aws

# Restaurer sauvegarde BD
./craft db/restore sauvegarde-avant-migration.sql
```

### Test sur environnement dev

```bash
# 1. Configurer .env
MIGRATION_ENV=dev
DO_S3_BASE_URL=https://dev-medias-test.tor1.digitaloceanspaces.com

# 2. Tester avec dry-run
./craft ncc-module/image-migration/migrate dryRun=1

# 3. Exécuter si OK
./craft ncc-module/url-replacement/replace-s3-urls
./craft ncc-module/image-migration/migrate
```

### Vérification après migration

```bash
# 1. Vérifier aucune URL AWS
./craft ncc-module/url-replacement/verify
./craft ncc-module/template-url-replacement/verify

# 2. Vérifier fichiers
./craft ncc-module/migration-diag/check-missing-files

# 3. Scanner BD manuellement
./craft db/query "SELECT COUNT(*) FROM content WHERE field_body LIKE '%s3.amazonaws%'"

# 4. Diagnostics complets
./craft ncc-module/migration-diag/analyze
```

---

## 🔑 Points critiques

### ⚠️ À NE PAS OUBLIER

1. **Installer DO Spaces plugin AVANT toute opération**
   ```bash
   composer require vaersaagod/dospaces
   ./craft plugin/install dospaces
   ```

2. **Installer rclone et sync AWS → DO AVANT migration**
   ```bash
   rclone copy aws-s3:bucket do:bucket -P
   ```

3. **Créer systèmes de fichiers AVANT migration**
   ```bash
   ./craft ncc-module/filesystem/create-all
   ```

4. **Configurer transform filesystem pour TOUS les volumes**
   ```bash
   ./craft ncc-module/volume-config/set-transform-filesystem
   ```

5. **Sauvegarder AVANT toute opération**
   ```bash
   ./craft db/backup
   ddev export-db --file=sauvegarde.sql.gz
   ```

6. **Toujours tester avec dryRun=1 d'abord**
   ```bash
   ./craft ncc-module/image-migration/migrate dryRun=1
   ```

7. **Reconstruire index APRÈS migration**
   ```bash
   ./craft index-assets/all
   ./craft resave/entries --update-search-index=1
   ```

8. **Ajouter optimisedImagesField AVANT générer transforms**
   ```bash
   ./craft ncc-module/volume-config/add-optimised-field images_do
   ```

9. **Vider caches APRÈS migration**
   ```bash
   ./craft clear-caches/all
   # + Purger CDN manuellement
   ```

### ✅ Ordre obligatoire

```
0. Installer DO Spaces plugin + rclone
1. Sync AWS → DO (rclone)
2. Créer systèmes de fichiers DO
3. Configurer transform filesystem pour TOUS les volumes
4. Sauvegarder tout
5. Vérifications pré-migration
6. Remplacer URL base de données
7. Remplacer URL gabarits
8. Migrer fichiers physiques
9. Basculer volumes vers DO
10. Reconstruire index
11. Ajouter optimisedImagesField
12. Vider caches
13. Générer transformations
14. Vérification finale
```

### 🚫 Erreurs courantes

- ❌ Oublier d'installer le plugiciel DO Spaces
- ❌ Ne pas avoir de sync AWS → DO fraîche avant migration
- ❌ Oublier de créer les systèmes de fichiers DO d'abord
- ❌ Ne pas configurer le transform filesystem pour les volumes
- ❌ Ne pas sauvegarder avant de commencer
- ❌ Sauter l'étape de test (dryRun=1)
- ❌ Oublier de reconstruire les index après migration
- ❌ Oublier d'ajouter optimisedImagesField AVANT de générer les transforms
- ❌ Ne pas vider les caches (Craft + CDN)
- ❌ Ne pas vérifier les configurations de plugiciels
- ❌ Basculer les volumes avant de migrer les fichiers

---

## 📞 Support

### Journaux

```bash
# Console
tail -f storage/logs/console.log

# Web
tail -f storage/logs/web.log

# Erreurs 404
grep "404" /var/log/nginx/access.log | grep -i "\.jpg\|\.png\|\.gif"
```

### Documentation

- **README_FR.md** - Guide complet
- **README.md** - Guide complet (anglais)
- **MIGRATION_ANALYSIS.md** - Analyse détaillée
- **CONFIGURATION_GUIDE.md** - Guide de configuration

---

## 📈 Statistiques

- **Contrôleurs :** 14 (dont 1 nouveau: volume-config)
- **Systèmes de fichiers :** 8
- **Couverture :** 95-98%
- **Temps estimé :** 3-5 jours
- **Namespace :** `ncc-module`
- **Automation :** Configuration automatisée des volumes et transforms
- **Vérifications automatiques :** 10 checks pré-migration

---

**Version :** 2.0 | **Date :** 2025-11-05 | **Projet :** do-migration

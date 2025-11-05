# Fiche de référence - Migration AWS S3 vers DO Spaces

**Référence rapide pour Craft CMS 4 | Migration ncc-website-2 → DigitalOcean Spaces tor1**

---

## ⚡ Commandes essentielles

### Configuration initiale (UNE FOIS)

```bash
# 1. Créer les systèmes de fichiers DO
./craft ncc-module/filesystem/create-all

# 2. Vérifier
./craft ncc-module/fs-diag/list-fs

# 3. Sauvegarder
./craft db/backup
ddev export-db --file=sauvegarde-avant-migration.sql.gz
```

### Migration complète (ORDRE)

```bash
# PHASE 1: Pré-vérifications
./craft ncc-module/migration-check/check-all
./craft ncc-module/plugin-config-audit/scan

# PHASE 2: Base de données
./craft ncc-module/url-replacement/replace-s3-urls --dryRun=1  # Test
./craft ncc-module/url-replacement/replace-s3-urls             # Réel
./craft ncc-module/url-replacement/verify                       # Vérifier

# PHASE 3: Gabarits
./craft ncc-module/template-url/scan                           # Scanner
./craft ncc-module/template-url/replace                        # Remplacer
./craft ncc-module/template-url/verify                         # Vérifier

# PHASE 4: Fichiers
./craft ncc-module/image-migration/migrate --dryRun=1          # Test
./craft ncc-module/image-migration/migrate                     # Réel

# PHASE 5: Basculement
./craft ncc-module/filesystem-switch/to-do                     # Basculer
./craft ncc-module/filesystem-switch/verify                    # Vérifier

# PHASE 6: Post-migration (CRITIQUE!)
./craft index-assets/all                                       # Index
./craft resave/entries --update-search-index=1                 # Recherche
./craft clear-caches/all                                       # Caches
./craft ncc-module/migration-diag/analyze                      # Diagnostics
```

---

## 📋 Liste de vérification complète

### ☐ Avant migration

- [ ] Sauvegarde base de données : `./craft db/backup`
- [ ] Sauvegarde fichiers : `tar -czf sauvegarde.tar.gz templates/ config/`
- [ ] Systèmes de fichiers DO créés : `./craft ncc-module/filesystem/create-all`
- [ ] Connectivité vérifiée : `./craft ncc-module/fs-diag/test-connection images_do`
- [ ] Variables d'environnement configurées dans `.env`
- [ ] Scanner plugiciels : `./craft ncc-module/plugin-config-audit/scan`

### ☐ Migration base de données

- [ ] Test à blanc : `./craft ncc-module/url-replacement/replace-s3-urls --dryRun=1`
- [ ] Exécution réelle : `./craft ncc-module/url-replacement/replace-s3-urls`
- [ ] Vérification : `./craft ncc-module/url-replacement/verify`
- [ ] Aucune URL AWS trouvée ✓

### ☐ Migration gabarits

- [ ] Scanner : `./craft ncc-module/template-url/scan`
- [ ] Remplacer : `./craft ncc-module/template-url/replace`
- [ ] Vérifier : `./craft ncc-module/template-url/verify`
- [ ] Vérification manuelle : `grep -r "s3.amazonaws" templates/`

### ☐ Migration fichiers

- [ ] Afficher plan : `./craft ncc-module/image-migration/show-plan`
- [ ] Test à blanc : `./craft ncc-module/image-migration/migrate --dryRun=1`
- [ ] Exécution réelle : `./craft ncc-module/image-migration/migrate`
- [ ] Vérifier statut : `./craft ncc-module/image-migration/status`
- [ ] Vérifier fichiers : `./craft ncc-module/migration-check/verify-files`

### ☐ Basculement volumes

- [ ] Afficher statut : `./craft ncc-module/filesystem-switch/show`
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
./craft ncc-module/filesystem/show-plan              # Afficher plan
./craft ncc-module/filesystem/create-all             # Créer systèmes fichiers
./craft ncc-module/filesystem/create [handle]        # Créer un système fichiers
```

### Diagnostic

```bash
./craft ncc-module/fs-diag/list-fs                   # Lister systèmes fichiers
./craft ncc-module/fs-diag/test-connection [handle]  # Tester connexion
./craft ncc-module/fs-diag/list-files [handle]       # Lister fichiers
./craft ncc-module/fs-diag/info [handle]             # Info système fichiers
```

### Vérification

```bash
./craft ncc-module/migration-check/check-all         # Vérifier tout
./craft ncc-module/migration-check/check-filesystems # Vérifier systèmes fichiers
./craft ncc-module/migration-check/check-volumes     # Vérifier volumes
./craft ncc-module/migration-check/verify-files      # Vérifier fichiers
./craft ncc-module/migration-check/check-broken-assets # Vérifier actifs brisés
```

### Remplacement URL

```bash
./craft ncc-module/url-replacement/replace-s3-urls --dryRun=1  # Test
./craft ncc-module/url-replacement/replace-s3-urls             # Réel
./craft ncc-module/url-replacement/verify                       # Vérifier
./craft ncc-module/url-replacement/show-stats                   # Statistiques
```

### Gabarits

```bash
./craft ncc-module/template-url/scan                 # Scanner
./craft ncc-module/template-url/replace              # Remplacer
./craft ncc-module/template-url/verify               # Vérifier
./craft ncc-module/template-url/list-backups         # Lister sauvegardes
```

### Migration fichiers

```bash
./craft ncc-module/image-migration/show-plan         # Afficher plan
./craft ncc-module/image-migration/migrate --dryRun=1 # Test
./craft ncc-module/image-migration/migrate           # Exécuter
./craft ncc-module/image-migration/status            # Statut
./craft ncc-module/image-migration/show-changes      # Changements
./craft ncc-module/image-migration/rollback          # Retour arrière
```

### Basculement

```bash
./craft ncc-module/filesystem-switch/show            # Afficher statut
./craft ncc-module/filesystem-switch/to-do [volume]  # Basculer vers DO
./craft ncc-module/filesystem-switch/to-aws [volume] # Basculer vers AWS
./craft ncc-module/filesystem-switch/verify          # Vérifier
```

### Analyse post-migration

```bash
./craft ncc-module/migration-diag/analyze            # Analyser
./craft ncc-module/migration-diag/check-volumes      # Vérifier volumes
./craft ncc-module/migration-diag/check-assets       # Vérifier actifs
./craft ncc-module/migration-diag/check-transforms   # Vérifier transformations
```

### Transformations

```bash
./craft ncc-module/transform-discovery/scan          # Scanner
./craft ncc-module/transform-discovery/show-stats    # Statistiques
./craft ncc-module/transform-pre-generation/generate # Générer
./craft ncc-module/transform-pre-generation/status   # Statut
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
./craft ncc-module/fs-diag/test-connection images_do
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
./craft ncc-module/fs-diag/test-connection imageTransforms_do
./craft clear-caches/asset-transform-index
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
SELECT COUNT(*) FROM content WHERE field_body LIKE '%ncc-website-2%';

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
./craft ncc-module/filesystem/create-all
./craft db/backup

# 2. Vérifications
./craft ncc-module/migration-check/check-all

# 3. Migration
./craft ncc-module/url-replacement/replace-s3-urls --dryRun=1
./craft ncc-module/url-replacement/replace-s3-urls
./craft ncc-module/template-url/replace
./craft ncc-module/image-migration/migrate

# 4. Basculement
./craft ncc-module/filesystem-switch/to-do

# 5. Post-migration
./craft index-assets/all
./craft resave/entries --update-search-index=1
./craft clear-caches/all
```

### Reprise après interruption

```bash
# La migration reprend automatiquement
./craft ncc-module/image-migration/migrate

# Vérifier où on en est
./craft ncc-module/image-migration/status
./craft ncc-module/image-migration/show-changes
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
./craft ncc-module/url-replacement/replace-s3-urls --dryRun=1
./craft ncc-module/image-migration/migrate --dryRun=1

# 3. Exécuter si OK
./craft ncc-module/url-replacement/replace-s3-urls
./craft ncc-module/image-migration/migrate
```

### Vérification après migration

```bash
# 1. Vérifier aucune URL AWS
./craft ncc-module/url-replacement/verify
./craft ncc-module/template-url/verify

# 2. Vérifier fichiers
./craft ncc-module/migration-check/verify-files

# 3. Scanner BD manuellement
./craft db/query "SELECT COUNT(*) FROM content WHERE field_body LIKE '%s3.amazonaws%'"

# 4. Diagnostics complets
./craft ncc-module/migration-diag/analyze
```

---

## 🔑 Points critiques

### ⚠️ À NE PAS OUBLIER

1. **Créer systèmes de fichiers AVANT migration**
   ```bash
   ./craft ncc-module/filesystem/create-all
   ```

2. **Sauvegarder AVANT toute opération**
   ```bash
   ./craft db/backup
   ddev export-db --file=sauvegarde.sql.gz
   ```

3. **Toujours tester avec --dryRun=1 d'abord**
   ```bash
   ./craft ncc-module/url-replacement/replace-s3-urls --dryRun=1
   ./craft ncc-module/image-migration/migrate --dryRun=1
   ```

4. **Reconstruire index APRÈS migration**
   ```bash
   ./craft index-assets/all
   ./craft resave/entries --update-search-index=1
   ```

5. **Vider caches APRÈS migration**
   ```bash
   ./craft clear-caches/all
   # + Purger CDN manuellement
   ```

### ✅ Ordre obligatoire

```
0. Créer systèmes de fichiers DO
1. Sauvegarder tout
2. Vérifications pré-migration
3. Remplacer URL base de données
4. Remplacer URL gabarits
5. Migrer fichiers physiques
6. Basculer volumes vers DO
7. Reconstruire index
8. Vider caches
9. Vérification finale
```

### 🚫 Erreurs courantes

- ❌ Oublier de créer les systèmes de fichiers DO d'abord
- ❌ Ne pas sauvegarder avant de commencer
- ❌ Sauter l'étape --dryRun=1
- ❌ Oublier de reconstruire les index après migration
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

- **Contrôleurs :** 11
- **Systèmes de fichiers :** 8
- **Couverture :** 95-98%
- **Temps estimé :** 3-5 jours
- **Namespace :** `ncc-module`

---

**Version :** 2.0 | **Date :** 2025-11-05 | **Projet :** do-migration

# Fiche de référence - Migration AWS S3 vers DO Spaces

**Référence rapide pour Craft CMS 4 | Migration ncc-website-2 → DigitalOcean Spaces tor1**

---

## ⚡ Commandes essentielles

### Configuration initiale (UNE FOIS)

```bash
# 1. Créer les systèmes de fichiers DO
./craft ncc-module/filesystem/create

# 2. Vérifier
./craft ncc-module/filesystem/list

# 3. Sauvegarder
./craft db/backup
ddev export-db --file=sauvegarde-avant-migration.sql.gz
```

### Migration complète (ORDRE)

```bash
# PHASE 0: Configuration
./craft ncc-module/filesystem/create
./craft ncc-module/filesystem/list

# PHASE 1: Pré-vérifications
./craft ncc-module/migration-check/check
./craft ncc-module/migration-check/analyze
./craft ncc-module/filesystem-switch/preview
./craft ncc-module/filesystem-switch/test-connectivity
./craft ncc-module/plugin-config-audit/scan
./craft ncc-module/static-asset-scan/scan

# PHASE 2: Base de données
./craft ncc-module/url-replacement/show-samples                 # Aperçu
./craft ncc-module/url-replacement/replace-s3-urls              # Remplacer
./craft ncc-module/url-replacement/verify                       # Vérifier
./craft ncc-module/extended-url-replacement/scan-additional     # Scanner tables supp.
./craft ncc-module/extended-url-replacement/replace-additional  # Remplacer tables supp.
./craft ncc-module/extended-url-replacement/replace-json        # Remplacer JSON

# PHASE 3: Gabarits
./craft ncc-module/template-url-replacement/scan                # Scanner
./craft ncc-module/template-url-replacement/replace             # Remplacer
./craft ncc-module/template-url-replacement/verify              # Vérifier

# PHASE 4: Fichiers (Option rclone - RAPIDE)
rclone copy aws-s3:ncc-website-2 medias:medias \
  --exclude "_*/**" --fast-list --transfers=32 \
  --checkers=16 --use-mmap --s3-acl=public-read -P

# PHASE 4: Fichiers (Option Craft - PLUS LENT)
./craft ncc-module/image-migration/migrate                      # Migrer
./craft ncc-module/image-migration/monitor                      # Surveiller
./craft ncc-module/image-migration/status                       # Statut

# PHASE 5: Basculement
./craft ncc-module/filesystem-switch/preview                    # Aperçu
./craft ncc-module/filesystem-switch/to-do                      # Basculer
./craft ncc-module/filesystem-switch/verify                     # Vérifier

# PHASE 6: Post-migration (CRITIQUE!)
./craft index-assets/all                                        # Index actifs
./craft resave/entries --update-search-index=1                  # Index recherche
./craft resave/assets                                           # Réenregistrer actifs
./craft clear-caches/all                                        # Vider caches
./craft ncc-module/migration-diag/analyze                       # Diagnostics
./craft ncc-module/migration-diag/check-missing-files           # Fichiers manquants
```

---

## 📋 Liste de vérification complète

### ☐ Avant migration

- [ ] Sauvegarde BD : `./craft db/backup`
- [ ] Sauvegarde fichiers : `tar -czf sauvegarde.tar.gz templates/ config/`
- [ ] Systèmes de fichiers DO créés : `./craft ncc-module/filesystem/create`
- [ ] Connectivité vérifiée : `./craft ncc-module/filesystem-switch/test-connectivity`
- [ ] Variables d'environnement configurées dans `.env`
- [ ] Scanner plugiciels : `./craft ncc-module/plugin-config-audit/scan`
- [ ] Scanner actifs statiques : `./craft ncc-module/static-asset-scan/scan`

### ☐ Migration base de données

- [ ] Afficher exemples : `./craft ncc-module/url-replacement/show-samples`
- [ ] Exécution tables principales : `./craft ncc-module/url-replacement/replace-s3-urls`
- [ ] Vérification : `./craft ncc-module/url-replacement/verify`
- [ ] Scanner tables supp. : `./craft ncc-module/extended-url-replacement/scan-additional`
- [ ] Remplacer tables supp. : `./craft ncc-module/extended-url-replacement/replace-additional`
- [ ] Remplacer JSON : `./craft ncc-module/extended-url-replacement/replace-json`
- [ ] Aucune URL AWS trouvée ✓

### ☐ Migration gabarits

- [ ] Scanner : `./craft ncc-module/template-url-replacement/scan`
- [ ] Remplacer : `./craft ncc-module/template-url-replacement/replace`
- [ ] Vérifier : `./craft ncc-module/template-url-replacement/verify`
- [ ] Vérification manuelle : `grep -r "s3.amazonaws" templates/`

### ☐ Migration fichiers

- [ ] **Option A - rclone (RAPIDE)** : Exécuter commande rclone
- [ ] **Option B - Craft** : `./craft ncc-module/image-migration/migrate`
- [ ] Vérifier statut : `./craft ncc-module/image-migration/status`
- [ ] Vérifier fichiers : `./craft ncc-module/migration-diag/check-missing-files`
- [ ] Comparer systèmes : `./craft ncc-module/fs-diag/compare-fs`

### ☐ Basculement volumes

- [ ] Aperçu : `./craft ncc-module/filesystem-switch/preview`
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
- [ ] Vérifier fichiers manquants : `./craft ncc-module/migration-diag/check-missing-files`

### ☐ Vérification finale

- [ ] URL BD : `./craft ncc-module/url-replacement/verify` (= 0) ✓
- [ ] Tables supp. : `./craft ncc-module/extended-url-replacement/scan-additional` (= 0) ✓
- [ ] Gabarits : `./craft ncc-module/template-url-replacement/verify` (= 0) ✓
- [ ] Images s'affichent sur le site ✓
- [ ] Navigateur d'actifs fonctionne ✓
- [ ] Téléversements fonctionnent ✓
- [ ] Transformations se génèrent ✓
- [ ] Aucune erreur 404 dans les journaux ✓
- [ ] Tests manuels réussis ✓

### ☐ Cas particuliers

- [ ] Configs plugiciels : `./craft ncc-module/plugin-config-audit/scan`
- [ ] Actifs statiques : `./craft ncc-module/static-asset-scan/scan`
- [ ] Projectconfig : `./craft db/query "SELECT path FROM projectconfig WHERE value LIKE '%s3.amazonaws%'"`

---

## 🔧 Contrôleurs par catégorie

### Configuration

```bash
./craft ncc-module/filesystem/list              # Lister systèmes fichiers
./craft ncc-module/filesystem/create            # Créer systèmes fichiers DO
./craft ncc-module/filesystem/delete            # Supprimer systèmes fichiers DO
```

### Diagnostic

```bash
./craft ncc-module/fs-diag/list-fs              # Lister fichiers
./craft ncc-module/fs-diag/compare-fs           # Comparer systèmes fichiers
./craft ncc-module/fs-diag/search-fs            # Rechercher fichiers
./craft ncc-module/fs-diag/verify-fs            # Vérifier si fichier existe
```

### Vérification

```bash
./craft ncc-module/migration-check/check        # Vérifier tout (défaut)
./craft ncc-module/migration-check/analyze      # Analyse détaillée
```

### Remplacement URL (tables principales)

```bash
./craft ncc-module/url-replacement/replace-s3-urls      # Remplacer (défaut)
./craft ncc-module/url-replacement/show-samples         # Afficher exemples
./craft ncc-module/url-replacement/verify               # Vérifier
```

### Remplacement URL (avancé)

```bash
./craft ncc-module/extended-url-replacement/scan-additional     # Scanner (défaut)
./craft ncc-module/extended-url-replacement/replace-additional  # Remplacer tables supp.
./craft ncc-module/extended-url-replacement/replace-json        # Remplacer JSON
```

### Gabarits

```bash
./craft ncc-module/template-url-replacement/scan            # Scanner (défaut)
./craft ncc-module/template-url-replacement/replace         # Remplacer
./craft ncc-module/template-url-replacement/verify          # Vérifier
./craft ncc-module/template-url-replacement/restore-backups # Restaurer
```

### Migration fichiers

```bash
./craft ncc-module/image-migration/migrate          # Migrer (défaut)
./craft ncc-module/image-migration/status           # Statut/checkpoints
./craft ncc-module/image-migration/monitor          # Surveiller temps réel
./craft ncc-module/image-migration/rollback         # Retour arrière
./craft ncc-module/image-migration/cleanup          # Nettoyer checkpoints
./craft ncc-module/image-migration/force-cleanup    # Forcer nettoyage
```

### Basculement

```bash
./craft ncc-module/filesystem-switch/preview            # Aperçu (défaut)
./craft ncc-module/filesystem-switch/list-filesystems   # Lister systèmes
./craft ncc-module/filesystem-switch/test-connectivity  # Tester connectivité
./craft ncc-module/filesystem-switch/to-do              # Basculer vers DO
./craft ncc-module/filesystem-switch/to-aws             # Retour vers AWS
./craft ncc-module/filesystem-switch/verify             # Vérifier setup
```

### Analyse post-migration

```bash
./craft ncc-module/migration-diag/analyze               # Analyser (défaut)
./craft ncc-module/migration-diag/check-missing-files   # Fichiers manquants
./craft ncc-module/migration-diag/move-originals        # Déplacer originaux
```

### Transformations

```bash
# Découverte
./craft ncc-module/transform-discovery/discover         # Tout (défaut)
./craft ncc-module/transform-discovery/scan-database    # BD seulement
./craft ncc-module/transform-discovery/scan-templates   # Gabarits seulement

# Pré-génération
./craft ncc-module/transform-pre-generation/discover    # Découvrir (défaut)
./craft ncc-module/transform-pre-generation/generate    # Générer
./craft ncc-module/transform-pre-generation/verify      # Vérifier
./craft ncc-module/transform-pre-generation/warmup      # Préchauffer
```

### Plugiciels et actifs statiques

```bash
./craft ncc-module/plugin-config-audit/list-plugins     # Lister plugiciels
./craft ncc-module/plugin-config-audit/scan             # Scanner (défaut)
./craft ncc-module/static-asset-scan/scan               # Scanner JS/CSS (défaut)
```

---

## 🚨 Dépannage rapide

### Images ne s'affichent pas

```bash
./craft clear-caches/all
./craft ncc-module/filesystem-switch/verify
./craft ncc-module/fs-diag/verify-fs
./craft ncc-module/fs-diag/list-fs
```

### URL AWS encore présentes

```bash
./craft ncc-module/url-replacement/verify
./craft ncc-module/extended-url-replacement/scan-additional
./craft ncc-module/template-url-replacement/verify
./craft db/query "SELECT * FROM content WHERE field_body LIKE '%s3.amazonaws%' LIMIT 5"
```

### Migration interrompue

```bash
./craft ncc-module/image-migration/migrate  # Reprend automatiquement
./craft ncc-module/image-migration/status   # Vérifier statut
./craft ncc-module/image-migration/monitor  # Surveiller
```

### Fichiers manquants

```bash
./craft ncc-module/migration-diag/check-missing-files
./craft ncc-module/fs-diag/compare-fs
```

### Transformations ne se génèrent pas

```bash
./craft ncc-module/fs-diag/verify-fs
./craft clear-caches/asset-transform-index
./craft clear-caches/asset-indexes
```

### Problèmes de verrous

```bash
./craft ncc-module/image-migration/cleanup          # Nettoyer
./craft ncc-module/image-migration/force-cleanup    # Forcer
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
./craft ncc-module/filesystem-switch/test-connectivity

# 3. Scanner
./craft ncc-module/plugin-config-audit/scan
./craft ncc-module/static-asset-scan/scan

# 4. Migration BD
./craft ncc-module/url-replacement/show-samples
./craft ncc-module/url-replacement/replace-s3-urls
./craft ncc-module/extended-url-replacement/replace-additional
./craft ncc-module/extended-url-replacement/replace-json

# 5. Migration gabarits
./craft ncc-module/template-url-replacement/replace

# 6. Migration fichiers (choisir une option)
# Option A: rclone (rapide)
rclone copy aws-s3:ncc-website-2 medias:medias \
  --exclude "_*/**" --fast-list --transfers=32 \
  --checkers=16 --use-mmap --s3-acl=public-read -P

# Option B: Craft (plus lent)
./craft ncc-module/image-migration/migrate

# 7. Basculement
./craft ncc-module/filesystem-switch/to-do

# 8. Post-migration
./craft index-assets/all
./craft resave/entries --update-search-index=1
./craft clear-caches/all
./craft ncc-module/migration-diag/analyze
```

### Reprise après interruption

```bash
# La migration reprend automatiquement
./craft ncc-module/image-migration/migrate

# Vérifier où on en est
./craft ncc-module/image-migration/status
./craft ncc-module/image-migration/monitor
```

### Retour arrière (rollback)

```bash
# Retour arrière migration fichiers
./craft ncc-module/image-migration/rollback

# Retour arrière basculement volumes
./craft ncc-module/filesystem-switch/to-aws

# Restaurer gabarits
./craft ncc-module/template-url-replacement/restore-backups

# Restaurer sauvegarde BD
./craft db/restore sauvegarde-avant-migration.sql
```

### Test sur environnement dev

```bash
# 1. Configurer .env
MIGRATION_ENV=dev
DO_S3_BASE_URL=https://dev-medias-test.tor1.digitaloceanspaces.com

# 2. Aperçus
./craft ncc-module/filesystem-switch/preview
./craft ncc-module/url-replacement/show-samples

# 3. Exécuter si OK
./craft ncc-module/url-replacement/replace-s3-urls
./craft ncc-module/template-url-replacement/replace
./craft ncc-module/image-migration/migrate
```

### Vérification après migration

```bash
# 1. Vérifier aucune URL AWS
./craft ncc-module/url-replacement/verify
./craft ncc-module/extended-url-replacement/scan-additional
./craft ncc-module/template-url-replacement/verify

# 2. Scanner BD manuellement
./craft db/query "SELECT COUNT(*) FROM content WHERE field_body LIKE '%s3.amazonaws%'"

# 3. Vérifier fichiers
./craft ncc-module/migration-diag/check-missing-files
./craft ncc-module/fs-diag/compare-fs

# 4. Diagnostics complets
./craft ncc-module/migration-diag/analyze
```

### Synchronisation rclone AWS → DO

```bash
# Configuration rclone requise au préalable
# Voir: https://rclone.org/s3/ et https://rclone.org/s3/#digitalocean-spaces

# Commande de synchronisation
rclone copy aws-s3:ncc-website-2 medias:medias \
  --exclude "_*/**" \
  --fast-list \
  --transfers=32 \
  --checkers=16 \
  --use-mmap \
  --s3-acl=public-read \
  -P

# Options:
# --exclude "_*/**"      : Exclut dossiers commençant par underscore
# --fast-list            : Liste rapide (plus de mémoire, plus rapide)
# --transfers=32         : 32 transferts en parallèle
# --checkers=16          : 16 vérifications en parallèle
# --use-mmap             : Utilise mmap (meilleures performances)
# --s3-acl=public-read   : Définit ACL public-read
# -P                     : Affiche progression

# Avantages:
# - 10-20x plus rapide que migration Craft
# - Reprise automatique si interrompu
# - Vérification d'intégrité intégrée

# Après rclone, faire quand même:
./craft ncc-module/url-replacement/replace-s3-urls
./craft ncc-module/template-url-replacement/replace
./craft ncc-module/filesystem-switch/to-do
./craft index-assets/all
./craft clear-caches/all
```

---

## 🔑 Points critiques

### ⚠️ À NE PAS OUBLIER

1. **Créer systèmes de fichiers AVANT migration**
   ```bash
   ./craft ncc-module/filesystem/create
   ```

2. **Sauvegarder AVANT toute opération**
   ```bash
   ./craft db/backup
   ddev export-db --file=sauvegarde.sql.gz
   ```

3. **Tester aperçu avant d'exécuter**
   ```bash
   ./craft ncc-module/filesystem-switch/preview
   ./craft ncc-module/url-replacement/show-samples
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
3. Scanner plugiciels et actifs statiques
4. Remplacer URL base de données (principales + supplémentaires + JSON)
5. Remplacer URL gabarits
6. Migrer fichiers physiques (rclone OU Craft)
7. Basculer volumes vers DO
8. Reconstruire index
9. Vider caches
10. Vérification finale
```

### 🚫 Erreurs courantes

- ❌ Oublier de créer les systèmes de fichiers DO d'abord
- ❌ Ne pas sauvegarder avant de commencer
- ❌ Sauter les tables supplémentaires et champs JSON
- ❌ Oublier de reconstruire les index après migration
- ❌ Ne pas vider les caches (Craft + CDN)
- ❌ Ne pas vérifier les configurations de plugiciels
- ❌ Ne pas scanner les actifs statiques (JS/CSS)
- ❌ Basculer les volumes avant de migrer les fichiers
- ❌ Oublier de vérifier les fichiers manquants après migration

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
- **Fiche_Reference_FR.md** - Cette fiche (référence rapide)
- **README.md** - Guide complet (anglais)
- **MIGRATION_ANALYSIS.md** - Analyse détaillée
- **CONFIGURATION_GUIDE.md** - Guide de configuration

---

## 📈 Statistiques

- **Contrôleurs :** 13
- **Actions :** 50+ commandes
- **Systèmes de fichiers :** 8
- **Couverture :** 95-98%
- **Temps estimé :** 3-5 jours (Craft) ou 1-2 jours (rclone + Craft)
- **Namespace :** `ncc-module`

---

## 🎓 13 contrôleurs disponibles

1. **filesystem** - Gestion systèmes de fichiers (list, create, delete)
2. **filesystem-switch** - Basculement volumes (preview, to-do, to-aws, verify)
3. **fs-diag** - Diagnostics (list-fs, compare-fs, search-fs, verify-fs)
4. **url-replacement** - Remplacement URL BD (replace-s3-urls, show-samples, verify)
5. **extended-url-replacement** - Avancé (scan-additional, replace-additional, replace-json)
6. **template-url-replacement** - Gabarits (scan, replace, verify, restore-backups)
7. **image-migration** - Fichiers (migrate, status, monitor, rollback, cleanup)
8. **migration-check** - Pré-migration (check, analyze)
9. **migration-diag** - Post-migration (analyze, check-missing-files, move-originals)
10. **transform-discovery** - Découverte (discover, scan-database, scan-templates)
11. **transform-pre-generation** - Génération (discover, generate, verify, warmup)
12. **plugin-config-audit** - Plugiciels (list-plugins, scan)
13. **static-asset-scan** - Actifs statiques (scan)

---

**Version :** 2.1 | **Date :** 2025-11-05 | **Projet :** do-migration

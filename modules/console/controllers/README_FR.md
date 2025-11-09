# Trousse de migration AWS S3 vers DigitalOcean Spaces

**Migration complète pour Craft CMS 4**

Source : **AWS S3 (${AWS_SOURCE_BUCKET}, ${AWS_SOURCE_REGION})**
Destination : **DigitalOcean Spaces (Toronto - tor1)**

---

## 📋 Table des matières

- [Aperçu](#aperçu)
- [Prérequis](#prérequis)
- [Configuration initiale](#configuration-initiale)
- [Synchronisation rclone](#synchronisation-rclone)
- [Processus de migration](#processus-de-migration)
- [Contrôleurs disponibles](#contrôleurs-disponibles)
- [Dépannage](#dépannage)
- [Critères de réussite](#critères-de-réussite)

---

## Aperçu

**12 contrôleurs spécialisés** pour migrer une installation Craft CMS 4 :

- ✅ Remplacement des URL dans la base de données
- ✅ Remplacement des URL dans les gabarits
- ✅ Migration des fichiers physiques (avec reprise possible)
- ✅ Gestion des systèmes de fichiers et volumes
- ✅ Validation pré-migration
- ✅ Vérification post-migration
- ✅ Découverte et pré-génération des transformations d'images
- ✅ Audit des configurations de plugiciels
- ✅ Scan des actifs statiques (JS/CSS)
- ✅ Remplacement avancé dans tables supplémentaires et champs JSON

**Couverture :** 85-90% automatisée → 95-98% avec étapes supplémentaires

**Espace de noms :** Toutes les commandes utilisent `craft ncc-module/{contrôleur}/{action}`

---

## Prérequis

### Synchro AWS et Digital Ocean
```bash
rclone config create aws-s3 s3 \
  provider AWS \
  access_key_id AKIAYP3VFFLYOX4VS65X \
  secret_access_key **************** \
  region ca-central-1 \
  acl public-read
```


```bash
rclone config create prod-medias s3 \
  provider DigitalOcean \
  access_key_id DO801VD26PT36YBQA4LC \
  secret_access_key ******************************* \
  endpoint tor1.digitaloceanspaces.com \
  acl public-read
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
- **[vaersaagod/dospaces](https://github.com/vaersaagod/dospaces)** - **REQUIS**
  ```bash
  composer require vaersaagod/dospaces
  ./craft plugin/install dospaces
  ```
- Ce plugiciel DOIT être installé AVANT toute opération de migration
- La commande `migration-check/check` vérifiera automatiquement son installation

### 4. rclone - **REQUIS pour synchronisation efficace**
- **rclone est nécessaire** pour une synchronisation efficace AWS → DO
- [Installer rclone](https://rclone.org/install/)
- **IMPORTANT:** Assurez-vous d'avoir une synchronisation fraîche d'AWS vers le bucket DigitalOcean approprié AVANT de lancer la migration
- La commande `migration-check/check` vérifiera automatiquement la disponibilité de rclone

### 5. Variables d'environnement

Ajoutez à votre fichier `.env` :

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
```

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

✅ **Note :** Tous les contrôleurs utilisent maintenant la configuration centralisée via `MigrationConfig`. Les valeurs sont chargées automatiquement depuis `config/migration-config.php`.

### Étape 2 : Installer les contrôleurs

```bash
# Copier tous les contrôleurs
cp *Controller.php votre-projet-craft/modules/console/controllers/
```

### Étape 3 : Vérifier l'installation

```bash
./craft ncc-module/filesystem/list
```

---

## Synchronisation rclone

### ⚠️ IMPORTANT : Synchronisation fraîche requise

**AVANT de commencer la migration**, assurez-vous d'avoir une synchronisation fraîche et complète d'AWS vers le bucket DigitalOcean approprié. Cette étape est CRITIQUE pour assurer que tous les fichiers sont disponibles avant la migration.

### Copier les fichiers avec rclone

Au lieu d'utiliser le contrôleur de migration Craft, vous pouvez synchroniser directement AWS vers DO avec rclone :

```bash
# Synchroniser AWS S3 vers DigitalOcean Spaces
rclone copy aws-s3:${AWS_SOURCE_BUCKET} medias:medias \
  --exclude "_*/**" \
  --fast-list \
  --transfers=32 \
  --checkers=16 \
  --use-mmap \
  --s3-acl=public-read \
  -P

# OU pour synchroniser (supprimer les fichiers supprimés dans la source)
rclone sync aws-s3:${AWS_SOURCE_BUCKET} medias:medias \
  --exclude "_*/**" \
  --fast-list \
  --transfers=32 \
  --checkers=16 \
  --use-mmap \
  --s3-acl=public-read \
  -P
```

**Vérifier la synchronisation :**
```bash
# Comparer les deux buckets
rclone check aws-s3:${AWS_SOURCE_BUCKET} medias:medias --one-way
```

**Options expliquées :**
- `--exclude "_*/**"` : Exclut les dossiers commençant par underscore
- `--fast-list` : Liste rapide (utilise plus de mémoire mais plus rapide)
- `--transfers=32` : 32 transferts en parallèle
- `--checkers=16` : 16 vérifications en parallèle
- `--use-mmap` : Utilise mmap pour de meilleures performances
- `--s3-acl=public-read` : Définit ACL public-read sur les fichiers
- `-P` : Affiche la progression

**Avantages :**
- ✅ Beaucoup plus rapide (parallélisme massif)
- ✅ Reprise automatique si interrompu
- ✅ Vérification d'intégrité intégrée
- ✅ Ne dépend pas de Craft

## Processus de migration

Suivez ces étapes **dans l'ordre** :

### Phase 0 : Configuration

#### 0.1 Créer les systèmes de fichiers DigitalOcean Spaces

```bash
# Lister les systèmes de fichiers actuels
./craft ncc-module/filesystem/list

# Créer les systèmes de fichiers DO
./craft ncc-module/filesystem/create

# Vérifier
./craft ncc-module/filesystem/list
```

**Alternative manuelle :** Créer dans le panneau de contrôle Craft :
1. Réglages → Actifs → Systèmes de fichiers
2. Cliquer sur "+ Nouveau système de fichiers"
3. Configurer pour chaque volume

#### 0.2 Configurer le système de fichiers Transform pour tous les volumes

**IMPORTANT :** Cette étape est essentielle pour éviter de polluer les systèmes de fichiers avec des transformations.

```bash
# Vérifier la configuration actuelle
./craft ncc-module/volume-config/status

# Test à blanc (recommandé)
./craft ncc-module/volume-config/set-transform-filesystem --dry-run

# Appliquer la configuration
./craft ncc-module/volume-config/set-transform-filesystem
```

**Alternative manuelle dans le panneau de contrôle Craft :**
1. Aller à Réglages → Actifs → Volumes
2. Pour CHAQUE volume :
   - Cliquer sur le volume
   - Onglet "Paramètres"
   - Dans "Transform Filesystem", sélectionner "Image Transforms (DO)"
   - Sauvegarder

---

### Phase 1 : Vérifications pré-migration

#### 1.1 Diagnostics

```bash
# Aperçu du basculement (dry run)
./craft ncc-module/filesystem-switch/preview

# Vérifier la connectivité de tous les systèmes de fichiers
./craft ncc-module/filesystem-switch/test-connectivity

# Lister tous les systèmes de fichiers
./craft ncc-module/filesystem-switch/list-filesystems

# Vérification complète pré-migration
./craft ncc-module/migration-check/check

# Analyser les actifs en détail
./craft ncc-module/migration-check/analyze
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

# Scanner les actifs statiques (JS/CSS)
./craft ncc-module/static-asset-scan/scan
```

---

### Phase 2 : Remplacement des URL dans la base de données

#### 2.1 Scanner et afficher des exemples

```bash
# Afficher des exemples d'URL de la BD
./craft ncc-module/url-replacement/verify
```

#### 2.2 Remplacer les URL (tables principales)

```bash
# Remplacer les URL AWS S3 par DO Spaces
./craft ncc-module/url-replacement/replace-s3-urls
```

#### 2.3 Vérification

```bash
# Vérifier qu'aucune URL AWS ne reste
./craft ncc-module/url-replacement/verify
```

#### 2.4 Tables supplémentaires et champs JSON

```bash
# Scanner les tables supplémentaires
./craft ncc-module/extended-url-replacement/scan-additional

# Remplacer dans les tables supplémentaires
./craft ncc-module/extended-url-replacement/replace-additional

# Remplacer dans les champs JSON
./craft ncc-module/extended-url-replacement/replace-json
```

**Résultat attendu :** "✓ No AWS S3 URLs found"

---

### Phase 3 : Remplacement des URL dans les gabarits

#### 3.1 Scanner

```bash
./craft ncc-module/template-url-replacement/scan
```

#### 3.2 Remplacer

```bash
./craft ncc-module/template-url-replacement/replace
```

#### 3.3 Vérifier

```bash
./craft ncc-module/template-url-replacement/verify

```

#### 3.4 Restaurer (si nécessaire)

```bash
# Restaurer depuis les sauvegardes
./craft ncc-module/template-url-replacement/restore-backups
```

---

### Phase 4 : Migration des fichiers physiques

**Fonctionnalités :**
- Système de points de contrôle (reprise si interrompu)
- Journal des changements pour retour en arrière
- Suivi de progression en temps réel
- Nettoyage automatique

#### 4.1 Vérifier le statut

```bash
# Lister les checkpoints disponibles
./craft ncc-module/image-migration/status
```

#### 4.2 Test à blanc (RECOMMANDÉ)

```bash
# Test sans modifications réelles (dry run)
./craft ncc-module/image-migration/migrate dryRun=1
```

#### 4.3 Exécution

```bash
# Lancer la migration complète
./craft ncc-module/image-migration/migrate

# Options utiles:
# - skipBackup=1          : Sauter la sauvegarde (si déjà faite)
# - skipInlineDetection=1 : Sauter la détection inline (plus rapide mais moins précis)
```

**Si interrompu :**
```bash
# Reprendre automatiquement depuis le dernier checkpoint
./craft ncc-module/image-migration/migrate resume=1

# Ou reprendre depuis un checkpoint spécifique
./craft ncc-module/image-migration/migrate checkpointId=migration_20250105_143022
```

#### 4.4 Suivi en temps réel

```bash
# Surveiller la progression en temps réel (dans un autre terminal)
./craft ncc-module/image-migration/monitor
```

#### 4.5 Nettoyage

```bash
# Nettoyer les anciens checkpoints (plus de 72h)
./craft ncc-module/image-migration/cleanup

# Nettoyer les checkpoints plus anciens que 12h
./craft ncc-module/image-migration/cleanup olderThanHours=12

# Forcer le nettoyage (supprime TOUS les verrous - utiliser avec précaution!)
./craft ncc-module/image-migration/force-cleanup
```

#### 4.6 Retour arrière (si nécessaire)

```bash
# Annuler la migration (prompt interactif pour sélectionner quelle migration)
./craft ncc-module/image-migration/rollback

# Annuler une migration spécifique
./craft ncc-module/image-migration/rollback <migration-id>
```

---

### Phase 5 : Basculement des systèmes de fichiers

#### 5.1 Aperçu

```bash
# Aperçu du basculement (dry run)
./craft ncc-module/filesystem-switch/preview
```

#### 5.2 Basculer vers DigitalOcean

```bash
# Basculer tous les volumes vers DO Spaces
./craft ncc-module/filesystem-switch/to-do
```

#### 5.3 Vérifier

```bash
# Vérifier le basculement
./craft ncc-module/filesystem-switch/verify

# Vérifier un fichier spécifique
./craft ncc-module/fs-diag/verify-fs
```

#### 5.4 Retour arrière (si nécessaire)

```bash
# Revenir à AWS S3
./craft ncc-module/filesystem-switch/to-aws
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
# Analyse complète
./craft ncc-module/migration-diag/analyze

# Vérifier les fichiers manquants
./craft ncc-module/migration-diag/check-missing-files

# Déplacer les originaux (si nécessaire)
./craft ncc-module/migration-diag/move-originals
```

---

### Phase 7 : Transformations d'images

#### 7.1 **IMPORTANT:** Ajouter optimisedImagesField AVANT de générer les transformations

**CRITIQUE :** Cette étape DOIT être complétée APRÈS la migration mais AVANT de générer les transformations pour assurer que les transformations sont correctement générées.

```bash
# Vérifier la configuration actuelle
./craft ncc-module/volume-config/status

# Test à blanc (recommandé)
./craft ncc-module/volume-config/add-optimised-field images --dry-run

# Ajouter le champ
./craft ncc-module/volume-config/add-optimised-field images
```

**Alternative manuelle dans le panneau de contrôle Craft :**
1. Aller à Réglages → Actifs → Volumes
2. Cliquer sur "Images (DO)"
3. Onglet "Disposition des champs"
4. Dans l'onglet "Content", cliquer sur "+ Ajouter un champ"
5. Sélectionner "optimisedImagesField"
6. Sauvegarder

#### 7.2 Découvrir les transformations

```bash
# Découvrir TOUTES les transformations (BD + gabarits)
./craft ncc-module/transform-discovery/discover

# Ou scanner séparément
./craft ncc-module/transform-discovery/scan-database
./craft ncc-module/transform-discovery/scan-templates
```

#### 7.3 Découvrir les transformations utilisées

```bash
# Découvrir les transformations dans la BD
./craft ncc-module/transform-pre-generation/discover
```

#### 7.4 Générer les transformations

```bash
# Générer les transformations
./craft ncc-module/transform-pre-generation/generate
```

#### 7.5 Vérifier et préchauffer

```bash
# Vérifier que les transformations existent
./craft ncc-module/transform-pre-generation/verify

# Préchauffer en simulant le trafic
./craft ncc-module/transform-pre-generation/warmup
```

---

### Phase 8 : Vérification finale

#### 8.1 Scanner la base de données

```bash
# Vérifier qu'aucune URL AWS ne reste
./craft ncc-module/url-replacement/verify

# Scanner les tables supplémentaires
./craft ncc-module/extended-url-replacement/scan-additional

# Vérifier les gabarits
./craft ncc-module/template-url-replacement/verify
```

#### 8.2 Scanner manuellement

```bash
# Scanner BD pour URL AWS restantes
./craft db/query "SELECT COUNT(*) as count FROM content WHERE field_body LIKE '%s3.amazonaws%'"
./craft db/query "SELECT COUNT(*) as count FROM content WHERE field_body LIKE '%${AWS_SOURCE_BUCKET}%'"

# Vérifier projectconfig
./craft db/query "SELECT path FROM projectconfig WHERE value LIKE '%s3.amazonaws%'"
```

**Résultat attendu :** Toutes les requêtes retournent 0 ligne.

#### 8.3 Tests manuels

- [ ] Naviguer sur le site - les images s'affichent correctement
- [ ] Tester le téléversement d'images dans le panneau de contrôle
- [ ] Tester l'insertion d'images Redactor/CKEditor
- [ ] Vérifier le navigateur d'actifs fonctionne
- [ ] Vérifier les transformations d'images se génèrent
- [ ] Tester depuis différents navigateurs
- [ ] Vérifier la réactivité mobile

#### 8.4 Surveiller les journaux

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

# Scanner les configurations
./craft ncc-module/plugin-config-audit/scan
```

Plugiciels courants à vérifier :
- **Imager-X :** Emplacements de stockage des transformations
- **Blitz :** Stockage du cache statique
- **Redactor :** Chemins de config personnalisés
- **Feed Me :** URL sources d'importation

#### 9.2 Actifs statiques (JS/CSS)

```bash
# Scanner JS/CSS pour URL S3
./craft ncc-module/static-asset-scan/scan

# Recherche manuelle
grep -r "s3.amazonaws.com\|${AWS_SOURCE_BUCKET}" web/assets/ web/dist/
```

---

## Contrôleurs disponibles

### 1. filesystem
Gestion des systèmes de fichiers.

```bash
./craft ncc-module/filesystem/list              # Lister tous les systèmes de fichiers
./craft ncc-module/filesystem/create            # Créer les systèmes de fichiers DO
./craft ncc-module/filesystem/delete            # Supprimer tous les systèmes de fichiers DO
```

### 2. volume-config
Configuration des volumes (transform filesystem, field layouts).

```bash
./craft ncc-module/volume-config/status                     # Afficher l'état actuel de la configuration
./craft ncc-module/volume-config/set-transform-filesystem   # Configurer transform filesystem pour tous les volumes
./craft ncc-module/volume-config/add-optimised-field        # Ajouter optimisedImagesField au volume Images (DO)
./craft ncc-module/volume-config/configure-all              # Configurer tous les paramètres (convenience command)
```

### 3. filesystem-switch
Basculer les volumes entre AWS et DO.

```bash
./craft ncc-module/filesystem-switch/preview            # Aperçu (dry run)
./craft ncc-module/filesystem-switch/list-filesystems   # Lister systèmes de fichiers
./craft ncc-module/filesystem-switch/test-connectivity  # Tester connectivité
./craft ncc-module/filesystem-switch/to-do              # Basculer vers DO
./craft ncc-module/filesystem-switch/to-aws             # Retour vers AWS
./craft ncc-module/filesystem-switch/verify             # Vérifier setup
```

### 4. fs-diag
Diagnostics des systèmes de fichiers.

```bash
./craft ncc-module/fs-diag/list-fs              # Lister fichiers
./craft ncc-module/fs-diag/compare-fs           # Comparer deux systèmes de fichiers
./craft ncc-module/fs-diag/search-fs            # Rechercher fichiers spécifiques
./craft ncc-module/fs-diag/verify-fs            # Vérifier si fichier existe
```

### 5. url-replacement
Remplacer les URL AWS S3 dans la base de données.

```bash
./craft ncc-module/url-replacement/replace-s3-urls      # Remplacer URL (défaut)
./craft ncc-module/url-replacement/show-samples         # Afficher exemples URL
./craft ncc-module/url-replacement/verify               # Vérifier remplacement
```

### 6. extended-url-replacement
Remplacement avancé (tables supplémentaires, JSON).

```bash
./craft ncc-module/extended-url-replacement/scan-additional     # Scanner tables (défaut)
./craft ncc-module/extended-url-replacement/replace-additional  # Remplacer tables
./craft ncc-module/extended-url-replacement/replace-json        # Remplacer JSON
```

### 7. template-url-replacement
Remplacer les URL dans les gabarits Twig.

```bash
./craft ncc-module/template-url-replacement/scan            # Scanner (défaut)
./craft ncc-module/template-url-replacement/replace         # Remplacer
./craft ncc-module/template-url-replacement/verify          # Vérifier
./craft ncc-module/template-url-replacement/restore-backups # Restaurer sauvegardes
```

### 8. image-migration
Migrer les fichiers d'actifs physiques.

```bash
# Migration principale (action par défaut)
./craft ncc-module/image-migration/migrate
# Flags disponibles:
#   dryRun=1              - Test sans modifications
#   skipBackup=1          - Sauter la sauvegarde
#   skipInlineDetection=1 - Sauter la détection inline (RTE)
#   resume=1              - Reprendre une migration interrompue
#   checkpointId=<id>     - Reprendre depuis un checkpoint spécifique
#   skipLock=1            - Ignorer le verrou (dangereux!)

# Autres actions
./craft ncc-module/image-migration/status           # Lister checkpoints et statut
./craft ncc-module/image-migration/monitor          # Surveiller progression temps réel
./craft ncc-module/image-migration/rollback         # Retour arrière (prompt interactif)
./craft ncc-module/image-migration/cleanup          # Nettoyer vieux checkpoints (72h)
./craft ncc-module/image-migration/force-cleanup    # Forcer nettoyage (supprime TOUS verrous)

# Exemples d'utilisation avec flags
./craft ncc-module/image-migration/migrate dryRun=1
./craft ncc-module/image-migration/migrate resume=1
./craft ncc-module/image-migration/migrate checkpointId=migration_20250105_143022
./craft ncc-module/image-migration/cleanup olderThanHours=48
```

### 9. migration-check
Validation pré-migration (10 vérifications automatiques).

```bash
./craft ncc-module/migration-check/check            # Vérifications complètes (défaut)
./craft ncc-module/migration-check/analyze          # Analyse détaillée actifs
```

**Vérifie automatiquement :**
- Configuration des volumes
- Accès aux systèmes de fichiers
- Schéma de base de données
- Configuration PHP
- Opérations sur les fichiers
- Distribution des actifs
- **Installation du plugiciel DO Spaces**
- **Disponibilité de rclone**
- **Configuration du transform filesystem**
- **Disposition des champs de volume**

### 10. migration-diag
Diagnostics post-migration.

```bash
./craft ncc-module/migration-diag/analyze               # Analyser (défaut)
./craft ncc-module/migration-diag/check-missing-files   # Vérifier fichiers manquants
./craft ncc-module/migration-diag/move-originals        # Déplacer originaux
```

### 11. transform-discovery
Découvrir les transformations d'images.

```bash
./craft ncc-module/transform-discovery/discover         # Découvrir tout (défaut)
./craft ncc-module/transform-discovery/scan-database    # Scanner BD seulement
./craft ncc-module/transform-discovery/scan-templates   # Scanner gabarits seulement
```

### 12. transform-pre-generation
Pré-générer les transformations d'images.

```bash
./craft ncc-module/transform-pre-generation/discover    # Découvrir transformations (défaut)
./craft ncc-module/transform-pre-generation/generate    # Générer transformations
./craft ncc-module/transform-pre-generation/verify      # Vérifier transformations
./craft ncc-module/transform-pre-generation/warmup      # Préchauffer (simuler trafic)
```

### 13. plugin-config-audit
Auditer les configurations de plugiciels.

```bash
./craft ncc-module/plugin-config-audit/list-plugins     # Lister plugiciels
./craft ncc-module/plugin-config-audit/scan             # Scanner configs (défaut)
```

### 14. static-asset-scan
Scanner les actifs statiques (JS/CSS).

```bash
./craft ncc-module/static-asset-scan/scan               # Scanner JS/CSS (défaut)
```

---

## Dépannage

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
./craft db/query "SELECT * FROM content WHERE field_body LIKE '%s3.amazonaws%' LIMIT 1"
./craft db/query "SELECT * FROM projectconfig WHERE value LIKE '%s3.amazonaws%'"
```

### Transformations ne se génèrent pas

```bash
./craft ncc-module/fs-diag/verify-fs
./craft clear-caches/asset-transform-index
./craft clear-caches/asset-indexes
```

### Migration interrompue

```bash
# Reprendre automatiquement
./craft ncc-module/image-migration/migrate

# Vérifier le statut
./craft ncc-module/image-migration/status

# Surveiller
./craft ncc-module/image-migration/monitor
```

### Fichiers manquants après migration

```bash
# Vérifier fichiers manquants
./craft ncc-module/migration-diag/check-missing-files

# Comparer systèmes de fichiers
./craft ncc-module/fs-diag/compare-fs
```

### Erreurs de permissions

```bash
# Tester la connectivité
./craft ncc-module/filesystem-switch/test-connectivity

# Vérifier setup
./craft ncc-module/filesystem-switch/verify
```

### Problèmes de verrous ou checkpoints

```bash
# Nettoyer les anciens checkpoints
./craft ncc-module/image-migration/cleanup

# Forcer le nettoyage (supprime TOUS les verrous)
./craft ncc-module/image-migration/force-cleanup
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
- ✅ Tables supplémentaires : Aucune URL AWS (projectconfig, etc.)
- ✅ Champs JSON : Aucune URL AWS
- ✅ Gabarits : Aucune URL AWS dans les fichiers de gabarits
- ✅ Actifs statiques : Aucune URL AWS dans JS/CSS
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
# === CONFIGURATION ===
./craft ncc-module/filesystem/create
./craft ncc-module/filesystem/list

# === PRÉ-MIGRATION ===
./craft ncc-module/migration-check/check
./craft ncc-module/filesystem-switch/preview
./craft ncc-module/filesystem-switch/test-connectivity
./craft db/backup

# === SCANNER ===
./craft ncc-module/plugin-config-audit/scan
./craft ncc-module/static-asset-scan/scan

# === BASE DE DONNÉES ===
./craft ncc-module/url-replacement/show-samples
./craft ncc-module/url-replacement/replace-s3-urls
./craft ncc-module/url-replacement/verify
./craft ncc-module/extended-url-replacement/scan-additional
./craft ncc-module/extended-url-replacement/replace-additional
./craft ncc-module/extended-url-replacement/replace-json

# === GABARITS ===
./craft ncc-module/template-url-replacement/scan
./craft ncc-module/template-url-replacement/replace
./craft ncc-module/template-url-replacement/verify

# === FICHIERS (Option 1: rclone - RAPIDE) ===
rclone copy aws-s3:${AWS_SOURCE_BUCKET} medias:medias \
  --exclude "_*/**" --fast-list --transfers=32 \
  --checkers=16 --use-mmap --s3-acl=public-read -P

# === FICHIERS (Option 2: Craft) ===
./craft ncc-module/image-migration/migrate dryRun=1  # Test d'abord
./craft ncc-module/image-migration/migrate           # Exécution
./craft ncc-module/image-migration/monitor           # Surveiller
./craft ncc-module/image-migration/status            # Statut

# === BASCULEMENT ===
./craft ncc-module/filesystem-switch/to-do
./craft ncc-module/filesystem-switch/verify

# === POST-MIGRATION ===
./craft index-assets/all
./craft resave/entries --update-search-index=1
./craft clear-caches/all
./craft ncc-module/migration-diag/analyze
./craft ncc-module/migration-diag/check-missing-files

# === TRANSFORMATIONS ===
./craft ncc-module/transform-discovery/discover
./craft ncc-module/transform-pre-generation/discover
./craft ncc-module/transform-pre-generation/generate
./craft ncc-module/transform-pre-generation/verify
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
| **EXTENDED_CONTROLLERS.md** | Contrôleurs supplémentaires |
| **ARCHITECTURE_RECOMMENDATION.md** | Recommandations d'architecture |
| **MANAGER_EXTRACTION_GUIDE.md** | Guide d'extraction des gestionnaires |

---

## Statistiques de migration

### Source (AWS S3)
- **Compartiment :** ${AWS_SOURCE_BUCKET}
- **Région :** ${AWS_SOURCE_REGION}
- **Formats d'URL :** 6 modèles différents

### Destination (DigitalOcean Spaces)
- **Région :** tor1 (Toronto)
- **Systèmes de fichiers :** 8
- **Sous-dossiers :** Configurables

### Trousse
- **Contrôleurs :** 14 contrôleurs spécialisés
- **Actions :** 55+ commandes disponibles
- **Couverture :** 95-98% avec toutes les étapes
- **Espace de noms :** `ncc-module`
- **Temps estimé :** 3-5 jours (Craft) ou 1-2 jours (rclone + Craft)
- **Automation :** Configuration automatisée des volumes et transforms

---

## Ressources

- [Documentation Craft CMS 4](https://craftcms.com/docs/4.x/)
- [Documentation DigitalOcean Spaces](https://docs.digitalocean.com/products/spaces/)
- [Plugiciel vaersaagod/dospaces](https://github.com/vaersaagod/dospaces)
- [Documentation rclone](https://rclone.org/docs/)

---

**Projet :** do-migration
**Statut :** Prêt pour l'exécution 🚀
**Objectif :** Migration 100% AWS S3 → DigitalOcean Spaces
**Confiance :** 95-98% de couverture réalisable
**Dernière mise à jour :** 2025-11-05
**Version :** 2.1
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

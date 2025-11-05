# Référence Rapide - Configuration de la Migration

**Guide de configuration pour la migration AWS S3 vers DigitalOcean Spaces**

---

## 📁 Emplacement des Fichiers

```
craft/config/
  └── migration-config.php          ← Configuration principale (à personnaliser)

craft/modules/helpers/
  └── MigrationConfig.php            ← Classe d'aide (ne pas modifier)

craft/.env                           ← Variables d'environnement actives
```

**Explication :** Le système utilise un fichier de configuration central (`migration-config.php`) qui définit les paramètres pour tous les environnements (dev, staging, prod). Les variables sensibles (clés d'accès) sont stockées dans `.env`.

---

## ⚙️ Configuration Initiale (3 Étapes)

```bash
# 1. Copier les fichiers du système de configuration
cp config/migration-config.php craft/config/
cp MigrationConfig.php craft/modules/helpers/

# 2. Configurer les variables d'environnement dans craft/.env
echo "MIGRATION_ENV=dev" >> craft/.env
echo "DO_S3_ACCESS_KEY=votre_clé_accès" >> craft/.env
echo "DO_S3_SECRET_KEY=votre_clé_secrète" >> craft/.env
echo "DO_S3_BUCKET=nom-de-votre-bucket" >> craft/.env
echo "DO_S3_BASE_URL=https://votre-bucket.tor1.digitaloceanspaces.com" >> craft/.env

# 3. Vérifier que la configuration est valide
./craft ncc-module/url-replacement/show-config
```

**Important :**
- `MIGRATION_ENV` détermine quel environnement est actif (dev, staging, ou prod)
- Les clés DO_S3_* sont vos identifiants DigitalOcean Spaces
- Le bucket DO doit exister avant de lancer la migration

---

## 🔧 Personnalisation de migration-config.php

Ouvrir `craft/config/migration-config.php` et modifier les sections suivantes :

### 1. Configuration AWS S3 (source)
```php
'aws' => [
    'bucket' => 'nom-bucket-aws',          // Nom du bucket S3 source
    'urls' => [
        'https://nom-bucket-aws.s3.amazonaws.com',   // Toutes les variations
        'http://nom-bucket-aws.s3.amazonaws.com',    // d'URL utilisées
        'https://nom-bucket-aws.s3.ca-central-1.amazonaws.com',
    ],
],
```
**Astuce :** Ajoutez toutes les variations d'URL trouvées dans votre base de données.

### 2. Configuration DigitalOcean par Environnement
```php
'dev' => [
    'digitalocean' => [
        'baseUrl' => 'https://dev-bucket.tor1.digitaloceanspaces.com',
    ],
],
'staging' => [
    'digitalocean' => [
        'baseUrl' => 'https://staging-bucket.tor1.digitaloceanspaces.com',
    ],
],
'prod' => [
    'digitalocean' => [
        'baseUrl' => 'https://prod-bucket.tor1.digitaloceanspaces.com',
    ],
],
```
**Explication :** Chaque environnement pointe vers un bucket DigitalOcean différent. Le système utilisera automatiquement le bon selon `MIGRATION_ENV`.

### 3. Correspondance des Systèmes de Fichiers (si nécessaire)
```php
'filesystemMappings' => [
    'aws_images' => 'do_images',       // AWS handle → DO handle
    'aws_documents' => 'do_documents',
],
```
**Quand modifier :** Seulement si vos "filesystem handles" Craft diffèrent entre AWS et DO.

### 4. Configuration des Volumes (si nécessaire)
```php
'volumes' => [
    'source' => ['images', 'documents'],   // Volumes sources à migrer
    'target' => 'images',                  // Volume cible principal
    'quarantine' => 'quarantine',          // Volume pour fichiers problématiques
],
```

---

## 🔄 Changer d'Environnement

### Méthode 1 : Fichiers .env Pré-configurés (Recommandé)
```bash
# Pour développement
cp config/.env.dev craft/.env

# Pour staging
cp config/.env.staging craft/.env

# Pour production
cp config/.env.prod craft/.env
```

### Méthode 2 : Variable Temporaire (Pour Tests)
```bash
MIGRATION_ENV=staging ./craft ncc-module/url-replacement/show-config
```

**Explication :** La variable `MIGRATION_ENV` contrôle quel environnement est actif. Changez-la pour basculer entre dev, staging, et prod.

---

## ✅ Vérification de la Configuration

```bash
# Afficher la configuration actuelle
./craft ncc-module/url-replacement/show-config

# Résultat attendu :
# Environment: DEV
# AWS Bucket: nom-bucket-aws
# DO Bucket: nom-bucket-do
# DO Base URL: https://nom-bucket-do.tor1.digitaloceanspaces.com
# ✓ La configuration est valide

# Tester chaque environnement
MIGRATION_ENV=dev ./craft ncc-module/url-replacement/show-config
MIGRATION_ENV=staging ./craft ncc-module/url-replacement/show-config
MIGRATION_ENV=prod ./craft ncc-module/url-replacement/show-config
```

**Avant de continuer :** Assurez-vous que tous les environnements affichent "✓ La configuration est valide".

---

## 🚨 Dépannage

| Erreur | Solution |
|--------|----------|
| Fichier de configuration introuvable | `cp config/migration-config.php craft/config/` |
| Classe MigrationConfig introuvable | `cp MigrationConfig.php craft/modules/helpers/` |
| Clé d'accès DO manquante | Ajouter `DO_S3_ACCESS_KEY=...` dans `craft/.env` |
| Mauvais environnement actif | Vérifier `MIGRATION_ENV` dans `craft/.env` |
| Erreurs de validation | Exécuter `./craft ncc-module/url-replacement/show-config` pour voir les détails |
| URL de base DO invalide | Vérifier le format : `https://bucket.region.digitaloceanspaces.com` |

---

## 📋 Liste de Vérification Avant Migration

- [ ] Fichiers copiés dans `craft/config/` et `craft/modules/helpers/`
- [ ] Variables d'environnement configurées dans `craft/.env`
- [ ] Buckets AWS et DO identifiés et accessibles
- [ ] URLs personnalisées dans `migration-config.php`
- [ ] Configuration validée avec `show-config` pour chaque environnement
- [ ] Clés d'accès DigitalOcean testées et fonctionnelles

---

## 📚 Documentation Additionnelle

- **Guide complet :** CONFIGURATION_GUIDE.md
- **Configuration principale :** config/migration-config.php
- **Classe d'aide :** MigrationConfig.php

---

**Démarrage Rapide :** Copier 2 fichiers → Configurer .env → Vérifier → Prêt!

**Version :** 1.0

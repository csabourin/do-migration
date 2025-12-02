# REQUEST FOR CHANGE (RFC)
## Migration des Assets de AWS S3 vers DigitalOcean Spaces

---

### 1. INFORMATIONS GÉNÉRALES

| Champ | Valeur |
|-------|--------|
| **Numéro RFC** | RFC-2025-xxx |
| **Type de changement** | Standard - Infrastructure |
| **Priorité** | Normale |
| **Catégorie** | Migration de stockage cloud |
| **Date de soumission** | 2025-12-02 |
| **Demandeur** | [Nom du demandeur] |
| **Environnement** | Production |

---

### 2. DESCRIPTION DU CHANGEMENT

**Objectif**: Migrer l'ensemble des assets statiques (images, documents, médias) du système de gestion de contenu Craft CMS depuis Amazon Web Services (AWS S3) vers DigitalOcean Spaces.

**Périmètre**:
- Migration de [X] assets (~[Y] GB)
- [Z] volumes de stockage Craft CMS
- Mise à jour des références d'URL dans la base de données
- Reconfiguration des filesystems Craft CMS

**Outil utilisé**: Spaghetti Migrator v5.0 (plugin Craft CMS certifié)

---

### 3. JUSTIFICATION BUSINESS

| Bénéfice | Impact |
|----------|--------|
| **Réduction des coûts** | Économie estimée: [X]% sur les frais de stockage et transfert |
| **Performance accrue** | Latence réduite pour les utilisateurs [région] |
| **Simplification opérationnelle** | Consolidation des services cloud |
| **Conformité** | Hébergement dans [région préférée] |

---

### 4. ANALYSE DE RISQUES

#### Risques Identifiés

| Risque | Probabilité | Impact | Niveau |
|--------|-------------|--------|--------|
| Interruption temporaire d'accès aux assets | Faible | Moyen | **Moyen** |
| Échec de migration partielle | Très faible | Élevé | **Moyen** |
| Corruption de données lors du transfert | Très faible | Élevé | **Faible** |
| URLs cassées après migration | Faible | Moyen | **Faible** |
| Temps de migration plus long que prévu | Moyen | Faible | **Faible** |

---

### 5. MESURES D'ATTÉNUATION DES RISQUES

#### Mesures Techniques

1. **Redirection des fichiers statiques depuis AWS** (Phase transitoire)
   - Configuration de règles de redirection S3 → DigitalOcean
   - Maintien de l'accès AWS pendant la période de transition
   - Validation progressive du routage des assets

2. **Système de checkpoint et reprise**
   - Sauvegarde automatique de l'état de migration toutes les 10 batches
   - Capacité de reprise après interruption sans perte de progression

3. **Validation pré-migration exhaustive**
   - Test de connectivité aux deux providers
   - Vérification de l'intégrité des assets sources
   - Validation des permissions et configurations

4. **Mécanisme de rollback complet**
   - Journal détaillé de toutes les opérations (change log)
   - Capacité de rollback en cas d'échec critique
   - Sauvegarde de base de données avant modification des URLs

5. **Mode dry-run**
   - Simulation complète sans modifications réelles
   - Validation du plan de migration avant exécution

#### Mesures Organisationnelles

- Migration en dehors des heures de pointe
- Monitoring continu durant la migration
- Équipe technique en standby

---

### 6. PLAN DE ROLLBACK

**Critères de déclenchement**:
- Échec de plus de 5% des assets
- Corruption de données détectée
- Interruption de service > 30 minutes
- Décision business

**Procédure de rollback** (durée estimée: 1-2 heures):
```bash
1. Exécution du rollback automatisé
   ./craft spaghetti-migrator/image-migration/rollback

2. Restauration de la base de données (backup automatique)

3. Reconfiguration des filesystems vers AWS

4. Validation post-rollback

5. Communication aux parties prenantes
```

---

### 7. PLAN D'IMPLÉMENTATION

#### Phase 1: Préparation (J-7)
- ✓ Installation et configuration de Spaghetti Migrator
- ✓ Validation des credentials AWS et DigitalOcean
- ✓ Tests de connectivité providers
- ✓ Configuration des filesystems de destination

#### Phase 2: Validation (J-3)
- Exécution dry-run complète
- Vérification pré-migration (migration-check)
- Revue des warnings et résolution

#### Phase 3: Exécution (Jour J)

| Heure | Action | Durée | Responsable |
|-------|--------|-------|-------------|
| 22:00 | Backup base de données | 15 min | DBA |
| 22:15 | Lancement migration | 2-4h | DevOps |
| 22:15 | Configuration redirections AWS | 30 min | DevOps |
| 02:15* | Validation post-migration | 30 min | DevOps |
| 02:45* | Tests fonctionnels | 30 min | QA |
| 03:15* | Monitoring initial | 1h | DevOps |

*Horaires estimés selon durée réelle de migration

#### Phase 4: Validation (J+1 à J+7)
- Monitoring quotidien des erreurs 404
- Validation progressive des assets
- Ajustement des redirections si nécessaire
- Désactivation progressive des redirections AWS (J+7)

---

### 8. IMPACTS ET DÉPENDANCES

#### Impacts sur les Systèmes
- **Craft CMS**: Reconfiguration des filesystems (downtime: ~5 min)
- **CDN**: Mise à jour de la configuration (si applicable)
- **Backups**: Ajustement des scripts de backup

#### Impacts sur les Utilisateurs
- **Utilisateurs finaux**: Aucun impact visible (redirections transparentes)
- **Éditeurs de contenu**: Aucun impact sur le workflow
- **Développeurs**: Mise à jour des configurations locales

#### Dépendances
- Accès administrateur AWS S3
- Accès administrateur DigitalOcean Spaces
- Accès SSH au serveur Craft CMS
- Fenêtre de maintenance approuvée

---

### 9. TESTS ET VALIDATION

#### Tests Pré-Migration
- ✓ Dry-run complet (100% des assets simulés)
- ✓ Test de rollback en environnement de staging
- ✓ Validation checksums et intégrité fichiers

#### Tests Post-Migration
- Validation de 100% des assets migrés (checksums MD5)
- Tests fonctionnels des fonctionnalités critiques
- Vérification des URLs et liens
- Tests de performance et latence
- Validation des transforms d'images

#### Critères de Succès
- ✓ 100% des assets migrés avec succès
- ✓ 0 erreur de corruption de données
- ✓ Temps de réponse < seuil défini
- ✓ 0 lien cassé après migration
- ✓ Validation QA approuvée

---

### 10. COMMUNICATION

#### Avant Migration
- **J-7**: Notification CAB et parties prenantes
- **J-3**: Confirmation de la fenêtre de maintenance
- **J-1**: Rappel aux équipes techniques

#### Pendant Migration
- Updates aux responsables toutes les heures
- Notification immédiate en cas d'incident

#### Après Migration
- **J+1**: Rapport de migration (succès/échecs)
- **J+7**: Bilan post-implémentation

---

### 11. RESSOURCES REQUISES

| Ressource | Rôle | Disponibilité |
|-----------|------|---------------|
| DevOps Lead | Exécution migration | 22h-04h |
| DBA | Backup/Rollback DB | 22h-04h (on-call) |
| QA Engineer | Tests validation | 02h-04h |
| Product Owner | Décision Go/No-Go | On-call |

**Outils**:
- Spaghetti Migrator v5.0
- Accès CLI serveur production
- Outils de monitoring (logs, métriques)

---

### 12. COÛTS ET BUDGET

| Poste | Montant |
|-------|---------|
| Licence/Outil | $0 (open-source) |
| Heures supplémentaires | [X] heures × [Y]$/h |
| Double facturation cloud (période transition) | ~$[Z]/mois × 1 mois |
| **Total estimé** | **$[TOTAL]** |

**ROI**: Break-even attendu en [X] mois grâce aux économies de stockage.

---

### 13. APPROBATIONS REQUISES

| Rôle | Nom | Statut | Date |
|------|-----|--------|------|
| **CAB Chair** | | ⏳ Pending | |
| **Technical Lead** | | ⏳ Pending | |
| **Product Owner** | | ⏳ Pending | |
| **Security Officer** | | ⏳ Pending | |
| **Operations Manager** | | ⏳ Pending | |

---

### 14. ANNEXES

**Documents de référence**:
- Architecture Spaghetti Migrator: `ARCHITECTURE.md`
- Guide d'opération: `PRODUCTION_OPERATIONS.md`
- Configuration migration: `config/migration-config.php`
- Logs dry-run: `[lien vers logs]`

**Contacts d'escalade**:
- Niveau 1: [DevOps Lead] - [email/phone]
- Niveau 2: [Technical Director] - [email/phone]
- Niveau 3: [CTO] - [email/phone]

---

### DÉCISION CAB

| Décision | ☐ Approuvé | ☐ Approuvé avec conditions | ☐ Reporté | ☐ Rejeté |
|----------|------------|---------------------------|-----------|-----------|
| **Date de décision** | |
| **Date d'implémentation autorisée** | |
| **Conditions/Commentaires** | |
| **Signature CAB Chair** | |

---

**Document préparé par**: [Nom]
**Date**: 2025-12-02
**Version**: 1.0

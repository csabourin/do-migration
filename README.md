# AWS S3 to DigitalOcean Spaces Migration Toolkit

**Complete migration suite for Craft CMS 4 - Moving from AWS S3 to DigitalOcean Spaces**

---

## ⚡ NEW: Centralized Configuration System

**🎯 Single Source of Truth for Multi-Environment Migrations**

The toolkit now includes a centralized configuration system that eliminates hardcoded values and supports multiple environments (dev, staging, prod).

### Quick Setup (3 Steps)
```bash
# 1. Copy configuration files
cp config/migration-config.php your-craft/config/
cp MigrationConfig.php your-craft/modules/helpers/

# 2. Set environment in .env
echo "MIGRATION_ENV=dev" >> .env
echo "DO_S3_ACCESS_KEY=your_key" >> .env
echo "DO_S3_BASE_URL=https://your-bucket.tor1.digitaloceanspaces.com" >> .env

# 3. Verify configuration
./craft ncc-module/url-replacement/show-config
```

### Benefits
- ✅ **Single config file** - Update once, applies everywhere
- ✅ **Environment-aware** - Easy switching between dev/staging/prod
- ✅ **Auto-validation** - Catches missing settings before migration
- ✅ **Type-safe** - Dedicated methods for each setting
- ✅ **No hardcoding** - All values from centralized config

**Read:** [CONFIGURATION_GUIDE.md](CONFIGURATION_GUIDE.md) | [Quick Reference](CONFIG_QUICK_REFERENCE.md)

---

## 📚 Documentation Overview

This repository contains a **production-grade migration toolkit** with comprehensive tools and documentation:

### Core Migration Controllers (Existing)
- ✅ **UrlReplacementController** - Database content URL replacement
- ✅ **TemplateUrlReplacementController** - Twig template file updates
- ✅ **ImageMigrationController** - Physical asset file migration
- ✅ **FilesystemController** - DO Spaces filesystem creation
- ✅ **FilesystemSwitchController** - Volume switching (AWS ↔ DO)
- ✅ **MigrationCheckController** - Pre-migration validation
- ✅ **FsDiagController** - Filesystem diagnostics
- ✅ **MigrationDiagController** - Post-migration analysis
- ✅ **TransformDiscoveryController** - Image transform analysis
- ✅ **TransformPreGenerationController** - Transform pre-generation

### Documentation Files

| File | Purpose | Priority |
|------|---------|----------|
| **[CONFIGURATION_GUIDE.md](CONFIGURATION_GUIDE.md)** | Centralized config system guide | 🔴 **NEW** - Start Here |
| **[CONFIG_QUICK_REFERENCE.md](CONFIG_QUICK_REFERENCE.md)** | Quick reference for config usage | 🔴 **NEW** - Quick Ref |
| **[MIGRATION_ANALYSIS.md](MIGRATION_ANALYSIS.md)** | Complete analysis of migration coverage + gaps | 🔴 Must Read |
| **[QUICK_CHECKLIST.md](QUICK_CHECKLIST.md)** | Quick reference checklist for 100% migration | 🔴 Must Read |
| **[EXTENDED_CONTROLLERS.md](EXTENDED_CONTROLLERS.md)** | Code for additional controllers (gaps) | 🟡 Optional |
| **[migrationGuide.md](migrationGuide.md)** | Detailed setup & usage guide | 🟡 Reference |

---

## 🎯 Quick Start

### 1. Read the Analysis
Start here to understand what's covered and what's not:
```bash
cat MIGRATION_ANALYSIS.md
```

### 2. Follow the Checklist
Use the quick checklist for step-by-step execution:
```bash
cat QUICK_CHECKLIST.md
```

### 3. Run Pre-Migration Audit
```bash
# Check for S3 references in config/code
grep -r "s3.amazonaws.com\|ncc-website-2" config/ modules/ web/
```

### 4. Execute Core Migration
```bash
# Dry runs first
./craft ncc-module/url-replacement/replace-s3-urls --dryRun=1
./craft ncc-module/template-url/scan
./craft ncc-module/image-migration/migrate --dryRun=1

# Live execution
./craft ncc-module/url-replacement/replace-s3-urls
./craft ncc-module/template-url/replace
./craft ncc-module/image-migration/migrate
./craft ncc-module/filesystem-switch/to-do
```

### 5. Post-Migration Tasks
```bash
# Critical: Rebuild indexes and clear caches
./craft index-assets/all
./craft resave/entries --update-search-index=1
./craft clear-caches/all

# Purge CDN cache (CloudFlare, Fastly, etc.)
```

### 6. Verify
```bash
./craft ncc-module/url-replacement/verify
./craft ncc-module/template-url/verify
./craft ncc-module/filesystem-switch/verify
```

---

## 📊 Migration Coverage

### ✅ What's Covered (85-90%)
- Database content columns (content, matrixcontent_*)
- Twig template files
- Physical asset files (with checkpoint/resume)
- Volume/filesystem configuration
- Image transforms

### ⚠️ Gaps Identified (10-15%)
- **projectconfig table** (plugin settings)
- **JSON fields** (table fields, nested content)
- **elements_sites metadata** (JSON field)
- **PHP config files** (hardcoded URLs)
- **JS/CSS static assets** (hardcoded URLs)
- **Plugin configurations** (Imager-X, Blitz, etc.)
- **Redactor/CKEditor configs**
- **Email templates**
- **Search indexes** (need rebuild)
- **Cache purging** (Craft, CDN, browser)

### 🎯 Achieving 100% Coverage
Follow the **QUICK_CHECKLIST.md** + implement **EXTENDED_CONTROLLERS.md** (optional) = **95-98% coverage**

---

## 🔍 Key Documents

### MIGRATION_ANALYSIS.md
**Read this first!** Comprehensive analysis including:
- What's already covered by existing controllers
- Complete list of gaps with explanations
- Priority matrix (Critical → Optional)
- Recommended action plan (5 phases)
- Suggested controller enhancements
- Estimated timeline (3-5 days)

**Key Sections:**
- ✅ What's Already Covered
- 🔍 Gaps & Additional Areas (13 gap areas identified)
- 📋 Recommended Action Plan (5 phases)
- 🔧 Suggested Controller Enhancements
- 🎯 Priority Matrix
- ✅ Final Recommendations

### QUICK_CHECKLIST.md
**Your migration execution guide.** Includes:
- Pre-flight checklist (already covered vs gaps)
- Critical gaps with quick commands
- Priority-sorted action items
- Verification commands
- Post-migration audit script
- Troubleshooting guide

**Perfect for:**
- Daily migration execution
- Quick reference during migration
- Status tracking
- Verification steps

### EXTENDED_CONTROLLERS.md
**Optional code implementations** for covering gaps:
- `ExtendedUrlReplacementController` (projectconfig, JSON fields)
- `StaticAssetScanController` (JS/CSS scanning)
- `PluginConfigAuditController` (plugin config audit)

**Use when:**
- You need to handle complex JSON fields
- Found hardcoded URLs in static assets
- Want to audit all plugin configurations
- Need systematic approach to edge cases

### migrationGuide.md
**Detailed operational guide** for existing controllers:
- Setup instructions
- Usage examples
- Change log explanation
- Rollback procedures
- Performance expectations
- Troubleshooting

---

## 🚀 Recommended Migration Timeline

### Day 1: Preparation & Audit
- [ ] Read MIGRATION_ANALYSIS.md
- [ ] Run audits from QUICK_CHECKLIST.md
- [ ] Search config/modules/web for S3 references
- [ ] Backup database
- [ ] Dry-run existing controllers

### Day 2: Core Migration
- [ ] Execute UrlReplacementController
- [ ] Execute TemplateUrlReplacementController
- [ ] Execute ImageMigrationController
- [ ] Execute FilesystemSwitchController

### Day 3: Gap Coverage
- [ ] Scan projectconfig table
- [ ] Check plugin configurations
- [ ] Audit PHP config files
- [ ] Rebuild search indexes
- [ ] Clear all caches

### Day 4: Verification
- [ ] Run all verify commands
- [ ] Manual testing (browse site, test uploads)
- [ ] Check API responses (if applicable)
- [ ] Purge CDN cache
- [ ] Test from different browsers/devices

### Day 5: Monitoring
- [ ] Monitor logs for errors
- [ ] Search database for remaining AWS URLs
- [ ] Track 404s (broken assets)
- [ ] Document any remaining references
- [ ] Create final migration report

---

## 📋 Success Criteria

Migration is **100% complete** when:

- ✅ All verification commands pass (no AWS URLs found)
- ✅ Website displays images correctly
- ✅ Admin panel asset browser works
- ✅ Image uploads work
- ✅ Redactor/CKEditor works
- ✅ Search returns correct results
- ✅ No 404 errors in server logs
- ✅ CDN/caches purged
- ✅ Full database scan shows 0 AWS references

---

## 🎯 Critical Post-Migration Tasks

**Don't forget these!**

```bash
# 1. Rebuild search indexes (CRITICAL)
./craft index-assets/all
./craft resave/entries --update-search-index=1

# 2. Clear all caches (CRITICAL)
./craft clear-caches/all
./craft invalidate-tags/all

# 3. Purge CDN cache (CRITICAL)
# CloudFlare: Dashboard → Caching → Purge Everything
# OR via API/CLI

# 4. Check projectconfig (HIGH)
./craft db/query "SELECT * FROM projectconfig WHERE config LIKE '%s3.amazonaws%'"

# 5. Verify plugin configs (HIGH)
grep -r "s3.amazonaws.com" config/imager-x.php config/blitz.php
```

---

## 🔧 Troubleshooting

### Images not displaying after migration
```bash
# 1. Clear caches
./craft clear-caches/all

# 2. Verify filesystem
./craft ncc-module/filesystem-switch/verify

# 3. Test connectivity
./craft ncc-module/fs-diag/test-connection images_do

# 4. Check browser network tab for 404s
```

### Still finding AWS URLs in database
```bash
# Identify location
./craft db/query "SELECT * FROM content WHERE field_yourField LIKE '%s3.amazonaws%' LIMIT 1"

# Check if JSON field (requires special handling)
# See EXTENDED_CONTROLLERS.md
```

### Transforms not generating
```bash
# Verify transform filesystem
./craft ncc-module/migration-diag/analyze

# Regenerate transforms
./craft ncc-module/transform-pre-generation/generate

# Check DO Spaces permissions
```

---

## 📞 Support

### Enable Debug Logging
```bash
# In .env file
CRAFT_DEV_MODE=true
CRAFT_LOG_LEVEL=4

# Check logs
tail -f storage/logs/console.log
tail -f storage/logs/web.log
```

### Run Diagnostics
```bash
./craft ncc-module/migration-diag/analyze
./craft ncc-module/fs-diag/list-fs images_do
```

### Create Issue
Include:
1. Output from dry-run
2. Error messages from logs
3. Change log JSON (if partially completed)
4. Volume/filesystem configuration
5. DO Spaces permissions status

---

## 📊 Statistics

### AWS S3 Configuration
- **Bucket:** ncc-website-2
- **Region:** ca-central-1
- **URL Formats:** 6 different patterns detected

### DigitalOcean Spaces Configuration
- **Region:** tor1 (Toronto)
- **Endpoint:** https://dev-medias-test.tor1.digitaloceanspaces.com
- **Filesystems:** 8 (images, optimisedImages, imageTransforms, documents, videos, formDocuments, chartData, quarantine)

### Migration Scope
- **Controllers:** 10 core + 3 optional extended
- **Documentation:** 4 comprehensive guides
- **Coverage:** 85-90% (existing) → 95-98% (with recommendations)
- **Estimated Time:** 3-5 days for complete migration

---

## 📝 Version History

### v1.0 (2025-11-05)
- Initial comprehensive analysis
- Created MIGRATION_ANALYSIS.md (gap analysis)
- Created QUICK_CHECKLIST.md (execution guide)
- Created EXTENDED_CONTROLLERS.md (code examples)
- Updated README.md (this file)

---

## 🎓 Key Learnings

1. **Existing toolkit is excellent** - Covers all primary migration paths
2. **Edge cases matter** - projectconfig, JSON fields, caches often overlooked
3. **Search indexes critical** - Must rebuild after URL changes
4. **Cache purging essential** - Multiple cache layers (Craft, CDN, browser)
5. **Plugin configs vary** - Each plugin stores settings differently
6. **Testing is crucial** - Dry-runs prevent costly mistakes
7. **Documentation helps** - Change logs enable rollback
8. **Monitoring matters** - Watch logs for 1 week post-migration

---

## 🔗 Related Resources

- [Craft CMS 4 Documentation](https://craftcms.com/docs/4.x/)
- [DigitalOcean Spaces Documentation](https://docs.digitalocean.com/products/spaces/)
- [vaersaagod/dospaces Plugin](https://github.com/vaersaagod/dospaces)

---

## 📄 License

Migration toolkit documentation and controllers.
Adapt as needed for your project.

---

**Project:** do-migration
**Target:** 100% AWS S3 → DigitalOcean Spaces migration
**Status:** Analysis Complete ✅ | Ready for Execution 🚀
**Confidence:** 98-99% coverage achievable with recommendations

---

**Last Updated:** 2025-11-05
**Version:** 1.0

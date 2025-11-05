# 100% AWS to DO Migration - Quick Checklist

**Target:** Zero AWS S3 references remaining
**Bucket:** ncc-website-2

---

## ✅ Already Covered by Existing Controllers

- [x] Database content columns (content, matrixcontent_*)
- [x] Twig template files (.twig)
- [x] Physical asset files
- [x] Filesystem/Volume configuration
- [x] Transform handling

---

## ❌ Critical Gaps - Must Address

### 1. Search Indexes (5 min)
```bash
# After URL migration, rebuild search
./craft index-assets/all
./craft resave/entries --update-search-index=1
./craft clear-caches/all
```

### 2. Cache Purging (10 min)
```bash
# Craft caches
./craft clear-caches/all
./craft invalidate-tags/all

# If using Blitz
./craft blitz/cache/clear

# CloudFlare (manual or via API)
# Dashboard → Caching → Purge Everything

# Browser cache
# Test in incognito mode after changes
```

### 3. Database projectconfig Table (30 min)
```bash
# Search for S3 URLs in plugin configurations
./craft db/query "SELECT * FROM projectconfig WHERE config LIKE '%s3.amazonaws%'"
./craft db/query "SELECT * FROM projectconfig WHERE config LIKE '%ncc-website-2%'"

# Manual fix: Edit plugin settings in Craft Admin
# OR: Update JSON directly in database (advanced)
```

### 4. PHP Config Files (20 min)
```bash
# Search all config files
grep -r "s3.amazonaws.com" config/
grep -r "ncc-website-2" config/
grep -r "s3.amazonaws.com" modules/

# Check specific configs:
# - config/general.php
# - config/imager-x.php (if using Imager)
# - config/blitz.php (if using Blitz)
# - config/redactor/ (rich text editor configs)

# Replace hardcoded URLs with environment variables
```

### 5. Plugin Configuration Audit (30 min)
```bash
# List installed plugins
composer show | grep craftcms

# Check common plugins:
# □ Imager-X: config/imager-x.php
# □ Blitz: config/blitz.php
# □ SEO plugins: Check metadata templates
# □ Form plugins: Check upload destinations
# □ Redactor: config/redactor/*.json

# Review each plugin's settings in Craft Admin
```

---

## ⚠️ High Priority Gaps

### 6. JSON Field Data (1-2 hours)
**Issue:** Table fields, Matrix blocks with nested S3 URLs

**Quick Check:**
```bash
# Search for JSON fields with S3 URLs
./craft db/query "SELECT * FROM content WHERE JSON_SEARCH(field_tableField, 'one', '%s3.amazonaws%') IS NOT NULL" 2>/dev/null
```

**Solution:** See `EXTENDED_CONTROLLERS.md` for JSON-aware replacement code

### 7. elements_sites Metadata (10 min)
```bash
# Check metadata column for S3 URLs
./craft db/query "SELECT * FROM elements_sites WHERE metadata LIKE '%s3.amazonaws%'"

# If found, manual update needed (JSON field)
```

### 8. Static JS/CSS Files (30 min)
```bash
# Search JavaScript files
grep -r "s3.amazonaws.com" web/assets/ 2>/dev/null
grep -r "ncc-website-2" web/assets/ 2>/dev/null

# Search inline JS/CSS in templates
grep -r "s3.amazonaws.com" templates/ --include="*.twig" | grep -E "<script|<style"

# Manual update if found
```

---

## 🟡 Medium Priority Gaps

### 9. Redactor/CKEditor Configs (15 min)
```bash
# Check Redactor configs
cat config/redactor/*.json | grep -i "s3\|amazonaws"

# Check in database
./craft db/query "SELECT * FROM projectconfig WHERE path LIKE '%redactor%' AND config LIKE '%s3.amazonaws%'"

# Update configs if needed
```

### 10. Email Templates (15 min)
```bash
# Check email templates
grep -r "s3.amazonaws.com" templates/emails/ 2>/dev/null
grep -r "ncc-website-2" templates/emails/ 2>/dev/null

# Test email sending after migration
```

### 11. User Profile Photos (10 min)
```bash
# Check if user photos are in migrated volume
./craft db/query "SELECT u.id, u.username, a.volumeId, a.filename
FROM users u
JOIN assets a ON u.photoId = a.id
WHERE u.photoId IS NOT NULL"

# Verify photos work after migration
```

### 12. GraphQL/API Responses (20 min)
```bash
# If using GraphQL, test asset URLs
curl -X POST https://your-site.com/api \
  -H "Content-Type: application/json" \
  -d '{"query":"{ assets { url } }"}'

# Verify URLs point to DO Spaces, not AWS
```

---

## 🟢 Low Priority / Optional

### 13. Entry Revisions (Advanced)
**Decision:** Accept old revisions have AWS URLs OR purge before migration

```bash
# Option A: Accept (document in MIGRATION_NOTES.md)
# Option B: Prune old revisions
./craft resave/entries --update-search-index=0

# Option C: Update revisions table (slow, not recommended)
```

### 14. Global Sets (10 min)
```bash
# Check global sets for S3 URLs
./craft db/query "SELECT * FROM globalsets"
./craft db/query "SELECT * FROM content WHERE elementId IN (SELECT id FROM elements WHERE type = 'craft\\\\elements\\\\GlobalSet')"
```

### 15. Database Full Scan (Verification)
```bash
# Export database and search for AWS references
mysqldump your_database > dump.sql
grep -i "s3.amazonaws.com" dump.sql | wc -l
grep -i "ncc-website-2" dump.sql | wc -l

# If count > 0, investigate specific occurrences
grep -i "s3.amazonaws.com" dump.sql | head -20
```

---

## 🚀 Recommended Migration Order

### **Day 1: Preparation**
1. ✅ Backup database: `ddev export-db --file=pre-migration.sql.gz`
2. ✅ Run audits from checklist items #4, #5, #8
3. ✅ Document findings
4. ✅ Dry-run existing controllers

### **Day 2: Primary Migration**
1. ✅ Execute existing controllers:
   ```bash
   ./craft url-replacement/replace-s3-urls
   ./craft template-url/replace
   ./craft ncc-module/image-migration/migrate
   ./craft ncc-module/filesystem-switch/to-do
   ```

### **Day 3: Gap Coverage**
1. ✅ Address critical gaps (#1-5)
2. ✅ Clear all caches (#2)
3. ✅ Rebuild indexes (#1)
4. ✅ Verify plugin configs (#5)

### **Day 4: Verification & Testing**
1. ✅ Run verification commands
2. ✅ Manual testing (browse site, test uploads)
3. ✅ Check API responses (if applicable)
4. ✅ Purge CDN cache

### **Day 5: Monitoring**
1. ✅ Monitor logs for errors
2. ✅ Run full database scan (#15)
3. ✅ Document any remaining AWS references
4. ✅ Create final report

---

## 📊 Verification Commands

```bash
# 1. Verify database content
./craft url-replacement/verify

# 2. Verify templates
./craft template-url/verify

# 3. Verify filesystems
./craft ncc-module/filesystem-switch/verify

# 4. Search database for remaining AWS URLs
./craft db/query "SELECT TABLE_NAME, COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
AND DATA_TYPE IN ('text', 'mediumtext', 'longtext')" | \
while read table column; do
  echo "Checking $table.$column"
  ./craft db/query "SELECT COUNT(*) FROM \`$table\` WHERE \`$column\` LIKE '%s3.amazonaws%'"
done

# 5. Check projectconfig
./craft project-config/rebuild
./craft project-config/apply

# 6. Test image URLs on site
curl -s https://your-site.com | grep -o "https://[^\"']*amazonaws.com[^\"']*"

# 7. Test admin panel
# Manually check: Settings → Assets → Filesystems
# Verify all filesystems show DO Spaces
```

---

## 🔍 Post-Migration Audit Script

Save as `check-aws-references.sh`:

```bash
#!/bin/bash

echo "=== AWS S3 Reference Checker ==="
echo ""

# Config files
echo "1. Checking config files..."
grep -r "s3.amazonaws.com\|ncc-website-2" config/ 2>/dev/null && echo "⚠️  Found in config/" || echo "✓ Config clean"

# PHP files
echo "2. Checking PHP files..."
grep -r "s3.amazonaws.com\|ncc-website-2" modules/ plugins/ 2>/dev/null && echo "⚠️  Found in modules/plugins" || echo "✓ PHP clean"

# Static assets
echo "3. Checking JS/CSS..."
grep -r "s3.amazonaws.com\|ncc-website-2" web/assets/ 2>/dev/null && echo "⚠️  Found in web/assets/" || echo "✓ Static assets clean"

# Templates (should be clean after template-url controller)
echo "4. Checking templates..."
grep -r "s3.amazonaws.com\|ncc-website-2" templates/ 2>/dev/null && echo "⚠️  Found in templates/" || echo "✓ Templates clean"

# Database (sample check)
echo "5. Checking database..."
./craft db/query "SELECT COUNT(*) as count FROM content WHERE CONCAT_WS('',field_*) LIKE '%s3.amazonaws%'" 2>/dev/null

echo ""
echo "=== Audit Complete ==="
```

```bash
chmod +x check-aws-references.sh
./check-aws-references.sh
```

---

## 🎯 Success Criteria

Migration is **100% complete** when:

- ✅ All verification commands pass (no AWS URLs found)
- ✅ Website displays images correctly (manual check)
- ✅ Image uploads work in admin panel
- ✅ Redactor/CKEditor image browsing works
- ✅ Email templates send with correct images
- ✅ API responses (if applicable) return DO URLs
- ✅ Search results show correct URLs
- ✅ No 404 errors for assets (check server logs)
- ✅ CDN/caches purged and tested
- ✅ Full database scan shows 0 AWS references

---

## 📞 Troubleshooting

### "Still finding AWS URLs in database"
```bash
# Identify specific location
./craft db/query "SELECT * FROM content WHERE field_yourField LIKE '%s3.amazonaws%' LIMIT 1"

# Check table type
# If JSON field, see EXTENDED_CONTROLLERS.md for solution
```

### "Images not displaying after migration"
```bash
# 1. Clear caches
./craft clear-caches/all

# 2. Check filesystem config
./craft ncc-module/filesystem-switch/verify

# 3. Test filesystem connectivity
./craft ncc-module/fs-diag/test-connection images_do

# 4. Check browser network tab for 404s
```

### "Transforms not generating"
```bash
# 1. Verify transform filesystem
./craft ncc-module/migration-diag/analyze

# 2. Manually regenerate
./craft ncc-module/transform-pre-generation/generate

# 3. Check DO Spaces permissions (write access)
```

---

## 📝 Final Notes

- **Estimated Time:** 3-5 days for complete migration
- **Downtime Required:** Minimal (can do most during low traffic)
- **Risk Level:** Low (existing controllers are well-tested)
- **Rollback:** Change logs + database backups make rollback possible

**Most Critical Items:**
1. Search index rebuild (#1)
2. Cache purging (#2)
3. Plugin configs (#5)
4. projectconfig table (#3)

Focus on these 4 items + existing controllers = **95%+ coverage**

---

**Checklist Version:** 1.0
**Date:** 2025-11-05

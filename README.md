# NCC Migration Module

A Composer-installable Craft CMS 4 module that bundles the NCC AWS S3 → DigitalOcean Spaces migration toolkit. The package wraps the existing console controllers, dashboard, and helper utilities into a single module that can be dropped into any Craft 4 project without manual bootstrapping.

## ✨ Highlights

- Ships as a PSR-4 autoloaded Craft module (`type: craft-module`)
- Automatic bootstrap via `bootstrap.php` – no need to edit `config/app.php`
- Includes a ready-to-copy default configuration at `vendor/ncc/migration-module/modules/config/migration-config.php`
- Keeps all original console/web controllers, Twig filters, and dashboard templates intact

## 🚀 Installation

1. **Add the repository to your Craft project's `composer.json`** (if it is not published on Packagist):
   ```json
   {
     "repositories": [
       {
         "type": "path",
         "url": "../path-to/do-migration"
       }
     ]
   }
   ```

2. **Require the module**
   ```bash
   composer require ncc/migration-module:dev-main
   ```

3. **Copy the configuration stub (optional but recommended)**
   ```bash
   cp vendor/ncc/migration-module/modules/config/migration-config.php config/migration-config.php
   ```

4. **Update your `.env` file** with the DigitalOcean credentials referenced in the config (`DO_S3_*`, `MIGRATION_ENV`).

5. **Verify the module is registered**
   ```bash
   ./craft ncc-module/migration-check/check
   ```

The Composer autoloader executes `bootstrap.php`, which registers the module handle (`ncc-module`) with Craft for both web and console requests. No further configuration is required.

## 🧭 Directory Structure

```
modules/
├── NCCModule.php                 # Module entry point
├── console/controllers/…         # 13 migration console controllers
├── controllers/…                 # CP dashboard controller
├── helpers/MigrationConfig.php   # Centralised configuration helper
├── filters/…                     # Custom Twig filters
├── templates/ncc-module/…        # Control Panel templates
└── config/migration-config.php   # Default configuration wrapper
```

Refer to `ARCHITECTURE.md` for a deep dive into each subsystem.

## ✅ Post-install Checklist

- [ ] Run `./craft clear-caches/all`
- [ ] Review `config/migration-config.php` and tailor filesystem handles
- [ ] Confirm DigitalOcean Spaces credentials via `./craft ncc-module/migration-check/check`
- [ ] Execute the migration controllers as described in `DASHBOARD.md`

## 📚 Additional Resources

- `SOLUTION.md` – troubleshooting missing method errors
- `DASHBOARD.md` – Control Panel dashboard reference
- `CONFIG_QUICK_REFERENCE.md` – configuration cheat sheet
- `ARCHITECTURE.md` – full module architecture documentation

---
Craft CMS® is a trademark of Pixel & Tonic. NCC Migration Module is not affiliated with or endorsed by Pixel & Tonic.

# ps8_image_manager

PrestaShop 8 module — WebP conversion and image optimization tools for product images.

## Features

- **WebP conversion**: batch convert product images (JPG/PNG → WebP) via AJAX with progress tracking
- **Image optimization**: re-compress images in-place to reduce file size without visible quality loss
- **Statistics dashboard**: coverage %, disk space savings, image format breakdown
- **Capability detection**: auto-detects Imagick or falls back to GD

## Requirements

- PrestaShop 8.x
- PHP 8.1+
- GD extension (built-in) or Imagick extension (optional, better quality)
- Doctrine DBAL (provided by PrestaShop)

## Installation

Upload to `modules/sc_image_manager/` and install from Back Office > Modules.

Registers under **Advanced Parameters > Scriptami** via the shared `AdminScriptami` parent tab.

## Architecture

```
src/
├── Controller/Admin/     # ImageManagerController (4 actions: index, webp-batch, optimize-batch, stats)
├── Service/              # WebpConverterService, ImageOptimizerService
└── Traits/               # HaveScriptamiTab
```

## Tests

```bash
composer install
./vendor/bin/phpunit --testdox
```

25 tests, 65 assertions.

## Part of the Scriptami Suite

- [ps8_verify_multishop](https://github.com/RebelliousSmile/ps8_verify_multishop) — Multishop data integrity
- [ps8_replace_text](https://github.com/RebelliousSmile/ps8_replace_text) — Find & replace across the database
- [ps8_giftcard_repair](https://github.com/RebelliousSmile/ps8_giftcard_repair) — Gift card data repair
- [ps8_iqit_repair](https://github.com/RebelliousSmile/ps8_iqit_repair) — IQIT Warehouse theme module repair
- [ps8_import_dumps](https://github.com/RebelliousSmile/ps8_import_dumps) — SQL dump comparison and import
- [ps8_image_manager](https://github.com/RebelliousSmile/ps8_image_manager) — WebP conversion and image optimization

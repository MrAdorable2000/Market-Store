# Image Assets — Isoko Ryacu

## Where the images live

| Directory | Purpose |
|-----------|---------|
| `assets/images/real/` | **Real photographs** (JPEG, optimized). Used for hero, listing covers, blog covers, seller avatars, favicon. |
| `assets/images/placeholders/` | **Branded SVG placeholders**. Used as automatic fallback if a real photo is missing, and for empty states. |

## How images are resolved

The `real_image($name, $fallback)` helper in `includes/functions.php`:

1. Looks for `assets/images/real/<name>.jpg` — if it exists, returns that URL.
2. Otherwise falls back to `assets/images/placeholders/<fallback>.svg`.

The `image_or_default($path, $default)` helper:

1. If `$path` is set (a DB-stored path), uses it directly.
2. Otherwise tries `assets/images/real/<stem-of-default>.jpg`.
3. Otherwise falls back to the SVG placeholder.

This means: replace any file in `assets/images/real/` with a better photo and the entire UI updates — no code changes needed.

## Available real images

| Name | Used for | Subject |
|------|----------|---------|
| `hero.jpg` | Homepage hero | African marketplace scene (Pexels) |
| `vehicle.jpg` | Toyota Corolla listing | Sedan car (multiple sources) |
| `motorcycle.jpg` | Honda motorcycle listing | Motorcycle |
| `phone.jpg` | iPhone 13 listing | Smartphone |
| `apartment.jpg` | Apartment rental listing | Modern African apartment |
| `land.jpg` | Land plot listing | African countryside plot |
| `laptop.jpg` | HP EliteBook listing | Laptop |
| `sofa.jpg` | Sofa set listing | Fabric sofa |
| `tent.jpg` | Event tent rental listing | Outdoor wedding tent |
| `agriculture.jpg` | Irrigation pump rental | African farmer / pump |
| `service.jpg` | Airport pickup service | African taxi driver |
| `tablet.jpg` | iPad listing | Tablet device |
| `house.jpg` | 4-bedroom house rental | Modern African house |
| `blog-safety.jpg` | "How to buy safely" blog | Online shopping safety |
| `blog-sell.jpg` | "Sell online like a pro" blog | African entrepreneur |
| `blog-rent.jpg` | "Renting property" blog | Rental keys handover |
| `blog-scam.jpg` | "Avoid scams" blog | Online scam warning |
| `blog-laptop.jpg` | "Choosing a used laptop" blog | Laptop repair |
| `seller-aline.jpg` | Featured seller avatar | African woman entrepreneur |
| `seller-eric.jpg` | Featured seller avatar | African man business owner |
| `avatar-buyer.jpg` | Buyer avatar | African woman customer |
| `favicon.jpg` | Site favicon | Marketplace logo |

## Source attribution

All images were retrieved via the **ZAI image-search service** (`z-ai image-search` CLI), which surfaces publicly available images from sources such as Pexels, Unsplash, Forbes Africa, YouTube, and others. Each image is re-hosted on the ZAI OSS CDN for stable embedding, and then downloaded locally into `assets/images/real/` so the project is self-contained and works offline.

## Replacing images

The current images are suitable for an academic demo and Phase 1 development. Before any commercial deployment:

1. Audit each image's license (the source name is shown in the original `z-ai image-search` JSON output).
2. Replace any images whose license does not permit your intended use.
3. Upload properly licensed replacements to `assets/images/real/` with the same filenames — the UI updates automatically.

## Regenerating the SVG placeholders (optional)

```bash
python3 scripts/gen_placeholders.py
```

The Python script writes 21 branded SVG files to `assets/images/placeholders/`. The placeholders are still used as fallbacks (and for categories without a real photo yet — e.g. "Fashion", "Construction Materials", "Other Products").

## Why we don't hotlink

The user's brief says:

> Do not hotlink random images from websites. Use local image paths or a reliable image-storage system.

We followed this rule strictly. Every image is downloaded and stored locally under `assets/images/real/`. The project has zero external image hotlinks.

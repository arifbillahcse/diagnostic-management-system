# LabNest landing page

Static marketing site for the diagnostic software. Plain HTML, CSS and
JavaScript: no build step, no framework, no external fonts or scripts. It is
separate from the Laravel application and shares no code with it.

## Before publishing: replace the placeholders

Search the whole folder and replace:

| Placeholder | Replace with | Files |
|---|---|---|
| `LabNest` | Your product name | all files |
| `https://www.example.com.bd` | Your real domain | `index.html`, `privacy.html`, `terms.html`, `robots.txt`, `sitemap.xml`, `llms.txt`, `.htaccess` |
| `8801XXXXXXXXX` / `+880 1XXX-XXXXXX` | Your WhatsApp number | `index.html`, `js/main.js`, `llms.txt` |
| `hello@example.com.bd` | Your email | `index.html`, `js/main.js`, `privacy.html`, `llms.txt` |
| `Your street address` | Office address | `index.html`, `privacy.html` |
| `Contact us` (pricing cards) | Prices, once decided | `index.html` |

After renaming the product, regenerate `img/og-image.png` (the image shown
when the link is shared) and change `img/favicon.svg` if you have a logo.

Also:
- Have a lawyer review `privacy.html` and `terms.html`, then remove the
  "Draft" notes.
- Add a real testimonial after the pilot: the section is commented out in
  `index.html` ("Testimonial slot"). Never publish invented quotes.
- When prices are fixed, add `offers` (price, `priceCurrency: "BDT"`) to the
  `SoftwareApplication` data in the `<head>` of `index.html`.
- Update `lastmod` dates in `sitemap.xml` when pages change.

## Hosting

Upload the folder's contents to the domain's document root (cPanel File
Manager, or any static host). `.htaccess` forces HTTPS and `www`, compresses
files and sets cache headers on Apache/LiteSpeed; edit its domain line, or
delete the `www` rule if you use the bare domain.

## After going live

1. Google Search Console: add the domain, submit `sitemap.xml`.
2. Bing Webmaster Tools: import from Search Console (Bing also feeds
   ChatGPT search and Copilot).
3. Google Business Profile for the office address.
4. Test: Google Rich Results Test (FAQ), PageSpeed Insights, and the
   Facebook Sharing Debugger for the share image.

## What is in the page for search and AI

- SEO: title, description, canonical, one H1 with ordered headings, Open
  Graph and Twitter cards, `en-BD` language, sitemap, robots, fast static
  files, mobile layout, accessible labels.
- AEO: FAQ written as question and direct answer, with matching `FAQPage`
  structured data; `HowTo` for the go-live steps.
- GEO: `Organization`, `WebSite` and `SoftwareApplication` structured data,
  `llms.txt` with the key facts, content readable without JavaScript, and AI
  crawlers allowed in `robots.txt` (change `Allow` to `Disallow` to block one).

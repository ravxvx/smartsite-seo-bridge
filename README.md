# SmartSite SEO Bridge 1.4.0

Full control of Rank Math SEO for administrators and connected tools (Jetpack / WordPress.com MCP, REST, Abilities API).

## What's new in 1.4.0

- Content snippets tab: add or remove marked SEO intros (bundled in snippets/seo-intros.json) at the start of posts.

## 1.3.1

- Audit tab: Export PDF (print view), CSV and JSON of every item with score, keyword, words and each failed check with a fix tip.

## 1.3.0
- **SEO audit with estimated score** (target 81+, Rank Math shows green above 80): focus keyword in title / description / URL / first 10% / content / subheading / image alt, content length, density, links, unique keyword, title and description length. Every failed check has a tip.
  The score is an estimate; Rank Math computes its own score only in the editor.
- **Fixed:** `sanitize_text_field` removed `%date%`, `%category%` and similar Rank Math variables. A safe sanitizer keeps them.
- **Fixed:** correct permission checks for post and term meta; character limits counted with `mb_strlen`.
- **More fields:** Facebook image, Twitter card type / image / use-Facebook, primary category, advanced robots (max-snippet, max-video-preview, max-image-preview).
- **Schema:** FAQPage, Service, ProfessionalService, SoftwareApplication (REST `schema` field).
- **History + undo** of every per-item SEO change (last 200).
- **Global settings** (titles, general, sitemap, instant-indexing, modules read-only) with **dry-run preview**, automatic **backups** and **restore**.
- **Redirects** through the Rank Math Redirections module (module must be enabled).
- **Term SEO** routes for categories, tags and other public taxonomies.
- **Dashboard** (Tools > SmartSite SEO Bridge): audit table with filters and pagination, full edit form, history, settings and redirects tabs.
- Activation hook, `uninstall.php` (removes only this plugin's own data), Rank Math missing notice.

## REST (namespace `smartsite-seo/v1`, administrators only)
| Route | Purpose |
|---|---|
| `GET /items?post_type=page&audit=1&search=&page=1` | list with estimated score |
| `GET/POST /pages/{id}` | read / update SEO fields, robots, advanced_robots, schema |
| `GET /pages/{id}/audit` | audit one item |
| `GET/POST /terms/{taxonomy}/{id}` | term SEO |
| `GET /history`, `POST /history/{id}/undo` | change history |
| `GET/POST /settings/{group}` | `{"values": {...}, "dry_run": true}` |
| `GET /settings-backups`, `POST /settings-backups/{id}/restore` | backups |
| `GET/POST /redirects` | `{"from":"/old/","to":"/new/","code":301}` |
| `GET /status` | versions and capabilities |

Example update:
```json
{"title":"GLPI Plugins | SmartSite","description":"...","focus_keyword":"GLPI plugins",
 "schema":{"FAQPage":{"questions":[{"question":"Is it free?","answer":"Yes."}]}}}
```
An empty string (or `null` for a schema) removes a field.

## Security
Administrators only (`manage_options`), REST nonce, no tokens stored. Settings changes are validated, backed up and reversible.

## Install
Plugins > Add New > Upload Plugin (replace 1.2.0) > activate.

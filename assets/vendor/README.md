# Vendored front-end libraries

Bootstrap, Bootstrap Icons, and Chart.js are committed here as static files
instead of being loaded from a CDN (`cdn.jsdelivr.net`). Reasons:

- One less third-party DNS/TLS handshake on every page load — on a slow or
  congested connection this was showing up as a visibly slower, "unstyled"
  first paint.
- `assets/.htaccess` sets long-lived `Cache-Control` headers for everything
  under `assets/`, which only helps if the files are actually served from
  this origin.
- No dependency on jsdelivr's uptime for the app to render at all.

## Versions currently vendored

| Library         | Version | Source                                         |
|------------------|---------|------------------------------------------------|
| Bootstrap        | 5.3.3   | `npm install bootstrap@5.3.3`                   |
| Bootstrap Icons  | 1.11.3  | `npm install bootstrap-icons@1.11.3`            |
| Chart.js         | 4.4.0   | `npm install chart.js@4.4.0`                    |

## Updating a version

```bash
npm install bootstrap@<version> bootstrap-icons@<version> chart.js@<version>
cp node_modules/bootstrap/dist/css/bootstrap.min.css        assets/vendor/bootstrap/css/
cp node_modules/bootstrap/dist/css/bootstrap.min.css.map    assets/vendor/bootstrap/css/
cp node_modules/bootstrap/dist/js/bootstrap.bundle.min.js   assets/vendor/bootstrap/js/
cp node_modules/bootstrap/dist/js/bootstrap.bundle.min.js.map assets/vendor/bootstrap/js/
cp node_modules/bootstrap-icons/font/bootstrap-icons.min.css assets/vendor/bootstrap-icons/font/
cp -r node_modules/bootstrap-icons/font/fonts                assets/vendor/bootstrap-icons/font/
cp node_modules/chart.js/dist/chart.umd.js assets/vendor/chartjs/chart.umd.min.js
```

Then bump the version table above and update this README.

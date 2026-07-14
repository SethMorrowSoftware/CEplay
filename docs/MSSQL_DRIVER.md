# Installing the MSSQL PHP driver (Go-Kart Labor report)

The Go-Kart Labor page queries the CenterEdge MSSQL database directly, which
needs one PHP extension the stock runtime doesn't ship: `pdo_dblib`
(FreeTDS), `pdo_sqlsrv` (Microsoft), or `pdo_odbc`. The page's **Test
connection** button tells you whether one is present.

## Fedora CoreOS (containerized app) — the normal case

On FCOS the host is immutable and the app runs in a container, so the driver
goes **into the container image**, not onto the host. A ready-made overlay
build is included at `deploy/Containerfile.mssql` (FreeTDS / `pdo_dblib` —
small, no external repos, plenty for this report's read-only SELECTs).

1. Find the image your app container currently uses:

   ```bash
   podman ps --format '{{.Names}}  {{.Image}}'
   ```

2. Build the overlay from the repo checkout, pointing at that image:

   ```bash
   podman build -f deploy/Containerfile.mssql \
       --build-arg BASE_IMAGE=<image from step 1> \
       -t ceplay-app:mssql .
   ```

3. Point the service at the new tag and restart:
   - **Quadlet** (`/etc/containers/systemd/*.container`): change `Image=` to
     `ceplay-app:mssql`, then `systemctl daemon-reload && systemctl restart <unit>`.
   - **podman-compose**: change the `image:` line, then `podman-compose up -d`.

4. Verify and test:

   ```bash
   podman exec <container> php -m | grep -i pdo_dblib
   ```

   Then reload the Go-Kart Labor page and hit **Test connection** — it
   should report `Connected via dblib` with today's sales and labor figures.

Notes:
- The app connects out to the SQL box on TCP 1433; FCOS's default firewall
  and SELinux policy allow outbound container traffic, so no host changes
  are usually needed. If the test times out, check that the SQL Server
  allows remote TCP connections and that 1433 is open on ITS firewall.
- `deploy/Containerfile.mssql` handles both Debian- and Alpine-based
  official `php:*` images. If your app image is NOT derived from
  `docker.io/library/php` (`docker-php-ext-install` missing), the FreeTDS
  package for your base distro plus its `pdo_dblib` package is the
  equivalent — or ask and we'll adapt the Containerfile.

## Prefer Microsoft's official driver instead? (`pdo_sqlsrv`)

Bigger build, needs Microsoft's package repo, but it is the
vendor-supported path:

```dockerfile
ARG BASE_IMAGE=docker.io/library/php:8.2-apache
FROM ${BASE_IMAGE}
RUN set -eux; \
    apt-get update && apt-get install -y --no-install-recommends gnupg2 curl apt-transport-https unixodbc-dev; \
    curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft.gpg; \
    . /etc/os-release; \
    echo "deb [signed-by=/usr/share/keyrings/microsoft.gpg] https://packages.microsoft.com/debian/${VERSION_ID}/prod ${VERSION_CODENAME} main" \
        > /etc/apt/sources.list.d/mssql-release.list; \
    apt-get update; ACCEPT_EULA=Y apt-get install -y msodbcsql18; \
    pecl install sqlsrv pdo_sqlsrv; \
    docker-php-ext-enable sqlsrv pdo_sqlsrv; \
    rm -rf /var/lib/apt/lists/*
```

## Bare-metal / VM installs (no containers)

- **Windows (IIS/Apache):** download Microsoft's *PHP drivers for SQL
  Server*, drop `php_pdo_sqlsrv_*.dll` into the PHP `ext` dir, add
  `extension=pdo_sqlsrv` to php.ini, restart the web server.
- **Debian/Ubuntu:** `apt install php-sybase` (provides pdo_dblib), restart PHP-FPM/Apache.
- **RHEL/Fedora (mutable):** `dnf install php-pdo freetds`, plus the
  `pdo_dblib` extension from your PHP stream, or use Remi's repo.

## After the driver is in

Everything else happens on the Go-Kart Labor page as an admin: enter the
server/database/username/password (stored encrypted), add your go-kart
department filters to the two pre-filled queries, **Test connection**, done.
Use a read-only SQL login if possible — the app refuses to run anything but
a single SELECT, but least-privilege is cheaper than trust.

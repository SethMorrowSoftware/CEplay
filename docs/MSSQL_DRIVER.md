# Installing the MSSQL PHP driver (Go-Kart Labor report)

The Go-Kart Labor page queries the CenterEdge MSSQL database directly, which
needs one PHP extension the stock runtime doesn't ship: `pdo_dblib`
(FreeTDS), `pdo_sqlsrv` (Microsoft), or `pdo_odbc`. The page's **Test
connection** button tells you whether one is present.

## This repo's FCOS install (setup-fcos.sh) — automatic

`setup-fcos.sh` handles the driver end-to-end. On the server:

```bash
cd /var/persist/pause-groups-src
sudo git pull
sudo bash setup-fcos.sh          # safe to re-run; won't touch your database
sudo systemctl restart pause-groups-fpm
```

During step 4 the script now:
1. pulls the stock `php:8.3-fpm` image as always;
2. builds a thin overlay on top of it from `deploy/Containerfile.mssql`
   (FreeTDS / `pdo_dblib` — a couple of MB, no external repos);
3. **saves the built image to `/var/persist/pause-groups/php-fpm-mssql.tar`**
   — this matters because podman's image cache does NOT survive FCOS's
   automatic OS rebuilds (Mon/Tue 2:30 AM). The `pause-groups-fpm` unit
   reloads the image from that tar after a rebuild, no internet needed,
   driver intact;
4. points the `pause-groups-fpm` service at the overlay image.

If the overlay build fails (typically: no route to the Debian package
mirrors), the script says so and falls back to the stock image — everything
except the Go-Kart Labor report keeps working. Fix the issue and re-run.

Verify after the restart:

```bash
sudo podman exec pause-groups-fpm php -m | grep -i pdo_dblib
```

Then open the Go-Kart Labor page and hit **Test connection** — it should
report `Connected via dblib` with today's sales and labor figures.

Network note: the PHP-FPM container runs with `--network host`, so it
reaches the SQL box (TCP 1433) exactly like the VM itself does. If the test
times out, check that SQL Server allows remote TCP connections and that
1433 is open on ITS firewall — nothing on the FCOS side needs opening.

## Other containerized setups (generic)

1. Find your app image: `podman ps --format '{{.Names}}  {{.Image}}'`
2. Build the overlay:

   ```bash
   podman build -f deploy/Containerfile.mssql \
       --build-arg BASE_IMAGE=<image from step 1> \
       -t ceplay-app:mssql .
   ```

3. Point your unit/compose at `ceplay-app:mssql` and restart.
4. If your host wipes the image cache on updates (immutable OSes), persist
   the image with `podman save`/`podman load` the way `setup-fcos.sh` does.

`deploy/Containerfile.mssql` handles both Debian- and Alpine-based official
`php:*` images. If your app image is NOT derived from
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

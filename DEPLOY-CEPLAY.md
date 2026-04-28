# Deploying pause-groups beside Grafana on FCOS (`/ceplay` + subdomain)

This runbook is for your exact scenario:

- Fedora CoreOS VM already running Nginx reverse proxies
- Grafana already available at `http(s)://SERVER_IP/`
- You want pause-groups at `http(s)://SERVER_IP/ceplay` without breaking existing routes
- You also want `https://ceplay.thecastlefuncenter.com`

> Keep your existing `server {}` blocks. Do **not** replace them. Add only the `location` blocks shown below.

## If you are already mid-migration and need recovery now

Use this fast rescue sequence to get back to a good state **without breaking existing sites**:

1. In `/var/persist/nginx.conf`, temporarily remove/comment only the `ceplay` `listen 443 ssl` server block if it references cert files that do not exist yet.
2. Keep the existing Grafana/Claw/default vhosts unchanged.
3. Keep a `ceplay` port-80 block with `/.well-known/acme-challenge/` mapped to `/var/www/html`.
4. Run `nginx -t` and reload.
5. Run Certbot for `ceplay.thecastlefuncenter.com`.
6. Confirm cert files exist under `/etc/letsencrypt/live/ceplay.thecastlefuncenter.com/` inside the Nginx container.
7. Re-add the ceplay HTTPS block and run `nginx -t` + reload again.

If `nginx -t` fails at any step, stop and fix before reloading.

## 1) Install app files and PHP-FPM service

Follow `INSTALL-FCOS.md` through setup script execution, but **do not** add the generated standalone `server {}` block.

```bash
cd /var/persist
sudo git clone https://github.com/morroware/pause-groups.git pause-groups-src
cd /var/persist/pause-groups-src
sudo bash setup-fcos.sh
```

Expected runtime endpoints after script:

- App files: `/var/persist/pause-groups/`
- PHP-FPM listener: `127.0.0.1:9000`
- systemd service: `pause-groups-fpm`

## 2) Mount the app under `/ceplay` in your existing Nginx server block

Open your active Nginx config (`/var/persist/nginx.conf`) and add the following inside the **same** `server {}` block that currently serves `SERVER_IP`.

```nginx
# --- pause-groups at /ceplay ---
location = /ceplay {
    return 302 /ceplay/;
}

# Block direct access to sensitive paths
location ~ ^/ceplay/(data|lib|\.env|config\.php|cron.*\.php|run_action\.php|fresh_install\.php|AUDIT\.md|README\.md) {
    deny all;
    return 404;
}

# Static assets
location /ceplay/public/ {
    alias /var/persist/pause-groups/public/;
    expires 1h;
    add_header Cache-Control "public, immutable";
}

# Front controller: map /ceplay/* to index.php
location /ceplay/ {
    alias /var/persist/pause-groups/;
    try_files $uri $uri/ /ceplay/index.php?$query_string;
}

# PHP handling for /ceplay/*.php
location ~ ^/ceplay/(.+\.php)$ {
    alias /var/persist/pause-groups/$1;
    include fastcgi_params;
    fastcgi_pass 127.0.0.1:9000;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME /var/persist/pause-groups/$1;
    fastcgi_param REMOTE_ADDR $remote_addr;

    # Tell app it is mounted under /ceplay
    fastcgi_param SCRIPT_NAME /ceplay/$1;
    fastcgi_param REQUEST_URI $request_uri;
}
```

Why this is safe for Grafana/reverse proxies:

- It only matches `/ceplay` paths.
- Existing `/`, `/grafana`, and other proxy locations remain unchanged.
- No additional `listen` or `server_name` blocks are introduced, so no vhost precedence changes.

Then validate and reload:

```bash
sudo podman exec systemd-nginx nginx -t
sudo podman exec systemd-nginx nginx -s reload
```

## 3) Update app base URL assumptions

Because pause-groups is now under a subpath, set your external access URL to include `/ceplay` in user bookmarks and operational docs:

- `http://SERVER_IP/ceplay/`
- or `https://ceplay.thecastlefuncenter.com/` (after DNS + TLS below)

## 4) Verify nothing broke

Run these checks after reload:

```bash
# Existing root app (Grafana) should still respond
curl -I http://SERVER_IP/

# pause-groups health under subpath
curl -s http://SERVER_IP/ceplay/api/health

# Optional: verify Grafana path/proxy if you use one
curl -I http://SERVER_IP/grafana
```

If `/ceplay/api/health` returns JSON and Grafana still works, your coexistence config is correct.

## 5) DNS setup for `ceplay.thecastlefuncenter.com`

At your DNS provider:

1. Create an **A record**:
   - Name/Host: `ceplay`
   - Type: `A`
   - Value: your public server IP
   - TTL: 300 (or provider default)
2. If you already have an apex `A` record for `thecastlefuncenter.com`, this does **not** affect it.
3. Wait for propagation (usually a few minutes to 1 hour).

Check propagation:

```bash
dig +short ceplay.thecastlefuncenter.com A
```

## 6) TLS certificate for subdomain (safe order of operations)

Use this exact order to avoid downtime:

1. Keep `ceplay` on HTTP first (with an ACME challenge location).
2. Issue the certificate.
3. Add the HTTPS `server {}` block that references the new cert files.
4. Test and reload.

Do **not** add a `ssl_certificate /etc/letsencrypt/live/ceplay...` reference until those files exist, or `nginx -t` will fail.

### 6.1 HTTP block for ACME challenge

```nginx
server {
    listen 80;
    server_name ceplay.thecastlefuncenter.com;

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/html;
        default_type "text/plain";
        try_files $uri =404;
        allow all;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}
```

This explicit ACME location prevents challenge requests from being blocked by stricter regex locations (for example `location ~ /\.` deny rules).

### 6.2 Issue cert for `ceplay.thecastlefuncenter.com`

```bash
sudo /usr/bin/podman run -it --rm \
  -v /var/persist/letsencrypt:/etc/letsencrypt:z \
  -v /var/persist/webroot:/var/www/html:z \
  docker.io/certbot/certbot:latest certonly \
  --webroot --webroot-path /var/www/html \
  -v -d ceplay.thecastlefuncenter.com
```

Confirm files now exist:

```bash
sudo podman exec systemd-nginx sh -lc 'ls -l /etc/letsencrypt/live/ceplay.thecastlefuncenter.com'
```

### 6.3 Add HTTPS server block after cert exists

```nginx
server {
    listen 443 ssl;
    http2 on;
    server_name ceplay.thecastlefuncenter.com;

    ssl_certificate     /etc/letsencrypt/live/ceplay.thecastlefuncenter.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/ceplay.thecastlefuncenter.com/privkey.pem;

    # same /ceplay location blocks as above, or host directly at /
}
```

If your current Nginx container already terminates TLS for other sites, keep using the same certificate automation flow already in place (do not introduce a competing ACME method).

Then always test/reload:

```bash
sudo podman exec systemd-nginx nginx -t
sudo podman exec systemd-nginx nginx -s reload
```

### 6.4 Fast diagnosis for common failures

- `cannot load certificate "/etc/letsencrypt/live/ceplay.../fullchain.pem"`
  - Cause: HTTPS block added before cert exists.
  - Fix: remove/comment ceplay 443 block, issue cert, then re-add block.

- `Certbot ... Invalid response ... 403` for `/.well-known/acme-challenge/...`
  - Cause: ACME path blocked by Nginx location rules.
  - Fix: ensure the `location ^~ /.well-known/acme-challenge/ { ... }` block above is present in the `ceplay` port-80 server.

## 7) Rollback plan (fast)

If anything goes wrong:

1. Remove only the `/ceplay` location blocks you added.
2. Re-test Nginx config.
3. Reload Nginx.

```bash
sudo podman exec systemd-nginx nginx -t
sudo podman exec systemd-nginx nginx -s reload
```

Grafana and existing proxies will return to prior behavior immediately.

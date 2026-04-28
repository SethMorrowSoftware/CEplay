# Installing pause-groups on Fedora CoreOS

This guide covers a complete installation on the FCOS web server VM, step by step.
Every command is shown exactly as you should type it.

---

## Before you start

### What you need on hand

- **SSH access** to the FCOS VM (via Tailscale or the local network)
- **Your CenterEdge API credentials** — Base URL, username, and password.
  You can skip this during the installer and enter them later through the web UI,
  but having them ready saves a second trip.
- **A hostname or IP address** for the app. This is what you'll type into a browser
  to reach it. It can be:
  - The server's internal IP address (e.g. `192.168.1.50`)
  - A hostname on your internal DNS (e.g. `pause.castle.local`)
  - A public domain name if this will be internet-facing

---

## Understanding this server first

### There is no traditional "web root" on this machine

On a standard Linux server you'd put PHP files in `/var/www/html` and Apache or
Nginx would serve them directly. **This server works differently.** Here's what's
actually running:

```
┌─────────────────────────────────────────────────────────┐
│  Fedora CoreOS (immutable OS — you can't apt/dnf install)
│
│  ┌──────────────────┐  ┌───────────────┐  ┌──────────┐
│  │  Nginx container  │  │ Grafana ctnr  │  │Tailscale │
│  │  port 80 / 443   │  │  port 3000    │  │  ctnr    │
│  └────────┬─────────┘  └───────────────┘  └──────────┘
│           │ proxies PHP requests
│  ┌────────▼─────────┐
│  │ PHP-FPM container │  ← this is what we're adding
│  │  port 9000       │
│  └──────────────────┘
│
│  /var/persist/          ← the only directory that survives rebuilds
│    nginx.conf           ← Nginx's config file (we'll edit this)
│    webroot/             ← static files for other sites (we don't use this)
│    letsencrypt/         ← SSL certificates
│    pause-groups/        ← where this app will live (created by setup script)
└─────────────────────────────────────────────────────────┘
```

**Key points:**

- `/var/persist/webroot` is for **static HTML/CSS/JS files only**. PHP does not work there.
  This app is PHP, so it does **not** go in the webroot.
- This app's files will live at `/var/persist/pause-groups/` — a new folder that
  the setup script creates.
- PHP will run as its own separate container (added by the setup script) alongside
  the existing Nginx, Grafana, and Tailscale containers.
- Nginx is already running. The setup script does **not** restart or modify it —
  you'll add one new `server {}` block to its config file yourself, so you stay in
  control and can't accidentally break existing sites.

### The OS rebuilds itself automatically

FCOS applies OS updates on a schedule (currently Mon/Tues at 2:30 AM) by replacing
the entire OS image and rebooting. After a rebuild, everything in `/var/persist`
is exactly as you left it — the app files, database, config, and the new PHP-FPM
systemd service will all survive automatically.

---

## Part 1 — Get the code onto the server

### Step 1.1 — SSH into the FCOS VM

From your Windows machine, open a terminal and connect via Tailscale or the local
network:

```
ssh core@<server-ip-or-hostname>
```

> The default FCOS admin user is `core`. If your admin configured a different
> username, use that instead.

Once connected, your prompt should look something like:

```
[core@webserver ~]$
```

### Step 1.2 — Check that Git is available

FCOS does not install many tools by default. Verify git is present:

```bash
git --version
```

If you see `git version 2.x.x` you're good. If it says "command not found":

```bash
# Run git inside a temporary container — no install needed
alias git='podman run --rm -it -v "$PWD:$PWD" -w "$PWD" docker.io/alpine/git:latest'
git --version
```

### Step 1.3 — Clone the repository into /var/persist

The code needs to land somewhere under `/var/persist` so it survives if the OS
rebuilds while you're working on this. Clone it to a staging folder:

```bash
cd /var/persist
sudo git clone https://github.com/morroware/pause-groups.git pause-groups-src
```

> If the repo is private or on a different host, replace the URL with yours.
> If you already have the code as a zip, see the note at the end of this section.

Confirm the files are there:

```bash
ls /var/persist/pause-groups-src/
```

You should see: `setup-fcos.sh`, `index.php`, `config.php`, `install.php`, etc.

> **Already have the code as a zip?**
> Copy it from Windows with SCP:
> ```
> scp pause-groups.zip core@<server-ip>:/var/persist/
> ```
> Then on the server:
> ```bash
> cd /var/persist
> sudo unzip pause-groups.zip -d pause-groups-src
> ```

---

## Part 2 — Run the setup script

The setup script handles all the technical work: copying files, generating an
encryption key, pulling the PHP container image, running the installer, creating
systemd services, and generating the Nginx config block for you to add.

### Step 2.1 — Make the script executable

```bash
sudo chmod +x /var/persist/pause-groups-src/setup-fcos.sh
```

### Step 2.2 — Run the setup script

```bash
cd /var/persist/pause-groups-src
sudo bash setup-fcos.sh
```

The script will run through 12 steps with explanations at each one.
Here's what to expect and what to do at each interactive point:

---

#### Pre-flight checks (automatic)

The script checks for Podman, `/var/persist`, internet access, and OpenSSL before
doing anything. If any check fails, it will tell you exactly what's wrong and how
to fix it. Fix the issue and re-run — the script is safe to re-run.

---

#### Steps 1–4 (automatic, no input needed)

- Copies app files to `/var/persist/pause-groups/`
- Creates the data directory
- Generates a random encryption key (saved to `/var/persist/pause-groups/.env`)
- Pulls the PHP-FPM container image from Docker Hub

> **The image pull (step 4) takes 2–5 minutes** on first run — the image is
> about 450 MB. You'll see download progress bars. This is normal.

---

#### Step 5 — The installer prompts

Before the installer launches, the script prints a preview of every question.
**Press Enter** when prompted to launch it.

The installer will ask:

| Question | What to enter |
|----------|--------------|
| **Username** | Your admin login name. Press Enter to accept `admin`, or type your own. |
| **Display name** | Your name as shown in the app. Press Enter to accept `Administrator`. |
| **Password** | At least 8 characters. You'll type it twice. It won't show on screen. |
| **Timezone** | e.g. `America/New_York`. Press Enter to accept the default. A full list is at [en.wikipedia.org/wiki/List_of_tz_database_time_zones](https://en.wikipedia.org/wiki/List_of_tz_database_time_zones) |
| **Configure CenterEdge API now?** | Type `y` if you have your credentials ready. Type `n` to skip and configure through the web UI after login — either is fine. |

If you type `y` for CenterEdge:

| Question | What to enter |
|----------|--------------|
| **API Base URL** | The URL of your CenterEdge Card System API, e.g. `https://your-centeredge-server/api` |
| **API Username** | Your CenterEdge API username |
| **API Password** | Your CenterEdge API password (won't show on screen) |
| **API Key** | Optional. Press Enter to skip if you don't have one. |

> **Tip:** The installer will print some `chown` and `crontab` instructions at the
> end. **Ignore those** — the setup script handles both automatically in later steps.

---

#### Steps 6–11 (automatic, no input needed)

- Fixes file ownership for the PHP-FPM container
- Creates and starts the `pause-groups-fpm` systemd service
- Creates systemd timers for the watchdog (every minute) and daily cron (00:05)
- Removes `fresh_install.php` for security
- Generates the Nginx server block and saves it to a file
- Verifies all services started correctly

---

#### At the end of the script

The script prints:
1. A status table showing which services are running
2. The Nginx config block you need to add (also saved to a file)
3. A checklist of remaining manual steps

The remaining manual steps are all covered in Part 3 below.

---

## Part 3 — Add the Nginx server block

This is the only part that requires careful manual editing. The setup script
generates exactly what needs to be added but does not modify Nginx automatically,
because Nginx is already serving other sites and an automated edit could break them.

### Step 3.1 — Review the generated block

The setup script saved the Nginx block to a file. Read it:

```bash
cat /var/persist/pause-groups-nginx.conf.snippet
```

You'll see a `server { }` block with comments explaining each section.
Take a moment to read through it. You don't need to change anything except
possibly the `server_name` line (see step 3.3).

### Step 3.2 — Open the Nginx config

```bash
sudo nano /var/persist/nginx.conf
```

This opens the config file in the `nano` text editor.

**nano basics:**
- Arrow keys move the cursor
- `Ctrl+End` jumps to the bottom of the file
- `Ctrl+X` exits (it will ask to save)
- `Y` then `Enter` saves

### Step 3.3 — Find where to add the new block

Press `Ctrl+End` to jump to the end of the file.

You're looking for the **last** closing `}` that belongs to a `server { }` block.
The new block goes **inside** the existing `http { ... }` block, right before that `http` block's final closing `}`.

It should look something like this when you're done:

```nginx
http {
    # ... existing config ...

    # ... existing server block for other sites ...
    server {
        ...
    }

    # pause-groups — PHP arcade game scheduling app
    server {
        listen 80;
        server_name _;
        ...
    }
}
```

### Step 3.4 — Paste in the new block

In a second terminal window (or copy it from the snippet output), you need to type
or paste the contents of the snippet. The easiest way:

**Open a second SSH session** and print the snippet:
```bash
cat /var/persist/pause-groups-nginx.conf.snippet
```

Then switch back to the first session (where `nano` is open) and type/paste the
block just before the final closing `}` of the `http` block.

> **Tip:** If your SSH client supports it (PuTTY, Windows Terminal, etc.), you can
> right-click to paste copied text into the terminal.

### Step 3.5 — Set the server_name

Inside the new block, find this line:

```nginx
server_name _;
```

Replace `_` with your server's actual IP address or hostname:

```nginx
server_name 192.168.1.50;
```

or:

```nginx
server_name pause.castle.local;
```

> `_` is a catch-all that matches any hostname. It works, but if another site
> on this server also uses `_`, there will be a conflict. Using a specific IP or
> hostname is safer and clearer.

### Step 3.6 — Save the file

Press `Ctrl+X`, then `Y`, then `Enter` to save and exit nano.

### Step 3.7 — Test the Nginx config

**Always test before reloading.** This command checks for syntax errors without
affecting anything that's currently running:

```bash
sudo podman exec systemd-nginx nginx -t
```

A successful test looks like:
```
nginx: the configuration file /etc/nginx/nginx.conf syntax is ok
nginx: configuration file /etc/nginx/nginx.conf test is successful
```

If you see errors, read them carefully — they usually tell you the exact line
number of the problem. Open nano again, fix the issue, and re-test.

> **Common mistakes:**
> - Missing semicolons at the end of a line
> - Missing closing `}` brace
> - Accidentally deleting part of an existing block while editing

### Step 3.8 — Reload Nginx

Only do this after the test passes:

```bash
sudo podman exec systemd-nginx nginx -s reload
```

This applies the new config without dropping any existing connections. The existing
Grafana proxy, Tailscale, and any other sites continue working without interruption.

---

## Part 4 — Verify and access the app

### Step 4.1 — Check all services are running

```bash
sudo systemctl status pause-groups-fpm
```

You should see `Active: active (running)`. Press `q` to exit.

```bash
sudo systemctl list-timers 'pause-groups*'
```

You should see two timers listed with their next trigger times.

### Step 4.2 — Check the health endpoint

```bash
curl -s http://localhost/api/health | python3 -m json.tool
```

> Replace `localhost` with your server's IP or hostname if needed.

A healthy response looks like:

```json
{
    "status": "ok",
    "database": "ok",
    "cron_heartbeat": "ok",
    "watchdog_heartbeat": "degraded"
}
```

> `watchdog_heartbeat` will show as `degraded` until the watchdog timer has fired
> at least once (within the first minute). Refresh after a minute and it should
> show `ok`.

### Step 4.3 — Open the app in a browser

Navigate to:

```
http://<your-server-ip-or-hostname>/
```

You should see the login screen. Log in with the username and password you set
during the installer.

### Step 4.4 — Configure CenterEdge API (if you skipped it during install)

1. Log in to the app
2. Click **Settings** in the navigation bar
3. Fill in **API Base URL**, **Username**, **Password**, and optionally **API Key**
4. Click **Save**
5. Click **Test Connection** — it should connect and report the number of games found

### Step 4.5 — Sync your game list

After the API is configured:

1. Go to **Dashboard** or **Groups**
2. Click **Sync Now** to pull the current game list from CenterEdge

---

## Part 5 — What to do after an OS rebuild

FCOS rebuilds itself automatically on the update schedule (Mon/Tues 2:30 AM).
Here's what happens and what (if anything) you need to do:

| Item | Survives rebuild? | Notes |
|------|--------------------|-------|
| App files (`/var/persist/pause-groups/`) | ✅ Yes | On the persist VHDX |
| Database | ✅ Yes | Inside app files |
| Encryption key (`.env`) | ✅ Yes | Inside app files |
| Nginx config (`/var/persist/nginx.conf`) | ✅ Yes | On the persist VHDX |
| PHP-FPM systemd service | ✅ Yes | In `/etc/systemd/system/` which is overlaid, not replaced |
| Systemd timers | ✅ Yes | Same — in `/etc/systemd/system/` |
| PHP-FPM container image | ⚠️ May need re-pull | Podman image cache is in `/var/lib/containers` which is NOT persisted. The service will auto-pull on next start. |

**In practice:** After an automatic rebuild, all services should come back up on
their own within a minute or two. The PHP-FPM container image will be re-pulled
from Docker Hub on first start if it's not cached, which takes a few minutes.

If services don't come back after a rebuild:

```bash
sudo systemctl start pause-groups-fpm
sudo systemctl start pause-groups-watchdog.timer
sudo systemctl start pause-groups-daily.timer
```

---

## Quick reference — useful commands

### Check service health

```bash
# Is PHP-FPM running?
sudo systemctl status pause-groups-fpm

# Are the timers scheduled?
sudo systemctl list-timers 'pause-groups*'

# App health endpoint
curl -s http://localhost/api/health
```

### View logs

```bash
# PHP-FPM container logs (startup errors, PHP errors)
sudo journalctl -eu pause-groups-fpm

# Watchdog log (runs every minute — shows action execution)
sudo tail -f /var/persist/pause-groups/data/watchdog.log

# Daily cron log (runs at 00:05 — shows game sync and planning)
sudo tail -f /var/persist/pause-groups/data/cron.log
```

### Manually trigger background tasks

```bash
# Run the watchdog right now (instead of waiting for the next minute)
sudo systemctl start pause-groups-watchdog

# Run the daily planner right now (syncs games, re-plans today)
sudo systemctl start pause-groups-daily
```

### Restart services

```bash
# Restart PHP-FPM (e.g. after updating app files)
sudo systemctl restart pause-groups-fpm

# Reload Nginx config (after editing /var/persist/nginx.conf)
sudo podman exec systemd-nginx nginx -s reload
```

### Check what containers are running

```bash
sudo podman ps -a
```

You should see (among others): `systemd-nginx`, `systemd-grafana`, `systemd-tailscale`,
and — after this installation — `pause-groups-fpm`.

### Start fresh (wipe database, keep app files and encryption key)

```bash
cd /var/persist/pause-groups-src
sudo bash setup-fcos.sh --reset
```

This deletes the database and walks you through the installer again.
Your Nginx config is not affected.

---

## Troubleshooting

### The app shows a blank page or 502 Bad Gateway

PHP-FPM is probably not running. Check:

```bash
sudo systemctl status pause-groups-fpm
sudo journalctl -eu pause-groups-fpm --no-pager -n 50
```

Restart it:

```bash
sudo systemctl restart pause-groups-fpm
```

### The login page loads but login fails

Check that the data directory has the right ownership:

```bash
ls -la /var/persist/pause-groups/data/
```

Files should be owned by `33` (uid of `www-data` inside the PHP container).
If they're owned by `root`, fix it:

```bash
sudo chown -R 33:33 /var/persist/pause-groups/data/
```

### Nginx returns 404 for everything

The Nginx block might not have been added correctly. Test the config:

```bash
sudo podman exec systemd-nginx nginx -t
```

Read any errors, fix the config, test again, then reload:

```bash
sudo nano /var/persist/nginx.conf
sudo podman exec systemd-nginx nginx -t
sudo podman exec systemd-nginx nginx -s reload
```

### Nginx says `"server" directive is not allowed here`

This means the pause-groups `server { ... }` block was pasted **outside** the `http { ... }` block.
Move the entire pause-groups server block so it is **inside** `http { ... }`, just before that block's final `}`.

Then test and reload again:

```bash
sudo podman exec systemd-nginx nginx -t
sudo podman exec systemd-nginx nginx -s reload
```

### The watchdog heartbeat stays "degraded" in /api/health

The watchdog timer runs every minute. Wait 2 minutes, then check again.
If it's still degraded:

```bash
sudo systemctl status pause-groups-watchdog.timer
sudo systemctl start pause-groups-watchdog
sudo tail -20 /var/persist/pause-groups/data/watchdog.log
```

### Games are not syncing from CenterEdge

1. Go to **Settings** in the app and click **Test Connection**
2. If the test fails, check the API Base URL format and credentials
3. Check that the VM can reach the CenterEdge server:
   ```bash
   curl -v https://your-centeredge-server/api/
   ```

### I need to update the app to a newer version

```bash
# Pull the latest code
cd /var/persist/pause-groups-src
sudo git pull

# Re-run the setup script (safe to re-run — won't wipe your database)
sudo bash setup-fcos.sh

# Restart PHP-FPM to pick up the new files
sudo systemctl restart pause-groups-fpm
```

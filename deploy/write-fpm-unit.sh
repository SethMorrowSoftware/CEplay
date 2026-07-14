#!/usr/bin/env bash
# =============================================================================
#  write-fpm-unit.sh — single source of truth for pause-groups-fpm.service
# =============================================================================
#
#  Called by BOTH setup-fcos.sh (fresh install) and update.sh (routine
#  updates), so the unit file can never drift between the two paths.
#
#  USAGE:
#    write-fpm-unit.sh <env_file> <install_dir> <runtime_image> <base_image> [load_tar]
#
#    runtime_image  image the service runs (stock php:fpm, or the MSSQL overlay)
#    base_image     the stock php:fpm image the overlay was built FROM — recorded
#                   as a comment so update.sh can detect the operator's chosen
#                   PHP version even after the unit points at the overlay tag
#    load_tar       optional; when given, an ExecStartPre reloads runtime_image
#                   from this tar whenever podman's cache lost it (FCOS OS
#                   rebuilds wipe /var/lib/containers)
#
#  Writes /etc/systemd/system/pause-groups-fpm.service and daemon-reloads.
#  Does NOT enable/start/restart — callers decide when.

set -euo pipefail

ENV_FILE="${1:?usage: write-fpm-unit.sh <env_file> <install_dir> <runtime_image> <base_image> [load_tar]}"
INSTALL_DIR="${2:?missing install_dir}"
RUNTIME_IMAGE="${3:?missing runtime_image}"
BASE_IMAGE="${4:?missing base_image}"
LOAD_TAR="${5:-}"

# PG_UNIT_FILE override exists for tests; production callers leave it unset.
UNIT_FILE="${PG_UNIT_FILE:-/etc/systemd/system/pause-groups-fpm.service}"

IMAGE_LOAD_PRE=""
if [[ -n "$LOAD_TAR" ]]; then
    IMAGE_LOAD_PRE="ExecStartPre=-/usr/bin/bash -c '/usr/bin/podman image exists ${RUNTIME_IMAGE} || /usr/bin/podman load -i ${LOAD_TAR}'"
fi

cat > "$UNIT_FILE" <<UNIT
[Unit]
Description=pause-groups PHP-FPM
Documentation=https://hub.docker.com/_/php
# BASE_PHP_IMAGE=${BASE_IMAGE}
# Start after network is up so Podman can resolve image references
After=network-online.target
Wants=network-online.target

[Service]
# Restart the container automatically if it exits for any reason
Restart=always
RestartSec=10s

# Remove any stale container from a previous run before starting
ExecStartPre=-/usr/bin/podman rm -f pause-groups-fpm
# Podman's image cache is wiped by FCOS OS rebuilds; reload the runtime image
# from /var/persist when it's gone (no-op while it's cached).
${IMAGE_LOAD_PRE}

# Start PHP-FPM in the foreground (-F) inside the php:fpm container.
# --network host   shares the host network namespace so Nginx can reach port 9000
# --env-file       loads PG_ENCRYPTION_KEY for encrypting stored API credentials
# -v               mounts the app at the same path as on the host
# -w               sets the working directory (must match app dir for DB_PATH to resolve)
ExecStart=/usr/bin/podman run --rm \\
    --name pause-groups-fpm \\
    --network host \\
    --env-file ${ENV_FILE} \\
    -v ${INSTALL_DIR}:${INSTALL_DIR}:z \\
    -w ${INSTALL_DIR} \\
    ${RUNTIME_IMAGE}

ExecStop=/usr/bin/podman stop --time 10 pause-groups-fpm

[Install]
WantedBy=multi-user.target
UNIT

# The unit file is already written at this point; a daemon-reload hiccup
# shouldn't abort a caller mid-update. If systemd is genuinely broken the
# caller's restart will fail loudly right after.
if command -v systemctl >/dev/null 2>&1; then
    systemctl daemon-reload || echo "WARN: systemctl daemon-reload failed — run it manually" >&2
fi
echo "wrote ${UNIT_FILE} (image: ${RUNTIME_IMAGE}$( [[ -n "$LOAD_TAR" ]] && echo ", reload tar: ${LOAD_TAR}" || true ))"

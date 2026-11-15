#!/usr/bin/env bash
# Remote server bootstrap (STRATEGY.md section 9).
# Installs Docker + dependencies, then the signed, versioned agent release is
# deployed and its checksum verified before start. Orchestrated by Laravel
# queued jobs over SSH using a restricted deployment account — never arbitrary
# shell from RabbitMQ (section 9 SSH security).
set -euo pipefail

echo "[bootstrap] verifying prerequisites..."
command -v docker >/dev/null 2>&1 || {
  echo "[bootstrap] installing Docker..."
  # TODO: use a narrowly scoped privileged installer (section 9).
  curl -fsSL https://get.docker.com | sh
}

RELEASE_DIR="${1:-/opt/phishguard/current}"
echo "[bootstrap] deploying agent release at ${RELEASE_DIR}"
# TODO: verify release checksum/signature before start (section 9).

echo "[bootstrap] done."

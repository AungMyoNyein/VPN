#!/usr/bin/env bash
# Build sing-box libbox.aar for Android embedding.
# Pinned upstream: SagerNet/sing-box v1.11.4
# License: GPL-3.0 (sing-box) — review compatibility before distribution.
set -euo pipefail

SING_BOX_VERSION="${SING_BOX_VERSION:-v1.11.4}"
OUT_DIR="$(cd "$(dirname "$0")/../mobile/android/app/libs" && pwd)"
WORK_DIR="${WORK_DIR:-/tmp/sing-box-build}"

: "${ANDROID_HOME:?ANDROID_HOME must point to Android SDK}"
: "${ANDROID_NDK_HOME:?ANDROID_NDK_HOME must point to Android NDK}"

if ! command -v java >/dev/null 2>&1; then
  echo "Java JDK is required (openjdk-17-jdk-headless recommended)" >&2
  exit 1
fi

if ! command -v go >/dev/null 2>&1; then
  echo "Go 1.23+ is required to build libbox" >&2
  exit 1
fi

mkdir -p "$WORK_DIR"
if [[ ! -d "$WORK_DIR/.git" ]]; then
  git clone --depth 1 --branch "$SING_BOX_VERSION" https://github.com/SagerNet/sing-box.git "$WORK_DIR"
fi

export PATH="$(go env GOPATH)/bin:$PATH"
go install -v github.com/sagernet/gomobile/cmd/gomobile@v0.1.12
go install -v github.com/sagernet/gomobile/cmd/gobind@v0.1.12

cd "$WORK_DIR"
go run ./cmd/internal/build_libbox -target android

cp -f "$WORK_DIR/libbox.aar" "$OUT_DIR/libbox.aar"
echo "Installed $OUT_DIR/libbox.aar ($(du -h "$OUT_DIR/libbox.aar" | awk '{print $1}'))"

#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

APP_NAME="PasteFilezClipboard"
BUNDLE_ID="com.pastefilez.clipboard"
BUILD_DIR="build"
APP_DIR="$BUILD_DIR/$APP_NAME.app"
EXEC_DIR="$APP_DIR/Contents/MacOS"
RES_DIR="$APP_DIR/Contents/Resources"

rm -rf "$BUILD_DIR"
mkdir -p "$EXEC_DIR" "$RES_DIR"

swiftc -O \
  -framework Cocoa \
  -framework AppKit \
  -o "$EXEC_DIR/$APP_NAME" \
  main.swift

cp Info.plist "$APP_DIR/Contents/Info.plist"

cat > "$APP_DIR/Contents/PkgInfo" <<'EOF'
APPL????
EOF

echo
echo "App built at: $APP_DIR"
echo
echo "Install:"
echo "  cp -R $APP_DIR ~/Applications/"
echo
echo "Configure (one time):"
echo "  defaults write $BUNDLE_ID server https://your-domain.tld"
echo "  defaults write $BUNDLE_ID api_token YOUR_API_TOKEN"
echo
echo "Install LaunchAgent (auto-start at login):"
echo "  cp com.pastefilez.clipboard.plist ~/Library/LaunchAgents/"
echo "  launchctl load -w ~/Library/LaunchAgents/com.pastefilez.clipboard.plist"
echo
echo "Run manually:"
echo "  open $APP_DIR"

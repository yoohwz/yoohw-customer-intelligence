#!/usr/bin/env bash
set -euo pipefail

SOURCE_INPUT="${1:-}"
DESTINATION_INPUT="${2:-}"

if [[ -z "$SOURCE_INPUT" || -z "$DESTINATION_INPUT" ]]; then
  echo "usage: bash scripts/stage-distribution.sh <source> <fresh-destination>" >&2
  exit 2
fi

SOURCE="$(cd "$SOURCE_INPUT" && pwd -P)"

if [[ ! -f "$SOURCE/yoohw-customer-intelligence.php" || ! -f "$SOURCE/.distignore" ]]; then
  echo "source is not a Customer Intelligence repository root" >&2
  exit 1
fi

if ! REPO_ROOT="$(git -C "$SOURCE" rev-parse --show-toplevel 2>/dev/null)"; then
  echo "source must be a Git worktree" >&2
  exit 1
fi
REPO_ROOT="$(cd "$REPO_ROOT" && pwd -P)"
if [[ "$REPO_ROOT" != "$SOURCE" ]]; then
  echo "source must be the repository root" >&2
  exit 1
fi

if [[ -e "$DESTINATION_INPUT" || -L "$DESTINATION_INPUT" ]]; then
  echo "destination must not already exist" >&2
  exit 1
fi

DESTINATION_PARENT="$(dirname "$DESTINATION_INPUT")"
DESTINATION_NAME="$(basename "$DESTINATION_INPUT")"
mkdir -p "$DESTINATION_PARENT"
DESTINATION_PARENT="$(cd "$DESTINATION_PARENT" && pwd -P)"
DESTINATION="$DESTINATION_PARENT/$DESTINATION_NAME"

case "$DESTINATION/" in
  "$SOURCE/"|"$SOURCE/"*)
    echo "destination must be outside the source tree" >&2
    exit 1
    ;;
esac

if [[ -e "$DESTINATION" || -L "$DESTINATION" ]]; then
  echo "destination must not already exist" >&2
  exit 1
fi

mkdir -- "$DESTINATION"

TRACKED="$(mktemp)"
MANIFEST="$(mktemp)"
trap 'rm -f "$TRACKED" "$MANIFEST"' EXIT

git -C "$SOURCE" ls-files -z > "$TRACKED"

while IFS= read -r -d '' path; do
  case "$path" in
    .DS_Store|*/.DS_Store|.env|*/.env|.env.*|*/.env.*|*.[Ll][Oo][Gg]|*.[Zz][Ii][Pp]|*.[Rr][Aa][Rr]|*.7[Zz]|*.[Tt][Aa][Rr]|*.[Tt][Gg][Zz]|*.[Gg][Zz]|*.[Bb][Zz]2|*.[Xx][Zz])
      continue
      ;;
  esac

  case "$path" in
    admin/*|includes/*|assets/*|templates/*|languages/*|yoohw-customer-intelligence.php|readme.txt|changelog.txt|uninstall.php|license.txt|third-party-licenses.txt)
      printf '%s\0' "$path" >> "$MANIFEST"
      ;;
  esac
done < "$TRACKED"

if [[ ! -s "$MANIFEST" ]]; then
  echo "distribution allowlist selected no tracked product files" >&2
  exit 1
fi

rsync -a \
  --from0 \
  --files-from="$MANIFEST" \
  --exclude-from="$SOURCE/.distignore" \
  "$SOURCE/" "$DESTINATION/"

for forbidden in .git .github tests docs scripts AGENTS.md .distignore composer.json composer.lock phpunit.xml phpunit.xml.dist .phpunit.result.cache; do
  if [[ -e "$DESTINATION/$forbidden" ]]; then
    echo "forbidden development artifact in distribution: $forbidden" >&2
    exit 1
  fi
done

if find "$DESTINATION" -type l -print -quit | grep -q .; then
  echo "distribution must not contain symbolic links" >&2
  exit 1
fi

if find "$DESTINATION" -type f \( \
  -name '.DS_Store' -o \
  -name '.env' -o \
  -name '.env.*' -o \
  -iname '*.log' -o \
  -iname '*.zip' -o \
  -iname '*.rar' -o \
  -iname '*.7z' -o \
  -iname '*.tar' -o \
  -iname '*.tgz' -o \
  -iname '*.gz' -o \
  -iname '*.bz2' -o \
  -iname '*.xz' \
\) -print -quit | grep -q .; then
  echo "distribution must not contain local artifacts or nested archives" >&2
  exit 1
fi

for required in yoohw-customer-intelligence.php readme.txt uninstall.php admin includes assets templates languages; do
  if [[ ! -e "$DESTINATION/$required" ]]; then
    echo "distribution is missing required path: $required" >&2
    exit 1
  fi
done

printf 'distribution-ok files=%s\n' "$(find "$DESTINATION" -type f | wc -l | tr -d ' ')"

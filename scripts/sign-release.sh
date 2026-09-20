#!/usr/bin/env bash
# Build and sign locally using the installed Nextcloud's official occ command.
set -euo pipefail
umask 077

fail() { printf '%s\n' "$*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || fail 'Run this release helper as root.'
release_source=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
nextcloud_root=${NEXTCLOUD_ROOT:-/var/www/nextcloud}
[[ -f "$nextcloud_root/occ" && -f "$nextcloud_root/config/config.php" ]] || fail 'Set NEXTCLOUD_ROOT to your Nextcloud installation.'
for executable in git php node npm openssl sudo tar sha256sum; do
    command -v "$executable" >/dev/null || fail "Missing command: $executable"
done

# Optional explicit paths: bash scripts/sign-release.sh /path/musiccurator.key /path/musiccurator.crt
private_key=${1:-}
certificate=${2:-}
if [[ -z $private_key ]]; then
    mapfile -d '' -t candidates < <(find /root -type d \( -name node_modules -o -name .git \) -prune -o -type f -name musiccurator.key -print0)
    [[ ${#candidates[@]} -eq 1 ]] || fail 'Pass the unique musiccurator.key and musiccurator.crt paths as the two arguments.'
    private_key=${candidates[0]}
fi
if [[ -z $certificate ]]; then
    mapfile -d '' -t candidates < <(find /root -type d \( -name node_modules -o -name .git \) -prune -o -type f -name musiccurator.crt -print0)
    [[ ${#candidates[@]} -eq 1 ]] || fail 'Pass the unique musiccurator.key and musiccurator.crt paths as the two arguments.'
    certificate=${candidates[0]}
fi
[[ -r $private_key && -r $certificate ]] || fail 'Signing key or certificate is not readable.'
openssl x509 -in "$certificate" -checkend 0 -noout
openssl verify -CAfile "$nextcloud_root/resources/codesigning/root.crt" "$certificate"
php -r '$c=openssl_x509_parse(file_get_contents($argv[1])); if (($c["subject"]["CN"] ?? "") !== "musiccurator") {fwrite(STDERR,"Certificate is not for musiccurator\n"); exit(1);}' "$certificate"
key_public=$(openssl pkey -in "$private_key" -pubout | sha256sum)
cert_public=$(openssl x509 -in "$certificate" -pubkey -noout | sha256sum)
[[ $key_public == "$cert_public" ]] || fail 'Certificate and private key do not match.'

cd -- "$release_source"
[[ -z $(git status --porcelain --untracked-files=no) ]] || fail 'Commit or stash tracked changes before signing a release.'
version=$(node -p 'JSON.parse(require("fs").readFileSync("package.json", "utf8")).version')
[[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail 'Expected a numeric release version.'
xml_version=$(php -r 'echo (string)simplexml_load_file($argv[1])->version;' appinfo/info.xml)
[[ $version == "$xml_version" ]] || fail 'package.json and info.xml versions differ.'
mkdir -p dist
archive="$release_source/dist/musiccurator-$version.tar.gz"
[[ ! -e $archive ]] || fail "Archive already exists: $archive. Move it aside before rebuilding."
npm ci
npm run lint
npm run stylelint
npm audit --audit-level=moderate
npm run build
[[ -s js/musiccurator-main.mjs && -s css/musiccurator-main.css ]] || fail 'Compiled frontend assets are missing.'

signing_dir=$(mktemp -d /tmp/musiccurator-sign.XXXXXXXX)
trap 'rm -rf -- "$signing_dir"' EXIT
mkdir "$signing_dir/musiccurator"
# Explicit runtime allowlist: no Git metadata, dev tools, node_modules or credentials.
for directory in appinfo lib templates img js css; do
    cp -R -- "$release_source/$directory" "$signing_dir/musiccurator/"
done
if [[ -d l10n ]]; then cp -R l10n "$signing_dir/musiccurator/"; fi
cp -- LICENSE README.md CHANGELOG.md "$signing_dir/musiccurator/"
rm -f -- "$signing_dir/musiccurator/appinfo/signature.json"
install -m 600 -- "$private_key" "$signing_dir/musiccurator.key"
install -m 600 -- "$certificate" "$signing_dir/musiccurator.crt"
nextcloud_uid=$(stat -c %u "$nextcloud_root/config/config.php")
chown -R "$nextcloud_uid" "$signing_dir"
find "$signing_dir/musiccurator" -type d -exec chmod 755 {} +
find "$signing_dir/musiccurator" -type f -exec chmod 644 {} +
sudo -u "#$nextcloud_uid" php "$nextcloud_root/occ" integrity:sign-app \
    --privateKey="$signing_dir/musiccurator.key" \
    --certificate="$signing_dir/musiccurator.crt" \
    --path="$signing_dir/musiccurator"
[[ -s "$signing_dir/musiccurator/appinfo/signature.json" ]] || fail 'No signature was generated.'
chmod 644 "$signing_dir/musiccurator/appinfo/signature.json"
tar --owner=0 --group=0 -C "$signing_dir" -czf "$archive" musiccurator
(cd -- "$release_source/dist" && sha256sum "musiccurator-$version.tar.gz") > "$archive.sha256"
printf 'Signed archive: %s\nChecksum: %s.sha256\n' "$archive" "$archive"

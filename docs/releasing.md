# Build, sign and publish

Use a clean checkout of the release commit on the release server. Requirements:
Node 24, npm 11, PHP with SimpleXML/OpenSSL, OpenSSL, sudo and an installed
Nextcloud. Never commit or upload the private signing key.

Run as root from the checkout:

```bash
bash scripts/sign-release.sh
```

The helper finds a unique musiccurator.key and musiccurator.crt below /root.
If there are multiple matches or the files are elsewhere, specify both paths:

```bash
bash scripts/sign-release.sh /path/musiccurator.key /path/musiccurator.crt
```

NEXTCLOUD_ROOT defaults to /var/www/nextcloud and can be overridden. The script
checks certificate trust, expiry, app scope and key match; runs the frontend
checks and build; and copies only runtime files into a temporary app directory.
It invokes Nextcloud's official occ integrity:sign-app as the owner of the
Nextcloud configuration file. A temporary key copy is restricted to that user
and removed with the staging directory on exit. The private key is outside the
app directory and is never included in the archive. Original key files remain
untouched.

The output is dist/musiccurator-1.0.0.tar.gz and its SHA-256 checksum. The archive
contains a top-level musiccurator directory and appinfo/signature.json. Do not
modify its contents after signing. An existing archive is never overwritten.

After signing, publish from the same checkout with an authenticated GitHub CLI:

```bash
gh release create v1.0.0 \
  dist/musiccurator-1.0.0.tar.gz \
  dist/musiccurator-1.0.0.tar.gz.sha256 \
  --repo Happyfeet01/musiccurator \
  --target "$(git rev-parse HEAD)" \
  --title 'MusicCurator 1.0.0' \
  --notes-file docs/releases/1.0.0.md
```

Alternatively, create v1.0.0 in the GitHub releases UI at the same commit and
upload the two output files. App Store submission is a separate step. The old
automatic unsigned beta workflow on master has been removed so it cannot
publish a different unsigned package alongside this release.

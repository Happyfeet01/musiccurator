# Signiertes Release für MusicCurator

Die Nextcloud-App-ID lautet `musiccurator`. Hinterlege in **Happyfeet01/musiccurator → Settings → Secrets and variables → Actions** die beiden Repository-Secrets:

- `APP_PRIVATE_KEY` enthält den privaten PEM-Schlüssel von musiccurator.
- `APP_PUBLIC_CRT` enthält das von Nextcloud für musiccurator ausgestellte Zertifikat.

Erhöhe die Version in `appinfo/info.xml` und `package.json` und lege `docs/releases/<Version>.md` an. Pull Requests testen den Build ohne Signierschlüssel.

Ein Merge einer neuen Release-Version nach `main` startet das signierte GitHub-Release automatisch. Der Release-Job prüft die App-Signatur mit `occ` und die separate SHA-512-Archivsignatur mit OpenSSL, bevor das GitHub-Release öffentlich wird. Für den Nextcloud App Store reichst du anschließend die Download-URL des Archivs und den Inhalt der zugehörigen `.signature` ein.

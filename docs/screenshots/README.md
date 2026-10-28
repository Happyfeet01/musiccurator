# App Store screenshot

`musiccurator-library.png` is a browser capture of the actual production Vue
bundle built from this repository, displayed in a lightweight Nextcloud-style
host shell. It uses fictional artists and tracks; no personal library or account
data is shown. The image itself is labelled “Demo library · sample data”.

To reproduce: run `npm ci && npm run build`, serve the repository root locally,
then open `/docs/screenshots/demo.html` at 1600 × 1100 and capture the viewport.
The demo fixture only mocks read APIs in that document; it is not loaded by the
app or the release. The screenshot link in `appinfo/info.xml` is pinned to a
commit so branch deletion cannot break the App Store image.

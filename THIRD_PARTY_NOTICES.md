# Third-party notices

## QR Code encoder

Open LMS bundles QR encoding code derived from **Kazuhiko Arase’s QRCode for JavaScript**, whose source header states the MIT license, using the vendored implementation distributed with **qrcode-terminal 0.12.0**. The qrcode-terminal package is distributed under Apache License 2.0. The Apache 2.0 text is retained at `licenses/qrcode-terminal-Apache-2.0.txt`; the original Kazuhiko Arase copyright/MIT notice is retained inside `assets/js/vendor/openlms-qrcode.js`.

This bundled local copy is used only to render TOTP enrollment QR codes in the browser. The TOTP secret is not sent to a third-party QR service.

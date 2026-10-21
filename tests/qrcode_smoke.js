global.window = global;
require('../assets/js/vendor/openlms-qrcode-modules-1.js');
require('../assets/js/vendor/openlms-qrcode-modules-2.js');
require('../assets/js/vendor/openlms-qrcode-modules-3.js');
require('../assets/js/vendor/openlms-qrcode-modules-4.js');
require('../assets/js/vendor/openlms-qrcode.js');
const svg = global.OpenLMSQRCode.svg('otpauth://totp/Open%20LMS%3Atest%40example.test?secret=JBSWY3DPEHPK3PXP&issuer=Open%20LMS');
if (!svg.startsWith('<svg') || !svg.includes('<rect')) throw new Error('QR SVG was not generated');
console.log('PASS: local bundled MFA QR encoder generated SVG');

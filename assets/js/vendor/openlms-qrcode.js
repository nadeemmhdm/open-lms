/* Open LMS bundled QR modules. Third-party license: licenses/qrcode-terminal-Apache-2.0.txt */
(function(S,global){'use strict';var __modules=S.modules,__cache=S.cache;
function __require(id){ if(__cache[id]) return __cache[id].exports; if(!__modules[id]) throw new Error('QR module not found: '+id); var m={exports:{}}; __cache[id]=m; __modules[id](m,m.exports,__require); return m.exports; }
var QRCode=__require('QRCode'); var Levels=__require('QRErrorCorrectLevel');
function svg(text){
  var qr=new QRCode(-1, Levels.M); qr.addData(String(text)); qr.make();
  var count=qr.getModuleCount(), quiet=4, size=count+quiet*2;
  var out='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '+size+' '+size+'" role="img" aria-label="Authenticator QR code" shape-rendering="crispEdges">';
  out+='<rect width="100%" height="100%" fill="white"/>';
  out+='<g fill="black">';
  for(var r=0;r<count;r++){ for(var c=0;c<count;c++){ if(qr.isDark(r,c)) out+='<rect x="'+(c+quiet)+'" y="'+(r+quiet)+'" width="1" height="1"/>'; }}
  return out+'</g></svg>';
}
global.OpenLMSQRCode={svg:svg};
})(window.OpenLMSQRState,window);

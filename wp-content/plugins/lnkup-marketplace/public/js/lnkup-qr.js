/**
 * Local QR codes on top of the vendored qrcode-generator (MIT). Nothing is sent to a QR service.
 *
 * window.LNKupQR.svg(text, size)      -> SVG markup string ('' on failure)
 * window.LNKupQR.dataUrl(text, size)  -> data:image/svg+xml URI for <img src>
 * window.LNKupQR.pngBlob(text, size)  -> Promise<Blob> (PNG download)
 */
(function () {
  'use strict';

  function build(text) {
    if (typeof window.qrcode !== 'function' || !text) return null;
    try {
      var qr = window.qrcode(0, 'M');
      qr.addData(String(text).slice(0, 1000));
      qr.make();
      return qr;
    } catch (e) {
      return null;
    }
  }

  function svg(text, size, margin) {
    var qr = build(text);
    if (!qr) return '';
    var count = qr.getModuleCount();
    var m = margin == null ? 4 : margin;
    var total = count + m * 2;
    var d = '';
    for (var r = 0; r < count; r++) {
      for (var c = 0; c < count; c++) {
        if (qr.isDark(r, c)) d += 'M' + (c + m) + ' ' + (r + m) + 'h1v1h-1z';
      }
    }
    var px = Math.max(64, size || 240);
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + total + ' ' + total + '" width="' + px + '" height="' + px + '" shape-rendering="crispEdges"><rect width="' + total + '" height="' + total + '" fill="#fff"/><path fill="#0b0b0c" d="' + d + '"/></svg>';
  }

  function dataUrl(text, size, margin) {
    var markup = svg(text, size, margin);
    return markup ? 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(markup) : '';
  }

  function pngBlob(text, size) {
    return new Promise(function (resolve, reject) {
      var src = dataUrl(text, size || 1024);
      if (!src) return reject(new Error('QR unavailable'));
      var img = new Image();
      img.onload = function () {
        var canvas = document.createElement('canvas');
        canvas.width = canvas.height = size || 1024;
        var ctx = canvas.getContext('2d');
        ctx.imageSmoothingEnabled = false;
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(function (blob) { blob ? resolve(blob) : reject(new Error('PNG export failed')); }, 'image/png');
      };
      img.onerror = function () { reject(new Error('QR render failed')); };
      img.src = src;
    });
  }

  window.LNKupQR = { svg: svg, dataUrl: dataUrl, pngBlob: pngBlob };
})();

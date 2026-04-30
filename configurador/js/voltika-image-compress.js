/**
 * Voltika image compressor.
 *
 * Accepts a File (or Blob) and returns a Promise<File>. Images larger than
 * 500 KB are re-encoded to JPEG at quality 0.82, capped at 1600 px on the
 * longest side. EXIF orientation is honored via createImageBitmap when
 * available. Non-images and small files pass through untouched. On ANY
 * error, the original file is returned — this function never throws.
 *
 * Why: phone cameras produce 5–15 MB photos (HEIC even larger). PHP's
 * upload_max_filesize rejects them before our code runs. Compressing on
 * the client removes that failure mode entirely and also makes uploads
 * much faster on mobile networks.
 */
(function(){
  var MAX_DIM       = 1600;
  var QUALITY       = 0.82;
  var SKIP_IF_UNDER = 500 * 1024;

  function compress(file){
    return new Promise(function(resolve){
      if (!file || !file.type || file.type.indexOf('image/') !== 0) return resolve(file);
      if (file.size && file.size < SKIP_IF_UNDER) return resolve(file);

      var outName = (file.name || 'photo').replace(/\.[^.]+$/, '') + '.jpg';

      function finish(blob){
        if (!blob) return resolve(file);
        try {
          var out = new File([blob], outName, { type: 'image/jpeg', lastModified: Date.now() });
          resolve(out.size < file.size ? out : file);
        } catch (e) {
          // Older mobile Safari — no File constructor. Fake it on the Blob.
          blob.name = outName; blob.lastModified = Date.now();
          resolve(blob.size < file.size ? blob : file);
        }
      }

      function scaleAndEncode(src){
        try {
          var w = src.width  || src.naturalWidth;
          var h = src.height || src.naturalHeight;
          if (!w || !h) return resolve(file);
          if (w > h && w > MAX_DIM)      { h = Math.round(h * MAX_DIM / w); w = MAX_DIM; }
          else if (h >= w && h > MAX_DIM){ w = Math.round(w * MAX_DIM / h); h = MAX_DIM; }

          var canvas = document.createElement('canvas');
          canvas.width = w; canvas.height = h;
          canvas.getContext('2d').drawImage(src, 0, 0, w, h);

          if (canvas.toBlob) {
            canvas.toBlob(finish, 'image/jpeg', QUALITY);
          } else {
            // Very old browser fallback
            var dataURL = canvas.toDataURL('image/jpeg', QUALITY);
            var bin = atob(dataURL.split(',')[1]);
            var buf = new Uint8Array(bin.length);
            for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
            finish(new Blob([buf], { type: 'image/jpeg' }));
          }
        } catch (e) { resolve(file); }
      }

      // createImageBitmap auto-applies EXIF orientation — ideal for phone photos
      if (typeof createImageBitmap === 'function') {
        try {
          createImageBitmap(file, { imageOrientation: 'from-image' })
            .then(scaleAndEncode)
            .catch(imageLoad);
          return;
        } catch (e) { /* imageOrientation option unsupported, fall through */ }
      }

      function imageLoad(){
        var url = URL.createObjectURL(file);
        var img = new Image();
        img.onload  = function(){ URL.revokeObjectURL(url); scaleAndEncode(img); };
        img.onerror = function(){ URL.revokeObjectURL(url); resolve(file); };
        img.src = url;
      }
      imageLoad();
    });
  }

  window.voltikaCompressImage = compress;
})();

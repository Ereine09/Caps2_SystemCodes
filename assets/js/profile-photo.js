/*
 * Profile photo picker shared by the customer and rider profile pages.
 * ProfilePhoto.toSquare(file) -> Promise<data URI>: center-crops the photo to a square and
 * scales it to 400x400 JPEG, so uploads stay small. The server re-checks it
 * (app/helpers/profile_photo_helper.php).
 */
(function (w) {
    'use strict';
    function toSquare(file, size) {
        size = size || 400;
        return new Promise(function (resolve, reject) {
            if (!file || !/^image\//.test(file.type)) { reject(new Error('Please choose a photo.')); return; }
            var reader = new FileReader();
            reader.onerror = function () { reject(new Error('Could not read the photo.')); };
            reader.onload = function () {
                var img = new Image();
                img.onerror = function () { reject(new Error('Could not read the photo.')); };
                img.onload = function () {
                    var side = Math.min(img.width, img.height);
                    var c = document.createElement('canvas');
                    c.width = c.height = Math.min(size, side);
                    c.getContext('2d').drawImage(img, (img.width - side) / 2, (img.height - side) / 2, side, side, 0, 0, c.width, c.height);
                    resolve(c.toDataURL('image/jpeg', 0.85));
                };
                img.src = reader.result;
            };
            reader.readAsDataURL(file);
        });
    }
    w.ProfilePhoto = { toSquare: toSquare };
})(window);

document.addEventListener('DOMContentLoaded', function () {
    var mainImg = document.querySelector('.product-main-img');
    var thumbs  = document.querySelectorAll('.product-thumb');
    if (!mainImg || !thumbs.length) return;

    thumbs.forEach(function (thumb) {
        thumb.addEventListener('click', function () {
            mainImg.src = thumb.src;
            mainImg.alt = thumb.alt;
        });
    });
});

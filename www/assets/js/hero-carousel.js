document.addEventListener('DOMContentLoaded', function () {
    var carousel = document.querySelector('[data-hero-carousel]');
    if (!carousel) return;

    var slides = carousel.querySelectorAll('[data-hero-slide]');
    var dots   = carousel.querySelectorAll('[data-hero-dot]');
    var prev   = carousel.querySelector('[data-hero-prev]');
    var next   = carousel.querySelector('[data-hero-next]');
    if (slides.length < 2) return;

    var current  = 0;
    var timer    = null;
    var INTERVAL = 6000;

    function show(index) {
        current = (index + slides.length) % slides.length;
        slides.forEach(function (slide, i) { slide.classList.toggle('active', i === current); });
        dots.forEach(function (dot, i) { dot.classList.toggle('active', i === current); });
    }

    function startAutoplay() {
        stopAutoplay();
        timer = setInterval(function () { show(current + 1); }, INTERVAL);
    }

    function stopAutoplay() {
        if (timer) clearInterval(timer);
        timer = null;
    }

    if (prev) prev.addEventListener('click', function () { show(current - 1); startAutoplay(); });
    if (next) next.addEventListener('click', function () { show(current + 1); startAutoplay(); });
    dots.forEach(function (dot, i) {
        dot.addEventListener('click', function () { show(i); startAutoplay(); });
    });

    carousel.addEventListener('mouseenter', stopAutoplay);
    carousel.addEventListener('mouseleave', startAutoplay);
    carousel.addEventListener('focusin', stopAutoplay);
    carousel.addEventListener('focusout', startAutoplay);

    startAutoplay();
});

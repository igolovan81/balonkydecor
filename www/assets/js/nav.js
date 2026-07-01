document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.querySelector('.nav-toggle');
    var headerInner = document.querySelector('.header-inner');
    if (!toggle || !headerInner) return;
    toggle.addEventListener('click', function () {
        var isOpen = headerInner.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
});

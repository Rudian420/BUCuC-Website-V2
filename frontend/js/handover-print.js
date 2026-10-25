// Open the print dialog once the page (and the logo) has loaded
window.addEventListener('load', function () {
    setTimeout(function () {
        window.print();
    }, 300);
});

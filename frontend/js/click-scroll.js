// jquery-click-scroll
// Refined for BUCuC with custom section IDs

var sectionArray = [
    'section_1',       // Home
    'section_2',       // About
    'advisors_section', // Advisors
    'section_3',       // Panel
    'section_4',       // Sb Members
    'section_5',       // Past Events
    'very-bottom'      // Contact
];

$.each(sectionArray, function (index, value) {

    $(document).scroll(function () {
        var target = $('#' + value);
        if (target.length) {
            var offsetSection = target.offset().top - 90; // Adjust for header height
            var docScroll = $(document).scrollTop();
            var docScroll1 = docScroll + 1;

            if (docScroll1 >= offsetSection) {
                $('.navbar-nav .nav-item .nav-link').removeClass('active');
                $('.navbar-nav .nav-item .nav-link').addClass('inactive');
                $('.navbar-nav .nav-item .nav-link').eq(index).addClass('active');
                $('.navbar-nav .nav-item .nav-link').eq(index).removeClass('inactive');
            }
        }
    });

    $('.click-scroll').eq(index).click(function (e) {
        var target = $('#' + value);
        if (target.length) {
            var offsetClick = target.offset().top - 90;
            e.preventDefault();
            $('html, body').animate({
                'scrollTop': offsetClick
            }, 300)
        }
    });

});

$(document).ready(function () {
    $('.navbar-nav .nav-item .nav-link').addClass('inactive');
    $('.navbar-nav .nav-item .nav-link').eq(0).addClass('active');
    $('.navbar-nav .nav-item .nav-link').eq(0).removeClass('inactive');
});
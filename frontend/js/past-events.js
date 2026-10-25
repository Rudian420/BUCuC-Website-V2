// Past Events animations: AOS, particles, countup, and parallax
(function () {
    // Initialize AOS
    if (window.AOS) {
        AOS.init({
            duration: 1000,
            easing: 'ease-in-out',
            once: true,
            mirror: false
        });
    }

    // Create floating particles
    function createParticles() {
        const particlesBg = document.getElementById('particlesBg');
        if (!particlesBg) return;
        const particleCount = 25;

        for (let i = 0; i < particleCount; i++) {
            const particle = document.createElement('div');
            particle.className = 'particle';
            particle.style.left = Math.random() * 100 + '%';
            particle.style.top = Math.random() * 100 + '%';
            particle.style.animationDelay = Math.random() * 4 + 's';
            particle.style.animationDuration = (Math.random() * 2 + 2) + 's';
            particlesBg.appendChild(particle);
        }
    }

    // Loading screen - faster transition
    window.addEventListener('load', function () {
        const loadingOverlay = document.getElementById('loadingOverlay');
        if (loadingOverlay) {
            setTimeout(() => {
                loadingOverlay.style.opacity = '0';
                setTimeout(() => {
                    loadingOverlay.style.display = 'none';
                }, 300);
            }, 800);
        }
    });

    // Enhanced counting animation function
    function initCountingAnimation() {
        const statNumbers = document.querySelectorAll('.stat-number');
        if (statNumbers.length === 0) return;

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const target = entry.target;
                    if (!target.classList.contains('animated')) {
                        animateNumber(target);
                        target.classList.add('animated');
                    }
                }
            });
        }, {
            threshold: 0.3,
            rootMargin: '0px 0px -30px 0px'
        });

        statNumbers.forEach(stat => {
            observer.observe(stat);
        });
    }

    function animateNumber(element) {
        const finalText = element.textContent;
        const finalNumber = parseInt(finalText.replace(/\D/g, ''), 10);
        if (isNaN(finalNumber)) return;
        const hasPlus = finalText.includes('+');

        let currentNumber = 0;
        const duration = 1800;
        const steps = 60;
        const increment = finalNumber / steps;
        const stepDuration = duration / steps;

        element.style.transform = 'scale(1.05)';
        setTimeout(() => {
            element.style.transform = 'scale(1)';
        }, 80);

        const timer = setInterval(() => {
            currentNumber += increment;

            if (currentNumber >= finalNumber) {
                currentNumber = finalNumber;
                clearInterval(timer);
                element.style.transform = 'scale(1.02)';
                setTimeout(() => {
                    element.style.transform = 'scale(1)';
                }, 150);
            }

            const displayText = hasPlus ? Math.floor(currentNumber) + '+' : Math.floor(currentNumber);
            element.textContent = displayText;
        }, stepDuration);
    }

    // Smooth scrolling for anchor links & init
    function init() {
        setTimeout(createParticles, 100);

        if (window.location.hash) {
            const targetElement = document.querySelector(window.location.hash);
            if (targetElement) {
                setTimeout(() => {
                    targetElement.scrollIntoView({ behavior: 'smooth' });
                }, 100);
            }
        }

        initCountingAnimation();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Parallax effect for particles
    let ticking = false;
    window.addEventListener('scroll', function () {
        if (!ticking) {
            requestAnimationFrame(function () {
                const scrolled = window.pageYOffset;
                const particles = document.querySelectorAll('.particle');
                particles.forEach((particle, index) => {
                    const speed = 0.3 + (index % 2) * 0.1;
                    particle.style.transform = `translateY(${scrolled * speed}px)`;
                });
                ticking = false;
            });
            ticking = true;
        }
    });
})();

// Floating particles and login interaction for admin-login.php
(function () {
  function createParticles() {
    const particlesContainer = document.getElementById("particles");
    if (!particlesContainer) return;
    const particleCount = 20;

    for (let i = 0; i < particleCount; i++) {
      const particle = document.createElement("div");
      particle.className = "particle";
      particle.style.left = Math.random() * 100 + "%";
      particle.style.animationDelay = Math.random() * 6 + "s";
      particle.style.animationDuration = Math.random() * 3 + 3 + "s";
      particlesContainer.appendChild(particle);
    }
  }

  document.querySelectorAll(".form-control").forEach((input) => {
    input.addEventListener("focus", function () {
      if (this.parentElement) this.parentElement.style.transform = "scale(1.02)";
    });

    input.addEventListener("blur", function () {
      if (this.parentElement) this.parentElement.style.transform = "scale(1)";
    });
  });

  const togglePassword = document.getElementById('togglePassword');
  const passwordInput = document.getElementById('adminPassword');

  if (togglePassword && passwordInput) {
    togglePassword.addEventListener('click', function () {
      const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
      passwordInput.setAttribute('type', type);
      this.classList.toggle('fa-eye');
      this.classList.toggle('fa-eye-slash');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener("DOMContentLoaded", createParticles);
  } else {
    createParticles();
  }
})();

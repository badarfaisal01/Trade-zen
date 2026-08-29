(() => {
  'use strict';
  document.querySelectorAll('.modal-sign__input-toggle').forEach(button => {
    button.addEventListener('click', () => {
      const input = button.parentElement?.querySelector('input[type="password"],input[type="text"]');
      if (!input) return;
      input.type = input.type === 'password' ? 'text' : 'password';
      const show = button.querySelector('.password-icon-show');
      const hide = button.querySelector('.password-icon-hide');
      if (show) show.style.display = input.type === 'password' ? 'none' : 'block';
      if (hide) hide.style.display = input.type === 'password' ? 'block' : 'none';
    });
  });

  const menuButton = document.querySelector('#mobile-trigger, .header__mobile-button');
  const mobileMenu = document.querySelector('.header__mobile-container');
  menuButton?.addEventListener('click', event => {
    event.preventDefault();
    mobileMenu?.classList.toggle('active');
    menuButton.classList.toggle('open');
  });

  document.querySelectorAll('.language, .language-mobile').forEach(button => {
    button.addEventListener('click', event => {
      event.preventDefault();
      const target = button.classList.contains('language-mobile') ? '#countriesMenu-mobile' : '#countriesMenu';
      document.querySelector(target)?.classList.toggle('active');
    });
  });

  document.querySelectorAll('a[href="#"]').forEach(link => link.addEventListener('click', event => event.preventDefault()));
})();

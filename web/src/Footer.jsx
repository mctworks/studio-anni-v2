import React from 'react';
import mct630Logo from './assets/mct-630-logo.svg'; 

const Footer = () => {
  const year = new Date().getFullYear();

  return (
    <footer className="site-footer">
      <span className="site-footer__copyright">© {year} Studio Anni LLC</span>

      <span className="site-footer__dev">
        <img src={mct630Logo} alt="MCT630" className="site-footer__logo" />
        Developed by{' '}
        <a
          href="https://mct630.com"
          target="_blank"
          rel="noopener noreferrer"
          className="site-footer__link"
        >
          MCT630
        </a>
      </span>
    </footer>
  );
};

export default Footer;
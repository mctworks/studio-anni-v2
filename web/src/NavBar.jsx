import React, { useState, useEffect, useCallback } from 'react';
import { stack as StackMenu } from 'react-burger-menu';
import ThemeToggle from './ThemeToggle';
import shopbagDark from './assets/shopbag-icon-dark-mode.svg';
import shopbagLight from './assets/shopbag-icon-light-mode.svg';

const NavBar = () => {
  const [isDarkMode, setIsDarkMode] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);

  // READ ONCE ON MOUNT. DO NOT OBSERVE.
  useEffect(() => {
    setIsDarkMode(document.documentElement.classList.contains('dark-mode'));
  }, []);

  const handleMenuStateChange = useCallback(({ isOpen }) => {
    setMenuOpen(isOpen);
  }, []);

  return (
    <>
      <nav className="global-nav">
        <div className="nav-right-controls">
          <img 
            src={isDarkMode ? shopbagDark : shopbagLight} 
            alt="Cart" 
            className="nav-icon" 
          />
          <div className="nav-theme-wrapper"><ThemeToggle /></div>
          <div className="burger-trigger" onClick={() => setMenuOpen((open) => !open)}>
            <div className="burger-bar"></div>
            <div className="burger-bar"></div>
            <div className="burger-bar"></div>
          </div>
        </div>
      </nav>

      <StackMenu 
        isOpen={menuOpen} 
        width="100%"
        htmlClassName="menu-open"
        bodyClassName="menu-open"
        onClose={() => setMenuOpen(false)}
        onStateChange={handleMenuStateChange}
        outerContainerId="outer-container"
        pageWrapId="page-wrap"
      >
        <div className="menu-content">
          <a href="/" onClick={() => setMenuOpen(false)}>Home</a>
          <a href="/#" onClick={() => setMenuOpen(false)}>Galleries</a>
          <a href="/#" onClick={() => setMenuOpen(false)}>Commissions</a>
          <a href="/about" onClick={() => setMenuOpen(false)}>About</a>
        </div>
      </StackMenu>
    </>
  );
};

export default NavBar;
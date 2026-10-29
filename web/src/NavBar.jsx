import React, { useState, useEffect, useCallback } from 'react';
import { stack as StackMenu } from 'react-burger-menu';
import { useNavigate } from 'react-router-dom';
import ThemeToggle from './ThemeToggle';
import shopbagDark from './assets/shopbag-icon-dark-mode.svg';
import shopbagLight from './assets/shopbag-icon-light-mode.svg';

const NavBar = () => {
  const navigate = useNavigate();
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
          <button
            type="button"
            className="burger-trigger"
            aria-label={menuOpen ? 'Close navigation menu' : 'Open navigation menu'}
            aria-expanded={menuOpen}
            onClick={() => setMenuOpen((open) => !open)}
          >
            <div className="burger-bar"></div>
            <div className="burger-bar"></div>
            <div className="burger-bar"></div>
          </button>
        </div>
      </nav>

      <StackMenu 
        isOpen={menuOpen} 
        customBurgerIcon={false}
        width="100%"
        htmlClassName="menu-open"
        bodyClassName="menu-open"
        onClose={() => setMenuOpen(false)}
        onStateChange={handleMenuStateChange}
        outerContainerId="outer-container"
        pageWrapId="page-wrap"
      >
        <div className="menu-content">
          <button type="button" onClick={() => { setMenuOpen(false); navigate('/'); }}>Home</button>
          <button type="button" onClick={() => { setMenuOpen(false); navigate('/gallery'); }}>Galleries</button>
          <button type="button" onClick={() => { setMenuOpen(false); navigate('/#'); }}>Commissions</button>
          <button type="button" onClick={() => { setMenuOpen(false); navigate('/about'); }}>About</button>
        </div>
      </StackMenu>
    </>
  );
};

export default NavBar;
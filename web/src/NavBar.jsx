import React, { useState, useCallback } from 'react';
import { stack as StackMenu } from 'react-burger-menu';
import { Link, useNavigate } from 'react-router-dom';
import ThemeToggle from './ThemeToggle';
import { useAuth } from './AuthContext.jsx';
import { useTheme } from './ThemeContext.jsx';
import shopbagDark from './assets/shopbag-icon-dark-mode.svg';
import shopbagLight from './assets/shopbag-icon-light-mode.svg';
import saLogoDark from './assets/sa-logo-v2-dark-mode.svg';
import saLogoLight from './assets/sa-logo-v2.svg';
import archiveIconDark from './assets/archive-icon-dark-mode.svg';
import archiveIconLight from './assets/archive-icon-light-mode.svg';
import galleriesIconDark from './assets/galleries-icon-dark-mode.svg';
import galleriesIconLight from './assets/galleries-icon-light-mode.svg';
import commissionIconDark from './assets/commission-icon-dark-mode.svg';
import commissionIconLight from './assets/commission-icon-light-mode.svg';

const NavBar = () => {
  const navigate = useNavigate();
  const { user, staff, logout } = useAuth();
  const { isDarkMode } = useTheme();
  const [menuOpen, setMenuOpen] = useState(false);

  const handleMenuStateChange = useCallback(({ isOpen }) => {
    setMenuOpen(isOpen);
  }, []);

  return (
    <>
      <nav className="global-nav">
        <div className="nav-left-controls">
          <Link to="/" className="nav-brand" aria-label="Studio Anni home">
            <img src={isDarkMode ? saLogoDark : saLogoLight} alt="Studio Anni" />
          </Link>
          {user ? (
            <Link to="/account" className="nav-account-link">👤{user.username || user.displayName || 'Account'}</Link>
          ) : (
            <Link to="/login" className="nav-account-link">Sign In</Link>
          )}
        </div>
        <div className="nav-right-controls">
          {staff && (
            <button type="button" className="nav-mimosa" onClick={() => navigate('/mimosa')}>MIMOSA</button>
          )}
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
          <button type="button" onClick={() => { setMenuOpen(false); navigate('/gallery'); }}><img src={isDarkMode ? galleriesIconDark : galleriesIconLight} alt="Galleries" /> Galleries</button>
          <button type="button" onClick={() => { setMenuOpen(false); navigate('/archive'); }}><img src={isDarkMode ? archiveIconDark : archiveIconLight} alt="Past Works" /> Past Works</button>
          <button type="button" onClick={() => { setMenuOpen(false); navigate('/commissions'); }}><img src={isDarkMode ? commissionIconDark : commissionIconLight} alt="Commissions" /> Commissions</button>
          <button type="button" onClick={() => { setMenuOpen(false); navigate('/about'); }}>About</button>
          {staff && (
            <button type="button" onClick={() => { setMenuOpen(false); navigate('/mimosa'); }}>MIMOSA</button>
          )}
          {user && !staff && (
            <button type="button" onClick={() => { setMenuOpen(false); navigate('/account'); }}>My Account</button>
          )}
          {user ? (
            <button type="button" onClick={async () => { setMenuOpen(false); await logout(); navigate('/'); }}>Sign Out</button>
          ) : (
            <button type="button" onClick={() => { setMenuOpen(false); navigate('/login'); }}>Sign In</button>
          )}
        </div>
      </StackMenu>
    </>
  );
};

export default NavBar;
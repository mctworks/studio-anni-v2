// web/src/LandingPage.jsx
import { useEffect, useState } from 'react';

const LandingPage = () => {
  const [health, setHealth] = useState('checking');
  const [isDarkMode, setIsDarkMode] = useState(false);

  useEffect(() => {
    // 1. API Health
    fetch('/api/health')
      .then((r) => r.json())
      .then((d) => setHealth(d.status))
      .catch(() => setHealth('API unreachable'));

    // 2. Theme Sync
    const updateTheme = () => {
      setIsDarkMode(document.documentElement.classList.contains('dark-mode'));
    };

    updateTheme();

    // Watch for class changes on <html> tag (triggered by ThemeToggle)
    const observer = new MutationObserver(updateTheme);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

    return () => observer.disconnect();
  }, []);

  return (
    <div className="container under-construction">
      <img
        src={isDarkMode ? '/src/assets/sa-logo-v2-dark-mode.svg' : '/src/assets/sa-logo-v2.svg'}
        width="400"
        alt="Studio Anni Logo"
        className="logo"
      />
      <h1 style={{ textAlign: 'center' }}>Studio Anni Ver. 2.0</h1>
      <p style={{ textAlign: 'center' }}>Construction is UNDERWAY!</p>
      <p style={{ textAlign: 'center' }}>
        API Health: <span style={{ color: health === 'ok' ? 'green' : 'red' }}>{health}</span>
      </p>
    </div>
  );
};

export default LandingPage;
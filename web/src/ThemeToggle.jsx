import React, { useState, useEffect } from 'react';
import darkModeIcon from './assets/dark-mode-icon.svg';
import lightModeIcon from './assets/light-mode-icon.svg';

const ThemeToggle = () => {
  const [mode, setMode] = useState('light');

  useEffect(() => {
    const systemPreference = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    setMode(systemPreference);
    document.documentElement.className = systemPreference === 'dark' ? 'dark-mode' : '';

    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
    const handleChange = (e) => {
      const newMode = e.matches ? 'dark' : 'light';
      setMode(newMode);
      document.documentElement.className = newMode === 'dark' ? 'dark-mode' : '';
    };

    mediaQuery.addListener(handleChange);
    return () => mediaQuery.removeListener(handleChange);
  }, []);

  const toggleMode = () => {
    const newMode = mode === 'light' ? 'dark' : 'light';
    setMode(newMode);
    document.documentElement.className = newMode === 'dark' ? 'dark-mode' : '';
  };

  return (
    <div className="theme-toggle">
      <button onClick={toggleMode} className="theme-toggle-btn">
        <img 
          src={mode === 'light' ? darkModeIcon : lightModeIcon} 
          alt="Toggle Theme" 
          className="theme-icon" 
          width="24"
        />
      </button>
    </div>
  );
};

export default ThemeToggle;
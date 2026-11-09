import { createContext, useContext, useEffect, useMemo, useState } from 'react';

const ThemeContext = createContext(null);
const STORAGE_KEY = 'studio-anni-theme';

const readSavedMode = () => {
  try {
    const savedMode = window.localStorage.getItem(STORAGE_KEY);
    return savedMode === 'dark' || savedMode === 'light' ? savedMode : null;
  } catch {
    return null;
  }
};

const readSystemPreference = () => (
  window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
);

export const ThemeProvider = ({ children }) => {
  const [systemMode, setSystemMode] = useState(readSystemPreference);
  const [savedMode, setSavedMode] = useState(readSavedMode);
  const isDarkMode = (savedMode ?? systemMode) === 'dark';

  useEffect(() => {
    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
    const handleChange = (event) => setSystemMode(event.matches ? 'dark' : 'light');

    if (mediaQuery.addEventListener) mediaQuery.addEventListener('change', handleChange);
    else mediaQuery.addListener(handleChange);

    return () => {
      if (mediaQuery.removeEventListener) mediaQuery.removeEventListener('change', handleChange);
      else mediaQuery.removeListener(handleChange);
    };
  }, []);

  useEffect(() => {
    document.documentElement.classList.toggle('dark-mode', isDarkMode);
  }, [isDarkMode]);

  const toggleTheme = () => {
    const nextMode = isDarkMode ? 'light' : 'dark';
    setSavedMode(nextMode);
    try {
      window.localStorage.setItem(STORAGE_KEY, nextMode);
    } catch {
      // Theme still works for this session when storage is unavailable.
    }
  };

  const value = useMemo(() => ({ isDarkMode, toggleTheme }), [isDarkMode]);

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
};

export const useTheme = () => {
  const theme = useContext(ThemeContext);
  if (!theme) throw new Error('useTheme must be used within a ThemeProvider');
  return theme;
};

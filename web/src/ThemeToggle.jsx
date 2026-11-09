import { useTheme } from './ThemeContext.jsx';
import darkModeIcon from './assets/dark-mode-icon.svg';
import lightModeIcon from './assets/light-mode-icon.svg';

const ThemeToggle = () => {
  const { isDarkMode, toggleTheme } = useTheme();

  return (
    <div className="theme-toggle">
      <button onClick={toggleTheme} className="theme-toggle-btn" aria-label={`Switch to ${isDarkMode ? 'light' : 'dark'} mode`}>
        <img 
          src={isDarkMode ? lightModeIcon : darkModeIcon} 
          alt="Toggle Theme" 
          className="theme-icon" 
          width="24"
        />
      </button>
    </div>
  );
};

export default ThemeToggle;
import { Route, Routes } from 'react-router-dom';
import LandingPage from './LandingPage';
import ThemeToggle from './ThemeToggle';

export default function App() {
  return (
    <>
      <ThemeToggle />
      <Routes>
        <Route path="/" element={<LandingPage />} />
        <Route path="*" element={<div>404 Not Found</div>} />
      </Routes>
    </>
  );
}
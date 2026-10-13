// src/App.jsx
import { Route, Routes } from 'react-router-dom';
import LandingPage from './LandingPage.jsx';

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<LandingPage />} />
      <Route path="*" element={<div>404 Not Found</div>} />
    </Routes>
  );
}
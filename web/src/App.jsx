import { Route, Routes } from 'react-router-dom';
import LandingPage from './LandingPage.jsx';
import GalleryPage from './GalleryPage.jsx';
import PiecePage from './PiecePage.jsx';
import NavBar from './NavBar.jsx';
import Footer from './Footer.jsx';

export default function App() {
  return (
    <div id="outer-container">
      <div id="page-wrap">
        <NavBar />
        <main>
          <Routes>
            <Route path="/" element={<LandingPage />} />
            <Route path="/gallery" element={<GalleryPage />} />
            <Route path="/gallery/:categorySlug" element={<GalleryPage />} />
            <Route path="/pieces/:pieceKey" element={<PiecePage />} />
            <Route path="*" element={<div>404 Not Found</div>} />
          </Routes>
        </main>
        <Footer />
      </div>
    </div>
  );
}
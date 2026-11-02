import { lazy, Suspense, useLayoutEffect } from 'react';
import { Route, Routes, useLocation } from 'react-router-dom';
import LandingPage from './LandingPage.jsx';
import GalleryPage from './GalleryPage.jsx';
import ArchivePage from './ArchivePage.jsx';
import PiecePage from './PiecePage.jsx';
import LoginPage from './LoginPage.jsx';
import NavBar from './NavBar.jsx';
import Footer from './Footer.jsx';
import { AuthProvider } from './AuthContext.jsx';

const Mimosa = lazy(() => import('./mimosa/Mimosa.jsx'));

function ScrollToTop() {
  const { pathname } = useLocation();

  useLayoutEffect(() => {
    window.scrollTo(0, 0);
  }, [pathname]);

  return null;
}

function Site() {
  return (
    <div id="outer-container">
      <ScrollToTop />
      <div id="page-wrap">
        <NavBar />
        <main>
          <Routes>
            <Route path="/" element={<LandingPage />} />
            <Route path="/gallery" element={<GalleryPage />} />
            <Route path="/gallery/:categorySlug" element={<GalleryPage />} />
            <Route path="/archive" element={<ArchivePage />} />
            <Route path="/pieces/:pieceKey" element={<PiecePage />} />
            <Route path="/login" element={<LoginPage />} />
            <Route path="*" element={<div>404 Not Found</div>} />
          </Routes>
        </main>
        <Footer />
      </div>
    </div>
  );
}

export default function App() {
  return (
    <AuthProvider>
      <Routes>
        <Route
          path="/mimosa/*"
          element={
            <Suspense fallback={null}>
              <Mimosa />
            </Suspense>
          }
        />
        <Route path="*" element={<Site />} />
      </Routes>
    </AuthProvider>
  );
}
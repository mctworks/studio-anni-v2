import { lazy, Suspense, useLayoutEffect, useState } from 'react';
import { Route, Routes, useLocation } from 'react-router-dom';
import LandingPage from './LandingPage.jsx';
import GalleryPage from './GalleryPage.jsx';
import ArchivePage from './ArchivePage.jsx';
import PiecePage from './PiecePage.jsx';
import CommissionsPage from './CommissionsPage.jsx';
import AccountPage from './account/AccountPage.jsx';
import PublicProfilePage from './account/PublicProfilePage.jsx';
import LoginPage from './LoginPage.jsx';
import NavBar from './NavBar.jsx';
import Footer from './Footer.jsx';
import { AuthProvider } from './AuthContext.jsx';
import { ThemeProvider } from './ThemeContext.jsx';

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
            <Route path="/commissions" element={<CommissionsPage />} />
            <Route path="/pieces/:pieceKey" element={<PiecePage />} />
            <Route path="/login" element={<LoginPage />} />
            <Route path="/account" element={<AccountPage />} />
            <Route path="/u/:username" element={<PublicProfilePage />} />
            <Route path="*" element={<div>404 Not Found</div>} />
          </Routes>
        </main>
        <Footer />
      </div>
    </div>
  );
}

function PageTransitions() {
  const location = useLocation();
  const [displayLocation, setDisplayLocation] = useState(location);
  const [phase, setPhase] = useState('visible');

  useLayoutEffect(() => {
    if (location.key === displayLocation.key) return undefined;

    if (location.pathname === displayLocation.pathname) {
      setDisplayLocation(location);
      setPhase('visible');
      return undefined;
    }

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      setDisplayLocation(location);
      setPhase('visible');
      return undefined;
    }

    setPhase('exiting');
    const timeout = window.setTimeout(() => {
      setDisplayLocation(location);
      setPhase('entering');
    }, 160);

    return () => window.clearTimeout(timeout);
  }, [location, displayLocation]);

  return (
    <div
      className={`page-transition page-transition--${phase}`}
      aria-busy={phase === 'exiting'}
      onAnimationEnd={(event) => {
        if (event.target === event.currentTarget && event.animationName === 'page-fade-in') {
          setPhase('visible');
        }
      }}
    >
      <Routes location={displayLocation}>
        <Route path="/mimosa/*" element={<Suspense fallback={null}><Mimosa /></Suspense>} />
        <Route path="*" element={<Site />} />
      </Routes>
    </div>
  );
}

export default function App() {
  return (
    <ThemeProvider>
      <AuthProvider>
        <PageTransitions />
      </AuthProvider>
    </ThemeProvider>
  );
}
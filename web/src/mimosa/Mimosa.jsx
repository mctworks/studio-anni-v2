import { useEffect, useState } from 'react';
import { Link, Route, Routes, useLocation } from 'react-router-dom';
import ThemeToggle from '../ThemeToggle.jsx';
import { isStaff, useAuth } from '../AuthContext.jsx';
import './mimosa.css';
import { api, ApiError, setUnauthorizedHandler } from './api.js';
import Login from './Login.jsx';
import PieceList from './PieceList.jsx';
import PieceForm from './PieceForm.jsx';

export default function Mimosa() {
  const auth = useAuth();
  const location = useLocation();
  const [phase, setPhase] = useState('loading');
  const [user, setUser] = useState(null);
  const [meta, setMeta] = useState(null);
  const [codesLeft, setCodesLeft] = useState(location.state?.codesLeft ?? null);

  useEffect(() => {
    const tag = document.createElement('meta');
    tag.name = 'robots';
    tag.content = 'noindex, nofollow';
    document.head.appendChild(tag);
    const title = document.title;
    document.title = 'MIMOSA';

    setUnauthorizedHandler(() => {
      auth.setUser(null);
      setUser(null);
      setPhase('login');
    });

    api
      .get('/me')
      .then((d) => {
        auth.setUser(d.user);
        setUser(d.user);
        setPhase(isStaff(d.user) ? 'ready' : 'off');
      })
      .catch((e) => {
        if (e instanceof ApiError && e.status === 404) setPhase('off');
        else if (e instanceof ApiError && e.status === 401) setPhase('login');
        else setPhase('error');
      });

    return () => {
      setUnauthorizedHandler(null);
      tag.remove();
      document.title = title;
    };
  }, []);

  useEffect(() => {
    if (phase !== 'ready' || meta) return;
    api.get('/meta').then(setMeta).catch(() => setPhase('error'));
  }, [phase, meta]);

  const logout = async () => {
    await auth.logout();
    setUser(null);
    setMeta(null);
    setPhase('login');
  };

  if (phase === 'loading') return <div className="mim-wrap">Loading…</div>;
  if (phase === 'off') return <div>404 Not Found</div>;
  if (phase === 'error') return <div className="mim-wrap mim-error">Could not reach the server.</div>;
  if (phase === 'login') {
    return (
      <Login
        onLogin={(u, left) => {
          auth.setUser(u);
          setUser(u);
          setCodesLeft(left ?? null);
          setPhase(isStaff(u) ? 'ready' : 'off');
        }}
      />
    );
  }

  return (
    <div className="mim-wrap">
      <header className="mim-header">
        <Link to="/mimosa" className="mim-brand">MIMOSA</Link>
        <span>
          {user.displayName} ({user.role})
        </span>
        <button type="button" className="mim-link" onClick={logout}>Sign out</button>
        <ThemeToggle />
      </header>

      {codesLeft !== null && (
        <p className="mim-banner">
          You signed in with a recovery code. {codesLeft} remaining.{' '}
          <button type="button" className="mim-link" onClick={() => setCodesLeft(null)}>Dismiss</button>
        </p>
      )}

      {meta ? (
        <Routes>
          <Route index element={<PieceList meta={meta} />} />
          <Route path="pieces/new" element={<PieceForm meta={meta} />} />
          <Route path="pieces/:id" element={<PieceForm meta={meta} />} />
          <Route path="*" element={<div>404 Not Found</div>} />
        </Routes>
      ) : (
        <p>Loading…</p>
      )}
    </div>
  );
}

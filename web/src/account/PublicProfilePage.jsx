import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import Avatar from './Avatar.jsx';
import './account.css';

export default function PublicProfilePage() {
  const { username } = useParams();
  const [state, setState] = useState({ loading: true, profile: null });

  useEffect(() => {
    const controller = new AbortController();
    setState({ loading: true, profile: null });
    fetch(`/api/users/${encodeURIComponent(username)}`, { signal: controller.signal })
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => setState({ loading: false, profile: d?.data ?? null }))
      .catch(() => {
        if (!controller.signal.aborted) setState({ loading: false, profile: null });
      });
    return () => controller.abort();
  }, [username]);

  if (state.loading) return <div className="container account-page"><p>Loading…</p></div>;
  if (!state.profile) return <div className="container account-page"><h1>Profile not found</h1></div>;

  const { displayName, title, location, pieces, avatar } = state.profile;

  return (
    <div className="container account-page">
      <div className="account-profile-head">
        <Avatar src={avatar} name={displayName} />
        <div>
          <h1>{displayName}</h1>
          <p className="account-meta">@{username} · {title}{location ? ` · ${location}` : ''}</p>
        </div>
      </div>
      {pieces && (
        <>
          <h2>Owned pieces</h2>
          <div className="piece-grid piece-grid--three">
            {pieces.map((p) => (
              <Link className="piece-card" key={p.id} to={`/pieces/${p.id}-${p.slug}`}>
                <span className="piece-card__image-wrap">
                  {p.image ? <img className="piece-card__image" src={p.image} alt={p.title} loading="lazy" /> : <span className="piece-card__image piece-card__image--empty" />}
                </span>
                <span className="piece-card__details"><span className="piece-card__title">{p.title}</span></span>
              </Link>
            ))}
          </div>
        </>
      )}
    </div>
  );
}

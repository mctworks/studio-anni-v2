import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from './api.js';
import { centsToDollars, formatDate, imageSrc } from './util.js';

const LIMIT = 25;

export default function PieceList({ meta }) {
  const [qInput, setQInput] = useState('');
  const [q, setQ] = useState('');
  const [status, setStatus] = useState('');
  const [deleted, setDeleted] = useState('0');
  const [untagged, setUntagged] = useState(false);
  const [sort, setSort] = useState('updated');
  const [offset, setOffset] = useState(0);
  const [tick, setTick] = useState(0);
  const [result, setResult] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    const t = setTimeout(() => {
      setQ(qInput.trim());
      setOffset(0);
    }, 300);
    return () => clearTimeout(t);
  }, [qInput]);

  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams({ deleted, sort, limit: String(LIMIT), offset: String(offset) });
    if (q) params.set('q', q);
    if (status) params.set('status', status);
    if (untagged) params.set('untagged', '1');

    api
      .get(`/pieces?${params}`)
      .then((d) => {
        if (!cancelled) {
          setResult(d);
          setError('');
        }
      })
      .catch((e) => {
        if (!cancelled) setError(`Could not load pieces (${e.status ?? 'network'}).`);
      });

    return () => {
      cancelled = true;
    };
  }, [q, status, deleted, untagged, sort, offset, tick]);

  const filter = (setter) => (e) => {
    setter(e.target.type === 'checkbox' ? e.target.checked : e.target.value);
    setOffset(0);
  };

  const act = async (piece, restore) => {
    if (!restore && !window.confirm(`Delete "${piece.title}"? It can be restored later.`)) return;
    try {
      if (restore) await api.post(`/pieces/${piece.id}/restore`, {});
      else await api.del(`/pieces/${piece.id}`);
      setTick((t) => t + 1);
    } catch (e) {
      setError(`Action failed (${e.status ?? 'network'}).`);
    }
  };

  const total = result?.meta?.total ?? 0;
  const rows = result?.data ?? [];

  return (
    <section>
      <div className="mim-bar">
        <h2>Pieces</h2>
        <Link className="mim-btn" to="/mimosa/pieces/new">New piece</Link>
      </div>

      <div className="mim-filters">
        <input type="search" placeholder="Search title" value={qInput} onChange={(e) => setQInput(e.target.value)} />
        <select value={status} onChange={filter(setStatus)} aria-label="Status">
          <option value="">All statuses</option>
          {(meta?.statuses ?? []).map((s) => (
            <option key={s} value={s}>{s}</option>
          ))}
        </select>
        <select value={deleted} onChange={filter(setDeleted)} aria-label="Deleted">
          <option value="0">Active</option>
          <option value="1">Deleted</option>
          <option value="all">All</option>
        </select>
        <select value={sort} onChange={filter(setSort)} aria-label="Sort">
          <option value="updated">Recently updated</option>
          <option value="id">ID</option>
          <option value="title">Title</option>
        </select>
        <label className="mim-check">
          <input type="checkbox" checked={untagged} onChange={filter(setUntagged)} /> Untagged only
        </label>
      </div>

      {error && <p className="mim-error" role="alert">{error}</p>}

      <table className="mim-table">
        <thead>
          <tr>
            <th></th>
            <th>Title</th>
            <th>Status</th>
            <th>Price</th>
            <th>Size</th>
            <th>Updated</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {rows.map((p) => {
            const src = imageSrc(p.image);
            return (
              <tr key={p.id} className={p.deleted_at ? 'mim-muted' : undefined}>
                <td>{src && <img className="mim-thumb" src={src} alt="" loading="lazy" />}</td>
                <td>
                  <Link to={`/mimosa/pieces/${p.id}`}>{p.title}</Link>
                  <small> #{p.id}</small>
                </td>
                <td>{p.status}{p.deleted_at ? ' (deleted)' : ''}</td>
                <td>
                  {p.status === 'available' ? `$${centsToDollars(p.price_cents)}` : ''}
                  {p.sale_price_cents ? ` → $${centsToDollars(p.sale_price_cents)}` : ''}
                </td>
                <td>{p.size}</td>
                <td>{formatDate(p.updated_at)}</td>
                <td className="mim-actions">
                  <Link to={`/mimosa/pieces/${p.id}`}>Edit</Link>
                  {p.deleted_at ? (
                    <button type="button" className="mim-link" onClick={() => act(p, true)}>Restore</button>
                  ) : (
                    <button type="button" className="mim-link" onClick={() => act(p, false)}>Delete</button>
                  )}
                </td>
              </tr>
            );
          })}
          {result && rows.length === 0 && (
            <tr>
              <td colSpan="7">No pieces match.</td>
            </tr>
          )}
        </tbody>
      </table>

      <div className="mim-pager">
        <button type="button" disabled={offset === 0} onClick={() => setOffset(Math.max(0, offset - LIMIT))}>Previous</button>
        <span>
          {total === 0 ? 0 : offset + 1}–{Math.min(offset + LIMIT, total)} of {total}
        </span>
        <button type="button" disabled={offset + LIMIT >= total} onClick={() => setOffset(offset + LIMIT)}>Next</button>
      </div>
    </section>
  );
}

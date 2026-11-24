import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from './api.js';

const LIMIT = 25;

export default function CustomerReplies() {
  const [status, setStatus] = useState('pending');
  const [kind, setKind] = useState('');
  const [offset, setOffset] = useState(0);
  const [result, setResult] = useState(null);
  const [error, setError] = useState('');

  const load = useCallback(() => {
    const params = new URLSearchParams({ limit: String(LIMIT), offset: String(offset) });
    if (status) params.set('status', status);
    if (kind) params.set('kind', kind);
    return api
      .get(`/replies?${params}`)
      .then((d) => {
        setResult(d);
        setError('');
      })
      .catch((e) => setError(`Could not load replies (${e.status ?? 'network'}).`));
  }, [status, kind, offset]);

  useEffect(() => {
    load();
  }, [load]);

  const act = async (fn) => {
    setError('');
    try {
      await fn();
      await load();
    } catch (e) {
      setError(`Action failed (${e.status ?? 'network'}).`);
    }
  };

  const filter = (setter) => (e) => {
    setter(e.target.value);
    setOffset(0);
  };

  const rows = result?.data ?? [];
  const total = result?.meta?.total ?? 0;

  return (
    <section>
      <div className="mim-bar">
        <h2>Customer Replies</h2>
        <Link to="/mimosa/users">Back to Users</Link>
      </div>

      <div className="mim-filters">
        <select value={status} onChange={filter(setStatus)} aria-label="Status">
          <option value="pending">Awaiting approval{result ? ` (${result.meta.pending})` : ''}</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
          <option value="">All</option>
        </select>
        <select value={kind} onChange={filter(setKind)} aria-label="Type">
          <option value="">Guestbook and pieces</option>
          <option value="guestbook">Guestbook only</option>
          <option value="piece">Piece comments only</option>
        </select>
      </div>

      {error && <p className="mim-error" role="alert">{error}</p>}

      <table className="mim-table">
        <thead>
          <tr>
            <th>Author</th>
            <th>Where</th>
            <th>Message</th>
            <th>Posted</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={`${r.kind}-${r.id}`} className={r.status === 'rejected' ? 'mim-muted' : undefined}>
              <td>
                {r.author ? <Link to={`/mimosa/users/${r.author.userId}`}>{r.author.displayName}</Link> : 'Deleted account'}
                {r.author && <small> {r.author.title}{r.author.accountStatus !== 'active' ? ` · ${r.author.accountStatus}` : ''}</small>}
              </td>
              <td>{r.kind === 'guestbook' ? 'Guestbook' : <Link to={`/mimosa/pieces/${r.piece.id}`}>{r.piece.title}</Link>}</td>
              <td className="mim-pre">{r.body}</td>
              <td>{new Date(r.createdAt).toLocaleDateString()}</td>
              <td>{r.status}</td>
              <td className="mim-actions">
                {r.status !== 'approved' && <button type="button" onClick={() => act(() => api.post(`/replies/${r.kind}/${r.id}/status`, { status: 'approved' }))}>Approve</button>}
                {r.status !== 'rejected' && <button type="button" onClick={() => act(() => api.post(`/replies/${r.kind}/${r.id}/status`, { status: 'rejected' }))}>Reject</button>}
                <button
                  type="button"
                  onClick={() => {
                    if (window.confirm('Delete this permanently?')) act(() => api.del(`/replies/${r.kind}/${r.id}`));
                  }}
                >
                  Delete
                </button>
              </td>
            </tr>
          ))}
          {result && rows.length === 0 && (
            <tr>
              <td colSpan="6">Nothing here.</td>
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

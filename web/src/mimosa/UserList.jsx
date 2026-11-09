import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from './api.js';
import { formatDate } from './util.js';

const LIMIT = 25;

export default function UserList() {
  const [qInput, setQInput] = useState('');
  const [q, setQ] = useState('');
  const [role, setRole] = useState('');
  const [status, setStatus] = useState('');
  const [offset, setOffset] = useState(0);
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
    const params = new URLSearchParams({ limit: String(LIMIT), offset: String(offset) });
    if (q) params.set('q', q);
    if (role) params.set('role', role);
    if (status) params.set('status', status);
    api
      .get(`/users?${params}`)
      .then((d) => {
        if (!cancelled) {
          setResult(d);
          setError('');
        }
      })
      .catch((e) => {
        if (!cancelled) setError(`Could not load users (${e.status ?? 'network'}).`);
      });
    return () => {
      cancelled = true;
    };
  }, [q, role, status, offset]);

  const filter = (setter) => (e) => {
    setter(e.target.value);
    setOffset(0);
  };

  const total = result?.meta?.total ?? 0;
  const rows = result?.data ?? [];

  return (
    <section>
      <div className="mim-bar">
        <h2>Users</h2>
      </div>

      <div className="mim-filters">
        <input type="search" placeholder="Search email, name or username" value={qInput} onChange={(e) => setQInput(e.target.value)} />
        <select value={role} onChange={filter(setRole)} aria-label="Role">
          <option value="">All roles</option>
          <option value="customer">Customers</option>
          <option value="admin">Admins</option>
          <option value="owner">Owner</option>
        </select>
        <select value={status} onChange={filter(setStatus)} aria-label="Status">
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="disabled">Disabled</option>
          <option value="banned">Banned</option>
        </select>
      </div>

      {error && <p className="mim-error" role="alert">{error}</p>}

      <table className="mim-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Title</th>
            <th>Pieces</th>
            <th>Status</th>
            <th>Joined</th>
            <th>Last sign-in</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((u) => (
            <tr key={u.id} className={u.status !== 'active' ? 'mim-muted' : undefined}>
              <td>
                <Link to={`/mimosa/users/${u.id}`}>{u.displayName || u.username || u.email}</Link>
                {u.username && <small> @{u.username}</small>}
              </td>
              <td>{u.email}</td>
              <td>{u.role}</td>
              <td>{u.title ?? ''}</td>
              <td>{u.role === 'customer' ? u.owned : ''}</td>
              <td>{u.status}</td>
              <td>{formatDate(u.createdAt)}</td>
              <td>{formatDate(u.lastLoginAt)}</td>
            </tr>
          ))}
          {result && rows.length === 0 && (
            <tr>
              <td colSpan="8">No users match.</td>
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

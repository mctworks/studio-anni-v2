import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api } from './api.js';
import { formatDate, imageSrc } from './util.js';

const MESSAGES = {
  forbidden: 'Only the owner can change a staff account.',
  cannot_change_self: 'You cannot change your own status.',
  not_customer: 'That only applies to customer accounts.',
  not_active: 'The account is not active.',
  already_owned: 'That piece is already assigned to another customer. Remove it from them first.',
  already_yours: 'That piece is already assigned to this customer.',
  piece_not_found: 'That piece does not exist or was deleted.',
  mail_failed: 'The email could not be sent.',
  too_many_attempts: 'Too many requests. Try again later.',
  validation: 'That value is not valid.',
  owner_locked: 'The owner account cannot be changed here.',
  not_eligible: 'Only active accounts with a verified email can be made admin.',
  no_profile: 'This account has no customer profile to return to. Set it to disabled instead.',
  no_change: 'Nothing to change.',
};

const say = (e) => MESSAGES[e.code] ?? `Action failed (${e.status ?? 'network'}).`;

function PieceAssigner({ onAssign }) {
  const [input, setInput] = useState('');
  const [results, setResults] = useState([]);

  useEffect(() => {
    const q = input.trim();
    if (q.length < 2) {
      setResults([]);
      return undefined;
    }
    let cancelled = false;
    const t = setTimeout(() => {
      api
        .get(`/pieces?${new URLSearchParams({ q, limit: '8' })}`)
        .then((d) => {
          if (!cancelled) setResults(d.data ?? []);
        })
        .catch(() => {
          if (!cancelled) setResults([]);
        });
    }, 300);
    return () => {
      cancelled = true;
      clearTimeout(t);
    };
  }, [input]);

  return (
    <div>
      <input type="search" placeholder="Find a piece by title to assign" value={input} onChange={(e) => setInput(e.target.value)} />
      {results.length > 0 && (
        <ul className="mim-nested">
          {results.map((p) => (
            <li key={p.id}>
              {p.title} <small>#{p.id} · {p.status}</small>{' '}
              <button
                type="button"
                className="mim-link"
                onClick={async () => {
                  if (await onAssign(p)) {
                    setInput('');
                    setResults([]);
                  }
                }}
              >
                Assign
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export default function UserDetail({ me }) {
  const { id } = useParams();
  const [user, setUser] = useState(null);
  const [title, setTitle] = useState('');
  const [status, setStatus] = useState('active');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const apply = useCallback((u) => {
    setUser(u);
    setTitle(u.customTitle ?? '');
    setStatus(u.status);
  }, []);

  useEffect(() => {
    let cancelled = false;
    api
      .get(`/users/${id}`)
      .then((d) => {
        if (!cancelled) apply(d.user);
      })
      .catch((e) => {
        if (!cancelled) setError(e.status === 404 ? 'User not found.' : `Could not load the user (${e.status ?? 'network'}).`);
      });
    return () => {
      cancelled = true;
    };
  }, [id, apply]);

  const run = async (fn, ok) => {
    setError('');
    setNotice('');
    try {
      const d = await fn();
      if (d?.user) apply(d.user);
      setNotice(ok);
      return true;
    } catch (e) {
      setError(say(e));
      return false;
    }
  };

  if (!user) return <p className={error ? 'mim-error' : undefined}>{error || 'Loading…'}</p>;

  const customer = user.role === 'customer';
  const canRole = me.role === 'owner' && me.id !== user.id && user.role !== 'owner';
  const canStatus = me.id !== user.id && (customer || me.role === 'owner');

  return (
    <section>
      <div className="mim-bar">
        <h2>{user.displayName || user.username || user.email}</h2>
        <Link to="/mimosa/users">Back to users</Link>
      </div>

      {error && <p className="mim-error" role="alert">{error}</p>}
      {notice && <p className="mim-ok" role="status">{notice}</p>}

      <fieldset>
        <legend>Account</legend>
        {user.avatar && (
          <p>
            <img className="mim-thumb" src={user.avatar} alt="" width="64" height="64" />{' '}
            <button
              type="button"
              className="mim-link"
              onClick={() => window.confirm('Remove this picture?') && run(() => api.del(`/users/${user.id}/avatar`), 'Picture removed.')}
            >
              Remove picture
            </button>
          </p>
        )}
        <p>Email: {user.email} {user.emailVerified ? '(verified)' : '(not verified)'}</p>
        <p>Role: {user.role}{user.role !== 'customer' && (user.totpEnrolled ? ' · authenticator set up' : ' · authenticator NOT set up (cannot sign in yet)')}</p>
        {canRole && (
          <p>
            <button
              type="button"
              onClick={() => {
                const to = user.role === 'admin' ? 'customer' : 'admin';
                const msg = to === 'admin'
                  ? `Give ${user.email} admin access to MIMOSA? They are signed out everywhere and must set up an authenticator before they can sign in.`
                  : `Remove admin access from ${user.email}? Their authenticator and recovery codes are deleted.`;
                if (window.confirm(msg)) run(() => api.patch(`/users/${user.id}`, { role: to }), to === 'admin' ? 'Admin access granted.' : 'Admin access removed.');
              }}
            >
              {user.role === 'admin' ? 'Remove admin access' : 'Make admin'}
            </button>
          </p>
        )}
        {user.role === 'admin' && !user.totpEnrolled && (
          <p className="mim-note">
            On the server, run <code>php bin/enroll_totp.php --email={user.email}</code> with this person present. They scan the secret, confirm a code and keep the recovery codes.
          </p>
        )}
        {user.username && <p>Username: @{user.username}</p>}
        {customer && <p>Profile: {user.profilePublic ? 'public' : 'private'}{user.location ? ` · ${user.location}` : ''}</p>}
        <p>Joined {formatDate(user.createdAt)} · Last sign-in {formatDate(user.lastLoginAt) || 'never'}</p>
        <label>
          Status{' '}
          <select value={status} onChange={(e) => setStatus(e.target.value)} disabled={!canStatus}>
            <option value="active">active</option>
            <option value="disabled">disabled</option>
            <option value="banned">banned</option>
          </select>
        </label>{' '}
        <button
          type="button"
          disabled={!canStatus || status === user.status}
          onClick={() => {
            if (status !== 'active' && !window.confirm(`Set this account to ${status}? They will be signed out everywhere.`)) return;
            run(() => api.patch(`/users/${user.id}`, { status }), 'Status updated.');
          }}
        >
          Save status
        </button>
      </fieldset>

      {user.hasProfile && (
        <fieldset>
          <legend>Title</legend>
          <p>Shown now: {user.title}{user.customTitle ? ' (custom)' : (customer ? ' (from owned pieces)' : ' (default)')}</p>
          <input value={title} maxLength={40} placeholder="Custom title, e.g. VIP Client" onChange={(e) => setTitle(e.target.value)} />{' '}
          <button type="button" onClick={() => run(() => api.patch(`/users/${user.id}`, { customTitle: title }), 'Title saved.')}>
            Save title
          </button>{' '}
          {user.customTitle && (
            <button type="button" className="mim-link" onClick={() => run(() => api.patch(`/users/${user.id}`, { customTitle: null }), 'Custom title cleared.')}>
              Clear
            </button>
          )}
        </fieldset>

      )}

      {customer && (
        <>
          <fieldset>
            <legend>Owned pieces</legend>
            {user.pieces.length === 0 && <p>None yet.</p>}
            {user.pieces.length > 0 && (
              <table className="mim-table">
                <tbody>
                  {user.pieces.map((p) => {
                    const src = imageSrc(p.image);
                    return (
                      <tr key={p.id}>
                        <td>{src && <img className="mim-thumb" src={src} alt="" loading="lazy" />}</td>
                        <td>
                          <Link to={`/mimosa/pieces/${p.id}`}>{p.title}</Link> <small>#{p.id} · {p.status}</small>
                        </td>
                        <td>{formatDate(p.deliveredAt)} · {p.source}</td>
                        <td className="mim-actions">
                          <button
                            type="button"
                            className="mim-link"
                            onClick={() => window.confirm(`Remove "${p.title}" from this customer?`) && run(() => api.del(`/users/${user.id}/pieces/${p.id}`), 'Piece removed.')}
                          >
                            Remove
                          </button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            )}
            <PieceAssigner onAssign={(p) => run(() => api.post(`/users/${user.id}/pieces`, { pieceId: p.id }), `Assigned "${p.title}".`)} />
            <p className="mim-note">Assign a piece only once it has been delivered; it counts toward the customer's title.</p>
          </fieldset>

          <fieldset>
            <legend>Password</legend>
            <button
              type="button"
              disabled={user.status !== 'active'}
              onClick={() => window.confirm(`Email a reset link to ${user.email}?`) && run(() => api.post(`/users/${user.id}/reset-password`, {}), 'Reset email sent.')}
            >
              Send reset email
            </button>
            <p className="mim-note">The customer chooses their own password. Staff never see or set it.</p>
          </fieldset>

          <fieldset>
            <legend>Shipping addresses</legend>
            {user.addresses.length === 0 && <p>None saved. Customers add theirs at checkout.</p>}
            {user.addresses.map((a) => (
              <p key={a.id}>
                {a.label ? <strong>{a.label}{a.isDefault ? ' (default)' : ''}: </strong> : null}
                {a.recipient}, {a.line1}{a.line2 ? `, ${a.line2}` : ''}, {a.city}, {a.region} {a.postalCode}, {a.country}{a.phone ? ` · ${a.phone}` : ''}
              </p>
            ))}
          </fieldset>
        </>
      )}
    </section>
  );
}

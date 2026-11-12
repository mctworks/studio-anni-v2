import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from './api.js';
import { centsToDollars, formatDate } from './util.js';

const AVAILABILITY = [
  ['open', 'Accepting commissions'],
  ['delays', 'Accepting commissions, current delays'],
  ['closed', 'Temporarily closed'],
  ['unknown', 'To be announced'],
];

export const STATUS_LABEL = {
  submitted: 'Submitted',
  info_requested: 'Info requested',
  customer_replied: 'Customer replied',
  quoted: 'Quoted',
  deposit_paid: 'Deposit paid',
  in_progress: 'In progress',
  completed: 'Completed',
  awaiting_balance: 'Awaiting balance',
  shipped: 'Shipped',
  denied: 'Denied',
  abandoned: 'Abandoned',
  stopped: 'Stopped',
  refunded: 'Refunded',
};

const HEALTH_LABEL = { ok: 'OK', warn: 'Check', fail: 'Down', off: 'Not set up' };

function Health() {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    setBusy(true);
    api
      .get('/health')
      .then((d) => {
        setData(d);
        setError('');
      })
      .catch((e) => setError(`Could not load health checks (${e.status ?? 'network'}).`))
      .finally(() => setBusy(false));
  }, []);

  useEffect(load, [load]);

  return (
    <section className="mim-panel">
      <div className="mim-bar">
        <h3>System health</h3>
        <button type="button" onClick={load} disabled={busy}>{busy ? 'Checking…' : 'Re-check'}</button>
      </div>
      {error && <p className="mim-error" role="alert">{error}</p>}
      {data && (
        <ul className="mim-health">
          {data.checks.map((c) => (
            <li key={c.id}>
              <span className={`mim-pill mim-pill--${c.status}`}>{HEALTH_LABEL[c.status] ?? c.status}</span>
              <strong>{c.label}</strong>
              <span className="mim-note">{c.detail}</span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function Availability({ value, onSaved }) {
  const [draft, setDraft] = useState(value);
  const [state, setState] = useState('');

  useEffect(() => setDraft(value), [value]);

  const save = async () => {
    setState('saving');
    try {
      const d = await api.put('/commissions/availability', { availability: draft });
      onSaved(d.availability);
      setState('saved');
    } catch (e) {
      setState(`Could not save (${e.status ?? 'network'}).`);
    }
  };

  return (
    <section className="mim-panel">
      <h3>Homepage commission status</h3>
      <p className="mim-note">Shown on the homepage and the Commissions page.</p>
      <div className="mim-filters">
        <select value={draft} onChange={(e) => { setDraft(e.target.value); setState(''); }} aria-label="Commission availability">
          {AVAILABILITY.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
        </select>
        <button type="button" onClick={save} disabled={draft === value || state === 'saving'}>Save</button>
        {state === 'saved' && <span className="mim-ok" role="status">Saved</span>}
        {state && state !== 'saved' && state !== 'saving' && <span className="mim-error" role="alert">{state}</span>}
      </div>
    </section>
  );
}

export default function CommissionsHome() {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let off = false;
    api
      .get('/commissions')
      .then((d) => {
        if (!off) setData(d);
      })
      .catch((e) => {
        if (!off) setError(`Could not load commissions (${e.status ?? 'network'}).`);
      });
    return () => {
      off = true;
    };
  }, []);

  const needReply = data ? data.counts.submitted + data.counts.info_requested + data.counts.customer_replied : 0;

  return (
    <section>
      <div className="mim-bar">
        <h2>Commissions &amp; homepage</h2>
        <Link className="mim-btn" to="/mimosa/pricing">Edit pricing</Link>
      </div>
      {error && <p className="mim-error" role="alert">{error}</p>}

      <div className="mim-cols">
        {data && <Availability value={data.availability} onSaved={(availability) => setData({ ...data, availability })} />}
        <Health />
      </div>

      <section className="mim-panel">
        <h3>Commission queue</h3>
        {!data ? <p>Loading…</p> : (
          <>
            <p className="mim-stats">
              <span><strong>{needReply}</strong> need a reply</span>
              <span><strong>{data.counts.deposit_paid + data.counts.in_progress + data.counts.completed}</strong> in the studio</span>
              <span><strong>{data.counts.awaiting_balance}</strong> awaiting balance</span>
            </p>
            {data.recent.length === 0 ? (
              <p className="mim-note">No commission requests yet. The request form is not built; requests will appear here.</p>
            ) : (
              <table className="mim-table">
                <thead>
                  <tr><th>#</th><th>Customer</th><th>Type</th><th>Size</th><th>Quote</th><th>Status</th><th>Requested</th></tr>
                </thead>
                <tbody>
                  {data.recent.map((c) => (
                    <tr key={c.id}>
                      <td>{c.id}</td>
                      <td>{c.customer}</td>
                      <td>{c.subject_type === 'pet' ? 'Pet' : 'Human'}</td>
                      <td>{c.size}</td>
                      <td>${centsToDollars(c.quoted_cents)}</td>
                      <td>{STATUS_LABEL[c.status] ?? c.status}</td>
                      <td>{formatDate(c.created_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </>
        )}
      </section>

      <section className="mim-panel">
        <h3>Latest announcements</h3>
        <p className="mim-note">The CTA and announcements component will be controlled from here once it is built.</p>
      </section>
    </section>
  );
}

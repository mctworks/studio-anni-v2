import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api } from './api.js';
import { centsToDollars, formatDate } from './util.js';
import { STATUS_LABEL } from './CommissionsHome.jsx';

const ERR = {
  note_required: 'A message to the customer is required for this status.',
  invalid_transition: 'That status change is not allowed from here.',
  conflict: 'This commission changed while you were working. Reloaded.',
  note_too_long: 'Message is too long.',
};

export default function CommissionDetail() {
  const { id } = useParams();
  const [c, setC] = useState(null);
  const [error, setError] = useState('');
  const [next, setNext] = useState('');
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState('');

  const load = useCallback(() => api.get(`/commissions/${id}`).then((d) => {
    setC(d.commission);
    setNext(d.commission.next_statuses[0] ?? '');
  }), [id]);

  useEffect(() => {
    load().catch((e) => setError(e.status === 404 ? 'Commission not found.' : `Could not load this commission (${e.status ?? 'network'}).`));
  }, [load]);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setResult('');
    try {
      const d = await api.post(`/commissions/${id}/status`, { status: next, note });
      setNote('');
      setResult(d.emailed ? 'Status updated and the customer was emailed.' : 'Status updated, but the email could not be sent.');
    } catch (err) {
      setResult(ERR[err.code] ?? `Could not update (${err.status ?? 'network'}).`);
    }
    await load().catch(() => {});
    setBusy(false);
  };

  if (error) return <p className="mim-error" role="alert">{error}</p>;
  if (!c) return <p>Loading…</p>;

  return (
    <section>
      <div className="mim-bar">
        <h2>Commission #{c.id}</h2>
        <Link to="/mimosa">Back to commissions</Link>
      </div>
      <dl className="mim-details">
        <dt>Status</dt><dd>{STATUS_LABEL[c.status] ?? c.status}</dd>
        <dt>Customer</dt><dd>{c.customer} (<a href={`mailto:${c.email}`}>{c.email}</a>) · <Link to={`/mimosa/users/${c.user_id}`}>account</Link></dd>
        <dt>Type</dt><dd>{c.subject_type === 'pet' ? 'Pet / animal' : 'Human'}</dd>
        <dt>Size</dt><dd>{c.size}</dd>
        <dt>Subjects</dt><dd>{c.person_count} people, {c.pet_count} pets</dd>
        <dt>Estimate</dt><dd>${centsToDollars(c.quoted_cents)} plus shipping</dd>
        <dt>Delivery</dt><dd>{c.delivery === 'pickup' ? `Local pickup (ZIP ${c.postal_code})` : 'Ship'}</dd>
        <dt>Requested</dt><dd>{formatDate(c.created_at)}</dd>
        <dt>Terms</dt><dd>{c.terms_version}, accepted {formatDate(c.terms_accepted_at)}</dd>
      </dl>

      <section className="mim-panel">
        <h3>Update status</h3>
        {c.next_statuses.length === 0 ? <p className="mim-note">This commission is closed.</p> : (
          <form className="mim-form" onSubmit={submit}>
            <p className="mim-note">The customer is emailed on every status change. Your message is included and shown on their account.</p>
            <select value={next} onChange={(e) => setNext(e.target.value)} aria-label="New status">
              {c.next_statuses.map((s) => <option key={s} value={s}>{STATUS_LABEL[s] ?? s}</option>)}
            </select>
            <textarea rows={4} maxLength={4000} value={note} onChange={(e) => setNote(e.target.value)} placeholder={next === 'info_requested' ? 'What do you need to know? (required)' : 'Optional message to the customer'} aria-label="Message to customer" />
            <button type="submit" disabled={busy || !next}>{busy ? 'Saving…' : 'Update and email customer'}</button>
          </form>
        )}
        {result && <p role="status" className="mim-note">{result}</p>}
      </section>

      <h3>Description</h3>
      <p className="mim-pre">{c.description}</p>
      <h3>Source photos</h3>
      {c.files.length === 0 ? <p className="mim-note">No photos.</p> : (
        <ul className="mim-photos">
          {c.files.map((f) => (
            <li key={f.id}>
              <a href={`/api/mimosa/commissions/${c.id}/files/${f.id}`} target="_blank" rel="noreferrer">
                <img src={`/api/mimosa/commissions/${c.id}/files/${f.id}`} alt={f.original_name} loading="lazy" />
              </a>
              <span className="mim-note">{f.original_name}</span>
            </li>
          ))}
        </ul>
      )}
      <h3>History</h3>
      <ul className="mim-history">
        {c.events.map((e, i) => (
          <li key={i}>
            <strong>{e.kind === 'message' ? 'Customer replied' : STATUS_LABEL[e.to] ?? e.to}</strong>
            <span className="mim-note"> · {formatDate(e.created_at)} · {e.by}{e.kind === 'status' && e.from ? (e.emailed ? ' · emailed' : ' · not emailed') : ''}</span>
            {e.note && <p className="mim-pre">{e.note}</p>}
          </li>
        ))}
      </ul>
    </section>
  );
}

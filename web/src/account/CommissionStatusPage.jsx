import { useEffect, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { account } from './api.js';
import { customerStatus, money } from '../commissionStatus.js';
import './account.css';

const when = (v) => new Date(String(v).replace(' ', 'T') + 'Z').toLocaleString();

export default function CommissionStatusPage() {
  const { id } = useParams();
  const location = useLocation();
  const [c, setC] = useState(null);
  const [state, setState] = useState('loading');
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const load = () =>
    account.commission(id).then((r) => {
      if (r.ok) {
        setC(r.data.commission);
        setState('ready');
      } else setState(r.status === 401 ? 'login' : 'missing');
    });

  useEffect(() => {
    load();
  }, [id]); // eslint-disable-line react-hooks/exhaustive-deps

  const send = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError('');
    const r = await account.replyCommission(id, message);
    setBusy(false);
    if (!r.ok) return setError('Your reply could not be sent. Please try again.');
    setMessage('');
    load();
  };

  if (state === 'loading') return <div className="container account-page"><p>Loading…</p></div>;
  if (state === 'login') return <div className="container account-page"><p>Please <Link to="/login">sign in</Link> to see your commission.</p></div>;
  if (state === 'missing') return <div className="container account-page"><p>Commission not found. <Link to="/account">Back to your account</Link></p></div>;

  return (
    <div className="container account-page">
      <p><Link to="/account">Back to your account</Link></p>
      <h1>Portrait request #{c.id}</h1>
      {location.state?.created && <p role="status">Thank you! Your request was sent. A confirmation is on its way to your inbox.</p>}
      <dl className="account-details">
        <dt>Status</dt><dd><strong>{customerStatus[c.status] ?? c.status}</strong></dd>
        <dt>Portrait</dt><dd>{c.subject_type === 'pet' ? 'Pet(s)' : 'Person(s)'} · {c.size} · {c.person_count} people, {c.pet_count} pets</dd>
        <dt>Estimate</dt><dd>{money(c.quoted_cents)} plus shipping</dd>
        <dt>Delivery</dt><dd>{c.delivery === 'pickup' ? `Local pickup (ZIP ${c.postal_code})` : 'Ship'}</dd>
        <dt>Source images</dt><dd>{c.photo_count}</dd>
        <dt>Requested</dt><dd>{when(c.created_at)}</dd>
      </dl>
      {['quoted', 'completed', 'awaiting_balance'].includes(c.status) && <p><Link className="page-nav-button" to={`/account/commissions/${c.id}/pay`}>Make a payment</Link></p>}
      <h2>What you asked for</h2>
      <p className="account-pre">{c.description}</p>

      <h2>History</h2>
      <ol className="account-history">
        {c.events.map((e, i) => (
          <li key={i}>
            <strong>{e.kind === 'message' ? 'You replied' : customerStatus[e.status] ?? e.status}</strong>
            <span className="account-note"> · {when(e.created_at)}</span>
            {e.note && <p className="account-pre">{e.note}</p>}
          </li>
        ))}
      </ol>

      {c.can_reply && (
        <form className="account-form" onSubmit={send}>
          <h2>Reply to Anni</h2>
          <textarea rows={6} maxLength={4000} required value={message} onChange={(e) => setMessage(e.target.value)} />
          {error && <small className="account-error" role="alert">{error}</small>}
          <button className="page-nav-button" type="submit" disabled={busy}>{busy ? 'Sending…' : 'Send reply'}</button>
        </form>
      )}
    </div>
  );
}

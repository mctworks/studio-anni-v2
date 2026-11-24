import { useState } from 'react';
import './comments.css';

const ERRORS = {
  empty: 'Write something first.',
  too_long: 'That is too long.',
  invalid: 'That contains characters we cannot accept.',
  no_profile: 'Create your public profile on your account page first.',
  already_signed: 'You have already signed the guestbook.',
  too_many_attempts: 'Too many posts. Try again later.',
  unauthenticated: 'Please sign in again.',
};

export default function ReplyForm({ max, label, submitLabel, send, onPosted, rows = 4 }) {
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError('');
    setNotice('');
    const r = await send(body);
    setBusy(false);
    if (r.ok) {
      setBody('');
      setNotice(r.data.reply.status === 'approved' ? 'Posted.' : 'Thank you! It will appear once we have approved it.');
      onPosted?.(r.data.reply);
      return;
    }
    const code = r.data?.fields?.body ?? r.data?.error;
    setError(ERRORS[code] ?? 'That could not be posted. Please try again.');
  };

  return (
    <form className="reply-form" onSubmit={submit}>
      <label htmlFor="reply-body">{label}</label>
      <textarea id="reply-body" value={body} onChange={(e) => setBody(e.target.value)} maxLength={max} rows={rows} required />
      <div className="reply-form__foot">
        <span className="reply-form__count" aria-live="polite">{body.length} / {max}</span>
        <button className="page-nav-button" type="submit" disabled={busy || body.trim() === ''}>{submitLabel}</button>
      </div>
      {error && <p className="reply-form__error" role="alert">{error}</p>}
      {notice && <p role="status">{notice}</p>}
    </form>
  );
}

import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from './AuthContext.jsx';
import { account } from './account/api.js';
import { PickupNotice } from './PickupNotice.jsx';
import { availabilityCopy, money } from './commissionStatus.js';
import './CommissionRequestPage.css';

const MAX_FILES = 4;
const MAX_TOTAL = 25 * 1024 * 1024;
const TYPES = ['image/jpeg', 'image/png', 'image/gif'];

const ERRORS = {
  photos: {
    required: 'Please attach at least one source image.',
    too_many: `Please attach no more than ${MAX_FILES} images.`,
    too_large: 'Your images are over the 25 MB limit.',
    unsupported_type: 'Please upload .jpg, .jpeg, .gif or .png files only.',
    upload_failed: 'An image failed to upload. Please try again.',
  },
  canvas_size_id: { not_offered: 'That size is not offered for this type of portrait.' },
};

const TERMS = [
  'Deposit: Work begins once a deposit of 50% (or payment in full) is received. Deposits are non-refundable once work has begun, except where Studio Anni is unable to complete the commission.',
  'Balance: The balance is due when the painting is finished and before it ships. Until it is paid, Studio Anni keeps the painting and the deposit. If paying on time is difficult, talk to us before then; payment plans are available at our discretion.',
  "Scope: The details agreed at approval are the scope of the commission. Additions or changes after work has begun aren't included and may not be possible.",
  'Updates: Anni shares works in progress when she chooses, usually on Bluesky. Occasional questions about status are welcome.',
  'Right To Stop: If a customer repeatedly pressures or harasses Studio Anni staff (including, but not limited to Anni herself), micromanages the work, or makes ongoing demands outside the agreed scope, Studio Anni reserves the right to stop work and keep the deposit, and may decline future commissions.',
  'Our Responsibility: If Anni makes an error that can\'t easily be fixed, or is unable finish the commission, the deposit is refunded.',
];

const mb = (bytes) => `${(bytes / 1048576).toFixed(1)} MB`;

function Dropzone({ files, setFiles, error }) {
  const input = useRef(null);
  const [over, setOver] = useState(false);
  const [note, setNote] = useState('');
  const total = files.reduce((n, f) => n + f.file.size, 0);

  const add = (list) => {
    const next = [...files];
    let msg = '';
    for (const file of list) {
      if (!TYPES.includes(file.type)) msg = `${file.name}: please upload .jpg, .jpeg, .gif or .png files only.`;
      else if (next.length >= MAX_FILES) msg = `Please attach no more than ${MAX_FILES} images.`;
      else if (next.reduce((n, f) => n + f.file.size, 0) + file.size > MAX_TOTAL) msg = 'Adding that would go over the 25 MB limit.';
      else next.push({ id: `${file.name}-${file.size}-${file.lastModified}-${next.length}`, file, url: URL.createObjectURL(file) });
    }
    setNote(msg);
    setFiles(next);
  };

  const remove = (id) => {
    setNote('');
    setFiles(files.filter((f) => {
      if (f.id === id) URL.revokeObjectURL(f.url);
      return f.id !== id;
    }));
  };

  return (
    <div className="crf-field">
      <span className="crf-label" id="crf-photos-label">Source Image(s)</span>
      <div
        className={`crf-drop${over ? ' crf-drop--over' : ''}`}
        onDragOver={(e) => { e.preventDefault(); setOver(true); }}
        onDragLeave={() => setOver(false)}
        onDrop={(e) => { e.preventDefault(); setOver(false); add([...e.dataTransfer.files]); }}
      >
        <p>Drag and drop your images here, or</p>
        <button type="button" className="page-nav-button" onClick={() => input.current?.click()} aria-describedby="crf-photos-label">Choose images</button>
        <input ref={input} type="file" accept=".jpg,.jpeg,.gif,.png,image/jpeg,image/png,image/gif" multiple hidden onChange={(e) => { add([...e.target.files]); e.target.value = ''; }} />
        <small>2 to 4 clear photos work best. {MAX_FILES} images maximum, 25 MB total. JPG, GIF or PNG.</small>
      </div>
      {files.length > 0 && (
        <>
          <ul className="crf-thumbs">
            {files.map((f) => (
              <li key={f.id}>
                <img src={f.url} alt={f.file.name} />
                <span>{f.file.name}</span>
                <button type="button" className="account-link" onClick={() => remove(f.id)}>Remove</button>
              </li>
            ))}
          </ul>
          <small>{files.length} of {MAX_FILES} images · {mb(total)} of 25 MB</small>
          {files.length < 2 && <small> · Tip: additional source photos at different angles helps Anni capture your subject.</small>}
        </>
      )}
      {(note || error) && <p className="crf-error" role="alert">{note || error}</p>}
    </div>
  );
}

export default function CommissionRequestPage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [info, setInfo] = useState(null);
  const [subject, setSubject] = useState('pet');
  const [sizeId, setSizeId] = useState('');
  const [pets, setPets] = useState(1);
  const [people, setPeople] = useState(1);
  const [description, setDescription] = useState('');
  const [delivery, setDelivery] = useState('ship');
  const [zip, setZip] = useState('');
  const [terms, setTerms] = useState(false);
  const [files, setFiles] = useState([]);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState('');

  useEffect(() => {
    const c = new AbortController();
    fetch('/api/commissions/info', { signal: c.signal }).then((r) => (r.ok ? r.json() : Promise.reject(new Error('info')))).then(setInfo).catch(() => {});
    return () => c.abort();
  }, []);

  useEffect(() => () => files.forEach((f) => URL.revokeObjectURL(f.url)), []); // eslint-disable-line react-hooks/exhaustive-deps

  const sizes = useMemo(() => (info?.sizes || []).filter((s) => (subject === 'pet' ? s.pet_cents : s.human_cents) != null), [info, subject]);
  useEffect(() => {
    if (!sizes.some((s) => String(s.canvas_size_id) === String(sizeId))) setSizeId(sizes[0] ? String(sizes[0].canvas_size_id) : '');
  }, [sizes]); // eslint-disable-line react-hooks/exhaustive-deps

  const quote = useMemo(() => {
    const size = sizes.find((s) => String(s.canvas_size_id) === String(sizeId));
    if (!size || !info) return null;
    const base = subject === 'pet' ? size.pet_cents : size.human_cents;
    const f = info.fees;
    const extras = subject === 'pet' ? Math.max(0, pets - 1) * f.extra_pet_cents : Math.max(0, people - 1) * f.extra_person_cents + pets * f.human_pet_cents;
    return base + extras;
  }, [sizes, sizeId, info, subject, pets, people]);

  if (!user) {
    return (
      <section className="container content-page crf">
        <h1>Portrait Request Form</h1>
        <p>In order to request a commission, you will first need to sign in or create a Studio Anni account. If you have questions, feel free to use the contact form on the <Link to="/commissions">Commissions page</Link> before sending a request with this form.</p>
        <button type="button" className="page-nav-button" onClick={() => navigate('/login')}>Sign In / Create Account</button>
      </section>
    );
  }

  if (info?.availability === 'closed') {
    return (
      <section className="container content-page crf">
        <h1>Portrait Request Form</h1>
        <p>{availabilityCopy.closed.label}. Please check back soon.</p>
        <p><Link to="/commissions">Back to Commissions</Link></p>
      </section>
    );
  }

  const submit = async (e) => {
    e.preventDefault();
    if (busy) return;
    setFormError('');
    const local = {};
    if (files.length === 0) local.photos = 'required';
    if (!terms) local.terms = 'required';
    if (Object.keys(local).length) return setErrors(local);

    const form = new FormData();
    form.append('subject_type', subject);
    form.append('canvas_size_id', sizeId);
    form.append('pet_count', String(pets));
    form.append('person_count', subject === 'pet' ? '0' : String(people));
    form.append('description', description);
    form.append('delivery', delivery);
    form.append('postal_code', zip);
    form.append('terms', '1');
    files.forEach((f) => form.append('photos[]', f.file, f.file.name));

    setBusy(true);
    const r = await account.requestCommission(form);
    setBusy(false);
    if (r.ok) return navigate(`/account/commissions/${r.data.commission.id}`, { state: { created: true } });
    if (r.status === 422) return setErrors(r.data?.fields || {});
    setErrors({});
    setFormError(
      {
        413: 'Your images are too large for the server. Please use smaller files.',
        429: 'You have sent several requests today. Please try again tomorrow or contact us.',
        409: 'Commissions are temporarily closed.',
      }[r.status] || 'Your request could not be sent. Please try again or contact us directly.',
    );
  };

  const fieldError = (name, text) => (errors[name] ? <p className="crf-error" role="alert">{text || 'Please check this field.'}</p> : null);

  return (
    <section className="container content-page crf">
      <h1>Portrait Request Form</h1>
      <form onSubmit={submit} noValidate={false}>
        <fieldset>
          <legend>Contact Details</legend>
          <p className="crf-note">Sending as {user.displayName || user.email} ({user.email}). Anni will reply to this address.</p>
        </fieldset>

        <fieldset>
          <legend>About The Subject(s)</legend>
          <div className="crf-row">
            <label className="crf-field">
              <span className="crf-label">Portrait of</span>
              <select value={subject} onChange={(e) => { const nextSubject = e.target.value; setSubject(nextSubject); setPets(nextSubject === 'human' ? 0 : 1); }}>
                <option value="pet">Animal/Pet(s)</option>
                <option value="human">Person(s)</option>
              </select>
            </label>
            <label className="crf-field">
              <span className="crf-label">Canvas size</span>
              <select value={sizeId} onChange={(e) => setSizeId(e.target.value)} required>
                {sizes.map((s) => <option key={s.canvas_size_id} value={s.canvas_size_id}>{s.label} · {money(subject === 'pet' ? s.pet_cents : s.human_cents)}</option>)}
              </select>
              {fieldError('canvas_size_id', ERRORS.canvas_size_id[errors.canvas_size_id])}
            </label>
          </div>
          <div className="crf-row">
            {subject === 'human' && (
              <label className="crf-field">
                <span className="crf-label">Number of people</span>
                <input type="number" min={1} max={10} value={people} onChange={(e) => setPeople(Math.min(10, Math.max(1, Number(e.target.value) || 1)))} />
              </label>
            )}
            <label className="crf-field">
              <span className="crf-label">{subject === 'pet' ? 'Number of pets/animals' : 'Animals to include'}</span>
              <input type="number" min={subject === 'pet' ? 1 : 0} max={10} value={pets} onChange={(e) => setPets(Math.min(10, Math.max(subject === 'pet' ? 1 : 0, Number(e.target.value) || 0)))} />
            </label>
          </div>
          {quote !== null && <p className="crf-quote">Estimate: <strong>{money(quote)}</strong>{delivery === 'pickup' ? ' with local pickup (no shipping)' : ' plus shipping'}. No payment is needed until Anni approves your request.</p>}

          <label className="crf-field">
            <span className="crf-label">Please briefly tell us what you want Anni to paint, be it person(s) or pet(s)! (If you're arranging for local pickup, please include proposed pickup details.)</span>
            <textarea rows={8} minLength={10} maxLength={4000} required placeholder="Talk About Who You Want Painted!" value={description} onChange={(e) => setDescription(e.target.value)} />
            {fieldError('description', 'Please tell us a little more (at least a few words).')}
          </label>

          <Dropzone files={files} setFiles={(f) => { setFiles(f); setErrors((x) => ({ ...x, photos: undefined })); }} error={errors.photos ? ERRORS.photos[errors.photos] || 'Please check your images.' : ''} />

          <div className="crf-row">
            <label className="crf-field">
              <span className="crf-label">Delivery</span>
              <select value={delivery} onChange={(e) => setDelivery(e.target.value)}>
                <option value="ship">Ship to me</option>
                <option value="pickup">Local pickup</option>
              </select>
            </label>
            <label className="crf-field">
              <span className="crf-label">ZIP code{delivery === 'pickup' ? '' : ' (optional)'}</span>
              <input type="text" inputMode="numeric" maxLength={10} pattern="\d{5}(-\d{4})?" required={delivery === 'pickup'} value={zip} onChange={(e) => setZip(e.target.value)} />
              {fieldError('postal_code', 'Please enter a valid 5-digit ZIP code.')}
            </label>
          </div>
          {delivery === 'pickup' && <PickupNotice zip={zip} />}

          <p className="crf-note">Progress photos are not sent to individual customers. Anni posts works in progress on Bluesky when she chooses.</p>
          <details className="crf-terms">
            <summary>Commission terms (draft)</summary>
            <ol>{TERMS.map((t) => <li key={t}>{t}</li>)}</ol>
          </details>
          <label className="crf-check">
            <input type="checkbox" checked={terms} onChange={(e) => setTerms(e.target.checked)} /> I agree to the commission terms (draft).
          </label>
          {fieldError('terms', 'Please accept the terms to continue.')}

          {formError && <p className="crf-error" role="alert">{formError}</p>}
          <button type="submit" className="page-nav-button" disabled={busy || !sizeId}>{busy ? 'Sending…' : 'Send Portrait Request!'}</button>
        </fieldset>
      </form>
    </section>
  );
}

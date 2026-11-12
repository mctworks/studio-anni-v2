import { useCallback, useEffect, useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { api, ApiError } from './api.js';
import ImageManager from './ImageManager.jsx';
import { centsToDollars, dollarsToCents, localInputToIso, utcToLocalInput } from './util.js';

const EMPTY = {
  title: '',
  slug: '',
  description: '',
  medium: 'Acrylic on Canvas',
  canvas_size_id: '',
  price: '',
  sale_price: '',
  sale_ends_at: '',
  status: 'available',
  status_note: '',
  origin: 'original',
  purchase_mode: 'buy',
  print_url: '',
  painted_on: '',
  category_ids: [],
};

const ERRORS = {
  invalid: 'Invalid value.',
  unknown_field: 'Unknown field.',
  too_long: 'Too long.',
  taken: 'Already in use.',
  unknown_category: 'Unknown category.',
  required_when_available: 'Required for an available piece.',
  not_offered: 'This size is retired and cannot be sold.',
  must_be_below_price: 'Must be below the price.',
  requires_available: 'Only applies to available pieces.',
  requires_sale_price: 'Add a sale price.',
  required: 'Required.',
};

const toForm = (p) => ({
  title: p.title ?? '',
  slug: p.slug ?? '',
  description: p.description ?? '',
  medium: p.medium ?? '',
  canvas_size_id: p.canvas_size_id ? String(p.canvas_size_id) : '',
  price: p.price_cents ? centsToDollars(p.price_cents) : '',
  sale_price: p.sale_price_cents ? centsToDollars(p.sale_price_cents) : '',
  sale_ends_at: utcToLocalInput(p.sale_ends_at),
  status: p.status ?? 'available',
  status_note: p.status_note ?? '',
  origin: p.origin ?? 'original',
  purchase_mode: p.purchase_mode ?? 'buy',
  print_url: p.print_url ?? '',
  painted_on: p.painted_on ? String(p.painted_on).slice(0, 10) : '',
  category_ids: (p.category_ids ?? []).map(Number),
});

const nul = (s) => (s.trim() === '' ? null : s.trim());

function toPayload(f) {
  const errors = {};

  let price = 0;
  if (f.price.trim() !== '') {
    price = dollarsToCents(f.price);
    if (price === null) errors.price_cents = 'invalid';
  }

  let sale = null;
  if (f.sale_price.trim() !== '') {
    sale = dollarsToCents(f.sale_price);
    if (sale === null) errors.sale_price_cents = 'invalid';
  }

  const payload = {
    title: f.title.trim(),
    description: nul(f.description),
    medium: nul(f.medium),
    canvas_size_id: f.canvas_size_id ? Number(f.canvas_size_id) : null,
    price_cents: price,
    sale_price_cents: sale,
    sale_ends_at: localInputToIso(f.sale_ends_at),
    status: f.status,
    status_note: nul(f.status_note),
    origin: f.origin,
    purchase_mode: f.purchase_mode,
    print_url: nul(f.print_url),
    painted_on: nul(f.painted_on),
    category_ids: [...f.category_ids].sort((a, b) => a - b),
  };
  if (f.slug.trim() !== '') payload.slug = f.slug.trim();

  return { payload, errors };
}

function Field({ label, error, children }) {
  return (
    <label className="mim-field">
      <span>{label}</span>
      {children}
      {error && <small className="mim-error">{ERRORS[error] ?? error}</small>}
    </label>
  );
}

export default function PieceForm({ meta }) {
  const { id } = useParams();
  const navigate = useNavigate();
  const location = useLocation();
  const isNew = !id;

  const [piece, setPiece] = useState(null);
  const [initial, setInitial] = useState(EMPTY);
  const [form, setForm] = useState(EMPTY);
  const [errs, setErrs] = useState({});
  const [msg, setMsg] = useState('');
  const [loadError, setLoadError] = useState('');
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const d = await api.get(`/pieces/${id}`);
      setPiece(d.piece);
      const f = toForm(d.piece);
      setInitial(f);
      setForm(f);
      setLoadError('');
    } catch (e) {
      setLoadError(e.status === 404 ? 'Piece not found.' : `Could not load piece (${e.status ?? 'network'}).`);
    }
  }, [id]);

  useEffect(() => {
    setErrs({});
    setMsg('');
    setLoadError('');
    if (isNew) {
      setPiece(null);
      setInitial(EMPTY);
      setForm(EMPTY);
    } else {
      load();
    }
  }, [isNew, load]);

  const set = (name) => (e) => setForm((f) => ({ ...f, [name]: e.target.value }));

  const toggleCategory = (cid) =>
    setForm((f) => ({
      ...f,
      category_ids: f.category_ids.includes(cid) ? f.category_ids.filter((c) => c !== cid) : [...f.category_ids, cid],
    }));

  const save = async (e) => {
    e.preventDefault();
    setMsg('');
    const { payload, errors } = toPayload(form);
    if (!payload.title) errors.title = 'required';
    if (!payload.canvas_size_id) errors.canvas_size_id = 'required';
    if (Object.keys(errors).length) {
      setErrs(errors);
      setMsg('Fix the highlighted fields.');
      return;
    }
    setErrs({});

    setBusy(true);
    try {
      if (isNew) {
        const d = await api.post('/pieces', payload);
        navigate(`/mimosa/pieces/${d.piece.id}`, { replace: true, state: { promptImage: true } });
        return;
      }

      const base = toPayload(initial).payload;
      const diff = {};
      for (const k of Object.keys(payload)) {
        if (JSON.stringify(payload[k]) !== JSON.stringify(base[k])) diff[k] = payload[k];
      }
      if (Object.keys(diff).length === 0) {
        setMsg('No changes.');
        return;
      }
      await api.patch(`/pieces/${id}`, diff);
      await load();
      setMsg('Saved.');
    } catch (err) {
      if (!(err instanceof ApiError)) {
        setMsg('Could not reach the server.');
      } else if (err.status === 422) {
        setErrs(err.data?.fields ?? {});
        setMsg('Fix the highlighted fields.');
      } else if (err.code === 'slug_taken') {
        setErrs({ slug: 'taken' });
        setMsg('Fix the highlighted fields.');
      } else if (err.code === 'piece_deleted') {
        setMsg('This piece is deleted. Restore it to edit.');
        await load();
      } else {
        setMsg(`Save failed (${err.status}).`);
      }
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    if (!window.confirm(`Delete "${piece.title}"? It can be restored later.`)) return;
    try {
      await api.del(`/pieces/${id}`);
      await load();
    } catch (err) {
      setMsg(`Delete failed (${err.status ?? 'network'}).`);
    }
  };

  const restore = async () => {
    try {
      await api.post(`/pieces/${id}/restore`, {});
      await load();
      setMsg('Restored.');
    } catch (err) {
      setMsg(`Restore failed (${err.status ?? 'network'}).`);
    }
  };

  if (loadError) {
    return (
      <section>
        <p className="mim-error">{loadError}</p>
        <Link to="/mimosa/pieces">Back to pieces</Link>
      </section>
    );
  }
  if (!isNew && !piece) return <p>Loading…</p>;

  const categories = meta?.categories ?? [];
  const parents = categories.filter((c) => !c.parent_id);
  const kids = (pid) => categories.filter((c) => c.parent_id === pid);
  const sizes = meta?.sizes ?? [];
  const deleted = !!piece?.deleted_at;

  const catBox = (c) => (
    <label key={c.id} className="mim-check">
      <input type="checkbox" checked={form.category_ids.includes(Number(c.id))} onChange={() => toggleCategory(Number(c.id))} /> {c.name}
    </label>
  );

  return (
    <section>
      <div className="mim-bar">
        <h2>{isNew ? 'New piece' : `${piece.title} #${piece.id}`}</h2>
        <Link to="/mimosa/pieces">Back to pieces</Link>
      </div>

      {deleted && (
        <p className="mim-banner">
          This piece is deleted.{' '}
          <button type="button" className="mim-link" onClick={restore}>Restore</button>
        </p>
      )}

      <form onSubmit={save} className="mim-form">
        <fieldset disabled={deleted || busy}>
          <Field label="Title" error={errs.title}>
            <input value={form.title} onChange={set('title')} maxLength={200} required />
          </Field>

          <Field label={isNew ? 'Slug (optional, generated from title)' : 'Slug'} error={errs.slug}>
            <input value={form.slug} onChange={set('slug')} maxLength={120} />
          </Field>

          <Field label="Description" error={errs.description}>
            <textarea rows="5" value={form.description} onChange={set('description')} />
          </Field>

          <div className="mim-grid">
            <Field label="Medium" error={errs.medium}>
              <input value={form.medium} onChange={set('medium')} maxLength={100} />
            </Field>

            <Field label="Size" error={errs.canvas_size_id}>
              <select value={form.canvas_size_id} onChange={set('canvas_size_id')} required>
                <option value="">Choose…</option>
                <optgroup label="Offered">
                  {sizes.filter((s) => s.offered).map((s) => (
                    <option key={s.id} value={s.id}>{s.label}</option>
                  ))}
                </optgroup>
                <optgroup label="Retired">
                  {sizes.filter((s) => !s.offered).map((s) => (
                    <option key={s.id} value={s.id}>{s.label}</option>
                  ))}
                </optgroup>
              </select>
            </Field>

            <Field label="Status" error={errs.status}>
              <select value={form.status} onChange={set('status')}>
                {(meta?.statuses ?? []).map((s) => (
                  <option key={s} value={s}>{s}</option>
                ))}
              </select>
            </Field>

            <Field label="Origin" error={errs.origin}>
              <select value={form.origin} onChange={set('origin')}>
                {(meta?.origins ?? []).map((s) => (
                  <option key={s} value={s}>{s}</option>
                ))}
              </select>
            </Field>

            <Field label="Purchase mode" error={errs.purchase_mode}>
              <select value={form.purchase_mode} onChange={set('purchase_mode')}>
                {(meta?.purchase_modes ?? []).map((s) => (
                  <option key={s} value={s}>{s}</option>
                ))}
              </select>
            </Field>

            <Field label="Painted on" error={errs.painted_on}>
              <input type="date" value={form.painted_on} onChange={set('painted_on')} />
            </Field>

            <Field label="Price ($)" error={errs.price_cents}>
              <input inputMode="decimal" placeholder="0.00" value={form.price} onChange={set('price')} />
            </Field>

            <Field label="Sale price ($)" error={errs.sale_price_cents}>
              <input inputMode="decimal" placeholder="0.00" value={form.sale_price} onChange={set('sale_price')} />
            </Field>

            <Field label="Sale ends" error={errs.sale_ends_at}>
              <input type="datetime-local" value={form.sale_ends_at} onChange={set('sale_ends_at')} />
            </Field>
          </div>

          <Field label="Status note" error={errs.status_note}>
            <input value={form.status_note} onChange={set('status_note')} maxLength={255} />
          </Field>

          <Field label="Print URL" error={errs.print_url}>
            <input type="url" value={form.print_url} onChange={set('print_url')} maxLength={500} />
          </Field>

          <div className="mim-field">
            <span>Categories</span>
            {parents.map((c) => (
              <div key={c.id}>
                {catBox(c)}
                {kids(c.id).length > 0 && <div className="mim-nested">{kids(c.id).map(catBox)}</div>}
              </div>
            ))}
            {errs.category_ids && <small className="mim-error">{ERRORS[errs.category_ids] ?? errs.category_ids}</small>}
          </div>
        </fieldset>

        {msg && <p className={msg === 'Saved.' || msg === 'Restored.' ? 'mim-ok' : 'mim-error'} role="status">{msg}</p>}

        <div className="mim-bar">
          <button type="submit" disabled={deleted || busy}>{isNew ? 'Create piece' : 'Save changes'}</button>
          {!isNew && !deleted && (
            <button type="button" className="mim-link" onClick={remove}>Delete piece</button>
          )}
        </div>
      </form>

      {!isNew && <ImageManager pieceId={piece.id} deleted={deleted} prompt={!!location.state?.promptImage} />}
    </section>
  );
}

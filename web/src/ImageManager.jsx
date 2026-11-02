import { useCallback, useEffect, useState } from 'react';
import { api, ApiError } from './api.js';
import WatermarkEditor from './WatermarkEditor.jsx';

const MESSAGES = {
  no_file: 'No file was received.',
  upload_failed: 'The upload failed. Try again.',
  unsupported_type: 'Only JPEG and PNG files can be used.',
  dimensions_too_large: 'That image is too many pixels for the server to process. Resize it first.',
  gd_unavailable: 'The server cannot process images (GD is not available).',
  logo_missing: 'That logo file is not on the server.',
  logo_unreadable: 'The logo file on the server could not be read.',
  invalid_watermark: 'The watermark settings were rejected.',
  piece_deleted: 'This piece is deleted. Restore it first.',
  file_missing: 'The image file is missing on the server.',
  source_missing: 'The stored source for this image is missing.',
  not_watermarked: 'This image has no watermark to remove.',
  write_failed: 'The server could not write the file.',
  storage_unavailable: 'The server storage folder is not writable.',
  not_found: 'Not found.',
  confirm_required: 'Confirm first that this image has no watermark yet.',
};

const mb = (n) => `${(n / 1048576).toFixed(n >= 10485760 ? 0 : 1)} MB`;

function describe(err, limit) {
  if (!(err instanceof ApiError)) return 'Could not reach the server.';
  if (err.code === 'too_large') return `That file is too large${limit ? ` (limit ${mb(limit)})` : ''}.`;
  if (err.code === 'validation') return `Rejected: ${JSON.stringify(err.data?.fields ?? {})}`;
  return MESSAGES[err.code] ?? `Something went wrong (${err.status}).`;
}

export default function ImageManager({ pieceId, deleted, prompt }) {
  const [images, setImages] = useState(null);
  const [cfg, setCfg] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [bust, setBust] = useState(() => Date.now());
  const [editor, setEditor] = useState(null);

  const limit = cfg ? Math.min(...[cfg.limits.upload_bytes, cfg.limits.post_bytes, cfg.limits.app_bytes].filter((n) => n)) : null;

  const load = useCallback(async () => {
    try {
      const [c, list] = await Promise.all([api.get('/images/config'), api.get(`/pieces/${pieceId}/images`)]);
      setCfg(c);
      setImages(list.images);
      setError('');
    } catch (e) {
      setError(describe(e));
    }
  }, [pieceId]);

  useEffect(() => {
    load();
  }, [load]);

  const previewUrl = editor?.url;
  useEffect(
    () => () => {
      if (previewUrl) URL.revokeObjectURL(previewUrl);
    },
    [previewUrl],
  );

  const pick = (e) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    if (limit && file.size > limit) {
      setError(`That file is too large (${mb(file.size)}; limit ${mb(limit)}).`);
      return;
    }
    setError('');
    setEditor({ mode: 'new', file, url: URL.createObjectURL(file), kind: images.length === 0 ? 'main' : 'angle', alt: '' });
  };

  const run = async (fn) => {
    setBusy(true);
    setError('');
    try {
      await fn();
      setBust(Date.now());
      setEditor(null);
      await load();
    } catch (e) {
      setError(describe(e, limit));
    } finally {
      setBusy(false);
    }
  };

  const upload = (params) =>
    run(() => {
      const form = new FormData();
      form.append('file', editor.file);
      form.append('kind', editor.kind);
      if (editor.alt.trim()) form.append('alt_text', editor.alt.trim());
      form.append('watermark', params ? JSON.stringify(params) : 'none');

      return api.post(`/pieces/${pieceId}/images`, form);
    });

  const apply = (params) =>
    run(() => api.post(`/images/${editor.image.id}/watermark`, editor.image.tracked ? params : { ...params, confirm_clean: true }));

  const remove = (image) => {
    if (!window.confirm('Remove the watermark and restore the unwatermarked image?')) return;
    run(() => api.del(`/images/${image.id}/watermark`));
  };

  if (!images || !cfg) {
    return (
      <div className="mim-images">
        <h3>Images</h3>
        {error ? <p className="mim-error">{error}</p> : <p>Loading…</p>}
      </div>
    );
  }

  return (
    <div className="mim-images">
      <h3>Images</h3>

      {prompt && images.length === 0 && !editor && (
        <p className="mim-banner">Piece created. Add its main image below. You can place a watermark before it uploads.</p>
      )}
      {error && <p className="mim-error" role="alert">{error}</p>}

      {editor?.mode === 'new' && (
        <>
          <div className="mim-grid">
            <label className="mim-field">
              <span>Kind</span>
              <select value={editor.kind} onChange={(e) => setEditor({ ...editor, kind: e.target.value })}>
                {cfg.kinds.map((k) => (
                  <option key={k} value={k}>{k}</option>
                ))}
              </select>
            </label>
            <label className="mim-field">
              <span>Alt text</span>
              <input value={editor.alt} maxLength={255} onChange={(e) => setEditor({ ...editor, alt: e.target.value })} />
            </label>
          </div>
          <p className="mim-note">The upload is shrunk to {cfg.max_side}px on its longest side. The original is kept privately.</p>
          <WatermarkEditor
            src={editor.url}
            logos={cfg.logos}
            initial={null}
            busy={busy}
            confirmLabel="Upload with watermark"
            secondaryLabel="Upload without watermark"
            onConfirm={upload}
            onSecondary={() => upload(null)}
            onCancel={() => setEditor(null)}
          />
        </>
      )}

      {editor?.mode === 'existing' && !editor.confirmed && (
        <div className="mim-wm-editor">
          <p className="mim-banner">
            This is an older image the system hasn't processed before, so it may already carry a watermark. Look at it below. Only continue if it has no watermark yet, or the new one would be stacked on top of the old one.
          </p>
          <img className="mim-wm-base" src={`/media/pieces/${editor.image.filename}?v=${bust}`} alt={editor.image.alt_text ?? ''} />
          <div className="mim-bar">
            <button type="button" onClick={() => setEditor({ ...editor, confirmed: true })}>It has no watermark. Continue</button>
            <button type="button" className="mim-link" onClick={() => setEditor(null)}>Cancel</button>
          </div>
        </div>
      )}

      {editor?.mode === 'existing' && editor.confirmed && (
        <>
          <p className="mim-note">
            The watermark is applied to the stored unwatermarked copy, so applying it again never stacks. The image keeps its filename.
          </p>
          <WatermarkEditor
            src={`/api/mimosa/images/${editor.image.id}/base?v=${bust}`}
            logos={cfg.logos}
            initial={editor.image.watermark}
            busy={busy}
            confirmLabel="Apply watermark"
            onConfirm={apply}
            onCancel={() => setEditor(null)}
          />
        </>
      )}

      {!editor && (
        <>
          {images.length === 0 && <p>No images.</p>}
          <ul>
            {images.map((im) => (
              <li key={im.id}>
                <img src={`/media/pieces/${im.filename}?v=${bust}`} alt={im.alt_text ?? ''} loading="lazy" />
                <small>{im.kind} · {!im.tracked ? 'older image, watermark unknown' : im.watermark ? 'watermarked' : 'no watermark'}</small>
                {!deleted && (
                  <span className="mim-actions">
                    <button type="button" className="mim-link" onClick={() => setEditor({ mode: 'existing', image: im, confirmed: im.tracked })} disabled={busy}>Watermark…</button>
                    {im.watermark && (
                      <button type="button" className="mim-link" onClick={() => remove(im)} disabled={busy}>Remove</button>
                    )}
                  </span>
                )}
              </li>
            ))}
          </ul>

          {!deleted && (
            <label className="mim-field">
              <span>Add image (JPEG or PNG{limit ? `, up to ${mb(limit)}` : ''})</span>
              <input type="file" accept="image/jpeg,image/png" onChange={pick} disabled={busy} />
            </label>
          )}
        </>
      )}
    </div>
  );
}

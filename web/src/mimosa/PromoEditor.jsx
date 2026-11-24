import { useEffect, useState } from 'react';
import { api } from './api.js';

const EMPTY = { heading: '', text: '', image_url: null, image_alt: '' };

export default function PromoEditor() {
  const [promo, setPromo] = useState(EMPTY);
  const [heading, setHeading] = useState('');
  const [text, setText] = useState('');
  const [imageAlt, setImageAlt] = useState('');
  const [file, setFile] = useState(null);
  const [clearImage, setClearImage] = useState(false);
  const [state, setState] = useState('loading');

  useEffect(() => {
    api.get('/promo').then((data) => {
      const current = data.promo ?? EMPTY;
      setPromo(current);
      setHeading(current.heading);
      setText(current.text);
      setImageAlt(current.image_alt ?? '');
      setState('ready');
    }).catch((error) => {
      setState(`Could not load promo (${error.status ?? 'network'}).`);
    });
  }, []);

  const save = async (event) => {
    event.preventDefault();
    setState('saving');
    const form = new FormData();
    form.append('heading', heading);
    form.append('text', text);
    form.append('image_alt', imageAlt);
    if (file) {
      form.append('file', file);
      form.append('file_expected', '1');
    }
    if (clearImage) form.append('clear_image', '1');

    try {
      const data = await api.post('/promo', form);
      if (file && !data.promo?.image_url) {
        setState('Could not save promo (image_missing).');
        return;
      }
      setPromo(data.promo ?? EMPTY);
      setHeading(data.promo?.heading ?? '');
      setText(data.promo?.text ?? '');
      setImageAlt(data.promo?.image_alt ?? '');
      setFile(null);
      setClearImage(false);
      setState('saved');
    } catch (error) {
      const detail = error.code ?? error.status ?? 'network';
      setState(`Could not save promo (${detail}).`);
    }
  };

  return (
    <section className="mim-panel">
      <h3>Homepage promo</h3>
      <form className="mim-form mim-promo-form" onSubmit={save}>
        <label>
          Image (JPEG or PNG)
          <input
            type="file"
            accept="image/jpeg,image/png"
            onChange={(event) => {
              setFile(event.target.files?.[0] ?? null);
              setClearImage(false);
              setState('ready');
            }}
          />
        </label>
        <label>
          Image alt text
          <input
            type="text"
            maxLength={255}
            value={imageAlt}
            onChange={(event) => { setImageAlt(event.target.value); setState('ready'); }}
            disabled={!promo.image_url && !file}
          />
        </label>
        {promo.image_url && !file && (
          <label className="mim-promo-clear">
            <input
              type="checkbox"
              checked={clearImage}
              onChange={(event) => {
                setClearImage(event.target.checked);
                setState('ready');
              }}
            />
            Remove current image
          </label>
        )}
        <label>
          Heading
          <input
            type="text"
            maxLength={120}
            value={heading}
            onChange={(event) => { setHeading(event.target.value); setState('ready'); }}
          />
        </label>
        <label>
          Text
          <textarea
            maxLength={2000}
            rows={5}
            value={text}
            onChange={(event) => { setText(event.target.value); setState('ready'); }}
          />
        </label>
        <div className="mim-promo-actions">
          <button type="submit" disabled={state === 'loading' || state === 'saving'}>
            {state === 'saving' ? 'Saving…' : 'Save promo'}
          </button>
          {state === 'saved' && <span className="mim-ok" role="status">Saved</span>}
          {state !== 'saved' && state !== 'ready' && state !== 'loading' && state !== 'saving' && <span className="mim-error" role="alert">{state}</span>}
        </div>
      </form>
    </section>
  );
}

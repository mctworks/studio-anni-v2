import { useCallback, useEffect, useRef, useState } from 'react';
import { gsap } from 'gsap';
import { Draggable } from 'gsap/Draggable';

gsap.registerPlugin(Draggable);

const MIN_WIDTH = 0.02;
const clamp = (n, lo, hi) => Math.min(hi, Math.max(lo, n));
const round4 = (n) => Math.round(n * 10000) / 10000;

export default function WatermarkEditor({ src, logos, initial, confirmLabel, secondaryLabel, busy, onConfirm, onSecondary, onCancel }) {
  const boxRef = useRef(null);
  const markRef = useRef(null);
  const logoRef = useRef(null);
  const baseRef = useRef(null);
  const sizeRef = useRef({ w: 1, h: 1 });

  const [logo, setLogo] = useState(initial?.logo && logos.includes(initial.logo) ? initial.logo : logos.includes('light') ? 'light' : logos[0]);
  const [opacity, setOpacity] = useState(initial?.opacity ?? 0.6);
  const [pos, setPos] = useState(initial ? { x: initial.x, y: initial.y, width: initial.width } : null);
  const posRef = useRef(pos);
  posRef.current = pos;

  const measure = useCallback(() => {
    const box = boxRef.current;
    if (box) sizeRef.current = { w: box.clientWidth || 1, h: box.clientHeight || 1 };
  }, []);

  const place = useCallback(() => {
    const logoEl = logoRef.current;
    const baseEl = baseRef.current;
    if (!logoEl || !logoEl.naturalWidth || !baseEl || !baseEl.naturalWidth || posRef.current) return;
    measure();
    const { w, h } = sizeRef.current;
    const width = 0.25;
    const heightFrac = (width * w * logoEl.naturalHeight) / logoEl.naturalWidth / h;
    setPos({ width, x: round4(1 - width - 0.03), y: round4(clamp(1 - heightFrac - 0.03, 0, 1)) });
  }, [measure]);

  useEffect(() => {
    if (logoRef.current?.complete) place();
  }, [logo, place]);

  useEffect(() => {
    const box = boxRef.current;
    const mark = markRef.current;
    const trigger = logoRef.current;
    if (!box || !mark || !trigger || !pos) return undefined;

    const [drag] = Draggable.create(mark, {
      type: 'left,top',
      bounds: box,
      trigger,
      onPress() {
        measure();
        this.applyBounds(box);
      },
      onDragEnd() {
        const { w, h } = sizeRef.current;
        const x = round4(clamp(mark.offsetLeft / w, 0, 1));
        const y = round4(clamp(mark.offsetTop / h, 0, 1));
        gsap.set(mark, { left: `${x * 100}%`, top: `${y * 100}%` });
        setPos((p) => ({ ...p, x, y }));
      },
    });

    return () => drag.kill();
  }, [pos === null, logo, measure]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!pos || !markRef.current) return;
    gsap.set(markRef.current, { left: `${pos.x * 100}%`, top: `${pos.y * 100}%`, width: `${pos.width * 100}%` });
  }, [pos]);

  const startResize = (e) => {
    e.preventDefault();
    e.stopPropagation();
    measure();
    const target = e.currentTarget;
    target.setPointerCapture(e.pointerId);
    const startX = e.clientX;
    const start = posRef.current;
    const { w, h } = sizeRef.current;
    const logoEl = logoRef.current;
    const aspect = logoEl.naturalHeight / logoEl.naturalWidth;
    const maxWidth = Math.max(MIN_WIDTH, Math.min(1 - start.x, ((1 - start.y) * h) / (aspect * w)));

    const move = (ev) => {
      const width = round4(clamp(start.width + (ev.clientX - startX) / w, MIN_WIDTH, maxWidth));
      setPos({ ...posRef.current, width });
    };
    const end = () => {
      target.removeEventListener('pointermove', move);
      target.removeEventListener('pointerup', end);
      target.removeEventListener('pointercancel', end);
    };
    target.addEventListener('pointermove', move);
    target.addEventListener('pointerup', end);
    target.addEventListener('pointercancel', end);
  };

  if (logos.length === 0) {
    return (
      <div className="mim-wm-editor">
        <p className="mim-error">No watermark logos on the server. Add dark.png and light.png to storage/watermarks/.</p>
        <div className="mim-bar">
          {secondaryLabel && <button type="button" onClick={onSecondary} disabled={busy}>{secondaryLabel}</button>}
          <button type="button" className="mim-link" onClick={onCancel}>Cancel</button>
        </div>
      </div>
    );
  }

  const params = pos ? { logo, x: pos.x, y: pos.y, width: pos.width, opacity: round4(opacity) } : null;

  return (
    <div className="mim-wm-editor">
      <div className="mim-wm-stage">
        <div className="mim-wm-box" ref={boxRef}>
          <img
            ref={baseRef}
            src={src}
            alt=""
            className="mim-wm-base"
            draggable="false"
            onLoad={() => {
              measure();
              place();
            }}
          />
          <div className="mim-wm-mark" ref={markRef}>
            <img
              ref={logoRef}
              style={{ opacity }}
              src={`/api/mimosa/watermarks/${logo}`}
              alt="Watermark"
              draggable="false"
              onLoad={place}
            />
            <span className="mim-wm-handle" onPointerDown={startResize} role="presentation" />
          </div>
        </div>
      </div>

      <div className="mim-wm-controls">
        <fieldset className="mim-wm-logos">
          <legend>Logo</legend>
          {logos.map((v) => (
            <label key={v} className="mim-check">
              <input type="radio" name="wm-logo" checked={logo === v} onChange={() => setLogo(v)} /> {v}
            </label>
          ))}
        </fieldset>

        <label className="mim-field">
          <span>Opacity: {Math.round(opacity * 100)}%</span>
          <input type="range" min="5" max="100" step="1" value={Math.round(opacity * 100)} onChange={(e) => setOpacity(Number(e.target.value) / 100)} />
        </label>

        <p className="mim-note">Drag the logo to move it. Drag the corner square to resize it.</p>

        <div className="mim-bar">
          <button type="button" disabled={!params || busy} onClick={() => onConfirm(params)}>{confirmLabel}</button>
          {secondaryLabel && <button type="button" disabled={busy} onClick={onSecondary}>{secondaryLabel}</button>}
          <button type="button" className="mim-link" onClick={onCancel} disabled={busy}>Cancel</button>
        </div>
      </div>
    </div>
  );
}

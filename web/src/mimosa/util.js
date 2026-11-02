export function centsToDollars(cents) {
  const c = Number(cents) || 0;
  return `${Math.floor(c / 100)}.${String(c % 100).padStart(2, '0')}`;
}

export function dollarsToCents(input) {
  const s = String(input).trim().replace(/^\$/, '').replace(/,/g, '');
  const m = /^(\d{1,7})(?:\.(\d{1,2}))?$/.exec(s);
  if (!m) return null;
  return Number(m[1]) * 100 + Number((m[2] ?? '').padEnd(2, '0') || 0);
}

export function utcToLocalInput(value) {
  if (!value) return '';
  const d = new Date(String(value).replace(' ', 'T') + 'Z');
  if (Number.isNaN(d.getTime())) return '';
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

export function localInputToIso(value) {
  if (!value) return null;
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return null;
  return d.toISOString().replace(/\.\d{3}Z$/, '+00:00');
}

export function imageSrc(img) {
  if (!img) return null;
  if (typeof img === 'string') return `/media/pieces/${img}`;
  if (img.url) return img.url;
  if (img.filename) return `/media/pieces/${img.filename}`;
  return null;
}

export function formatDate(value) {
  if (!value) return '';
  const d = new Date(String(value).replace(' ', 'T') + 'Z');
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString();
}

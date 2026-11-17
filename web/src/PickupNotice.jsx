import { useEffect, useState } from 'react';

export const PICKUP_ZIP = '30047';
export const PICKUP_MILES = 20;
const STUDIO = { lat: 33.8898, lon: -84.1402 };
const cache = new Map();

const distance = (lat, lon) => {
  const rad = (d) => (d * Math.PI) / 180;
  const a = Math.sin(rad(lat - STUDIO.lat) / 2) ** 2 + Math.cos(rad(STUDIO.lat)) * Math.cos(rad(lat)) * Math.sin(rad(lon - STUDIO.lon) / 2) ** 2;
  return 3958.8 * 2 * Math.asin(Math.sqrt(a));
};

export function useFarFromStudio(zip) {
  const five = /^(\d{5})(-\d{4})?$/.exec(String(zip || '').trim())?.[1] ?? null;
  const [miles, setMiles] = useState(null);

  useEffect(() => {
    setMiles(null);
    if (!five) return undefined;
    if (cache.has(five)) {
      setMiles(cache.get(five));
      return undefined;
    }
    const controller = new AbortController();
    fetch(`https://api.zippopotam.us/us/${five}`, { signal: controller.signal })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error('zip'))))
      .then((d) => {
        const lat = Number(d.places?.[0]?.latitude);
        const lon = Number(d.places?.[0]?.longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;
        const m = distance(lat, lon);
        cache.set(five, m);
        setMiles(m);
      })
      .catch(() => {});
    return () => controller.abort();
  }, [five]);

  return { miles, far: miles !== null && miles > PICKUP_MILES };
}

export function PickupNotice({ zip }) {
  const { miles, far } = useFarFromStudio(zip);

  return (
    <div role="status">
      <p className="crf-note">Local pickup replaces shipping, so no shipping fee applies. Mention your availability and preferred contact method in your description.</p>
      {far && <p className="crf-note">That ZIP is about {Math.round(miles)} miles from the studio ({PICKUP_ZIP}). You're still welcome to arrange pickup!</p>}
    </div>
  );
}

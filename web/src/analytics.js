// Matomo, loaded only when configured at build time (web/.env.production):
//   VITE_MATOMO_URL=https://YOUR-SUBDOMAIN.example.com/   (tracker base, trailing slash)
//   VITE_MATOMO_SITE_ID=1
//   VITE_MATOMO_SCRIPT_URL=...  (optional; defaults to <VITE_MATOMO_URL>matomo.js)
// Cookieless and Do-Not-Track aware, so no consent banner is needed. Staff (/mimosa) pages are never tracked.
const base = import.meta.env.VITE_MATOMO_URL;
const siteId = import.meta.env.VITE_MATOMO_SITE_ID;
const scriptUrl = import.meta.env.VITE_MATOMO_SCRIPT_URL || (base ? `${base.replace(/\/?$/, '/')}matomo.js` : '');

const enabled = import.meta.env.PROD && Boolean(base && siteId);
let started = false;

function queue(...args) {
  (window._paq = window._paq || []).push(args);
}

function init() {
  if (started || !enabled) return;
  started = true;
  queue('disableCookies');
  queue('setDoNotTrack', true);
  queue('setTrackerUrl', `${base.replace(/\/?$/, '/')}matomo.php`);
  queue('setSiteId', siteId);
  const s = document.createElement('script');
  s.async = true;
  s.src = scriptUrl;
  document.head.appendChild(s);
}

let lastUrl = null;

export function trackPageView(pathname, search = '') {
  if (!enabled || pathname.startsWith('/mimosa')) return;
  init();
  const url = window.location.origin + pathname + search;
  if (lastUrl) {
    queue('setReferrerUrl', lastUrl);
    queue('setCustomUrl', url);
    queue('setDocumentTitle', document.title);
  }
  queue('trackPageView');
  lastUrl = url;
}

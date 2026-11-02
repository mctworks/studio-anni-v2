const BASE = '/api/mimosa';

let csrf = null;
let onUnauthorized = null;

export class ApiError extends Error {
  constructor(status, data) {
    super(data?.error || `HTTP ${status}`);
    this.status = status;
    this.code = data?.error ?? null;
    this.data = data;
  }
}

export const setUnauthorizedHandler = (fn) => {
  onUnauthorized = fn;
};

export const clearCsrf = () => {
  csrf = null;
};

async function send(method, path, body, headers = {}) {
  const form = typeof FormData !== 'undefined' && body instanceof FormData;
  const res = await fetch(BASE + path, {
    method,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(body !== undefined && !form ? { 'Content-Type': 'application/json' } : {}),
      ...headers,
    },
    body: body === undefined ? undefined : form ? body : JSON.stringify(body),
  });

  let data = null;
  if (res.status !== 204) {
    try {
      data = await res.json();
    } catch {
      data = null;
    }
  }
  if (data && typeof data.csrfToken === 'string') csrf = data.csrfToken;

  return { res, data };
}

async function refreshCsrf() {
  const { res, data } = await send('GET', '/csrf');
  if (!res.ok || !csrf) throw new ApiError(res.status, data);
}

async function request(method, path, body) {
  const write = method !== 'GET';
  if (write && !csrf) await refreshCsrf();

  let { res, data } = await send(method, path, body, write ? { 'X-CSRF-Token': csrf } : {});

  if (write && res.status === 403 && data?.error === 'csrf') {
    await refreshCsrf();
    ({ res, data } = await send(method, path, body, { 'X-CSRF-Token': csrf }));
  }

  if (!res.ok) {
    if (res.status === 401 && data?.error === 'unauthenticated' && onUnauthorized) onUnauthorized();
    throw new ApiError(res.status, data);
  }

  return data;
}

export const api = {
  get: (path) => request('GET', path),
  post: (path, body) => request('POST', path, body),
  patch: (path, body) => request('PATCH', path, body),
  del: (path) => request('DELETE', path),
};

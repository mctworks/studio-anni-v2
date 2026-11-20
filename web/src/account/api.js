let csrf = null;

async function request(path, method, body, retried) {
  if (method !== 'GET' && !csrf) {
    const r = await fetch('/api/account/csrf', { credentials: 'same-origin' });
    if (r.ok) csrf = (await r.json()).csrfToken;
  }
  const headers = {};
  const form = typeof FormData !== 'undefined' && body instanceof FormData;
  if (body !== undefined && !form) headers['Content-Type'] = 'application/json';
  if (csrf) headers['X-CSRF-Token'] = csrf;
  const res = await fetch(`/api/account${path}`, {
    method,
    credentials: 'same-origin',
    headers,
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
  if (data?.csrfToken) csrf = data.csrfToken;
  if (res.status === 403 && data?.error === 'csrf' && !retried) {
    csrf = null;
    return request(path, method, body, true);
  }
  return { ok: res.ok, status: res.status, data };
}

export const account = {
  me: () => request('/me', 'GET'),
  totp: (body) => request('/login/totp', 'POST', body),
  profile: () => request('/profile', 'GET'),
  addresses: () => request('/addresses', 'GET'),
  createAddress: (body) => request('/addresses', 'POST', body),
  updateAddress: (id, body) => request(`/addresses/${id}`, 'PATCH', body),
  deleteAddress: (id) => request(`/addresses/${id}`, 'DELETE'),
  forgot: (email) => request('/password/forgot', 'POST', { email }),
  resetPassword: (token, password) => request('/password/reset', 'POST', { token, password }),
  uploadAvatar: (file) => {
    const f = new FormData();
    f.append('file', file);
    return request('/profile/avatar', 'POST', f);
  },
  removeAvatar: () => request('/profile/avatar', 'DELETE'),
  commissions: () => request('/commissions', 'GET'),
  commission: (id) => request(`/commissions/${id}`, 'GET'),
  requestCommission: (formData) => request('/commissions', 'POST', formData),
  replyCommission: (id, message) => request(`/commissions/${id}/reply`, 'POST', { message }),
  cart: () => request('/cart', 'GET'),
  addToCart: (pieceId) => request('/cart', 'POST', { piece_id: pieceId }),
  removeFromCart: (pieceId) => request(`/cart/${pieceId}`, 'DELETE'),
  checkoutQuote: (body) => request('/checkout/quote', 'POST', body),
  checkoutPay: (body) => request('/checkout/pay', 'POST', body),
  finalizePayment: (paymentId) => request('/checkout/finalize', 'POST', { payment_id: paymentId }),
  commissionPayment: (id) => request(`/commissions/${id}/payment`, 'GET'),
  commissionQuote: (id, body) => request(`/commissions/${id}/payment/quote`, 'POST', body),
  commissionPay: (id, body) => request(`/commissions/${id}/payment/pay`, 'POST', body),
  createProfile: (fields) => request('/profile', 'POST', fields),
  updateProfile: (fields) => request('/profile', 'PATCH', fields),
  register: (fields) => request('/register', 'POST', fields),
  login: (email, password) => request('/login', 'POST', { email, password }),
  verify: (code) => request('/verify', 'POST', { code }),
  loginCode: (code, trustDevice) => request('/login/code', 'POST', { code, trustDevice }),
  resend: () => request('/verify/resend', 'POST', {}),
  logout: async () => {
    const r = await request('/logout', 'POST', {});
    csrf = null;
    return r;
  },
};

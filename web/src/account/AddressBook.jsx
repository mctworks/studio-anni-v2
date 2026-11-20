import { useEffect, useState } from 'react';
import { account } from './api.js';

const emptyAddress = {
  label: '',
  recipient: '',
  line1: '',
  line2: '',
  city: '',
  region: '',
  postal_code: '',
  phone: '',
  isDefault: false,
};

const fields = [
  ['label', 'Label (optional)', 40],
  ['recipient', 'Recipient', 120],
  ['line1', 'Street address', 120],
  ['line2', 'Apt, suite, etc. (optional)', 120],
  ['city', 'City', 80],
  ['region', 'State (2 letters)', 2],
  ['postal_code', 'ZIP code', 10],
  ['phone', 'Phone (optional)', 30],
];

const displayAddress = (address) => [address.line1, address.line2, `${address.city}, ${address.region} ${address.postal_code}`].filter(Boolean).join(', ');

export default function AddressBook() {
  const [addresses, setAddresses] = useState([]);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyAddress);
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);

  const load = async () => {
    const r = await account.addresses();
    if (r.ok) setAddresses(r.data.addresses);
  };

  useEffect(() => {
    load();
  }, []);

  const start = (address = null) => {
    setEditing(address?.id ?? 'new');
    setForm(address ? { ...address } : { ...emptyAddress, isDefault: addresses.length === 0 });
    setErrors({});
    setMessage('');
  };

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setMessage('');
    const r = editing === 'new'
      ? await account.createAddress(form)
      : await account.updateAddress(editing, form);
    setBusy(false);
    if (r.status === 422) {
      setErrors(r.data?.fields ?? {});
      return;
    }
    if (!r.ok) {
      setMessage('Could not save this address. Please try again.');
      return;
    }
    await load();
    setEditing(null);
    setMessage('Address saved.');
  };

  const remove = async (id) => {
    setBusy(true);
    const r = await account.deleteAddress(id);
    setBusy(false);
    if (r.ok) {
      await load();
      setMessage('Address removed.');
    } else {
      setMessage('Could not remove this address. Please try again.');
    }
  };

  return (
    <section className="account-addresses">
      <h2>Saved shipping addresses</h2>
      {addresses.length === 0 && <p>No saved addresses yet.</p>}
      <ul className="account-address-list">
        {addresses.map((address) => (
          <li key={address.id}>
            <div>
              <strong>{address.label || address.recipient}</strong>
              {address.isDefault && <span className="account-note"> · Default</span>}
              <p>{address.recipient}<br />{displayAddress(address)}</p>
            </div>
            <div className="account-address-actions">
              <button className="account-link" type="button" disabled={busy} onClick={() => start(address)}>Edit</button>
              <button className="account-link" type="button" disabled={busy} onClick={() => remove(address.id)}>Remove</button>
            </div>
          </li>
        ))}
      </ul>
      {editing === null && <button className="page-nav-button" type="button" onClick={() => start()}>Add address</button>}
      {editing !== null && (
        <form className="account-form account-address-form" onSubmit={submit}>
          <h3>{editing === 'new' ? 'Add shipping address' : 'Edit shipping address'}</h3>
          {fields.map(([name, label, maxLength]) => (
            <label className="account-field" key={name}>
              {label}
              <input
                name={name}
                value={form[name] ?? ''}
                maxLength={maxLength}
                autoComplete={{ recipient: 'name', line1: 'address-line1', line2: 'address-line2', city: 'address-level2', region: 'address-level1', postal_code: 'postal-code', phone: 'tel' }[name]}
                required={!['label', 'line2', 'phone'].includes(name)}
                aria-invalid={errors[name] ? 'true' : undefined}
                onChange={(e) => setForm({ ...form, [name]: e.target.value })}
              />
              {errors[name] && <small className="account-error">{errors[name] === 'required' ? 'Required.' : 'Check this field.'}</small>}
            </label>
          ))}
          <label className="account-check"><input type="checkbox" checked={form.isDefault} onChange={(e) => setForm({ ...form, isDefault: e.target.checked })} /> Make this my default address</label>
          {message && <small role="status">{message}</small>}
          <div className="account-address-actions">
            <button className="page-nav-button" type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save address'}</button>
            <button className="account-link" type="button" disabled={busy} onClick={() => setEditing(null)}>Cancel</button>
          </div>
        </form>
      )}
      {editing === null && message && <p role="status">{message}</p>}
    </section>
  );
}
const fields = [
  ['recipient', 'Full name', 'name'],
  ['line1', 'Street address', 'address-line1'],
  ['line2', 'Apt, suite, etc. (optional)', 'address-line2'],
  ['city', 'City', 'address-level2'],
  ['region', 'State (2 letters)', 'address-level1'],
  ['postal_code', 'ZIP code', 'postal-code'],
];

export default function AddressFields({ value, onChange, errors = {} }) {
  return (
    <>
      {fields.map(([key, label, auto]) => (
        <label className="account-field" key={key}>
          {label}
          <input
            value={value[key] ?? ''}
            autoComplete={auto}
            maxLength={key === 'region' ? 2 : key === 'postal_code' ? 10 : 120}
            required={key !== 'line2'}
            aria-invalid={errors[key] ? 'true' : undefined}
            onChange={(e) => onChange({ ...value, [key]: e.target.value })}
          />
          {errors[key] && <small className="account-error" role="alert">{errors[key] === 'required' ? 'Required' : 'Check this field'}</small>}
        </label>
      ))}
    </>
  );
}

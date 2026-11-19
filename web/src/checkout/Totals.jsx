import { money } from '../commissionStatus.js';

export default function Totals({ rows, total }) {
  return (
    <dl className="checkout-totals">
      {rows.map(([label, cents]) => (
        <div key={label}><dt>{label}</dt><dd>{money(cents)}</dd></div>
      ))}
      <div className="checkout-totals__total"><dt>Total</dt><dd>{money(total)}</dd></div>
    </dl>
  );
}

import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useAuth } from '../AuthContext.jsx';
import { account } from '../account/api.js';
import AddressFields from './AddressFields.jsx';
import PayForm from './PayForm.jsx';
import Totals from './Totals.jsx';
import { money } from '../commissionStatus.js';
import '../account/account.css';
import './checkout.css';

const labels = { deposit: 'Deposit', full: 'Pay in full', balance: 'Final payment' };
const errorText = {
  not_payable: 'Nothing is due on this commission right now.',
  shipping_unavailable: 'We could not price shipping online. Please contact us and we will sort it out.',
  tax_unavailable: 'We could not calculate sales tax right now. Please try again in a few minutes.',
  rate_limited: 'Too many attempts. Please wait a few minutes.',
  payment_unavailable: 'Payments are unavailable right now. Please try again shortly.',
};

export default function PayCommissionPage() {
  const { id } = useParams();
  const { user } = useAuth();
  const [info, setInfo] = useState(null);
  const [state, setState] = useState('loading');
  const [option, setOption] = useState('');
  const [address, setAddress] = useState({});
  const [fields, setFields] = useState({});
  const [quote, setQuote] = useState(null);
  const [session, setSession] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    account.commissionPayment(id).then((r) => {
      if (r.ok) {
        setInfo(r.data.payment);
        setOption(Object.keys(r.data.payment.options)[0] ?? '');
        setState('ready');
      } else setState(r.status === 401 ? 'login' : 'missing');
    });
  }, [id]);

  if (state === 'loading') return <div className="container account-page"><p>Loading…</p></div>;
  if (state === 'login' || !user) return <div className="container account-page"><p>Please <Link to="/login">sign in</Link> to make a payment.</p></div>;
  if (state === 'missing') return <div className="container account-page"><p>Commission not found. <Link to="/account">Back to your account</Link></p></div>;

  const options = Object.entries(info.options);
  const needsAddress = option === 'balance' && info.delivery === 'ship';
  const back = <p><Link to={`/account/commissions/${id}`}>Back to portrait request #{id}</Link></p>;

  if (options.length === 0) {
    return <div className="container account-page">{back}<h1>Payment</h1><p>Nothing is due on this commission right now.</p></div>;
  }

  const fail = (r) => {
    setFields(r.data?.fields ?? {});
    if (r.data?.quote) setQuote(r.data.quote);
    setError(r.data?.error === 'price_changed' ? 'The total changed. Please review it and continue.' : errorText[r.data?.error] ?? 'Something went wrong. Please try again.');
  };

  const getQuote = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError('');
    setFields({});
    const r = await account.commissionQuote(id, { option, address: needsAddress ? address : undefined });
    setBusy(false);
    if (r.ok) setQuote(r.data.quote);
    else fail(r);
  };

  const pay = async () => {
    setBusy(true);
    setError('');
    const r = await account.commissionPay(id, { option: quote.option, address: quote.address ?? undefined, expected_total_cents: quote.total_cents });
    setBusy(false);
    if (r.ok) setSession(r.data);
    else fail(r);
  };

  return (
    <div className="container account-page checkout-page">
      {back}
      <h1>Payment</h1>
      {!quote && (
        <form className="account-form" onSubmit={getQuote}>
          {options.length > 1 && (
            <fieldset className="checkout-method">
              <legend>Amount</legend>
              {options.map(([key, cents]) => (
                <label className="account-check" key={key}>
                  <input type="radio" name="option" checked={option === key} onChange={() => setOption(key)} /> {labels[key]} · {money(cents)}
                </label>
              ))}
            </fieldset>
          )}
          {options.length === 1 && <p><strong>{labels[option]}</strong>{info.options[option] > 0 && <> · {money(info.options[option])}</>}</p>}
          {needsAddress && (
            <>
              <p className="account-note">Where should we ship your finished portrait?</p>
              <AddressFields value={address} onChange={setAddress} errors={fields} />
            </>
          )}
          {error && <small className="account-error" role="alert">{error}</small>}
          <button className="page-nav-button" type="submit" disabled={busy}>{busy ? 'Calculating…' : 'Review payment'}</button>
        </form>
      )}
      {quote && (
        <>
          <Totals
            rows={[[labels[quote.option], quote.base_cents], ...(quote.option === 'balance' && info.delivery === 'ship' ? [['Shipping', quote.shipping_cents]] : []), ['Sales tax', quote.tax_cents]]}
            total={quote.total_cents}
          />
          {!session && (
            <div className="account-form">
              {error && <small className="account-error" role="alert">{error}</small>}
              <button className="page-nav-button" type="button" disabled={busy} onClick={pay}>{busy ? 'One moment…' : 'Continue to payment'}</button>
              <button className="checkout-link" type="button" onClick={() => { setQuote(null); setError(''); }}>Back</button>
            </div>
          )}
          {session && <PayForm session={session} />}
        </>
      )}
    </div>
  );
}

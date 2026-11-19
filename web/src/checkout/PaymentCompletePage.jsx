import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { account } from '../account/api.js';
import { useCart } from '../CartContext.jsx';
import '../account/account.css';

export default function PaymentCompletePage() {
  const [params] = useSearchParams();
  const cart = useCart();
  const id = Number(params.get('payment'));
  const [result, setResult] = useState(null);

  useEffect(() => {
    let off = false;
    let tries = 0;
    const run = async () => {
      const r = await account.finalizePayment(id);
      if (off) return;
      const s = r.ok ? r.data.status : r.status === 401 ? 'login' : 'error';
      if ((s === 'processing' || s === 'pending') && tries++ < 5) {
        setTimeout(run, 2000);
        return;
      }
      setResult(r.ok ? r.data : { status: s });
      if (s === 'succeeded') cart.refresh();
    };
    if (id > 0) run();
    else setResult({ status: 'error' });
    return () => {
      off = true;
    };
  }, [id]); // eslint-disable-line react-hooks/exhaustive-deps

  if (!result) return <div className="container account-page"><p>Confirming your payment…</p></div>;

  return (
    <div className="container account-page">
      {result.status === 'succeeded' && (
        <>
          <h1>Thank you!</h1>
          <p role="status">Your payment was received. A confirmation is on its way to your inbox.</p>
          {result.commission_id ? <Link to={`/account/commissions/${result.commission_id}`}>Back to your commission</Link> : <Link to="/gallery">Back to the gallery</Link>}
        </>
      )}
      {result.status === 'refunded' && (
        <>
          <h1>Sorry, that piece just sold</h1>
          <p role="status">Someone else bought a piece in your order moments before your payment cleared, so we refunded you in full. <Link to="/gallery">Back to the gallery</Link></p>
        </>
      )}
      {!['succeeded', 'refunded'].includes(result.status) && (
        <>
          <h1>Payment not completed</h1>
          <p role="status">We have not received your payment yet. If you were charged, it will be confirmed automatically and you will get an email. Otherwise you can <Link to={result.commission_id ? `/account/commissions/${result.commission_id}` : '/cart'}>try again</Link>.</p>
        </>
      )}
    </div>
  );
}

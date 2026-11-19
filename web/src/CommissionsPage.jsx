import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import ContactForm from './ContactForm.jsx';
import KenBurnsSlideshow from './KenBurnsSlideshow.jsx';
import { useAuth } from './AuthContext.jsx';
import { availabilityCopy, money } from './commissionStatus.js';
import './CommissionsPage.css';
import commissionsLogo from './assets/commission-icon-dark-mode.svg';

const COMMISSION_BANNER_KEYS = [
  '205-dobby-2020',
  '73-friendship',
  '131-silver',
  '203-m-h',
  '132-lacey-at-autauga-creek',
  '216-crumpet',
  '187-murphy',
];
const COMMISSION_EXAMPLE_KEYS = [
  '166-desiray-dicky',
  '202-grace-danielle',
  '163-general-angus-mctuffy',
  '2-carbuncle',
];
const BANNER_CONFIG = {
  intervalMs: 5630,
  fadeMs: 1800,
  aspectRatio: '14 / 8',
  effects: ['zoom-out-center', 'pan-top-bottom','classic-in-bottom-left-center', 'pan-left-right', 'zoom-out-center', 'classic-in-top-right'],
  frameEffect: 'fade',
};

function PriceTable({ prices }) {
  if (prices.length === 0) return null;

  return (
    <table className="commissions-prices">
      <caption>Prices by canvas size, one subject, shipping not included</caption>
      <thead>
        <tr><th scope="col">Size</th><th scope="col">Pet/Animal</th><th scope="col">Human Subject</th></tr>
      </thead>
      <tbody>
        {prices.map((row) => (
          <tr key={row.canvas_size_ids.join('-')}>
            <th scope="row">{row.label}</th>
            <td>{row.pet_cents === null ? '—' : money(row.pet_cents)}</td>
            <td>{row.human_cents === null ? '—' : money(row.human_cents)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

export default function CommissionsPage() {
  const { user } = useAuth();
  const [info, setInfo] = useState(null);
  const [infoError, setInfoError] = useState(false);
  const [banner, setBanner] = useState([]);
  const [commissionExamples, setCommissionExamples] = useState([]);

  useEffect(() => {
    const controller = new AbortController();
    fetch('/api/commissions/info', { signal: controller.signal })
      .then((response) => {
        if (!response.ok) throw new Error('Commission info request failed');
        return response.json();
      })
      .then(setInfo)
      .catch((reason) => {
        if (reason.name !== 'AbortError') setInfoError(true);
      });
    return () => controller.abort();
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    Promise.all(COMMISSION_BANNER_KEYS.map(async (pieceKey) => {
      const response = await fetch(`/api/pieces/${encodeURIComponent(pieceKey)}`, { signal: controller.signal });
      if (!response.ok) return null;
      const result = await response.json();
      return result.data;
    }))
      .then((pieces) => {
        if (!controller.signal.aborted) {
          setBanner(pieces
            .filter((piece) => piece?.image?.url)
            .map((piece) => ({ id: piece.id, src: piece.image.url, alt: piece.image.alt || piece.title })));
        }
      })
      .catch((reason) => {
        if (reason.name !== 'AbortError') setBanner([]);
      });

    return () => controller.abort();
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    Promise.all(COMMISSION_EXAMPLE_KEYS.map(async (pieceKey) => {
      const response = await fetch(`/api/pieces/${encodeURIComponent(pieceKey)}`, { signal: controller.signal });
      if (!response.ok) return null;
      const result = await response.json();
      return result.data;
    }))
      .then((pieces) => {
        if (!controller.signal.aborted) setCommissionExamples(pieces.filter((piece) => piece?.image?.url));
      })
      .catch((reason) => {
        if (reason.name !== 'AbortError') setCommissionExamples([]);
      });
    return () => controller.abort();
  }, []);

  const availability = availabilityCopy[info?.availability] || availabilityCopy.unknown;
  const fees = info?.fees;

  return (
    <section className="container content-page commissions-page">
      <p className="eyebrow">Custom portraits by Anni</p>
      <KenBurnsSlideshow {...BANNER_CONFIG} images={banner} className="gallery-page__banner" ariaLabel="Recent commissioned portraits">
        <h1><img src={commissionsLogo} alt="Commissions" width="25%" height="25%"/> COMMISSIONS</h1>
      </KenBurnsSlideshow>

      <div className={`commission-status commission-status--${availability.tone}`}>
        <span className="commission-status__dot" aria-hidden="true" />
        <span>{availability.label}</span>
      </div>

      <section aria-labelledby="about-heading">
        <h2 id="about-heading">Portraits made to be recognized</h2>
        <blockquote className="commissions-quote">
          <i>
            I have always loved animals. Even as a child, I felt a strong bond with them and I feel a special connection with every animal I meet. I think the beauty of animals should be celebrated. Every time I paint an animal, I aim to capture who the animal is, not just the details of fur and eyes. My Pet Portraits are no different. I want to capture who your pet is on canvas, to the point that you can instantly recognize the attributes that spur the love you have for your pet.
          </i>
          <footer>- Anni H. Thompson</footer>
        </blockquote>
        <p>Anni is best known for her animal portraits, though her work with human figures has been featured in major studio films alongside her animal paintings.</p>
        <p>She welcomes commissions of both people and pets, and is glad to bring a fantasy or otherwise unrealistic interpretation to either. As she puts it, when she paints a portrait of someone, she wants to show not just what they look like, but who they are, working to capture the subtleties of expression while matching skin tone and texture, and she loves to paint all kinds of people, old and young, male and female, light and dark, and everything in between. Whether the subject is a beloved companion or a person, her aim is the same: to capture not just their likeness, but their soul.</p>
      </section>

      <section aria-labelledby="pricing-heading" className="commissions-pricing">
        <h2 id="pricing-heading">Pricing</h2>
        {infoError && <p className="catalog-message">Pricing is temporarily unavailable.</p>}
        {info && <PriceTable prices={info.prices} />}
        {fees && (
          <ul className="commissions-extras">
            <li>Animal portrait: add {money(fees.extra_pet_cents)} for each additional pet or animal.</li>
            <li>Human portrait: add {money(fees.extra_person_cents)} for each additional person, and {money(fees.human_pet_cents)} for each pet or animal you wish to include.</li>
          </ul>
        )}
      </section>

      <section aria-labelledby="how-heading" className="commissions-how">
        <h2 id="how-heading">How it works</h2>
        <ol className="commissions-steps">
          <li><strong>Ask first, if you're not sure.</strong> Questions before you commit are welcome and need no account. Use the form below.</li>
          <li><strong>Request your portrait.</strong> {user ? 'You are signed in.' : <><Link to="/login">Sign in or create an account</Link>.</>} Send your source photos, subjects and canvas size.</li>
          <li><strong>Anni reviews.</strong> She may reply with questions, approve the commission, or (although incredibly rarely the case) let you know it is not a fit.</li>
          <li><strong>Payment.</strong> Pay in full, or{info ? ` put down a ${info.deposit_percent}%` : ' put down a'} deposit to begin. Shipping is quoted separately. Anni can take payment via PayPal or Venmo via e-mail, or with a credit or debit card here on the website (We use Stripe for payment processing).</li>
        </ol>
        <p><Link className="page-nav-button" to="/commissions/request">Portrait Request Form</Link></p>
      </section>

      <section className="commissions-section" aria-labelledby="commissions-examples-heading">
        <h2 id="commissions-examples-heading">Don't let reality limit you...</h2>
        <p>While Anni's style focuses on realism in order to capture the essense of both animals and humans, she can also dress them up as anything from a wizard to 17th century French admiral! She can even capture your favorite fantasy creature from that one video game you love so much!</p>
        {commissionExamples.length > 0 && (
          <div className="commissions-examples">
            {commissionExamples.map((piece) => (
              <Link className="commissions-example" key={piece.id} to={`/pieces/${piece.id}-${piece.slug}`}>
                <img src={piece.image.url} alt={piece.image.alt || piece.title} loading="lazy" />
                <span>
                  <strong>{piece.title}</strong>
                  <small>{[piece.size, piece.medium].filter(Boolean).join(' · ')}</small>
                </span>
              </Link>
            ))}
          </div>
        )}
      </section>

      <ContactForm source="commissions" heading="Have a question first?" intro="Have questions about pricing, sizes, photos or timing? Anni will be more than happy to answer any questions. For a business or organization commission, contact us here instead of using the request form. <b>PLEASE NOTE: This form is for only customers and commission inquiries. Unsolicited sales pitches will result in a permanent e-mail ban and Studio Anni account suspension." />
    </section>
  );
}
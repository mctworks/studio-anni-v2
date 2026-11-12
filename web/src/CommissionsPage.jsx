import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import ContactForm from './ContactForm.jsx';
import KenBurnsSlideshow from './KenBurnsSlideshow.jsx';
import { useAuth } from './AuthContext.jsx';
import { availabilityCopy, money } from './commissionStatus.js';
import './CommissionsPage.css';

const BANNER_COUNT = 7;
const PAGE_SIZE = 50;
const BANNER_CONFIG = {
  intervalMs: 5630,
  fadeMs: 1800,
  aspectRatio: '16 / 7',
  effects: ['classic-in-bottom-left-center', 'pan-left-right', 'zoom-out-center', 'classic-in-top-right'],
  frameEffect: 'fade',
};

const canvasArea = (size) => {
  const dimensions = String(size || '').match(/\d+(?:\.\d+)?/g);
  return dimensions?.length >= 2 ? Number(dimensions[0]) * Number(dimensions[1]) : 0;
};

function PriceTable({ prices }) {
  if (prices.length === 0) return null;

  return (
    <table className="commissions-prices">
      <caption>Prices by canvas size, one subject, shipping not included</caption>
      <thead>
        <tr><th scope="col">Size</th><th scope="col">Pet portrait</th><th scope="col">Portrait of a person</th></tr>
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
    const fetchPage = async (offset) => {
      const query = new URLSearchParams({ origin: 'commission', sort: 'newest', limit: String(PAGE_SIZE), offset: String(offset) });
      const response = await fetch(`/api/pieces?${query}`, { signal: controller.signal });
      if (!response.ok) throw new Error('Catalog request failed');
      return response.json();
    };

    (async () => {
      try {
        const first = await fetchPage(0);
        const total = Number(first.meta?.total) || first.data.length;
        const offsets = Array.from({ length: Math.max(0, Math.ceil(total / PAGE_SIZE) - 1) }, (_, i) => (i + 1) * PAGE_SIZE);
        const rest = await Promise.all(offsets.map(fetchPage));
        if (controller.signal.aborted) return;

        // Pages arrive newest first. Keep the 7 largest canvases, then show them newest to oldest.
        const pieces = [first, ...rest].flatMap((page) => page.data || []).filter((piece) => piece.image?.url && canvasArea(piece.size) > 0);
        const order = new Map(pieces.map((piece, index) => [piece.id, index]));
        const largest = [...pieces]
          .sort((a, b) => canvasArea(b.size) - canvasArea(a.size) || order.get(a.id) - order.get(b.id))
          .slice(0, BANNER_COUNT)
          .sort((a, b) => order.get(a.id) - order.get(b.id));

        setBanner(largest.map((piece) => ({ id: piece.id, src: piece.image.url, alt: piece.image.alt || piece.title })));
      } catch (reason) {
        if (reason.name !== 'AbortError') setBanner([]);
      }
    })();

    return () => controller.abort();
  }, []);

  const availability = availabilityCopy[info?.availability] || availabilityCopy.unknown;
  const fees = info?.fees;

  return (
    <section className="container content-page commissions-page">
      <p className="eyebrow">Custom portraits by Anni</p>
      <KenBurnsSlideshow {...BANNER_CONFIG} images={banner} className="gallery-page__banner" ariaLabel="Recent commissioned portraits">
        <h1>COMMISSIONS</h1>
      </KenBurnsSlideshow>

      <div className={`commission-status commission-status--${availability.tone}`}>
        <span className="commission-status__dot" aria-hidden="true" />
        <span>{availability.label}</span>
      </div>

      <section aria-labelledby="about-heading">
        <h2 id="about-heading">Portraits made to be recognized</h2>
        <blockquote className="commissions-quote">
          <p>
            I have always loved animals. Even as a child, I felt a strong bond with them and I feel a special connection with every animal I meet. I think the beauty of animals should be celebrated. Every time I paint an animal, I aim to capture who the animal is, not just the details of fur and eyes. My Pet Portraits are no different. I want to capture who your pet is on canvas, to the point that you can instantly recognize the attributes that spur the love you have for your pet.
          </p>
          <footer>- Anni H. Thompson</footer>
        </blockquote>
        <p>Anni is best known for her animal portraits, though her work with human figures has been featured in major studio films alongside her animal paintings.</p>
        <p>She welcomes commissions of both people and pets, and is glad to bring a fantasy or otherwise unrealistic interpretation to either. As she puts it, when she paints a portrait of someone, she wants to show not just what they look like, but who they are, working to capture the subtleties of expression while matching skin tone and texture, and she loves to paint all kinds of people, old and young, male and female, light and dark, and everything in between. Whether the subject is a beloved companion or a person, her aim is the same: to capture not just their likeness, but their soul.</p>
      </section>

      <section aria-labelledby="pricing-heading">
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

      <section aria-labelledby="how-heading">
        <h2 id="how-heading">How it works</h2>
        <ol className="commissions-steps">
          <li><strong>Ask first, if you're not sure.</strong> Questions before you commit are welcome and need no account. Use the form below.</li>
          <li><strong>Request your portrait.</strong> {user ? 'You are signed in.' : <><Link to="/login">Sign in or create an account</Link>.</>} Send your source photos, subjects and canvas size.</li>
          <li><strong>Anni reviews.</strong> She may reply with questions, approve the commission, or (although incredibly rarely the case) let you know it is not a fit.</li>
          <li><strong>Pay.</strong> Pay in full, or{info ? ` put down a ${info.deposit_percent}%` : ' put down a'} deposit to begin. Shipping is quoted separately.</li>
        </ol>
        <p className="commissions-note">The request form is not open yet.</p>
      </section>

      <ContactForm source="commissions" heading="Have a question first?" intro="Have questions about pricing, sizes, photos or timing? Anni will be more than happy to answer any questions. For a business or organization commission, contact us here instead of using the request form." />
    </section>
  );
}

export default function Promo({ image, imageAlt, heading, text }) {
  const hasImage = Boolean(image);
  const hasHeading = Boolean(heading?.trim());
  const hasText = Boolean(text?.trim());
  if (!hasImage && !hasHeading && !hasText) return null;

  return (
    <section className={`homepage-promo${hasImage ? '' : ' homepage-promo--text-only'}`} aria-labelledby={hasHeading ? 'homepage-promo-heading' : undefined}>
      {hasImage && <img className="homepage-promo__image" src={image} alt={imageAlt ?? ''} />}
      {(hasHeading || hasText) && (
        <div className="homepage-promo__content">
          {hasHeading && <h3 id="homepage-promo-heading">{heading}</h3>}
          {hasText && <p>{text}</p>}
        </div>
      )}
    </section>
  );
}

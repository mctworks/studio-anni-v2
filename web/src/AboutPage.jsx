import ContactForm from './ContactForm.jsx';
import portrait from './assets/images/aboutanni.jpg';
import './AboutPage.css';

export default function AboutPage() {
  return (
    <article className="container content-page about-page">
      <h1>About Anni</h1>

      <section className="about-page__bio" aria-labelledby="about-bio-heading">
        <figure className="about-page__portrait">
          <img
            src={portrait}
            alt="A self-portrait of Anni Thompson, a fareskined woman with dark hair and blue eyes wearing glasses."
          />
        </figure>
        <div className="about-page__bio-copy">
          <h2 id="about-bio-heading">Meet Anni</h2>
          <p>
            Anni Thompson is a professional artist in the Atlanta area who specializes in original acrylic paintings and custom work since she established Studio Anni with her husband in 2017. The themes of her work include animals, space, nature, fantasy, and everyday people. Anni has a meticulous eye for minor details, aiming for realism and the use of vibrant colors to capture not only the look of the subject, but also its essence and feel. While her influences include Maggie Stiefvater, her aunt (and teacher, sometimes){' '}
            <a href="https://www.bepekafka.com/" target="_blank" rel="noreferrer">Bepe Kafka</a>, as well as Vincent Van Gogh, Anni prefers to focus on refining her own proven style rather than fixating on the style of other artists. She has over 200 works completed, with some being featured in major studio motion pictures for both Warner Bros. and Paramount.
          </p>
          <p>
            Anni is not only an avid reader, but a self proclaimed nerd and animal lover. She lives with her husband and longtime Webmaster for Studio Anni,{' '}
            <a href="https://mct630.com/" target="_blank" rel="noreferrer">Michael Thompson</a>, and her animals (three cats and a small chihuahua.) She spends a lot of time caught up in her imagination. Anni aims to spread joy through her work and hopes that any time you see her artwork, it makes you feel a little bit better.
          </p>
        </div>
      </section>

      <section className="about-page__statement" aria-labelledby="about-statement-heading">
        <h2 id="about-statement-heading">Artist Statement</h2>
        <blockquote>
          “I don't really do artist statements. I'm just a person who likes to paint what comes to mind. It's really not that deep.”
        </blockquote>
      </section>

      <section className="about-page__ai" aria-labelledby="about-ai-heading">
        <h2 id="about-ai-heading">AI Notice</h2>
        <p>
          All of Anni's work was painted by hand, with no assistance from AI whatsoever. Her methods for creating art have changed very little since she started painting professionally in 2016, with only adjustments in techinique rather than relying on technology that doesn't truly know how to create.{' '}
          <strong>Neither Anni Thompson, nor Studio Anni, consents to having her work used by any LLM for any purpose whatsoever, included by not limited to the training of LLMs used for image generation.</strong>
        </p>
      </section>

      <ContactForm
        source="about"
        heading="Contact Anni"
        intro="Questions or comments about Anni and her work? Feel free to shoot her a message using the form below."
        className="about-page__contact"
      />
    </article>
  );
}
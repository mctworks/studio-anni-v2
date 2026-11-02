import { useEffect, useMemo, useRef } from 'react';
import { gsap } from 'gsap';
import './KenBurnsSlideshow.css';

const POSITIONS = {
  center: [0, 0],
  left: [4, 0],
  right: [-4, 0],
  top: [0, 4],
  bottom: [0, -4],
  topLeft: [4, 4],
  topRight: [-4, 4],
  bottomLeft: [4, -4],
  bottomRight: [-4, -4],
};

const movement = (from, fromScale, to, toScale) => ({
  from: { xPercent: POSITIONS[from][0], yPercent: POSITIONS[from][1], scale: fromScale },
  to: { xPercent: POSITIONS[to][0], yPercent: POSITIONS[to][1], scale: toScale },
});

const EFFECTS = {
  'zoom-in-center': movement('center', 1.04, 'center', 1.18),
  'zoom-out-center': movement('center', 1.18, 'center', 1.04),
  'zoom-in-top-left': movement('topLeft', 1.08, 'topLeft', 1.18),
  'zoom-in-top-right': movement('topRight', 1.08, 'topRight', 1.18),
  'zoom-in-bottom-left': movement('bottomLeft', 1.08, 'bottomLeft', 1.18),
  'zoom-in-bottom-right': movement('bottomRight', 1.08, 'bottomRight', 1.18),
  'pan-left-right': movement('left', 1.14, 'right', 1.14),
  'pan-right-left': movement('right', 1.14, 'left', 1.14),
  'pan-bottom-top': movement('bottom', 1.14, 'top', 1.14),
  'pan-top-bottom': movement('top', 1.14, 'bottom', 1.14),
  'pan-bottom-left-top-right': movement('bottomLeft', 1.14, 'topRight', 1.14),
  'pan-top-right-bottom-left': movement('topRight', 1.14, 'bottomLeft', 1.14),
  'classic-in-top-left': movement('center', 1.04, 'topLeft', 1.18),
  'classic-in-top-right': movement('center', 1.04, 'topRight', 1.18),
  'classic-in-bottom-left': movement('center', 1.04, 'bottomLeft', 1.18),
  'classic-in-bottom-right': movement('center', 1.04, 'bottomRight', 1.18),
  'classic-in-bottom-left-center': movement('bottomLeft', 1.08, 'center', 1.18),
  'classic-in-bottom-right-center': movement('bottomRight', 1.08, 'center', 1.18),
  'classic-out-top-left': movement('topLeft', 1.18, 'center', 1.04),
  'classic-out-top-right': movement('topRight', 1.18, 'center', 1.04),
  'classic-out-bottom-left': movement('bottomLeft', 1.18, 'center', 1.04),
  'classic-out-bottom-right': movement('bottomRight', 1.18, 'center', 1.04),
};

export const KEN_BURNS_EFFECTS = Object.keys(EFFECTS);
const DEFAULT_EFFECT = 'classic-in-bottom-left-center';
const FRAME_EFFECTS = ['none', 'fade', 'glow', 'shimmer'];

const KenBurnsSlideshow = ({
  images = [],
  intervalMs = 6500,
  fadeMs = 1200,
  aspectRatio = '16 / 7',
  effects = DEFAULT_EFFECT,
  frameEffect = 'fade',
  className = '',
  ariaLabel = 'Featured artwork slideshow',
  children,
}) => {
  const slides = useMemo(() => images.slice(0, 15), [images]);
  const slideRefs = useRef([]);

  useEffect(() => {
    const slideElements = slideRefs.current.slice(0, slides.length);
    const imageElements = slideElements.map((slide) => slide?.querySelector('img'));
    if (!slideElements.length || slideElements.some((slide) => !slide) || imageElements.some((image) => !image)) return undefined;

    const durationMs = Math.max(1000, Number(intervalMs) || 6500);
    const fadeDurationMs = Math.min(durationMs / 2, Math.max(250, Number(fadeMs) || 1200));
    const durationSeconds = durationMs / 1000;
    const fadeSeconds = fadeDurationMs / 1000;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const pauseReasons = new Set();
    let activeIndex = 0;
    let timer = null;
    let nextDueAt = 0;
    let remainingMs = durationMs;
    let motionTween = null;
    let incomingTween = null;
    let outgoingTween = null;
    let observer = null;

    gsap.set(slideElements, { autoAlpha: 0, zIndex: 0 });
    gsap.set(slideElements[0], { autoAlpha: 1, zIndex: 1 });
    gsap.set(imageElements, { xPercent: 0, yPercent: 0, scale: 1.04, transformOrigin: '50% 50%' });

    const startMotion = (index) => {
      if (reduceMotion) return;
      const effectList = Array.isArray(effects) ? effects : [effects];
      const requestedEffect = slideElements[index].dataset.effect || effectList[index % effectList.length] || DEFAULT_EFFECT;
      const preset = EFFECTS[requestedEffect] || EFFECTS[DEFAULT_EFFECT];
      motionTween?.kill();
      motionTween = gsap.fromTo(imageElements[index], preset.from, {
        ...preset.to,
        duration: durationSeconds + fadeSeconds,
        ease: 'none',
      });
    };

    const schedule = (delay) => {
      if (slides.length < 2 || pauseReasons.size || reduceMotion) return;
      remainingMs = delay;
      nextDueAt = Date.now() + delay;
      timer = window.setTimeout(advance, delay);
    };

    function advance() {
      timer = null;
      if (pauseReasons.size || slides.length < 2) return;

      const previousIndex = activeIndex;
      activeIndex = (activeIndex + 1) % slides.length;
      const previousSlide = slideElements[previousIndex];
      const nextSlide = slideElements[activeIndex];

      outgoingTween?.kill();
      incomingTween?.kill();
      gsap.set(nextSlide, { autoAlpha: 0, zIndex: 2 });
      gsap.set(imageElements[activeIndex], { xPercent: 0, yPercent: 0, scale: 1.04 });
      slideElements.forEach((slide, index) => slide.setAttribute('aria-hidden', index === activeIndex ? 'false' : 'true'));
      startMotion(activeIndex);
      incomingTween = gsap.to(nextSlide, { autoAlpha: 1, duration: fadeSeconds, ease: 'power1.inOut' });
      outgoingTween = gsap.to(previousSlide, {
        autoAlpha: 0,
        duration: fadeSeconds,
        ease: 'power1.inOut',
        onComplete: () => gsap.set(previousSlide, { zIndex: 0 }),
      });
      schedule(durationMs);
    }

    const setPaused = (reason, paused) => {
      if (paused) {
        if (pauseReasons.has(reason)) return;
        pauseReasons.add(reason);
        if (pauseReasons.size === 1) {
          remainingMs = timer === null ? durationMs : Math.max(0, nextDueAt - Date.now());
          window.clearTimeout(timer);
          timer = null;
          motionTween?.pause();
          incomingTween?.pause();
          outgoingTween?.pause();
        }
        return;
      }

      if (!pauseReasons.has(reason)) return;
      pauseReasons.delete(reason);
      if (pauseReasons.size === 0) {
        motionTween?.play();
        incomingTween?.play();
        outgoingTween?.play();
        schedule(remainingMs);
      }
    };

    const handleVisibility = () => setPaused('document', document.hidden);
    document.addEventListener('visibilitychange', handleVisibility);
    handleVisibility();

    if ('IntersectionObserver' in window) {
      observer = new IntersectionObserver(([entry]) => setPaused('viewport', !entry.isIntersecting), { threshold: 0.01 });
      observer.observe(slideElements[0].parentElement);
    }

    startMotion(0);
    schedule(durationMs);

    return () => {
      window.clearTimeout(timer);
      observer?.disconnect();
      document.removeEventListener('visibilitychange', handleVisibility);
      motionTween?.kill();
      incomingTween?.kill();
      outgoingTween?.kill();
      gsap.killTweensOf([...slideElements, ...imageElements]);
    };
  }, [slides, intervalMs, fadeMs, effects]);

  const activeFrameEffect = FRAME_EFFECTS.includes(frameEffect) ? frameEffect : 'fade';

  return (
    <div
      className={`ken-burns-slideshow ken-burns-slideshow--${activeFrameEffect} ${className}`.trim()}
      role="region"
      aria-label={ariaLabel}
      aria-roledescription="carousel"
      style={{ '--kb-aspect-ratio': aspectRatio }}
    >
      {slides.map((image, index) => (
        <div
          className="ken-burns-slideshow__slide"
          key={image.id ?? image.src ?? index}
          ref={(element) => { slideRefs.current[index] = element; }}
          data-effect={image.effect}
          aria-hidden={index !== 0}
        >
          <img src={image.src} alt={image.alt || ''} loading={index === 0 ? 'eager' : 'lazy'} draggable="false" />
        </div>
      ))}
      {children ? <div className="ken-burns-slideshow__overlay">{children}</div> : null}
    </div>
  );
};

export default KenBurnsSlideshow;
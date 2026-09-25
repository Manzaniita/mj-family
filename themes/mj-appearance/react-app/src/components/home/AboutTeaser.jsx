import { Link } from 'react-router-dom';
import { ArrowRight } from 'lucide-react';
import { MaskReveal, Reveal } from '@/components/motion/Reveal';
import { Section } from '@/components/layout/Layout';
import { WP, ABOUT } from '@/lib/config';
import { t } from '@/lib/copy';
import { paragraphsOf } from '@/lib/colegas';
import { cn } from '@/lib/format';

/**
 * "Sobre nosotros" en la portada: la foto de Nosotros (Apariencia → MJ
 * Appearance → Contenido → Nosotros) + eyebrow, título, un texto corto y el
 * enlace a /nosotros. El texto es `home_about_text`; vacío → primer párrafo
 * del texto de la página Nosotros. Sin foto ni texto, la sección no aparece.
 *
 * `override`: lo escrito en el bloque "Nosotros" (adelanto); cada campo con
 * valor pisa al del panel.
 */
export function AboutTeaser({ override } = {}) {
  const o = override || {};
  const image = o.image || ABOUT.image;
  const text = (o.text || '').trim() || t('home_about_text').trim() || paragraphsOf(WP?.aboutText)[0] || '';
  if (!image && !text) return null;

  const eyebrow = o.eyebrow || t('home_about_eyebrow');
  const title = o.title || t('home_about_title');
  const linkLabel = (o.linkLabel || t('home_about_link')).trim();

  return (
    <Section className="mj-about-teaser">
      <div className={cn('grid items-center gap-10 lg:gap-16', image && 'lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]')}>
        {image && (
          <MaskReveal className="mj-about-teaser__photo overflow-hidden rounded-(--mj-radius-2xl) border border-line">
            <img
              src={typeof image === 'string' ? image : image.url}
              alt="" loading="lazy"
              style={typeof image === 'object' && image.focal ? { objectPosition: `${image.focal.x * 100}% ${image.focal.y * 100}%` } : {}}
              className="aspect-4/5 h-full w-full object-cover"
            />
          </MaskReveal>
        )}
        <Reveal className={cn('mj-about-teaser__body', image ? 'max-w-2xl' : 'mx-auto max-w-3xl text-center')} delay={image ? 0.1 : 0}>
          {eyebrow && (
            <p className="mj-eyebrow text-xs font-semibold uppercase tracking-[0.2em] text-accent">{eyebrow}</p>
          )}
          {title && <h2 className="mj-about-teaser__title display mt-3 text-3xl md:text-5xl">{title}</h2>}
          {text && (
            <p className="mj-about-teaser__text mt-5 whitespace-pre-line text-base leading-relaxed text-ink-soft md:text-lg">{text}</p>
          )}
          {linkLabel && (
            <Link
              to="/nosotros"
              className="mj-link-arrow mj-about-teaser__link group mt-7 inline-flex items-center gap-2 text-sm font-medium text-accent"
            >
              {linkLabel}
              <ArrowRight size={16} className="transition-transform duration-300 group-hover:translate-x-1" />
            </Link>
          )}
        </Reveal>
      </div>
    </Section>
  );
}

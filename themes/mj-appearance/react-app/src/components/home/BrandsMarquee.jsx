import { Link } from 'react-router-dom';
import { useCatalog } from '@/store/catalog';
import { t } from '@/lib/copy';

/**
 * Marcas en loop infinito — CSS puro (pause en hover), duplicando la pista.
 * `label`: la frase del bloque "Cinta de marcas"; vacío = la del panel.
 */
export function BrandsMarquee({ label: ownLabel } = {}) {
  const brands = useCatalog((s) => s.brands);
  if (brands.length === 0) return null;
  const repeats = Math.max(2, Math.ceil(12 / brands.length));
  const track = Array.from({ length: repeats }, () => brands).flat();
  const label = ownLabel || t('home_brands_label');
  return (
    <section className="mj-marquee hairline-t hairline-b bg-bg-soft/40 py-10 overflow-hidden">
      {label && (
        <p className="mj-marquee__label mb-8 text-center text-xs font-semibold uppercase tracking-[0.25em] text-ink-faint">
          {label}
        </p>
      )}
      <div className="group relative">
        {/* fade en los bordes */}
        <div className="pointer-events-none absolute inset-y-0 left-0 z-10 w-24 bg-gradient-to-r from-bg to-transparent" />
        <div className="pointer-events-none absolute inset-y-0 right-0 z-10 w-24 bg-gradient-to-l from-bg to-transparent" />
        <div className="flex w-max animate-marquee gap-4 group-hover:[animation-play-state:paused]">
          {track.map((b, i) => (
            <Link
              key={`${b.slug}-${i}`}
              to={`/marca/${b.slug}`}
              className="mj-marquee__item flex h-16 w-44 items-center justify-center rounded-xl2 glass font-display text-lg font-semibold tracking-tight text-ink-faint transition-all duration-300 hover:text-ink hover:border-accent/30 hover:shadow-glow"
            >
              {b.name}
            </Link>
          ))}
        </div>
      </div>
    </section>
  );
}

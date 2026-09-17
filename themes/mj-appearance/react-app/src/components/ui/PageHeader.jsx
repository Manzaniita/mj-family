import { Reveal } from '@/components/motion/Reveal';

/** Encabezado de una página de texto: antetítulo, título grande y bajada. */
export function PageHeader({ eyebrow, title, subtitle }) {
  return (
    <Reveal className="mj-page-head max-w-2xl">
      {eyebrow && (
        <p className="mj-eyebrow text-xs font-semibold uppercase tracking-[0.2em] text-accent">{eyebrow}</p>
      )}
      <h1 className={`display text-4xl md:text-6xl ${eyebrow ? 'mt-3' : ''}`}>{title}</h1>
      {subtitle && <p className="mt-4 text-lg text-ink-soft">{subtitle}</p>}
    </Reveal>
  );
}

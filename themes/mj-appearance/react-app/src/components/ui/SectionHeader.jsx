import { ArrowRight } from 'lucide-react';
import { Link } from 'react-router-dom';
import { Reveal } from '@/components/motion/Reveal';
import { t } from '@/lib/copy';

const linkCls =
  'mj-link-arrow group inline-flex items-center gap-2 text-sm font-medium text-ink-soft transition-colors hover:text-ink';

export function SectionHeader({ eyebrow, title, subtitle, linkTo, linkLabel = t('ui_view_all') }) {
  return (
    <Reveal className="mj-section-head mb-10 flex flex-wrap items-end justify-between gap-6 md:mb-14">
      <div className="max-w-2xl">
        {eyebrow && (
          <p className="mj-eyebrow mb-3 text-xs font-semibold uppercase tracking-[0.2em] text-accent">
            {eyebrow}
          </p>
        )}
        <h2 className="mj-section-head__title display text-3xl md:text-5xl">{title}</h2>
        {subtitle && <p className="mj-section-head__subtitle mt-4 text-base text-ink-soft md:text-lg">{subtitle}</p>}
      </div>
      {linkTo && (/^https?:\/\//.test(linkTo) ? (
        <a href={linkTo} target="_blank" rel="noreferrer" className={linkCls}>
          {linkLabel}
          <ArrowRight size={16} className="transition-transform duration-300 group-hover:translate-x-1" />
        </a>
      ) : (
        <Link to={linkTo} className={linkCls}>
          {linkLabel}
          <ArrowRight size={16} className="transition-transform duration-300 group-hover:translate-x-1" />
        </Link>
      ))}
    </Reveal>
  );
}

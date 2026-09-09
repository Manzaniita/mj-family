import { Link } from 'react-router-dom';
import { LOGO, SITE } from '@/lib/config';
import { useUI } from '@/store';
import { cn } from '@/lib/format';

/**
 * Logo de la tienda. Sale de Apariencia → MJ Appearance → Logo (o del
 * Personalizador de WordPress). Sin imagen: la inicial del sitio en un
 * cuadrado de acento más el nombre.
 *
 * size: 'sm' (header, footer) | 'lg' (intro).
 * Clases mj-logo, mj-logo__img, mj-logo__mark, mj-logo__name: ganchos para las skins.
 */
export function Logo({ size = 'sm', className, linked = true, showName, nameClassName }) {
  const theme = useUI((s) => s.theme);
  const name = SITE?.name || 'mj';
  const src = LOGO.url ? (theme === 'dark' && LOGO.dark ? LOGO.dark : LOGO.url) : null;
  const withName = showName ?? LOGO.showName;
  const lg = size === 'lg';

  const content = src ? (
    <>
      <img
        src={src}
        alt={LOGO.alt || name}
        className={cn('mj-logo__img w-auto object-contain', lg ? 'h-16 max-w-60' : 'h-9 max-w-44')}
      />
      {withName && (
        <span className={cn('mj-logo__name font-display font-bold tracking-tight', lg ? 'text-2xl' : 'text-lg', nameClassName)}>
          {name}
        </span>
      )}
    </>
  ) : (
    <>
      <span
        className={cn(
          'mj-logo__mark grid place-items-center bg-accent font-display font-bold uppercase leading-none text-white shadow-glow',
          lg ? 'size-14 rounded-[14px] text-2xl' : 'size-8 rounded-[9px] text-sm'
        )}
        aria-hidden="true"
      >
        {name.trim()[0] || 'M'}
      </span>
      <span className={cn('mj-logo__name font-display font-bold tracking-tight', lg ? 'text-2xl' : 'text-lg', nameClassName)}>
        {name.toLowerCase()}
      </span>
    </>
  );

  const cls = cn('mj-logo flex items-center', lg ? 'gap-4' : 'gap-2.5', className);
  if (!linked) return <span className={cls}>{content}</span>;
  return (
    <Link to="/" className={cls} aria-label={`${name} — inicio`}>
      {content}
    </Link>
  );
}

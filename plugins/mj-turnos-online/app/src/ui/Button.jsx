import { forwardRef } from 'react';
import { Link } from 'react-router-dom';
import { Loader2 } from 'lucide-react';
import { cn } from '@/lib/format';

/*
 * Clases mjto-btn / mjto-btn--{variant} / mjto-btn--{size}: ganchos para skins.
 * La forma sale de --mjto-radius-btn (pill por defecto).
 */
const variants = {
  primary: 'mjto-btn--primary bg-accent text-on-accent shadow-glow hover:bg-accent-strong',
  secondary: 'mjto-btn--secondary bg-surface text-ink border border-line shadow-card hover:bg-surface-2',
  soft: 'mjto-btn--soft bg-accent-soft text-accent hover:bg-accent/15',
  outline: 'mjto-btn--outline border border-line-strong text-ink hover:bg-surface-2',
  ghost: 'mjto-btn--ghost text-ink-soft hover:bg-surface-2 hover:text-ink',
  danger: 'mjto-btn--danger bg-danger-soft text-danger hover:bg-danger/20',
  dangerSolid: 'mjto-btn--danger-solid bg-danger text-white hover:brightness-110',
  success: 'mjto-btn--success bg-success-soft text-success hover:bg-success/20',
  inverse: 'mjto-btn--inverse bg-ink text-bg hover:opacity-90',
};

const sizes = {
  xs: 'mjto-btn--xs h-8 px-3 text-xs gap-1',
  sm: 'mjto-btn--sm h-9 px-3.5 text-[13px] gap-1.5',
  md: 'mjto-btn--md h-11 px-5 text-sm gap-2',
  lg: 'mjto-btn--lg h-12 px-6 text-[15px] gap-2.5',
};

const iconOnlySizes = {
  xs: 'size-8',
  sm: 'size-9',
  md: 'size-11',
  lg: 'size-12',
};

const iconPx = { xs: 14, sm: 16, md: 18, lg: 20 };

/**
 * Botón / enlace con la misma cara.
 * @param {{ variant?: keyof variants, size?: 'xs'|'sm'|'md'|'lg', to? (react-router), href?, icon?: Component,
 *   iconRight?: Component, iconOnly?: boolean (cuadrado, requiere aria-label), loading?: boolean, full?: boolean,
 *   disabled?, className?, children, type? }} props
 */
export const Button = forwardRef(function Button(
  {
    variant = 'primary',
    size = 'md',
    to,
    href,
    icon: Icon,
    iconRight: IconRight,
    iconOnly = false,
    loading = false,
    full = false,
    disabled = false,
    className,
    children,
    type,
    ...rest
  },
  ref
) {
  const cls = cn(
    'mjto-btn relative inline-flex select-none items-center justify-center whitespace-nowrap rounded-btn font-semibold tracking-[-0.005em] transition-[background-color,color,box-shadow,transform,opacity,filter] duration-200 active:scale-[0.97] disabled:pointer-events-none disabled:opacity-45',
    variants[variant] || variants.primary,
    iconOnly ? cn('shrink-0 px-0', iconOnlySizes[size] || iconOnlySizes.md) : sizes[size] || sizes.md,
    full && 'w-full',
    loading && 'cursor-progress',
    className
  );
  const px = iconPx[size] || 18;
  const content = (
    <>
      {loading ? (
        <Loader2 size={px} className="animate-spin-soft shrink-0" aria-hidden="true" />
      ) : (
        Icon && <Icon size={px} className="shrink-0" aria-hidden="true" />
      )}
      {!iconOnly && children != null && <span className="truncate">{children}</span>}
      {iconOnly && !Icon && !loading ? children : null}
      {IconRight && !loading && <IconRight size={px} className="shrink-0" aria-hidden="true" />}
    </>
  );
  const isDisabled = disabled || loading;

  if (to && !isDisabled) {
    return (
      <Link ref={ref} to={to} className={cls} {...rest}>
        {content}
      </Link>
    );
  }
  if (href && !isDisabled) {
    return (
      <a ref={ref} href={href} className={cls} {...rest}>
        {content}
      </a>
    );
  }
  return (
    <button ref={ref} type={type || 'button'} className={cls} disabled={isDisabled} aria-busy={loading || undefined} {...rest}>
      {content}
    </button>
  );
});

/** Botón flotante (FAB) para "Nuevo turno": abajo a la derecha, sobre la nav mobile. */
export function Fab({ icon: Icon, label, className, ...rest }) {
  return (
    <Button
      variant="primary"
      size="lg"
      icon={Icon}
      className={cn(
        'mjto-fab fixed right-4 z-40 h-14 rounded-full pl-5 pr-6 shadow-float lg:right-8 lg:bottom-8',
        'bottom-[calc(var(--mjto-nav-h)+env(safe-area-inset-bottom)+1rem)]',
        className
      )}
      {...rest}
    >
      {label}
    </Button>
  );
}

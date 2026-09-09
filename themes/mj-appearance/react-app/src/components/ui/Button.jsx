import { motion } from 'framer-motion';
import { Link } from 'react-router-dom';
import { cn } from '@/lib/format';

/*
 * Clases mj-btn / mj-btn--{variant} / mj-btn--{size}: ganchos para las skins.
 * La forma sale de --mj-radius-btn (pill en el padre; las skins la cambian).
 */
const variants = {
  primary:
    'mj-btn--primary bg-accent text-white shadow-glow hover:brightness-110',
  secondary:
    'mj-btn--secondary glass text-ink hover:bg-surface-2',
  ghost:
    'mj-btn--ghost text-ink-soft hover:text-ink hover:bg-surface',
  inverse:
    'mj-btn--inverse bg-ink text-bg hover:opacity-90',
};

const sizes = {
  sm: 'mj-btn--sm h-9 px-4 text-sm gap-1.5',
  md: 'mj-btn--md h-11 px-6 text-sm gap-2',
  lg: 'mj-btn--lg h-13 px-8 text-base gap-2.5',
};

export function Button({
  variant = 'primary', size = 'md', to, href, className, children, ...rest
}) {
  const cls = cn(
    'mj-btn inline-flex items-center justify-center rounded-(--mj-radius-btn) font-medium transition-all duration-300 select-none whitespace-nowrap cursor-pointer disabled:opacity-40 disabled:pointer-events-none',
    variants[variant],
    sizes[size],
    className
  );
  const motionProps = { whileHover: { scale: 1.02 }, whileTap: { scale: 0.97 } };

  if (to) {
    return (
      <motion.span {...motionProps} className="inline-flex">
        <Link to={to} className={cls} {...rest}>{children}</Link>
      </motion.span>
    );
  }
  if (href) {
    return (
      <motion.a href={href} className={cls} {...motionProps} {...rest}>{children}</motion.a>
    );
  }
  return (
    <motion.button className={cls} {...motionProps} {...rest}>{children}</motion.button>
  );
}

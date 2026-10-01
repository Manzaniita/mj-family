import { Link } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { STILL } from '@/lib/motion';
import { ShoppingBag, Heart, Scale, User, SunMedium, MoonStar, Menu } from 'lucide-react';
import { useCart, useWishlist, useCompare, useUI, useAuth } from '@/store';
import { EASE } from '@/lib/format';
import { t } from '@/lib/copy';

export function IconAction({ to, onClick, icon: Icon, label, count, iconSize = 19 }) {
  const content = (
    <motion.span
      whileHover={STILL ? undefined : { scale: 1.08 }}
      whileTap={STILL ? undefined : { scale: 0.92 }}
      className="mj-icon-action relative grid size-9 place-items-center rounded-(--mj-radius-pill) text-ink-soft transition-colors hover:text-ink hover:bg-surface lg:size-10"
    >
      <Icon size={Number(iconSize) || 19} strokeWidth={1.8} />
      {count > 0 && (
        <motion.span
          key={count}
          initial={{ scale: 0.5 }}
          animate={{ scale: 1 }}
          transition={{ type: 'spring', stiffness: 500, damping: 20 }}
          className="mj-icon-action__count absolute -right-0.5 -top-0.5 grid min-w-4.5 place-items-center rounded-full bg-accent px-1 text-[10px] font-bold text-white h-4.5"
        >
          {count}
        </motion.span>
      )}
    </motion.span>
  );

  return to ? (
    <Link to={to} aria-label={label} title={label}>{content}</Link>
  ) : (
    <button type="button" onClick={onClick} aria-label={label} title={label}>{content}</button>
  );
}

export function CartBlock({ showCount = true, iconSize = 19 }) {
  const cartCount = useCart((s) => s.items.reduce((n, i) => n + i.qty, 0));
  const setCartOpen = useUI((s) => s.setCartOpen);

  return (
    <div className="mj-block-cart flex items-center">
      <IconAction
        onClick={() => setCartOpen(true)}
        icon={ShoppingBag}
        label={t('nav_cart')}
        count={showCount ? cartCount : 0}
        iconSize={iconSize}
      />
    </div>
  );
}

export function WishlistBlock({ showCount = true, iconSize = 19 }) {
  const count = useWishlist((s) => s.ids.length);

  return (
    <div className="mj-block-wishlist flex items-center">
      <IconAction
        to="/favoritos"
        icon={Heart}
        label={t('nav_favorites')}
        count={showCount ? count : 0}
        iconSize={iconSize}
      />
    </div>
  );
}

export function CompareBlock({ showCount = true, iconSize = 19 }) {
  const count = useCompare((s) => s.ids.length);

  return (
    <div className="mj-block-compare flex items-center">
      <IconAction
        to="/comparar"
        icon={Scale}
        label={t('nav_compare')}
        count={showCount ? count : 0}
        iconSize={iconSize}
      />
    </div>
  );
}

export function AccountBlock({ iconSize = 19 }) {
  const user = useAuth((s) => s.user);

  return (
    <div className="mj-block-account flex items-center">
      <IconAction
        to={user ? '/cuenta' : '/login'}
        icon={User}
        label={t('nav_account')}
        iconSize={iconSize}
      />
    </div>
  );
}

export function ThemeToggleBlock({ iconSize = 19 }) {
  const theme = useUI((s) => s.theme);
  const toggleTheme = useUI((s) => s.toggleTheme);
  const dark = theme === 'dark';
  const label = dark ? t('nav_theme_light') : t('nav_theme_dark');
  const Icon = dark ? SunMedium : MoonStar;

  return (
    <div className="mj-block-theme flex items-center">
      <motion.button
        type="button"
        role="switch"
        aria-checked={dark}
        aria-label={label}
        title={label}
        whileHover={STILL ? undefined : { scale: 1.08 }}
        whileTap={STILL ? undefined : { scale: 0.92 }}
        onClick={(e) => toggleTheme({ x: e.clientX || undefined, y: e.clientY || undefined })}
        className="mj-theme-toggle mj-icon-action relative grid size-9 place-items-center overflow-hidden rounded-(--mj-radius-pill) text-ink-soft transition-colors hover:text-ink hover:bg-surface lg:size-10"
      >
        <AnimatePresence mode="popLayout" initial={false}>
          <motion.span
            key={theme}
            className="grid place-items-center"
            initial={{ rotate: -90, scale: 0.4, opacity: 0 }}
            animate={{ rotate: 0, scale: 1, opacity: 1 }}
            exit={{ rotate: 90, scale: 0.4, opacity: 0 }}
            transition={{ duration: 0.4, ease: EASE }}
          >
            <Icon size={Number(iconSize) || 19} strokeWidth={1.8} />
          </motion.span>
        </AnimatePresence>
      </motion.button>
    </div>
  );
}

export function MobileTriggerBlock({ iconSize = 20 }) {
  const setMobileMenuOpen = useUI((s) => s.setMobileMenuOpen);

  return (
    <button
      type="button"
      onClick={() => setMobileMenuOpen(true)}
      aria-label={t('nav_menu')}
      title={t('nav_menu')}
      className="mj-block-mobile-trigger grid size-9 shrink-0 place-items-center rounded-(--mj-radius-pill) text-ink-soft transition-colors hover:bg-surface hover:text-ink"
    >
      <Menu size={Number(iconSize) || 20} strokeWidth={1.8} />
    </button>
  );
}

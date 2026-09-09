import { useEffect, useRef, useState } from 'react';
import { motion, animate, useInView, useMotionValue, useScroll, useSpring, useTransform } from 'framer-motion';
import { EASE, cn } from '@/lib/format';
import { CUR, SITE } from '@/lib/config';
import { CINEMATIC, FINE_POINTER, TRANSITIONS } from '@/lib/motion';

/**
 * Lenguaje de motion de toda la app. Cada pieza degrada sola según
 * lib/motion.js: con reduced-motion o nivel "sutil" se vuelve estática,
 * y las piezas cinematográficas (máscara, parallax, magnético, contador)
 * solo actúan con nivel "cinematográfica".
 */

/** Reveal on-scroll: fade + rise. */
export function Reveal({ children, delay = 0, y = 28, className, once = true, ...rest }) {
  if (!TRANSITIONS) {
    return (
      <motion.div className={className} initial={false} {...rest}>
        {children}
      </motion.div>
    );
  }
  return (
    <motion.div
      className={className}
      initial={{ opacity: 0, y }}
      whileInView={{ opacity: 1, y: 0 }}
      viewport={{ once, margin: '-80px' }}
      transition={{ duration: 0.7, delay, ease: EASE }}
      {...rest}
    >
      {children}
    </motion.div>
  );
}

/** Contenedor con stagger para listas/grillas. */
export function Stagger({ children, className, gap = 0.07 }) {
  return (
    <motion.div
      className={className}
      initial={TRANSITIONS ? 'hidden' : false}
      whileInView="show"
      viewport={{ once: true, margin: '-60px' }}
      variants={{ show: { transition: { staggerChildren: TRANSITIONS ? gap : 0 } } }}
    >
      {children}
    </motion.div>
  );
}

export const staggerItem = {
  hidden: { opacity: 0, y: 24 },
  show: { opacity: 1, y: 0, transition: { duration: 0.6, ease: EASE } },
};

/**
 * Reveal por máscara: la imagen se descubre de abajo hacia arriba mientras
 * su escala se asienta. Para medios grandes (galería, categorías, banners).
 */
export function MaskReveal({ children, className, delay = 0, once = true }) {
  if (!CINEMATIC) {
    return (
      <Reveal className={className} delay={delay} y={20} once={once}>
        {children}
      </Reveal>
    );
  }
  return (
    <motion.div
      className={cn('overflow-hidden', className)}
      initial="hidden"
      whileInView="show"
      viewport={{ once, margin: '-60px' }}
    >
      <motion.div
        className="h-full w-full"
        variants={{
          hidden: { clipPath: 'inset(0 0 100% 0)' },
          show: { clipPath: 'inset(0 0 0% 0)', transition: { duration: 0.9, delay, ease: EASE } },
        }}
      >
        <motion.div
          className="h-full w-full"
          variants={{
            hidden: { scale: 1.12 },
            show: { scale: 1, transition: { duration: 1.2, delay, ease: EASE } },
          }}
        >
          {children}
        </motion.div>
      </motion.div>
    </motion.div>
  );
}

/**
 * Parallax vertical ligado al scroll del elemento (0 → speed·100% de su alto
 * mientras sale por arriba). Pensado para capas de fondo del hero.
 */
export function Parallax({ children, speed = 0.3, className, style }) {
  const ref = useRef(null);
  const { scrollYProgress } = useScroll({ target: ref, offset: ['start start', 'end start'] });
  const y = useTransform(scrollYProgress, [0, 1], ['0%', `${speed * 100}%`]);
  return (
    <motion.div ref={ref} className={className} style={{ ...style, y: CINEMATIC ? y : 0 }}>
      {children}
    </motion.div>
  );
}

/**
 * Botón magnético: el contenido se inclina hacia el puntero cuando está
 * cerca y vuelve con un resorte. Solo con puntero fino y nivel cinematográfico.
 */
export function Magnetic({ children, strength = 0.35, className }) {
  const ref = useRef(null);
  const spring = { stiffness: 220, damping: 18, mass: 0.4 };
  const x = useSpring(useMotionValue(0), spring);
  const y = useSpring(useMotionValue(0), spring);

  if (!CINEMATIC || !FINE_POINTER) {
    return <span className={cn('inline-flex', className)}>{children}</span>;
  }

  const onMove = (e) => {
    const r = ref.current?.getBoundingClientRect();
    if (!r) return;
    x.set((e.clientX - (r.left + r.width / 2)) * strength);
    y.set((e.clientY - (r.top + r.height / 2)) * strength);
  };
  const reset = () => {
    x.set(0);
    y.set(0);
  };

  return (
    <motion.span
      ref={ref}
      className={cn('inline-flex', className)}
      style={{ x, y }}
      onPointerMove={onMove}
      onPointerLeave={reset}
    >
      {children}
    </motion.span>
  );
}

const numberFormat = (decimals) =>
  new Intl.NumberFormat(SITE.locale, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });

/**
 * Contador animado: recibe el texto final ("120+", "$ 25.000", "4,8") y
 * anima solo la parte numérica cuando entra en pantalla. Sin nivel
 * cinematográfico muestra el valor final directamente. Pase lo que pase
 * (pestaña en segundo plano, rAF pausado), el valor final queda escrito.
 */
export function Counter({ value, className, duration = 1.4 }) {
  const ref = useRef(null);
  const inView = useInView(ref, { once: true, margin: '-40px' });
  const text = String(value ?? '');
  const match = text.match(/\d[\d.,]*/);
  const [display, setDisplay] = useState(CINEMATIC && match ? text.replace(match[0], '0') : text);

  useEffect(() => {
    if (!CINEMATIC || !inView || !match) {
      setDisplay(text);
      return undefined;
    }
    const raw = match[0];
    const thousands = CUR.thousandSep || '.';
    const decimalSep = CUR.decimalSep || ',';
    const decimals = raw.includes(decimalSep) ? raw.split(decimalSep)[1].length : 0;
    const normalized = raw.split(thousands).join('').replace(decimalSep, '.');
    const target = Number(normalized);
    if (!Number.isFinite(target)) {
      setDisplay(text);
      return undefined;
    }
    const fmt = numberFormat(decimals);
    const controls = animate(0, target, {
      duration,
      ease: EASE,
      onUpdate: (v) => setDisplay(text.replace(raw, fmt.format(v))),
      onComplete: () => setDisplay(text),
    });
    return () => controls.stop();
  }, [inView, text]);

  // Red de seguridad: si la animación no llegó a correr, mostrar el valor final.
  useEffect(() => {
    if (!CINEMATIC) return undefined;
    const t = setTimeout(() => setDisplay(text), (duration + 1.5) * 1000);
    return () => clearTimeout(t);
  }, [text, duration]);

  return (
    <span ref={ref} className={className}>
      {display}
    </span>
  );
}

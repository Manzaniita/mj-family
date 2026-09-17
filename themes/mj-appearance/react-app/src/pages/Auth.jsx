import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { AlertCircle, CheckCircle2, Loader2 } from 'lucide-react';
import { Section } from '@/components/layout/Layout';
import { Input } from '@/components/ui/Input';
import { Button } from '@/components/ui/Button';
import { Reveal } from '@/components/motion/Reveal';
import { useAuth, finishAuth } from '@/store';
import { WP, IS_WP, SESSION } from '@/lib/config';
import { usePageMeta } from '@/lib/seo';
import { t } from '@/lib/copy';

function AuthShell({ title, subtitle, children, footer }) {
  return (
    <Section className="grid min-h-[70vh] place-items-center">
      <Reveal className="w-full max-w-md">
        <div className="mj-auth-card rounded-(--mj-radius-2xl) border border-line glass p-8 md:p-10">
          <h1 className="display text-3xl">{title}</h1>
          <p className="mt-2 text-sm text-ink-soft">{subtitle}</p>
          <div className="mt-8 space-y-4">{children}</div>
        </div>
        <p className="mt-6 text-center text-sm text-ink-soft">{footer}</p>
      </Reveal>
    </Section>
  );
}

function ErrorBox({ children }) {
  return (
    <div className="flex items-start gap-3 rounded-xl2 border border-danger/30 bg-danger/10 p-4 text-sm text-danger">
      <AlertCircle size={17} className="mt-0.5 shrink-0" />
      <p>{children}</p>
    </div>
  );
}

/** Limpia el error global de auth al entrar y al salir de la pantalla. */
function useClearAuthError() {
  const clearError = useAuth((s) => s.clearError);
  useEffect(() => {
    clearError();
    return clearError;
  }, [clearError]);
}

/**
 * Destino de vuelta tras el login (?redirect=): solo paths relativos al
 * sitio ("/turnos"), nunca URLs externas ni "//otro-dominio". Cualquier otra
 * cosa → '' (se ignora y se va a /cuenta).
 */
function safeRedirect(value) {
  const path = String(value || '').trim();
  if (!path.startsWith('/') || path.startsWith('//') || path.startsWith('/\\')) return '';
  if (/[\r\n]/.test(path)) return '';
  return path;
}

export function Login() {
  usePageMeta(t('seo_title_login'));
  const { login, loading, error } = useAuth();
  useClearAuthError();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const redirect = safeRedirect(params.get('redirect'));
  const [form, setForm] = useState({ username: '', password: '', remember: SESSION.rememberDefault });
  const set = (k) => (e) =>
    setForm((f) => ({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }));

  const onSubmit = async (e) => {
    e.preventDefault();
    const ok = await login(form.username.trim(), form.password, form.remember);
    if (!ok) return;
    // Con ?redirect= se recarga la página completa: así, por ejemplo, el
    // plugin MJ Turnos Online intercepta /turnos y le muestra el panel a la dueña.
    if (redirect) {
      window.location.assign(redirect);
      return;
    }
    // Recarga (default) para que WordPress ya pinte la barra de administración
    // de quien acaba de entrar; si está desactivada, navegación normal.
    if (finishAuth(SESSION.accountPath)) return;
    navigate(SESSION.accountPath);
  };

  return (
    <AuthShell
      title="Bienvenido de vuelta"
      subtitle="Ingresá para ver tus pedidos y favoritos."
      footer={<>¿No tenés cuenta? <Link to="/registro" className="font-medium text-accent hover:underline">Creá una</Link></>}
    >
      <form onSubmit={onSubmit} className="space-y-4">
        <Input
          label="Usuario o email"
          placeholder="tu@email.com"
          autoComplete="username"
          value={form.username}
          onChange={set('username')}
          required
        />
        <Input
          label="Contraseña"
          type="password"
          placeholder="••••••••"
          autoComplete="current-password"
          value={form.password}
          onChange={set('password')}
          required
        />
        <div className="flex items-center justify-between">
          <label className="flex cursor-pointer items-center gap-2 text-xs text-ink-soft">
            <input
              type="checkbox"
              checked={form.remember}
              onChange={set('remember')}
              className="size-4 accent-accent"
            />
            Recordarme
          </label>
          <Link to="/recuperar" className="text-xs text-ink-soft hover:text-accent">¿Olvidaste tu contraseña?</Link>
        </div>
        {error && <ErrorBox>{error}</ErrorBox>}
        <Button type="submit" className="w-full" disabled={loading}>
          {loading && <Loader2 size={16} className="animate-spin" />}
          Ingresar
        </Button>
      </form>
    </AuthShell>
  );
}

export function Register() {
  usePageMeta(t('seo_title_register'));
  const { register, loading, error } = useAuth();
  useClearAuthError();
  const navigate = useNavigate();
  const [form, setForm] = useState({ first_name: '', last_name: '', email: '', password: '' });
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const onSubmit = async (e) => {
    e.preventDefault();
    const ok = await register({
      email: form.email.trim(),
      password: form.password,
      first_name: form.first_name.trim(),
      last_name: form.last_name.trim(),
    });
    if (!ok) return;
    if (finishAuth(SESSION.accountPath)) return;
    navigate(SESSION.accountPath);
  };

  if (IS_WP && !WP?.registrationEnabled) {
    return (
      <AuthShell
        title="Creá tu cuenta"
        subtitle="El registro de cuentas nuevas no está disponible."
        footer={<Link to="/login" className="font-medium text-accent hover:underline">Volver a ingresar</Link>}
      >
        <div className="flex items-start gap-3 rounded-xl2 border border-line bg-surface p-4 text-sm text-ink-soft">
          <AlertCircle size={17} className="mt-0.5 shrink-0 text-accent" />
          <p>El registro está deshabilitado. Si ya tenés una cuenta, ingresá con tus datos.</p>
        </div>
        <Button className="w-full" to="/login">Ir a ingresar</Button>
      </AuthShell>
    );
  }

  return (
    <AuthShell
      title="Creá tu cuenta"
      subtitle="Comprá más rápido y accedé a beneficios exclusivos."
      footer={<>¿Ya tenés cuenta? <Link to="/login" className="font-medium text-accent hover:underline">Ingresá</Link></>}
    >
      <form onSubmit={onSubmit} className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2">
          <Input
            label="Nombre"
            placeholder="Tu nombre"
            autoComplete="given-name"
            value={form.first_name}
            onChange={set('first_name')}
            required
          />
          <Input
            label="Apellido"
            placeholder="Tu apellido"
            autoComplete="family-name"
            value={form.last_name}
            onChange={set('last_name')}
            required
          />
        </div>
        <Input
          label="Email"
          type="email"
          placeholder="tu@email.com"
          autoComplete="email"
          value={form.email}
          onChange={set('email')}
          required
        />
        <Input
          label="Contraseña"
          type="password"
          placeholder="Mínimo 6 caracteres"
          autoComplete="new-password"
          minLength={6}
          value={form.password}
          onChange={set('password')}
          required
        />
        {error && <ErrorBox>{error}</ErrorBox>}
        <Button type="submit" className="w-full" disabled={loading}>
          {loading && <Loader2 size={16} className="animate-spin" />}
          Crear cuenta
        </Button>
      </form>
    </AuthShell>
  );
}

export function Recover() {
  usePageMeta(t('seo_title_recover'));
  const { recover, loading } = useAuth();
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);

  const onSubmit = async (e) => {
    e.preventDefault();
    try {
      await recover(email.trim());
    } finally {
      // Siempre mostramos éxito: no revelamos si el email existe.
      setSent(true);
    }
  };

  return (
    <AuthShell
      title="Recuperar contraseña"
      subtitle="Te enviamos un enlace para restablecerla."
      footer={<Link to="/login" className="font-medium text-accent hover:underline">Volver a ingresar</Link>}
    >
      {sent ? (
        <div className="flex items-start gap-3 rounded-xl2 border border-success/30 bg-success/10 p-4 text-sm text-success">
          <CheckCircle2 size={17} className="mt-0.5 shrink-0" />
          <p>Si el email existe, te enviamos el enlace para restablecer tu contraseña.</p>
        </div>
      ) : (
        <form onSubmit={onSubmit} className="space-y-4">
          <Input
            label="Email"
            type="email"
            placeholder="tu@email.com"
            autoComplete="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
          <Button type="submit" className="w-full" disabled={loading}>
            {loading && <Loader2 size={16} className="animate-spin" />}
            Enviar enlace
          </Button>
        </form>
      )}
    </AuthShell>
  );
}

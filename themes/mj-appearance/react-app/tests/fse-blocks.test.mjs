import assert from 'node:assert/strict';
import { test } from 'node:test';
import { build } from 'esbuild';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import React from 'react';
import { create } from 'react-test-renderer';

const mocks = {
  '@/store': `
    export const useCart = fn => fn({ items: [] });
    export const useWishlist = fn => fn({ ids: [] });
    export const useCompare = fn => fn({ ids: [] });
    export const useUI = fn => fn({ theme: 'dark', toggleTheme: () => {}, setCartOpen: () => {}, setMobileMenuOpen: () => {} });
    export const useAuth = fn => fn({ user: null });
  `,
  '@/lib/copy': 'export const t = key => key;',
  '@/lib/config': 'export const CONTACT = {}; export const SOCIAL = {}; export const SITE = { name: "MJ" }; export const LOGO = { url: "", dark: "", showName: true };',
  '@/lib/nav': 'export const isExternalHref = url => url.startsWith("http");',
  '@/lib/wc': 'export const sendContact = async () => ({ ok: true });',
  '@/components/ui/Logo': 'export function Logo() { return React.createElement("div", { className: "logo" }, "Logo"); }',
  '@/components/layout/MegaMenu': 'export function MegaMenu() { return React.createElement("nav", null, "Menu"); }',
  '@/components/layout/SearchBar': 'export function SearchBar() { return React.createElement("input", null); }',
  '@/components/layout/ExchangeRate': 'export function ExchangeRate() { return React.createElement("span", null, "USD"); }',
  '@/components/layout/PaymentSelector': 'export function PaymentSelector() { return React.createElement("span", null, "Pagos"); }',
  '@/components/layout/TopBar': 'export function TopBar() { return React.createElement("div", null, "TopBar"); }',
  '@/components/layout/FreeShippingBar': 'export function FreeShippingBar() { return React.createElement("div", null, "Shipping"); }',
  '@/components/ui/Button': `
    import React from 'react';
    export function Button(props) { return React.createElement(props.href ? "a" : "button", props, props.children); }
  `,
  '@/components/ui/DynamicIcon': 'export const iconByName = () => null;',
  '@/components/layout/Header': `
    import React from 'react';
    export function IconAction(props) { return React.createElement("button", null, props.label); }
    export function ThemeToggle() { return React.createElement("button", null, "Theme"); }
    export function QuickLinks() { return null; }
  `,
};

const bundle = await build({
  entryPoints: [resolve('src/components/bloques/HeaderFooterBlocks.jsx')],
  bundle: true,
  write: false,
  format: 'cjs',
  platform: 'node',
  jsx: 'automatic',
  packages: 'external',
  alias: { '@': resolve('src') },
  plugins: [
    {
      name: 'mocks-fse',
      setup(api) {
        api.onResolve({ filter: /^@\// }, (args) =>
          mocks[args.path] ? { path: args.path, namespace: 'mock' } : undefined
        );
        api.onLoad({ filter: /.*/, namespace: 'mock' }, (args) => ({
          contents: mocks[args.path],
          loader: 'jsx',
          resolveDir: resolve('.'),
        }));
      },
    },
  ],
});

const module = { exports: {} };
new Function('require', 'module', 'exports', bundle.outputFiles[0].text)(
  createRequire(import.meta.url),
  module,
  module.exports
);

const { HEADER_FOOTER_BLOCKS, CtaButtonBlock, FooterLinksBlock } = module.exports;

test('HEADER_FOOTER_BLOCKS registra todos los bloques modulares de cabecera y pie', () => {
  const expectedBlocks = [
    'logo',
    'nav',
    'search',
    'carrito',
    'favoritos',
    'comparador',
    'cuenta',
    'tema-toggle',
    'dolar',
    'medios-pago',
    'anuncios',
    'envio-gratis',
    'acciones-header',
    'cta-button',
    'newsletter',
    'footer-brand',
    'footer-links',
    'redes',
    'contacto',
    'footer-legal',
  ];

  for (const block of expectedBlocks) {
    assert.ok(HEADER_FOOTER_BLOCKS[block], `El bloque ${block} debe estar registrado en HEADER_FOOTER_BLOCKS`);
    assert.equal(typeof HEADER_FOOTER_BLOCKS[block], 'function', `El bloque ${block} debe ser una función componente React`);
  }
});

test('CtaButtonBlock renderiza enlace externo con target _blank si corresponde', () => {
  const tree = create(
    React.createElement(CtaButtonBlock, {
      label: 'Consultar WhatsApp',
      url: 'https://wa.me/5491112345678',
      target: '_blank',
      variant: 'primary',
    })
  ).toJSON();

  assert.ok(tree);
  const anchor = tree.children.find((c) => c.type === 'a');
  assert.ok(anchor, 'Debe renderizar un tag <a> para URL externa');
  assert.equal(anchor.props.href, 'https://wa.me/5491112345678');
  assert.equal(anchor.props.target, '_blank');
});

test('FooterLinksBlock parsea enlaces con salto de linea y pipes', async () => {
  const { MemoryRouter } = await import('react-router-dom');
  const tree = create(
    React.createElement(
      MemoryRouter,
      null,
      React.createElement(FooterLinksBlock, {
        title: 'Ayuda',
        links: 'Preguntas Frecuentes | /faq\nTérminos y Condiciones | /terminos',
      })
    )
  ).toJSON();

  assert.ok(tree);
  const title = tree.children.find((c) => c.type === 'h4');
  assert.equal(title.children[0], 'Ayuda');
  const ul = tree.children.find((c) => c.type === 'ul');
  assert.ok(ul);
  assert.equal(ul.children.length, 2);
});

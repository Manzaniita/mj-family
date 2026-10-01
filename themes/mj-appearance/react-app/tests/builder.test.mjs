import assert from 'node:assert/strict';
import { test } from 'node:test';
import { build } from 'esbuild';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import React from 'react';
import { create } from 'react-test-renderer';

const bundle = await build({
  entryPoints: [resolve('src/components/layout/BlockRegistry.jsx')],
  bundle: true,
  write: false,
  format: 'cjs',
  platform: 'node',
  jsx: 'automatic',
  packages: 'external',
  alias: { '@': resolve('src') },
});

const module = { exports: {} };
new Function('require', 'module', 'exports', bundle.outputFiles[0].text)(
  createRequire(import.meta.url),
  module,
  module.exports
);

const { HEADER_BLOCKS, FOOTER_BLOCKS } = module.exports;

test('El catálogo de bloques de Cabecera (Header) contiene todos los componentes requeridos', () => {
  const expectedHeaderBlocks = [
    'mj-logo',
    'mj-nav',
    'mj-megamenu',
    'mj-search',
    'mj-cart',
    'mj-wishlist',
    'mj-compare',
    'mj-account',
    'mj-theme-toggle',
    'mj-mobile-trigger',
    'mj-dolar',
    'mj-payment-selector',
    'mj-cta-button',
    'mj-html',
    'mj-topbar',
    'mj-shipping-bar',
  ];

  for (const blockType of expectedHeaderBlocks) {
    assert.ok(HEADER_BLOCKS[blockType], `El bloque ${blockType} debe estar registrado en HEADER_BLOCKS`);
    assert.equal(typeof HEADER_BLOCKS[blockType], 'function', `El bloque ${blockType} debe ser un componente React`);
  }
});

test('El catálogo de bloques de Pie de página (Footer) contiene todos los componentes requeridos', () => {
  const expectedFooterBlocks = [
    'mj-footer-newsletter',
    'mj-footer-brand',
    'mj-footer-columns',
    'mj-footer-contact',
    'mj-footer-socials',
    'mj-footer-legal',
    'mj-footer-payments',
    'mj-cta-button',
    'mj-html',
  ];

  for (const blockType of expectedFooterBlocks) {
    assert.ok(FOOTER_BLOCKS[blockType], `El bloque ${blockType} debe estar registrado en FOOTER_BLOCKS`);
    assert.equal(typeof FOOTER_BLOCKS[blockType], 'function', `El bloque ${blockType} debe ser un componente React`);
  }
});

test('El bloque CtaButtonBlock renderiza enlace con atributos y texto personalizado', () => {
  const Cta = HEADER_BLOCKS['mj-cta-button'];
  const tree = create(
    React.createElement(Cta, {
      label: 'Consultar WhatsApp',
      url: 'https://wa.me/5491112345678',
      target: '_blank',
      variant: 'primary',
    })
  ).toJSON();

  assert.ok(tree, 'El bloque debe renderizar');
  const anchor = tree.children.find((c) => c.type === 'a');
  assert.ok(anchor, 'Debe contener una etiqueta <a>');
  assert.equal(anchor.props.href, 'https://wa.me/5491112345678');
  assert.equal(anchor.props.target, '_blank');
});

test('El bloque HtmlBlock renderiza contenido HTML libre y respeta estilo', () => {
  const Html = HEADER_BLOCKS['mj-html'];
  const tree = create(
    React.createElement(Html, {
      content: '<span>Envío en el día</span>',
      textColor: '#ff0000',
    })
  ).toJSON();

  assert.ok(tree, 'El bloque debe renderizar');
  assert.equal(tree.props.style.color, '#ff0000');
  assert.equal(tree.props.dangerouslySetInnerHTML.__html, '<span>Envío en el día</span>');
});

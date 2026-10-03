/**
 * Definición del bloque Gutenberg de MJ Instagram.
 *
 * Utiliza wp.element.createElement nativo sin necesidad de build externo.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.blocks || !wp.element) {
		return;
	}

	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var be = wp.blockEditor || wp.editor;
	var InspectorControls = be.InspectorControls;
	var useBlockProps = be.useBlockProps;
	var c = wp.components;
	var ServerSideRender = wp.serverSideRender || c.ServerSideRender;

	var blockData = window.MJInstagramBlockData || { isConnected: false, profile: {}, settingsUrl: '' };

	registerBlockType('mj-instagram/feed', {
		title: 'Instagram (MJ)',
		description: 'Mostrá publicaciones, historias, destacados o tu perfil de Instagram en cualquier página.',
		category: 'mj',
		icon: el('svg', { width: 24, height: 24, viewBox: '0 0 24 24', fill: 'none', stroke: '#e1306c', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round' },
			el('rect', { width: 20, height: 20, x: 2, y: 2, rx: 5, ry: 5 }),
			el('path', { d: 'M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z' }),
			el('line', { x1: 17.5, x2: 17.51, y1: 6.5, y2: 6.5 })
		),
		keywords: ['instagram', 'redes', 'feed', 'historias', 'destacados', 'posts'],
		attributes: {
			displayType: { type: 'string', default: 'posts' },
			layout: { type: 'string', default: 'grid' },
			columnsDesktop: { type: 'number', default: 4 },
			columnsMobile: { type: 'number', default: 2 },
			limit: { type: 'number', default: 8 },
			aspectRatio: { type: 'string', default: 'square' },
			spacing: { type: 'string', default: 'normal' },
			showProfileHeader: { type: 'boolean', default: false },
			showBio: { type: 'boolean', default: true },
			showFollowers: { type: 'boolean', default: true },
			showFollowButton: { type: 'boolean', default: true },
			followButtonText: { type: 'string', default: 'Seguinos' },
			customTitle: { type: 'string', default: '' },
			customSubtitle: { type: 'string', default: '' },
			mediaTypeFilter: { type: 'string', default: 'all' },
			openInNewTab: { type: 'boolean', default: true },
			showHoverOverlay: { type: 'boolean', default: true },
			showPostModal: { type: 'boolean', default: true },
		},

		edit: function (props) {
			var attrs = props.attributes;
			var set = props.setAttributes;
			var blockProps = useBlockProps ? useBlockProps() : {};

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					// Panel 1: Contenido a mostrar
					el(
						c.PanelBody,
						{ title: 'Contenido a mostrar', initialOpen: true },
						el(c.SelectControl, {
							label: '¿Qué querés mostrar?',
							value: attrs.displayType,
							options: [
								{ label: 'Publicaciones recientes (Feed)', value: 'posts' },
								{ label: 'Historias activas (Stories)', value: 'stories' },
								{ label: 'Historias destacadas (Highlights)', value: 'highlights' },
								{ label: 'Perfil + publicaciones', value: 'profile' },
								{ label: 'Completo (Perfil, historias y fotos)', value: 'all' },
							],
							onChange: function (val) { set({ displayType: val }); },
						}),
						(attrs.displayType === 'posts' || attrs.displayType === 'profile' || attrs.displayType === 'all') &&
						el(c.RangeControl, {
							label: 'Cantidad de publicaciones',
							value: attrs.limit,
							min: 1,
							max: 24,
							onChange: function (val) { set({ limit: val }); },
						}),
						(attrs.displayType === 'posts' || attrs.displayType === 'profile' || attrs.displayType === 'all') &&
						el(c.SelectControl, {
							label: 'Filtrar tipo de medio',
							value: attrs.mediaTypeFilter,
							options: [
								{ label: 'Todas las publicaciones', value: 'all' },
								{ label: 'Solo fotos', value: 'IMAGE' },
								{ label: 'Solo videos y Reels', value: 'VIDEO' },
							],
							onChange: function (val) { set({ mediaTypeFilter: val }); },
						})
					),

					// Panel 2: Diseño y columnas
					el(
						c.PanelBody,
						{ title: 'Diseño y distribución', initialOpen: false },
						el(c.SelectControl, {
							label: 'Disposición',
							value: attrs.layout,
							options: [
								{ label: 'Grilla tradicional', value: 'grid' },
								{ label: 'Carrusel deslizable', value: 'carousel' },
							],
							onChange: function (val) { set({ layout: val }); },
						}),
						el(c.RangeControl, {
							label: 'Columnas en escritorio',
							value: attrs.columnsDesktop,
							min: 2,
							max: 6,
							onChange: function (val) { set({ columnsDesktop: val }); },
						}),
						el(c.RangeControl, {
							label: 'Columnas en celular',
							value: attrs.columnsMobile,
							min: 1,
							max: 3,
							onChange: function (val) { set({ columnsMobile: val }); },
						}),
						el(c.SelectControl, {
							label: 'Formato de las fotos',
							value: attrs.aspectRatio,
							options: [
								{ label: 'Cuadradas (1:1)', value: 'square' },
								{ label: 'Verticales (4:5)', value: 'portrait' },
							],
							onChange: function (val) { set({ aspectRatio: val }); },
						}),
						el(c.SelectControl, {
							label: 'Espaciado entre fotos',
							value: attrs.spacing,
							options: [
								{ label: 'Compacto (6px)', value: 'compact' },
								{ label: 'Normal (14px)', value: 'normal' },
								{ label: 'Amplio (24px)', value: 'relaxed' },
							],
							onChange: function (val) { set({ spacing: val }); },
						})
					),

					// Panel 3: Cabecera y perfil
					el(
						c.PanelBody,
						{ title: 'Cabecera y perfil', initialOpen: false },
						el(c.TextControl, {
							label: 'Título de la sección (opcional)',
							value: attrs.customTitle,
							placeholder: 'Ej: Seguinos en Instagram',
							onChange: function (val) { set({ customTitle: val }); },
						}),
						el(c.TextControl, {
							label: 'Bajada / Subtítulo (opcional)',
							value: attrs.customSubtitle,
							placeholder: 'Ej: Compartí tus fotos con el hashtag #mitienda',
							onChange: function (val) { set({ customSubtitle: val }); },
						}),
						el(c.ToggleControl, {
							label: 'Mostrar tarjeta de perfil',
							checked: attrs.showProfileHeader || attrs.displayType === 'profile' || attrs.displayType === 'all',
							onChange: function (val) { set({ showProfileHeader: val }); },
						}),
						(attrs.showProfileHeader || attrs.displayType === 'profile' || attrs.displayType === 'all') &&
						el(c.ToggleControl, {
							label: 'Mostrar biografía',
							checked: attrs.showBio,
							onChange: function (val) { set({ showBio: val }); },
						}),
						(attrs.showProfileHeader || attrs.displayType === 'profile' || attrs.displayType === 'all') &&
						el(c.ToggleControl, {
							label: 'Mostrar seguidores',
							checked: attrs.showFollowers,
							onChange: function (val) { set({ showFollowers: val }); },
						}),
						(attrs.showProfileHeader || attrs.displayType === 'profile' || attrs.displayType === 'all') &&
						el(c.ToggleControl, {
							label: 'Mostrar botón de Seguir',
							checked: attrs.showFollowButton,
							onChange: function (val) { set({ showFollowButton: val }); },
						}),
						(attrs.showProfileHeader || attrs.displayType === 'profile' || attrs.displayType === 'all') &&
						attrs.showFollowButton &&
						el(c.TextControl, {
							label: 'Texto del botón',
							value: attrs.followButtonText,
							onChange: function (val) { set({ followButtonText: val }); },
						})
					),

					// Panel 4: Opciones avanzadas
					el(
						c.PanelBody,
						{ title: 'Interacción y enlaces', initialOpen: false },
						el(c.ToggleControl, {
							label: 'Mostrar efecto hover sobre fotos',
							help: 'Muestra leyenda y logo de Instagram al pasar el cursor.',
							checked: attrs.showHoverOverlay,
							onChange: function (val) { set({ showHoverOverlay: val }); },
						}),
						el(c.ToggleControl, {
							label: 'Abrir enlaces en nueva pestaña',
							checked: attrs.openInNewTab,
							onChange: function (val) { set({ openInNewTab: val }); },
						})
					)
				),

				// Banner informativo en el editor
				el(
					'div',
					{ className: 'mj-ig-editor-notice' },
					el('span', { className: 'dashicons dashicons-instagram', style: { color: '#e1306c' } }),
					el(
						'span',
						null,
						blockData.isConnected
							? 'Conectado a Instagram: @' + (blockData.profile.username || 'cuenta')
							: el('span', null, 'Modo demostración. Podés conectar tu cuenta real en ', el('a', { href: blockData.settingsUrl, target: '_blank' }, 'MJ → Instagram'), '.')
					)
				),

				// Previsualización fiel en vivo del bloque usando ServerSideRender
				ServerSideRender
					? el(ServerSideRender, {
						block: 'mj-instagram/feed',
						attributes: attrs,
					})
					: el('div', { style: { padding: '20px', textAlign: 'center', background: '#f9f9f9', border: '1px dashed #ccc' } }, 'Cargando vista previa de Instagram...')
			);
		},

		save: function () {
			return null; // Renderizado dinámico en servidor vía PHP render_callback
		},
	});
})(window.wp);

/**
 * Interactividad frontend de MJ Instagram (carruseles y navegación).
 */
(function () {
	'use strict';

	function initCarousels() {
		var containers = document.querySelectorAll('.mj-ig-feed-container[data-mj-carousel="true"]');
		containers.forEach(function (container) {
			if (container.dataset.mjCarouselInit) {
				return;
			}
			container.dataset.mjCarouselInit = 'true';

			var track = container.querySelector('.mj-ig-feed--carousel');
			var btnPrev = container.querySelector('.mj-ig-nav-prev');
			var btnNext = container.querySelector('.mj-ig-nav-next');

			if (!track) return;

			function scrollAmount() {
				var item = track.querySelector('.mj-ig-item');
				return item ? item.offsetWidth + 14 : 260;
			}

			if (btnPrev) {
				btnPrev.addEventListener('click', function (e) {
					e.preventDefault();
					track.scrollBy({ left: -scrollAmount() * 2, behavior: 'smooth' });
				});
			}

			if (btnNext) {
				btnNext.addEventListener('click', function (e) {
					e.preventDefault();
					track.scrollBy({ left: scrollAmount() * 2, behavior: 'smooth' });
				});
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initCarousels);
	} else {
		initCarousels();
	}

	// Compatibilidad con navegación SPA y observador de mutaciones
	if (typeof MutationObserver !== 'undefined') {
		var observer = new MutationObserver(function () {
			initCarousels();
		});
		observer.observe(document.body, { childList: true, subtree: true });
	}
})();

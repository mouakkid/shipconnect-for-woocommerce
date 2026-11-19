/**
 * ShipConnect : catalogue des transporteurs (filtres par recherche, pays, région, type d'API)
 * et téléchargement des logos depuis le site de chaque transporteur, par petits lots.
 */
(function () {
	'use strict';

	var CFG = window.ShipC;
	var T = window.ShipCCatalog || {};
	var root = document.querySelector('[data-shipc-catalog]');
	if (!CFG || !root) {
		return;
	}

	function all(selector, scope) {
		return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
	}

	function format(text) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(text || '').replace(/%(\d+\$)?s/g, function (match, pos) {
			var index = pos ? parseInt(pos, 10) - 1 : i++;
			return args[index] !== undefined ? String(args[index]) : '';
		});
	}

	function post(action, data) {
		var body = new FormData();
		Object.keys(data || {}).forEach(function (key) {
			body.append(key, data[key]);
		});
		body.append('action', 'shipc_' + action);
		body.append('nonce', CFG.nonce);
		return fetch(CFG.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (response) {
				return response.json();
			})
			.then(function (json) {
				if (json && json.success) {
					return json.data || {};
				}
				throw new Error((json && json.data && json.data.message) || T.error || 'Error');
			});
	}

	/* ---------- Filtres ---------- */

	var cards = all('[data-shipc-carrier]', root);
	var search = root.querySelector('[data-shipc-catalog-filter="search"]');
	var country = root.querySelector('[data-shipc-catalog-filter="country"]');
	var region = root.querySelector('[data-shipc-catalog-filter="region"]');
	var api = root.querySelector('[data-shipc-catalog-filter="api"]');
	var counter = root.querySelector('[data-shipc-catalog-count]');
	var empty = root.querySelector('[data-shipc-catalog-empty]');
	var reset = root.querySelector('[data-shipc-catalog-reset]');
	var eu = country ? (country.getAttribute('data-eu') || '').split(' ') : [];
	var defaults = {
		country: country ? country.value : '',
		region: '',
		api: ''
	};

	function normalize(text) {
		var value = String(text || '').toLowerCase().trim();
		return value.normalize ? value.normalize('NFD').replace(/[̀-ͯ]/g, '') : value;
	}

	/** Une société couvre le pays choisi : pays cité, couverture mondiale, ou Union européenne pour un pays membre. */
	function covers(card, code) {
		if (!code) {
			return true;
		}
		var list = (card.getAttribute('data-countries') || '').split(' ');
		return list.indexOf(code) !== -1 || list.indexOf('GLOBAL') !== -1 || (list.indexOf('EU') !== -1 && eu.indexOf(code) !== -1);
	}

	function apply() {
		var q = search ? normalize(search.value) : '';
		var c = country ? country.value : '';
		var r = region ? region.value : '';
		var a = api ? api.value : '';
		var shown = 0;

		cards.forEach(function (card) {
			var match =
				covers(card, c) &&
				(!q || normalize(card.getAttribute('data-search')).indexOf(q) !== -1) &&
				(!r || card.getAttribute('data-region') === r) &&
				(!a || card.getAttribute('data-api') === a);
			card.hidden = !match;
			if (match) {
				shown++;
			}
		});

		if (counter) {
			counter.textContent = format(T.results, shown, cards.length);
		}
		if (empty) {
			empty.hidden = shown > 0;
		}
		if (reset) {
			reset.hidden = !(q || c !== defaults.country || r || a);
		}
		// Signale la fin du filtrage (admin.js remesure les notes tronquées).
		root.dispatchEvent(new CustomEvent('shipc:catalog-filtered', { bubbles: true }));
	}

	root.addEventListener('input', function (event) {
		if (event.target.matches('[data-shipc-catalog-filter]')) {
			apply();
		}
	});
	root.addEventListener('change', function (event) {
		if (event.target.matches('[data-shipc-catalog-filter]')) {
			apply();
		}
	});
	if (reset) {
		reset.addEventListener('click', function () {
			if (search) {
				search.value = '';
			}
			if (country) {
				country.value = defaults.country;
			}
			if (region) {
				region.value = '';
			}
			if (api) {
				api.value = '';
			}
			apply();
			if (search) {
				search.focus();
			}
		});
	}

	apply();

	/* ---------- Logos ---------- */

	var logoButton = document.querySelector('[data-shipc-logos="fetch"]');
	var logoStatus = document.querySelector('[data-shipc-logos-status]');

	function showLogo(slug, url) {
		all('[data-logo-slug="' + slug + '"]').forEach(function (mark) {
			var img = document.createElement('img');
			img.src = url;
			img.alt = '';
			img.decoding = 'async';
			mark.textContent = '';
			mark.classList.remove('shipc-logo-mark--mono');
			mark.removeAttribute('style');
			mark.appendChild(img);
		});
	}

	/** Un passage : le serveur renvoie son horodatage au premier lot, les échecs de ce passage ne sont pas retentés. */
	function fetchBatch(pass, stats) {
		return post('fetch_logos', { pass: pass || '' }).then(function (res) {
			(res.done || []).forEach(function (item) {
				showLogo(item.slug, item.url);
			});
			stats.have = res.have;
			stats.total = res.total;
			if (res.remaining > 0 && ((res.done || []).length || (res.failed || []).length)) {
				return fetchBatch(res.pass, stats);
			}
			return stats;
		});
	}

	if (logoButton) {
		// Le libellé ne change pas : la roue de chargement (aria-busy) suffit ; le résultat est annoncé aux lecteurs d'écran.
		logoButton.addEventListener('click', function () {
			logoButton.disabled = true;
			logoButton.setAttribute('aria-busy', 'true');
			fetchBatch('', {})
				.then(function (stats) {
					if (logoStatus) {
						logoStatus.textContent = format(T.done, stats.have, stats.total);
					}
					logoButton.hidden = stats.have >= stats.total;
				})
				.catch(function (err) {
					if (logoStatus) {
						logoStatus.textContent = err.message;
					}
				})
				.then(function () {
					logoButton.disabled = false;
					logoButton.removeAttribute('aria-busy');
				});
		});
	}
})();

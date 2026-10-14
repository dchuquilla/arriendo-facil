<?php
/**
 * Notificación flotante (toast) reutilizable para los formularios públicos.
 *
 * Imprime una sola vez por petición el CSS y el JS de `window.afToast()`.
 * Tipos: approved (aprobado), rejected (rechazado), info.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Toast
 */
class Arriendo_Facil_Toast {

	/**
	 * Evita imprimir el bloque más de una vez por petición.
	 *
	 * @var bool
	 */
	private static $printed = false;

	/**
	 * Devuelve el <style> y <script> del toast (vacío si ya se imprimieron).
	 *
	 * @return string
	 */
	public static function inline_assets() {
		if ( self::$printed ) {
			return '';
		}
		self::$printed = true;

		$close_label = __( 'Cerrar notificación', 'arriendo-facil' );

		ob_start();
		?>
		<style id="af-toast-css">
			.af-toast-stack{position:fixed;top:16px;right:16px;z-index:100000;display:flex;flex-direction:column;gap:12px;width:min(380px,calc(100vw - 32px));pointer-events:none;}
			body.admin-bar .af-toast-stack{top:48px;}
			.af-toast{--af-toast-color:#164A55;--af-toast-bg:#F7F8F6;position:relative;display:flex;gap:12px;align-items:flex-start;box-sizing:border-box;padding:14px 40px 16px 14px;background:#FFFFFF;border:1px solid #DCE5E3;border-left:5px solid var(--af-toast-color);border-radius:12px;box-shadow:0 12px 32px rgba(25,50,56,.18);color:#193238;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;pointer-events:auto;overflow:hidden;opacity:0;transform:translateX(24px);transition:opacity .25s ease,transform .25s ease;}
			.af-toast.is-visible{opacity:1;transform:none;}
			.af-toast.is-leaving{opacity:0;transform:translateX(24px);}
			.af-toast--approved{--af-toast-color:#2F7D73;--af-toast-bg:#DDEEEB;}
			.af-toast--rejected{--af-toast-color:#D9534F;--af-toast-bg:#FBECEB;}
			.af-toast--info{--af-toast-color:#3882F6;--af-toast-bg:#E8F1FE;}
			.af-toast__icon{flex:0 0 36px;width:36px;height:36px;border-radius:50%;background:var(--af-toast-bg);color:var(--af-toast-color);display:flex;align-items:center;justify-content:center;}
			.af-toast__icon svg{width:20px;height:20px;}
			.af-toast__body{min-width:0;}
			.af-toast__title{margin:0 0 2px;font-size:15px;font-weight:700;line-height:1.35;color:#193238;}
			.af-toast__msg{margin:0;font-size:14px;line-height:1.5;color:#4A5C61;word-break:break-word;}
			.af-toast__close{position:absolute;top:8px;right:8px;width:28px;height:28px;border:0;border-radius:8px;background:transparent;color:#647579;font-size:20px;line-height:1;cursor:pointer;}
			.af-toast__close:hover,.af-toast__close:focus-visible{background:#EEF2F0;color:#193238;outline:none;}
			.af-toast__bar{position:absolute;left:0;bottom:0;height:3px;width:100%;background:var(--af-toast-color);transform-origin:left;animation:af-toast-bar linear forwards;}
			.af-toast:hover .af-toast__bar{animation-play-state:paused;}
			@keyframes af-toast-bar{from{transform:scaleX(1);}to{transform:scaleX(0);}}
			@media (max-width:520px){.af-toast-stack{top:8px;right:8px;left:8px;width:auto;}body.admin-bar .af-toast-stack{top:54px;}.af-toast{transform:translateY(-16px);}.af-toast.is-leaving{transform:translateY(-16px);}}
			@media (prefers-reduced-motion:reduce){.af-toast,.af-toast.is-leaving{transition:none;transform:none;}.af-toast__bar{animation:none;display:none;}}
		</style>
		<script id="af-toast-js">
		(function(){
			if(window.afToast){ return; }

			var ICONS = {
				approved:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>',
				rejected:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>',
				info:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>'
			};
			var CLOSE_LABEL = <?php echo wp_json_encode( $close_label ); ?>;

			function stack(){
				var el = document.querySelector('.af-toast-stack');
				if(!el){
					el = document.createElement('div');
					el.className = 'af-toast-stack';
					document.body.appendChild(el);
				}
				return el;
			}

			window.afToast = function(opts){
				opts = opts || {};
				var type = ICONS[opts.type] ? opts.type : 'info';
				var duration = typeof opts.duration === 'number' ? opts.duration : (type === 'rejected' ? 10000 : 7000);

				var toast = document.createElement('div');
				toast.className = 'af-toast af-toast--' + type;
				toast.setAttribute('role', type === 'rejected' ? 'alert' : 'status');

				var icon = document.createElement('span');
				icon.className = 'af-toast__icon';
				icon.innerHTML = ICONS[type];

				var body = document.createElement('div');
				body.className = 'af-toast__body';
				var title = document.createElement('p');
				title.className = 'af-toast__title';
				title.textContent = String(opts.title || '');
				body.appendChild(title);
				if(opts.message){
					var msg = document.createElement('p');
					msg.className = 'af-toast__msg';
					msg.textContent = String(opts.message);
					body.appendChild(msg);
				}

				var close = document.createElement('button');
				close.type = 'button';
				close.className = 'af-toast__close';
				close.setAttribute('aria-label', CLOSE_LABEL);
				close.innerHTML = '&times;';

				toast.appendChild(icon);
				toast.appendChild(body);
				toast.appendChild(close);

				var bar;
				if(duration > 0){
					bar = document.createElement('span');
					bar.className = 'af-toast__bar';
					bar.style.animationDuration = duration + 'ms';
					toast.appendChild(bar);
				}

				var timer = null;
				function dismiss(){
					if(timer){ clearTimeout(timer); timer = null; }
					toast.classList.remove('is-visible');
					toast.classList.add('is-leaving');
					setTimeout(function(){ if(toast.parentNode){ toast.parentNode.removeChild(toast); } }, 300);
				}
				close.addEventListener('click', dismiss);

				stack().appendChild(toast);
				requestAnimationFrame(function(){ toast.classList.add('is-visible'); });

				if(bar){
					bar.addEventListener('animationend', dismiss);
				}

				return { dismiss: dismiss };
			};
		})();
		</script>
		<?php

		return (string) ob_get_clean();
	}
}

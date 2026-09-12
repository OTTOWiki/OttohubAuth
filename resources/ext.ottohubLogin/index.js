/**
 * 登录页：把「站内账号」与「OTTOhub 账号」两组字段做成标签页。
 *
 * 设计要点：
 *  - **服务端不依赖本脚本**：两组字段在后端本来就是并列的、且都可用（空的字段对应的
 *    AuthenticationRequest 会被 AuthManager 丢弃 → 那个 provider 直接 ABSTAIN），
 *    所以这里只做「显示层」改造。JS 没跑（禁用/加载失败）时，页面退化成两组字段并排显示，
 *    功能完全不受影响。
 *  - **提交时只发当前页的字段**：隐藏那一页的 input 会被 disable（disabled 的值不会提交），
 *    否则浏览器会把两套凭据一起发上去，可能让另一个 provider 用过期凭据把整次登录弄失败。
 *    切换标签时会重新 enable，值不丢。
 *  - 「记住我的登录状态」是核心字段、两组共用，留在两页之外。
 *
 * @license GPL-2.0-or-later
 */
( function () {
	'use strict';

	var STORAGE_KEY = 'ottohubauth-login-tab';
	var CLASS_LOCAL = 'ottohubauth-local-field';
	var CLASS_HUB = 'ottohubauth-hub-field';

	function text( key ) {
		return mw.message( key ).text();
	}

	function readStoredTab() {
		try {
			var v = window.localStorage.getItem( STORAGE_KEY );
			return v === 'hub' || v === 'local' ? v : '';
		} catch ( e ) {
			return '';
		}
	}

	function storeTab( mode ) {
		try {
			window.localStorage.setItem( STORAGE_KEY, mode );
		} catch ( e ) {
			// 隐私模式等取不到 localStorage，忽略
		}
	}

	/** 页面上是否有一条与 OTTOhub 有关的错误提示（失败后重显表单时用来自动切到对应页） */
	function hubErrorVisible() {
		var boxes = document.querySelectorAll(
			'.mw-message-box-error, .errorbox, .cdx-message--error, .mw-message-box-warning'
		);
		for ( var i = 0; i < boxes.length; i++ ) {
			if ( boxes[ i ].textContent.indexOf( 'OTTOhub' ) !== -1 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 建一个空面板（**先不要把字段搬进来**：搬进来之后原来那个参照点就不再是 form 的子节点了，
	 * 后面的 insertBefore 会抛 NotFoundError —— 2026-09-12 被 jsdom 测试抓到过）。
	 */
	function buildPanel( mode ) {
		var panel = document.createElement( 'div' );
		panel.className = 'ottohubauth-panel';
		panel.setAttribute( 'role', 'tabpanel' );
		panel.id = 'ottohubauth-panel-' + mode;
		panel.setAttribute( 'aria-labelledby', 'ottohubauth-tab-' + mode );
		panel.tabIndex = 0;

		var hint = document.createElement( 'p' );
		hint.className = 'ottohubauth-panel-hint';
		hint.textContent = text( mode === 'hub' ? 'ottohubauth-tab-hub-hint' : 'ottohubauth-tab-local-hint' );
		panel.appendChild( hint );

		return panel;
	}

	function buildTab( mode ) {
		var tab = document.createElement( 'button' );
		tab.type = 'button';
		tab.className = 'ottohubauth-tab';
		tab.id = 'ottohubauth-tab-' + mode;
		tab.setAttribute( 'role', 'tab' );
		tab.setAttribute( 'aria-controls', 'ottohubauth-panel-' + mode );
		tab.textContent = text( mode === 'hub' ? 'ottohubauth-tab-hub' : 'ottohubauth-tab-local' );
		return tab;
	}

	function init() {
		var form = document.querySelector( 'form[name="userlogin"]' );
		if ( !form ) {
			return;
		}
		var localNodes = Array.prototype.slice.call( form.querySelectorAll( '.' + CLASS_LOCAL ) );
		var hubNodes = Array.prototype.slice.call( form.querySelectorAll( '.' + CLASS_HUB ) );
		if ( !localNodes.length || !hubNodes.length ) {
			return; // 服务端没打标记 → 保持原样
		}

		var anchor = localNodes[ 0 ];
		var parent = anchor.parentNode;

		var tablist = document.createElement( 'div' );
		tablist.className = 'ottohubauth-tabs';
		tablist.setAttribute( 'role', 'tablist' );
		tablist.setAttribute( 'aria-label', text( 'ottohubauth-tablist-label' ) );

		var tabLocal = buildTab( 'local' );
		var tabHub = buildTab( 'hub' );
		tablist.appendChild( tabLocal );
		tablist.appendChild( tabHub );

		var panelLocal = buildPanel( 'local' );
		var panelHub = buildPanel( 'hub' );

		// ① 先把空容器插到第一个本地字段前面（此刻 anchor 仍在 form 里，可安全作为参照点）
		parent.insertBefore( tablist, anchor );
		parent.insertBefore( panelLocal, anchor );
		parent.insertBefore( panelHub, anchor );

		// ② 再搬字段（搬完原位置自然腾空；提交按钮等留在两页之外）
		localNodes.forEach( function ( node ) {
			panelLocal.appendChild( node );
		} );
		hubNodes.forEach( function ( node ) {
			panelHub.appendChild( node );
		} );

		var panels = { local: panelLocal, hub: panelHub };
		var tabs = { local: tabLocal, hub: tabHub };

		function inputs( panel ) {
			return Array.prototype.slice.call( panel.querySelectorAll( 'input, select, textarea' ) );
		}

		/** 显示/隐藏一页：值一律保留、不禁用（禁用只发生在提交那一刻） */
		function setPanelVisible( panel, visible ) {
			panel.hidden = !visible;
			inputs( panel ).forEach( function ( el ) {
				el.disabled = false;
				if ( visible ) {
					el.removeAttribute( 'tabindex' );
				} else {
					el.setAttribute( 'tabindex', '-1' );
				}
			} );
		}

		var current = 'local';

		function activate( mode, focus ) {
			current = mode;
			[ 'local', 'hub' ].forEach( function ( m ) {
				var on = m === mode;
				setPanelVisible( panels[ m ], on );
				tabs[ m ].setAttribute( 'aria-selected', on ? 'true' : 'false' );
				tabs[ m ].tabIndex = on ? 0 : -1;
			} );
			storeTab( mode );
			if ( focus ) {
				tabs[ mode ].focus();
			}
		}

		tabLocal.addEventListener( 'click', function () {
			activate( 'local' );
		} );
		tabHub.addEventListener( 'click', function () {
			activate( 'hub' );
		} );

		tablist.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'ArrowLeft' && e.key !== 'ArrowRight' ) {
				return;
			}
			e.preventDefault();
			activate( current === 'local' ? 'hub' : 'local', true );
		} );

		// 提交时只送当前页的字段（disabled 的字段不会被提交）
		form.addEventListener( 'submit', function () {
			inputs( panels[ current === 'local' ? 'hub' : 'local' ] ).forEach( function ( el ) {
				el.disabled = true;
			} );
		} );

		// 初始页判定顺序：
		//   ① URL 显式指定（?ottohub=1 / ?local=1）
		//   ② 上一页留下的 OTTOhub 账号（说明上次就是走 OTTOhub）
		//   ③ 页面上有 OTTOhub 报错
		//   ④ 上次手动选过的那一页（localStorage）
		//   ⑤ 都没有 → **OTTOhub 账号**（站长 2026-09-12：新用户一进来就应该是对的那一页；
		//      老用户切一次「站内账号」之后会被记住）
		var forced = mw.config.get( 'ottohubauthDefaultTab' );
		var initial = forced === 'hub' || forced === 'local' ? forced : '';
		if ( !initial ) {
			var hubAccount = form.querySelector( 'input[name="ottohubAccount"]' );
			initial = hubAccount && hubAccount.value ? 'hub' : '';
		}
		if ( !initial && hubErrorVisible() ) {
			initial = 'hub';
		}
		if ( !initial ) {
			initial = readStoredTab();
		}
		activate( initial || 'hub' );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

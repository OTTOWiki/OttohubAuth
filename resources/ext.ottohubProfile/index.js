/**
 * 阶段 2：OTTOhub 个人资料区块。
 *
 * 服务端只输出 data-ottohub-uid；本模块直接用浏览器请求 OTTOhub 公开接口取头像/用户名/简介。
 *
 * 硬性安全要求（docs/ottohub-sso.md §5）：
 *  - 远端返回的是**外部数据** → 只用 textContent / createElement，**禁止 innerHTML**
 *  - uid 拼进 URL 前用 /^[0-9]+$/ 校验，且必须是 OTTOhub 侧的值
 *  - 头像 URL 只接受 https:// 且主机属于 OTTOhub CDN（cdn-cos.ottohub.cn / img.ottohub.cn）
 *  - 外链 target="_blank" + rel="noopener noreferrer"
 *  - 接口失败/超时（3s）→ 显示「OTTOhub 用户 #<hubUid>」并可点进主页，不留空白、不报错
 *
 * 本模块刻意不向 wiki 回传任何东西，也不写 cookie/localStorage。
 */
( function () {
	'use strict';

	var API_BASE = 'https://api.ottohub.cn/api/user/';
	var PROFILE_BASE = 'https://www.ottohub.cn/user/';
	var TIMEOUT_MS = 3000;
	var ALLOWED_AVATAR_HOSTS = [ 'cdn-cos.ottohub.cn', 'img.ottohub.cn' ];

	/**
	 * 严格校验 hub uid：上游返回的是字符串，只接受纯数字。
	 */
	function normalizeHubUid( raw ) {
		var s = String( raw == null ? '' : raw ).trim();
		return /^[0-9]+$/.test( s ) ? s : null;
	}

	/**
	 * 头像 URL 白名单校验；不合法返回 null。
	 */
	function safeAvatarUrl( raw ) {
		if ( typeof raw !== 'string' || raw === '' ) {
			return null;
		}
		var u;
		try {
			u = new URL( raw );
		} catch ( e ) {
			return null;
		}
		if ( u.protocol !== 'https:' ) {
			return null;
		}
		return ALLOWED_AVATAR_HOSTS.indexOf( u.hostname ) !== -1 ? u.href : null;
	}

	function profileUrl( hubUid ) {
		return PROFILE_BASE + encodeURIComponent( hubUid );
	}

	function externalLink( hubUid, text ) {
		var a = document.createElement( 'a' );
		a.href = profileUrl( hubUid );
		a.target = '_blank';
		a.rel = 'noopener noreferrer';
		a.textContent = text;
		return a;
	}

	function msg( key, fallback ) {
		var v = mw.message( key );
		return v.exists() ? v.text() : fallback;
	}

	/**
	 * 降级展示：接口失败/超时/数据不可用时，仍然显示可点进主页的「OTTOhub 用户 #uid」。
	 */
	function renderFallback( root, hubUid ) {
		root.textContent = '';
		var p = document.createElement( 'p' );
		p.className = 'ottohubauth-profile-fallback';
		p.appendChild( externalLink(
			hubUid,
			msg( 'ottohubauth-profile-fallback', 'OTTOhub 用户 #$1' ).replace( '$1', hubUid )
		) );
		root.appendChild( p );
	}

	function renderProfile( root, hubUid, data ) {
		root.textContent = '';

		var username = ( typeof data.username === 'string' && data.username !== '' )
			? data.username
			: msg( 'ottohubauth-profile-unnamed', '未命名用户' );
		var intro = ( typeof data.intro === 'string' ) ? data.intro : '';
		var avatar = safeAvatarUrl( data.avatar_url );

		var wrap = document.createElement( 'div' );
		wrap.className = 'ottohubauth-profile-inner';

		if ( avatar ) {
			var img = document.createElement( 'img' );
			img.className = 'ottohubauth-avatar';
			img.src = avatar;
			img.alt = '';
			img.width = 75;
			img.height = 75;
			img.loading = 'lazy';
			img.referrerPolicy = 'no-referrer';
			wrap.appendChild( img );
		}

		var nameLine = document.createElement( 'div' );
		nameLine.className = 'ottohubauth-profile-name';
		nameLine.appendChild( externalLink( hubUid, username ) );
		wrap.appendChild( nameLine );

		var uidLine = document.createElement( 'div' );
		uidLine.className = 'ottohubauth-profile-uid';
		uidLine.textContent = msg( 'ottohubauth-profile-uid', 'OTTOhub UID：$1' )
			.replace( '$1', hubUid );
		wrap.appendChild( uidLine );

		// intro 在上游是 Markdown；按**纯文本**显示，不在客户端渲染 HTML
		if ( intro !== '' ) {
			var introEl = document.createElement( 'div' );
			introEl.className = 'ottohubauth-profile-intro';
			introEl.textContent = intro;
			wrap.appendChild( introEl );
		}

		root.appendChild( wrap );
	}

	function fetchProfile( hubUid ) {
		var controller = ( typeof AbortController === 'function' ) ? new AbortController() : null;
		var timer = null;
		if ( controller ) {
			timer = setTimeout( function () {
				controller.abort();
			}, TIMEOUT_MS );
		}
		return fetch( API_BASE + hubUid, {
			method: 'GET',
			headers: { Accept: 'application/json' },
			credentials: 'omit',
			mode: 'cors',
			cache: 'no-store',
			signal: controller ? controller.signal : undefined
		} ).then( function ( resp ) {
			if ( timer !== null ) {
				clearTimeout( timer );
			}
			if ( !resp.ok ) {
				throw new Error( 'http_' + resp.status );
			}
			return resp.json();
		} ).then( function ( body ) {
			if ( !body || body.status !== 'success' ) {
				throw new Error( 'upstream_error' );
			}
			// data 包装；同时也容忍平坦结构
			var data = ( body.data && typeof body.data === 'object' ) ? body.data : body;
			// 上游 uid 也是字符串，校验它确实是 OTTOhub 侧的那个 uid
			var echoed = normalizeHubUid( data.uid );
			if ( echoed !== null && echoed !== hubUid ) {
				throw new Error( 'uid_mismatch' );
			}
			return data;
		} );
	}

	function init() {
		var root = document.getElementById( 'ottohubauth-profile-block' );
		if ( !root ) {
			return;
		}
		var hubUid = normalizeHubUid( root.getAttribute( 'data-ottohub-uid' ) );
		if ( hubUid === null ) {
			// 服务端本来只应输出数字；异常时直接收摊（不暴露任何东西）
			root.parentNode.removeChild( root );
			return;
		}
		fetchProfile( hubUid ).then( function ( data ) {
			renderProfile( root, hubUid, data );
		}, function () {
			renderFallback( root, hubUid );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

<?php
/**
 * 服务装配：OttohubClient / OttohubAccountStore / OttohubThrottle / OttohubSender。
 *
 * 依据站点环境（docs/mediawiki.md）：扩展**零 composer 依赖**，只用核心服务 + curl。
 *
 * @license GPL-2.0-or-later
 */

use MediaWiki\Extension\OttohubAuth\OttohubAccountStore;
use MediaWiki\Extension\OttohubAuth\OttohubClient;
use MediaWiki\Extension\OttohubAuth\OttohubProviderFactory;
use MediaWiki\Extension\OttohubAuth\OttohubSender;
use MediaWiki\Extension\OttohubAuth\OttohubThrottle;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	'OttohubAuth.Client' => static function ( MediaWikiServices $services ): OttohubClient {
		$config = $services->getMainConfig();
		return new OttohubClient(
			(string)$config->get( 'OttohubAuth_ApiBase' ),
			(int)$config->get( 'OttohubAuth_Timeout' )
		);
	},

	'OttohubAuth.AccountStore' => static function ( MediaWikiServices $services ): OttohubAccountStore {
		return new OttohubAccountStore( $services->getConnectionProvider() );
	},

	'OttohubAuth.Provider' => static function ( MediaWikiServices $services ): OttohubProviderFactory {
		// provider 由 AuthManagerAutoConfig 注册，不是普通服务 —— 必须经 AuthManager 取
		return new OttohubProviderFactory( $services->getAuthManager() );
	},

	'OttohubAuth.Throttle' => static function ( MediaWikiServices $services ): OttohubThrottle {
		$config = $services->getMainConfig();
		$settings = $config->get( 'OttohubAuth_Throttle' );
		return new OttohubThrottle(
			$services->getObjectCacheFactory()->getLocalClusterInstance(),
			(int)( $settings['count'] ?? 5 ),
			(int)( $settings['window'] ?? 600 )
		);
	},

	'OttohubAuth.Sender' => static function ( MediaWikiServices $services ): OttohubSender {
		return new OttohubSender(
			$services->getService( 'OttohubAuth.Client' ),
			$services->getObjectCacheFactory()->getLocalClusterInstance(),
			$services->getMainConfig(),
			LoggerFactory::getInstance( 'OttohubAuth' )
		);
	},
];

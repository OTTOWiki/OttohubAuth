<?php
/**
 * 建表钩子（单独一个类，因为 LoadExtensionSchemaUpdates 是**无服务依赖**的钩子）。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

class SchemaHooks {

	/**
	 * @param \MediaWiki\Installer\DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$updater->addExtensionTable(
			'ottohub_accounts',
			__DIR__ . '/../sql/mysql/table_ottohub_accounts.sql'
		);
	}
}
